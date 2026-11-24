-- Ejecutar solamente en la base de PRUEBAS y después de un respaldo.
-- No modifica existencias ni crea usuarios automáticamente.
CREATE TABLE IF NOT EXISTS pos_usuario_asignaciones (
 usuario_id INT(11) NOT NULL PRIMARY KEY,
 sucursal_id INT(11) NOT NULL,
 caja_id INT(11) NOT NULL,
 creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_posua_usuario FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
 CONSTRAINT fk_posua_sucursal FOREIGN KEY(sucursal_id) REFERENCES pos_sucursales(id),
 CONSTRAINT fk_posua_caja FOREIGN KEY(caja_id) REFERENCES pos_cajas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
