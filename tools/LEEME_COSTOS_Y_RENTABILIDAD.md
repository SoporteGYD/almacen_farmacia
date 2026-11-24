# Distribución G&D — Costos, precios y rentabilidad del Catálogo Maestro

Este parche añade un tablero **exclusivamente de consulta** a `public/erp_catalogo_finanzas.php` y enlaces desde el Catálogo Maestro y sus Reportes. No ejecuta `INSERT`, `UPDATE`, `DELETE` ni `ALTER` sobre la base.

## Instalación

1. Respaldar el proyecto y la BD. Mantener los parches anteriores, incluidos Reportes y Auditoría del Catálogo Maestro.
2. Extraer el ZIP y copiar únicamente `tools/` a `C:\xampp\htdocs\almacen-farmacia2\tools`.
3. En VS Code / PowerShell:

```powershell
& "C:\xampp\php\php.exe" "C:\xampp\htdocs\almacen-farmacia2\tools\instalar_finanzas_catalogo.php" --check
```

4. Únicamente si muestra `COMPATIBLE`:

```powershell
& "C:\xampp\php\php.exe" "C:\xampp\htdocs\almacen-farmacia2\tools\instalar_finanzas_catalogo.php" --apply
```

5. Abrir `http://localhost/almacen-farmacia2/public/erp_catalogo_finanzas.php` o Catálogo Maestro → Costos y rentabilidad. `Ctrl+F5` para actualizar.

## Permisos y seguridad

- Solo `ADMINISTRADOR` o `ADMIN` pueden abrir el análisis y exportar sus importes. Esto coincide con el criterio del módulo Reportes del Catálogo Maestro.
- No crea tablas ni cambia estructuras, existencias POS/Almacén, impuestos, márgenes, precios, facturas ni productos.
- El instalador crea respaldos de archivos cambiados bajo `tools/backups/finanzas_catalogo_*` y aborta ante archivos preexistentes con cambios incompatibles.
- Los enlaces por producto solo se muestran a administradores. Tener la URL directa no evita la verificación del rol.

## Mediciones disponibles

- **Costos:** último costo registrado, promedio ponderado, costo de catálogo, diferencias entre ellos, flete de ficha y último costo más flete.
- **Precios:** base de catálogo, precios públicos de **todas las sucursales con margen y mark-up individual**, precio de mayoreo, mínimo mayoreo, precio anterior si hay historial, precio sugerido y objetivo estimado según método de la ficha.
- **Rentabilidad (teórica):** utilidad por pieza y márgenes frente al último costo y al promedio, ganancia sobre costo (mark-up), proporción costo/venta, margen con flete, mayoreo, diferencias frente a sugerido/objetivo.
- **Presentaciones:** factor de compra, costo/precio multiplicado por piezas por caja.
- **Impuestos y descuentos:** IVA de compra, IVA de venta, IEPS y los 5 descuentos de ficha como datos informativos (no se aplican automáticamente).
- **POS por sucursal:** stock en farmacia, valor teórico a último/promedio y utilidad potencial sobre stock. NUNCA se mezclan con las existencias de Bodega.

## Definiciones fundamentales

- Margen sobre precio de venta: `(precio - costo) / precio × 100`.
- Ganancia sobre costo o mark-up: `(precio - costo) / costo × 100`.
- Precio objetivo por mark-up: `costo × (1 + tasa/100)`.
- Precio objetivo por margen sobre venta: `costo / (1 - tasa/100)` (tasa < 100).
- Costo, precio y factor deben corresponder a **piezas de la misma presentación** para que las comparaciones sean válidas.
- **Los resultados no representan utilidad neta.** No se separan impuestos de precios públicos ni se modelan IEPS, comisiones, devoluciones, promociones o gastos generales. El precio público puede incluir IVA. Corregir la base de cálculo fiscal antes de adoptar márgenes como oficiales.
- Precio base (`productos.precio_venta`) es diferente de precio POS de cada sucursal (`pos_precios.precio_publico`). Si falta el precio de la sucursal no se reemplaza silenciosamente por el base.
- Un IVA vacío sigue siendo **sin configurar**, no equivale a IVA 0%.
- Último costo = valor registrado en `productos.costo_ultimo`, que puede no corresponder a la última factura efectivamente pagada.
- Si no existen tablas opcionales de ficha, mayoreo, POS o historial, sus datos no se inventan: aparecerán vacíos.
- **Exportaciones:** `.xlsx` requiere PhpSpreadsheet instalado en `vendor/`; CSV y la vista imprimible están disponibles como alternativa.

## Protocolo de prueba

1. Entra como Administrador; prueba código exacto y precios por sucursal.
2. Compara `costo_ultimo`, `costo_promedio`, `precio_venta` contra registros de Productos y `pos_precios`.
3. Comprueba un artículo sin precio público en Toscana: el margen de esa sucursal debe ser "—".
4. Revisa margen y mark-up de una pieza con costo de $55.80 y precio $76.69: margen 27.24%, ganancia s/ costo 37.44%.
5. Verifica un artículo sin IVA: debe decir "Sin configurar".
6. Confirma que un usuario de Compras/Encargado sin permiso de costos recibe 403.
7. Exporta código de barras con ceros iniciales a XLSX y valida que se mantiene como texto.
8. Si `--check` dice `INCOMPATIBLE`, no fuerces el instalador: envía los archivos `erp_catalogo_maestro.php` y `erp_catalogo_reportes.php` de tu versión local.

**Compatibilidad validada contra el paquete de Auditoría del Catálogo y Reportes que existía en la conversación. No se pudo conectar a tu MariaDB/XAMPP real desde el entorno de construcción.**
