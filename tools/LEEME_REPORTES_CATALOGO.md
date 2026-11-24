# ERP G&D — Reportes personalizados del Catálogo Maestro

Actualización incremental para **almacen-farmacia2**. No ejecuta SQL de escritura ni altera existencias, precios o fichas.

## Instalación

1. **Respalda** tu carpeta de proyecto y tu base de datos.
2. Extrae este ZIP y **copia la carpeta `tools`** a `C:\xampp\htdocs\almacen-farmacia2`. Combina las carpetas; no reemplaces el proyecto completo.
3. Desde PowerShell, dentro del proyecto:
   ```powershell
   & "C:\xampp\php\php.exe" "C:\xampp\htdocs\almacen-farmacia2\tools\instalar_reportes_catalogo.php" --check
   ```
4. Si indica **COMPATIBLE**:
   ```powershell
   & "C:\xampp\php\php.exe" "C:\xampp\htdocs\almacen-farmacia2\tools\instalar_reportes_catalogo.php" --apply
   ```
5. Abre `http://localhost/almacen-farmacia2/public/erp_catalogo_maestro.php` y pulsa **Reportes personalizados**. También está disponible `http://localhost/almacen-farmacia2/public/erp_catalogo_reportes.php`.

## Funciones

- Filtros por código, nombre, categoría, proveedor, laboratorio, estado, integridad de datos e IVA (si existe la tabla `erp_producto_ficha`).
- Selección de columnas: código, barras, descripción, categoría, proveedor, unidad, estado, ficha, tipo, subfamilia, IVA/IEPS y costos para Administrador.
- Vista de 50 filas por página; exportaciones con todos los registros que coinciden con los filtros.
- Excel `.xlsx` real por PhpSpreadsheet ya incluido en el proyecto, CSV UTF-8 como alternativa y versión para **Imprimir / Guardar PDF** desde el navegador.
- Los códigos se exportan como texto a XLSX para conservar ceros iniciales.
- `IVA sin configurar` se muestra distinto a `IVA 0%` configurado.
- La lista de rutas autorizadas del rol Compras se actualiza sin abrir permisos de otros módulos.

## Respaldo, límites y pruebas

- Al aplicar, los archivos modificados se respaldan automáticamente en `tools/backups/catalogo_reportes_<fecha>`.
- No hay nueva tabla ni migración de base de datos.
- Este instalador **no reemplaza un archivo existente diferente** si detecta conflicto; en ese caso muestra INCOMPATIBLE.
- Reportes enormes pueden requerir memoria adicional en PHP para `.xlsx`; CSV transmite los resultados en secuencia sin un límite de filas impuesto por el módulo.
- La opción PDF usa el flujo de impresión del navegador como el reporte original de Almacén; no descarga un PDF automáticamente.
- Pruebas realizadas sobre copia reconstruida de los archivos de referencia: sintaxis PHP, permisos de columnas, filtros e instalación repetida. **La conexión con tu MariaDB real se comprobará en tu equipo**.

## Verificación tras instalar

1. Entra como ADMINISTRADOR: debe aparecer el botón en Catálogo Maestro.
2. Selecciona columnas, filtra por proveedor y exporta XLSX; verifica códigos de barras con ceros iniciales.
3. Comprueba que IVA sin configurar aparezca como tal y no como 0%.
4. Entra como COMPRAS: debe permitir el reporte, pero no mostrar columnas de costos que sean exclusivas del administrador.
5. Confirma que el número de productos e inventarios no haya cambiado; este módulo es solo lectura.
