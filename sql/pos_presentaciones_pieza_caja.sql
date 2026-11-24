-- Ejecutar una sola vez en base prueba (hacer respaldo antes).
CREATE TABLE IF NOT EXISTS pos_presentaciones (
 sucursal_id INT NOT NULL,
 producto_id INT NOT NULL,
 modalidad_presentacion ENUM('PIEZA','CAJA','AMBAS') NOT NULL DEFAULT 'PIEZA',
 piezas_por_caja INT NOT NULL DEFAULT 1,
 precio_caja DECIMAL(12,2) NULL, -- Legado: no se utiliza para calcular el precio de caja.
 actualizado_por INT NULL,
 actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(sucursal_id,producto_id),
 CONSTRAINT fk_pos_presentacion_sucursal FOREIGN KEY(sucursal_id) REFERENCES pos_sucursales(id),
 CONSTRAINT fk_pos_presentacion_producto FOREIGN KEY(producto_id) REFERENCES productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE pos_venta_detalle
 ADD COLUMN presentacion VARCHAR(8) NOT NULL DEFAULT 'PIEZA',
 ADD COLUMN piezas_por_presentacion INT NOT NULL DEFAULT 1,
 ADD COLUMN unidades_descontadas INT NULL;
-- Para productos vendidos antes de esta migración: su cantidad histórica se expresa en piezas.
UPDATE pos_venta_detalle SET unidades_descontadas=cantidad WHERE unidades_descontadas IS NULL;
