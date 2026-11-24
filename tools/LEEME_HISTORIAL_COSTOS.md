# ERP G&D — Historial documental de costos por producto, proveedor y factura

## Requisitos

- PHP 8.0 o superior, XAMPP y ERP G&D existente.
- Haber instalado antes **Costos y rentabilidad del Catálogo Maestro**.
- Tablas de historial existentes: `erp_facturas`, `erp_facturas_detalle`, `erp_ordenes_compra`, `erp_ordenes_compra_detalle`, `proveedores`, `productos`.
- Acceso de administrador. No se agregan permisos nuevos a Compras para consultar costos protegidos.

## Instalación

1. Respaldar proyecto y la base MariaDB.
2. Copiar `tools/` del ZIP a `C:\xampp\htdocs\almacen-farmacia2\tools\`.
3. En PowerShell ejecutar:

```powershell
& "C:\xampp\php\php.exe" "C:\xampp\htdocs\almacen-farmacia2\tools\instalar_historial_costos_catalogo.php" --check
```

4. Solo si aparece `COMPATIBLE`, ejecutar el mismo comando con `--apply`.
5. Actualizar `Ctrl + F5`, entrar a Costos y Rentabilidad > Historial de costos y facturas.
   También se puede abrir `http://localhost/almacen-farmacia2/public/erp_catalogo_historial_costos.php`.

## ¿Qué muestra?

- Historial **FACTURA** de costo unitario realmente capturado en el documento, folio, proveedor, fecha, cantidad, código y estado documental.
- Historial **OC** opcional: costo pedido/emitido; **no equivale** a compra ni a recepción.
- Filtros por fechas, código/descripcion/folio, producto (ID), proveedor, fuente y sucursal para referencia de precio actual.
- Comparación de las **dos facturas más recientes** del producto seleccionado, diferenciando proveedor.
- Último costo y costo promedio **actuales del catálogo**, precio de venta **actual** y margen teórico calculado para cada documento.
- Resumen documental por proveedor y exportación de todas las partidas filtradas a CSV compatible con Excel. Versión imprimible de la página visible para guardar como PDF.

## Límites y precauciones

- Solo lectura: no ajusta precios, costos, inventario ni cambia el estado de facturas.
- La utilidad y el margen teóricos comparan un **costo de una factura histórica** con un **precio actual**; no son el margen que tuvo la venta en aquella fecha. Para reconstrucción histórica real se requieren precios vigentes en cada fecha y tratamiento de IVA, IEPS, descuentos, flete y costos operativos.
- El importe por proveedor es `cantidad documentada × costo unitario` antes de conciliaciones fiscales o ajustes posteriores. No representa cuentas pagadas.
- El costo promedio ponderado del inventario (`productos.costo_promedio`) es distinto del costo medio facturado del periodo.
- La vista web/impresión muestra 40 renglones por página; CSV exporta **todos** los registros que cumplan filtros. Para conjuntos enormes podría requerirse aumentar límites técnicos PHP/MariaDB.
- No usa ajustes automáticos, no inventa eventos históricos previos a los registros disponibles.
- Si el mismo folio se duplicó en fuentes externas, el reporte solo refleja los documentos almacenados en la BD consultada.

## Pruebas después de instalar

- Filtrar un código con al menos dos facturas, verificar fechas y costos contra las facturas originales.
- Cambiar entre FACTURA y OC y confirmar que no se mezclan importes.
- Seleccionar Tapachula y revisar que el precio sea el de esa sucursal; si no existe, mostrará «—».
- Exportar CSV y comprobar códigos con ceros iniciales. 
- Iniciar sesión como COMPRAS y comprobar que el panel financiero queda protegido.
