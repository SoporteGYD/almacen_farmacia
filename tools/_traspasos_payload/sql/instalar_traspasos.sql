-- Instalación aditiva, ejecutable únicamente en la base de pruebas. Sin USE.
CREATE TABLE IF NOT EXISTS erp_traspasos (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 folio VARCHAR(30) NOT NULL UNIQUE,
 origen_id INT NOT NULL,
 destino_id INT NOT NULL,
 origen_tipo ENUM('FARMACIA','ALMACEN','UNICO') NOT NULL,
 destino_tipo ENUM('FARMACIA','ALMACEN','UNICO') NOT NULL,
 modalidad ENUM('SOLICITUD','DIRECTO') NOT NULL,
 estado ENUM('SOLICITADO','POR_PREPARAR','PREPARADO','EN_TRANSITO','PARCIAL','INCIDENCIA','COMPLETADO','CANCELADO') NOT NULL,
 observaciones VARCHAR(500) DEFAULT NULL,
 solicitado_por INT NOT NULL,
 preparado_por INT DEFAULT NULL,
 despachado_por INT DEFAULT NULL,
 recibido_por INT DEFAULT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 preparado_en DATETIME DEFAULT NULL,
 despachado_en DATETIME DEFAULT NULL,
 completado_en DATETIME DEFAULT NULL,
 actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY ix_traspasos_origen (origen_id,estado,creado_en),
 KEY ix_traspasos_destino (destino_id,estado,creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS erp_traspasos_detalle (
 traspaso_id BIGINT UNSIGNED NOT NULL,
 producto_id INT NOT NULL,
 solicitado INT NOT NULL,
 ubicacion_origen_id INT DEFAULT NULL,
 enviado INT NOT NULL DEFAULT 0,
 recibido INT NOT NULL DEFAULT 0,
 PRIMARY KEY (traspaso_id,producto_id),
 CONSTRAINT fk_traspaso_det_cab FOREIGN KEY (traspaso_id) REFERENCES erp_traspasos(id),
 KEY ix_traspaso_det_producto (producto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS erp_traspasos_recepciones (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 traspaso_id BIGINT UNSIGNED NOT NULL,
 token CHAR(32) NOT NULL UNIQUE,
 usuario_id INT NOT NULL,
 observaciones VARCHAR(500) DEFAULT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY ix_traspaso_rec_cab (traspaso_id),
 CONSTRAINT fk_traspaso_rec_cab FOREIGN KEY (traspaso_id) REFERENCES erp_traspasos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS erp_traspasos_recepciones_detalle (
 recepcion_id BIGINT UNSIGNED NOT NULL,
 producto_id INT NOT NULL,
 cantidad INT NOT NULL,
 PRIMARY KEY (recepcion_id,producto_id),
 CONSTRAINT fk_traspaso_rec_det FOREIGN KEY (recepcion_id) REFERENCES erp_traspasos_recepciones(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS erp_traspasos_eventos (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 traspaso_id BIGINT UNSIGNED NOT NULL,
 usuario_id INT NOT NULL,
 accion VARCHAR(40) NOT NULL,
 detalle VARCHAR(1000) DEFAULT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY ix_traspaso_evt_cab (traspaso_id,creado_en),
 CONSTRAINT fk_traspaso_evt_cab FOREIGN KEY (traspaso_id) REFERENCES erp_traspasos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
