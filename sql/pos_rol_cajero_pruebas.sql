-- EJECUTAR ÚNICAMENTE en MySQL local (127.0.0.1:3307), BD almacen_farmacia.
-- CAMBIO DE ESTRUCTURA: agrega CAJERO a ENUM sin eliminar los roles actuales.
-- Antes: exportar respaldo de la BD local y verificar que NO es producción.
-- No crear usuario ni compartir contraseña por SQL; usar módulo Usuarios del sistema.
ALTER TABLE `usuarios`
  MODIFY COLUMN `rol` ENUM('ADMINISTRADOR','ENCARGADO','CONSULTA','GERENTE','JEFE_ALMACEN','CAJERO')
  COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'CONSULTA';
