-- Ejecutar SOLO una vez en MySQL local; nunca Ubuntu / ni la base almacen_farmacia.
USE `prueba`;
CREATE TABLE IF NOT EXISTS `pos_precios_mayoreo` (
 `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 `sucursal_id` INT NOT NULL,
 `producto_id` INT NOT NULL,
 `cantidad_minima` INT NOT NULL,
 `precio_mayoreo` DECIMAL(14,2) NOT NULL,
 `actualizado_por` INT DEFAULT NULL,
 `actualizado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (`id`),
 UNIQUE KEY `uq_pos_mayoreo_sucursal_producto` (`sucursal_id`,`producto_id`),
 CONSTRAINT `fk_pos_mayoreo_sucursal` FOREIGN KEY (`sucursal_id`) REFERENCES `pos_sucursales`(`id`),
 CONSTRAINT `fk_pos_mayoreo_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos`(`id`),
 CONSTRAINT `fk_pos_mayoreo_usuario` FOREIGN KEY (`actualizado_por`) REFERENCES `usuarios`(`id`),
 CONSTRAINT `chk_pos_mayoreo_minimo` CHECK (`cantidad_minima`>=2),
 CONSTRAINT `chk_pos_mayoreo_positivo` CHECK (`precio_mayoreo`>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- No altera existencias, ventas previas ni precios existentes.
