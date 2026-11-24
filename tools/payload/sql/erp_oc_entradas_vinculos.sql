-- Laboratorio G&D: enlace transaccional de Entrada clásica contra recepción OC.
-- No inserta movimientos ni modifica existencias anteriores.
CREATE TABLE IF NOT EXISTS erp_oc_entradas_vinculos (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 orden_id BIGINT UNSIGNED NOT NULL,
 recepcion_id BIGINT UNSIGNED NOT NULL,
 recepcion_detalle_id BIGINT UNSIGNED NOT NULL,
 producto_id INT NOT NULL,
 movimiento_id BIGINT UNSIGNED NOT NULL,
 almacen_id INT NOT NULL,
 cantidad INT UNSIGNED NOT NULL,
 usuario_id INT NOT NULL,
 solicitud_token CHAR(32) NOT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_entrada_movimiento (movimiento_id),
 UNIQUE KEY uq_solicitud_token (solicitud_token),
 KEY ix_recepcion_detalle (recepcion_detalle_id),
 KEY ix_orden_producto (orden_id,producto_id,almacen_id),
 KEY ix_recepcion (recepcion_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
