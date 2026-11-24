-- G&D V39. Laboratorio: el instalador exige prueba:3307.
-- Solicitudes NO representan canjes, no reservan puntos o inventario y NO son tickets de venta.
CREATE TABLE IF NOT EXISTS gyd_rewards_solicitudes_v39 (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clave CHAR(36) NOT NULL,
  cliente_id BIGINT UNSIGNED NOT NULL,
  sucursal_id INT NOT NULL,
  caja_id BIGINT UNSIGNED NOT NULL,
  catalogo_id BIGINT UNSIGNED NOT NULL,
  producto_id BIGINT UNSIGNED NOT NULL,
  puntos_snapshot INT UNSIGNED NOT NULL,
  saldo_snapshot INT UNSIGNED NOT NULL,
  stock_snapshot INT UNSIGNED NOT NULL,
  estado ENUM('PENDIENTE','CANCELADA') NOT NULL DEFAULT 'PENDIENTE',
  usuario_id BIGINT NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cancelado_por BIGINT NULL,
  cancelado_en DATETIME NULL,
  motivo_cancelacion VARCHAR(240) NULL,
  UNIQUE KEY uq_gyd_precanje_clave (clave),
  KEY idx_gyd_precanje_cliente (cliente_id, creado_en),
  KEY idx_gyd_precanje_sucursal (sucursal_id, creado_en),
  KEY idx_gyd_precanje_usuario (usuario_id, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
