<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/controllers/ResurtidoController.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function responderNotificaciones(
    bool $ok,
    array $datos = [],
    string $mensaje = '',
    int $codigoHttp = 200
): void {
    http_response_code($codigoHttp);

    echo json_encode(
        [
            'ok' => $ok,
            'mensaje' => $mensaje,
            'datos' => $datos
        ],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

    exit;
}

function resumirSolicitudes(array $solicitudes): array
{
    return array_map(
        static function (array $solicitud): array {
            return [
                'id' => (int) ($solicitud['id'] ?? 0),
                'folio' => (string) ($solicitud['folio'] ?? ''),
                'folio_documento' => (string) (
                    $solicitud['folio_documento'] ?? ''
                ),
                'fecha' => (string) ($solicitud['fecha'] ?? ''),
                'estado' => (string) ($solicitud['estado'] ?? ''),
                'verificador_nombre' => (string) (
                    $solicitud['verificador_nombre'] ?? ''
                ),
                'solicitante_nombre' => (string) (
                    $solicitud['solicitante_nombre'] ?? ''
                ),
                'almacen_nombre' => (string) (
                    $solicitud['almacen_nombre'] ?? ''
                ),
                'total_productos' => (int) (
                    $solicitud['total_productos'] ?? 0
                ),
                'total_cantidad' => (int) (
                    $solicitud['total_cantidad'] ?? 0
                )
            ];
        },
        $solicitudes
    );
}

if (!isLoggedIn()) {
    responderNotificaciones(
        false,
        [],
        'La sesión no está disponible.',
        401
    );
}

$user = currentUser();
$rol = strtoupper(trim((string) ($user['rol'] ?? '')));
$almacenId = (int) ($user['almacen_id'] ?? 0);

$puedeRecibirResurtidos = in_array(
    $rol,
    ['ADMINISTRADOR', 'ENCARGADO', 'JEFE_ALMACEN'],
    true
);

$puedeRecibirTickets =
    $rol === 'ADMINISTRADOR'
    || (
        in_array($rol, ['ENCARGADO', 'JEFE_ALMACEN'], true)
        && $almacenId === 1
    );

if (!$puedeRecibirResurtidos && !$puedeRecibirTickets) {
    responderNotificaciones(
        false,
        [],
        'No tiene permisos para consultar estas notificaciones.',
        403
    );
}

if (
    in_array($rol, ['ENCARGADO', 'JEFE_ALMACEN'], true)
    && $almacenId <= 0
) {
    responderNotificaciones(
        false,
        [],
        'La cuenta del encargado no tiene un almacén asignado.',
        403
    );
}

$_SESSION['ultimo_acceso'] = time();
renovarCookieSesion();
session_write_close();

try {
    $controller = new ResurtidoController();
    $filtroAlmacen =
        $rol === 'ADMINISTRADOR'
            ? null
            : $almacenId;

    $resurtidos = [];
    $tickets = [];

    if ($puedeRecibirResurtidos) {
        $resurtidos = resumirSolicitudes(
            $controller->obtenerPendientes(
                $filtroAlmacen,
                'RESURTIDO'
            )
        );
    }

    if ($puedeRecibirTickets) {
        $tickets = resumirSolicitudes(
            $controller->obtenerPendientes(
                $filtroAlmacen,
                'TICKET'
            )
        );
    }

    responderNotificaciones(
        true,
        [
            'consultado_en' => date('c'),
            'resurtidos' => [
                'habilitado' => $puedeRecibirResurtidos,
                'cantidad' => count($resurtidos),
                'solicitudes' => $resurtidos
            ],
            'tickets' => [
                'habilitado' => $puedeRecibirTickets,
                'cantidad' => count($tickets),
                'solicitudes' => $tickets
            ]
        ],
        'Notificaciones consultadas.'
    );
} catch (Throwable $e) {
    error_log(
        'Error al consultar las notificaciones móviles: '
        . $e->getMessage()
    );

    responderNotificaciones(
        false,
        [],
        'No fue posible consultar las notificaciones.',
        500
    );
}
