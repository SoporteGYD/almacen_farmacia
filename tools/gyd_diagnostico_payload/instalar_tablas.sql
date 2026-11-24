CREATE TABLE IF NOT EXISTS gyd_diagnosticos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  entorno VARCHAR(24) NOT NULL DEFAULT 'prueba',
  ok_count INT UNSIGNED NOT NULL DEFAULT 0,
  atencion_count INT UNSIGNED NOT NULL DEFAULT 0,
  problema_count INT UNSIGNED NOT NULL DEFAULT 0,
  no_disponible_count INT UNSIGNED NOT NULL DEFAULT 0,
  informe_json LONGTEXT NOT NULL,
  KEY idx_gyd_diag_fecha (creado_en),
  KEY idx_gyd_diag_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
