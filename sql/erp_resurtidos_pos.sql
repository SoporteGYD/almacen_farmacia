-- G&D ERP | Resurtidos Almacén -> POS / RECEPCIÓN FÍSICA
-- Base de PRUEBA. Ejecutar después de respaldo.
-- No reimporta salidas antiguas: sólo movimientos creados a partir de esta migración.
-- La salida en almacén ya descontó producto_existencias. Aquí se acredita ÚNICAMENTE al POS.

CREATE TABLE IF NOT EXISTS erp_resurtidos_pos_config (
 id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
 habilitado_desde DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO erp_resurtidos_pos_config (id, habilitado_desde) VALUES (1, NOW());

CREATE TABLE IF NOT EXISTS erp_resurtidos_pos_recepciones (
 movimiento_id INT NOT NULL PRIMARY KEY,
 resurtido_id INT NOT NULL,
 almacen_id INT NOT NULL,
 pos_sucursal_id INT NOT NULL,
 estado ENUM('PENDIENTE','PARCIAL','COMPLETA') NOT NULL DEFAULT 'PENDIENTE',
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY idx_rpos_estado (estado),
 KEY idx_rpos_resurtido (resurtido_id),
 CONSTRAINT fk_rpos_rec_mov FOREIGN KEY (movimiento_id) REFERENCES movimientos(id),
 CONSTRAINT fk_rpos_rec_pos FOREIGN KEY (pos_sucursal_id) REFERENCES pos_sucursales(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_resurtidos_pos_detalle (
 movimiento_id INT NOT NULL,
 producto_id INT NOT NULL,
 cantidad_enviada INT NOT NULL,
 cantidad_recibida INT NOT NULL DEFAULT 0,
 PRIMARY KEY (movimiento_id, producto_id),
 CONSTRAINT fk_rpos_det_rec FOREIGN KEY (movimiento_id) REFERENCES erp_resurtidos_pos_recepciones(movimiento_id),
 CONSTRAINT fk_rpos_det_prod FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_resurtidos_pos_confirmaciones (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 movimiento_id INT NOT NULL,
 token CHAR(32) NOT NULL UNIQUE,
 usuario_id INT NOT NULL,
 observaciones VARCHAR(750) DEFAULT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_rpos_conf_mov (movimiento_id),
 CONSTRAINT fk_rpos_conf_rec FOREIGN KEY (movimiento_id) REFERENCES erp_resurtidos_pos_recepciones(movimiento_id),
 CONSTRAINT fk_rpos_conf_user FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_resurtidos_pos_confirmaciones_detalle (
 confirmacion_id BIGINT UNSIGNED NOT NULL,
 producto_id INT NOT NULL,
 cantidad INT NOT NULL,
 PRIMARY KEY (confirmacion_id, producto_id),
 CONSTRAINT fk_rpos_cdet_conf FOREIGN KEY (confirmacion_id) REFERENCES erp_resurtidos_pos_confirmaciones(id),
 CONSTRAINT fk_rpos_cdet_prod FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bloquea cancelar salidas ya recibidas por Farmacia: evita recuperar Almacén dejando POS acreditado.
-- Para corregir una transferencia completada se necesita una reversa formal (siguiente etapa).
DROP TRIGGER IF EXISTS erp_rpos_prevenir_cancelacion;
DELIMITER $$
CREATE TRIGGER erp_rpos_prevenir_cancelacion BEFORE UPDATE ON movimientos
FOR EACH ROW
BEGIN
 IF COALESCE(OLD.cancelado,0)=0 AND COALESCE(NEW.cancelado,0)=1
    AND EXISTS (
      SELECT 1 FROM erp_resurtidos_pos_confirmaciones c
      WHERE c.movimiento_id=OLD.id
    ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Resurtido recibido en POS: primero haga reversa administrativa de la transferencia.';
 END IF;
END$$
DELIMITER ;
