-- Respaldar la base prueba primero. No modificar inventarios ni ventas.
CREATE TABLE IF NOT EXISTS erp_oc_autorizaciones (
 orden_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 estado ENUM('PENDIENTE','AUTORIZADA','RECHAZADA') NOT NULL,
 solicitado_por INT NOT NULL,
 solicitado_en DATETIME NOT NULL,
 decidido_por INT NULL,
 decidido_en DATETIME NULL,
 motivo_rechazo VARCHAR(500) NULL,
 CONSTRAINT fk_oc_auth_orden FOREIGN KEY(orden_id) REFERENCES erp_ordenes_compra(id),
 INDEX idx_oc_auth_estado(estado,solicitado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS erp_oc_autorizaciones_historial (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 orden_id BIGINT UNSIGNED NOT NULL,
 usuario_id INT NOT NULL,
 accion ENUM('SOLICITADA','AUTORIZADA','RECHAZADA') NOT NULL,
 observaciones VARCHAR(500) NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_oc_hist_orden(orden_id,creado_en),
 CONSTRAINT fk_oc_hist_orden FOREIGN KEY(orden_id) REFERENCES erp_ordenes_compra(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
