-- ERP G&D: devoluciones de ventas surtidas desde ALMACEN al POS.
-- INSTALAR SOLO en base prueba, DESPUES de erp_pos_reversiones.sql y erp_pos_origenes_historicos.sql.
-- No ajusta saldos históricos ni realiza devoluciones bancarias.
CREATE TABLE IF NOT EXISTS pos_devoluciones_almacen (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clave CHAR(32) NOT NULL,
  venta_id BIGINT UNSIGNED NOT NULL,
  detalle_id BIGINT UNSIGNED NOT NULL,
  producto_id INT NOT NULL,
  ticket_id BIGINT UNSIGNED NOT NULL,
  cantidad_presentaciones INT NOT NULL,
  piezas INT NOT NULL,
  importe DECIMAL(14,2) NOT NULL,
  motivo VARCHAR(500) NOT NULL,
  estado ENUM('SOLICITADA','APLICADA','REQUIERE_REVISION') NOT NULL DEFAULT 'SOLICITADA',
  solicitado_por INT NOT NULL,
  solicitado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ubicacion_existencia_id INT NULL,
  movimiento_id INT NULL,
  medio_reembolso ENUM('EFECTIVO','TARJETA','TRANSFERENCIA','OTRO') NULL,
  referencia_reembolso VARCHAR(120) NULL,
  recibido_por VARCHAR(150) NULL,
  evidencia VARCHAR(500) NULL,
  aprobado_por INT NULL,
  aprobado_en DATETIME NULL,
  observaciones_revision VARCHAR(500) NULL,
  UNIQUE KEY uq_clave(clave),
  KEY idx_detalle_estado(detalle_id,estado),
  KEY idx_venta(venta_id,estado),
  KEY idx_solicitado(solicitado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
