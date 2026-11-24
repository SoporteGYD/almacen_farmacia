-- Ejecutar solo en la base prueba, despues de respaldarla.
-- La tabla es un complemento documental del cierre POS; no modifica ventas ni existencias.
CREATE TABLE IF NOT EXISTS pos_cortes_denominaciones (
    turno_id BIGINT UNSIGNED NOT NULL,
    usuario_id INT NOT NULL,
    desglose_json LONGTEXT NOT NULL,
    total_contado DECIMAL(14,2) NOT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (turno_id),
    KEY idx_cortes_denominaciones_creado (creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
