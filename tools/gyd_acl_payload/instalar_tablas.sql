-- ÚNICAMENTE en base de pruebas «prueba». Instalador crea estas tablas de manera aditiva.
CREATE TABLE IF NOT EXISTS gyd_acl_roles (
 rol VARCHAR(32) NOT NULL,
 modulo VARCHAR(60) NOT NULL,
 decision ENUM('ALLOW','DENY') NOT NULL,
 actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (rol, modulo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS gyd_acl_usuarios (
 usuario_id INT NOT NULL,
 modulo VARCHAR(60) NOT NULL,
 decision ENUM('ALLOW','DENY') NOT NULL,
 actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (usuario_id, modulo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS gyd_acl_bitacora (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 actor_id INT NOT NULL,
 tipo ENUM('rol','usuario') NOT NULL,
 objetivo VARCHAR(64) NOT NULL,
 antes_json LONGTEXT NOT NULL,
 despues_json LONGTEXT NOT NULL,
 direccion_ip VARCHAR(45) NOT NULL DEFAULT '',
 creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_acl_actor_fecha (actor_id, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
