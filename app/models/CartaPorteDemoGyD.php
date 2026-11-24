<?php
declare(strict_types=1);
require_once __DIR__ . '/TraspasosGyD.php';
/** Registros de simulación estrictamente NO fiscales, sin conexión a PAC/SAT. */
final class CartaPorteDemoGyD {
    public const RFC_EMISOR = 'DGD151211PP5';
    public const NOMBRE_EMISOR = 'DISTRIBUCION G&D';
    public const REGIMEN_EMISOR = '601'; // General de Ley Personas Morales.
    public const CP_FISCAL = '30797';
    public const DOMICILIO_FISCAL = 'CARRETERA PUERTO MADERO S/N, COL. CANTARRANAS, TAPACHULA, CHIAPAS';
    private PDO $db;
    private TraspasosGyD $traspasos;
    public function __construct() {
        $this->traspasos = new TraspasosGyD();
        $this->db = $this->traspasos->db();
    }
    private function q(string $sql,array $p=[]):PDOStatement {
        $st=$this->db->prepare($sql); $st->execute($p); return $st;
    }
    private static function len(string $s):int{return function_exists('mb_strlen')?mb_strlen($s,'UTF-8'):strlen($s);}
    private static function required(array $p,string $name,int $max=150):string {
        $value=trim((string)($p[$name]??''));
        if($value===''||self::len($value)>$max||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$value))throw new DomainException('Campo obligatorio inválido: '.$name);
        return $value;
    }
    public static function role(array $u):string{return strtoupper(trim((string)($u['rol_original']??$u['rol']??'')));}
    public static function permitted(array $u):bool {
        return (int)($u['id']??0)>0&&in_array(self::role($u),['ADMINISTRADOR','ADMIN','DIRECCION','GERENTE','ENCARGADO'],true);
    }
    public function traspaso(array $u,int $id):array {
        if(!self::permitted($u)||$id<1)throw new DomainException('Sin autorización para consultar traspasos.');
        return $this->traspasos->ver($u,$id);
    }
    public function listado(array $u):array {
        if(!self::permitted($u))throw new DomainException('Acceso denegado.');
        return $this->traspasos->listado($u);
    }
    public function historial(array $u,int $id):array {
        $this->traspaso($u,$id);
        return $this->q('SELECT id,folio_demo,resultado,mensaje,fecha_registro,operador_id,huella_sha256 FROM erp_carta_porte_demo WHERE traspaso_id=? ORDER BY id DESC LIMIT 40',[$id])->fetchAll(PDO::FETCH_ASSOC);
    }
    public function comprobante(array $u,int $id,int $reg):array {
        $this->traspaso($u,$id);
        $v=$this->q('SELECT * FROM erp_carta_porte_demo WHERE traspaso_id=? AND id=? LIMIT 1',[$id,$reg])->fetch(PDO::FETCH_ASSOC);
        if(!$v||$v['ambiente']!=='DEMO'||(int)$v['es_fiscal']!==0)throw new DomainException('El comprobante no pertenece al ambiente DEMO.');
        return $v;
    }
    public function generar(array $u,int $id,array $p):array {
        $t=$this->traspaso($u,$id);
        if(!TraspasosGyD::global($u)&&TraspasosGyD::sucursalUsuario($u)!==(int)$t['origen_id'])throw new DomainException('Solo personal del origen puede simular la Carta Porte.');
        if(!in_array($t['estado'],['SOLICITADO','POR_PREPARAR','PREPARADO'],true))throw new DomainException('La simulación se genera antes del despacho.');
        $token=strtolower(trim((string)($p['token']??'')));
        if(!preg_match('/^[a-f0-9]{32}$/D',$token))throw new DomainException('Identificador de intento incorrecto.');
        $existing=$this->q('SELECT * FROM erp_carta_porte_demo WHERE token=?',[$token])->fetch(PDO::FETCH_ASSOC);
        if($existing){
            if((int)$existing['traspaso_id']!==$id)throw new DomainException('Token usado en otro traspaso.');
            return $existing;
        }
        $estado=(string)($p['resultado']??'');
        if(!in_array($estado,['ACEPTADO_DEMO','RECHAZADO_DEMO','SIN_RESPUESTA_DEMO'],true))throw new DomainException('Estado de simulación incorrecto.');
        if(count($t['items'])<1)throw new DomainException('No hay mercancías.');
        $salida=self::required($p,'salida',16);
        $dt=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$salida);
        if(!$dt||$dt->format('Y-m-d\TH:i')!==$salida)throw new DomainException('Fecha de salida inválida.');
        $rfc=strtoupper(self::required($p,'rfc_operador',13));
        if(!preg_match('/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/uD',$rfc))throw new DomainException('RFC del operador inválido.');
        $placas=strtoupper(self::required($p,'placas',20));
        if(!preg_match('/^[A-Z0-9 -]{4,20}$/D',$placas))throw new DomainException('Formato de placas inválido.');
        $cpO=self::required($p,'cp_origen',5);$cpD=self::required($p,'cp_destino',5);
        if(!preg_match('/^\d{5}$/D',$cpO)||!preg_match('/^\d{5}$/D',$cpD))throw new DomainException('Códigos postales de ruta inválidos.');
        $kms=filter_var((string)($p['km']??''),FILTER_VALIDATE_FLOAT);
        if($kms===false||$kms<=0||$kms>99999)throw new DomainException('Distancia mayor que cero obligatoria.');
        $camion=[
            'placas'=>$placas,
            'configuracion_vehicular'=>self::required($p,'configuracion',20),
            'tipo_permiso'=>self::required($p,'permiso_sict',40),
            'numero_permiso'=>self::required($p,'numero_permiso',60),
            'aseguradora'=>self::required($p,'aseguradora',100),
            'poliza'=>self::required($p,'poliza',70),
        ];
        $chofer=['nombre'=>self::required($p,'conductor',150),'rfc'=>$rfc,'licencia'=>self::required($p,'licencia',40)];
        $detalle=is_array($p['mercancias']??null)?$p['mercancias']:[];
        $goods=[];$pesoTotal=0.0;
        foreach($t['items'] as $item){
            $pid=(int)$item['producto_id'];$cantidad=(int)$item['solicitado'];
            if($cantidad<1)throw new DomainException('Cantidad de mercancía incorrecta.');
            $g=is_array($detalle[$pid]??null)?$detalle[$pid]:[];
            $clave=self::required($g,'clave_sat',8);$unidad=strtoupper(self::required($g,'unidad_sat',5));
            if(!preg_match('/^\d{8}$/D',$clave)||!preg_match('/^[A-Z0-9]{2,5}$/D',$unidad))throw new DomainException('Revisa las claves SAT del producto '.$item['codigo']);
            $peso=filter_var((string)($g['peso_kg']??''),FILTER_VALIDATE_FLOAT);
            if($peso===false||$peso<=0||$peso>100000)throw new DomainException('Peso total por partida inválido: '.$item['codigo']);
            $pesoTotal+=(float)$peso;
            $goods[]=[
                'producto_id'=>$pid,'codigo_interno'=>(string)$item['codigo'],
                'descripcion'=>(string)$item['descripcion'],'piezas'=>$cantidad,
                'clave_bienes_transp_declarada'=>$clave,'clave_unidad_declarada'=>$unidad,
                'peso_partida_kg'=>(float)$peso,
            ];
        }
        $data=[
            'ambiente'=>'DEMO','validez_fiscal'=>false,'es_cfdi'=>false,
            'referencia_cfdi'=>'4.0, tipo Traslado, valor 0','referencia_carta_porte'=>'3.1',
            'emisor_receptor'=>['rfc'=>self::RFC_EMISOR,'nombre'=>self::NOMBRE_EMISOR,'regimen_fiscal'=>self::REGIMEN_EMISOR,'cp_domicilio_fiscal'=>self::CP_FISCAL,'domicilio_fiscal'=>self::DOMICILIO_FISCAL],
            'traspaso'=>['id'=>(int)$t['id'],'folio'=>(string)$t['folio'],'estado'=>(string)$t['estado']],
            'origen'=>['nombre'=>(string)$t['origen'],'cp'=>$cpO,'domicilio'=>self::required($p,'domicilio_origen',200)],
            'destino'=>['nombre'=>(string)$t['destino'],'cp'=>$cpD,'domicilio'=>self::required($p,'domicilio_destino',200)],
            'viaje'=>['fecha_salida'=>$salida,'distancia_km'=>(float)$kms,'peso_total_kg'=>round($pesoTotal,3)],
            'vehiculo'=>$camion,'operador'=>$chofer,'mercancias'=>$goods,
            'nota'=>'PAC ficticio; sin timbrado, sellos, UUID, IdCCP, QR ni validación ante el SAT. Las claves declaradas NO fueron validadas contra los catálogos SAT.'
        ];
        $json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $hash=hash('sha256',$json);
        $messages=[
            'ACEPTADO_DEMO'=>'SIMULADO: respuesta aceptada. NO es una certificación fiscal.',
            'RECHAZADO_DEMO'=>'SIMULADO: PAC ficticio rechazó el documento.',
            'SIN_RESPUESTA_DEMO'=>'SIMULADO: PAC ficticio agotó el tiempo de espera.',
        ];
        // Identificador intencionalmente diferente al formato UUID fiscal.
        $folio='DEMO-CP-'.strtoupper(bin2hex(random_bytes(8)));
        try {
            $this->q('INSERT INTO erp_carta_porte_demo (traspaso_id,token,folio_demo,resultado,mensaje,resumen_json,huella_sha256,operador_id,ambiente,es_fiscal) VALUES (?,?,?,?,?,?,?,?,?,?)',[
                $id,$token,$folio,$estado,$messages[$estado],$json,$hash,(int)$u['id'],'DEMO',0
            ]);
        } catch(PDOException $e){
            if((string)$e->getCode()!=='23000')throw $e;
            $old=$this->q('SELECT * FROM erp_carta_porte_demo WHERE token=?',[$token])->fetch(PDO::FETCH_ASSOC);
            if(!$old||(int)$old['traspaso_id']!==$id)throw new DomainException('Conflicto de operación repetida.');
            return $old;
        }
        return $this->q('SELECT * FROM erp_carta_porte_demo WHERE token=?',[$token])->fetch(PDO::FETCH_ASSOC);
    }
}
