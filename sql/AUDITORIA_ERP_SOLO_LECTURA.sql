-- GYD ERP / CONSULTAS SOLO LECTURA / 2026-10-20
-- Ejecutar EN UNA COPIA de la BD preferentemente. No incluye INSERT/UPDATE/DELETE.
-- Ejecutar cada bloque por separado si faltan algunas tablas opcionales.

-- A. Identidades principales duplicadas tras normalización básica
SELECT UPPER(TRIM(codigo)) codigo_normalizado,COUNT(*) registros,GROUP_CONCAT(id ORDER BY id) ids
FROM productos WHERE TRIM(COALESCE(codigo,''))<>''
GROUP BY UPPER(TRIM(codigo)) HAVING COUNT(*)>1;

-- B. Códigos de barras duplicados
SELECT UPPER(TRIM(codigo_barras)) codigo_barras,COUNT(*) registros,GROUP_CONCAT(id ORDER BY id) ids
FROM productos WHERE TRIM(COALESCE(codigo_barras,''))<>''
GROUP BY UPPER(TRIM(codigo_barras)) HAVING COUNT(*)>1;

-- C. Descripciones exactas normalizadas duplicadas
SELECT UPPER(TRIM(descripcion)) descripcion,COUNT(*) registros,GROUP_CONCAT(id ORDER BY id) ids
FROM productos WHERE TRIM(COALESCE(descripcion,''))<>''
GROUP BY UPPER(TRIM(descripcion)) HAVING COUNT(*)>1;

-- D. Existencias negativas de bodega
SELECT pe.producto_id,p.codigo,pe.sucursal,pe.ubicacion,pe.existencia
FROM producto_existencias pe JOIN productos p ON p.id=pe.producto_id
WHERE pe.existencia<0;

-- E. Existencias negativas POS
SELECT sucursal_id,producto_id,existencia
FROM pos_existencias_farmacia WHERE existencia<0;

-- F. Conteo de recepciones vigentes por orden y producto
SELECT r.orden_id,rd.producto_id,SUM(rd.cantidad_piezas) recibidas
FROM erp_oc_recepciones r JOIN erp_oc_recepciones_detalle rd ON rd.recepcion_id=r.id
WHERE r.estado='REGISTRADA'
GROUP BY r.orden_id,rd.producto_id ORDER BY r.orden_id DESC;

-- G. Contra OC: facturadas / recibidas / solicitadas (sin multiplicar filas por JOINs)
SELECT od.orden_id,od.producto_id,SUM(od.cantidad_piezas) solicitadas,
 COALESCE((SELECT SUM(rd.cantidad_piezas) FROM erp_oc_recepciones r
    JOIN erp_oc_recepciones_detalle rd ON rd.recepcion_id=r.id
    WHERE r.orden_id=od.orden_id AND r.estado='REGISTRADA' AND rd.producto_id=od.producto_id),0) recibidas,
 COALESCE((SELECT SUM(fd.cantidad_facturada) FROM erp_facturas f
    JOIN erp_facturas_detalle fd ON fd.factura_id=f.id
    WHERE f.orden_id=od.orden_id AND fd.producto_id=od.producto_id),0) facturadas
FROM erp_ordenes_compra_detalle od
GROUP BY od.orden_id,od.producto_id
ORDER BY od.orden_id DESC,od.producto_id;

-- H. Movimientos netos por factura con ajustes auditados: diagnóstico
SELECT f.id factura_id, f.folio_interno, fd.producto_id,
 SUM(fd.cantidad_facturada) facturadas,
 COALESCE((SELECT SUM(a.cantidad) FROM erp_facturas_stock_aplicaciones a
    WHERE a.factura_id=f.id AND a.producto_id=fd.producto_id),0) aplicadas_brutas,
 COALESCE((SELECT SUM(CASE WHEN e.tipo='AJUSTE_FALTANTE' THEN e.cantidad ELSE -e.cantidad END)
    FROM erp_oc_conciliacion_eventos e WHERE e.factura_id=f.id AND e.producto_id=fd.producto_id),0) ajuste_neto
FROM erp_facturas f JOIN erp_facturas_detalle fd ON fd.factura_id=f.id
WHERE f.orden_id IS NOT NULL
GROUP BY f.id,f.folio_interno,fd.producto_id
ORDER BY f.id DESC;

-- I. Estado puntual del caso ya conciliado OC8 (verificar que no reaparezca la diferencia)
SELECT producto_id,sucursal,ubicacion,existencia FROM producto_existencias
WHERE producto_id=1394 AND sucursal='CIUDAD HIDALGO' AND ubicacion='R1N1Z01';
