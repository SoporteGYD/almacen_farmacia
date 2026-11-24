-- ERP G&D | Gestión de conteos y ajustes: solo entorno prueba, con respaldo previo.
-- Requiere erp_pos_farmacia_control, pos_existencias_farmacia y ERP POS Inteligente instalados.
-- Esta migración NO altera saldos. Conservar sus tablas para la trazabilidad.
CREATE TABLE IF NOT EXISTS erp_conciliacion_conteos (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 sucursal_id INT NOT NULL,
 producto_id INT NOT NULL,
 ubicacion ENUM('FARMACIA','ALMACEN') NOT NULL,
 cantidad_contada INT NOT NULL,
 cantidad_sistema INT NULL,
 pos_general_corte INT NOT NULL,
 cobrado_pendiente_corte INT NOT NULL DEFAULT 0,
 observaciones VARCHAR(500) NOT NULL,
 usuario_id INT NOT NULL,
 registrado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_conc_conteos_producto (sucursal_id,producto_id,ubicacion,id),
 KEY idx_conc_conteos_usuario (usuario_id,registrado_en),
 CONSTRAINT ck_conc_conteos_cantidad CHECK (cantidad_contada >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_conciliacion_ajustes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 conteo_id BIGINT UNSIGNED NOT NULL,
 sucursal_id INT NOT NULL,
 producto_id INT NOT NULL,
 destino ENUM('FARMACIA','POS_GENERAL','ALMACEN') NOT NULL,
 cantidad_objetivo INT NOT NULL,
 cantidad_anterior INT NULL,
 estado ENUM('SOLICITADO','AUTORIZADO','RECHAZADO','APLICADO','PENDIENTE_OPERACION_ALMACEN','CERRADO_MANUAL','CADUCADO') NOT NULL DEFAULT 'SOLICITADO',
 motivo VARCHAR(500) NOT NULL,
 solicitado_por INT NOT NULL,
 solicitado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 autorizado_por INT NULL,
 autorizado_en DATETIME NULL,
 aplicado_por INT NULL,
 aplicado_en DATETIME NULL,
 referencia VARCHAR(250) NULL,
 UNIQUE KEY uq_conc_ajuste_conteo_destino (conteo_id,destino),
 KEY idx_conc_ajustes_estado (estado,solicitado_en),
 KEY idx_conc_ajustes_producto (sucursal_id,producto_id),
 FOREIGN KEY (conteo_id) REFERENCES erp_conciliacion_conteos(id),
 CONSTRAINT ck_conc_ajustes_cantidad CHECK (cantidad_objetivo >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_conciliacion_bitacora (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ajuste_id BIGINT UNSIGNED NOT NULL,
 evento VARCHAR(35) NOT NULL,
 usuario_id INT NOT NULL,
 anterior INT NULL,
 nuevo INT NULL,
 observacion VARCHAR(500) NOT NULL,
 registrado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_conc_bitacora (ajuste_id,id),
 FOREIGN KEY (ajuste_id) REFERENCES erp_conciliacion_ajustes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
