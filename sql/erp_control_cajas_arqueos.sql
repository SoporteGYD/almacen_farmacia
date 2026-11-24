-- ERP G&D | Control Gerencial de Cajas (solo base de pruebas `prueba`).
-- Primero respalde su BD. Esta migración NO altera las tablas nativas POS.
-- No ejecutar si alguno de estos nombres ya se emplea para tablas distintas.
CREATE TABLE IF NOT EXISTS erp_caja_movimientos_control (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  operacion_clave CHAR(32) NOT NULL,
  turno_id BIGINT UNSIGNED NOT NULL,
  movimiento_id BIGINT UNSIGNED NOT NULL,
  usuario_id INT NOT NULL,
  tipo ENUM('INGRESO','RETIRO') NOT NULL,
  monto DECIMAL(14,2) NOT NULL,
  motivo VARCHAR(255) NOT NULL,
  referencia VARCHAR(120) DEFAULT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_caja_mov_clave (operacion_clave),
  UNIQUE KEY uq_caja_mov_referencia (movimiento_id),
  KEY idx_caja_mov_turno (turno_id, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS erp_caja_arqueos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  operacion_clave CHAR(32) NOT NULL,
  turno_id BIGINT UNSIGNED NOT NULL,
  usuario_id INT NOT NULL,
  esperado_corte DECIMAL(14,2) NOT NULL,
  efectivo_contado DECIMAL(14,2) NOT NULL,
  diferencia DECIMAL(14,2) NOT NULL,
  motivo VARCHAR(500) NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_caja_arqueo_clave (operacion_clave),
  KEY idx_caja_arqueo_turno (turno_id, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS erp_caja_revisiones (
  turno_id BIGINT UNSIGNED NOT NULL,
  estado ENUM('REVISADO','OBSERVADO') NOT NULL,
  observaciones VARCHAR(500) NOT NULL,
  usuario_id INT NOT NULL,
  revisado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (turno_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
