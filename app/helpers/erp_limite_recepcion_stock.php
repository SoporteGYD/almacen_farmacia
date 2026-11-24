<?php
declare(strict_types=1);
/** Verificación transversal: no aplicar más piezas netas que recibidas en OC. */
function erp_guard_limite_recepcion_stock(PDO $db,int $ordenId,int $productoId,int $agregar): void {
    if ($ordenId<1 || $productoId<1 || $agregar<1) return;
    $st=$db->prepare("SELECT COUNT(*) FROM erp_oc_recepciones WHERE orden_id=? AND estado='REGISTRADA'");
    $st->execute([$ordenId]);
    if (!(int)$st->fetchColumn()) return; // Sin flujo previo de recepciones: flujo de verificación física vigente.
    $st=$db->prepare("SELECT COALESCE(SUM(rd.cantidad_piezas),0)
     FROM erp_oc_recepciones r JOIN erp_oc_recepciones_detalle rd ON rd.recepcion_id=r.id
     JOIN erp_ordenes_compra_detalle od ON od.id=rd.orden_detalle_id
       AND od.orden_id=r.orden_id AND od.producto_id=rd.producto_id
     WHERE r.orden_id=? AND r.estado='REGISTRADA' AND rd.producto_id=?");
    $st->execute([$ordenId,$productoId]);$recibidas=(int)$st->fetchColumn();
    $st=$db->prepare("SELECT COALESCE(SUM(a.cantidad),0)
     FROM erp_facturas_stock_aplicaciones a JOIN erp_facturas f ON f.id=a.factura_id
     WHERE f.orden_id=? AND a.producto_id=?");
    $st->execute([$ordenId,$productoId]);$bruto=(int)$st->fetchColumn();
    // Un ajuste de faltante disminuye el neto; reposición posterior lo repone.
    $st=$db->prepare("SELECT COALESCE(SUM(CASE WHEN tipo='AJUSTE_FALTANTE' THEN cantidad ELSE -cantidad END),0)
     FROM erp_oc_conciliacion_eventos WHERE orden_id=? AND producto_id=?");
    $st->execute([$ordenId,$productoId]);$descontado=(int)$st->fetchColumn();
    $neto=$bruto-$descontado;
    if ($neto<0 || $neto+$agregar>$recibidas) {
       throw new RuntimeException('Aplicación detenida: sólo constan '.$recibidas.' piezas recibidas en la OC. '
        .'Neto ya aplicado: '.$neto.'; intento de sumar: '.$agregar.'. '
        .'Registra primero la recepción física y concilia la diferencia.');
    }
}
