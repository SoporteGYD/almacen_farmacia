CREATE TABLE IF NOT EXISTS erp_pet_usuario_sucursal (
 usuario_id INT NOT NULL PRIMARY KEY,
 sucursal_id INT NOT NULL,
 actualizado_por INT NOT NULL,
 actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY ix_pet_usuario_sucursal (sucursal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS erp_pet_cab (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 folio VARCHAR(35) NOT NULL UNIQUE,
 origen_id INT NOT NULL,
 destino_id INT NOT NULL,
 destino_tipo ENUM('ALMACEN','FARMACIA','UNICO') NOT NULL,
 estado ENUM('PENDIENTE_COMPRAS','VALIDADA','RECHAZADA','EMITIDA','CANCELADA') NOT NULL DEFAULT 'PENDIENTE_COMPRAS',
 creado_por INT NOT NULL,
 validado_por INT DEFAULT NULL,
 emitido_por INT DEFAULT NULL,
 observaciones VARCHAR(600) DEFAULT NULL,
 respuesta_compras VARCHAR(600) DEFAULT NULL,
 creada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 validada_en DATETIME DEFAULT NULL,
 emitida_en DATETIME DEFAULT NULL,
 KEY ix_pet_estado_fecha (estado,creada_en),
 KEY ix_pet_destino_fecha (destino_id,creada_en),
 KEY ix_pet_origen_fecha (origen_id,creada_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS erp_pet_det (
 peticion_id BIGINT UNSIGNED NOT NULL,
 producto_id INT NOT NULL,
 origen_tipo ENUM('ALMACEN','FARMACIA','UNICO') NOT NULL,
 solicitado INT NOT NULL,
 aprobado INT DEFAULT NULL,
 razon VARCHAR(255) DEFAULT NULL,
 PRIMARY KEY (peticion_id,producto_id,origen_tipo),
 KEY ix_pet_det_producto (producto_id),
 CONSTRAINT fk_pet_det_pet FOREIGN KEY (peticion_id) REFERENCES erp_pet_cab(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS erp_pet_tr_vinculo (
 peticion_id BIGINT UNSIGNED NOT NULL,
 origen_tipo ENUM('ALMACEN','FARMACIA','UNICO') NOT NULL,
 traspaso_id BIGINT UNSIGNED NOT NULL UNIQUE,
 PRIMARY KEY (peticion_id,origen_tipo),
 CONSTRAINT fk_pet_vinc_cab FOREIGN KEY (peticion_id) REFERENCES erp_pet_cab(id),
 CONSTRAINT fk_pet_vinc_tras FOREIGN KEY (traspaso_id) REFERENCES erp_traspasos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS erp_pet_eventos (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 peticion_id BIGINT UNSIGNED NOT NULL,
 usuario_id INT NOT NULL,
 accion VARCHAR(40) NOT NULL,
 mensaje VARCHAR(800) DEFAULT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY ix_pet_eventos (peticion_id,creado_en),
 CONSTRAINT fk_pet_eventos_cab FOREIGN KEY (peticion_id) REFERENCES erp_pet_cab(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
