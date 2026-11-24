-- Opcional: auditoría de reaperturas documentales tras conciliación física.
-- No modifica facturas, movimientos ni existencias existentes.
CREATE TABLE IF NOT EXISTS erp_oc_facturas_reaperturas (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  factura_id BIGINT UNSIGNED NOT NULL,
  orden_id BIGINT UNSIGNED NOT NULL,
  conciliacion_evento_id BIGINT UNSIGNED NOT NULL,
  estado_anterior VARCHAR(40) NOT NULL,
  estado_nuevo VARCHAR(40) NOT NULL,
  motivo VARCHAR(250) NOT NULL,
  usuario_id INT NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reapertura_evento (factura_id, conciliacion_evento_id),
  KEY idx_reaperturas_orden (orden_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
