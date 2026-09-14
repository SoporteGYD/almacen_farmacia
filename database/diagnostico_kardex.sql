/*
  DIAGNÓSTICO DE KARDEX - SOLO LECTURA
  No modifica información.

  saldo_base_implicito = existencia_actual - (entradas - salidas)

  Un saldo_base_implicito distinto de cero no significa necesariamente un error:
  puede representar existencia que ya estaba cargada antes de que comenzara el
  historial de movimientos o ajustes directos de existencia no registrados como
  documento de entrada/salida.
*/

SELECT
    p.id AS producto_id,
    p.codigo,
    p.descripcion,
    a.id AS almacen_id,
    a.nombre AS almacen,
    COALESCE(ex.existencia_actual, 0) AS existencia_actual,
    COALESCE(mv.entradas, 0) AS entradas_historicas,
    COALESCE(mv.salidas, 0) AS salidas_historicas,
    COALESCE(mv.entradas, 0) - COALESCE(mv.salidas, 0) AS neto_movimientos,
    COALESCE(ex.existencia_actual, 0)
      - (COALESCE(mv.entradas, 0) - COALESCE(mv.salidas, 0)) AS saldo_base_implicito
FROM productos p
CROSS JOIN almacenes a
LEFT JOIN (
    SELECT
        pe.producto_id,
        CASE
            WHEN UPPER(TRIM(pe.sucursal)) IN ('CIUDAD HIDALGO', 'CD HIDALGO') THEN 1
            WHEN UPPER(TRIM(pe.sucursal)) IN ('TUXTLA', 'TUXTLA GUTIERREZ') THEN 3
            ELSE 0
        END AS almacen_id,
        SUM(COALESCE(pe.existencia, 0)) AS existencia_actual
    FROM producto_existencias pe
    GROUP BY pe.producto_id,
             CASE
                WHEN UPPER(TRIM(pe.sucursal)) IN ('CIUDAD HIDALGO', 'CD HIDALGO') THEN 1
                WHEN UPPER(TRIM(pe.sucursal)) IN ('TUXTLA', 'TUXTLA GUTIERREZ') THEN 3
                ELSE 0
             END
) ex
    ON ex.producto_id = p.id
   AND ex.almacen_id = a.id
LEFT JOIN (
    SELECT
        md.producto_id,
        m.almacen_id,
        SUM(CASE WHEN m.tipo_movimiento = 'ENTRADA' THEN md.cantidad ELSE 0 END) AS entradas,
        SUM(CASE WHEN m.tipo_movimiento = 'SALIDA' THEN md.cantidad ELSE 0 END) AS salidas
    FROM movimientos m
    INNER JOIN movimiento_detalle md
        ON md.movimiento_id = m.id
    WHERE COALESCE(m.cancelado, 0) = 0
    GROUP BY md.producto_id, m.almacen_id
) mv
    ON mv.producto_id = p.id
   AND mv.almacen_id = a.id
WHERE a.estado = 1
  AND (
      COALESCE(ex.existencia_actual, 0) <> 0
      OR COALESCE(mv.entradas, 0) <> 0
      OR COALESCE(mv.salidas, 0) <> 0
  )
ORDER BY a.nombre, p.descripcion;
