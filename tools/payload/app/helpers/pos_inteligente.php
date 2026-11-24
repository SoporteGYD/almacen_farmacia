<?php
declare(strict_types=1);
/** Stock POS GENERAL vs. conteo FÍSICO de farmacia vs. almacén (nunca sumar al POS en transferencias). */
function pi_almacen(int $sucursal):int {return match($sucursal){1=>1,2=>3,default=>0};}
function pi_ubicacion(PDO $db,int $sucursal,int $producto,bool $lock=false):array {
    $alm=pi_almacen($sucursal);
    $st=$db->prepare('SELECT existencia,minimo FROM erp_pos_farmacia_control WHERE sucursal_id=? AND producto_id=?'.($lock?' FOR UPDATE':''));
    $st->execute([$sucursal,$producto]);$r=$st->fetch(PDO::FETCH_ASSOC)?:null;
    $pos=$db->prepare('SELECT existencia FROM pos_existencias_farmacia WHERE sucursal_id=? AND producto_id=?');
    $pos->execute([$sucursal,$producto]);$general=(int)($pos->fetchColumn()?:0);
    $resPos=$db->prepare("SELECT COALESCE(SUM(t.cantidad),0) FROM erp_pos_tickets_almacen t JOIN resurtidos r ON r.id=t.resurtido_id WHERE t.sucursal_id=? AND t.producto_id=? AND t.venta_id IS NULL AND r.estado<>'CANCELADO'");
    $resPos->execute([$sucursal,$producto]);$reservadoPOS=(int)$resPos->fetchColumn();
    $bodega=0;$apartado=0;
    if($alm){
        $loc=$alm===1?['CIUDAD HIDALGO','CD HIDALGO']:['TUXTLA','TUXTLA GUTIERREZ','TUXTLA GUTIÉRREZ'];
        $placeholders=implode(',',array_fill(0,count($loc),'?'));
        $sql="SELECT COALESCE(SUM(GREATEST(pe.existencia,0)),0) FROM producto_existencias pe WHERE pe.producto_id=? AND UPPER(TRIM(pe.sucursal)) COLLATE utf8mb4_general_ci IN ($placeholders) AND TRIM(COALESCE(pe.ubicacion,''))<>'' AND UPPER(TRIM(pe.ubicacion)) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION','SIN UBICACIÓN')";
        $st=$db->prepare($sql);$st->execute(array_merge([$producto],$loc));$bodega=(int)$st->fetchColumn();
        $st=$db->prepare("SELECT COALESCE(SUM(GREATEST(d.cantidad_solicitada-d.cantidad_surtida,0)),0) FROM resurtido_detalles d JOIN resurtidos r ON r.id=d.resurtido_id WHERE d.producto_id=? AND r.almacen_id=? AND r.estado IN ('PENDIENTE','EN_PROCESO','PARCIAL')");
        $st->execute([$producto,$alm]);$apartado=(int)$st->fetchColumn();
    }
    return ['general'=>$general,'reservado_pos'=>$reservadoPOS,'general_disponible'=>max(0,$general-$reservadoPOS),'farmacia'=>$r?(int)$r['existencia']:null,'minimo'=>$r?(int)$r['minimo']:24,'almacen'=>$bodega,'almacen_disponible'=>max(0,$bodega-$apartado),'configurada'=>(bool)$r,'tiene_almacen'=>(bool)$alm];
}
/** El almacén será origen preferido si la venta tocaría el mínimo; si no alcanza, permite farmacia si alcanza físicamente. */
function pi_decidir(array $s,int $qty):string {
 if($qty<1||$qty>$s['general_disponible'])return 'INSUFICIENTE';
 if(!$s['tiene_almacen'])return 'FARMACIA_GENERAL';
 if(!$s['configurada'])return 'SIN_CONTEO';
 $f=$s['farmacia'];$a=$s['almacen_disponible'];$m=$s['minimo'];
 if($qty<=max(0,$f-$m))return 'FARMACIA';
 if($qty<=$a)return 'ALMACEN';
 if($qty<=$f)return 'FARMACIA';
 return 'SIN_ORIGEN';
}
function pi_alerta(PDO $db,int $sucursal,int $producto,int $cantidad,int $minimo):void {
 if($cantidad<=$minimo){
  $db->prepare("INSERT INTO erp_pos_alertas(sucursal_id,producto_id,estado,existencia,minimo,actualizada_en) VALUES (?,?,'ABIERTA',?,?,NOW()) ON DUPLICATE KEY UPDATE estado='ABIERTA',existencia=VALUES(existencia),minimo=VALUES(minimo),actualizada_en=NOW()") ->execute([$sucursal,$producto,$cantidad,$minimo]);
 } else {
  $db->prepare("UPDATE erp_pos_alertas SET estado='RESUELTA',existencia=?,actualizada_en=NOW() WHERE sucursal_id=? AND producto_id=? AND estado='ABIERTA'")->execute([$cantidad,$sucursal,$producto]);
 }
}


