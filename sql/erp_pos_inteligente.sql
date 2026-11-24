-- ERP G&D: POS GENERAL + STOCK FISICO FARMACIA. SOLO EN BASE prueba con respaldo previo.
-- No convierte ni importa automáticamente saldos del POS general a Farmacia física.
CREATE TABLE IF NOT EXISTS erp_pos_farmacia_control (
 sucursal_id INT NOT NULL, producto_id INT NOT NULL,
 existencia INT NOT NULL DEFAULT 0, minimo INT NOT NULL DEFAULT 24,
 actualizado_por INT NULL, actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(sucursal_id,producto_id),
 KEY idx_stock_usu (actualizado_por),
 FOREIGN KEY (sucursal_id) REFERENCES pos_sucursales(id),
 FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS erp_pos_alertas (
 sucursal_id INT NOT NULL, producto_id INT NOT NULL,
 estado ENUM('ABIERTA','RESUELTA') NOT NULL DEFAULT 'ABIERTA',
 existencia INT NOT NULL, minimo INT NOT NULL DEFAULT 24,
 actualizada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(sucursal_id,producto_id), KEY idx_estado(estado),
 FOREIGN KEY (sucursal_id) REFERENCES pos_sucursales(id),
 FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS erp_pos_tickets_almacen (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 clave CHAR(36) NOT NULL UNIQUE,
 resurtido_id INT NOT NULL UNIQUE,
 sucursal_id INT NOT NULL,
 caja_id INT NOT NULL,
 usuario_id INT NOT NULL,
 producto_id INT NOT NULL,
 cantidad INT NOT NULL,
 venta_id BIGINT UNSIGNED DEFAULT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_pendientes(sucursal_id,producto_id,venta_id),
 FOREIGN KEY (resurtido_id) REFERENCES resurtidos(id),
 FOREIGN KEY (sucursal_id) REFERENCES pos_sucursales(id),
 FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Migración manual de datos históricos: no ajustar saldos ya cobrados automáticamente.
CREATE TABLE IF NOT EXISTS erp_pos_conteos_historial (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 sucursal_id INT NOT NULL,producto_id INT NOT NULL,
 anterior INT DEFAULT NULL, nueva INT NOT NULL, minimo_anterior INT DEFAULT NULL,minimo_nuevo INT NOT NULL,
 usuario_id INT NOT NULL,motivo VARCHAR(250) NOT NULL,fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
 KEY idx_conteos(sucursal_id,producto_id,fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Un ticket de venta ya cobrado no puede devolverse al almacén mediante cancelación directa.
DROP TRIGGER IF EXISTS erp_pos_ticket_no_cancelar_vendido;
DELIMITER $$
CREATE TRIGGER erp_pos_ticket_no_cancelar_vendido BEFORE UPDATE ON resurtidos
FOR EACH ROW
BEGIN
 IF NEW.estado='CANCELADO' AND OLD.estado<>'CANCELADO'
    AND EXISTS(SELECT 1 FROM erp_pos_tickets_almacen WHERE resurtido_id=OLD.id AND venta_id IS NOT NULL) THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ticket de POS ya cobrado: requiere devolución/reversa auditada, no cancelación simple.';
 END IF;
END$$
DELIMITER ;
