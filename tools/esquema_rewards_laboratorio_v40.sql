-- V40 - Exclusivo para base prueba:3307. Los inventarios son virtuales.
CREATE TABLE IF NOT EXISTS gyd_rewards_lab_clientes_v40 (
  cliente_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  codigo VARCHAR(24) NOT NULL UNIQUE,
  escenario VARCHAR(80) NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gyd_rewards_lab_productos_v40 (
  sucursal_id INT NOT NULL,
  producto_id BIGINT UNSIGNED NOT NULL,
  precio_centavos BIGINT UNSIGNED NOT NULL,
  costo_centavos BIGINT UNSIGNED NOT NULL,
  stock_virtual INT UNSIGNED NOT NULL,
  puntos_requeridos INT UNSIGNED NOT NULL,
  escenario VARCHAR(35) NOT NULL DEFAULT 'PERSONALIZADO',
  actualizado_por BIGINT NULL,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (sucursal_id,producto_id),
  KEY idx_lab_v40_producto (producto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gyd_rewards_lab_auditoria_v40 (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sucursal_id INT NOT NULL,
  producto_id BIGINT UNSIGNED NOT NULL,
  usuario_id BIGINT NULL,
  accion VARCHAR(30) NOT NULL,
  antes_json LONGTEXT NULL,
  despues_json LONGTEXT NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_lab_v40_audit (sucursal_id,producto_id,creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
