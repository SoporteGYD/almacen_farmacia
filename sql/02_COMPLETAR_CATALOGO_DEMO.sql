-- G&D ERP - COMPLETAR CATÁLOGO DE PRUEBA - 23/10/2026
-- MODO DEMOSTRACION: NUNCA EJECUTAR EN PRODUCCION.
-- Esta secuencia es DML idempotente sobre el esquema ERP disponible en la BD laboratorio v3.
-- La BD actual de XAMPP debe respaldarse. No toca cantidades, ventas, órdenes,
-- facturas, recepciones, auditorías operativas, usuarios, permisos o aplicaciones POS.
-- Paso 01 | costos de referencia existentes o costo simulado determinista donde falta.
UPDATE productos p
SET
 p.precio_compra=CASE WHEN p.precio_compra>0 THEN p.precio_compra
   WHEN p.costo_ultimo>0 THEN ROUND(p.costo_ultimo,2)
   WHEN p.costo_promedio>0 THEN ROUND(p.costo_promedio,2)
   ELSE ROUND(8.00+MOD(p.id*37,1990)/10,2) END,
 p.costo_ultimo=CASE WHEN p.costo_ultimo>0 THEN p.costo_ultimo
   WHEN p.precio_compra>0 THEN p.precio_compra
   WHEN p.costo_promedio>0 THEN p.costo_promedio
   ELSE ROUND(8.00+MOD(p.id*37,1990)/10,4) END,
 p.costo_promedio=CASE WHEN p.costo_promedio>0 THEN p.costo_promedio
   WHEN p.costo_ultimo>0 THEN ROUND(p.costo_ultimo*(0.96+MOD(p.id,7)*0.01),4)
   WHEN p.precio_compra>0 THEN ROUND(p.precio_compra*(0.96+MOD(p.id,7)*0.01),4)
   ELSE ROUND((8.00+MOD(p.id*37,1990)/10)*(0.96+MOD(p.id,7)*0.01),4) END;

-- Paso 02 | referencias descriptivas vacías, sin alterar códigos ni existencias.
UPDATE productos p
SET
 p.categoria_id=COALESCE(p.categoria_id, (SELECT MIN(id) FROM categorias)),
 p.proveedor_id=COALESCE(p.proveedor_id,
    (SELECT id FROM proveedores WHERE estado=1 AND UPPER(TRIM(nombre)) NOT LIKE '%TRASPASO%' ORDER BY id LIMIT 1)),
 p.laboratorio=CASE WHEN p.laboratorio IS NULL OR TRIM(p.laboratorio)='' THEN 'LABORATORIO DEMO - REVISAR' ELSE p.laboratorio END,
 p.unidad_medida=CASE WHEN p.unidad_medida IS NULL OR TRIM(p.unidad_medida)='' THEN 'PZA' ELSE p.unidad_medida END;

-- Paso 03 | completar código de barras SOLAMENTE sin duplicar códigos existentes.
UPDATE productos p
LEFT JOIN productos q ON q.codigo_barras = CONCAT('DEMO-CB-',LPAD(p.id,8,'0')) AND q.id<>p.id
SET p.codigo_barras = CONCAT('DEMO-CB-',LPAD(p.id,8,'0'))
WHERE (p.codigo_barras IS NULL OR TRIM(p.codigo_barras)='') AND q.id IS NULL;

-- Paso 04 | precio base SIMULADO, coherente con costo más alto.
UPDATE productos p
LEFT JOIN (
 SELECT producto_id,MAX(costo_unitario) AS max_facturado
 FROM erp_facturas_detalle GROUP BY producto_id
) fh ON fh.producto_id=p.id
SET p.precio_venta=ROUND(GREATEST(p.costo_ultimo,p.costo_promedio,p.precio_compra,
  COALESCE(fh.max_facturado,0))*(1.51+MOD(p.id,11)*0.017),2);

-- Paso 05 | crear ficha de producto cuando falta (se conservan fichas existentes).
INSERT INTO erp_producto_ficha
 (producto_id,tipo,subfamilia,descripcion_ampliada,unidad_compra,factor_compra,
  unidad_venta,factor_venta,unidad_detallista,factor_detallista,unidad_auxiliar,factor_auxiliar,
  iva_compra,iva_venta,ieps,margen_sugerido,precio_sugerido,piezas_cambio_precio,
  metodo_precio,descuento_1,descuento_2,descuento_3,descuento_4,descuento_general,
  costo_flete_unitario,puntos_monto_compra,puntos_otorgar,puntos_canje,observaciones)
SELECT
 p.id,
 CASE WHEN p.categoria_id IN (1,5,7) THEN 'FARMACIA' ELSE 'COMERCIAL' END,
 COALESCE(c.nombre,'SIN CATEGORIA DEMO'),
 CONCAT(p.descripcion,' | FICHA FINANCIERA DEMO - NO USAR COMO FICHA FISCAL REAL'),
 'PZA',1,'PZA',1,'CAJA',12,'PZA',1,
 CASE WHEN p.categoria_id IN (1,5,7,8) THEN 0 ELSE 16 END,
 CASE WHEN p.categoria_id IN (1,5,7,8) THEN 0 ELSE 16 END,
 0,35,
 ROUND(p.costo_ultimo*1.6,2),12,'MARK_UP',1,0,0,0,0,
 ROUND(p.costo_ultimo*0.012,2),0,0,0,'GENERADO EN LABORATORIO G&D 2026-10-23 - VALORES SIMULADOS'
