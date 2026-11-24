-- RESPALDAR primero la base prueba. Ejecutar una sola vez.
-- Amplía el ENUM de usuarios, sin eliminar roles anteriores.
ALTER TABLE usuarios MODIFY COLUMN rol ENUM('ADMINISTRADOR','ENCARGADO','CONSULTA','GERENTE','JEFE_ALMACEN','CAJERO','COMPRAS','DIRECCION') NOT NULL DEFAULT 'CONSULTA';
CREATE TABLE IF NOT EXISTS erp_correos_usuario (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 usuario_id INT NOT NULL UNIQUE,
 proveedor ENUM('GMAIL','ONECOM') NOT NULL,
 correo VARCHAR(254) NOT NULL,
 smtp_host VARCHAR(200) NOT NULL,
 smtp_port INT NOT NULL DEFAULT 587,
 secreto_cifrado TEXT NOT NULL,
 actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_erp_correo_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- El registro de envíos previo se mantiene; no ejecutar otra vez su SQL.

CREATE TABLE IF NOT EXISTS erp_oc_envios_usuario (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 orden_id BIGINT UNSIGNED NOT NULL,
 folio VARCHAR(40) NOT NULL,
 usuario_id INT NOT NULL,
 remitente VARCHAR(254) NOT NULL,
 destinatario VARCHAR(254) NOT NULL,
 estado ENUM('EN_PROCESO','ENVIADO','ERROR') NOT NULL DEFAULT 'EN_PROCESO',
 detalle_error VARCHAR(500) NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 enviado_en DATETIME NULL,
 INDEX idx_envios_orden (orden_id,creado_en),
 INDEX idx_envios_usuario (usuario_id,creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
