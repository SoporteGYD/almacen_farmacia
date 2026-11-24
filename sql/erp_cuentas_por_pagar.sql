-- ERP G&D: Cuentas por pagar a proveedores
-- Requiere la migración sql/erp_facturas_verificacion.sql ya aplicada.
-- No altera tablas de inventario ni los movimientos de caja.
-- Ejecutar en la base de pruebas `prueba` después de respaldar.
CREATE TABLE IF NOT EXISTS erp_cxp_documentos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  factura_id BIGINT UNSIGNED NOT NULL,
  proveedor_id INT NOT NULL,
  sucursal ENUM('TAPACHULA','TOSCANA','HIDALGO','TUXTLA') NOT NULL,
  total_documento DECIMAL(14,2) NOT NULL,
  fecha_vencimiento DATE NOT NULL,
  dias_credito SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  observaciones VARCHAR(500) DEFAULT NULL,
  registro_token CHAR(32) NOT NULL UNIQUE,
  registrado_por INT NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cxp_factura (factura_id),
  KEY idx_cxp_vencimiento (fecha_vencimiento),
  KEY idx_cxp_proveedor (proveedor_id),
  KEY idx_cxp_sucursal (sucursal),
  CONSTRAINT fk_cxp_factura FOREIGN KEY (factura_id) REFERENCES erp_facturas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_cxp_solicitudes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  documento_id BIGINT UNSIGNED NOT NULL,
  tipo ENUM('PAGO','NOTA_CREDITO') NOT NULL,
  importe DECIMAL(14,2) NOT NULL,
  estado ENUM('PENDIENTE','AUTORIZADA','RECHAZADA','APLICADA','CANCELADA') NOT NULL DEFAULT 'PENDIENTE',
  motivo VARCHAR(500) NOT NULL,
  solicitud_token CHAR(32) NOT NULL UNIQUE,
  solicitado_por INT NOT NULL,
  solicitado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  autorizado_por INT DEFAULT NULL,
  autorizado_en DATETIME DEFAULT NULL,
  resolucion VARCHAR(500) DEFAULT NULL,
  UNIQUE KEY uq_cxp_id_doc (id, documento_id),
  KEY idx_cxp_sol_doc_estado (documento_id,estado),
  CONSTRAINT fk_cxp_sol_doc FOREIGN KEY (documento_id) REFERENCES erp_cxp_documentos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_cxp_aplicaciones (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  solicitud_id BIGINT UNSIGNED NOT NULL,
  documento_id BIGINT UNSIGNED NOT NULL,
  tipo ENUM('PAGO','NOTA_CREDITO') NOT NULL,
  importe DECIMAL(14,2) NOT NULL,
  fecha_real DATE NOT NULL,
  medio ENUM('EFECTIVO','TRANSFERENCIA','DEPOSITO','CHEQUE','OTRO','NOTA_CREDITO') NOT NULL,
  referencia VARCHAR(120) NOT NULL,
  observaciones VARCHAR(500) DEFAULT NULL,
  evidencia_archivo VARCHAR(64) DEFAULT NULL,
  evidencia_mime VARCHAR(50) DEFAULT NULL,
  aplicado_por INT NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cxp_solicitud_aplicada (solicitud_id),
  KEY idx_cxp_apl_documento (documento_id),
  CONSTRAINT fk_cxp_apl_sol_doc FOREIGN KEY (solicitud_id, documento_id) REFERENCES erp_cxp_solicitudes(id, documento_id),
  CONSTRAINT fk_cxp_apl_documento FOREIGN KEY (documento_id) REFERENCES erp_cxp_documentos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_cxp_auditoria (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  documento_id BIGINT UNSIGNED NOT NULL,
  solicitud_id BIGINT UNSIGNED DEFAULT NULL,
  evento ENUM('ALTA','SOLICITUD','AUTORIZACION','RECHAZO','CANCELACION','APLICACION') NOT NULL,
  usuario_id INT NOT NULL,
  detalle VARCHAR(600) NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cxp_audit_doc (documento_id,creado_en),
  CONSTRAINT fk_cxp_audit_doc FOREIGN KEY (documento_id) REFERENCES erp_cxp_documentos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
