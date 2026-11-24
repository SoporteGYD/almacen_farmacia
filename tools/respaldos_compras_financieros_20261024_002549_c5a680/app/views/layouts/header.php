<?php

require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/utils.php';

$user = currentUser();
// OCAV_BADGES_V1: badges del flujo de órdenes; siempre derivados de base de datos.
$ocavBadge=['almacen'=>0,'compras'=>0];
if ($user) {
    try {
        require_once __DIR__.'/../../helpers/erp_oc_avisos.php';
        $ocavBadge=ocav_conteos((new Database())->connect(), $user);
    } catch (Throwable $e) { error_log('OCAV badges: '.$e->getMessage()); }
}

$moduleCss = $moduleCss ?? null;

$rolUsuario = strtoupper(
    trim($user['rol'] ?? '')
);

$almacenId = (int) (
    $user['almacen_id'] ?? 0
);

// DIRECCION tiene el mismo menú que ADMINISTRADOR; mantiene su etiqueta real.
$esAdmin = in_array($rolUsuario, ['ADMINISTRADOR', 'DIRECCION'], true);
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
        'DIRECCION',
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
        'DIRECCION',
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

// Menu por rol: conserva los permisos originales y solo reorganiza enlaces.
// El acceso real se valida tambien en cada pagina; ocultar un enlace no concede permisos.
$erpEstaHabilitado = in_array($rolUsuario, ['ADMINISTRADOR','ADMIN','DIRECCION','GERENTE','CONSULTA','COMPRAS'], true);
$menuEsAdmin = in_array($rolUsuario, ['ADMINISTRADOR','ADMIN','DIRECCION'], true);
$menuEsCompras = $rolUsuario === 'COMPRAS';
$menuSinAlmacen = $menuEsCompras;
$menuTitulo = $erpEstaHabilitado ? 'ERP G&D' : 'Menú Almacén';
$menuSecciones = [];
$menuAgregar = static function (string $grupo, string $titulo, string $ruta, bool $permitido = true) use (&$menuSecciones): void {
    if (!$permitido) return;
    if (!isset($menuSecciones[$grupo])) $menuSecciones[$grupo] = [];
    $menuSecciones[$grupo][] = ['titulo' => $titulo, 'ruta' => $ruta];
};
$menuPaginaActual = basename(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');

// General: ahora Inicio y Productos van DENTRO del menu.
$menuAgregar('🏠 General', 'Inicio / Dashboard', 'dashboard.php', !$esJefeAlmacen && !$menuEsCompras);
$menuAgregar('🏠 General', 'Productos', 'productos.php', !$esGerente && !$esConsulta && !$esJefeAlmacen && !$menuEsCompras);
$menuAgregar('🏠 General', 'Punto de Venta', 'pos.php', $menuEsAdmin);
$menuAgregar('🏠 General', 'Precios y stock POS', 'pos_configurar.php', $menuEsAdmin);
$menuAgregar('🏠 General', 'Conteo y mínimos de Farmacia', 'pos_ubicaciones_config.php', $menuEsAdmin);

// Operacion. Tickets y Resurtidos no se incluyen porque permanecen FUERA.
$menuAgregar('📦 Operación de almacén', 'Entradas', 'entradas.php', !$esGerente && !$esConsulta && !$esJefeAlmacen && !$menuEsCompras);
$menuAgregar('📦 Operación de almacén', 'Salidas', 'salidas.php', !$esGerente && !$esConsulta && !$esJefeAlmacen && !$menuEsCompras);
$menuAgregar('📦 Operación de almacén', 'Devoluciones', 'devoluciones.php', $puedeVerDevoluciones && !$menuEsCompras);

// Existencias y conteos.
$menuAgregar('📊 Inventarios', 'Existencias', 'existencias.php', !$esJefeAlmacen && !$menuEsCompras);
$menuAgregar('📊 Inventarios', 'Agotados', 'agotados.php', $puedeVerAgotados && !$menuEsCompras);
$menuAgregar('📊 Inventarios', 'Inventario Físico', 'inventario_fisico.php', $puedeVerInventarios && !$menuEsCompras);
$menuAgregar('📊 Inventarios', 'Inventario Virtual', 'inventario_virtual.php', $puedeVerInventarioVirtual && !$menuEsCompras);
$menuAgregar('📊 Inventarios', 'Historial Virtual', 'inventario_virtual_historial.php', $puedeVerInventarioVirtual && !$menuEsCompras);
$menuAgregar('📊 Inventarios', 'Conteo de Conciliación', 'erp_conciliacion_gestion.php', $puedeVerInventarios && in_array($rolUsuario,['ENCARGADO','JEFE_ALMACEN','GERENTE'],true));

// Historicos del almacen.
$menuAgregar('📁 Historiales y reportes', 'Kardex de Almacén', 'kardex.php', !$esGerente && !$esConsulta && !$esJefeAlmacen && !$menuEsCompras);
$menuAgregar('📁 Historiales y reportes', 'Reportes de Almacén', 'reportes.php', !$esGerente && !$esConsulta && !$esJefeAlmacen && !$menuEsCompras);
$menuAgregar('📁 Historiales y reportes', 'Historial de Entradas', 'historial_entradas.php', !$esGerente && !$esConsulta && !$esJefeAlmacen && !$menuEsCompras);
$menuAgregar('📁 Historiales y reportes', 'Historial de Salidas', 'historial_salidas.php', !$esGerente && !$esConsulta && !$esJefeAlmacen && !$menuEsCompras);
$menuAgregar('📁 Historiales y reportes', 'Historial de Movimientos', 'historial_movimientos.php', $menuEsAdmin);

// ERP: no se muestra al ENCARGADO (su menu es solo de Almacen).
$menuAgregar('🛍️ Ventas y gerencia', 'Dashboard Gerencial · Ventas y Utilidades', 'erp_dashboard_gerencial.php', $menuEsAdmin);
$menuAgregar('🛍️ Ventas y gerencia', 'Eficiencia de Cajeros', 'erp_eficiencia_cajeros.php', $menuEsAdmin);
$menuAgregar('🛍️ Ventas y gerencia', 'Diagnóstico de Tiempos', 'erp_eficiencia_diagnostico.php', $menuEsAdmin);
$menuAgregar('🛍️ Ventas y gerencia', 'Control de Cajas y Arqueos', 'erp_control_cajas.php', $menuEsAdmin);
$menuAgregar('🛍️ Ventas y gerencia', 'Cancelaciones y Devoluciones POS', 'erp_pos_reversiones.php', $menuEsAdmin);
$menuAgregar('🛍️ Ventas y gerencia', 'Devoluciones desde Almacén', 'erp_pos_devoluciones_almacen.php', $menuEsAdmin);

$menuAgregar('🧾 Compras y proveedores', 'Catálogo Maestro de Productos', 'erp_catalogo_maestro.php', $erpEstaHabilitado);
$menuAgregar('🧾 Compras y proveedores', 'Proveedores', 'erp_proveedores.php', $erpEstaHabilitado);
$menuAgregar('🧾 Compras y proveedores', 'Inicio de Compras', 'erp_compras_inicio.php', $menuEsCompras || $menuEsAdmin);
$menuAgregar('🧾 Compras y proveedores', 'Análisis de compras', 'erp_analisis_compras.php', $menuEsCompras || $menuEsAdmin);
$menuAgregar('🧾 Compras y proveedores', 'Órdenes de Compra', 'erp_ordenes_compra.php', $erpEstaHabilitado);
$menuAgregar('🧾 Compras y proveedores', 'Autorizaciones de Compra', 'erp_oc_autorizaciones.php', $menuEsAdmin || $menuEsCompras);
$menuAgregar('🧾 Compras y proveedores', 'Mi correo de trabajo', 'erp_mi_correo.php', $menuEsAdmin || $menuEsCompras);
$menuAgregar('🧾 Compras y proveedores', 'Enviar orden de compra', 'erp_oc_enviar.php', $menuEsAdmin || $menuEsCompras);
$menuAgregar('🧾 Compras y proveedores', 'Entrada General por Factura', 'erp_facturas.php', $menuEsAdmin || $menuEsCompras || $esGerente);
$menuAgregar('🧾 Compras y proveedores', 'Verificación de Facturas', 'erp_facturas_verificacion.php', $menuEsAdmin || $menuEsCompras || $esGerente);
$menuAgregar('🧾 Compras y proveedores', 'Cuentas por Pagar', 'erp_cuentas_por_pagar.php', $menuEsAdmin || $menuEsCompras);
$menuAgregar('🧾 Compras y proveedores', 'Tesorería y Calendario de Pagos', 'erp_tesoreria.php', $menuEsAdmin || $menuEsCompras);
$menuAgregar('🧾 Compras y proveedores', 'Flujo de Efectivo, Bancos y Presupuestos', 'erp_flujo_efectivo.php', $menuEsAdmin);

$menuAgregar('🚚 Recepciones', 'Pendientes de Recepción', 'erp_recepciones_pendientes.php', $erpEstaHabilitado);
$menuAgregar('🚚 Recepciones', 'Historial de Recepciones', 'erp_recepciones_historial.php', $erpEstaHabilitado);

$menuAgregar('🧮 Control y conciliación', 'Conciliación de Inventarios', 'erp_conciliacion_inventarios.php', $menuEsAdmin);
$menuAgregar('🧮 Control y conciliación', 'Reservas de Almacén', 'erp_reservas_almacen.php', $menuEsAdmin);
$menuAgregar('🧮 Control y conciliación', 'Conteos y Ajustes Autorizados', 'erp_conciliacion_gestion.php', $menuEsAdmin);
$menuAgregar('🧮 Control y conciliación', 'Kardex General Unificado', 'erp_kardex_general.php', $menuEsAdmin);
$menuAgregar('🧮 Control y conciliación', 'Recepción de Resurtidos al POS', 'erp_resurtidos_pos.php', $menuEsAdmin || $esGerente);

$menuAgregar('⚙️ Administración', 'Usuarios', 'usuario.php', $menuEsAdmin);
$menuAgregar('⚙️ Administración', 'Respaldos', 'respaldos.php', $menuEsAdmin || $esEncargado);
// El ENCARGADO conserva todos sus accesos permitidos, pero distribuidos
// en tres grupos compactos. Se toman de la lista anterior: no se otorgan
// permisos ni se eliminan rutas de otros roles.
if ($esEncargado) {
    $menuEncargadoPorRuta = [];
    foreach ($menuSecciones as $itemsGrupo) {
        foreach ($itemsGrupo as $itemGrupo) {
            $menuEncargadoPorRuta[$itemGrupo['ruta']] = $itemGrupo;
        }
    }
    $menuEncargadoOrden = [
        '🏠 Operación del Almacén' => [
            'dashboard.php', 'productos.php', 'entradas.php',
            'salidas.php', 'devoluciones.php',
        ],
        '📦 Existencias y Conteos' => [
            'existencias.php', 'agotados.php', 'inventario_fisico.php',
            'inventario_virtual.php', 'inventario_virtual_historial.php',
            'erp_conciliacion_gestion.php',
        ],
        '📊 Historial, Reportes y Herramientas' => [
            'kardex.php', 'reportes.php', 'historial_entradas.php',
            'historial_salidas.php', 'respaldos.php',
        ],
    ];
    $menuEncargadoAgrupado = [];
    foreach ($menuEncargadoOrden as $grupoNuevo => $rutasGrupo) {
        foreach ($rutasGrupo as $rutaGrupo) {
            if (isset($menuEncargadoPorRuta[$rutaGrupo])) {
                $menuEncargadoAgrupado[$grupoNuevo][] = $menuEncargadoPorRuta[$rutaGrupo];
                unset($menuEncargadoPorRuta[$rutaGrupo]);
            }
        }
    }
    // Si se añaden módulos permitidos en el futuro, nunca ocultarlos:
    // se integran automáticamente a la última categoría.
    if ($menuEncargadoPorRuta) {
        foreach ($menuEncargadoPorRuta as $itemRestante) {
            $menuEncargadoAgrupado['📊 Historial, Reportes y Herramientas'][] = $itemRestante;
        }
    }
    $menuSecciones = $menuEncargadoAgrupado;
}
$menuNumeroEnlaces = array_sum(array_map('count', $menuSecciones));
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

        /* ERP G&D: menú administrativo organizado y adaptable a la pantalla.
           Solo cambia la presentación: los enlaces y sus condiciones PHP se conservan. */
        .navbar .erp-nav {
            position: relative;
            display: inline-flex;
            align-items: center;
            flex: 0 0 auto;
        }
        .navbar .erp-nav > summary {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            list-style: none;
            cursor: pointer;
            background: #1456a0;
            border: 1px solid #1456a0;
            border-radius: 10px;
            color: #fff;
            padding: 10px 14px;
            font-weight: 800;
            user-select: none;
            min-height: 42px;
        }
        .navbar .erp-nav > summary::-webkit-details-marker { display: none; }
        .navbar .erp-nav > summary:hover,
        .navbar .erp-nav[open] > summary { background: #0b427f; }
        .navbar .erp-nav > summary:focus-visible {
            outline: 3px solid #facc15;
            outline-offset: 3px;
        }
        .navbar .erp-nav > summary .erp-nav-arrow {
            display: inline-block;
            transition: transform .18s ease;
        }
        .navbar .erp-nav[open] > summary .erp-nav-arrow { transform: rotate(180deg); }

        .navbar .erp-nav-menu {
            position: absolute;
            z-index: 10020;
            top: calc(100% + 8px);
            left: 0;
            width: min(960px, calc(100vw - 32px));
            max-height: min(640px, 68dvh);
            overflow-y: auto;
            overflow-x: hidden;
            overscroll-behavior: contain;
            padding: 14px;
            background: #fff;
            border: 1px solid #d6e3f2;
            border-radius: 16px;
            box-shadow: 0 18px 45px rgba(11, 38, 72, .22);
            color: #163b61;
            scrollbar-width: thin;
            scrollbar-color: #a2bad8 #f2f6fb;
            text-align: left;
        }
        .navbar .erp-nav-menu::-webkit-scrollbar { width: 8px; }
        .navbar .erp-nav-menu::-webkit-scrollbar-track { background: #f2f6fb; }
        .navbar .erp-nav-menu::-webkit-scrollbar-thumb {
            background: #a2bad8;
            border-radius: 8px;
        }
        .navbar .erp-nav-menu .erp-menu-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 2px 4px 12px;
            border-bottom: 1px solid #e8eef7;
            margin-bottom: 12px;
        }
        .navbar .erp-nav-menu .erp-menu-title strong {
            font-size: 15px;
            font-weight: 850;
            color: #123b65;
        }
        .navbar .erp-nav-menu .erp-menu-title small {
            font-size: 12px;
            color: #647a95;
        }
        .navbar .erp-nav-menu .erp-menu-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            align-items: start;
        }
        .navbar .erp-nav-menu .erp-menu-section {
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 3px;
            border: 1px solid #e3ecf6;
            background: #f8fbff;
            border-radius: 12px;
            padding: 10px;
        }
        .navbar .erp-nav-menu .erp-menu-heading {
            color: #2b5c8b;
            font-size: 11px;
            font-weight: 850;
            letter-spacing: .07em;
            text-transform: uppercase;
            padding: 5px 7px 9px;
            margin: 0 0 5px;
            border-bottom: 1px solid #dfeaf6;
        }
        /* El CSS antiguo de .navbar a usa !important: se neutraliza solo aquí. */
        .navbar .erp-nav-menu .erp-menu-section a {
            display: block !important;
            box-sizing: border-box;
            width: 100%;
            min-width: 0;
            margin: 0;
            padding: 10px 9px !important;
            color: #173e69 !important;
            background: transparent !important;
            border: 0 !important;
            border-radius: 8px !important;
            text-decoration: none !important;
            font-size: 13px;
            line-height: 1.35;
            font-weight: 650 !important;
            white-space: normal !important;
            overflow-wrap: break-word;
        }
        .navbar .erp-nav-menu .erp-menu-section a:hover,
        .navbar .erp-nav-menu .erp-menu-section a:focus-visible {
            color: #0e4d91 !important;
            background: #e5f0ff !important;
        }
        .navbar .erp-nav-menu .erp-menu-section a:focus-visible {
            outline: 2px solid #2875c4;
            outline-offset: -2px;
        }
        @media (min-width: 761px) and (max-width: 1100px) {
            .navbar .erp-nav-menu .erp-menu-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .navbar .erp-nav-menu { width: min(680px, calc(100vw - 32px)); }
        }
        @media (max-width: 760px) {
            .navbar .erp-nav-menu {
                width: min(410px, calc(100vw - 24px));
                max-height: 70dvh;
                padding: 10px;
                border-radius: 12px;
            }
            .navbar .erp-nav-menu .erp-menu-grid { grid-template-columns: 1fr; }
            .navbar .erp-nav-menu .erp-menu-title { align-items: flex-start; }
            .navbar .erp-nav-menu .erp-menu-title small { display: none; }
            .navbar .erp-nav-menu .erp-menu-section a { font-size: 14px; }
        }

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
        /* Un único desplegable para todos los módulos; Resurtidos y Tickets quedan fuera. */
        .navbar.gyd-menu-principal {
            display: flex !important;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px !important;
            padding: 9px 20px !important;
            overflow: visible !important;
        }
        .navbar.gyd-menu-principal .gyd-menu-desplegable > summary {
            background: linear-gradient(110deg, #174d88, #1d68c4) !important;
            border-color: #165caa;
            color: #fff !important;
            padding: 10px 16px !important;
            border-radius: 12px !important;
            min-height: 44px;
        }
        .navbar.gyd-menu-principal .gyd-menu-acceso-directo {
            display: inline-flex !important;
            align-items: center;
            gap: 8px;
            min-height: 44px;
            font-weight: 750 !important;
            color: #16487e !important;
            background: #eef5ff !important;
            border: 1px solid #d3e3f7 !important;
            border-radius: 12px !important;
            white-space: nowrap;
        }
        .navbar.gyd-menu-principal .gyd-menu-acceso-directo:hover {
            background: #dfeeff !important;
            color: #123c72 !important;
        }
        .navbar .gyd-menu-panel {
            width: min(1160px, calc(100vw - 24px));
            padding: 16px;
            max-height: calc(100dvh - 110px);
            scrollbar-gutter: stable;
        }
        .navbar .gyd-menu-panel .gyd-menu-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
        }
        .gyd-menu-titulo { display: flex; flex-direction: column; gap: 3px; }
        .gyd-menu-total { font-size: 12px; font-weight: 700; color: #496b94; white-space: nowrap; }
        .gyd-menu-busqueda { margin: 0 1px 14px; }
        .gyd-menu-label { display: block; font: 700 12px system-ui, sans-serif; color: #526c8c; margin-bottom: 5px; }
        .gyd-menu-busqueda input {
            width: 100%; padding: 11px 13px;
            border: 1px solid #cbdcf0; border-radius: 10px;
            color: #193958; background: #fff; font: 14px system-ui, sans-serif;
            outline-offset: 2px;
        }
        .gyd-menu-busqueda input:focus { border-color: #2673c2; outline: 2px solid #cde4ff; }
        .navbar .gyd-menu-panel .gyd-menu-seccion { padding: 12px; gap: 3px; }
        .navbar .gyd-menu-panel .gyd-menu-seccion a { padding: 10px !important; line-height: 1.35; }
        .navbar .gyd-menu-panel .gyd-menu-seccion a.gyd-menu-activo {
            background: #e1eeff !important; color: #0d4b91 !important;
            box-shadow: inset 3px 0 #2471c6;
        }
        .navbar .erp-nav-menu .gyd-menu-seccion[hidden],
        .navbar .erp-nav-menu .gyd-menu-seccion a[hidden],
        .navbar .erp-nav-menu .gyd-menu-vacio[hidden] { display: none !important; }
        .gyd-menu-vacio { margin: 10px; text-align: center; font-size: 14px; color: #627891; }
        @media (max-width: 1000px) {
            .navbar .gyd-menu-panel .gyd-menu-grid { grid-template-columns: repeat(2,minmax(0,1fr)); }
        }
        @media (max-width: 690px) {
            .navbar.gyd-menu-principal { padding: 9px 12px !important; gap: 7px !important; }
            .navbar.gyd-menu-principal .gyd-menu-desplegable > summary,
            .navbar.gyd-menu-principal .gyd-menu-acceso-directo {
                padding: 9px 11px !important; min-height: 40px; font-size: 13px;
            }
            .navbar .gyd-menu-panel { padding: 12px; width: calc(100vw - 20px); }
            .navbar .gyd-menu-panel .gyd-menu-grid { grid-template-columns: 1fr; }
            .navbar .gyd-menu-panel .erp-menu-title small { display: inline; }
        }

        /* ADMINISTRADOR / DIRECCION / COMPRAS / GERENTE:
           columnas fluidas, sin filas de altura igual ni espacios en blanco
           bajo las tarjetas mas cortas. Los enlaces PHP no se alteran. */
        .navbar .gyd-menu-panel .gyd-menu-grid:not(.gyd-menu-encargado) {
            display: block;
            columns: 3 250px;
            column-gap: 10px;
            column-fill: balance;
        }
        .navbar .gyd-menu-panel .gyd-menu-grid:not(.gyd-menu-encargado) .gyd-menu-seccion {
            display: inline-flex;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            margin: 0 0 10px;
            padding: 10px;
            vertical-align: top;
            break-inside: avoid;
            page-break-inside: avoid;
            -webkit-column-break-inside: avoid;
            gap: 1px;
        }
        .navbar .gyd-menu-panel .gyd-menu-grid:not(.gyd-menu-encargado) .gyd-menu-seccion a {
            padding: 7px 9px !important;
            line-height: 1.25;
            font-size: 12.5px;
        }
        .navbar .gyd-menu-panel .gyd-menu-grid:not(.gyd-menu-encargado) .erp-menu-heading {
            margin: 0 0 4px;
            padding: 4px 6px 7px;
        }
        @media (max-width: 1000px) {
            .navbar .gyd-menu-panel .gyd-menu-grid:not(.gyd-menu-encargado) {
                columns: 2 240px;
            }
        }
        @media (max-width: 690px) {
            .navbar .gyd-menu-panel .gyd-menu-grid:not(.gyd-menu-encargado) {
                columns: 1;
            }
            .navbar .gyd-menu-panel .gyd-menu-grid:not(.gyd-menu-encargado) .gyd-menu-seccion {
                margin-bottom: 9px;
            }
            .navbar .gyd-menu-panel .gyd-menu-grid:not(.gyd-menu-encargado) .gyd-menu-seccion a {
                padding: 9px 10px !important;
                font-size: 14px;
            }
        }

        /* ENCARGADO: 3 grupos reales, filas compactas, sin huecos visuales. */
        .navbar .gyd-menu-panel .gyd-menu-grid.gyd-menu-encargado {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            align-items: start;
            gap: 12px;
        }
        .navbar .gyd-menu-panel .gyd-menu-encargado .gyd-menu-seccion {
            align-self: start;
            padding: 12px;
            gap: 1px;
            border-radius: 12px;
            background: #f8fbff;
        }
        .navbar .gyd-menu-panel .gyd-menu-encargado .gyd-menu-seccion a {
            padding: 9px 10px !important;
            margin: 0 !important;
            line-height: 1.3;
        }
        .navbar .gyd-menu-panel .gyd-menu-encargado .erp-menu-heading {
            margin-bottom: 5px;
            padding: 4px 6px 8px;
        }
        @media (max-width: 950px) {
            .navbar .gyd-menu-panel .gyd-menu-grid.gyd-menu-encargado {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        @media (max-width: 690px) {
            .navbar .gyd-menu-panel .gyd-menu-grid.gyd-menu-encargado {
                grid-template-columns: minmax(0, 1fr);
                gap: 9px;
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

                <?= e($user['rol_original'] ?? $user['rol'] ?? '') ?>
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

    <nav class="navbar gyd-menu-principal" aria-label="Navegación principal">
        <?php if ($menuNumeroEnlaces > 0): ?>
        <details class="erp-nav gyd-menu-desplegable" id="gyd-menu-completo">
            <summary aria-label="Abrir <?= e($menuTitulo) ?>">
                <span aria-hidden="true">▦</span>
                <?= e($menuTitulo) ?>
                <span class="erp-nav-arrow" aria-hidden="true">▾</span>
            </summary>
            <div class="erp-nav-menu gyd-menu-panel" aria-label="Todos los módulos disponibles">
                <div class="erp-menu-title">
                    <div class="gyd-menu-titulo">
                        <strong><?= e($esEncargado ? 'Todos tus módulos de Almacén' : ($erpEstaHabilitado ? 'Todos los módulos de G&D' : 'Módulos de Almacén')) ?></strong>
                        <small><?= $esEncargado ? 'Accesos organizados en tres áreas, sin módulos administrativos del ERP' : 'Organizados por área, según tus permisos' ?></small>
                    </div>
                    <span class="gyd-menu-total"><?= (int)$menuNumeroEnlaces ?> accesos</span>
                </div>
                <div class="gyd-menu-busqueda">
                    <label class="gyd-menu-label" for="gyd-menu-filtro">Buscar módulo</label>
                    <input type="search" id="gyd-menu-filtro" placeholder="Ej. Inventario, Compras, Reportes..." autocomplete="off">
                </div>
                <div class="erp-menu-grid gyd-menu-grid<?= $esEncargado ? ' gyd-menu-encargado' : '' ?>">
                    <?php foreach ($menuSecciones as $grupo => $enlaces): ?>
                    <section class="erp-menu-section gyd-menu-seccion">
                        <h3 class="erp-menu-heading"><?= e($grupo) ?></h3>
                        <?php foreach ($enlaces as $enlace): ?>
                        <a href="<?= e($enlace['ruta']) ?>" <?= $menuPaginaActual === $enlace['ruta'] ? 'class="gyd-menu-activo" aria-current="page"' : '' ?>>
                            <?= e($enlace['titulo']) ?>
                        </a>
                        <?php endforeach; ?>
                    </section>
                    <?php endforeach; ?>
                </div>
                <p id="gyd-menu-vacio" class="gyd-menu-vacio" hidden>No se encontraron módulos con ese nombre.</p>
            </div>
        </details>
        <?php endif; ?>

        <?php // OCAV_NAV_RECEPCIONES_V1 ?>
        <?php if (in_array($rolUsuario,['ADMINISTRADOR','ADMIN','DIRECCION','ENCARGADO','ALMACEN','JEFE_ALMACEN'],true)): ?>
        <a href="erp_recepciones_pendientes.php" class="nav-resurtidos gyd-menu-acceso-directo" id="enlaceRecepcionesOc">
            <span>🚚 Recepciones OC</span>
            <span id="contadorOcAlmacen" class="contador-resurtidos" aria-label="Órdenes de compra pendientes de recibir" <?= $ocavBadge['almacen']>0?'':'hidden' ?>><?= (int)$ocavBadge['almacen'] ?></span>
        </a>
        <?php endif; ?>
        <?php if (in_array($rolUsuario,['ADMINISTRADOR','ADMIN','DIRECCION','COMPRAS'],true)): ?>
        <a href="erp_compras_avisos.php" class="nav-resurtidos gyd-menu-acceso-directo" id="enlaceAvisosCompras">
            <span>🔔 Compras</span>
            <span id="contadorOcCompras" class="contador-resurtidos" aria-label="Recepciones pendientes de revisar por Compras" <?= $ocavBadge['compras']>0?'':'hidden' ?>><?= (int)$ocavBadge['compras'] ?></span>
        </a>
        <?php endif; ?>
        <?php if ($puedeVerResurtidos): ?>
        <a href="resurtidos.php" class="nav-resurtidos gyd-menu-acceso-directo" id="enlaceResurtidos">
            <span>📦 Resurtidos</span>
            <?php if ($puedeRecibirResurtidos): ?>
            <span id="contadorResurtidos" class="contador-resurtidos" aria-label="Solicitudes de resurtido pendientes" title="Solicitudes pendientes" hidden>0</span>
            <?php endif; ?>
        </a>
        <?php endif; ?>

        <?php if ($puedeVerTickets): ?>
        <a href="tickets.php" class="nav-resurtidos gyd-menu-acceso-directo" id="enlaceTickets">
            <span>🎫 Tickets</span>
            <?php if ($puedeRecibirTickets): ?>
            <span id="contadorTickets" class="contador-resurtidos" aria-label="Tickets pendientes" title="Tickets pendientes" hidden>0</span>
            <?php endif; ?>
        </a>
        <?php endif; ?>
    </nav>
    <script>
      // OCAV_POLL_V1. Sin sonido ni notificaciones invasivas; se refresca al navegar y cada 90 s.
      (() => {
        const a=document.getElementById('contadorOcAlmacen');
        const c=document.getElementById('contadorOcCompras');
        if (!a && !c) return;
        const actualizar=async()=>{
          if (document.hidden) return;
          try {
            const resp=await fetch('erp_oc_avisos_api.php',{credentials:'same-origin',cache:'no-store'});
            if (!resp.ok) return;
            const data=await resp.json();if(!data.ok)return;
            for (const [el,cantidad] of [[a,data.contadores.almacen],[c,data.contadores.compras]]) {
              if(!el)continue;
              const n=Math.max(0,Number(cantidad)||0);el.textContent=String(n);el.hidden=n<1;
            }
          } catch (_) { /* conservar el último indicador hasta la siguiente consulta */ }
        };
        setInterval(actualizar,90000);
        document.addEventListener('visibilitychange',()=>{if(!document.hidden)actualizar();});
      })();
    </script>
    <script>
      /* Desplegable fijo con scroll propio: nunca se corta por el navbar ni el dashboard. */
      (function () {
        const menu = document.querySelector('.navbar .gyd-menu-desplegable');
        if (!menu) return;
        const trigger = menu.querySelector('summary');
        const panel = menu.querySelector('.gyd-menu-panel');
        const input = menu.querySelector('#gyd-menu-filtro');
        const empty = menu.querySelector('#gyd-menu-vacio');
        if (!trigger || !panel || !input) return;
        const groups = Array.from(panel.querySelectorAll('.gyd-menu-seccion'));
        const plain = (v) => v.toLocaleLowerCase('es').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        function filterItems() {
          const q = plain(input.value.trim());
          let visible = 0;
          for (const section of groups) {
            let matches = 0;
            const title = plain(section.querySelector('.erp-menu-heading').textContent);
            for (const link of section.querySelectorAll('a')) {
              const show = !q || plain(link.textContent).includes(q) || title.includes(q);
              link.hidden = !show;
              matches += Number(show);
            }
            section.hidden = matches === 0;
            visible += matches;
          }
          empty.hidden = visible > 0;
        }
        function positionPanel() {
          if (!menu.open) return;
          const rect = trigger.getBoundingClientRect();
          const pad = 10;
          const maxWidth = window.innerWidth > 1000 ? 1160 : window.innerWidth > 690 ? 820 : 420;
          const width = Math.min(maxWidth, Math.max(240, window.innerWidth - pad * 2));
          const left = Math.max(pad, Math.min(rect.left, window.innerWidth - width - pad));
          let top = rect.bottom + 8;
          if (top > window.innerHeight - 165) top = Math.max(pad, window.innerHeight - 165);
          const availableHeight = Math.max(120, window.innerHeight - top - pad);
          Object.assign(panel.style, {
            position: 'fixed', left: left + 'px', top: top + 'px',
            width: width + 'px', maxHeight: availableHeight + 'px',
            overflowY: 'auto', overflowX: 'hidden'
          });
        }
        input.addEventListener('input', filterItems);
        menu.addEventListener('toggle', () => {
          if (menu.open) {
            panel.scrollTop = 0;
            input.value = '';
            filterItems();
            requestAnimationFrame(positionPanel);
          }
        });
        window.addEventListener('resize', positionPanel);
        window.addEventListener('scroll', positionPanel, { passive: true });
        document.addEventListener('pointerdown', e => {
          if (menu.open && !menu.contains(e.target)) menu.open = false;
        });
        document.addEventListener('keydown', e => {
          if (e.key === 'Escape' && menu.open) { menu.open = false; trigger.focus(); }
        });
      })();
    </script>

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
