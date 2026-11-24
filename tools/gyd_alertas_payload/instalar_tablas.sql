-- Farmacia G&D: solo estado de atención de alertas; NO crea ni modifica movimientos contables.
CREATE TABLE IF NOT EXISTS gyd_alertas_gerenciales_atencion (
 clave VARCHAR(90) NOT NULL,
 atendido_por INT NOT NULL,
 atendido_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (clave),
 INDEX idx_gyd_alertas_atendido (atendido_en,atendido_por)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
