-- Solo lectura, ejecutar ANTES de instalar; no borrar automáticamente.
SELECT UPPER(TRIM(codigo)) AS codigo_normalizado,COUNT(*) repeticiones,GROUP_CONCAT(id ORDER BY id) productos
FROM productos GROUP BY UPPER(TRIM(codigo)) HAVING COUNT(*)>1;
SELECT UPPER(TRIM(codigo_barras)) AS barras_normalizado,COUNT(*) repeticiones,GROUP_CONCAT(id ORDER BY id) productos
FROM productos WHERE codigo_barras IS NOT NULL AND TRIM(codigo_barras)<>''
GROUP BY UPPER(TRIM(codigo_barras)) HAVING COUNT(*)>1;
SELECT UPPER(TRIM(descripcion)) AS descripcion_normalizada,COUNT(*) repeticiones,GROUP_CONCAT(id ORDER BY id) productos
FROM productos GROUP BY UPPER(TRIM(descripcion)) HAVING COUNT(*)>1;
SELECT p.id,p.codigo,p.codigo_barras,q.id AS otro_id,q.codigo AS otro_codigo,q.codigo_barras AS otro_barras
FROM productos p JOIN productos q ON p.id<q.id
WHERE (p.codigo=q.codigo_barras OR p.codigo_barras=q.codigo)
 AND p.codigo_barras IS NOT NULL AND q.codigo_barras IS NOT NULL;
