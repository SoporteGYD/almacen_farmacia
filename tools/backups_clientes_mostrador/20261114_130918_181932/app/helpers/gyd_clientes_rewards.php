<?php
declare(strict_types=1);
/**
 * G&D V37 · Fidelidad en laboratorio. No ejecuta canjes ni altera inventario.
 * Dinero siempre en CENTAVOS. Costo conservador = máximo lote con costo > 0.
 */
require_once __DIR__.'/gyd_rewards_reversiones_v37.php';
final class GydClientesRewards {
    private static function q(PDO $db,string $sql,array $params=[]): PDOStatement {
        $s=$db->prepare($sql);$s->execute($params);return $s;
    }
    public static function buscar(PDO $db,string $q):array {
        $q=trim($q);
        if(mb_strlen($q,'UTF-8')<2 || mb_strlen($q,'UTF-8')>100) return [];
        $like='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$q).'%';
        $rows=self::q($db,"SELECT id,codigo,nombre,telefono,correo,activo FROM gyd_clientes WHERE activo=1 AND (nombre LIKE ? OR telefono LIKE ? OR codigo LIKE ? OR correo LIKE ?) ORDER BY (codigo=?) DESC,nombre LIMIT 30",[$like,$like,$like,$like,$q])->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$r){$r['saldo']=self::saldo($db,(int)$r['id']);$r['puntos_en_revision']=GydRewardsReversionesV37::hayParcialesPendientes($db,(int)$r['id']);}unset($r);
        return $rows;
    }
    public static function recientes(PDO $db):array {
        $rows=self::q($db,'SELECT id,codigo,nombre,telefono,correo,activo FROM gyd_clientes ORDER BY id DESC LIMIT 60')->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$r){$r['saldo']=self::saldo($db,(int)$r['id']);$r['puntos_en_revision']=GydRewardsReversionesV37::hayParcialesPendientes($db,(int)$r['id']);}unset($r);
        return $rows;
    }
    public static function obtener(PDO $db,int $id):array {
        $r=self::q($db,'SELECT id,codigo,nombre,telefono,correo,acepta_programa,consentimiento_en,activo,creado_en FROM gyd_clientes WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
        if(!$r)throw new DomainException('El cliente no existe.');
        $r['saldo']=self::saldo($db,$id);
        $r['puntos_en_revision']=GydRewardsReversionesV37::hayParcialesPendientes($db,$id);
        $r['ventas']=self::q($db,"SELECT cv.venta_id,v.folio,v.pagada_en,v.total,v.estado,cv.puntos_otorgados FROM gyd_clientes_ventas cv JOIN pos_ventas v ON v.id=cv.venta_id WHERE cv.cliente_id=? ORDER BY cv.venta_id DESC LIMIT 25",[$id])->fetchAll(PDO::FETCH_ASSOC);
        foreach($r['ventas'] as &$venta){$venta['puntos_en_revision']=GydRewardsReversionesV37::hayParcialesVenta($db,(int)$venta['venta_id']);}unset($venta);
        return $r;
    }
    public static function saldo(PDO $db,int $clienteId):int {
        // Excluye en tiempo de consulta ventas canceladas y reversos totales aplicados.
        // Los reembolsos parciales se muestran explícitamente en revisión: NO hay canje.
        return GydRewardsReversionesV37::saldoVisible($db,$clienteId);
    }
    public static function validar(PDO $db,int $id,bool $bloquear=false):array {
        $r=self::q($db,'SELECT id,nombre,activo,acepta_programa FROM gyd_clientes WHERE id=?'.($bloquear?' FOR UPDATE':''),[$id])->fetch(PDO::FETCH_ASSOC);
        if(!$r || (int)$r['activo']!==1 || (int)$r['acepta_programa']!==1)throw new DomainException('Cliente no activo o sin consentimiento para el programa de puntos.');
        return $r;
    }
    private static function saneaNombre(string $v):string {
        $v=trim(preg_replace('/\s+/u',' ',$v)??'');
        if(mb_strlen($v,'UTF-8')<2 || mb_strlen($v,'UTF-8')>120 || preg_match('/[\x00-\x1F\x7F]/',$v))throw new DomainException('El nombre debe tener entre 2 y 120 caracteres.');
        return $v;
    }
    private static function saneaTelefono(string $v):?string {
        $v=trim($v);
        if($v==='' )return null;
        if(!preg_match('/^\+?[0-9() .-]{8,22}$/',$v))throw new DomainException('Teléfono inválido.');
        return $v;
    }
    private static function saneaCorreo(string $v):?string {
        $v=trim($v);
        if($v==='')return null;
        if(strlen($v)>190 || !filter_var($v,FILTER_VALIDATE_EMAIL))throw new DomainException('Correo electrónico inválido.');
        return mb_strtolower($v,'UTF-8');
    }
    public static function crear(PDO $db,array $data,int $usuario):array {
        if(empty($data['consentimiento']))throw new DomainException('El cliente debe aceptar el aviso de privacidad y la inscripción voluntaria.');
        $nombre=self::saneaNombre((string)($data['nombre']??''));
        $telefono=self::saneaTelefono((string)($data['telefono']??''));
        $correo=self::saneaCorreo((string)($data['correo']??''));
        $db->beginTransaction();
        try {
            self::q($db,'INSERT INTO gyd_clientes(codigo,nombre,telefono,correo,acepta_programa,consentimiento_en,creado_por,activo) VALUES (?,?,?,?,1,NOW(),?,1)',['TMP-'.bin2hex(random_bytes(8)),$nombre,$telefono,$correo,$usuario]);
            $id=(int)$db->lastInsertId();
            self::q($db,'UPDATE gyd_clientes SET codigo=? WHERE id=?',[sprintf('GD-%07d',$id),$id]);
            $db->commit();return self::obtener($db,$id);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function editar(PDO $db,int $id,array $data):array {
        $nombre=self::saneaNombre((string)($data['nombre']??''));
        $telefono=self::saneaTelefono((string)($data['telefono']??''));
        $correo=self::saneaCorreo((string)($data['correo']??''));
        $activo=isset($data['activo']) && in_array($data['activo'],[1,'1',true],true)?1:0;
        $s=self::q($db,'UPDATE gyd_clientes SET nombre=?,telefono=?,correo=?,activo=? WHERE id=?',[$nombre,$telefono,$correo,$activo,$id]);
        if($s->rowCount()===0 && !self::q($db,'SELECT 1 FROM gyd_clientes WHERE id=?',[$id])->fetchColumn())throw new DomainException('Cliente inexistente.');
        return self::obtener($db,$id);
    }
    public static function vincularSesion(string $key,int $caja,?int $cliente):void {
        if(!preg_match('/^[a-f0-9-]{36}$/i',$key) || $caja<1)throw new DomainException('Identificador de venta o caja inválido.');
        if(!isset($_SESSION['gyd_v36_clientes']) || !is_array($_SESSION['gyd_v36_clientes']))$_SESSION['gyd_v36_clientes']=[];
        foreach($_SESSION['gyd_v36_clientes'] as $k=>$r)if(!is_array($r)||time()-(int)($r['ts']??0)>600)unset($_SESSION['gyd_v36_clientes'][$k]);
        if($cliente===null){unset($_SESSION['gyd_v36_clientes'][$key]);return;}
        $_SESSION['gyd_v36_clientes'][$key]=['cliente'=>$cliente,'caja'=>$caja,'ts'=>time()];
        if(count($_SESSION['gyd_v36_clientes'])>15)$_SESSION['gyd_v36_clientes']=array_slice($_SESSION['gyd_v36_clientes'],-15,null,true);
    }
    public static function clienteSesion(string $key,int $caja):?int {
        $r=$_SESSION['gyd_v36_clientes'][$key]??null;
        if(!is_array($r) || (int)($r['caja']??0)!==$caja || time()-(int)($r['ts']??0)>600)return null;
        return (int)($r['cliente']??0)>0?(int)$r['cliente']:null;
    }
    public static function limpiarSesion(string $key):void {unset($_SESSION['gyd_v36_clientes'][$key]);}
    /** Únicamente con transacción de cobro abierta y confirmada por PuntoVenta. */
    public static function acumular(PDO $db,int $cliente,int $venta,int $sucursal,array $detalle,int $usuario):array {
        // Validar con lock para impedir cambios de cuenta entre consulta y asiento.
        self::validar($db,$cliente,true);
        $elegible=0;$utilidad=0;$excluidos=0;
        foreach($detalle as $d){
            // Excluir precios especiales, renglones sin costo y márgenes inseguros.
            if(($d['especial']??null)!==null){$excluidos++;continue;}
            $producto=(int)$d['id'];$piezas=(int)$d['unidades'];$importe=(int)$d['importe'];
            $costo=self::q($db,'SELECT MAX(costo_unitario) FROM lotes WHERE producto_id=? AND costo_unitario>0',[$producto])->fetchColumn();
            if($costo===null || $costo===false || $piezas<=0){$excluidos++;continue;}
            $costePza=(int)round(((float)$costo)*100);
            if($costePza<=0){$excluidos++;continue;}
            $ganancia=$importe-$piezas*$costePza;
            if($ganancia<=0 || $ganancia*100<$importe*15){$excluidos++;continue;}
            $elegible+=$importe;$utilidad+=$ganancia;
        }
        // 1 punto por $20 elegibles, 1 punto reservado a $0.10 futuro.
        // Pasivo máximo: 0.5% de ingreso elegible y 5% de utilidad estimada.
        $puntos=min(intdiv($elegible,2000),intdiv($utilidad,200));
        self::q($db,'INSERT INTO gyd_clientes_ventas(venta_id,cliente_id,sucursal_id,puntos_otorgados,ingreso_elegible_centavos,utilidad_referencia_centavos,registrado_por) VALUES(?,?,?,?,?,?,?)',[$venta,$cliente,$sucursal,$puntos,$elegible,$utilidad,$usuario]);
        if($puntos>0) self::q($db,"INSERT INTO gyd_puntos_movimientos(cliente_id,venta_id,origen_clave,tipo,puntos,valor_reserva_centavos,detalle,creado_por) VALUES (?,?,?,'ACUMULACION',?,?,?,?)",[$cliente,$venta,'VENTA-'.$venta,$puntos,$puntos*10,'Venta pagada; saldo provisional, canje no habilitado',$usuario]);
        return ['cliente_id'=>$cliente,'puntos'=>$puntos,'monto_elegible'=>number_format($elegible/100,2,'.',''),'renglones_excluidos'=>$excluidos,'saldo_estimado'=>self::saldo($db,$cliente),'canje_habilitado'=>false];
    }
}
