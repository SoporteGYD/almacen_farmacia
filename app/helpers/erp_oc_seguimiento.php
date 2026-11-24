<?php
declare(strict_types=1);
/**
 * G&D ERP: conciliaciones físicas con auditoría preservada.
 * Nunca cambia precios, facturas históricas ni inventarios al consultar.
 * Los AJUSTE_FALTANTE auditados revierten temporalmente una aceptación
 * histórica incorrecta; las REPOSICIONES hechas por el conciliador la restablecen.
 */
function erp_oc_neto_validado(int $bruto,int $ajuste,int $repuesto): int {
    if($bruto<0 || $ajuste<0 || $repuesto<0 || $repuesto>$ajuste){
        throw new RuntimeException('Historial de conciliación inválido: revisar antes de continuar.');
    }
    $neto=$bruto-$ajuste+$repuesto;
    if($neto<0)throw new RuntimeException('La conciliación supera las cantidades originales.');
    return $neto;
}
function erp_oc_ajustes_por_factura(PDO $db,int $facturaId): array {
    $q=$db->prepare("SELECT producto_id,
       COALESCE(SUM(CASE WHEN tipo='AJUSTE_FALTANTE' THEN cantidad ELSE 0 END),0) descontado,
       COALESCE(SUM(CASE WHEN tipo='REPOSICION' THEN cantidad ELSE 0 END),0) repuesto
      FROM erp_oc_conciliacion_eventos WHERE factura_id=? GROUP BY producto_id");
    $q->execute([$facturaId]);$out=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
        $out[(int)$r['producto_id']]=['descontado'=>(int)$r['descontado'],'repuesto'=>(int)$r['repuesto']];
    }
    return $out;
}
function erp_oc_ajustes_por_orden(PDO $db,int $ordenId): array {
    $q=$db->prepare("SELECT producto_id,
       COALESCE(SUM(CASE WHEN tipo='AJUSTE_FALTANTE' THEN cantidad ELSE 0 END),0) descontado,
       COALESCE(SUM(CASE WHEN tipo='REPOSICION' THEN cantidad ELSE 0 END),0) repuesto
      FROM erp_oc_conciliacion_eventos WHERE orden_id=? GROUP BY producto_id");
    $q->execute([$ordenId]);$out=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$out[(int)$r['producto_id']]=[(int)$r['descontado'],(int)$r['repuesto']];
    return $out;
}
function erp_oc_resumen_ajustado(PDO $db,int $facturaId,array $filas): array {
    if(!$filas)return $filas;
    $ajustes=erp_oc_ajustes_por_factura($db,$facturaId);
    foreach($filas as &$r){
        $pid=(int)$r['producto_id'];$d=$ajustes[$pid]??['descontado'=>0,'repuesto'=>0];
        $bruto=(int)$r['aceptadas'];
        $neto=erp_oc_neto_validado($bruto,$d['descontado'],$d['repuesto']);
        if($neto+(int)$r['incidencias_pendientes']>(int)$r['cantidad_facturada']){
            throw new RuntimeException('Verificación inconsistente para producto '.$pid.'. Requiere conciliación antes de continuar.');
        }
        $r['aceptadas_historicas']=$bruto;
        $r['aceptadas']=$neto;
        $r['conciliado_faltante']=$d['descontado']-$d['repuesto'];
    }
    unset($r);
    return $filas;
}
/** Se ejecuta bajo bloqueo FOR UPDATE de la OC, en la misma transacción. */
function erp_oc_proteger_aceptacion_neta(PDO $db,int $ordenId,array $detalleFactura,array $porAceptar): void {
    if($ordenId<1)return;
    $solicitud=[];$productos=[];
    foreach($detalleFactura as $d)$productos[(int)$d['id']]=[(int)$d['producto_id'],(string)$d['descripcion_snapshot']];
    foreach($porAceptar as $a){
        if(($a['tipo']??'')!=='ACEPTADA')continue;
        $did=(int)$a['id'];
        if(!isset($productos[$did]))throw new RuntimeException('Partida no pertenece a esta factura.');
        $pid=$productos[$did][0];$qty=(int)$a['cantidad'];
        if($qty<1)throw new RuntimeException('La cantidad aceptada debe ser positiva.');
        $solicitud[$pid]=($solicitud[$pid]??0)+$qty;
    }
    if(!$solicitud)return;
    $q=$db->prepare("SELECT COUNT(*) FROM erp_oc_recepciones WHERE orden_id=?");
    $q->execute([$ordenId]);
    if(!(int)$q->fetchColumn())return; // Conserva sólo flujo anterior de OC que jamás usaron el módulo de recepciones.
    $q=$db->prepare("SELECT d.producto_id,COALESCE(SUM(d.cantidad_piezas),0) n
       FROM erp_oc_recepciones r JOIN erp_oc_recepciones_detalle d ON d.recepcion_id=r.id
       JOIN erp_ordenes_compra_detalle od ON od.id=d.orden_detalle_id AND od.orden_id=r.orden_id AND od.producto_id=d.producto_id
       WHERE r.orden_id=? AND r.estado='REGISTRADA' GROUP BY d.producto_id");
    $q->execute([$ordenId]);$recibidas=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$recibidas[(int)$r['producto_id']]=(int)$r['n'];
    $q=$db->prepare("SELECT fd.producto_id,COALESCE(SUM(rd.cantidad),0) n
       FROM erp_facturas f JOIN erp_facturas_detalle fd ON fd.factura_id=f.id
       JOIN erp_facturas_revision_detalle rd ON rd.factura_detalle_id=fd.id AND rd.tipo='ACEPTADA'
       WHERE f.orden_id=? GROUP BY fd.producto_id");
    $q->execute([$ordenId]);$historicas=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$historicas[(int)$r['producto_id']]=(int)$r['n'];
    $ajustes=erp_oc_ajustes_por_orden($db,$ordenId);
    foreach($solicitud as $pid=>$qty){
        [$desc,$rep]=$ajustes[$pid]??[0,0];
        $net=erp_oc_neto_validado($historicas[$pid]??0,$desc,$rep);
        $rec=$recibidas[$pid]??0;
        if($net+$qty>$rec){
            throw new RuntimeException('No se pueden aceptar '.$qty.' piezas adicionales del producto '.$pid.
                ': recibidas vigentes '.$rec.', aceptadas netas '.$net.'. Registra antes la recepción física.');
        }
    }
}
/** Protección adicional para el inventario. Debe llamarse dentro de la transacción. */
function erp_oc_proteger_aplicacion_neta(PDO $db,int $ordenId,int $facturaId,int $productoId,int $nuevaCantidad): void {
    if($productoId<1 || $nuevaCantidad<1)throw new RuntimeException('Producto o cantidad inválidos.');
    $q=$db->prepare('SELECT orden_id FROM erp_facturas WHERE id=?');$q->execute([$facturaId]);
    if((int)$q->fetchColumn()!==$ordenId)throw new RuntimeException('La factura no pertenece a esta orden.');
    $q=$db->prepare('SELECT COALESCE(SUM(cantidad_facturada),0) FROM erp_facturas_detalle WHERE factura_id=? AND producto_id=?');
    $q->execute([$facturaId,$productoId]);$amparadas=(int)$q->fetchColumn();
    $q=$db->prepare('SELECT COALESCE(SUM(cantidad),0) FROM erp_facturas_stock_aplicaciones WHERE factura_id=? AND producto_id=?');
    $q->execute([$facturaId,$productoId]);$bruto=(int)$q->fetchColumn();
    $a=erp_oc_ajustes_por_factura($db,$facturaId)[$productoId]??['descontado'=>0,'repuesto'=>0];
    $net=erp_oc_neto_validado($bruto,$a['descontado'],$a['repuesto']);
    if($net+$nuevaCantidad>$amparadas){
        throw new RuntimeException('Entrada bloqueada: factura ampara '.$amparadas.' piezas y ya se aplicaron netas '.$net.'.');
    }
    if($ordenId<1)return;
    $q=$db->prepare("SELECT COUNT(*) FROM erp_oc_recepciones WHERE orden_id=?");
    $q->execute([$ordenId]);if(!(int)$q->fetchColumn())return;
    $q=$db->prepare("SELECT COALESCE(SUM(d.cantidad_piezas),0) FROM erp_oc_recepciones r
       JOIN erp_oc_recepciones_detalle d ON d.recepcion_id=r.id
       JOIN erp_ordenes_compra_detalle od ON od.id=d.orden_detalle_id AND od.orden_id=r.orden_id AND od.producto_id=d.producto_id
       WHERE r.orden_id=? AND r.estado='REGISTRADA' AND d.producto_id=?");
    $q->execute([$ordenId,$productoId]);$rec=(int)$q->fetchColumn();
    $q=$db->prepare('SELECT COALESCE(SUM(a.cantidad),0) FROM erp_facturas_stock_aplicaciones a JOIN erp_facturas f ON f.id=a.factura_id WHERE f.orden_id=? AND a.producto_id=?');
    $q->execute([$ordenId,$productoId]);$brutoOC=(int)$q->fetchColumn();
    $aa=erp_oc_ajustes_por_orden($db,$ordenId)[$productoId]??[0,0];
    $netOC=erp_oc_neto_validado($brutoOC,$aa[0],$aa[1]);
    if($netOC+$nuevaCantidad>$rec){
        throw new RuntimeException('Entrada bloqueada: OC tiene '.$rec.' recibidas, '.$netOC.' aplicadas netas; intento '.$nuevaCantidad.'.');
    }
}
function erp_oc_seguimiento_por_factura(PDO $db,int $facturaId): array {
    $q=$db->prepare('SELECT orden_id FROM erp_facturas WHERE id=?');$q->execute([$facturaId]);$orden=(int)$q->fetchColumn();
    if($orden<1)return [];
    $q=$db->prepare("SELECT d.producto_id,d.descripcion_snapshot producto,
      d.cantidad_facturada facturadas,
      COALESCE((SELECT SUM(rc.cantidad_piezas) FROM erp_oc_recepciones r JOIN erp_oc_recepciones_detalle rc ON rc.recepcion_id=r.id WHERE r.orden_id=? AND r.estado='REGISTRADA' AND rc.producto_id=d.producto_id),0) recibidas,
      COALESCE((SELECT SUM(rd.cantidad) FROM erp_facturas_revision_detalle rd WHERE rd.factura_detalle_id=d.id AND rd.tipo='ACEPTADA'),0) aceptadas_brutas,
      COALESCE((SELECT SUM(a.cantidad) FROM erp_facturas_stock_aplicaciones a WHERE a.factura_id=d.factura_id AND a.producto_id=d.producto_id),0) aplicadas_brutas
      FROM erp_facturas_detalle d WHERE d.factura_id=?");
    $q->execute([$orden,$facturaId]);$filas=$q->fetchAll(PDO::FETCH_ASSOC);
    $aj=erp_oc_ajustes_por_factura($db,$facturaId);
    foreach($filas as &$r){
        $pid=(int)$r['producto_id'];$c=$aj[$pid]??['descontado'=>0,'repuesto'=>0];
        $r['aceptadas_netas']=erp_oc_neto_validado((int)$r['aceptadas_brutas'],$c['descontado'],$c['repuesto']);
        $r['aplicadas_netas']=erp_oc_neto_validado((int)$r['aplicadas_brutas'],$c['descontado'],$c['repuesto']);
        $r['por_verificar']=max(0,(int)$r['facturadas']-(int)$r['aceptadas_netas']);
        $r['por_recibir']=max(0,(int)$r['facturadas']-(int)$r['recibidas']);
    }
    unset($r);return $filas;
}
