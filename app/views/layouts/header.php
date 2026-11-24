<?php

require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/utils.php';

$user = currentUser();

$moduleCss = $moduleCss ?? null;

$rolUsuario = strtoupper(
    trim($user['rol'] ?? '')
);

$almacenId = (int) (
    $user['almacen_id'] ?? 0
);

$esAdmin = $rolUsuario === 'ADMINISTRADOR';
$esGerente = $rolUsuario === 'GERENTE';
$esEncargado = $rolUsuario === 'ENCARGADO';
$esJefeAlmacen = $rolUsuario === 'JEFE_ALMACEN';
$esConsulta = $rolUsuario === 'CONSULTA';

// ======================================================
// PERMISOS DEL MENÚ
// ======================================================

$puedeVerAgotados = in_array(
    $rolUsuario,
    [
        'ADMINISTRADOR',
        'GERENTE',
        'ENCARGADO',
        'CONSULTA'
    ],
    true
);

$puedeVerInventarios =
    !$esJefeAlmacen
    && (
        $esAdmin
        || $esGerente
        || $esEncargado
        || $esConsulta
        || $almacenId === 1
        || $almacenId === 2
        || $almacenId === 3
    );

$puedeVerResurtidos = in_array(
    $rolUsuario,
    [
        'ADMINISTRADOR',
        'GERENTE',
        'ENCARGADO',
        'JEFE_ALMACEN'
    ],
    true
);

/*
 * Solo el ADMINISTRADOR y el ENCARGADO reciben
 * notificaciones de solicitudes pendientes.
 */
$puedeRecibirResurtidos =
    $esAdmin
    || $esEncargado
    || $esJefeAlmacen;

$esGerenteCiudadHidalgo =
    $esGerente
    && $almacenId === 1;

$puedeVerInventarioVirtual =
    $puedeVerInventarios
    && !$esGerenteCiudadHidalgo;

$puedeVerTickets =
    $esAdmin
    || (
        $almacenId === 1
        && ($esGerente || $esEncargado || $esJefeAlmacen)
    );

$puedeRecibirTickets =
    $esAdmin
    || (($esEncargado || $esJefeAlmacen) && $almacenId === 1);

$puedeVerDevoluciones =
    $esAdmin
    || $esEncargado
    || $esGerente;

