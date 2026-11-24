CREATE TABLE IF NOT EXISTS gyd_retiros_intentos_clave (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 usuario_id INT NOT NULL,
 correcto TINYINT(1) NOT NULL,
 ip_hash CHAR(64) NOT NULL,
 creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_retiro_clave (usuario_id, correcto, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