FROM productos p
LEFT JOIN categorias c ON c.id=p.categoria_id
LEFT JOIN erp_producto_ficha f ON f.producto_id=p.id
WHERE f.producto_id IS NULL;

-- Paso 06 | completar únicamente los campos vacíos de fichas ya existentes.
UPDATE erp_producto_ficha f
JOIN productos p ON p.id=f.producto_id
LEFT JOIN categorias c ON c.id=p.categoria_id
SET
 f.tipo=COALESCE(NULLIF(TRIM(f.tipo),''),CASE WHEN p.categoria_id IN (1,5,7) THEN 'FARMACIA' ELSE 'COMERCIAL' END),
 f.subfamilia=COALESCE(NULLIF(TRIM(f.subfamilia),''),COALESCE(c.nombre,'SIN CATEGORIA DEMO')),
 f.descripcion_ampliada=COALESCE(NULLIF(TRIM(f.descripcion_ampliada),''),CONCAT(p.descripcion,' | FICHA DEMO')),
 f.unidad_detallista=COALESCE(NULLIF(TRIM(f.unidad_detallista),''),'CAJA'),
 f.factor_detallista=CASE WHEN f.factor_detallista IS NULL OR f.factor_detallista<=0 THEN 12 ELSE f.factor_detallista END,
 f.unidad_auxiliar=COALESCE(NULLIF(TRIM(f.unidad_auxiliar),''),'PZA'),
 f.factor_auxiliar=CASE WHEN f.factor_auxiliar IS NULL OR f.factor_auxiliar<=0 THEN 1 ELSE f.factor_auxiliar END,
 f.iva_compra=COALESCE(f.iva_compra,CASE WHEN p.categoria_id IN (1,5,7,8) THEN 0 ELSE 16 END),
 f.iva_venta=COALESCE(f.iva_venta,CASE WHEN p.categoria_id IN (1,5,7,8) THEN 0 ELSE 16 END),
 f.ieps=COALESCE(f.ieps,0),
 f.margen_sugerido=COALESCE(f.margen_sugerido,35),
 f.precio_sugerido=CASE WHEN f.precio_sugerido IS NULL OR f.precio_sugerido<=0 THEN ROUND(p.costo_ultimo*1.6,2) ELSE f.precio_sugerido END,
 f.piezas_cambio_precio=COALESCE(f.piezas_cambio_precio,12),
 f.descuento_1=COALESCE(f.descuento_1,1),
 f.descuento_2=COALESCE(f.descuento_2,0),
 f.descuento_3=COALESCE(f.descuento_3,0),
 f.descuento_4=COALESCE(f.descuento_4,0),
 f.descuento_general=COALESCE(f.descuento_general,0),
 f.costo_flete_unitario=COALESCE(f.costo_flete_unitario,ROUND(p.costo_ultimo*0.012,2)),
 f.puntos_monto_compra=COALESCE(f.puntos_monto_compra,0),
 f.puntos_otorgar=COALESCE(f.puntos_otorgar,0),
 f.puntos_canje=COALESCE(f.puntos_canje,0),
 f.observaciones=CASE WHEN f.observaciones LIKE '%DEMO_GYD_20261023%' THEN f.observaciones
   ELSE CONCAT(COALESCE(f.observaciones,''),' [DEMO_GYD_20261023: PRECIOS SINTETICOS Y TASAS SIN VALIDACION]') END;

-- Paso 07 | proveedores asociados para todos los productos.
INSERT INTO erp_producto_proveedores (producto_id,proveedor_id,prioridad)
SELECT p.id,p.proveedor_id,1
FROM productos p LEFT JOIN erp_producto_proveedores r
  ON r.producto_id=p.id AND r.proveedor_id=p.proveedor_id
WHERE p.proveedor_id IS NOT NULL AND r.producto_id IS NULL;

-- Paso 08 | segundo proveedor alternativo en una muestra para pruebas (asociación, no cotización).
INSERT INTO erp_producto_proveedores (producto_id,proveedor_id,prioridad)
SELECT p.id,MIN(prov.id),2
FROM productos p JOIN proveedores prov ON prov.estado=1
  AND prov.id<>p.proveedor_id AND UPPER(prov.nombre) NOT LIKE '%TRASPASO%'
LEFT JOIN erp_producto_proveedores rel ON rel.producto_id=p.id AND rel.proveedor_id=prov.id
WHERE MOD(p.id,5)=0 AND rel.producto_id IS NULL
 AND NOT EXISTS (SELECT 1 FROM erp_producto_proveedores ep2
      WHERE ep2.producto_id=p.id AND ep2.prioridad=2)
GROUP BY p.id;

