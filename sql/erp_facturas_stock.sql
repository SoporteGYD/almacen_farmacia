-- ERP G&D / Aplicación única de partidas ACEPTADAS al stock operativo.
-- Requiere sql/erp_facturas_verificacion.sql previamente instalado.
-- Ejecutar UNA SOLA VEZ en la copia de PRUEBAS después de hacer respaldo.
-- No modifica ni incrementa stock por sí mismo. El stock sólo cambia al confirmar
-- voluntariamente una aplicación en ERP G&D -> Aplicación al inventario.
CREATE TABLE IF NOT EXISTS erp_facturas_stock_aplicaciones (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  factura_id BIGINT UNSIGNED NOT NULL,
  revision_detalle_id BIGINT UNSIGNED NOT NULL,
  producto_id INT NOT NULL,
  cantidad INT UNSIGNED NOT NULL,
  destino ENUM('ALMACEN','FARMACIA') NOT NULL,
  ubicacion VARCHAR(100) DEFAULT NULL,
  almacen_id INT DEFAULT NULL,
  pos_sucursal_id INT DEFAULT NULL,
  movimiento_id INT DEFAULT NULL,
  usuario_id INT NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_erp_stock_revision (revision_detalle_id),
  KEY idx_erp_stock_factura (factura_id),
  KEY idx_erp_stock_producto (producto_id),
  CONSTRAINT fk_erp_stock_factura FOREIGN KEY (factura_id) REFERENCES erp_facturas(id),
  CONSTRAINT fk_erp_stock_revision FOREIGN KEY (revision_detalle_id) REFERENCES erp_facturas_revision_detalle(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
