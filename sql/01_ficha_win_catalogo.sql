-- G&D | Extensión de ficha de artículo inspirada en WinCaja.
-- SOLO metadatos. NO modifica productos existentes, ni POS, ni existencias de almacén.
CREATE TABLE IF NOT EXISTS erp_producto_ficha (
 producto_id INT NOT NULL PRIMARY KEY,
 tipo VARCHAR(70) DEFAULT NULL,
 subfamilia VARCHAR(120) DEFAULT NULL,
 descripcion_ampliada TEXT DEFAULT NULL,
 unidad_compra VARCHAR(20) NOT NULL DEFAULT 'PZA',
 factor_compra DECIMAL(14,4) NOT NULL DEFAULT 1,
 unidad_venta VARCHAR(20) NOT NULL DEFAULT 'PZA',
 factor_venta DECIMAL(14,4) NOT NULL DEFAULT 1,
 unidad_detallista VARCHAR(20) DEFAULT NULL,
 factor_detallista DECIMAL(14,4) DEFAULT NULL,
 unidad_auxiliar VARCHAR(20) DEFAULT NULL,
 factor_auxiliar DECIMAL(14,4) DEFAULT NULL,
 no_inventariable TINYINT(1) NOT NULL DEFAULT 0,
 es_compuesto TINYINT(1) NOT NULL DEFAULT 0,
 rastreo_lote_caducidad TINYINT(1) NOT NULL DEFAULT 0,
 numeros_serie TINYINT(1) NOT NULL DEFAULT 0,
 precio_libre TINYINT(1) NOT NULL DEFAULT 0,
 iva_compra DECIMAL(5,2) DEFAULT NULL,
 iva_venta DECIMAL(5,2) DEFAULT NULL,
 ieps DECIMAL(5,2) DEFAULT NULL,
 margen_sugerido DECIMAL(7,2) DEFAULT NULL,
 precio_sugerido DECIMAL(14,2) DEFAULT NULL,
 precio_costo_promedio TINYINT(1) NOT NULL DEFAULT 0,
 piezas_cambio_precio INT DEFAULT NULL,
 metodo_precio ENUM('MARK_UP','MARK_DOWN') NOT NULL DEFAULT 'MARK_UP',
 descuento_1 DECIMAL(5,2) DEFAULT NULL,
 descuento_2 DECIMAL(5,2) DEFAULT NULL,
 descuento_3 DECIMAL(5,2) DEFAULT NULL,
 descuento_4 DECIMAL(5,2) DEFAULT NULL,
 descuento_general DECIMAL(5,2) DEFAULT NULL,
 costo_flete_unitario DECIMAL(14,2) DEFAULT NULL,
 puntos_monto_compra DECIMAL(14,2) DEFAULT NULL,
 puntos_otorgar INT DEFAULT NULL,
 puntos_canje INT DEFAULT NULL,
 observaciones TEXT DEFAULT NULL,
 imagen_referencia VARCHAR(255) DEFAULT NULL,
 creado_por INT DEFAULT NULL,
 actualizado_por INT DEFAULT NULL,
 actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_erp_ficha_producto FOREIGN KEY(producto_id) REFERENCES productos(id) ON DELETE CASCADE,
 CONSTRAINT chk_ficha_factor_compra CHECK (factor_compra>0),
 CONSTRAINT chk_ficha_factor_venta CHECK (factor_venta>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS erp_producto_equivalencias (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 producto_id INT NOT NULL,
 codigo VARCHAR(150) NOT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_erp_prod_equiv_codigo(codigo),
 KEY ix_erp_prod_equiv_producto(producto_id),
 CONSTRAINT fk_erp_prod_equiv_producto FOREIGN KEY(producto_id) REFERENCES productos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS erp_producto_proveedores (
 producto_id INT NOT NULL,
 proveedor_id INT NOT NULL,
 prioridad INT NOT NULL DEFAULT 1,
 PRIMARY KEY(producto_id,proveedor_id),
 FOREIGN KEY(producto_id) REFERENCES productos(id) ON DELETE CASCADE,
 FOREIGN KEY(proveedor_id) REFERENCES proveedores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS erp_producto_historial_ficha (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 producto_id INT NOT NULL,
 usuario_id INT DEFAULT NULL,
 accion VARCHAR(40) NOT NULL,
 datos_anteriores LONGTEXT DEFAULT NULL,
 datos_nuevos LONGTEXT DEFAULT NULL,
 fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY ix_erp_hist_ficha_producto(producto_id,fecha),
 CONSTRAINT fk_erp_hist_ficha_producto FOREIGN KEY(producto_id) REFERENCES productos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
