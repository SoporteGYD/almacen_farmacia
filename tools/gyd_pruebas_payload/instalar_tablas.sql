-- Solo guarda resultados de pruebas. No modifica tablas de operaciones.
CREATE TABLE IF NOT EXISTS gyd_pruebas_integrales_runs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 usuario_id INT NOT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 informe_json MEDIUMTEXT NOT NULL,
 correctas INT UNSIGNED NOT NULL DEFAULT 0,
 revision INT UNSIGNED NOT NULL DEFAULT 0,
 fallidas INT UNSIGNED NOT NULL DEFAULT 0,
 no_disponibles INT UNSIGNED NOT NULL DEFAULT 0,
 INDEX idx_gpi_runs_fecha (creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS gyd_pruebas_integrales_manual (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 usuario_id INT NOT NULL,
 escenario VARCHAR(60) NOT NULL,
 resultado ENUM('pendiente','correcto','fallido') NOT NULL DEFAULT 'pendiente',
 observacion VARCHAR(500) NOT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_gpi_manual_escenario (escenario,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
