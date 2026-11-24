-- G&D V36. Sólo base PRUEBA puerto 3307: el instalador valida conexión.
-- DDL es idempotente. No modifica tablas de existencias, cobros ni catálogo.
CREATE TABLE IF NOT EXISTS gyd_clientes (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 codigo VARCHAR(24) NOT NULL UNIQUE,
 nombre VARCHAR(120) NOT NULL,
 telefono VARCHAR(24) NULL,
 correo VARCHAR(190) NULL,
 acepta_programa TINYINT(1) NOT NULL DEFAULT 0,
 consentimiento_en DATETIME NULL,
 activo TINYINT(1) NOT NULL DEFAULT 1,
 creado_por BIGINT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_gyd_clientes_nombre (nombre), INDEX idx_gyd_clientes_telefono (telefono)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS gyd_clientes_ventas (
 venta_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 cliente_id BIGINT UNSIGNED NOT NULL,
 sucursal_id INT NOT NULL,
 puntos_otorgados INT UNSIGNED NOT NULL DEFAULT 0,
 ingreso_elegible_centavos BIGINT UNSIGNED NOT NULL DEFAULT 0,
 utilidad_referencia_centavos BIGINT UNSIGNED NOT NULL DEFAULT 0,
 registrado_por BIGINT NULL,
 registrado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_gyd_cv_cliente (cliente_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS gyd_puntos_movimientos (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 cliente_id BIGINT UNSIGNED NOT NULL,
 venta_id BIGINT UNSIGNED NULL,
 origen_clave VARCHAR(80) NOT NULL UNIQUE,
 tipo ENUM('ACUMULACION','REVERSO','AJUSTE') NOT NULL,
 puntos INT NOT NULL,
 valor_reserva_centavos BIGINT NOT NULL DEFAULT 0,
 detalle VARCHAR(250) NOT NULL,
 creado_por BIGINT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_gyd_puntos_cliente_fecha (cliente_id,creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
