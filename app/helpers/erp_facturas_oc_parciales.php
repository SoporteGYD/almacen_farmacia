<?php
declare(strict_types=1);
/**
 * Conciliación OC → recepciones históricas → entradas generales por factura.
 * No toca existencias, costo promedio ni precios. Sólo computa cuotas autorizadas.
 * Una misma orden puede tener varias recepciones parciales y varias facturas.
 * Las cantidades están expresadas en PIEZAS.
 * En operaciones de guardado debe invocarse después de bloquear la fila de la OC
 * mediante SELECT ... FOR UPDATE, antes de insertar factura/detalles.
 */
function ef_oc_saldo_facturable(PDO $db, int $ordenId): array {
    if ($ordenId < 1) throw new RuntimeException('Orden inválida.');

    $st = $db->prepare('SELECT d.producto_id, MIN(d.codigo_snapshot) AS codigo, MIN(d.descripcion_snapshot) AS descripcion,
       SUM(d.cantidad_piezas) AS solicitado,
       ROUND(SUM(d.cantidad_piezas * d.costo_unitario) / NULLIF(SUM(d.cantidad_piezas),0),4) AS costo,
       MAX(p.estado) AS producto_activo
       FROM erp_ordenes_compra_detalle d LEFT JOIN productos p ON p.id=d.producto_id
       WHERE d.orden_id=? GROUP BY d.producto_id ORDER BY MIN(d.id)');
    $st->execute([$ordenId]);
    $pedido = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$pedido) throw new RuntimeException('La orden no contiene productos.');

    $st=$db->prepare("SELECT COUNT(*) FROM erp_oc_recepciones WHERE orden_id=? AND estado='REGISTRADA'");
    $st->execute([$ordenId]);
    $tieneRecepciones = (int)$st->fetchColumn() > 0;

    $st=$db->prepare("SELECT rd.producto_id, SUM(rd.cantidad_piezas) recibidas
       FROM erp_oc_recepciones r
       JOIN erp_oc_recepciones_detalle rd ON rd.recepcion_id=r.id
       JOIN erp_ordenes_compra_detalle d ON d.id=rd.orden_detalle_id
           AND d.orden_id=r.orden_id AND d.producto_id=rd.producto_id
       WHERE r.orden_id=? AND r.estado='REGISTRADA'
       GROUP BY rd.producto_id");
    $st->execute([$ordenId]);
    $recibidas=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r)$recibidas[(int)$r['producto_id']]=(int)$r['recibidas'];

    $st=$db->prepare('SELECT fd.producto_id,SUM(fd.cantidad_facturada) facturadas
       FROM erp_facturas f JOIN erp_facturas_detalle fd ON fd.factura_id=f.id
       WHERE f.orden_id=? GROUP BY fd.producto_id');
    $st->execute([$ordenId]);
    $facturadas=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r)$facturadas[(int)$r['producto_id']]=(int)$r['facturadas'];

    $partidas=[];$porProducto=[];
    $tot=['solicitadas'=>0,'recibidas'=>0,'facturadas'=>0,'facturables'=>0,'por_recibir'=>0,'restantes_oc'=>0];
    foreach($pedido as $d){
        $id=(int)$d['producto_id'];
        $sol=(int)$d['solicitado'];
        $rec=$recibidas[$id]??0;
        $fac=$facturadas[$id]??0;
        if($sol<1||$rec>$sol||$fac>$sol)
            throw new RuntimeException('Las cantidades históricas de «'.(string)$d['descripcion'].'» superan lo autorizado; revisa la orden antes de continuar.');
        // Con recepciones existentes sólo se factura lo físicamente recibido.
        // Sin recepciones, se conserva la captura documental previa a la verificación.
        $limite=$tieneRecepciones ? $rec : $sol;
        $disponible=max(0,$limite-$fac);
        $pendienteFisico=max(0,$sol-$rec);
        $pendienteOrden=max(0,$sol-$fac);
        $tot['solicitadas']+=$sol;
        $tot['recibidas']+=$rec;
        $tot['facturadas']+=$fac;
        $tot['facturables']+=$disponible;
        $tot['por_recibir']+=$pendienteFisico;
        $tot['restantes_oc']+=$pendienteOrden;
        $porProducto[$id]=['solicitado'=>$sol,'recibido'=>$rec,'facturado'=>$fac,'disponible'=>$disponible];
        if($disponible<=0)continue;
        if((int)$d['producto_activo']!==1)
            throw new RuntimeException('Un artículo pendiente de facturar está inactivo en el catálogo: '.(string)$d['descripcion'].'.');
        $partidas[]=[
            'id'=>$id,'codigo'=>(string)$d['codigo'],'descripcion'=>(string)$d['descripcion'],
            'qty'=>$disponible,'disponible'=>$disponible,'costo'=>(string)$d['costo'],
            'solicitado'=>$sol,'recibido'=>$rec,'facturado'=>$fac,
            'por_recibir'=>$pendienteFisico,
        ];
    }
    // GYD_PARCIALES_FACTURAS_SIN_TOPE_V1
    return ['tiene_recepciones'=>$tieneRecepciones,'productos'=>$partidas,'por_producto'=>$porProducto,'totales'=>$tot];
}
