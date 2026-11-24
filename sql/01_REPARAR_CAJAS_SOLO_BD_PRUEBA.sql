-- G&D | REPARAR CATALOGO DE SUCURSALES Y CAJAS POS
-- UNICAMENTE para MySQL local 127.0.0.1:3307, base prueba.
-- Seguro de volver a ejecutar: no borra ventas, productos ni movimientos.
-- No cambia stock ni precios del almacén.
USE `prueba`;

CREATE TABLE IF NOT EXISTS `pos_sucursales` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(150) NOT NULL,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pos_sucursal_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pos_cajas` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `sucursal_id` INT NOT NULL,
  `codigo` VARCHAR(30) NOT NULL,
  `nombre` VARCHAR(100) NOT NULL,
  `activa` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pos_caja_codigo` (`sucursal_id`, `codigo`),
  CONSTRAINT `fk_pos_caja_sucursal`
      FOREIGN KEY (`sucursal_id`) REFERENCES `pos_sucursales` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `pos_sucursales` (`nombre`,`activo`) VALUES
  ('CIUDAD HIDALGO',1),
  ('TUXTLA GUTIERREZ',1),
  ('TAPACHULA',1),
  ('PLAZA TOSCANA',1)
ON DUPLICATE KEY UPDATE `activo`=1;

INSERT INTO `pos_cajas` (`sucursal_id`,`codigo`,`nombre`,`activa`)
SELECT s.`id`, 'CAJA-01', CONCAT('Caja 01 - ',s.`nombre`), 1
FROM `pos_sucursales` s
WHERE s.`nombre` IN ('CIUDAD HIDALGO','TUXTLA GUTIERREZ','TAPACHULA','PLAZA TOSCANA')
ON DUPLICATE KEY UPDATE `activa`=1, `nombre`=VALUES(`nombre`);

-- Verificacion: deben verse 4 filas activas.
SELECT s.`id` AS `sucursal_id`, s.`nombre` AS `sucursal`,
       c.`id` AS `caja_id`, c.`codigo`, c.`nombre` AS `caja`,
       s.`activo` AS `sucursal_activa`, c.`activa` AS `caja_activa`
FROM `pos_sucursales` s
JOIN `pos_cajas` c ON c.`sucursal_id`=s.`id`
WHERE s.`activo`=1 AND c.`activa`=1
ORDER BY s.`nombre`,c.`codigo`;
