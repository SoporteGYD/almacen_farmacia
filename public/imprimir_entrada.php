<?php

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/helpers/utils.php';
require_once __DIR__ . '/../app/controllers/EntradaController.php';

requireLogin();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id <= 0) {
    die('Movimiento no válido.');
}

$controller = new EntradaController();
$entrada = $controller->obtenerEntrada($id);

if (!$entrada) {
    die('Entrada no encontrada.');
}

$total = 0.0;

foreach ($entrada['detalles'] as $detalle) {
    $total += ((float)$detalle['costo_unitario'] * (int)$detalle['cantidad']);
}

/*
|--------------------------------------------------------------------------
| REFERENCIA COMPLETA
|--------------------------------------------------------------------------
*/
$referenciaCompleta = trim($entrada['referencia'] ?? '');

$partesReferencia = explode('|', $referenciaCompleta);

$movimientoTexto = trim($partesReferencia[0] ?? 'Entrada de almacén');

$documentoTexto = '';
$proveedorManual = '';

foreach ($partesReferencia as $parte) {

    $parte = trim($parte);

    if (str_starts_with($parte, 'Ref:')) {
        $documentoTexto = trim(str_replace('Ref:', '', $parte));
    }

    if (str_starts_with($parte, 'Proveedor:')) {
        $proveedorManual = trim(str_replace('Proveedor:', '', $parte));
    }
}

/*
|--------------------------------------------------------------------------
| PROVEEDOR
|--------------------------------------------------------------------------
*/
if ($proveedorManual !== '') {
    $proveedorNombre = $proveedorManual;
} else {
    $proveedorNombre = $entrada['proveedor_nombre'] ?? '';
}

/*
|--------------------------------------------------------------------------
| DATOS GENERALES
|--------------------------------------------------------------------------
*/
$fechaImpresa = '';

if (!empty($entrada['fecha'])) {
    $fechaImpresa = date('d/m/Y', strtotime($entrada['fecha']));
}

