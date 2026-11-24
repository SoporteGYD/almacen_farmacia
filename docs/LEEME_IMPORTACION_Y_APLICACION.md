# G&D ERP — Base de pruebas con catálogo financiero completo
**Versión:** 23 de octubre de 2026. **Uso exclusivo:** laboratorio / datos simulados.

## Archivos

- `01_GYD_LAB_COMPLETA_IMPORTAR.sql.gz`: base **completa e independiente** `prueba_erp_lab_v3`, construida desde el respaldo de laboratorio del 20/10/2026, con los datos financieros sintéticos añadidos.
- `sql/02_COMPLETAR_CATALOGO_DEMO.sql`: **sólo** DML (sin crear ni eliminar bases), para completar una copia de prueba existente compatible. Importar desde la base de datos correcta seleccionada en phpMyAdmin.
- `sql/03_DIAGNOSTICO_FINAL.sql`: conteos y muestra de parámetros después de aplicar.
- `tools/completar_catalogo_demo.php`: alternativa para aplicar el SQL **desde el proyecto XAMPP**, con `--check` y confirmación explícita.

## Opción A: Importar base de laboratorio nueva (recomendada)

1. En phpMyAdmin comprueba que **NO existe** la base `prueba_erp_lab_v3` o renombra/respalda la anterior: el dump crea esa base nueva y fallará si ya existe.
2. En phpMyAdmin → Importar selecciona `01_GYD_LAB_COMPLETA_IMPORTAR.sql.gz`. Requiere MariaDB/MySQL compatible con el respaldo (el origen fue MariaDB 10.4). La extensión `.gz` reduce el tamaño de subida.
3. En una **copia del proyecto**, cambia la propiedad privada `dbName = 'prueba'` por `dbName = 'prueba_erp_lab_v3'` en `app/config/database.php`. **No cambies el proyecto operativo.**
4. Ejecuta las instalaciones de los módulos del ERP que hayas agregado después del respaldo (reportes, finanzas, comparador, alertas, seguimiento) **sobre esa copia** si deseas usarlos en la base laboratorio.
5. Ejecuta `sql/03_DIAGNOSTICO_FINAL.sql` usando `prueba_erp_lab_v3`.

## Opción B: Completar la base local actual `prueba` (con el sistema ya actualizado)

1. **Haz primero respaldo completo** de la base `prueba` desde phpMyAdmin → Exportar (SQL), y del proyecto.
2. Extrae este ZIP, copia las carpetas `tools` y `sql` a `C:\xampp\htdocs\almacen-farmacia2\` (fusiona con las existentes; no borres las demás).
3. En PowerShell:

```powershell
& "C:\xampp\php\php.exe" "C:\xampp\htdocs\almacen-farmacia2\tools\completar_catalogo_demo.php" --check
```

4. Debe mostrar `COMPATIBLE` y `BASE SELECCIONADA: prueba`. Si la base no es de pruebas **NO continúes**.
5. Aplica sobre la base verificada:

```powershell
& "C:\xampp\php\php.exe" "C:\xampp\htdocs\almacen-farmacia2\tools\completar_catalogo_demo.php" --apply --confirmar-prueba
```

6. Verifica:

```powershell
& "C:\xampp\php\php.exe" "C:\xampp\htdocs\almacen-farmacia2\tools\completar_catalogo_demo.php" --report
```

## Campos completados

- Catálogo: costo de compra/último/promedio cuando faltan, precio base recalculado tomando también como referencia el mayor costo documental disponible, categoría/proveedor cuando faltan, laboratorio/unidad y código de barras sintético solo donde falta.
- Ficha: clasificación, subfamilia, descripción ampliada, presentaciones por defecto en registros nuevos, IVA compra, IVA venta, IEPS, margen sugerido, precio sugerido, método, descuentos, flete, puntos y observaciones. Los valores fiscales **son ficticios de prueba, no clasificación fiscal real**. Los factores de conversión ya existentes se respetan.
- Todos los precios POS de las sucursales activas y sus precios de mayoreo, con márgenes positivos aproximados. **Los precios preexistentes también se recalculan** porque la base es de pruebas.
- Asociación de proveedor principal y proveedor alternativo de muestra; no son cotizaciones reales.
- Presentación por pieza o caja y precio de caja según número de piezas (sin descuento de caja).
- Historial sintético de precio marcado `DEMO_CATALOGO_20261023` para probar reportes. En segunda ejecución no se duplica.
- Proveedores: contactos ficticios reservados para pruebas (`@demo.invalid`).

## Lo que NO se cambia deliberadamente

- **Nunca cambia** piezas del inventario de Almacén, Farmacia, POS, movimientos, kardex, recepciones o stock aplicado.
- **No inventa ni certifica facturas, órdenes, recepciones, costos históricos reales por proveedor o ahorros realmente conseguidos**. El historial de costos por factura existente conserva sus registros.
- Las OC abiertas, facturas en revisión y alertas de seguimiento **no se cierran ni se marcan atendidas automáticamente**. El diagnóstico final informa cuántas existen; requieren conciliación/decisión real para evitar estados falsos.
- Campos que no deben falsificarse (imágenes, lote/caducidad, números de serie, credenciales, usuario operador, inventario físico y folios) siguen nulos cuando no exista evidencia.
- El valor 8%/16% por sucursal es una **regla de tu ERP**; la tasa fiscal por artículo sembrada aquí es DEMO y NO reemplaza esa lógica. Verifica la operación final antes de usar fiscalmente cualquier dato.

## Validaciones sugeridas

1. `--report`: sin costo último, promedio ni precio base deben tender a 0, ficha faltante a 0 y precios por sucursal equivaler a productos × sucursales activas (en la base de origen hay **2,371 productos × 4 sucursales = 9,484 precios POS**).
2. Entra como `COMPRAS` y prueba Reportes del Catálogo, Costos/Rentabilidad y Alertas Inteligentes.
3. Compara producto común en las cuatro sucursales. Hidalgo conserva ticket sin importes por configuración POS, aunque el catálogo sí tenga precio.
4. Ejecuta las consultas de `03_DIAGNOSTICO_FINAL.sql`. Las alertas aún pueden señalar **facturas antiguas** con costo diferente o las faltas documentales previas. No deben ocultarse a la fuerza.
5. Comprueba una orden de compra real de la copia y confirma que las piezas pendientes no hayan cambiado.

**Advertencia:** este paquete no se ha ejecutado contra tu MariaDB local. Pasó validación sintáctica y de estructura contra el dump disponible; las comprobaciones `--check`, transacción y SQL real se deben realizar en XAMPP antes de considerarlo listo.

**Nota sobre pendientes:** `sql/03_DIAGNOSTICO_FINAL.sql` añade un listado de piezas por OC que todavía no cuentan con recepción REGISTRADA. Una OC EMITIDA puede estar totalmente recibida y no es, por sí sola, prueba de pendiente.
