# Corrección del Historial de Movimientos

## Problema encontrado
El módulo anterior consultaba exclusivamente `auditoria_movimientos`.
Esa tabla es una bitácora y no es la fuente autoritativa de los movimientos de inventario.
Por ello, entradas o salidas existentes en `movimientos` podían no aparecer si fueron creadas antes de instalar la auditoría o si no se generó el evento de bitácora.

En el respaldo revisado se detectaron 1,283 registros reales en `movimientos` y solamente 646 eventos de auditoría `CREAR_ENTRADA` / `CREAR_SALIDA` ligados a movimientos. Por lo tanto, 637 movimientos reales no estaban representados por un evento de creación de auditoría.

## Solución
`Historial de Movimientos` ahora tiene dos vistas:

1. **Movimientos de inventario** (vista principal)
   - Lee directamente `movimientos`.
   - Muestra Entradas, Salidas, Ajustes, Traspasos, Mermas y Devoluciones disponibles en la base.
   - Permite buscar por folio, referencia, observaciones, usuario, almacén, código, código de barras o descripción del producto.
   - Filtra por usuario, almacén, tipo y rango de fechas.
   - Muestra estado APLICADO/CANCELADO.
   - Incluye detalle por producto desde `movimiento_detalle`.

2. **Bitácora del sistema**
   - Conserva el comportamiento anterior basado en `auditoria_movimientos`.
   - Sigue mostrando accesos, búsquedas, ediciones, cancelaciones y demás acciones técnicas.

## Archivos modificados
- `app/models/Auditoria.php`
- `app/controllers/AuditoriaController.php`
- `public/historial_movimientos.php`
- `public/assets/css/historial_movimientos.css`

## Base de datos
No requiere crear tablas ni ejecutar migraciones SQL.