/**
 * F1 - Exclusión mutua del pool lógico Tapachula/Toscana.
 * Las dos sucursales siguen teniendo sus propias filas de stock hasta su conciliación;
 * este candado no suma, traspasa ni descuenta piezas. Unifica SOLO la serialización.
 */
function pi_f1_clave_candado_stock(int $sucursal,int $producto):string {
    if ($sucursal<1 || $producto<1) throw new DomainException('Producto o sucursal invalidos.');
    return in_array($sucursal,[3,4],true)
        ? 'gyd_pos_pool_tt_'.$producto
        : 'gyd_pos_'.$sucursal.'_'.$producto;
}
/**
 * Referencia de existencias registradas en las dos filas POS. Esta suma NO es
 * disponibilidad autorizada ni certifica que ambas cargas sean distintas.
 */
function pi_f1_stock_compartido_referencial(PDO $db,int $sucursal,int $producto):?array {
    if (!in_array($sucursal,[3,4],true)) return null;
    if ($producto<1) throw new DomainException('Producto invalido.');
    $stmt=$db->prepare('SELECT sucursal_id,existencia FROM pos_existencias_farmacia WHERE producto_id=? AND sucursal_id IN (3,4) ORDER BY sucursal_id');
    $stmt->execute([$producto]);
    $sal=[3=>0,4=>0];
    $presentes=[3=>false,4=>false];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $id=(int)$fila['sucursal_id'];
        $sal[$id]+=(int)$fila['existencia'];
        $presentes[$id]=true;
    }
    return [
        'tapachula'=>$sal[3],
        'toscana'=>$sal[4],
        'suma_referencial'=>$sal[3]+$sal[4],
        'filas_en_ambas'=>$presentes[3] && $presentes[4],
        'conciliado'=>false,
        'apto_para_cobro'=>false,
    ];
}

/**
 * Candado entre cajeros y solicitudes POS de un mismo producto/sucursal.
 * No descuenta inventario: evita que dos procesos consuman simultáneamente
 * el mismo disponible antes de que la reserva/salida quede persistida.
 * El modelo Resurtido conserva su propia validación transaccional de bodega.
 */
function pi_bloquear_pos(PDO $db,int $sucursal,int $producto):string {
    if($sucursal<1 || $producto<1) throw new DomainException('Producto o sucursal inválidos.');
    $clave=pi_f1_clave_candado_stock($sucursal,$producto);
    $st=$db->prepare('SELECT GET_LOCK(?, 8)');
    $st->execute([$clave]);
    if((int)$st->fetchColumn()!==1) throw new DomainException('Otro cajero está procesando este producto. Intenta de nuevo en unos segundos.');
    return $clave;
}
function pi_liberar_pos(PDO $db,string $clave):void {
    if($clave==='') return;
    try{$st=$db->prepare('SELECT RELEASE_LOCK(?)');$st->execute([$clave]);}
    catch(Throwable $e){error_log('POS release lock: '.$e->getMessage());}
}
/** Tickets cobrados que aún figuran físicamente en el almacén.
 * Ya fueron descontados del POS general; no descontarlos una segunda vez.
 */
function pi_pendientes_entrega(PDO $db,int $sucursal,int $producto):array {
    $st=$db->prepare("SELECT
        COALESCE(SUM(CASE WHEN t.venta_id IS NOT NULL THEN GREATEST(t.cantidad-COALESCE(d.cantidad_surtida,0),0) ELSE 0 END),0) AS vendido_pendiente,
        COALESCE(SUM(CASE WHEN t.venta_id IS NULL AND r.estado IN ('PENDIENTE','EN_PROCESO','PARCIAL') THEN GREATEST(t.cantidad-COALESCE(d.cantidad_surtida,0),0) ELSE 0 END),0) AS solicitado_sin_cobro
        FROM erp_pos_tickets_almacen t
        INNER JOIN resurtidos r ON r.id=t.resurtido_id
        INNER JOIN resurtido_detalles d ON d.resurtido_id=r.id AND d.producto_id=t.producto_id
        WHERE t.sucursal_id=? AND t.producto_id=? AND r.estado<>'CANCELADO'");
    $st->execute([$sucursal,$producto]);$r=$st->fetch(PDO::FETCH_ASSOC)?:[];
    return ['vendido_pendiente'=>(int)round((float)($r['vendido_pendiente']??0)),
            'solicitado_sin_cobro'=>(int)round((float)($r['solicitado_sin_cobro']??0))];
}
