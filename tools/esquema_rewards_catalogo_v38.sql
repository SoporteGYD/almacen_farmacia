-- V38: catálogo administrativo de recompensas. No canjes ni movimientos de stock.
-- Aplicar exclusivamente mediante instalador que verifica DB prueba, puerto 3307.
CREATE TABLE IF NOT EXISTS gyd_rewards_catalogo_v38 (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 sucursal_id INT NOT NULL,
 producto_id BIGINT UNSIGNED NOT NULL,
 puntos_requeridos INT UNSIGNED NOT NULL,
 costo_referencia_centavos BIGINT UNSIGNED NOT NULL,
 precio_referencia_centavos BIGINT UNSIGNED NOT NULL,
 estado ENUM('BORRADOR','ACTIVO','PAUSADO') NOT NULL DEFAULT 'BORRADOR',
 observaciones VARCHAR(240) NOT NULL DEFAULT '',
 autorizado_por BIGINT NOT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_gyd_rewards_producto_sucursal (sucursal_id,producto_id),
 KEY idx_gyd_rewards_estado (estado),
 KEY idx_gyd_rewards_producto (producto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gyd_rewards_catalogo_auditoria_v38 (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 catalogo_id BIGINT UNSIGNED NOT NULL,
 usuario_id BIGINT NOT NULL,
 accion VARCHAR(30) NOT NULL,
 motivo VARCHAR(240) NOT NULL,
 antes_json LONGTEXT NULL,
 despues_json LONGTEXT NOT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_gyd_rewards_auditoria_catalogo (catalogo_id,creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
