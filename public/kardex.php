<?php

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/helpers/utils.php';
require_once __DIR__ . '/../app/models/Movimiento.php';

requireLogin();

$model = new Movimiento();

$productos = $model->getProductosCatalogo();
$almacenes = $model->getAlmacenes();

$usuario = $_SESSION['user'] ?? [];
$rol = strtoupper(trim($usuario['rol'] ?? ''));
$almacenSesion = (int)($usuario['almacen_id'] ?? 0);

$productoId = isset($_GET['producto_id']) ? (int)$_GET['producto_id'] : 0;

if ($rol === 'ADMINISTRADOR') {
    $almacenId = isset($_GET['almacen_id']) ? (int)$_GET['almacen_id'] : 0;
} else {
    $almacenId = $almacenSesion;
}

$fechaInicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fechaFinal = $_GET['fecha_final'] ?? date('Y-m-d');

$kardex = [];
$resumenKardex = [
    'existencia_actual' => 0,
    'inventario_inicial' => 0,
    'total_entradas' => 0,
    'total_salidas' => 0,
    'inventario_final' => 0,
    'diferencia_vs_actual' => 0,
    'conciliado_actual' => false,
];
$errorKardex = '';
$productoSeleccionado = null;
$almacenSeleccionado = 'TODOS';

foreach ($almacenes as $almacen) {
    if ((int)$almacen['id'] === $almacenId) {
        $almacenSeleccionado = $almacen['nombre'];
        break;
    }
}

foreach ($productos as $producto) {
    if ((int)$producto['id'] === $productoId) {
        $productoSeleccionado = $producto;
        break;
    }
}

if ($rol !== 'ADMINISTRADOR' && $almacenId <= 0) {
    $almacenSeleccionado = 'SIN ALMACÉN ASIGNADO';
}

if ($fechaInicio > $fechaFinal) {
    $errorKardex = 'La fecha inicial no puede ser mayor que la fecha final.';
} elseif ($productoId > 0 && ($rol === 'ADMINISTRADOR' || $almacenId > 0)) {
    $resultadoKardex = $model->generarKardexDetallado(
        $productoId,
        $almacenId,
        $fechaInicio,
        $fechaFinal
    );

    $kardex = $resultadoKardex['movimientos'] ?? [];
    $resumenKardex = array_merge(
        $resumenKardex,
        $resultadoKardex['resumen'] ?? []
    );
}

$productosJson = [];

foreach ($productos as $producto) {
    $texto = trim(($producto['codigo'] ?? '') . ' - ' . ($producto['descripcion'] ?? ''));

    $productosJson[] = [
        'id' => (int)$producto['id'],
        'codigo' => $producto['codigo'] ?? '',
        'descripcion' => $producto['descripcion'] ?? '',
        'texto' => $texto
    ];
}

$productoTextoActual = '';

if ($productoSeleccionado) {
    $productoTextoActual = trim(
        ($productoSeleccionado['codigo'] ?? '') . ' - ' . ($productoSeleccionado['descripcion'] ?? '')
    );
}

$moduleCss = 'kardex';
include __DIR__ . '/../app/views/layouts/header.php';
?>

<style>
.kardex-card {
    background: #fff;
    border-radius: 14px;
    padding: 20px;
    margin-bottom: 18px;
    box-shadow: 0 8px 18px rgba(0,0,0,.06);
    border: 1px solid #e5e7eb;
}

.kardex-summary {
    display: grid;
    grid-template-columns: repeat(5, minmax(140px, 1fr));
    gap: 10px;
    margin-bottom: 18px;
}

.kardex-summary-card {
    background: #fff;
    border: 1px solid #dbe4ef;
    border-radius: 12px;
    padding: 13px 14px;
    min-width: 0;
}

.kardex-summary-label {
    display: block;
    color: #64748b;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    margin-bottom: 5px;
}

.kardex-summary-value {
    color: #0f172a;
    font-size: 22px;
    font-weight: 800;
    line-height: 1;
}

.kardex-summary-card.actual,
.kardex-summary-card.final {
    border-color: #93c5fd;
    background: #eff6ff;
}

