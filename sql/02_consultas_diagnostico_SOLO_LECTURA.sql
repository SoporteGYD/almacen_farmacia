-- SOLO LECTURA: comprobación de la OC8 y factura FAC-<ceros>2.
SELECT f.id AS factura_id,f.folio_interno,f.estado,d.producto_id,d.cantidad_facturada,
 (SELECT COALESCE(SUM(rd.cantidad),0) FROM erp_facturas_revision_detalle rd WHERE rd.factura_detalle_id=d.id AND rd.tipo='ACEPTADA') AS aceptadas_historicas,
 (SELECT COALESCE(SUM(a.cantidad),0) FROM erp_facturas_stock_aplicaciones a WHERE a.factura_id=f.id AND a.producto_id=d.producto_id) AS aplicaciones_brutas,
 (SELECT COALESCE(SUM(CASE WHEN e.tipo='AJUSTE_FALTANTE' THEN e.cantidad WHEN e.tipo='REPOSICION' THEN -e.cantidad END),0) FROM erp_oc_conciliacion_eventos e WHERE e.factura_id=f.id AND e.producto_id=d.producto_id) AS saldo_conciliado,
 (SELECT COALESCE(SUM(rd.cantidad_piezas),0) FROM erp_oc_recepciones r JOIN erp_oc_recepciones_detalle rd ON rd.recepcion_id=r.id WHERE r.orden_id=f.orden_id AND r.estado='REGISTRADA' AND rd.producto_id=d.producto_id) AS recibidas_vigentes
FROM erp_facturas f JOIN erp_facturas_detalle d ON d.factura_id=f.id WHERE f.orden_id=8;

SELECT producto_id,sucursal,ubicacion,existencia FROM producto_existencias
WHERE producto_id=1394 AND sucursal='CIUDAD HIDALGO' AND ubicacion='R1N1Z01';

SELECT id,estado,orden_id FROM erp_oc_recepciones WHERE orden_id=8 ORDER BY id;
