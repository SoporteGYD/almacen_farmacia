-- G&D ERP · Verificacion DOCUMENTAL de origen para ventas anteriores al control físico.
-- Base destinada: prueba. Requiere primero sql/erp_pos_reversiones.sql de la entrega anterior.
-- NO altera saldos, pagos, ventas previas ni asigna origen automáticamente.
CREATE TABLE IF NOT EXISTS pos_origen_legacy_verificaciones (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  venta_id BIGINT UNSIGNED NOT NULL,
  producto_id INT NOT NULL,
  origen ENUM('FARMACIA','ALMACEN','FARMACIA_GENERAL') NOT NULL,
  piezas INT NOT NULL,
  evidencia VARCHAR(500) NOT NULL,
  usuario_id INT NOT NULL,
  verificado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_venta_producto_legacy (venta_id, producto_id),
  KEY idx_usuario_fecha (usuario_id, verificado_en),
  KEY idx_venta (venta_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
