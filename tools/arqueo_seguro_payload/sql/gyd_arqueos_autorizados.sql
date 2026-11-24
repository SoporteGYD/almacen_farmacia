-- SOLO base de pruebas `prueba`. No altera movimientos de ventas, POS ni retiros.
CREATE TABLE IF NOT EXISTS gyd_arqueos_autorizaciones (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 arqueo_id BIGINT UNSIGNED NOT NULL,
 turno_id BIGINT UNSIGNED NOT NULL,
 caja_id BIGINT UNSIGNED NOT NULL,
 solicitante_id INT NOT NULL,
 autorizador_id INT NOT NULL,
 autorizador_rol VARCHAR(40) NOT NULL,
 desglose_json LONGTEXT NOT NULL,
 autorizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_gyd_arqueo_unico (arqueo_id),
 KEY idx_gyd_arqueo_turno (turno_id,autorizado_en),
 KEY idx_gyd_arqueo_supervisor (autorizador_id,autorizado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS gyd_arqueos_intentos (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 solicitante_id INT NOT NULL,
 usuario_intentado VARCHAR(120) NOT NULL,
 exitoso TINYINT(1) NOT NULL,
 ip_hash CHAR(64) NOT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_gyd_arqueo_intentos (solicitante_id,exitoso,creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
