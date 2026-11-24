-- G&D: snapshot de costos para VENTAS NUEVAS, desde la instalación.
-- Ejecutar SOLO en la base de pruebas, después del respaldo.
-- No se rellenan costos históricos ni se modifica el inventario.
-- Antes de usar, confirmar que costo_promedio/costo_ultimo/precio_compra están expresados por PIEZA del POS.
-- Si no es así, no uses el margen mostrado hasta adaptar esta regla.
CREATE TABLE IF NOT EXISTS erp_pos_costos_venta (
    detalle_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    venta_id BIGINT UNSIGNED NOT NULL,
    producto_id INT NOT NULL,
    unidades INT NOT NULL,
    costo_unitario DECIMAL(14,4) NOT NULL,
    costo_total DECIMAL(18,4) NOT NULL,
    origen_costo ENUM('PROMEDIO','ULTIMO','COMPRA') NOT NULL,
    capturado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_costo_venta (venta_id),
    KEY idx_costo_producto (producto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Requiere las columnas unidades_descontadas y piezas_por_presentacion,
-- instaladas en el POS G&D con venta por pieza/caja.
DROP TRIGGER IF EXISTS erp_pos_guardar_costo_venta;
DELIMITER $$
CREATE TRIGGER erp_pos_guardar_costo_venta AFTER INSERT ON pos_venta_detalle
FOR EACH ROW
BEGIN
    DECLARE v_costo DECIMAL(14,4) DEFAULT NULL;
    DECLARE v_fuente VARCHAR(15) DEFAULT NULL;
    DECLARE v_piezas INT DEFAULT 0;
    SELECT CASE WHEN p.costo_promedio>0 THEN p.costo_promedio
                WHEN p.costo_ultimo>0 THEN p.costo_ultimo
                WHEN p.precio_compra>0 THEN p.precio_compra ELSE NULL END,
           CASE WHEN p.costo_promedio>0 THEN 'PROMEDIO'
                WHEN p.costo_ultimo>0 THEN 'ULTIMO'
                WHEN p.precio_compra>0 THEN 'COMPRA' ELSE NULL END
      INTO v_costo,v_fuente FROM productos p WHERE p.id=NEW.producto_id LIMIT 1;
    SET v_piezas=COALESCE(NEW.unidades_descontadas, NEW.cantidad * COALESCE(NULLIF(NEW.piezas_por_presentacion,0),1));
    IF v_costo IS NOT NULL AND v_piezas>0 THEN
        INSERT INTO erp_pos_costos_venta(detalle_id,venta_id,producto_id,unidades,costo_unitario,costo_total,origen_costo)
        VALUES(NEW.id,NEW.venta_id,NEW.producto_id,v_piezas,v_costo,ROUND(v_piezas*v_costo,4),v_fuente);
    END IF;
END$$
DELIMITER ;
