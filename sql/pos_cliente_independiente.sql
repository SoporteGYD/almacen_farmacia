-- Ejecutar una sola vez en la base `prueba` después de hacer respaldo.
ALTER TABLE `pos_ventas` ADD COLUMN `cliente_nombre` VARCHAR(120) NULL DEFAULT NULL AFTER `quien_lleva`;
-- Las ventas anteriores permanecen intactas: cliente_nombre será NULL.
