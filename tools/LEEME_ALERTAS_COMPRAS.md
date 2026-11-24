# ERP G&D — Panel de Alertas Inteligentes de Compras

## Antes de instalar

Depende de **Costos y Rentabilidad** y **Comparador de Proveedores**. Se requiere su archivo `app/helpers/erp_comparador_proveedores.php` y las páginas `public/erp_catalogo_comparar_proveedores.php` y `public/erp_catalogo_finanzas.php`.

Respalda la carpeta de tu proyecto y MySQL.

Copia la carpeta `tools` de este ZIP dentro de `C:\xampp\htdocs\almacen-farmacia2` combinando carpetas. No sustituyas la raíz completa del ERP.

```powershell
& "C:\xampp\php\php.exe" "C:\xampp\htdocs\almacen-farmacia2\tools\instalar_alertas_compras.php" --check
```

Solo si aparece **COMPATIBLE**:

```powershell
& "C:\xampp\php\php.exe" "C:\xampp\htdocs\almacen-farmacia2\tools\instalar_alertas_compras.php" --apply
```

Luego abre:

`http://localhost/almacen-farmacia2/public/erp_alertas_compras.php`

Puedes realizar pruebas locales de lógica con:

`C:\xampp\php\php.exe tools\PROBAR_ALERTAS_COMPRAS.php`

## Alertas y alcance

- **Aumento de costo:** último costo registrado en factura del proveedor frente a la factura anterior de ese proveedor y mismo código. Umbral predeterminado 20%.
- **Margen bajo:** diferencia entre el **precio público actual seleccionado** y el costo documentado en la última factura entre los proveedores comparados. Por defecto umbral 15%.
- **Precio inferior al costo:** riesgo de margen bruto negativo **estimado**.
- **Posible ahorro:** última factura del producto proviene de un proveedor más caro que otro proveedor con factura vigente en el mismo período; umbral predeterminado 8%. **No significa que exista una oferta actual**.
- **Sin precio:** no hay precio público positivo configurado para la sucursal seleccionada.

Los documentos que no estén en REGISTRADA, EN_REVISION, VERIFICADA o CON_INCIDENCIAS no se incluyen. Solo son facturas registradas: ninguna OC, pedido ni presupuesto se interpreta como compra realizada.

**El panel recalcula al cargar la página, no crea notificaciones push ni tareas programadas.** No modifica la BD, precios, márgenes, costos ni existencias. Los reportes muestran datos históricos de factura, no ventas netas. El cálculo de margen no descuenta IVA, IEPS, bonificaciones, flete ni gastos operativos. Validar antes de cambiar precios.

Disponible exclusivamente para `ADMIN`/`ADMINISTRADOR`, al igual que el comparador anterior.

El filtro "Proveedor" es una restricción sobre el universo de facturas, de modo que al escoger un proveedor individual ya no hay comparación entre múltiples proveedores. Para comparar, elige "Todos".

El rango de fechas determina tanto la última factura como la posible referencia anterior, por lo que un rango muy corto puede no mostrar aumentos históricos.

La exportación CSV ofrece todas las alertas filtradas, incluso las que no estén en la página visible. Se sanitizan cadenas que comienzan con caracteres de fórmula al abrir en Excel.

## Seguridad

- El instalador realiza una **comprobación de compatibilidad antes de escribir** y respalda los archivos que cambia.
- Si un archivo fuente ya existe con modificaciones, se detiene en lugar de sobrescribirlo.
- No modifica tablas ni lleva a cabo movimientos contables o de existencias.
- La prueba de sintaxis no equivale a una prueba con tu MariaDB local. Verifica especialmente las facturas anuladas, productos sin precios y diferencias entre unidades.
