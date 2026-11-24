-- G&D | Solo lectura | Revisar folio, cajero, efectivo y conciliacion.
-- No ajustar saldos desde este SQL; revisar cualquier diferencia con ticket y dinero fisico.
SELECT b.id, CONCAT('RET-',LPAD(b.id,8,'0')) folio, b.turno_id,
       b.creado_en,b.importe importe_ticket,m.monto importe_movimiento,
       b.boveda_estado,b.importe_boveda,
       CASE WHEN m.id IS NULL THEN 'SIN MOVIMIENTO POS'
            WHEN m.tipo <> 'RETIRO' THEN 'TIPO INCORRECTO'
            WHEN b.importe <> m.monto THEN 'DIFERENCIA EN MOVIMIENTO'
            WHEN b.boveda_estado='DIFERENCIA' THEN 'DIFERENCIA EN BOVEDA'
            WHEN b.boveda_estado='SIN_VERIFICAR' THEN 'PENDIENTE VERIFICACION FISICA'
            ELSE 'CONCILIADO' END diagnostico
FROM gyd_retiros_boveda b
LEFT JOIN pos_movimientos_caja m ON m.id=b.movimiento_id
ORDER BY b.id DESC LIMIT 500;

SELECT b.turno_id,COUNT(*) folios,
       SUM(b.importe) total_tickets,
       (SELECT COALESCE(SUM(m.monto),0) FROM pos_movimientos_caja m
        WHERE m.turno_id=b.turno_id AND m.tipo='RETIRO') total_retiros_libro_pos,
       SUM(CASE WHEN b.boveda_estado='SIN_VERIFICAR' THEN b.importe ELSE 0 END) pendiente_verificar_boveda,
       SUM(CASE WHEN b.boveda_estado='DIFERENCIA' THEN b.importe-b.importe_boveda ELSE 0 END) diferencia_boveda
FROM gyd_retiros_boveda b GROUP BY b.turno_id ORDER BY b.turno_id DESC;
