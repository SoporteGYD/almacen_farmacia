-- ERP G&D: bitacora de anulacion de recepcion en base PRUEBA.
-- Solo crea una tabla de auditoria. NO altera recepciones ni existencias.
-- Ejecutar tras respaldo de la base prueba. Requiere erp_oc_conciliacion_eventos
-- del paquete anterior de conciliacion de OC8.
CREATE TABLE IF NOT EXISTS erp_oc_recepciones_anulaciones (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    recepcion_id BIGINT UNSIGNED NOT NULL,
    orden_id BIGINT UNSIGNED NOT NULL,
    producto_id INT NOT NULL,
    cantidad_piezas INT UNSIGNED NOT NULL,
    estado_anterior VARCHAR(25) NOT NULL,
    estado_nuevo VARCHAR(25) NOT NULL,
    motivo VARCHAR(255) NOT NULL,
    usuario_id INT NOT NULL,
    movimiento_ajuste_id INT NOT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_recepcion_anulada (recepcion_id),
    UNIQUE KEY uq_anulacion_movimiento (movimiento_ajuste_id),
    KEY idx_orden_id (orden_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
