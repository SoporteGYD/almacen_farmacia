-- CONSULTAS DE SOLO LECTURA: ejecutar antes y despues de la conciliacion.
-- A) Estado de las recepciones ID 5 y 6.
SELECT r.id, r.orden_id, r.estado, rd.producto_id, rd.cantidad_piezas
FROM erp_oc_recepciones r JOIN erp_oc_recepciones_detalle rd ON rd.recepcion_id=r.id
WHERE r.orden_id=8 AND rd.producto_id=1394 ORDER BY r.id;
-- B) Stock destino (R1N1Z01).
SELECT producto_id, sucursal, ubicacion, existencia
FROM producto_existencias
WHERE producto_id=1394 AND sucursal='CIUDAD HIDALGO' AND ubicacion='R1N1Z01';
-- C) Historial de conciliacion y auditoria.
SELECT id, tipo, cantidad, movimiento_id, stock_antes, stock_despues, creado_en
FROM erp_oc_conciliacion_eventos WHERE orden_id=8 ORDER BY id;
SELECT recepcion_id, orden_id, cantidad_piezas, estado_anterior, estado_nuevo,
 usuario_id, movimiento_ajuste_id, creado_en
FROM erp_oc_recepciones_anulaciones WHERE orden_id=8 ORDER BY id;
-- D) El movimiento original 1590 sigue vigente y debe existir otro ajuste.
SELECT m.id,m.folio,m.tipo_movimiento,m.cancelado,md.cantidad,md.ubicacion
FROM movimientos m JOIN movimiento_detalle md ON md.movimiento_id=m.id
WHERE (m.id=1590 OR m.folio='AJ-ERP-OC8-PRUEBA-20')
  AND m.almacen_id=1 AND md.producto_id=1394 ORDER BY m.id;
