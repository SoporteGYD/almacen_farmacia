# Farmacias G&D — Comparador documental de proveedores

## Alcance
Reporte de **solo lectura** basado en renglones de `erp_facturas_detalle` unidos a las facturas efectivamente registradas en `erp_facturas`, con proveedor y código únicos del Catálogo Maestro. No mezcla datos de órdenes de compra emitidas con costos facturados. No actualiza precios, inventario POS, Almacén, Kardex ni costos de catálogo.

Se analiza **cada código** y se conserva el costo unitario de la factura más reciente de **cada proveedor**, la compra anterior de ese mismo proveedor, fecha, folio, cambio porcentual, precio público actual de la sucursal elegida, margen estimado por pieza y costos de catálogo (último y promedio ponderado). Si existen al menos dos proveedores con facturas dentro de los últimos N días se muestra el de menor costo histórico reciente, **no una cotización ni una oferta válida para comprar hoy**. Los costos anteriores al límite N días se etiquetan como antiguos y no se usan para escoger el menor costo reciente. Por defecto N=90.

Los precios y costos se asumen **por pieza**. En compras con cajas/bonificaciones hay que comprobar que el dato facturado sea comparable. Márgenes teóricos: antes de impuestos, descuentos, promociones y gastos de operación. Se usa el precio **actual** de POS o el precio base, nunca se reconstruyen precios históricos.

## Requisitos
- Proyecto `C:\xampp\htdocs\almacen-farmacia2`
- Módulos previos instalados: `erp_catalogo_finanzas.php` y `erp_catalogo_historial_costos.php`
- Tablas: `erp_facturas`, `erp_facturas_detalle`, `proveedores`, `productos`; opcional: `pos_precios`, `pos_sucursales`
- Perfil **Administrador** (se conservan las restricciones financieras del ERP)

## Instalación
1. Respalda archivos y base de datos.
2. Descomprime y copia **solo la carpeta `tools`** dentro del proyecto. No reemplaces las carpetas completas.
3. Ejecuta en PowerShell:

   `& "C:\xampp\php\php.exe" "C:\xampp\htdocs\almacen-farmacia2\tools\instalar_comparador_proveedores.php" --check`

4. **Solo si dice COMPATIBLE:**

   `& "C:\xampp\php\php.exe" "C:\xampp\htdocs\almacen-farmacia2\tools\instalar_comparador_proveedores.php" --apply`

5. Abre `http://localhost/almacen-farmacia2/public/erp_catalogo_comparar_proveedores.php`, actualiza con `Ctrl+F5` y filtra un código que tenga facturas de dos proveedores.
6. Desde la pantalla puedes **Exportar CSV para Excel** (todos los resultados del filtro) e **Imprimir página / Guardar PDF** (solo la página visible).

### Diagnóstico
- El instalador genera un respaldo de las pantallas que modifica dentro de `backups_gyd_comp_prov_...`.
- Si dice `INCOMPATIBLE`, **NO** fuerces `--apply` ni reemplaces archivos existentes. Comparte el error y copia de los PHP actuales para adaptar el instalador.
- Si no aparecen filas, confirma que haya facturas históricas en la base seleccionada, con códigos de producto válidos y las fechas adecuadas.
- Es normal que un código de **un solo proveedor** no muestre el distintivo «menor costo observado».
- Si hay muchos documentos, la consulta puede requerir ajustes de índices y memoria PHP; no introduce un tope comercial a cantidad de artículos, pero respeta los límites reales del servidor.

## Verificaciones ya realizadas sobre copia
- Sintaxis PHP de helper, pantalla, instalador y pantallas modificadas.
- 10 pruebas básicas de cálculos, antigüedad y protección de salida CSV.
- Instalación, ejecución repetida e inserción de un enlace en cada pantalla.

**No se probó contra la base de datos MariaDB real del usuario.**
