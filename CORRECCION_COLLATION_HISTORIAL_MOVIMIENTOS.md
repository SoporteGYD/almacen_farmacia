# Corrección Historial de Movimientos - Collations

## Problema
La versión anterior combinaba mediante `UNION` datos de `auditoria_movimientos` (utf8mb4_unicode_ci) con tablas operativas como `movimientos`, `usuarios`, `productos` y `almacenes` (utf8mb4_general_ci).

En MySQL esto puede provocar `Illegal mix of collations`, haciendo que el módulo muestre 0 movimientos y el mensaje "No fue posible consultar el historial de movimientos".

## Corrección
- Se eliminaron los UNION innecesarios del catálogo de filtros (Módulos/Acciones); ahora se combinan en PHP.
- En la vista consolidada se normalizan explícitamente los textos a `utf8mb4_unicode_ci` antes del UNION.
- La relación entre auditoría y movimientos usa comparación binaria para la acción y comparación numérica para `registro_id`, evitando conflictos de collation.
- No se modifican ni eliminan registros de inventario ni de auditoría.
- No requiere SQL ni migración.

## Archivos principales
- `app/models/Auditoria.php`
- El resto del proyecto se conserva igual que la versión sincronizada anterior.

## Prueba recomendada
1. Abrir Historial de movimientos > Movimientos de inventario.
2. Confirmar que vuelve a cargar movimientos sin filtros.
3. Buscar `5803`.
4. Abrir Bitácora del sistema y buscar `5803`.
5. Deben aparecer los eventos de auditoría y los movimientos reales/reconstruidos relacionados.
