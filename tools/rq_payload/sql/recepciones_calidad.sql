-- G&D: incidencias por entrega física de OC. No actualiza inventarios ni facturas.
-- Una recepción existente contabiliza ÚNICAMENTE piezas aceptadas en buen estado.
CREATE TABLE IF NOT EXISTS erp_oc_recepcion_incidencias (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 recepcion_id BIGINT UNSIGNED NOT NULL,
 orden_detalle_id BIGINT UNSIGNED DEFAULT NULL,
 producto_id INT DEFAULT NULL,
 tipo ENUM('FALTANTE','DANADA','CADUCADA','EQUIVOCADA','SOBRANTE') NOT NULL,
 cantidad_piezas INT UNSIGNED NOT NULL,
 codigo_reportado VARCHAR(150) DEFAULT NULL,
 descripcion_reportada VARCHAR(255) DEFAULT NULL,
 observaciones VARCHAR(500) NOT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_oc_calidad_recepcion (recepcion_id),
 KEY idx_oc_calidad_partida (orden_detalle_id,tipo),
 CONSTRAINT fk_oc_calidad_rec FOREIGN KEY (recepcion_id) REFERENCES erp_oc_recepciones(id),
 CONSTRAINT fk_oc_calidad_det FOREIGN KEY (orden_detalle_id) REFERENCES erp_ordenes_compra_detalle(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