-- Paso 09 | contacto DEMO, evita información de contacto real simulada.
UPDATE proveedores pr
SET pr.telefono=COALESCE(NULLIF(TRIM(pr.telefono),''),'0000000000'),
    pr.correo=COALESCE(NULLIF(TRIM(pr.correo),''),CONCAT('proveedor-',pr.id,'@demo.invalid')),
    pr.direccion=COALESCE(NULLIF(TRIM(pr.direccion),''),'DIRECCION DE PRUEBA - NO REAL');
UPDATE categorias c SET c.descripcion=COALESCE(NULLIF(TRIM(c.descripcion),''),CONCAT('CATEGORIA DE PRUEBA: ',c.nombre));

-- Paso 10 | precio POS simulado para todos los productos y sucursales activas.
INSERT INTO pos_precios (sucursal_id,producto_id,precio_publico,vigente_desde,actualizado_por)
SELECT s.id,p.id,ROUND(p.precio_venta*(1+(MOD(s.id,4)*0.018)),2),
       '2026-10-23 12:00:00',
       (SELECT MIN(id) FROM usuarios WHERE rol='ADMINISTRADOR' AND estado=1)
FROM pos_sucursales s CROSS JOIN productos p
LEFT JOIN pos_precios pp ON pp.sucursal_id=s.id AND pp.producto_id=p.id
WHERE s.activo=1 AND pp.id IS NULL;
-- Recalcular toda la muestra, incluso precios existentes, solo en BD DEMO.
UPDATE pos_precios pp JOIN productos p ON p.id=pp.producto_id JOIN pos_sucursales s ON s.id=pp.sucursal_id
SET pp.precio_publico=ROUND(p.precio_venta*(1+(MOD(s.id,4)*0.018)),2),
    pp.vigente_desde='2026-10-23 12:00:00'
WHERE s.activo=1;

-- Paso 11 | mayoreo: 12 piezas, descuento de 4%; mayor que último costo por diseño.
INSERT INTO pos_precios_mayoreo (sucursal_id,producto_id,cantidad_minima,precio_mayoreo,actualizado_por)
SELECT pp.sucursal_id,pp.producto_id,12,ROUND(pp.precio_publico*0.96,2),
 (SELECT MIN(id) FROM usuarios WHERE rol='ADMINISTRADOR' AND estado=1)
FROM pos_precios pp
LEFT JOIN pos_precios_mayoreo pm ON pm.sucursal_id=pp.sucursal_id AND pm.producto_id=pp.producto_id
WHERE pm.id IS NULL;
UPDATE pos_precios_mayoreo pm JOIN pos_precios pp
 ON pp.sucursal_id=pm.sucursal_id AND pp.producto_id=pm.producto_id
SET pm.cantidad_minima=12,pm.precio_mayoreo=ROUND(pp.precio_publico*0.96,2);

-- Paso 12 | presentación: costo y venta son siempre por pieza, caja es multiplicación.
INSERT INTO pos_presentaciones (sucursal_id,producto_id,modalidad_presentacion,piezas_por_caja,precio_caja,actualizado_por)
SELECT pp.sucursal_id,pp.producto_id,IF(MOD(pp.producto_id,4)=0,'AMBAS','PIEZA'),
 IF(MOD(pp.producto_id,4)=0,12,1),
 ROUND(pp.precio_publico*IF(MOD(pp.producto_id,4)=0,12,1),2),
 (SELECT MIN(id) FROM usuarios WHERE rol='ADMINISTRADOR' AND estado=1)
FROM pos_precios pp
LEFT JOIN pos_presentaciones pres ON pres.sucursal_id=pp.sucursal_id AND pres.producto_id=pp.producto_id
WHERE pres.producto_id IS NULL;
-- No modificar modalidades ya configuradas: sólo completa el precio de caja que falta.
UPDATE pos_presentaciones pres JOIN pos_precios pp
 ON pres.sucursal_id=pp.sucursal_id AND pres.producto_id=pp.producto_id
SET pres.precio_caja=ROUND(pp.precio_publico*GREATEST(pres.piezas_por_caja,1),2);

-- Paso 13 | referencia histórica marcada DEMO, sin modificar ventas ni facturas.
INSERT INTO pos_precios_historial
 (sucursal_id,producto_id,precio_anterior,precio_nuevo,usuario_id,motivo,creado_en)
SELECT pp.sucursal_id,pp.producto_id,ROUND(pp.precio_publico/1.035,2),pp.precio_publico,
       (SELECT MIN(id) FROM usuarios WHERE rol='ADMINISTRADOR' AND estado=1),
       'DEMO_CATALOGO_20261023 - PRECIO SIMULADO, NO ES VENTA NI CAMBIO REAL',
       '2026-10-22 12:00:00'
FROM pos_precios pp
WHERE NOT EXISTS (SELECT 1 FROM pos_precios_historial ph WHERE ph.sucursal_id=pp.sucursal_id
  AND ph.producto_id=pp.producto_id AND ph.motivo LIKE 'DEMO_CATALOGO_20261023%');
