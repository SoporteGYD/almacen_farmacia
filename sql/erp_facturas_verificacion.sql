-- G&D ERP / Entrada general por factura y verificación física (ETAPA DE CONCILIACIÓN)
-- Ejecutar UNA SOLA VEZ en una copia de la BD `prueba` con respaldo previo.
-- No modifica inventarios POS ni almacén. Las piezas registradas son CONTABLES,
-- y las aceptadas en verificación son un saldo de control, no stock operativo.
-- Requiere tablas: usuarios, proveedores, productos, erp_ordenes_compra.
CREATE TABLE IF NOT EXISTS erp_facturas (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  folio_interno VARCHAR(30) DEFAULT NULL UNIQUE,
  proveedor_id INT NOT NULL,
  sucursal ENUM('TAPACHULA','TOSCANA','HIDALGO','TUXTLA') NOT NULL,
  orden_id BIGINT UNSIGNED DEFAULT NULL,
  numero_factura VARCHAR(100) NOT NULL,
  uuid CHAR(36) DEFAULT NULL,
  fecha_factura DATE NOT NULL,
  estado ENUM('REGISTRADA','EN_REVISION','CON_INCIDENCIAS','VERIFICADA') NOT NULL DEFAULT 'REGISTRADA',
  observaciones VARCHAR(500) DEFAULT NULL,
  usuario_id INT NOT NULL,
  captura_token CHAR(32) NOT NULL UNIQUE,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_factura_proveedor_numero_fecha (proveedor_id, numero_factura, fecha_factura),
  UNIQUE KEY uq_factura_uuid (uuid),
  KEY idx_facturas_sucursal_estado (sucursal,estado),
  KEY idx_facturas_orden (orden_id),
  CONSTRAINT fk_ef_factura_prov FOREIGN KEY (proveedor_id) REFERENCES proveedores(id),
  CONSTRAINT fk_ef_factura_orden FOREIGN KEY (orden_id) REFERENCES erp_ordenes_compra(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_facturas_detalle (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  factura_id BIGINT UNSIGNED NOT NULL,
  producto_id INT NOT NULL,
  codigo_snapshot VARCHAR(150) NOT NULL,
  descripcion_snapshot VARCHAR(255) NOT NULL,
  cantidad_facturada INT UNSIGNED NOT NULL,
  costo_unitario DECIMAL(14,4) NOT NULL,
  UNIQUE KEY uq_factura_producto (factura_id,producto_id),
  KEY idx_ef_productos (producto_id),
  CONSTRAINT fk_ef_det_factura FOREIGN KEY (factura_id) REFERENCES erp_facturas(id),
  CONSTRAINT fk_ef_det_prod FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_facturas_revisiones (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  factura_id BIGINT UNSIGNED NOT NULL,
  solicitud_token CHAR(32) NOT NULL UNIQUE,
  usuario_id INT NOT NULL,
  observaciones VARCHAR(500) DEFAULT NULL,
  fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ef_revisiones_factura (factura_id,fecha),
  CONSTRAINT fk_ef_rev_factura FOREIGN KEY (factura_id) REFERENCES erp_facturas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_facturas_revision_detalle (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  revision_id BIGINT UNSIGNED NOT NULL,
  factura_detalle_id BIGINT UNSIGNED NOT NULL,
  tipo ENUM('ACEPTADA','FALTANTE','DANADA','CADUCADA','EQUIVOCADA') NOT NULL,
  cantidad INT UNSIGNED NOT NULL,
  lote VARCHAR(100) DEFAULT NULL,
  caducidad DATE DEFAULT NULL,
  observaciones VARCHAR(500) DEFAULT NULL,
  resolucion ENUM('NOTA_CREDITO','REPOSICION_RECIBIDA','DEVOLUCION','AJUSTE_DOCUMENTAL','OTRA') DEFAULT NULL,
  resolucion_nota VARCHAR(500) DEFAULT NULL,
  resuelto_por INT DEFAULT NULL,
  resuelto_en DATETIME DEFAULT NULL,
  KEY idx_ef_revision_detalle_factura (factura_detalle_id,tipo),
  CONSTRAINT fk_ef_rd_revision FOREIGN KEY (revision_id) REFERENCES erp_facturas_revisiones(id),
  CONSTRAINT fk_ef_rd_detalle FOREIGN KEY (factura_detalle_id) REFERENCES erp_facturas_detalle(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_facturas_incidencias_adicionales (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  factura_id BIGINT UNSIGNED NOT NULL,
  producto_id INT DEFAULT NULL,
  tipo ENUM('SOBRANTE','SIN_FACTURA','OTRO') NOT NULL,
  cantidad INT UNSIGNED NOT NULL,
  descripcion VARCHAR(500) NOT NULL,
  solicitud_token CHAR(32) NOT NULL UNIQUE,
  estado ENUM('ABIERTA','RESUELTA') NOT NULL DEFAULT 'ABIERTA',
  resolucion VARCHAR(500) DEFAULT NULL,
  registrado_por INT NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resuelto_por INT DEFAULT NULL,
  resuelto_en DATETIME DEFAULT NULL,
  KEY idx_ef_adicionales_factura (factura_id,estado),
  CONSTRAINT fk_ef_adicional_factura FOREIGN KEY (factura_id) REFERENCES erp_facturas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
