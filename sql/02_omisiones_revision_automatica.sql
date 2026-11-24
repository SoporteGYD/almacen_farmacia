-- Ejecutar en BD prueba después de la migración de precio etapa 1.
-- Es independiente de la existencia de inventario y no altera tablas anteriores.
CREATE TABLE IF NOT EXISTS erp_precio_omisiones (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 factura_id BIGINT NOT NULL,
 factura_detalle_id BIGINT NOT NULL,
 producto_id INT NOT NULL,
 costo_referencia DECIMAL(14,4) NOT NULL,
 costo_factura DECIMAL(14,4) NOT NULL,
 usuario_id INT NOT NULL,
 motivo VARCHAR(255) NOT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_cp_omitido_detalle (factura_detalle_id),
 KEY idx_cp_omisiones_factura (factura_id),
 KEY idx_cp_omisiones_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
