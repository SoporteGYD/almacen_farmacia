-- Solo lectura: posibles Entradas clásicas anteriores con referencia OC/REC
-- sin vínculo en erp_oc_entradas_vinculos. Revisar manualmente; NO corregir automáticamente.
SELECT m.id AS movimiento_id, m.folio, m.fecha, m.almacen_id,
       m.referencia, md.producto_id, md.cantidad,
       CASE WHEN v.movimiento_id IS NULL THEN 'REVISAR REFERENCIA / SIN VINCULO'
            ELSE 'VINCULADA' END AS diagnostico
FROM movimientos m
JOIN movimiento_detalle md ON md.movimiento_id=m.id
LEFT JOIN erp_oc_entradas_vinculos v ON v.movimiento_id=m.id
WHERE m.tipo_movimiento='ENTRADA'
  AND COALESCE(m.referencia,'') REGEXP 'OC-[0-9]{4,}|REC-[0-9]{4,}'
ORDER BY m.id DESC LIMIT 500;
