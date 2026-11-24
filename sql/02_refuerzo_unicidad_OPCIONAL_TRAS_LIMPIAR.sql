-- EJECUTAR MANUALMENTE SOLO TRAS ANALIZAR 00_diagnostico_duplicados_SOLO_LECTURA.sql.
-- Si existen duplicados heredados, el índice fallará SIN borrar datos.
-- Protege igualdad por mayúsculas/espacios externos y puede impedir altas antiguas conflictivas.
-- Requiere MySQL/MariaDB con columnas generadas e índices sobre ellas.
ALTER TABLE productos
 ADD COLUMN erp_descripcion_unica VARCHAR(255) GENERATED ALWAYS AS (UPPER(TRIM(descripcion))) STORED,
 ADD UNIQUE INDEX uq_erp_descripcion_unica(erp_descripcion_unica);
-- códigos y código_barras ya cuentan con índices únicos en los respaldos revisados.
-- Cruces entre codigo/codigo_barras requieren validaciones compartidas de aplicación;
-- los importadores ajenos deben utilizar el verificador gyd_producto_duplicado.
