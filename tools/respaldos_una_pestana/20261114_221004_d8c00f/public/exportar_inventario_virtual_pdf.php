<?php

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/helpers/utils.php';
require_once __DIR__ . '/../app/controllers/InventarioFisicoVirtualController.php';

requireLogin();

$controller = new InventarioFisicoVirtualController();
$controller->verificarAcceso();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$conteo = $controller->obtenerConteo($id);
$detalle = $controller->obtenerDetalle($id);

if (!$conteo) {
    die('Inventario no encontrado.');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Inventario Virtual</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 10mm;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            color: #111;
        }

        h2 {
            text-align: center;
            margin-bottom: 5px;
        }

        .info {
            margin-bottom: 15px;
            border: 1px solid #444;
            padding: 8px;
        }

        .info div {
            margin-bottom: 4px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: #0f172a;
            color: #fff;
            padding: 6px;
            border: 1px solid #333;
        }

        td {
            padding: 5px;
            border: 1px solid #333;
        }

        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        .no-print {
            margin-bottom: 15px;
        }

        .btn-print {
            background: #0f4c81;
            color: white;
            border: none;
            padding: 10px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
        }

        @media print {
            .no-print {
                display: none;
            }
        }
    </style>
</head>
<body>

<div class="no-print">
    <button onclick="window.print()" class="btn-print">Imprimir / Guardar PDF</button>
</div>

<h2>INVENTARIO VIRTUAL TUXTLA</h2>

<div class="info">
    <div><strong>Folio:</strong> <?= e($conteo['folio']) ?></div>
    <div><strong>Fecha:</strong> <?= e($conteo['created_at']) ?></div>
    <div><strong>Estado:</strong> <?= e($conteo['estado']) ?></div>
    <div><strong>Observaciones:</strong> <?= e($conteo['observaciones'] ?? '') ?></div>
</div>

<table>
    <thead>
        <tr>
            <th>Artículo</th>
            <th>Descripción</th>
            <th>Mostrador</th>
            <th>Piqueo</th>
            <th>Almacén</th>
            <th>Bodega</th>
            <th>Total</th>
            <th>Fecha captura</th>
        </tr>
    </thead>

    <tbody>
        <?php if (empty($detalle)): ?>
            <tr>
                <td colspan="8" class="text-center">No hay productos capturados.</td>
            </tr>
        <?php else: ?>
            <?php foreach ($detalle as $row): ?>
                <tr>
                    <td><?= e($row['codigo_barras']) ?></td>
                    <td><?= e($row['descripcion']) ?></td>
                    <td class="text-right"><?= (int)$row['mostrador'] ?></td>
                    <td class="text-right"><?= (int)$row['piqueo'] ?></td>
                    <td class="text-right"><?= (int)$row['almacen'] ?></td>
                    <td class="text-right"><?= (int)$row['bodega'] ?></td>
                    <td class="text-right"><?= (int)$row['total'] ?></td>
                    <td><?= e($row['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

</body>
</html>