?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Sistema de Almacén de Farmacia</title>

    <link
        rel="stylesheet"
        href="assets/css/global.css"
    >

    <?php if (!empty($moduleCss)): ?>

        <link
            rel="stylesheet"
            href="assets/css/<?= e($moduleCss) ?>.css?v=<?= time() ?>"
        >

    <?php endif; ?>

    <style>
        /* ==============================================
           ENLACES, CONTADORES Y ALERTAS MÓVILES
        ============================================== */

        .nav-resurtidos {
            position: relative;
            display: inline-flex !important;
            align-items: center;
            gap: 7px;
        }

        .contador-resurtidos {
            display: inline-flex;
            min-width: 21px;
            height: 21px;
            align-items: center;
            justify-content: center;
            padding: 0 6px;
            border: 2px solid #ffffff;
            border-radius: 999px;
            background: #dc3545;
            color: #ffffff;
            font-size: 11px;
            font-weight: 800;
            line-height: 1;
            box-shadow:
                0 2px 6px rgba(220, 53, 69, 0.3);
        }

        .contador-resurtidos[hidden] {
            display: none !important;
        }

        .contador-resurtidos.nuevas {
            animation:
                resurtidoPulso 1.2s ease-in-out 2;
        }

        @keyframes resurtidoPulso {
            0%,
            100% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.2);
            }
        }

        .btn-alertas-moviles {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-height: 34px;
            padding: 7px 11px;
            border: 1px solid rgba(255, 255, 255, 0.65);
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.14);
            color: #ffffff;
            font: inherit;
            font-size: 12px;
            font-weight: 800;
            cursor: pointer;
            transition:
                background 0.2s ease,
                color 0.2s ease,
                transform 0.15s ease;
        }

        .btn-alertas-moviles:hover {
            background: rgba(255, 255, 255, 0.24);
        }

        .btn-alertas-moviles:active {
            transform: scale(0.97);
        }

        .btn-alertas-moviles.alertas-activas {
            border-color: #fde68a;
            background: #fef3c7;
            color: #92400e;
            box-shadow:
                0 0 0 3px rgba(253, 230, 138, 0.22);
        }

        .alerta-movil-toast {
            position: fixed;
            z-index: 10050;
            right: 18px;
            bottom: 18px;
            display: grid;
            grid-template-columns: auto 1fr auto;
            align-items: center;
            gap: 11px;
            width: min(420px, calc(100vw - 28px));
            padding: 14px 15px;
            border: 1px solid #93c5fd;
            border-radius: 14px;
            background: #ffffff;
            color: #172033;
            text-align: left;
            box-shadow:
                0 18px 42px rgba(15, 23, 42, 0.28);
            cursor: pointer;
            animation: alertaMovilEntrada 0.25s ease-out;
        }

        .alerta-movil-toast.alerta-nueva {
            border-left: 7px solid #dc3545;
        }

        .alerta-movil-toast.alerta-estado {
            border-left: 7px solid #16a34a;
        }

        .alerta-movil-toast.alerta-recordatorio {
            border-color: #f59e0b;
            border-left: 7px solid #d97706;
            background: #fffbeb;
        }

        .alerta-movil-icono {
            font-size: 25px;
        }

        .alerta-movil-contenido {
            display: grid;
            gap: 4px;
        }

        .alerta-movil-contenido strong {
            color: #0f4c81;
            font-size: 15px;
        }

        .alerta-movil-contenido small {
            color: #475569;
            font-size: 12px;
            line-height: 1.4;
        }

        .alerta-movil-cerrar {
            color: #64748b;
            font-size: 24px;
            line-height: 1;
        }

        @keyframes alertaMovilEntrada {
            from {
                opacity: 0;
                transform: translateY(18px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (max-width: 720px) {
            .topbar-right {
                justify-content: flex-end;
            }

            .btn-alertas-moviles {
                order: 3;
                width: 100%;
            }

            .alerta-movil-toast {
                right: 14px;
                bottom: 14px;
            }
        }
    </style>
</head>

<body>

<header class="topbar">

    <div class="topbar-left">

        <h1>
            SISTEMA DE ALMACÉN - FARMACIA
        </h1>

    </div>

    <div class="topbar-right">

        <?php if ($user): ?>

            <?php if (
                $puedeRecibirResurtidos
                || $puedeRecibirTickets
            ): ?>

                <button
                    type="button"
                    id="botonAlertasMoviles"
                    class="btn-alertas-moviles"
                    aria-pressed="false"
                    title="Activar timbre fuerte y vibración"
                >
                    <span aria-hidden="true">🔔</span>
                    <span class="texto-alertas">
                        Activar timbre
                    </span>
                </button>

            <?php endif; ?>

            <span>
                <?= e($user['nombre'] ?? '') ?>

                |

                <?= e($user['rol'] ?? '') ?>
            </span>

            <a
                href="logout.php"
                class="btn-top"
            >
                Cerrar sesión
            </a>

        <?php endif; ?>

    </div>

</header>

<?php if ($user): ?>

    <nav class="navbar">

        <?php if (!$esJefeAlmacen): ?>

            <a href="dashboard.php">
                Inicio
            </a>

        <?php endif; ?>

        <?php if (!$esGerente && !$esConsulta && !$esJefeAlmacen): ?>

            <a href="productos.php">
                Productos
            </a>

            <a href="entradas.php">
                Entradas
            </a>

            <a href="salidas.php">
                Salidas
            </a>

        <?php endif; ?>

        <?php if (!$esJefeAlmacen): ?>

            <a href="existencias.php">
                Existencias
            </a>

        <?php endif; ?>

        <?php if ($puedeVerAgotados): ?>

            <a href="agotados.php">
                Agotados
            </a>

        <?php endif; ?>

        <?php if ($puedeVerResurtidos): ?>

            <a
                href="resurtidos.php"
                class="nav-resurtidos"
                id="enlaceResurtidos"
            >
                <span>Resurtidos</span>

                <?php if ($puedeRecibirResurtidos): ?>

                    <span
                        id="contadorResurtidos"
                        class="contador-resurtidos"
                        aria-label="Solicitudes de resurtido pendientes"
                        title="Solicitudes pendientes"
                        hidden
                    >
                        0
                    </span>

                <?php endif; ?>
            </a>

        <?php endif; ?>

        <?php if ($puedeVerTickets): ?>

            <a
                href="tickets.php"
                class="nav-resurtidos"
                id="enlaceTickets"
            >
                <span>Tickets</span>

                <?php if ($puedeRecibirTickets): ?>

                    <span
                        id="contadorTickets"
                        class="contador-resurtidos"
                        aria-label="Tickets pendientes"
                        title="Tickets pendientes"
                        hidden
                    >
                        0
                    </span>

                <?php endif; ?>
            </a>

        <?php endif; ?>

        <?php if ($puedeVerDevoluciones): ?>

            <a href="devoluciones.php">
                Devoluciones
            </a>

        <?php endif; ?>

        <?php if (!$esGerente && !$esConsulta && !$esJefeAlmacen): ?>

            <a href="kardex.php">
                Kardex
            </a>

            <a href="reportes.php">
                Reportes
            </a>

            <a href="historial_entradas.php">
                Historial de Entradas
            </a>

            <a href="historial_salidas.php">
                Historial Salidas
            </a>

        <?php endif; ?>

        <?php if ($puedeVerInventarios): ?>

            <a href="inventario_fisico.php">
                Inventario Físico
            </a>

        <?php endif; ?>

        <?php if ($puedeVerInventarioVirtual): ?>

            <a href="inventario_virtual.php">
                Inventario Virtual
            </a>

            <a href="inventario_virtual_historial.php">
                Historial Virtual
            </a>

        <?php endif; ?>

        <?php if ($esAdmin): ?>

            <a href="historial_movimientos.php">
                Historial de Movimientos
            </a>

            <a href="usuario.php">
                Usuarios
            </a>

        <?php endif; ?>

        <?php if ($esAdmin || $esEncargado): ?>

            <a href="respaldos.php">
                Respaldos
            </a>

        <?php endif; ?>

    </nav>

<?php endif; ?>

<?php if (
    $user
    && (
        $puedeRecibirResurtidos
        || $puedeRecibirTickets
    )
): ?>

    <script>
        window.AlmacenNotificacionesConfig = {
            usuarioId: <?= (int) ($user['id'] ?? 0) ?>,
            recibeResurtidos:
                <?= $puedeRecibirResurtidos ? 'true' : 'false' ?>,
            recibeTickets:
                <?= $puedeRecibirTickets ? 'true' : 'false' ?>,
            endpoint: 'api_notificaciones_pendientes.php',
            serviceWorker: 'sw-notificaciones.js',
            intervalo: 5000,
            recordatorio: 180000
        };
    </script>

    <script
        src="assets/js/notificaciones-movil.js?v=<?= time() ?>"
        defer
    ></script>

<?php endif; ?>

<main class="main-content">
