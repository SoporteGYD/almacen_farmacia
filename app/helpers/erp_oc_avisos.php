<?php
declare(strict_types=1);
require_once __DIR__.'/erp_oc_recepciones.php';
require_once __DIR__.'/../config/database.php';

/** Conteos derivados en MySQL, nunca de una campana manipulable del cliente. */
function ocav_conteos(PDO $pdo, array $usuario): array {
    $rol = strtoupper(trim((string)($usuario['rol']??'')));
    $alm = (int)($usuario['almacen_id']??0);
    $out=['almacen'=>0,'compras'=>0];
    if(in_array($rol,['ADMINISTRADOR','ADMIN','DIRECCION','ENCARGADO','ALMACEN','JEFE_ALMACEN'],true)) {
        $destino = [1=>'HIDALGO_BODEGA',3=>'TUXTLA_BODEGA'][$alm] ?? null;
        // Un usuario de Bodega sólo ve lo asignado; Dirección/Admin ve todos.
        $admin = in_array($rol,['ADMINISTRADOR','ADMIN','DIRECCION'],true);
        if ($admin || $destino !== null) {
            $sql="SELECT COUNT(*) FROM erp_ordenes_compra o
             JOIN (SELECT orden_id,SUM(cantidad_piezas) solicitado FROM erp_ordenes_compra_detalle GROUP BY orden_id) od ON od.orden_id=o.id
             LEFT JOIN (SELECT r.orden_id,SUM(rd.cantidad_piezas) recibido FROM erp_oc_recepciones r
                 JOIN erp_oc_recepciones_detalle rd ON rd.recepcion_id=r.id
                 WHERE r.estado='REGISTRADA' GROUP BY r.orden_id) rr ON rr.orden_id=o.id
             WHERE o.estado='EMITIDA' AND o.destino IN ('HIDALGO_BODEGA','TUXTLA_BODEGA')
               AND od.solicitado > COALESCE(rr.recibido,0)";
            $args=[];
            if (!$admin) {$sql.=" AND o.destino=?"; $args[]=$destino;}
            $s=$pdo->prepare($sql);$s->execute($args);$out['almacen']=(int)$s->fetchColumn();
        }
    }
    if(in_array($rol,['ADMINISTRADOR','ADMIN','DIRECCION','COMPRAS'],true)) {
        // El contador sólo conserva avisos que todavía no están documentados
        // ni fueron marcados manualmente como leídos.
        require_once __DIR__.'/erp_oc_aviso_estado.php';
        $pendientes=$pdo->query("SELECT id,orden_id,recepcion_id,estado FROM erp_oc_avisos_compras WHERE estado='PENDIENTE'")->fetchAll(PDO::FETCH_ASSOC);
        $estados=oc_status_batch($pdo,$pendientes);
        foreach($pendientes as $a){
            $s=$estados[(int)$a['id']]??null;
            if($s===null || !$s['lectura'])$out['compras']++;
        }
    }
    return $out;
}

/** Fuente canónica de una entrada originada por una recepción registrada. */
function ocav_origen_entrada(PDO $pdo, int $recepcionId, array $usuario): array {
    $st=$pdo->prepare("SELECT r.id,r.folio,r.destino,r.orden_id,r.estado,o.folio AS oc_folio,
       p.nombre AS proveedor,o.proveedor_id,v.movimiento_id
       FROM erp_oc_recepciones r
       JOIN erp_ordenes_compra o ON o.id=r.orden_id
       JOIN proveedores p ON p.id=o.proveedor_id
       LEFT JOIN erp_oc_entrada_vinculos v ON v.recepcion_id=r.id
       WHERE r.id=?");
    $st->execute([$recepcionId]);$r=$st->fetch(PDO::FETCH_ASSOC);
    if(!$r || $r['estado']!=='REGISTRADA')throw new RuntimeException('Recepción no encontrada o anulada.');
    $destino=(string)$r['destino'];
    $almacenId=['HIDALGO_BODEGA'=>1,'TUXTLA_BODEGA'=>3][$destino]??0;
    if($almacenId===0)throw new RuntimeException('Esta recepción corresponde a Farmacia, no a una entrada física de Bodega. Se aplica por el flujo de factura/POS.');
    $perm=erpr_acceso($usuario);
    if(!erpr_puede_registrar_destino($perm,$destino))throw new RuntimeException('No puedes registrar una entrada en ese Almacén.');
    if((int)($r['movimiento_id']??0)>0)throw new RuntimeException('El folio de recepción ya fue aplicado al Almacén. No se admite una segunda entrada.');
    // Bloqueo cruzado: la factura puede haber aplicado previamente al mismo Almacén.
    $s=$pdo->prepare("SELECT COUNT(*) FROM erp_facturas_stock_aplicaciones a
         JOIN erp_facturas f ON f.id=a.factura_id
         WHERE f.orden_id=? AND a.destino='ALMACEN'");
    $s->execute([(int)$r['orden_id']]);
    if((int)$s->fetchColumn()>0)throw new RuntimeException('Ya existen piezas de esta OC aplicadas al Almacén desde una factura. Requiere conciliación previa, no se debe duplicar la entrada.');

    $st=$pdo->prepare("SELECT rd.producto_id,rd.cantidad_piezas AS cantidad,rd.lote,rd.caducidad,
       d.codigo_snapshot AS codigo,d.descripcion_snapshot AS descripcion,d.costo_unitario AS costo
       FROM erp_oc_recepciones_detalle rd
       JOIN erp_ordenes_compra_detalle d ON d.id=rd.orden_detalle_id
       WHERE rd.recepcion_id=? ORDER BY rd.id");
    $st->execute([$recepcionId]);$lineas=$st->fetchAll(PDO::FETCH_ASSOC);
    if(!$lineas)throw new RuntimeException('La recepción no contiene artículos válidos.');
    $r['almacen_id']=$almacenId;$r['lineas']=$lineas;
    return $r;
}

/** La entrada debe coincidir con la recepción; ubicación editable, cantidades no falsificables. */
function ocav_validar_entrada(array $post, array $origen): void {
    $id=(int)$origen['almacen_id'];
    if((int)($post['almacen_id']??0)!==$id)throw new RuntimeException('La entrada debe registrarse en el Almacén indicado por la OC.');
    $pid=$post['producto_id']??[];$qty=$post['cantidad']??[];$lot=$post['numero_lote']??[];
    if(!is_array($pid)||!is_array($qty)||!is_array($lot)||count($pid)!==count($qty))throw new RuntimeException('Las partidas de entrada no son válidas.');
    $actual=[];$expected=[];
    foreach($origen['lineas'] as $l){
        $key=(int)$l['producto_id'].'|'.trim((string)($l['lote']??''));
        $expected[$key]=($expected[$key]??0)+(int)$l['cantidad'];
    }
    foreach($pid as $i=>$p){
        $cant=filter_var($qty[$i],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if(!$cant)throw new RuntimeException('Cantidad inválida en la entrada precargada.');
        $key=(int)$p.'|'.trim((string)($lot[$i]??''));
        $actual[$key]=($actual[$key]??0)+(int)$cant;
    }
    ksort($actual);ksort($expected);
    if($actual!==$expected)throw new RuntimeException('La cantidad o lote fue modificado y ya no coincide con la recepción. Registra la diferencia como incidencia; no ajustes inventario para hacerlo cuadrar.');
}
