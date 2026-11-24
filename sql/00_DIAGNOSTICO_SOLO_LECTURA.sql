-- Consultas de apoyo: SOLO LECTURA. Requieren la estructura ERP descrita.
SELECT f.folio_interno,f.numero_factura,a.id AS aplicacion_id,a.cantidad,
       a.movimiento_id,a.almacen_id,a.ubicacion
FROM erp_facturas_stock_aplicaciones a JOIN erp_facturas f ON f.id=a.factura_id
WHERE f.orden_id=8 AND a.producto_id=1394;

SELECT producto_id,sucursal,ubicacion,existencia
FROM producto_existencias WHERE producto_id=1394 AND sucursal='CIUDAD HIDALGO'
  AND ubicacion='R1N1Z01';

SELECT r.orden_id,rd.producto_id,SUM(rd.cantidad_piezas) piezas_recibidas
FROM erp_oc_recepciones r JOIN erp_oc_recepciones_detalle rd ON rd.recepcion_id=r.id
WHERE r.orden_id=8 AND r.estado='REGISTRADA' AND rd.producto_id=1394
GROUP BY r.orden_id,rd.producto_id;
