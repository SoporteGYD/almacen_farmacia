-- Ejecutar una sola vez en la base prueba; no borra datos existentes.
CREATE TABLE IF NOT EXISTS erp_oc_envios_correo (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  orden_id INT NOT NULL,
  folio VARCHAR(40) NOT NULL,
  destinatario VARCHAR(254) NOT NULL,
  usuario_id INT NULL,
  estado ENUM('EN_PROCESO','ENVIADO','ERROR') NOT NULL DEFAULT 'EN_PROCESO',
  detalle_error VARCHAR(500) NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  enviado_en DATETIME NULL,
  INDEX idx_orden_fecha (orden_id,creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
