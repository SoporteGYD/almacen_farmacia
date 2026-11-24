<?php
declare(strict_types=1);
/**
 * ERP G&D. Conciliación DOCUMENTAL para registrar factura aunque la orden no
 * haya llegado completa. Nunca mueve existencias, costo ni precios.
 * Requiere bloqueo previo de la fila de la orden en operaciones POST.
 */
function ef_oc_saldo_documental(PDO $db, int $ordenId): array {
    if ($ordenId < 1) throw new RuntimeException('Orden de compra inválida.');
    $st=$db->prepare("SELECT d.producto_id,MIN(d.codigo_snapshot) codigo,
        MIN(d.descripcion_snapshot) descripcion,SUM(d.cantidad_piezas) solicitado,
        ROUND(SUM(d.cantidad_piezas*d.costo_unitario)/NULLIF(SUM(d.cantidad_piezas),0),4) costo,
        MAX(COALESCE(p.estado,0)) activo
        FROM erp_ordenes_compra_detalle d LEFT JOIN productos p ON p.id=d.producto_id
        WHERE d.orden_id=? GROUP BY d.producto_id ORDER BY MIN(d.id)");
    $st->execute([$ordenId]);$pedido=$st->fetchAll(PDO::FETCH_ASSOC);
    if (!$pedido) throw new RuntimeException('La orden de compra no tiene partidas.');

    $st=$db->prepare("SELECT COUNT(*) FROM erp_oc_recepciones WHERE orden_id=? AND estado='REGISTRADA'");
    $st->execute([$ordenId]);$tieneRecepciones=(int)$st->fetchColumn()>0;

    $st=$db->prepare("SELECT rd.producto_id,SUM(rd.cantidad_piezas) AS recibidas
        FROM erp_oc_recepciones r
        JOIN erp_oc_recepciones_detalle rd ON rd.recepcion_id=r.id
        JOIN erp_ordenes_compra_detalle od ON od.id=rd.orden_detalle_id
          AND od.orden_id=r.orden_id AND od.producto_id=rd.producto_id
        WHERE r.orden_id=? AND r.estado='REGISTRADA'
        GROUP BY rd.producto_id");
    $st->execute([$ordenId]);$rec=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r) $rec[(int)$r['producto_id']]=(int)$r['recibidas'];

    $st=$db->prepare("SELECT fd.producto_id,SUM(fd.cantidad_facturada) AS facturadas
        FROM erp_facturas f JOIN erp_facturas_detalle fd ON fd.factura_id=f.id
        WHERE f.orden_id=? GROUP BY fd.producto_id");
    $st->execute([$ordenId]);$fac=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r) $fac[(int)$r['producto_id']]=(int)$r['facturadas'];

    $partidas=[];$porProducto=[];
    $tot=['solicitadas'=>0,'recibidas'=>0,'facturadas'=>0,'facturables'=>0,
          'por_recibir'=>0,'restantes_oc'=>0,'facturadas_no_recibidas'=>0];
    foreach($pedido as $d){
        $id=(int)$d['producto_id'];$sol=(int)$d['solicitado'];
        $recibido=$rec[$id]??0;$facturado=$fac[$id]??0;
        if($sol<1||$recibido>$sol||$facturado>$sol){
            throw new RuntimeException('Inconsistencia histórica para '.(string)$d['descripcion'].'.'
                .' Hay cantidades mayores a las de la orden: concilia los registros antes de seguir.');
        }
        // CAPACIDAD DE DOCUMENTO = OC - facturado. NO depende de recepción física.
        $pendienteDoc=$sol-$facturado;
        $pendienteFisico=max(0,$sol-$recibido);
        $sinRecibirYaFacturado=max(0,$facturado-$recibido);
        $recibidasSinFacturar=max(0,$recibido-$facturado);
        $tot['solicitadas']+=$sol;
        $tot['recibidas']+=$recibido;
        $tot['facturadas']+=$facturado;
        $tot['facturables']+=$pendienteDoc;
        $tot['por_recibir']+=$pendienteFisico;
        $tot['restantes_oc']+=$pendienteDoc;
        $tot['facturadas_no_recibidas']+=$sinRecibirYaFacturado;
        $porProducto[$id]=['solicitado'=>$sol,'recibido'=>$recibido,
            'facturado'=>$facturado,'disponible'=>$pendienteDoc];
        if($pendienteDoc===0)continue;
        if((int)$d['activo']!==1)
            throw new RuntimeException('El producto pendiente está inactivo: '.(string)$d['descripcion'].'.');
        // Sólo se sugiere lo recibido aún sin facturar si hay tal cantidad.
        // Si el proveedor facturó toda la OC, Compras puede ampliar la cantidad
        // hasta el pendiente de la orden según el documento real.
        $sugerido=$recibidasSinFacturar>0?min($recibidasSinFacturar,$pendienteDoc):$pendienteDoc;
        $partidas[]=[
            'id'=>$id,'codigo'=>(string)$d['codigo'],
            'descripcion'=>(string)$d['descripcion'],
            'qty'=>$sugerido,'disponible'=>$pendienteDoc,
            'costo'=>(string)$d['costo'],
            'solicitado'=>$sol,'recibido'=>$recibido,'facturado'=>$facturado,
            'por_recibir'=>$pendienteFisico,
        ];
    }
    // GYD_DOC_FACTURAS_SIN_TOPE_V1
    return ['tiene_recepciones'=>$tieneRecepciones,'productos'=>$partidas,
        'por_producto'=>$porProducto,'totales'=>$tot];
}

/**
 * Con recepción de OC registrada, impedir que Verificación acepte más piezas
 * que las que figuran recibidas físicamente, sumando TODAS las facturas de OC.
 * Se debe llamar dentro de la transacción con la OC bloqueada FOR UPDATE.
 * Si no existen recepciones OC, deja la validación física al flujo de Verificación
 * (compatible con sucursales que no usan el módulo previo de recepciones).
 */
function ef_oc_proteger_aceptacion(PDO $db,int $ordenId,array $detalleFactura,array $porAceptar): void {
    // GYD_OC_ACEPTACION_NETA_V2: considera las conciliaciones auditadas.
    require_once __DIR__.'/erp_oc_seguimiento.php';
    erp_oc_proteger_aceptacion_neta($db,$ordenId,$detalleFactura,$porAceptar);
}
