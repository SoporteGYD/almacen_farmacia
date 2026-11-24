<?php
declare(strict_types=1);
/**
 * G&D V40 - Laboratorio aislado de fidelización.
 * Unicamente la BD `prueba` en puerto 3307; jamás escribe existencias, precios,
 * ventas, cajas, cortes o recompensas activas del ERP.
 * Clientes sinteticos [PRUEBA] con ajustes rastreables en el libro V36.
 */
require_once __DIR__.'/gyd_rewards_catalogo_v38.php';
require_once __DIR__.'/gyd_clientes_rewards.php';
require_once __DIR__.'/gyd_rewards_reversiones_v37.php';
final class GydRewardsLabV40 {
    public const CLIENTES=[
      ['LAB40-001','[PRUEBA] Laura Morales',0,'Sin puntos'],
      ['LAB40-002','[PRUEBA] Diego Salinas',80,'Saldo bajo'],
      ['LAB40-003','[PRUEBA] Paola Ríos',350,'Saldo básico'],
      ['LAB40-004','[PRUEBA] Martín Cruz',750,'Cliente regular'],
      ['LAB40-005','[PRUEBA] Fernanda López',1500,'Cliente frecuente'],
      ['LAB40-006','[PRUEBA] Jorge Méndez',2800,'Saldo medio'],
      ['LAB40-007','[PRUEBA] Daniela Soto',4500,'Saldo alto'],
      ['LAB40-008','[PRUEBA] Ricardo Torres',10000,'Saldo máximo de laboratorio'],
    ];
    private static function q(PDO $db,string $sql,array $p=[]):PDOStatement {
        $s=$db->prepare($sql);$s->execute($p);return $s;
    }
    public static function entorno(PDO $db):void { GydRewardsCatalogoV38::laboratorio($db); }
    public static function calculo(int $precio,int $costo,int $stock,int $puntos,int $saldo):array {
        $eval=GydRewardsCatalogoV38::evaluarImportes($precio,$costo,true);
        $utilidad=$precio-$costo;
        $margen=$precio>0?round($utilidad*100/$precio,2):null;
        $esElegible=$precio>0 && $costo>0 && $utilidad>0 && $utilidad*100 >= $precio*15;
        $puntosCompra=$esElegible?min(intdiv($precio,2000),intdiv($utilidad,200)):0;
        $reserva=$puntosCompra*GydRewardsCatalogoV38::VALOR_PUNTO_CENTAVOS;
        $margenAjustado=$precio>0?round(($utilidad-$reserva)*100/$precio,2):null;
        $puntosOk=$puntos>0 && $puntos<=2000000 && $eval['minimo_puntos']!==null && $puntos>=(int)$eval['minimo_puntos'];
        $apto=(bool)$eval['apto'] && $puntosOk && $stock>0 && $saldo>=$puntos;
        $razon=!(bool)$eval['apto']?(string)$eval['razon']:
            (!$puntosOk?'Los puntos configurados no cubren el costo mínimo según V38.':
            ($stock<1?'Inventario virtual agotado.':
            ($saldo<$puntos?'Saldo insuficiente del cliente de prueba.':'Sólo simulación: cumple los controles del laboratorio.')));
        return [
            'margen_bruto_pct'=>$margen,
            'utilidad_bruta_centavos'=>$utilidad,
            'puntos_por_venta_normal'=>$puntosCompra,
            'reserva_puntos_venta_centavos'=>$reserva,
            'utilidad_bruta_tras_reserva_centavos'=>$utilidad-$reserva,
            'margen_tras_reserva_pct'=>$margenAjustado,
            'costo_recompensa_centavos'=>$costo,
            'presupuesto_puntos_centavos'=>$puntos*10,
            'minimo_puntos'=>$eval['minimo_puntos'],
            'apto_margen'=>(bool)$eval['apto'],
            'simulacion_posible'=>$apto,
            'razon'=>$razon,
            'saldo_hipotetico'=>$apto?$saldo-$puntos:$saldo,
            'stock_hipotetico'=>$apto?$stock-1:$stock,
            'aviso'=>'No es una venta ni un canje. No genera ingresos ni modifica existencias o puntos.'
        ];
    }
    public static function clientes(PDO $db):array {
        self::entorno($db);
        $r=self::q($db,'SELECT c.id,c.codigo,c.nombre,c.activo,c.acepta_programa,l.escenario
            FROM gyd_rewards_lab_clientes_v40 l JOIN gyd_clientes c ON c.id=l.cliente_id
            WHERE c.codigo LIKE \'LAB40-%\' ORDER BY c.codigo')->fetchAll(PDO::FETCH_ASSOC);
        foreach($r as &$c)$c['puntos']=(int)GydClientesRewards::saldo($db,(int)$c['id']);
        unset($c);return $r;
    }
    public static function sucursales(PDO $db):array {return GydRewardsCatalogoV38::sucursales($db);}
    public static function buscar(PDO $db,int $sucursal,string $texto):array {
        return GydRewardsCatalogoV38::buscarProductos($db,$sucursal,$texto);
    }
    public static function escenarios(PDO $db,int $sucursal=0):array {
        self::entorno($db);
        $where=$sucursal>0?'WHERE l.sucursal_id=?':'';
        $r=self::q($db,"SELECT l.sucursal_id,l.producto_id,l.precio_centavos,l.costo_centavos,
            l.stock_virtual,l.puntos_requeridos,l.escenario,l.actualizado_en,
            p.codigo,p.descripcion,s.nombre AS sucursal_nombre,
            pp.precio_publico AS precio_real,
            (SELECT MAX(t.costo_unitario) FROM lotes t WHERE t.producto_id=l.producto_id AND t.costo_unitario>0) AS costo_real
            FROM gyd_rewards_lab_productos_v40 l
            JOIN productos p ON p.id=l.producto_id JOIN pos_sucursales s ON s.id=l.sucursal_id
            LEFT JOIN pos_precios pp ON pp.sucursal_id=l.sucursal_id AND pp.producto_id=l.producto_id
            $where ORDER BY l.sucursal_id,l.escenario,l.producto_id LIMIT 150",$sucursal>0?[$sucursal]:[])->fetchAll(PDO::FETCH_ASSOC);
        foreach($r as &$x){
            $x['indicadores']=self::calculo((int)$x['precio_centavos'],(int)$x['costo_centavos'],(int)$x['stock_virtual'],(int)$x['puntos_requeridos'],0);
        }unset($x);return $r;
    }
    private static function valEntero(mixed $n,int $min,int $max,string $campo):int {
        $i=filter_var($n,FILTER_VALIDATE_INT,['options'=>['min_range'=>$min,'max_range'=>$max]]);
        if($i===false)throw new DomainException('Valor inválido: '.$campo.'.');
        return (int)$i;
    }
    public static function guardar(PDO $db,array $data,int $actor):array {
        self::entorno($db);
        if($actor<1)throw new DomainException('Usuario no identificado.');
        $sid=self::valEntero($data['sucursal_id']??null,1,9999,'sucursal');
        $pid=self::valEntero($data['producto_id']??null,1,2147483647,'producto');
        $stock=self::valEntero($data['stock_virtual']??null,0,100000,'inventario virtual');
        $puntos=self::valEntero($data['puntos_requeridos']??null,1,2000000,'puntos');
        $precio=GydRewardsCatalogoV38::centavos(isset($data['precio'])?(string)$data['precio']:null);
        $costo=GydRewardsCatalogoV38::centavos(isset($data['costo'])?(string)$data['costo']:null,true);
        if($precio===null||$precio<1||$precio>50000000||$costo===null||$costo<1||$costo>50000000)
            throw new DomainException('Precio y costo de prueba deben ser mayores a $0 y menores o iguales a $500,000.');
        $existe=self::q($db,'SELECT p.id FROM productos p JOIN pos_sucursales s ON s.id=? AND s.activo=1 WHERE p.id=? AND p.estado=1',[$sid,$pid])->fetchColumn();
        if(!$existe)throw new DomainException('La sucursal o el producto no existe o no está activo.');
        if($db->inTransaction())throw new RuntimeException('Transacción externa no admitida.');
        $db->beginTransaction();
        try {
            $anterior=self::q($db,'SELECT * FROM gyd_rewards_lab_productos_v40 WHERE sucursal_id=? AND producto_id=? FOR UPDATE',[$sid,$pid])->fetch(PDO::FETCH_ASSOC);
            self::q($db,'INSERT INTO gyd_rewards_lab_productos_v40(sucursal_id,producto_id,precio_centavos,costo_centavos,stock_virtual,puntos_requeridos,escenario,actualizado_por)
                VALUES(?,?,?,?,?,?,\'PERSONALIZADO\',?) ON DUPLICATE KEY UPDATE precio_centavos=VALUES(precio_centavos),costo_centavos=VALUES(costo_centavos),stock_virtual=VALUES(stock_virtual),puntos_requeridos=VALUES(puntos_requeridos),escenario=\'PERSONALIZADO\',actualizado_por=VALUES(actualizado_por)',[$sid,$pid,$precio,$costo,$stock,$puntos,$actor]);
            $nuevo=['sucursal_id'=>$sid,'producto_id'=>$pid,'precio_centavos'=>$precio,'costo_centavos'=>$costo,'stock_virtual'=>$stock,'puntos_requeridos'=>$puntos,'escenario'=>'PERSONALIZADO'];
            self::q($db,'INSERT INTO gyd_rewards_lab_auditoria_v40(sucursal_id,producto_id,usuario_id,accion,antes_json,despues_json) VALUES(?,?,?,?,?,?)',[
                $sid,$pid,$actor,$anterior?'MODIFICAR':'CREAR',$anterior?json_encode($anterior,JSON_UNESCAPED_UNICODE):null,json_encode($nuevo,JSON_UNESCAPED_UNICODE)
            ]);
            $db->commit();return ['guardado'=>true,'escenario'=>$nuevo,'indicadores'=>self::calculo($precio,$costo,$stock,$puntos,0)];
        } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function simular(PDO $db,int $sid,int $pid,int $cid):array {
        self::entorno($db);
        $r=self::q($db,'SELECT l.*,p.descripcion,p.codigo FROM gyd_rewards_lab_productos_v40 l JOIN productos p ON p.id=l.producto_id WHERE l.sucursal_id=? AND l.producto_id=?',[$sid,$pid])->fetch(PDO::FETCH_ASSOC);
        if(!$r)throw new DomainException('Configura primero el inventario de laboratorio para este producto.');
        $c=self::q($db,'SELECT c.id,c.nombre,c.codigo,c.activo,c.acepta_programa FROM gyd_clientes c JOIN gyd_rewards_lab_clientes_v40 lab ON lab.cliente_id=c.id WHERE c.id=?',[$cid])->fetch(PDO::FETCH_ASSOC);
        if(!$c || (int)$c['activo']!==1 || (int)$c['acepta_programa']!==1 || !str_starts_with((string)$c['codigo'],'LAB40-'))
            throw new DomainException('Selecciona un cliente ficticio activo de V40.');
        $saldo=max(0,(int)GydClientesRewards::saldo($db,$cid));
        $pendiente=GydRewardsReversionesV37::hayParcialesPendientes($db,$cid);
        $indicadores=self::calculo((int)$r['precio_centavos'],(int)$r['costo_centavos'],(int)$r['stock_virtual'],(int)$r['puntos_requeridos'],$saldo);
        if($pendiente){$indicadores['simulacion_posible']=false;
            $indicadores['saldo_hipotetico']=$saldo;
            $indicadores['stock_hipotetico']=(int)$r['stock_virtual'];
            $indicadores['razon']='Cliente con devolución parcial sin conciliar: simulación bloqueada.';
        }
        return ['cliente'=>$c,'saldo'=>$saldo,'articulo'=>$r,
            'indicadores'=>$indicadores,'pendiente_devolucion'=>$pendiente,'canje_real_habilitado'=>false];
    }
    /** Datos de demostracion: INSERT IGNORE sin sobreescribir modificaciones del usuario. */
    public static function sembrar(PDO $db,?int $actor=null):array {
        self::entorno($db);
        if($db->inTransaction())throw new RuntimeException('Transacción externa no admitida.');
        $creados=0;$ajustes=0;$articulos=0;$sucursales=[];
        $db->beginTransaction();
        try {
            foreach(self::CLIENTES as [$codigo,$nombre,$puntos,$escenario]){
                $c=self::q($db,'SELECT id,nombre FROM gyd_clientes WHERE codigo=? FOR UPDATE',[$codigo])->fetch(PDO::FETCH_ASSOC);
                if($c && !str_starts_with((string)$c['nombre'],'[PRUEBA]'))
                    throw new RuntimeException('Colisión de código con cliente no ficticio: '.$codigo);
                if(!$c){
                    self::q($db,'INSERT INTO gyd_clientes(codigo,nombre,telefono,correo,acepta_programa,consentimiento_en,activo,creado_por)
                        VALUES (?,?,NULL,NULL,1,NOW(),1,?)',[$codigo,$nombre,$actor]);
                    $cid=(int)$db->lastInsertId();$creados++;
                } else {$cid=(int)$c['id'];}
                self::q($db,'INSERT IGNORE INTO gyd_rewards_lab_clientes_v40(cliente_id,codigo,escenario) VALUES(?,?,?)',[$cid,$codigo,$escenario]);
                if($puntos>0){
                    $stmt=self::q($db,'INSERT IGNORE INTO gyd_puntos_movimientos(cliente_id,venta_id,origen_clave,tipo,puntos,valor_reserva_centavos,detalle,creado_por)
                        VALUES(?,NULL,?,\'AJUSTE\',?,?,?,?)',[$cid,'LAB-V40-'.$codigo,$puntos,$puntos*10,'AJUSTE DE DEMOSTRACIÓN V40 - puntos ficticios; no canjeables', $actor]);
                    if($stmt->rowCount()>0)$ajustes++;
                }
            }
            $sucursales=self::q($db,'SELECT id,nombre FROM pos_sucursales WHERE activo=1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            foreach($sucursales as $s){
                $sid=(int)$s['id'];
                $candidatos=self::q($db,'SELECT p.id,p.descripcion,pp.precio_publico,
                    (SELECT MAX(l.costo_unitario) FROM lotes l WHERE l.producto_id=p.id AND l.costo_unitario>0) AS costo_maximo
                    FROM productos p JOIN pos_precios pp ON pp.producto_id=p.id AND pp.sucursal_id=?
                    WHERE p.estado=1 AND pp.precio_publico>0 ORDER BY p.id LIMIT 1000',[$sid])->fetchAll(PDO::FETCH_ASSOC);
                $tipos=['RENTABLE','MARGEN_BAJO','SIN_EXISTENCIA','PUNTOS_BAJOS'];$slot=0;
                foreach($candidatos as $x){
                    $pr=GydRewardsCatalogoV38::centavos((string)$x['precio_publico']);
                    $co=GydRewardsCatalogoV38::centavos($x['costo_maximo']===null?null:(string)$x['costo_maximo'],true);
                    if($pr===null||$pr<100||$pr>50000000||$co===null||$co<1||$co>50000000)continue;
                    $tipo=$tipos[$slot];
                    if($tipo!=='MARGEN_BAJO' && $co*100>$pr*80) $co=intdiv($pr*65,100);
                    if($tipo==='MARGEN_BAJO')$co=intdiv($pr*90,100);
                    if($co<1)continue;
                    $min=intdiv($co,8)+($co%8?1:0);
                    if($min>2000000)continue;
                    $puntos=$tipo==='PUNTOS_BAJOS'?max(1,intdiv($min,2)):$min;
                    if($tipo==='PUNTOS_BAJOS' && $min<2)continue;
                    $stock=$tipo==='SIN_EXISTENCIA'?0:($tipo==='RENTABLE'?12:5);
                    $stmt=self::q($db,'INSERT IGNORE INTO gyd_rewards_lab_productos_v40(sucursal_id,producto_id,precio_centavos,costo_centavos,stock_virtual,puntos_requeridos,escenario,actualizado_por)
                        VALUES(?,?,?,?,?,?,?,?)',[$sid,(int)$x['id'],$pr,$co,$stock,$puntos,$tipo,$actor]);
                    if($stmt->rowCount()>0)$articulos++;
                    $slot++;if($slot>=4)break;
                }
            }
            $db->commit();
            return ['clientes_nuevos'=>$creados,'ajustes_puntos_nuevos'=>$ajustes,'articulos_virtuales_nuevos'=>$articulos,'sucursales_activas'=>count($sucursales),'sin_sobrescribir'=>true];
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function auditoria(PDO $db):array {
        self::entorno($db);
        return self::q($db,'SELECT a.id,a.accion,a.creado_en,a.usuario_id,s.nombre AS sucursal,p.descripcion AS producto
             FROM gyd_rewards_lab_auditoria_v40 a JOIN pos_sucursales s ON s.id=a.sucursal_id
             JOIN productos p ON p.id=a.producto_id ORDER BY a.id DESC LIMIT 30')->fetchAll(PDO::FETCH_ASSOC);
    }
}
