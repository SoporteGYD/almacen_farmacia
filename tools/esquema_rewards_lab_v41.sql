-- V41: exclusivamente prueba:3307; movimientos de canje 100% virtuales.
CREATE TABLE IF NOT EXISTS gyd_rewards_lab_canjes_v41 (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 clave CHAR(36) NOT NULL UNIQUE,
 sucursal_id INT NOT NULL,
 producto_id BIGINT UNSIGNED NOT NULL,
 cliente_id BIGINT UNSIGNED NOT NULL,
 puntos_gastados INT UNSIGNED NOT NULL,
 costo_centavos BIGINT UNSIGNED NOT NULL,
 precio_centavos BIGINT UNSIGNED NOT NULL,
 saldo_antes INT NOT NULL,
 saldo_despues INT NOT NULL,
 stock_antes INT UNSIGNED NOT NULL,
 stock_despues INT UNSIGNED NOT NULL,
 usuario_id BIGINT NOT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_lab41_cliente_fecha(cliente_id,creado_en),
 INDEX idx_lab41_sucursal_fecha(sucursal_id,creado_en),
 INDEX idx_lab41_producto(producto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
