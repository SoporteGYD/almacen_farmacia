# Corrección del Kardex conciliado

## Problema encontrado

El Kardex anterior iniciaba `$saldo = 0` después de aplicar el filtro de fechas.
Esto hacía que el primer movimiento del periodo partiera de cero aunque el producto
ya tuviera existencia previa. Además, la base actual no cuenta con un movimiento
de inventario inicial completo para todos los productos, por lo que reconstruir el
stock histórico solamente sumando entradas y restando salidas desde cero no es
confiable.

## Fuente de verdad

La existencia vigente se toma de `producto_existencias`.
Los movimientos documentales se toman de `movimientos` y `movimiento_detalle`.

## Nueva lógica

1. Obtiene la existencia real actual del producto en el almacén seleccionado.
2. Si la fecha final es histórica, revierte los movimientos posteriores para estimar
   la existencia al cierre de ese día.
3. Calcula el neto del periodo: Entradas - Salidas.
4. Obtiene el inventario inicial conciliado:

   Inventario inicial = Inventario final al corte - Neto del periodo

5. Recorre cronológicamente las entradas y salidas.
6. No fuerza saldos negativos a cero; si existiera una inconsistencia real, queda
   visible en vez de ocultarse.
7. Si un mismo folio tiene al mismo producto en varias ubicaciones, se consolida en
   una sola fila sumando la cantidad y mostrando las ubicaciones en notas.

## Resultado esperado

Cuando `Fecha final` es hoy:

- `Inv. Final` del último movimiento = existencia actual de `producto_existencias`.
- El resumen "Existencia actual sistema" y "Inv. final al corte" deben coincidir.

Cuando la fecha final es anterior a hoy:

- `Inv. final al corte` representa el saldo reconstruido para esa fecha.
- `Existencia actual sistema` muestra la existencia vigente para comparación.

## Base de datos

No se requiere crear una tabla nueva ni ejecutar una migración SQL para esta
corrección. `producto_existencias` ya contiene el stock actual que necesitamos como
ancla de conciliación.

## Archivos modificados

- `app/models/Movimiento.php`
- `public/kardex.php`

## Diagnóstico adicional

Se incluye `database/diagnostico_kardex.sql`, que es solo de consulta y no modifica
datos. Sirve para comparar existencia actual, entradas, salidas y el saldo base
histórico implícito de cada producto/almacén.
