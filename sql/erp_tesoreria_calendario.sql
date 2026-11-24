-- ERP G&D / Tesorería y calendario de pagos
-- REQUIERE: sql/erp_cuentas_por_pagar.sql ejecutado primero.
-- No cambia saldos ni tablas de POS/inventarios.
CREATE TABLE IF NOT EXISTS erp_tes_programaciones (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 solicitud_id BIGINT UNSIGNED NOT NULL,
 documento_id BIGINT UNSIGNED NOT NULL,
 fecha_programada DATE NOT NULL,
 prioridad ENUM('BAJA','NORMAL','ALTA','URGENTE') NOT NULL DEFAULT 'NORMAL',
 estado ENUM('PENDIENTE','AUTORIZADA','RECHAZADA','CANCELADA') NOT NULL DEFAULT 'PENDIENTE',
 observaciones VARCHAR(500) DEFAULT NULL,
 revision_observaciones VARCHAR(500) DEFAULT NULL,
 solicitada_por INT NOT NULL,
 solicitada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 autorizada_por INT DEFAULT NULL,
 autorizada_en DATETIME DEFAULT NULL,
 actualizada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_tes_programacion_solicitud (solicitud_id),
 KEY idx_tes_estado_fecha (estado,fecha_programada),
 KEY idx_tes_documento (documento_id),
 CONSTRAINT fk_tes_programacion_cxp_sol FOREIGN KEY (solicitud_id,documento_id)
  REFERENCES erp_cxp_solicitudes(id,documento_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_tes_programaciones_historial (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 programacion_id BIGINT UNSIGNED NOT NULL,
 accion ENUM('SOLICITADA','REPROGRAMADA','AUTORIZADA','RECHAZADA','CANCELADA') NOT NULL,
 fecha_programada DATE NOT NULL,
 prioridad VARCHAR(12) NOT NULL,
 usuario_id INT NOT NULL,
 detalle VARCHAR(600) NOT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_tes_his_prog (programacion_id,creado_en),
 CONSTRAINT fk_tes_his_prog FOREIGN KEY (programacion_id)
  REFERENCES erp_tes_programaciones(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
