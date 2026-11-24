-- SOLO LECTURA. NO RELLENAR TODOS LOS PRODUCTOS CON 0 NI 16 AUTOMATICAMENTE.
SELECT COUNT(*) total_productos,
 SUM(CASE WHEN f.producto_id IS NULL OR f.iva_compra IS NULL THEN 1 ELSE 0 END) sin_iva_compra,
 SUM(CASE WHEN f.iva_compra=0 THEN 1 ELSE 0 END) iva_0_configurado,
 SUM(CASE WHEN f.iva_compra=8 THEN 1 ELSE 0 END) iva_8_configurado,
 SUM(CASE WHEN f.iva_compra=16 THEN 1 ELSE 0 END) iva_16_configurado
FROM productos p LEFT JOIN erp_producto_ficha f ON f.producto_id=p.id WHERE p.estado=1;
SELECT p.id,p.codigo,p.descripcion,f.iva_compra,f.ieps
FROM productos p LEFT JOIN erp_producto_ficha f ON f.producto_id=p.id
WHERE p.estado=1 AND f.iva_compra IS NULL
ORDER BY p.descripcion LIMIT 100;
