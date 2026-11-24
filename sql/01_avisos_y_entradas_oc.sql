-- ERP G&D: Avisos internos + enlace único recepción de Bodega a entrada física.
-- Ejecútese en la MISMA BD que usan Compras, Recepciones y Almacén.
-- NO modifica saldos, NO crea movimientos de inventario, NO toca POS.
CREATE TABLE IF NOT EXISTS erp_oc_avisos_compras (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  recepcion_id BIGINT UNSIGNED NOT NULL,
  orden_id BIGINT UNSIGNED NOT NULL,
  usuario_emisor_id INT NOT NULL,
  estado ENUM('PENDIENTE','REVISADO') NOT NULL DEFAULT 'PENDIENTE',
  mensaje VARCHAR(300) NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revisado_por INT DEFAULT NULL,
  revisado_en DATETIME DEFAULT NULL,
  UNIQUE KEY uq_aviso_recepcion (recepcion_id),
  INDEX idx_oc_avisos_estado (estado,creado_en),
  CONSTRAINT fk_aviso_oc_recepcion FOREIGN KEY (recepcion_id) REFERENCES erp_oc_recepciones(id),
  CONSTRAINT fk_aviso_oc_orden FOREIGN KEY (orden_id) REFERENCES erp_ordenes_compra(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_oc_entrada_vinculos (
  recepcion_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  movimiento_id INT NOT NULL UNIQUE,
  usuario_id INT NOT NULL,
  aplicado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_oc_entrada_recepcion FOREIGN KEY (recepcion_id) REFERENCES erp_oc_recepciones(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
