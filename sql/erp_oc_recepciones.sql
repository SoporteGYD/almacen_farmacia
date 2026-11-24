-- ERP G&D · Recepciones parciales de órdenes de compra
-- IMPORTANTE: Respaldar la BD prueba antes de ejecutar una sola vez.
-- No actualiza existencias ni costos, no genera entradas de almacén y no modifica tablas POS.
-- Requiere erp_ordenes_compra, erp_ordenes_compra_detalle y erp_oc_autorizaciones.
CREATE TABLE IF NOT EXISTS erp_oc_recepciones (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  folio VARCHAR(40) DEFAULT NULL UNIQUE,
  orden_id BIGINT UNSIGNED NOT NULL,
  destino VARCHAR(30) NOT NULL,
  fecha_recepcion DATE NOT NULL,
  referencia_factura VARCHAR(100) DEFAULT NULL,
  observaciones VARCHAR(500) DEFAULT NULL,
  estado ENUM('REGISTRADA','ANULADA') NOT NULL DEFAULT 'REGISTRADA',
  usuario_id INT NOT NULL,
  solicitud_token CHAR(32) NOT NULL UNIQUE,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_oc_rec_orden_estado (orden_id, estado),
  INDEX idx_oc_rec_destino_fecha (destino, fecha_recepcion),
  CONSTRAINT fk_oc_rec_orden FOREIGN KEY (orden_id) REFERENCES erp_ordenes_compra (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_oc_recepciones_detalle (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  recepcion_id BIGINT UNSIGNED NOT NULL,
  orden_detalle_id BIGINT UNSIGNED NOT NULL,
  producto_id INT NOT NULL,
  cantidad_piezas INT UNSIGNED NOT NULL,
  lote VARCHAR(100) DEFAULT NULL,
  caducidad DATE DEFAULT NULL,
  UNIQUE KEY uq_oc_rec_detalle (recepcion_id, orden_detalle_id),
  INDEX idx_oc_rec_detalle_oc (orden_detalle_id),
  CONSTRAINT fk_oc_rec_det_recepcion FOREIGN KEY (recepcion_id) REFERENCES erp_oc_recepciones (id),
  CONSTRAINT fk_oc_rec_det_oc FOREIGN KEY (orden_detalle_id) REFERENCES erp_ordenes_compra_detalle (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
