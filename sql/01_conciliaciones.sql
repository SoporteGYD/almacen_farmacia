-- SOLO tras respaldo. Tabla de auditoría ADICIONAL. No altera inventario ni facturas al importarla.
CREATE TABLE IF NOT EXISTS erp_oc_conciliacion_eventos (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 aplicacion_id BIGINT UNSIGNED NOT NULL,
 orden_id BIGINT UNSIGNED NOT NULL,
 factura_id BIGINT UNSIGNED NOT NULL,
 producto_id INT NOT NULL,
 almacen_id INT NOT NULL,
 ubicacion VARCHAR(100) NOT NULL,
 tipo ENUM('AJUSTE_FALTANTE','REPOSICION') NOT NULL,
 cantidad INT UNSIGNED NOT NULL,
 movimiento_id INT NOT NULL,
 stock_antes INT NOT NULL,
 stock_despues INT NOT NULL,
 evidencia VARCHAR(255) NOT NULL,
 operado_por VARCHAR(120) NOT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_ajuste_por_aplicacion_tipo (aplicacion_id,tipo),
 UNIQUE KEY uq_evento_movimiento (movimiento_id),
 KEY idx_orden (orden_id), KEY idx_factura (factura_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
