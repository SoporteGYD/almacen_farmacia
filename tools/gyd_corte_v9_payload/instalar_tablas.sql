CREATE TABLE IF NOT EXISTS gyd_cortes_intentos (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 solicitante_id INT NOT NULL,
 usuario_intentado VARCHAR(120) NOT NULL,
 exitoso TINYINT(1) NOT NULL,
 ip_hash CHAR(64) NOT NULL,
 creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_cortes_intentos (solicitante_id, exitoso, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS gyd_cortes_autorizaciones (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 turno_id INT NOT NULL,
 cajero_id INT NOT NULL,
 autorizador_id INT NOT NULL,
 autorizador_rol VARCHAR(40) NOT NULL,
 modalidad VARCHAR(30) NOT NULL,
 resultado VARCHAR(10) NOT NULL,
 importe_diferencia DECIMAL(14,2) NOT NULL,
 creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_cortes_turno (turno_id),
 INDEX idx_cortes_autorizador (autorizador_id, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
