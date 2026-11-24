<?php

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/helpers/utils.php';
require_once __DIR__ . '/../app/controllers/SalidaController.php';

requireLogin();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id <= 0) {
    die('Movimiento no válido.');
}

$controller = new SalidaController();
$salida = $controller->obtenerSalida($id);

if (!$salida) {
    die('Salida no encontrada.');
}

$usuarioActual = currentUser();
$rolActual = strtoupper(
    trim((string) ($usuarioActual['rol'] ?? ''))
);

if ($rolActual === 'JEFE_ALMACEN') {
    $almacenUsuario = (int) (
        $usuarioActual['almacen_id'] ?? 0
    );
    $tipoSolicitud = strtoupper(
        trim((string) ($salida['solicitud_tipo'] ?? ''))
    );

    if (
        empty($salida['solicitud_origen_id'])
        || !in_array($tipoSolicitud, ['RESURTIDO', 'TICKET'], true)
        || $almacenUsuario <= 0
        || (int) ($salida['almacen_id'] ?? 0) !== $almacenUsuario
    ) {
        http_response_code(403);
        exit('Esta cuenta solamente puede imprimir salidas de Resurtidos y Tickets de su almacén.');
    }
}

$referenciaCompleta = trim($salida['referencia'] ?? '');
$partesReferencia = explode('|', $referenciaCompleta);
$movimientoTexto = trim($partesReferencia[0] ?? 'Salida de almacén');

$fechaImpresa = '';
if (!empty($salida['fecha'])) {
    $fechaImpresa = date('d/m/Y', strtotime($salida['fecha']));
}

function obtenerIniciales(string $nombreCompleto): string
{
    $nombreCompleto = trim($nombreCompleto);

    if ($nombreCompleto === '') {
        return '';
    }

    $partes = preg_split('/\s+/', $nombreCompleto);

    if (count($partes) >= 2) {
        $primeraInicial = strtoupper(substr($partes[0], 0, 1));
        $segundaInicial = strtoupper(substr($partes[1], 0, 1));

        return $primeraInicial . '.' . $segundaInicial . '.';
    }

    return strtoupper(substr($partes[0], 0, 1)) . '.';
}

$aliasUsuario = obtenerIniciales($salida['usuario_nombre'] ?? '');

// =============================================
// DETECTAR TIPO DE MOVIMIENTO PARA LAS FIRMAS
// USANDO el campo correcto: tipo_operacion
// =============================================
// IMPORTANTE: usar $salida['tipo_operacion'] que es el campo correcto en tu BD
$tipoOperacion = strtoupper(trim($salida['tipo_operacion'] ?? ''));
$mostrarFirmas = true;
$mostrarEntregado = false;
$mostrarFirmaGerente = false;
$mostrarDatosSolicitud = in_array(
    $tipoOperacion,
    ['RESURTIDO', 'TICKET'],
    true
);

$nombreQuienSolicito = trim(
    (string) (
        $salida['solicitud_verificador_nombre']
        ?? ''
    )
);

$nombreGerenteAutorizo = trim(
    (string) (
        $salida['solicitud_gerente_nombre']
        ?? ''
    )
);

/*
 * La salida guarda datos técnicos como "Folio ticket: ...", mientras que
 * la observación escrita en la tableta pertenece a la solicitud de origen.
 * Se muestran ambas para que la impresión y cualquier reimpresión histórica
 * conserven las indicaciones enviadas por el verificador.
 */
$observacionesMovimiento = trim(
    (string) ($salida['observaciones'] ?? '')
);

$observacionesSolicitud = trim(
    (string) (
        $salida['solicitud_observaciones']
        ?? ''
    )
);

$partesObservaciones = [];

if ($observacionesMovimiento !== '') {
    $partesObservaciones[] = $observacionesMovimiento;
}

