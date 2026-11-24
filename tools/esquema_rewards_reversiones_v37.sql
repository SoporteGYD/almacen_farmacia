-- V37, sólo prueba:3307 (validado por el instalador). Registro de devoluciones por conciliar.
-- No se modifican pos_ventas, pos_reversiones, artículos, pagos, stock ni cortes.
CREATE TABLE IF NOT EXISTS gyd_rewards_revision_v37 (
 reversion_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 venta_id BIGINT UNSIGNED NOT NULL,
 cliente_id BIGINT UNSIGNED NOT NULL,
 tipo_reversion VARCHAR(80) NOT NULL,
 estado_revision ENUM('PENDIENTE','CUBIERTA_CANCELACION') NOT NULL DEFAULT 'PENDIENTE',
 detectada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 revisada_en DATETIME NULL,
 INDEX idx_gyd_v37_cliente (cliente_id),
 INDEX idx_gyd_v37_venta (venta_id),
 INDEX idx_gyd_v37_estado (estado_revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
