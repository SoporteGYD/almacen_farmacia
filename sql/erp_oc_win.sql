-- ERP G&D: Orden de compra con presentación estilo WinCaja.
-- Requiere las tablas ya instaladas: erp_ordenes_compra, erp_ordenes_compra_detalle,
-- erp_oc_autorizaciones, erp_oc_autorizaciones_historial, usuarios y proveedores.
-- Respaldar la base antes de aplicar. No toca inventarios ni facturación.
CREATE TABLE IF NOT EXISTS erp_oc_win_meta (
 orden_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 fecha_entrega DATE NOT NULL,
 direccion_proveedor VARCHAR(255) NOT NULL DEFAULT '',
 colonia_proveedor VARCHAR(120) NOT NULL DEFAULT '',
 ciudad_proveedor VARCHAR(100) NOT NULL DEFAULT '',
 cp_proveedor VARCHAR(10) NOT NULL DEFAULT '',
 telefono_proveedor VARCHAR(35) NOT NULL DEFAULT '',
 descuento_1 DECIMAL(5,2) NOT NULL DEFAULT 0,
 descuento_2 DECIMAL(5,2) NOT NULL DEFAULT 0,
 descuento_3 DECIMAL(5,2) NOT NULL DEFAULT 0,
 descuento_4 DECIMAL(5,2) NOT NULL DEFAULT 0,
 descuento_general DECIMAL(5,2) NOT NULL DEFAULT 0,
 subtotal_bruto DECIMAL(16,2) NOT NULL DEFAULT 0,
 descuento_total DECIMAL(16,2) NOT NULL DEFAULT 0,
 iva_total DECIMAL(16,2) NOT NULL DEFAULT 0,
 ieps_total DECIMAL(16,2) NOT NULL DEFAULT 0,
 fpgc_total DECIMAL(16,2) NOT NULL DEFAULT 0,
 total_neto DECIMAL(16,2) NOT NULL DEFAULT 0,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_oc_win_meta FOREIGN KEY (orden_id) REFERENCES erp_ordenes_compra(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_oc_win_lineas (
 detalle_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 unidad VARCHAR(20) NOT NULL DEFAULT 'PZA',
 tasa_iva DECIMAL(5,2) NOT NULL DEFAULT 0,
 tasa_ieps DECIMAL(5,2) NOT NULL DEFAULT 0,
 cantidad_cajas DECIMAL(12,2) NOT NULL DEFAULT 0,
 CONSTRAINT fk_oc_win_linea FOREIGN KEY (detalle_id) REFERENCES erp_ordenes_compra_detalle(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_oc_win_gerentes (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 destino VARCHAR(30) NOT NULL,
 usuario_id INT NOT NULL,
 activo TINYINT(1) NOT NULL DEFAULT 1,
 registrado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_oc_win_destino_gerente(destino,usuario_id),
 INDEX idx_oc_win_gerentes_activos(destino,activo),
 CONSTRAINT fk_oc_win_gerente FOREIGN KEY(usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_oc_win_envios (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 orden_id BIGINT UNSIGNED NOT NULL,
 remitente_id INT NOT NULL,
 gerente_id INT NOT NULL,
 destinatario VARCHAR(254) NOT NULL,
 estado ENUM('EN_PROCESO','ENVIADO','ERROR') NOT NULL DEFAULT 'EN_PROCESO',
 error_detalle VARCHAR(300) DEFAULT NULL,
 solicitado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 finalizado_en DATETIME DEFAULT NULL,
 INDEX idx_oc_win_envios(orden_id,solicitado_en),
 INDEX idx_oc_win_destinatario(gerente_id,solicitado_en),
 CONSTRAINT fk_oc_win_envio_orden FOREIGN KEY(orden_id) REFERENCES erp_ordenes_compra(id),
 CONSTRAINT fk_oc_win_envio_gerente FOREIGN KEY(gerente_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