.kardex-summary-card.entrada .kardex-summary-value { color: #047857; }
.kardex-summary-card.salida .kardex-summary-value { color: #b91c1c; }

.kardex-conciliacion {
    margin: -8px 0 16px;
    padding: 9px 12px;
    border-radius: 9px;
    background: #ecfdf5;
    border: 1px solid #a7f3d0;
    color: #065f46;
    font-size: 12px;
    font-weight: 700;
}

.kardex-error {
    margin-bottom: 14px;
    padding: 10px 12px;
    border-radius: 9px;
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
    font-weight: 700;
}

@media (max-width: 1050px) {
    .kardex-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 600px) {
    .kardex-summary { grid-template-columns: 1fr; }
}

.kardex-filtros {
    display: grid;
    grid-template-columns: 180px 1fr 170px 170px auto;
    gap: 12px;
    align-items: end;
}

.kardex-field {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.kardex-field label {
    font-weight: bold;
    font-size: 13px;
    color: #1f2937;
}

.kardex-field input,
.kardex-field select {
    padding: 10px 12px;
    border: 1px solid #cfd8e3;
    border-radius: 8px;
    font-size: 14px;
}

.product-desc-box {
    margin-top: 7px;
    padding: 9px 11px;
    border-radius: 8px;
    background: #f8fafc;
    border: 1px solid #e5e7eb;
    color: #334155;
    font-size: 13px;
    min-height: 36px;
}

.product-desc-box strong {
    color: #0f172a;
}

.kardex-actions {
    display: flex;
    gap: 8px;
}

.btn-kardex {
    background: #0f4c81;
    color: white;
    border: none;
    padding: 11px 16px;
    border-radius: 8px;
    font-weight: bold;
    cursor: pointer;
    text-decoration: none;
}

.btn-print {
    background: #2563eb;
}

.kardex-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}

.kardex-table th {
    background: #0f172a;
    color: white;
    padding: 8px;
    border: 1px solid #d1d5db;
    text-align: left;
}

.kardex-table td {
    padding: 7px;
    border: 1px solid #d1d5db;
}

.kardex-table tr:nth-child(even) {
    background: #f8fafc;
}

.print-header,
.print-info,
.print-line,
.print-tipo,
.print-footer {
    display: none;
}

@media print {
    @page {
        size: A4 landscape;
        margin: 8mm 6mm 8mm 18mm;
    }

    html,
    body {
        width: 100%;
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
        font-family: Arial, Helvetica, sans-serif !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    body * {
        visibility: hidden !important;
    }

    .print-area,
    .print-area * {
        visibility: visible !important;
    }

    .print-area {
        position: fixed !important;
        left: 8mm !important;
        top: 0 !important;
        width: calc(100% - 10mm) !important;
        max-width: calc(100% - 10mm) !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
    }

    .no-print,
    .module-header,
    header,
    nav,
    .topbar,
    .navbar,
    footer {
        display: none !important;
    }

    .print-header {
        position: relative !important;
        display: flex !important;
        align-items: center !important;
        justify-content: flex-start !important;
        margin-bottom: 6px !important;
        min-height: 62px !important;
    }

    .print-left {
        display: flex !important;
        align-items: center !important;
        gap: 8px !important;
    }

    .print-logo img {
        width: 58px !important;
        height: auto !important;
        object-fit: contain !important;
    }

    .print-company {
        font-size: 9px !important;
        line-height: 1.25 !important;
    }

    .print-company-title {
        font-size: 10px !important;
        font-weight: bold !important;
        margin-bottom: 3px !important;
        text-transform: uppercase !important;
    }

    .print-title-box {
        position: absolute !important;
        left: 50% !important;
        transform: translateX(-50%) !important;
        text-align: center !important;
        font-size: 15px !important;
        line-height: 1.15 !important;
        font-weight: bold !important;
        text-transform: uppercase !important;
        letter-spacing: .5px !important;
    }

    .print-number {
        display: block !important;
        color: #c0392b !important;
        font-size: 18px !important;
        font-weight: bold !important;
        margin-top: 2px !important;
    }

    .print-line {
        display: block !important;
        border-top: 1px solid #777 !important;
        margin: 4px 0 !important;
    }

    .print-tipo {
        display: block !important;
        text-align: right !important;
        font-size: 14px !important;
        font-weight: bold !important;
        letter-spacing: 1px !important;
        margin: 4px 0 !important;
    }

    .print-info {
        display: grid !important;
        grid-template-columns: 80px 1fr 80px 1fr !important;
        gap: 4px 10px !important;
        font-size: 10px !important;
        margin-bottom: 5px !important;
    }

    .print-label {
        font-weight: bold !important;
        font-size: 10px !important;
    }

    .kardex-summary {
        display: grid !important;
        grid-template-columns: repeat(5, 1fr) !important;
        gap: 4px !important;
        margin: 5px 0 6px !important;
    }

    .kardex-summary-card {
        padding: 5px !important;
        border: 1px solid #777 !important;
        border-radius: 0 !important;
        background: #fff !important;
    }

    .kardex-summary-label {
        font-size: 7px !important;
        margin-bottom: 2px !important;
    }

    .kardex-summary-value {
        font-size: 11px !important;
        color: #000 !important;
    }

    .kardex-conciliacion {
        display: none !important;
    }

    .kardex-card {
        box-shadow: none !important;
        border: none !important;
        padding: 0 !important;
        margin: 0 !important;
        background: #fff !important;
        width: 100% !important;
    }

    .kardex-table {
        width: 94% !important;
        max-width: 94% !important;
        border-collapse: collapse !important;
        table-layout: fixed !important;
        font-size: 8px !important;
        margin-top: 6px !important;
    }

    .kardex-table thead {
        display: table-header-group !important;
    }

    .kardex-table tr {
        page-break-inside: avoid !important;
    }

    .kardex-table th,
    .kardex-table td {
        border: 1px solid #555 !important;
        padding: 2px !important;
        color: #000 !important;
        background: #fff !important;
        vertical-align: middle !important;
        text-align: center !important;
        line-height: 1.15 !important;
        word-break: break-word !important;
        overflow-wrap: break-word !important;
    }

    .kardex-table th {
        background: #efefef !important;
        font-weight: bold !important;
        font-size: 8.5px !important;
    }

    .col-notas {
        white-space: normal !important;
        overflow: visible !important;
        text-overflow: unset !important;
        word-break: break-word !important;
        line-height: 1.15 !important;
    }

    .print-footer {
        display: block !important;
        text-align: center !important;
        margin-top: 8px !important;
        font-size: 10px !important;
        font-weight: bold !important;
    }
}
</style>

<div class="module-header no-print">
    <div>
        <h2>Kardex de Inventario</h2>
        <p>Consulta movimientos por producto, almacén y rango de fechas.</p>
    </div>
</div>

<div class="kardex-card no-print">
    <form method="GET" class="kardex-filtros" onsubmit="return validarProductoKardex();">
        <div class="kardex-field">
            <label>Almacén</label>
            <select name="almacen_id" <?= $rol !== 'ADMINISTRADOR' ? 'disabled' : '' ?>>
                <?php if ($rol === 'ADMINISTRADOR'): ?>
                    <option value="0">TODOS</option>
                <?php endif; ?>

                <?php foreach ($almacenes as $almacen): ?>
                    <option 
                        value="<?= (int)$almacen['id'] ?>" 
                        <?= $almacenId === (int)$almacen['id'] ? 'selected' : '' ?>
                    >
                        <?= e($almacen['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <?php if ($rol !== 'ADMINISTRADOR'): ?>
                <input type="hidden" name="almacen_id" value="<?= (int)$almacenId ?>">
            <?php endif; ?>
        </div>

        <div class="kardex-field">
            <label>Producto</label>

            <input
                type="text"
                id="productoBuscar"
                list="productosKardexList"
                value="<?= e($productoTextoActual) ?>"
                placeholder="Escribe código o descripción del producto"
                autocomplete="off"
                required
            >

            <input
                type="hidden"
                name="producto_id"
                id="producto_id"
                value="<?= (int)$productoId ?>"
            >

            <datalist id="productosKardexList">
                <?php foreach ($productos as $producto): ?>
                    <option value="<?= e(($producto['codigo'] ?? '') . ' - ' . ($producto['descripcion'] ?? '')) ?>"></option>
                <?php endforeach; ?>
            </datalist>

            <div class="product-desc-box" id="productoDescripcionBox">
                <?php if ($productoSeleccionado): ?>
                    <strong>Descripción:</strong>
                    <?= e($productoSeleccionado['descripcion'] ?? '') ?>
                <?php else: ?>
                    Escribe o selecciona un producto.
                <?php endif; ?>
            </div>
        </div>

        <div class="kardex-field">
            <label>Fecha inicio</label>
            <input type="date" name="fecha_inicio" value="<?= e($fechaInicio) ?>">
        </div>

        <div class="kardex-field">
            <label>Fecha final</label>
            <input type="date" name="fecha_final" value="<?= e($fechaFinal) ?>">
        </div>

        <div class="kardex-actions">
            <button type="submit" class="btn-kardex">Generar Kardex</button>
            <button type="button" onclick="window.print()" class="btn-kardex btn-print">Imprimir</button>
        </div>
    </form>
</div>

<?php if ($errorKardex !== ''): ?>
    <div class="kardex-error no-print"><?= e($errorKardex) ?></div>
<?php endif; ?>

<div class="print-area">

    <?php if ($productoSeleccionado && $errorKardex === ''): ?>
        <div class="kardex-summary">
            <div class="kardex-summary-card actual">
                <span class="kardex-summary-label">Existencia actual sistema</span>
                <span class="kardex-summary-value"><?= number_format((int)$resumenKardex['existencia_actual']) ?></span>
            </div>
            <div class="kardex-summary-card">
                <span class="kardex-summary-label">Inv. inicial período</span>
                <span class="kardex-summary-value"><?= number_format((int)$resumenKardex['inventario_inicial']) ?></span>
            </div>
            <div class="kardex-summary-card entrada">
                <span class="kardex-summary-label">Entradas</span>
                <span class="kardex-summary-value">+<?= number_format((int)$resumenKardex['total_entradas']) ?></span>
            </div>
            <div class="kardex-summary-card salida">
                <span class="kardex-summary-label">Salidas</span>
                <span class="kardex-summary-value">-<?= number_format((int)$resumenKardex['total_salidas']) ?></span>
            </div>
            <div class="kardex-summary-card final">
                <span class="kardex-summary-label">Inv. final al corte</span>
                <span class="kardex-summary-value"><?= number_format((int)$resumenKardex['inventario_final']) ?></span>
            </div>
        </div>

        <?php if (!empty($resumenKardex['conciliado_actual'])): ?>
            <div class="kardex-conciliacion no-print">
                ✓ Kardex conciliado: el Inv. Final corresponde a la existencia actual del sistema para el almacén seleccionado.
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="print-header">
        <div class="print-left">
            <div class="print-logo">
                <img src="assets/img/logo.jpeg" alt="Logo G&D">
            </div>

            <div class="print-company">
                <div class="print-company-title">DISTRIBUCIÓN G&D, S.A. DE C.V.</div>
                <div>CP:</div>
                <div>RFC: DGD151211PP5</div>
            </div>
        </div>

        <div class="print-title-box">
            KARDEX DE INVENTARIO
            <span class="print-number">No. <?= $productoId > 0 ? (int)$productoId : '---' ?></span>
        </div>
    </div>

    <div class="print-line"></div>
    <div class="print-tipo">Tipo : Kardex</div>
    <div class="print-line"></div>

    <div class="print-info">
        <div class="print-label">Producto:</div>
        <div>
            <?php if ($productoSeleccionado): ?>
                <?= e($productoSeleccionado['codigo']) ?> - <?= e($productoSeleccionado['descripcion']) ?>
            <?php else: ?>
                Sin producto seleccionado
            <?php endif; ?>
        </div>

        <div class="print-label">Almacén:</div>
        <div><?= e($almacenSeleccionado) ?></div>
    </div>

    <div class="print-info">
        <div class="print-label">Fecha inicial:</div>
        <div><?= e(date('d/m/Y', strtotime($fechaInicio))) ?></div>

        <div class="print-label">Fecha final:</div>
        <div><?= e(date('d/m/Y', strtotime($fechaFinal))) ?></div>
    </div>

    <div class="kardex-card">
        <table class="kardex-table">
            <thead>
                <tr>
                    <th style="width: 8%;">Fecha</th>
                    <th style="width: 9%;">Folio</th>
                    <th style="width: 7%;">Tipo</th>
                    <th style="width: 8%;">Almacén</th>
                    <th style="width: 10%;">Destino / Ref.</th>
                    <th style="width: 6%;">Inv. Inicial</th>
                    <th style="width: 5%;">Cant.</th>
                    <th style="width: 6%;">Inv. Final</th>
                    <th style="width: 7%;">Efecto</th>
                    <th style="width: 18%;">Notas</th>
                    <th style="width: 8%;">Usuario</th>
                </tr>
            </thead>

            <tbody>
                <?php if (empty($kardex)): ?>
                    <tr>
                        <td colspan="11" style="text-align:center;">No hay movimientos para mostrar.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($kardex as $row): ?>
                        <tr>
                            <td><?= e(date('d/m/Y H:i', strtotime($row['fecha']))) ?></td>
                            <td><?= e($row['folio']) ?></td>
                            <td><?= e($row['tipo_movimiento']) ?></td>
                            <td><?= e($row['almacen_afectado']) ?></td>
                            <td><?= e($row['almacen_destino']) ?></td>
                            <td><?= (int)$row['inventario_inicial'] ?></td>
                            <td><?= (int)$row['cantidad'] ?></td>
                            <td><?= (int)$row['inventario_final'] ?></td>
                            <td><?= e($row['efecto']) ?></td>
                            <td class="col-notas"><?= e($row['notas']) ?></td>
                            <td><?= e($row['usuario']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="print-footer">
            Movimiento Realizado !!!
        </div>
    </div>

</div>

<script>
const productosKardex = <?= json_encode($productosJson, JSON_UNESCAPED_UNICODE) ?>;

const productoBuscar = document.getElementById('productoBuscar');
const productoIdInput = document.getElementById('producto_id');
const productoDescripcionBox = document.getElementById('productoDescripcionBox');

function normalizarTexto(valor) {
    return String(valor || '').trim().toLowerCase();
}

function buscarProductoPorTexto(texto) {
    const limpio = normalizarTexto(texto);

    if (!limpio) {
        return null;
    }

    let producto = productosKardex.find(p =>
        normalizarTexto(p.texto) === limpio
    );

    if (producto) {
        return producto;
    }

    producto = productosKardex.find(p =>
        normalizarTexto(p.codigo) === limpio
    );

    if (producto) {
        return producto;
    }

    producto = productosKardex.find(p =>
        normalizarTexto(p.descripcion) === limpio
    );

    if (producto) {
        return producto;
    }

    producto = productosKardex.find(p =>
        normalizarTexto(p.codigo).includes(limpio) ||
        normalizarTexto(p.descripcion).includes(limpio) ||
        normalizarTexto(p.texto).includes(limpio)
    );

    return producto || null;
}

function actualizarProductoSeleccionado() {
    const producto = buscarProductoPorTexto(productoBuscar.value);

    if (!producto) {
        productoIdInput.value = '';
        productoDescripcionBox.innerHTML = 'Producto no encontrado. Escribe el código o selecciona una opción de la lista.';
        return false;
    }

    productoIdInput.value = producto.id;
    productoBuscar.value = producto.texto;
    productoDescripcionBox.innerHTML = '<strong>Descripción:</strong> ' + escapeHtml(producto.descripcion);

    return true;
}

function validarProductoKardex() {
    return actualizarProductoSeleccionado();
}

function escapeHtml(str) {
    return String(str || '').replace(/[&<>"']/g, function(m) {
        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        }[m];
    });
}

productoBuscar.addEventListener('input', function() {
    const producto = buscarProductoPorTexto(this.value);

    if (!producto) {
        productoIdInput.value = '';
        productoDescripcionBox.innerHTML = 'Escribe código o descripción del producto.';
        return;
    }

    productoIdInput.value = producto.id;
    productoDescripcionBox.innerHTML = '<strong>Descripción:</strong> ' + escapeHtml(producto.descripcion);
});

productoBuscar.addEventListener('change', actualizarProductoSeleccionado);
</script>

<?php
if (file_exists(__DIR__ . '/../app/views/layouts/footer.php')) {
    include __DIR__ . '/../app/views/layouts/footer.php';
}
?>