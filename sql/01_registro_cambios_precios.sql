-- Ejecutar en la base de datos de PRUEBAS tras hacer respaldo.
-- No modifica precios, costos, inventarios ni registros previos.
-- NO ejecutar en producción sin respaldo y validación previa.
CREATE TABLE IF NOT EXISTS erp_precio_operaciones (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    token CHAR(32) NOT NULL,
    factura_id BIGINT NOT NULL,
    factura_detalle_id BIGINT NOT NULL,
    producto_id INT NOT NULL,
    costo_antes DECIMAL(14,4) NOT NULL,
    costo_factura DECIMAL(14,4) NOT NULL,
    modo VARCHAR(20) NOT NULL,
    calculo VARCHAR(20) NULL,
    motivo VARCHAR(250) NOT NULL,
    usuario_id INT NOT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_erp_precio_token (token),
    KEY ix_erp_precio_hist_producto (producto_id, creado_en),
    KEY ix_erp_precio_factura (factura_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS erp_precio_operacion_detalle (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    operacion_id BIGINT UNSIGNED NOT NULL,
    sucursal_id INT NOT NULL,
    precio_anterior DECIMAL(14,2) NULL,
    precio_nuevo DECIMAL(14,2) NOT NULL,
    UNIQUE KEY uk_erp_precio_op_sucursal (operacion_id,sucursal_id),
    KEY ix_erp_precio_sucursal (sucursal_id),
    CONSTRAINT fk_erp_precio_operacion FOREIGN KEY (operacion_id)
       REFERENCES erp_precio_operaciones(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
