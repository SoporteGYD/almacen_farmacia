-- Ejecutar UNA SOLA VEZ en la base prueba después de respaldarla.
-- Conservamos el ENUM metodo='TARJETA' para no romper reportes ni cierres.
ALTER TABLE pos_pagos ADD COLUMN tipo_tarjeta ENUM('DEBITO','CREDITO') NULL AFTER referencia;
