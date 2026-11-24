-- ERP G&D / POS: cancelaciones y devoluciones seguras. Ejecutar SOLO en la base de PRUEBAS `prueba`.
-- Ejecutar ANTES de reemplazar PuntoVenta.php: nuevas ventas registran origen fisico.
-- No modifica ni corrige movimientos o ventas historicas.
CREATE TABLE IF NOT EXISTS pos_venta_origenes (
  venta_id BIGINT UNSIGNED NOT NULL,
  producto_id INT NOT NULL,
  origen ENUM('FARMACIA','ALMACEN','FARMACIA_GENERAL') NOT NULL,
  piezas INT NOT NULL,
  almacen_ticket_id BIGINT UNSIGNED NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(venta_id,producto_id),
  KEY idx_producto(producto_id),
  KEY idx_ticket(almacen_ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS pos_reversiones (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  operacion_clave CHAR(36) NOT NULL,
  venta_id BIGINT UNSIGNED NOT NULL,
  tipo ENUM('CANCELACION_TOTAL','DEVOLUCION_PARCIAL','INCIDENCIA') NOT NULL,
  estado ENUM('APLICADA','PENDIENTE_REVISION') NOT NULL,
  detalle_id BIGINT UNSIGNED NULL,
  producto_id INT NULL,
  cantidad_presentaciones INT NOT NULL DEFAULT 0,
  piezas INT NOT NULL DEFAULT 0,
  importe DECIMAL(14,2) NOT NULL DEFAULT 0,
  medio_reembolso ENUM('EFECTIVO','TARJETA','TRANSFERENCIA','OTRO','PENDIENTE') DEFAULT NULL,
  referencia_reembolso VARCHAR(120) DEFAULT NULL,
  motivo VARCHAR(500) NOT NULL,
  usuario_id INT NOT NULL,
  turno_reembolso_id BIGINT UNSIGNED NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_operacion(operacion_clave),
  KEY idx_venta(venta_id,estado,tipo),
  KEY idx_usuario(usuario_id,creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS pos_tickets_cancelados_erp (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ticket_id BIGINT UNSIGNED NOT NULL,
  resurtido_id INT NOT NULL,
  usuario_id INT NOT NULL,
  motivo VARCHAR(500) NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ticket_cancelado(ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
