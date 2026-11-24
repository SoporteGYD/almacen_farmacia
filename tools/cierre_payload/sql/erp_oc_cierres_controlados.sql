-- ERP G&D: cierre administrativo auditado. No modifica cantidades, precios o la columna heredada estado.
CREATE TABLE IF NOT EXISTS erp_oc_cierres_controlados (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 orden_id BIGINT UNSIGNED NOT NULL,
 estado ENUM('CERRADA','REABIERTA') NOT NULL DEFAULT 'CERRADA',
 tipo ENUM('TOTAL','CON_DIFERENCIAS') NOT NULL,
 motivo VARCHAR(500) NOT NULL,
 evidencia VARCHAR(255) DEFAULT NULL,
 solicitadas INT UNSIGNED NOT NULL,
 recibidas_buenas INT UNSIGNED NOT NULL,
 facturadas INT UNSIGNED NOT NULL,
 aceptadas INT UNSIGNED NOT NULL,
 aplicadas INT UNSIGNED NOT NULL,
 faltantes_definitivas INT UNSIGNED NOT NULL,
 incidencias_especiales INT UNSIGNED NOT NULL DEFAULT 0,
 cerrado_por INT NOT NULL,
 cerrado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 reabierto_por INT DEFAULT NULL,
 reabierto_en DATETIME DEFAULT NULL,
 motivo_reapertura VARCHAR(500) DEFAULT NULL,
 UNIQUE KEY uq_oc_cierre_controlado (orden_id),
 KEY ix_estado (estado), KEY ix_cerrado_en (cerrado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS erp_oc_cierres_partidas (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 cierre_id BIGINT UNSIGNED NOT NULL,
 bitacora_id BIGINT UNSIGNED NOT NULL,
 producto_id INT NOT NULL,
 codigo VARCHAR(160) NOT NULL,
 solicitado INT UNSIGNED NOT NULL,
 recibido_bueno INT UNSIGNED NOT NULL,
 faltante_definitivo INT UNSIGNED NOT NULL,
 KEY ix_cierre_id (cierre_id), KEY ix_bitacora_id (bitacora_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS erp_oc_cierres_bitacora (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 orden_id BIGINT UNSIGNED NOT NULL,
 cierre_id BIGINT UNSIGNED NOT NULL,
 accion ENUM('CIERRE_TOTAL','CIERRE_CON_DIFERENCIAS','REAPERTURA') NOT NULL,
 usuario_id INT NOT NULL,
 observaciones VARCHAR(500) NOT NULL,
 evidencia VARCHAR(255) DEFAULT NULL,
 registrado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY ix_oc_fecha (orden_id,registrado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