$folioEntrada = $entrada['folio'] ?? '';
$almacenNombre = $entrada['almacen_nombre'] ?? '';
$usuarioNombre = $entrada['usuario_nombre'] ?? '';
$observaciones = $entrada['observaciones'] ?? '';

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Imprimir entrada <?= e($folioEntrada) ?></title>

    <style>

        @page {
            size: A4 portrait;
            margin: 12mm 14mm;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            color: #111;
            margin: 0;
            background: #ffffff;
            font-size: 11px;
            line-height: 1.25;
        }

        .no-print {
            width: 100%;
            max-width: 900px;
            margin: 14px auto 10px auto;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 14px;
            border-radius: 6px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            font-weight: bold;
            font-size: 13px;
        }

        .btn-primary {
            background: #0f4c81;
            color: #fff;
        }

        .btn-secondary {
            background: #6b7280;
            color: #fff;
        }

        .sheet {
            width: 100%;
            max-width: 900px;
            margin: 0 auto;
            background: #fff;
            padding: 8px 6px;
        }

        .header {
            display: grid;
            grid-template-columns: 78px 1fr 220px;
            gap: 10px;
            align-items: start;
            margin-bottom: 8px;
        }

        .logo-box {
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding-top: 2px;
        }

        .logo-box img {
            width: 75px;
            height: auto;
            object-fit: contain;
        }

        .company-box {
            font-size: 10px;
            line-height: 1.3;
        }

        .company-name {
            font-size: 11px;
            font-weight: bold;
            margin-bottom: 6px;
            text-transform: uppercase;
        }

        .movement-box {
            text-align: right;
            font-size: 10px;
            line-height: 1.3;
        }

        .movement-box h2 {
            margin: 0 0 6px 0;
            font-size: 11px;
            font-weight: bold;
        }

        .movement-number {
            color: #c0392b;
            font-size: 13px;
            font-weight: bold;
            word-break: break-word;
        }

        .line {
            border-top: 1px solid #7d7d7d;
            margin: 6px 0;
        }

        .tipo-row {
            text-align: right;
            font-size: 11px;
            font-weight: bold;
            margin: 4px 0;
        }

        .info-grid {
            display: grid;
            grid-template-columns: 82px 1fr 82px 1fr;
            gap: 4px 10px;
            font-size: 10px;
            margin-bottom: 5px;
            align-items: start;
        }

        .info-grid-full {
            display: grid;
            grid-template-columns: 90px minmax(0, 1fr);
            gap: 4px 10px;
            font-size: 10px;
            margin-bottom: 6px;
            align-items: start;
        }

        .label {
            font-weight: bold;
        }

        .observaciones-box {
            white-space: pre-wrap;
            word-break: break-word;
        }

        .documento-row {
            margin-bottom: 10px;
        }

        .documento-box {
            white-space: normal;
            word-break: break-word;
            overflow-wrap: anywhere;
            line-height: 1.5;
            max-width: 100%;
            font-size: 10px;
            padding-right: 10px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9.5px;
            margin-top: 8px;
            table-layout: fixed;
        }

        th,
        td {
            border: 1px solid #d6d6d6;
            padding: 5px 5px;
            vertical-align: top;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        th {
            background: #efefef;
            text-align: left;
            font-size: 9.5px;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .total-row td {
            font-weight: bold;
            background: #fafafa;
        }

        .footer-note {
            margin-top: 18px;
            text-align: center;
            font-size: 13px;
            font-weight: bold;
        }

        @media print {

            .no-print {
                display: none !important;
            }

            body {
                background: #fff;
                margin: 0;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .sheet {
                max-width: 100%;
                padding: 0;
                margin: 0;
            }

            table {
                page-break-inside: auto;
            }

            tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }

            thead {
                display: table-header-group;
            }

            tfoot {
                display: table-footer-group;
            }
        }

    </style>
</head>

<body>

<script>

function imprimirYLimpiar() {
    localStorage.removeItem('borradorEntrada');
    window.print();
}

</script>

<div class="no-print">
    <button class="btn btn-primary" onclick="imprimirYLimpiar()">Imprimir</button>
    <a class="btn btn-secondary" href="javascript:history.back()">Regresar</a>
    <a class="btn btn-primary" href="entradas.php">Nueva entrada</a>
</div>

<div class="sheet">

    <div class="header">

        <div class="logo-box">
            <img src="assets/img/logo.jpeg" alt="Logo G&D">
        </div>

        <div class="company-box">
            <div class="company-name">
                DISTRIBUCIÓN G&D, S.A. DE C.V.
            </div>

            <div>CP:</div>
            <div>RFC: DGD151211PP5</div>
        </div>

        <div class="movement-box">
            <h2>Movimientos al Inventario</h2>

            <div>
                Folio:
                <span class="movement-number">
                    <?= e($folioEntrada) ?>
                </span>
            </div>
        </div>

    </div>

    <div class="line"></div>

    <div class="tipo-row">
        Tipo: Entrada
    </div>

    <div class="line"></div>

    <div class="info-grid-full">
        <div class="label">Movimiento:</div>
        <div><?= e($movimientoTexto) ?></div>
    </div>

    <?php if ($documentoTexto !== ''): ?>

        <div class="info-grid-full documento-row">

            <div class="label">
                Documento:
            </div>

            <div class="documento-box">
                <?= e($documentoTexto) ?>
            </div>

        </div>

    <?php endif; ?>

    <div class="info-grid">

        <div class="label">Fecha:</div>
        <div><?= e($fechaImpresa) ?></div>

        <div class="label">Proveedor:</div>
        <div><?= e($proveedorNombre) ?></div>

    </div>

    <div class="info-grid">

        <div class="label">Almacén:</div>
        <div><?= e($almacenNombre) ?></div>

        <div class="label">Usuario:</div>
        <div><?= e($usuarioNombre) ?></div>

    </div>

    <div class="info-grid-full">

        <div class="label">Observaciones:</div>

        <div class="observaciones-box">
            <?= e($observaciones) ?>
        </div>

    </div>

    <div class="line"></div>

    <table>

        <thead>

            <tr>
                <th style="width: 55px;">Cantidad</th>
                <th style="width: 85px;">Clave</th>
                <th>Descripción</th>
                <th style="width: 70px;">Lote</th>
                <th style="width: 85px;">Ubicación</th>
                <th style="width: 65px;">Precio U.</th>
                <th style="width: 75px;">Importe</th>
            </tr>

        </thead>

        <tbody>

            <?php foreach ($entrada['detalles'] as $detalle): ?>

                <?php

                    $cantidad = (int)$detalle['cantidad'];
                    $costo = (float)$detalle['costo_unitario'];
                    $importe = $cantidad * $costo;

                ?>

                <tr>

                    <td class="text-center">
                        <?= $cantidad ?>
                    </td>

                    <td>
                        <?= e($detalle['codigo'] ?? '') ?>
                    </td>

                    <td>
                        <?= e($detalle['descripcion'] ?? '') ?>
                    </td>

                    <td>
                        <?= e($detalle['numero_lote'] ?? '') ?>
                    </td>

                    <td>
                        <?= e($detalle['ubicacion'] ?? '') ?>
                    </td>

                    <td class="text-right">
                        <?= number_format($costo, 2) ?>
                    </td>

                    <td class="text-right">
                        <?= number_format($importe, 2) ?>
                    </td>

                </tr>

            <?php endforeach; ?>

            <tr class="total-row">

                <td colspan="6" class="text-right">
                    Total
                </td>

                <td class="text-right">
                    <?= number_format($total, 2) ?>
                </td>

            </tr>

        </tbody>

    </table>

    <div class="footer-note">
        Movimiento Realizado !!!
    </div>

</div>

</body>
</html>