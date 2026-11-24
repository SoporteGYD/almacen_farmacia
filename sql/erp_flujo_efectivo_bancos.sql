-- ERP G&D - Flujo de efectivo, bancos y presupuestos (v1.0)
-- Requiere erp_cuentas_por_pagar.sql y erp_tesoreria_calendario.sql.
-- Ejecutar una vez sobre la base de pruebas `prueba` (con respaldo previo).
-- No modifica tablas existentes, POS ni Almacén.
CREATE TABLE IF NOT EXISTS erp_fin_cuentas (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  banco VARCHAR(100) NOT NULL,
  terminacion CHAR(4) NOT NULL,
  sucursal ENUM('GENERAL','TAPACHULA','TOSCANA','HIDALGO','TUXTLA') NOT NULL DEFAULT 'GENERAL',
  fecha_corte DATE NOT NULL,
  saldo_inicial DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  activa TINYINT(1) NOT NULL DEFAULT 1,
  creado_por INT NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_fin_cuenta_sucursal (sucursal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_fin_movimientos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cuenta_id BIGINT UNSIGNED NOT NULL,
  tipo ENUM('INGRESO','EGRESO') NOT NULL,
  categoria ENUM('DEPOSITO_VENTAS','COBRO','APORTACION','OTRO_INGRESO','PROVEEDORES','NOMINA','OPERACION','IMPUESTOS','OTRO_EGRESO') NOT NULL,
  sucursal ENUM('GENERAL','TAPACHULA','TOSCANA','HIDALGO','TUXTLA') NOT NULL DEFAULT 'GENERAL',
  importe DECIMAL(14,2) NOT NULL,
  fecha_movimiento DATE NOT NULL,
  referencia VARCHAR(120) NOT NULL,
  concepto VARCHAR(500) NOT NULL,
  cxp_aplicacion_id BIGINT UNSIGNED DEFAULT NULL,
  estado ENUM('PENDIENTE','AUTORIZADO','REGISTRADO','RECHAZADO','CANCELADO') NOT NULL DEFAULT 'PENDIENTE',
  token CHAR(32) NOT NULL,
  indice_registro CHAR(64) DEFAULT NULL,
  solicitado_por INT NOT NULL,
  solicitado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  autorizado_por INT DEFAULT NULL,
  autorizado_en DATETIME DEFAULT NULL,
  registrado_por INT DEFAULT NULL,
  registrado_en DATETIME DEFAULT NULL,
  resolucion VARCHAR(500) DEFAULT NULL,
  UNIQUE KEY uq_fin_mov_token (token),
  UNIQUE KEY uq_fin_mov_indice (indice_registro),
  UNIQUE KEY uq_fin_mov_cxp (cxp_aplicacion_id),
  KEY idx_fin_mov_cuenta_fecha (cuenta_id,fecha_movimiento,estado),
  KEY idx_fin_mov_categoria_fecha (categoria,fecha_movimiento),
  CONSTRAINT fk_fin_mov_cuenta FOREIGN KEY (cuenta_id) REFERENCES erp_fin_cuentas(id),
  CONSTRAINT fk_fin_mov_cxp FOREIGN KEY (cxp_aplicacion_id) REFERENCES erp_cxp_aplicaciones(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_fin_presupuestos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  periodo CHAR(7) NOT NULL,
  sucursal ENUM('GENERAL','TAPACHULA','TOSCANA','HIDALGO','TUXTLA') NOT NULL,
  categoria ENUM('PROVEEDORES','NOMINA','OPERACION','IMPUESTOS','OTRO_EGRESO') NOT NULL,
  limite DECIMAL(14,2) NOT NULL,
  actualizado_por INT NOT NULL,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_fin_presupuesto (periodo,sucursal,categoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_fin_pronosticos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cuenta_id BIGINT UNSIGNED NOT NULL,
  tipo ENUM('INGRESO','EGRESO') NOT NULL,
  fecha_prevista DATE NOT NULL,
  importe DECIMAL(14,2) NOT NULL,
  concepto VARCHAR(300) NOT NULL,
  estado ENUM('ACTIVO','CANCELADO') NOT NULL DEFAULT 'ACTIVO',
  token CHAR(32) NOT NULL,
  creado_por INT NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cancelado_por INT DEFAULT NULL,
  cancelado_en DATETIME DEFAULT NULL,
  UNIQUE KEY uq_fin_pronostico_token (token),
  KEY idx_fin_pronostico_fecha (fecha_prevista,estado),
  CONSTRAINT fk_fin_pronostico_cuenta FOREIGN KEY (cuenta_id) REFERENCES erp_fin_cuentas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_fin_tes_cuentas (
  programacion_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  cuenta_id BIGINT UNSIGNED NOT NULL,
  asignado_por INT NOT NULL,
  asignado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fin_tes_prog FOREIGN KEY (programacion_id) REFERENCES erp_tes_programaciones(id),
  CONSTRAINT fk_fin_tes_cuenta FOREIGN KEY (cuenta_id) REFERENCES erp_fin_cuentas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_fin_conciliaciones (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cuenta_id BIGINT UNSIGNED NOT NULL,
  fecha_estado DATE NOT NULL,
  saldo_bancario DECIMAL(14,2) NOT NULL,
  saldo_erp DECIMAL(14,2) NOT NULL,
  diferencia DECIMAL(14,2) NOT NULL,
  observaciones VARCHAR(500) NOT NULL,
  conciliado_por INT NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_fin_conc_cuenta_fecha (cuenta_id,fecha_estado),
  CONSTRAINT fk_fin_conc_cuenta FOREIGN KEY (cuenta_id) REFERENCES erp_fin_cuentas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_fin_auditoria (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  evento VARCHAR(40) NOT NULL,
  entidad VARCHAR(40) NOT NULL,
  entidad_id BIGINT UNSIGNED NOT NULL,
  usuario_id INT NOT NULL,
  detalle VARCHAR(600) NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_fin_audit_fecha (creado_en),
  KEY idx_fin_audit_entidad (entidad,entidad_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
