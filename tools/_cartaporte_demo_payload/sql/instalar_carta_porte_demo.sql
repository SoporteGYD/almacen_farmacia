-- Tablas exclusivas de pruebas fiscales. No se modifican inventarios ni traspasos.
CREATE TABLE IF NOT EXISTS erp_carta_porte_demo (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 traspaso_id BIGINT UNSIGNED NOT NULL,
 token CHAR(32) NOT NULL,
 folio_demo VARCHAR(42) NOT NULL,
 resultado ENUM('ACEPTADO_DEMO','RECHAZADO_DEMO','SIN_RESPUESTA_DEMO') NOT NULL,
 mensaje VARCHAR(300) NOT NULL,
 resumen_json LONGTEXT NOT NULL,
 huella_sha256 CHAR(64) NOT NULL,
 operador_id INT NOT NULL,
 fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 ambiente VARCHAR(12) NOT NULL DEFAULT 'DEMO',
 es_fiscal TINYINT(1) NOT NULL DEFAULT 0,
 UNIQUE KEY uk_cp_demo_token (token),
 UNIQUE KEY uk_cp_demo_folio (folio_demo),
 KEY ix_cp_demo_traspaso (traspaso_id, fecha_registro),
 CONSTRAINT fk_cp_demo_traspaso FOREIGN KEY (traspaso_id) REFERENCES erp_traspasos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
