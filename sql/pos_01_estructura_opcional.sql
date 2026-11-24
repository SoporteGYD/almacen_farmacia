-- DISTRIBUCIÓN G&D | Estructura inicial propuesta para Punto de Venta
-- MYSQL 8.4+ | NO EJECUTAR EN PRODUCCIÓN ANTES DE PROBAR EN UNA COPIA.
-- Este archivo NO borra ni edita tablas existentes. Solo crea tablas nuevas.
-- No contiene datos sensibles ni intenta sincronizar WinCaja.
USE `almacen_farmacia`;

-- Las sucursales de farmacia son diferentes de las bodegas registradas en `almacenes`.
CREATE TABLE IF NOT EXISTS `pos_sucursales` (
 `id` INT NOT NULL AUTO_INCREMENT,
 `nombre` VARCHAR(150) NOT NULL,
 `activo` TINYINT(1) NOT NULL DEFAULT 1,
 `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (`id`), UNIQUE KEY `uq_pos_sucursal_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pos_cajas` (
 `id` INT NOT NULL AUTO_INCREMENT,
 `sucursal_id` INT NOT NULL,
 `codigo` VARCHAR(30) NOT NULL,
 `nombre` VARCHAR(100) NOT NULL,
 `activa` TINYINT(1) NOT NULL DEFAULT 1,
 PRIMARY KEY (`id`), UNIQUE KEY `uq_pos_caja_codigo` (`sucursal_id`,`codigo`),
 CONSTRAINT `fk_pos_caja_sucursal` FOREIGN KEY (`sucursal_id`) REFERENCES `pos_sucursales` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Precios públicos por farmacia, independientes de los costos de almacén.
-- No se insertan valores porque todos los precio_venta del respaldo son 0.00.
CREATE TABLE IF NOT EXISTS `pos_precios` (
 `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 `sucursal_id` INT NOT NULL,
 `producto_id` INT NOT NULL,
 `precio_publico` DECIMAL(14,2) NOT NULL,
 `vigente_desde` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 `actualizado_por` INT DEFAULT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uq_pos_precio_sucursal_producto` (`sucursal_id`,`producto_id`),
 CONSTRAINT `fk_pos_precio_sucursal` FOREIGN KEY (`sucursal_id`) REFERENCES `pos_sucursales` (`id`),
 CONSTRAINT `fk_pos_precio_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`),
 CONSTRAINT `fk_pos_precio_usuario` FOREIGN KEY (`actualizado_por`) REFERENCES `usuarios` (`id`),
 CONSTRAINT `chk_pos_precio_no_negativo` CHECK (`precio_publico`>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pos_precios_historial` (
 `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 `sucursal_id` INT NOT NULL,
 `producto_id` INT NOT NULL,
 `precio_anterior` DECIMAL(14,2) DEFAULT NULL,
 `precio_nuevo` DECIMAL(14,2) NOT NULL,
 `usuario_id` INT NOT NULL,
 `motivo` VARCHAR(250) NOT NULL,
 `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (`id`),KEY `ix_pos_hist_precio_producto` (`sucursal_id`,`producto_id`,`creado_en`),
 CONSTRAINT `fk_pos_hist_precio_sucursal` FOREIGN KEY (`sucursal_id`) REFERENCES `pos_sucursales` (`id`),
 CONSTRAINT `fk_pos_hist_precio_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`),
 CONSTRAINT `fk_pos_hist_precio_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pos_turnos` (
 `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 `caja_id` INT NOT NULL,
 `usuario_apertura_id` INT NOT NULL,
 `usuario_cierre_id` INT DEFAULT NULL,
 `abierto_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 `cerrado_en` DATETIME DEFAULT NULL,
 `fondo_inicial` DECIMAL(14,2) NOT NULL DEFAULT 0,
 `efectivo_contado` DECIMAL(14,2) DEFAULT NULL,
 `diferencia_efectivo` DECIMAL(14,2) DEFAULT NULL,
 `estado` ENUM('ABIERTO','CERRADO') NOT NULL DEFAULT 'ABIERTO',
 `observaciones` VARCHAR(500) DEFAULT NULL,
 PRIMARY KEY (`id`),KEY `ix_pos_turno_caja_estado` (`caja_id`,`estado`),
 CONSTRAINT `fk_pos_turno_caja` FOREIGN KEY (`caja_id`) REFERENCES `pos_cajas` (`id`),
 CONSTRAINT `fk_pos_turno_apertura` FOREIGN KEY (`usuario_apertura_id`) REFERENCES `usuarios` (`id`),
 CONSTRAINT `fk_pos_turno_cierre` FOREIGN KEY (`usuario_cierre_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pos_ventas` (
 `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 `folio` VARCHAR(48) NOT NULL,
 `sucursal_id` INT NOT NULL,
 `caja_id` INT NOT NULL,
 `turno_id` BIGINT UNSIGNED NOT NULL,
 `usuario_id` INT NOT NULL,
 `estado` ENUM('BORRADOR','PENDIENTE_PAGO','PAGADA','CANCELADA') NOT NULL DEFAULT 'BORRADOR',
 `subtotal` DECIMAL(14,2) NOT NULL DEFAULT 0,
 `descuento` DECIMAL(14,2) NOT NULL DEFAULT 0,
 `impuestos` DECIMAL(14,2) NOT NULL DEFAULT 0,
 `total` DECIMAL(14,2) NOT NULL DEFAULT 0,
 `origen_externo` VARCHAR(50) DEFAULT NULL,
 `folio_externo` VARCHAR(100) DEFAULT NULL,
 `idempotency_key` CHAR(36) DEFAULT NULL,
 `creada_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 `pagada_en` DATETIME DEFAULT NULL,
 `cancelada_en` DATETIME DEFAULT NULL,
 `motivo_cancelacion` VARCHAR(500) DEFAULT NULL,
 PRIMARY KEY (`id`),UNIQUE KEY `uq_pos_venta_folio` (`folio`),
 UNIQUE KEY `uq_pos_idempotency_key` (`idempotency_key`),
 KEY `ix_pos_ventas_sucursal_fecha` (`sucursal_id`,`creada_en`),
 KEY `ix_pos_ventas_turno` (`turno_id`,`estado`),
 CONSTRAINT `fk_pos_venta_sucursal` FOREIGN KEY (`sucursal_id`) REFERENCES `pos_sucursales` (`id`),
 CONSTRAINT `fk_pos_venta_caja` FOREIGN KEY (`caja_id`) REFERENCES `pos_cajas` (`id`),
 CONSTRAINT `fk_pos_venta_turno` FOREIGN KEY (`turno_id`) REFERENCES `pos_turnos` (`id`),
 CONSTRAINT `fk_pos_venta_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`),
 CONSTRAINT `chk_pos_venta_importes` CHECK (`subtotal`>=0 AND `descuento`>=0 AND `impuestos`>=0 AND `total`>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pos_venta_detalle` (
 `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 `venta_id` BIGINT UNSIGNED NOT NULL,
 `producto_id` INT NOT NULL,
 `codigo_snapshot` VARCHAR(100) NOT NULL,
 `descripcion_snapshot` VARCHAR(255) NOT NULL,
 `cantidad` INT NOT NULL,
 `precio_unitario` DECIMAL(14,2) NOT NULL,
 `descuento` DECIMAL(14,2) NOT NULL DEFAULT 0,
 `impuestos` DECIMAL(14,2) NOT NULL DEFAULT 0,
 `total_renglon` DECIMAL(14,2) NOT NULL,
 PRIMARY KEY (`id`),KEY `ix_pos_detalle_venta` (`venta_id`),
 KEY `ix_pos_detalle_producto` (`producto_id`),
 CONSTRAINT `fk_pos_detalle_venta` FOREIGN KEY (`venta_id`) REFERENCES `pos_ventas` (`id`),
 CONSTRAINT `fk_pos_detalle_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`),
 CONSTRAINT `chk_pos_detalle_importes` CHECK (`cantidad`>0 AND `precio_unitario`>=0 AND `total_renglon`>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pos_pagos` (
 `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 `venta_id` BIGINT UNSIGNED NOT NULL,
 `metodo` ENUM('EFECTIVO','TARJETA','TRANSFERENCIA') NOT NULL,
 `monto` DECIMAL(14,2) NOT NULL,
 `referencia` VARCHAR(120) DEFAULT NULL,
 `estado` ENUM('PENDIENTE','CONFIRMADO','RECHAZADO') NOT NULL DEFAULT 'PENDIENTE',
 `confirmado_en` DATETIME DEFAULT NULL,
 `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (`id`),KEY `ix_pos_pago_venta` (`venta_id`),
 CONSTRAINT `fk_pos_pago_venta` FOREIGN KEY (`venta_id`) REFERENCES `pos_ventas` (`id`),
 CONSTRAINT `chk_pos_pago_monto` CHECK (`monto`>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pos_movimientos_caja` (
 `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 `turno_id` BIGINT UNSIGNED NOT NULL,
 `usuario_id` INT NOT NULL,
 `tipo` ENUM('INGRESO','RETIRO','AJUSTE') NOT NULL,
 `monto` DECIMAL(14,2) NOT NULL,
 `motivo` VARCHAR(255) NOT NULL,
 `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (`id`),KEY `ix_pos_movcaja_turno` (`turno_id`,`creado_en`),
 CONSTRAINT `fk_pos_movcaja_turno` FOREIGN KEY (`turno_id`) REFERENCES `pos_turnos` (`id`),
 CONSTRAINT `fk_pos_movcaja_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`),
 CONSTRAINT `chk_pos_movcaja_monto` CHECK (`monto`>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inventario de FARMACIA (vendible); no debe confundirse con las existencias de bodega.
-- La carga inicial de este inventario requiere conciliación con WinCaja y NO se incluye aquí.
CREATE TABLE IF NOT EXISTS `pos_existencias_farmacia` (
 `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 `sucursal_id` INT NOT NULL,
 `producto_id` INT NOT NULL,
 `existencia` INT NOT NULL DEFAULT 0,
 `actualizada_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (`id`), UNIQUE KEY `uq_pos_stock_farmacia` (`sucursal_id`,`producto_id`),
 CONSTRAINT `fk_pos_stock_sucursal` FOREIGN KEY (`sucursal_id`) REFERENCES `pos_sucursales` (`id`),
 CONSTRAINT `fk_pos_stock_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`),
 CONSTRAINT `chk_pos_stock_no_negativo` CHECK (`existencia`>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pos_auditoria` (
 `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 `usuario_id` INT DEFAULT NULL,
 `venta_id` BIGINT UNSIGNED DEFAULT NULL,
 `accion` VARCHAR(90) NOT NULL,
 `detalle` JSON DEFAULT NULL,
 `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (`id`),KEY `ix_pos_auditoria_fecha` (`creado_en`),
 CONSTRAINT `fk_pos_auditoria_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`),
 CONSTRAINT `fk_pos_auditoria_venta` FOREIGN KEY (`venta_id`) REFERENCES `pos_ventas` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- FIN: ninguna de estas tablas se utiliza en la prueba de impresión.
