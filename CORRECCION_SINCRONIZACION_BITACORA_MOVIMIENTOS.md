# Sincronización de Bitácora del sistema con Movimientos de inventario

## Problema detectado

La vista **Movimientos de inventario** consulta directamente `movimientos` y `movimiento_detalle`, mientras que la vista **Bitácora del sistema** consultaba únicamente `auditoria_movimientos`.

Esto provocaba dos situaciones:

1. Movimientos históricos válidos podían no aparecer en Bitácora si en su momento no se generó el evento de auditoría.
2. La búsqueda de Bitácora no revisaba el detalle de productos de un movimiento, por lo que buscar un código como `5803` encontraba búsquedas de producto, pero no necesariamente las entradas/salidas que contenían ese producto.

## Corrección aplicada

- La Bitácora ahora combina eventos reales de `auditoria_movimientos` con movimientos de inventario que carecen de su evento histórico de creación.
- Los movimientos recuperados se marcan como **INVENTARIO / Reconstruido**.
- No se inventan IP, navegador, método HTTP ni URL para registros históricos reconstruidos.
- La búsqueda de Bitácora ahora incluye código, código de barras, descripción y ubicación de los productos contenidos en los movimientos.
- Los eventos de auditoría vinculados a un movimiento muestran también el desglose real de productos.
- Los filtros por usuario, almacén, módulo, acción y fechas siguen funcionando sobre la vista consolidada.

## Archivos modificados

- `app/models/Auditoria.php`
- `public/historial_movimientos.php`
- `public/assets/css/historial_movimientos.css`

## Base de datos

No requiere crear tablas ni ejecutar una migración SQL.
