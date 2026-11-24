-- EJECUTAR UNA SOLA VEZ sobre la base `prueba` tras realizar respaldo.
-- No altera tablas de ventas, almacén, POS ni inventarios.
CREATE TABLE IF NOT EXISTS erp_ordenes_compra (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 folio VARCHAR(40) DEFAULT NULL UNIQUE,
 proveedor_id INT NOT NULL,
 destino VARCHAR(30) NOT NULL,
 fecha DATE NOT NULL,
 estado ENUM('BORRADOR','EMITIDA','CANCELADA') NOT NULL DEFAULT 'BORRADOR',
 observaciones VARCHAR(500) DEFAULT NULL,
 subtotal DECIMAL(16,2) NOT NULL DEFAULT 0.00,
 usuario_id INT DEFAULT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_erp_oc_proveedor (proveedor_id), INDEX idx_erp_oc_estado_fecha (estado, fecha),
 CONSTRAINT fk_erp_oc_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedores(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS erp_ordenes_compra_detalle (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 orden_id BIGINT UNSIGNED NOT NULL,
 producto_id INT NOT NULL,
 codigo_snapshot VARCHAR(150) NOT NULL,
 descripcion_snapshot VARCHAR(255) NOT NULL,
 cantidad_piezas INT UNSIGNED NOT NULL,
 costo_unitario DECIMAL(16,4) NOT NULL,
 importe DECIMAL(16,2) NOT NULL,
 CONSTRAINT fk_erp_oc_detalle_orden FOREIGN KEY (orden_id) REFERENCES erp_ordenes_compra(id),
 INDEX idx_erp_oc_detalle_producto (producto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
