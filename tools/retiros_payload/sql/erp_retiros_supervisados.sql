-- SOLO PARA LA BASE prueba. Los movimientos aprobados se insertan en pos_movimientos_caja.
CREATE TABLE IF NOT EXISTS erp_retiros_supervisados (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 turno_id BIGINT UNSIGNED NOT NULL,
 caja_id BIGINT UNSIGNED NOT NULL,
 cajero_id INT NOT NULL,
 contado_declarado DECIMAL(14,2) NOT NULL,
 esperado_solicitud DECIMAL(14,2) NOT NULL,
 importe DECIMAL(14,2) NOT NULL,
 motivo VARCHAR(255) NOT NULL DEFAULT 'Retiro de efectivo por límite de caja',
 estado ENUM('SOLICITADO','CONFIRMADO','RECHAZADO') NOT NULL DEFAULT 'SOLICITADO',
 revisor_id INT DEFAULT NULL,
 movimiento_id BIGINT UNSIGNED DEFAULT NULL,
 solicitud_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 resolucion_en DATETIME DEFAULT NULL,
 referencia VARCHAR(120) DEFAULT NULL,
 nota_revisor VARCHAR(255) DEFAULT NULL,
 PRIMARY KEY(id),
 UNIQUE KEY uq_erp_retiro_movimiento(movimiento_id),
 KEY idx_erp_retiros_turno(turno_id,estado),
 KEY idx_erp_retiros_estado(estado,solicitud_en),
 KEY idx_erp_retiros_confirmado(resolucion_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