if (
    $observacionesSolicitud !== ''
    && (
        $observacionesMovimiento === ''
        || stripos(
            $observacionesMovimiento,
            $observacionesSolicitud
        ) === false
    )
) {
    $partesObservaciones[] =
        'Solicitud: ' . $observacionesSolicitud;
}

$observacionesImpresion = implode(
    ' | ',
    $partesObservaciones
);

if ($observacionesImpresion === '') {
    $observacionesImpresion = 'Sin observaciones';
}

// Si es AJUSTE -> NO mostrar firmas
if ($tipoOperacion === 'AJUSTE') {
    $mostrarFirmas = false;
    $mostrarEntregado = false;
    $mostrarFirmaGerente = false;
} 
// Si es RESURTIDO -> mostrar 5 firmas (incluye Entregó y Firma del gerente)
elseif ($tipoOperacion === 'RESURTIDO') {
    $mostrarFirmas = true;
    $mostrarEntregado = true;
    $mostrarFirmaGerente = true;
}
// Para TICKET, TRASPASO, OTRO (solo 3 firmas, sin Entregado)
else {
    $mostrarFirmas = true;
    $mostrarEntregado = false;
    $mostrarFirmaGerente = false;
}

// Diagnóstico (opcional, eliminar después)
// echo "<!-- tipo_operacion = " . $tipoOperacion . ", mostrarEntregado = " . ($mostrarEntregado ? 'SI' : 'NO') . " -->";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Imprimir salida</title>

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

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9.5px;
            margin-top: 8px;
            table-layout: fixed;
        }

        th, td {
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

        .encabezado-th {
            border: none !important;
            background: #fff !important;
            padding: 0 0 8px 0 !important;
        }

        .header {
            display: grid;
            grid-template-columns: 78px 1fr 170px;
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
            font-size: 14px;
            font-weight: bold;
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

        .label {
            font-weight: bold;
        }

        .observaciones-box {
            grid-column: span 3;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .text-right {
            text-align: right;
        }

        .total-row td {
            font-weight: bold;
            background: #fafafa;
        }

        .firmas-box {
            margin-top: 45px;
            display: grid;
            gap: 28px;
            align-items: end;
        }
        
        /* Para 3 firmas (TICKET, TRASPASO, OTRO) */
        .firmas-3columnas {
            grid-template-columns: repeat(3, 1fr);
        }
        
        /* Para 5 firmas (SOLO RESURTIDO) */
        .firmas-5columnas {
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 18px;
        }

        .firma-item {
            text-align: center;
            font-size: 10px;
        }

        .firma-linea {
            border-top: 1px solid #111;
            height: 1px;
            margin-bottom: 6px;
        }

        .firma-titulo {
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 3px;
        }

        .firma-nombre {
            font-size: 9px;
            color: #333;
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

            thead {
                display: table-header-group;
            }

            tbody {
                display: table-row-group;
            }

            tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }

            .firmas-box,
            .footer-note {
                page-break-inside: avoid;
            }
        }
    </style>
</head>

<body>

<script>
function imprimirYLimpiar() {
    localStorage.removeItem('borradorSalida');
    window.print();
}
</script>

<div class="no-print">
    <button class="btn btn-primary" onclick="imprimirYLimpiar()">Imprimir</button>
    <a class="btn btn-secondary" href="javascript:history.back()">Regresar</a>

    <?php if ($rolActual !== 'JEFE_ALMACEN'): ?>
        <a class="btn btn-primary" href="salidas.php">Nueva salida</a>
    <?php endif; ?>
</div>

<div class="sheet">

    <table>
        <thead>
            <tr>
                <th colspan="4" class="encabezado-th">
                    <div class="header">
                        <div class="logo-box">
                            <img src="assets/img/logo.jpeg" alt="Logo G&D">
                        </div>

                        <div class="company-box">
                            <div class="company-name">DISTRIBUCIÓN G&D, S.A. DE C.V.</div>
                            <div>CP:</div>
                            <div>RFC: DGD151211PP5</div>
                        </div>

                        <div class="movement-box">
                            <h2>Movimientos al Inventario</h2>
                            <div>
                                Folio:
                                <span class="movement-number">
                                    <?= e($salida['folio']) ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="line"></div>

                    <div class="tipo-row">Tipo : Salida</div>

                    <div class="line"></div>

                    <div class="info-grid">
                        <div class="label">Movimiento:</div>
                        <div><?= e($movimientoTexto) ?></div>
                    </div>

                    <div class="info-grid">
                        <div class="label">Fecha:</div>
                        <div><?= e($fechaImpresa) ?></div>

                        <div class="label">Documento:</div>
                        <div><?= e($salida['tipo_operacion'] ?? '') ?></div>
                    </div>

                    <div class="info-grid">
                        <div class="label">Almacén:</div>
                        <div><?= e($salida['almacen_nombre'] ?? '') ?></div>

                        <div class="label">Usuario:</div>
                        <div><?= e($aliasUsuario) ?></div>
                    </div>

                    <?php if ($mostrarDatosSolicitud): ?>
                        <div class="info-grid">
                            <div class="label">Solicitó:</div>
                            <div>
                                <?= e(
                                    $nombreQuienSolicito !== ''
                                        ? $nombreQuienSolicito
                                        : 'Sin identificar'
                                ) ?>
                            </div>

                            <div class="label">Autorizó:</div>
                            <div>
                                <?= e(
                                    $nombreGerenteAutorizo !== ''
                                        ? $nombreGerenteAutorizo
                                        : 'Sin información'
                                ) ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="info-grid">
                        <div class="label">Observaciones:</div>
                        <div class="observaciones-box"><?= e($observacionesImpresion) ?></div>
                    </div>

                    <div class="line"></div>
                </th>
            </tr>

            <tr>
                <th style="width: 55px;">Cantidad</th>
                <th style="width: 90px;">Clave</th>
                <th>Descripción</th>
                <th style="width: 75px;">Ubicación</th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ($salida['detalles'] as $detalle): ?>
                <tr>
                    <td><?= (int)$detalle['cantidad'] ?></td>
                    <td><?= e($detalle['codigo']) ?></td>
                    <td><?= e($detalle['descripcion']) ?></td>
                    <td><?= e($detalle['ubicacion']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($mostrarFirmas): ?>
        <div class="firmas-box <?= $mostrarFirmaGerente ? 'firmas-5columnas' : 'firmas-3columnas' ?>">
            <!-- Surtió (siempre) -->
            <div class="firma-item">
                <div class="firma-linea"></div>
                <div class="firma-titulo">Surtió</div>
                <div class="firma-nombre">Nombre y firma</div>
            </div>

            <!-- Verificó (siempre) -->
            <div class="firma-item">
                <div class="firma-linea"></div>
                <div class="firma-titulo">Verificó</div>
                <div class="firma-nombre">Nombre y firma</div>
            </div>

            <!-- Entregó (SOLO si es RESURTIDO) -->
            <?php if ($mostrarEntregado): ?>
                <div class="firma-item">
                    <div class="firma-linea"></div>
                    <div class="firma-titulo">Entregó</div>
                    <div class="firma-nombre">Nombre y firma</div>
                </div>
            <?php endif; ?>
            
            <!-- Recibió (siempre) -->
            <div class="firma-item">
                <div class="firma-linea"></div>
                <div class="firma-titulo">Recibió</div>
                <div class="firma-nombre">Nombre y firma</div>
            </div>

            <!-- Firma del gerente (SOLO si es RESURTIDO) -->
            <?php if ($mostrarFirmaGerente): ?>
                <div class="firma-item">
                    <div class="firma-linea"></div>
                    <div class="firma-titulo">Firma del gerente</div>
                    <div class="firma-nombre">Nombre y firma</div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="footer-note">
        Movimiento Realizado !!!
    </div>

</div>

</body>
</html>
