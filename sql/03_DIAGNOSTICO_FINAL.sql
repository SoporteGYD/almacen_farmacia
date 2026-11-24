-- Solo lecturas, ejecutar en la base de datos seleccionada.
SELECT DATABASE() AS base_revisada,
 (SELECT COUNT(*) FROM productos) AS productos,
 (SELECT COUNT(*) FROM productos WHERE COALESCE(costo_ultimo,0)<=0) AS sin_ultimo_costo,
 (SELECT COUNT(*) FROM productos WHERE COALESCE(costo_promedio,0)<=0) AS sin_costo_promedio,
 (SELECT COUNT(*) FROM productos WHERE COALESCE(precio_venta,0)<=0) AS sin_precio_base,
 (SELECT COUNT(*) FROM productos WHERE categoria_id IS NULL OR proveedor_id IS NULL OR TRIM(COALESCE(laboratorio,''))='') AS incompletos_clave,
 (SELECT COUNT(*) FROM productos p LEFT JOIN erp_producto_ficha f ON f.producto_id=p.id WHERE f.producto_id IS NULL) AS sin_ficha,
 (SELECT COUNT(*) FROM erp_producto_ficha WHERE iva_compra IS NULL OR iva_venta IS NULL OR ieps IS NULL OR margen_sugerido IS NULL OR precio_sugerido IS NULL) AS fichas_financieras_incompletas,
 (SELECT COUNT(*) FROM pos_sucursales WHERE activo=1) AS sucursales_activas,
 (SELECT COUNT(*) FROM pos_precios WHERE precio_publico>0) AS precios_por_sucursal,
 (SELECT COUNT(*) FROM pos_precios_mayoreo WHERE precio_mayoreo>0) AS precios_mayoreo,
 (SELECT COUNT(*) FROM pos_presentaciones) AS presentaciones,
 (SELECT COUNT(*) FROM erp_ordenes_compra WHERE estado IN ('BORRADOR','EMITIDA')) AS oc_borrador_o_emitidas_no_equivale_a_pendiente,
 (SELECT COUNT(*) FROM erp_facturas WHERE estado<>'VERIFICADA') AS facturas_sin_verificar;
SELECT p.id,p.codigo,p.descripcion,p.costo_ultimo,p.costo_promedio,p.precio_venta,
 f.iva_compra,f.iva_venta,f.ieps,f.margen_sugerido,f.costo_flete_unitario,
 s.nombre AS sucursal,pp.precio_publico,
 ROUND(100*(pp.precio_publico-p.costo_ultimo)/NULLIF(pp.precio_publico,0),2) AS margen_pct,
 pm.precio_mayoreo
FROM productos p JOIN erp_producto_ficha f ON f.producto_id=p.id
JOIN pos_precios pp ON pp.producto_id=p.id
JOIN pos_sucursales s ON s.id=pp.sucursal_id
LEFT JOIN pos_precios_mayoreo pm ON pm.producto_id=p.id AND pm.sucursal_id=s.id
ORDER BY p.id,s.id LIMIT 30;

-- Piezas efectivamente pendientes según recepciones NO anuladas.
SELECT o.folio AS orden_compra,o.destino,p.codigo,p.descripcion,
 d.cantidad_piezas AS solicitadas,COALESCE(r.recibidas,0) AS recibidas,
 GREATEST(d.cantidad_piezas-COALESCE(r.recibidas,0),0) AS pendientes_recibir
FROM erp_ordenes_compra_detalle d
JOIN erp_ordenes_compra o ON o.id=d.orden_id
JOIN productos p ON p.id=d.producto_id
LEFT JOIN (
 SELECT rd.orden_detalle_id,SUM(rd.cantidad_piezas) AS recibidas
 FROM erp_oc_recepciones_detalle rd
 JOIN erp_oc_recepciones rr ON rr.id=rd.recepcion_id AND rr.estado='REGISTRADA'
 GROUP BY rd.orden_detalle_id
) r ON r.orden_detalle_id=d.id
WHERE o.estado='EMITIDA' AND GREATEST(d.cantidad_piezas-COALESCE(r.recibidas,0),0)>0
ORDER BY pendientes_recibir DESC,o.folio,d.id LIMIT 80;
