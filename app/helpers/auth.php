<?php

declare(strict_types=1);

require_once __DIR__ . '/../models/SesionPersistente.php';
require_once __DIR__ . '/audit.php';

/*
 * La sesión normal se conserva durante diez años. Además se utiliza una
 * credencial aleatoria guardada en la base de datos para reconstruirla si el
 * servidor elimina su archivo temporal. Solamente "Cerrar sesión" revoca la
 * credencial de este navegador.
 */
$tiempoSesion = 60 * 60 * 24 * 365 * 10;
$nombreCookiePersistente = 'almacen_sesion_persistente';

ini_set('session.gc_maxlifetime', (string) $tiempoSesion);
ini_set('session.cookie_lifetime', (string) $tiempoSesion);
ini_set('session.gc_probability', '0');
ini_set('session.gc_divisor', '1000');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

function solicitudEsHttps(): bool
{
    if (
        isset($_SERVER['HTTPS'])
        && strtolower((string) $_SERVER['HTTPS']) !== 'off'
        && (string) $_SERVER['HTTPS'] !== ''
    ) {
        return true;
    }

    return strtolower(
        trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))
    ) === 'https';
}

function opcionesCookieSesion(int $expira): array
{
    return [
        'expires' => $expira,
        'path' => '/',
        'domain' => '',
        'secure' => solicitudEsHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => $tiempoSesion,
        'path' => '/',
        'domain' => '',
        'secure' => solicitudEsHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function renovarCookieSesion(): void
{
    global $tiempoSesion;

    if (
        session_status() === PHP_SESSION_ACTIVE
        && !headers_sent()
    ) {
        setcookie(
            session_name(),
            session_id(),
            opcionesCookieSesion(time() + $tiempoSesion)
        );

        /*
         * Los navegadores pueden limitar la duración máxima de una cookie.
         * Volver a emitirla en cada uso convierte el plazo en deslizante y
         * mantiene autorizado el dispositivo mientras siga usando el sistema.
         */
        $persistente = extraerCookieSesionPersistente();

        if ($persistente) {
            establecerCookieSesionPersistente(
                $persistente['selector'],
                $persistente['token']
            );
        }
    }
}

function extraerCookieSesionPersistente(): ?array
{
    global $nombreCookiePersistente;

    $valor = trim(
        (string) ($_COOKIE[$nombreCookiePersistente] ?? '')
    );

    if (
        !preg_match(
            '/^([a-f0-9]{24})\\.([a-f0-9]{64})$/',
            $valor,
            $coincidencias
        )
    ) {
        return null;
    }

    return [
        'selector' => $coincidencias[1],
        'token' => $coincidencias[2],
    ];
}

function establecerCookieSesionPersistente(
    string $selector,
    string $token
): void {
    global $tiempoSesion;
    global $nombreCookiePersistente;

    if (headers_sent()) {
        return;
    }

    $valor = $selector . '.' . $token;

    setcookie(
        $nombreCookiePersistente,
        $valor,
        opcionesCookieSesion(time() + $tiempoSesion)
    );

    $_COOKIE[$nombreCookiePersistente] = $valor;
}

function eliminarCookieSesionPersistente(): void
{
    global $nombreCookiePersistente;

    if (!headers_sent()) {
        setcookie(
            $nombreCookiePersistente,
            '',
            opcionesCookieSesion(time() - 42000)
        );
    }

    unset($_COOKIE[$nombreCookiePersistente]);
}

function crearSesionPersistente(array $usuario): bool
{
    global $tiempoSesion;

    $usuarioId = (int) ($usuario['id'] ?? 0);
    $passwordHash = (string) ($usuario['password'] ?? '');

    if ($usuarioId <= 0 || $passwordHash === '') {
        return false;
    }

    try {
        $modelo = new SesionPersistente();
        $cookieAnterior = extraerCookieSesionPersistente();

        if ($cookieAnterior) {
            $modelo->eliminarPorSelector(
                $cookieAnterior['selector']
            );
        }

        $selector = bin2hex(random_bytes(12));
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $credencialHash = hash('sha256', $passwordHash);
        $expiraEn = date(
            'Y-m-d H:i:s',
            time() + $tiempoSesion
        );

        $creada = $modelo->crear(
            $usuarioId,
            $selector,
            $tokenHash,
            $credencialHash,
            $expiraEn,
            mb_substr(
                trim((string) ($_SERVER['REMOTE_ADDR'] ?? '')),
                0,
                45
            ) ?: null,
            mb_substr(
                trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')),
                0,
                500
            ) ?: null
        );

        if (!$creada) {
            return false;
        }

        $_SESSION['sesion_persistente_selector'] = $selector;
        establecerCookieSesionPersistente($selector, $token);
        $modelo->eliminarExpiradas();

        return true;
    } catch (Throwable $e) {
        error_log(
            'No fue posible crear la sesión persistente: '
            . $e->getMessage()
        );

        return false;
    }
}

function restaurarSesionPersistente(): bool
{
    global $tiempoSesion;

    if (isset($_SESSION['user'])) {
        return true;
    }

    $cookie = extraerCookieSesionPersistente();

    if (!$cookie) {
        return false;
    }

    try {
        $modelo = new SesionPersistente();
        $registro = $modelo->buscarActiva(
            $cookie['selector']
        );

        if (!$registro) {
            eliminarCookieSesionPersistente();
            return false;
        }

        $tokenValido = hash_equals(
            (string) $registro['token_hash'],
            hash('sha256', $cookie['token'])
        );
        $credencialValida = hash_equals(
            (string) $registro['credencial_hash'],
            hash(
                'sha256',
                (string) $registro['password']
            )
        );

        if (!$tokenValido || !$credencialValida) {
            $modelo->eliminarPorSelector(
                $cookie['selector']
            );
            eliminarCookieSesionPersistente();
            return false;
        }

        session_regenerate_id(true);

        $_SESSION['user'] = [
            'id' => (int) $registro['id'],
            'nombre' => $registro['nombre'],
            'usuario' => $registro['usuario'],
            'correo' => $registro['correo'],
            'rol' => $registro['rol'],
            'almacen_id' => isset($registro['almacen_id'])
                ? (int) $registro['almacen_id']
                : null,
            'almacen_nombre' =>
                $registro['almacen_nombre'] ?? null,
            'almacen_codigo' =>
                $registro['almacen_codigo'] ?? null,
        ];
        $_SESSION['ultimo_acceso'] = time();
        $_SESSION['sesion_persistente_selector'] =
            $cookie['selector'];

        /*
         * Rotar el secreto al reconstruir la sesión evita que una copia
         * anterior de la cookie pueda reutilizarse.
         */
        $tokenNuevo = bin2hex(random_bytes(32));
        $modelo->renovar(
            $cookie['selector'],
            hash('sha256', $tokenNuevo),
            hash(
                'sha256',
                (string) $registro['password']
            ),
            date(
                'Y-m-d H:i:s',
                time() + $tiempoSesion
            ),
            mb_substr(
                trim((string) ($_SERVER['REMOTE_ADDR'] ?? '')),
                0,
                45
            ) ?: null,
            mb_substr(
                trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')),
                0,
                500
            ) ?: null
        );

        establecerCookieSesionPersistente(
            $cookie['selector'],
            $tokenNuevo
        );
        renovarCookieSesion();

        auditLog([
            'modulo' => 'Autenticación',
            'accion' => 'SESION_RESTAURADA',
            'entidad' => 'usuario',
            'registro_id' => $registro['id'],
            'descripcion' =>
                'La sesión se restauró automáticamente en un dispositivo autorizado.',
        ]);

        return true;
    } catch (Throwable $e) {
        /*
         * Si la tabla aún no se ha instalado, la sesión PHP normal y el
         * keepalive continúan funcionando sin bloquear el acceso.
         */
        error_log(
            'No fue posible restaurar la sesión persistente: '
            . $e->getMessage()
        );

        return false;
    }
}

function revocarSesionPersistenteActual(): void
{
    $cookie = extraerCookieSesionPersistente();
    $selector = $cookie['selector']
        ?? (
            $_SESSION['sesion_persistente_selector']
            ?? ''
        );

    if ($selector !== '') {
        try {
            $modelo = new SesionPersistente();
            $modelo->eliminarPorSelector($selector);
        } catch (Throwable $e) {
            error_log(
                'No fue posible revocar la sesión persistente: '
                . $e->getMessage()
            );
        }
    }

    eliminarCookieSesionPersistente();
}

restaurarSesionPersistente();

/*
 * Las cuentas que ya estaban abiertas al instalar esta mejora también
 * reciben automáticamente su credencial persistente, sin obligarlas a salir.
 */
if (
    isset($_SESSION['user']['id'])
    && !extraerCookieSesionPersistente()
) {
    $ultimoIntento = (int) (
        $_SESSION['intento_sesion_persistente_en'] ?? 0
    );

    if (time() - $ultimoIntento >= 3600) {
        $_SESSION['intento_sesion_persistente_en'] = time();

        try {
            $modeloSesion = new SesionPersistente();
            $usuarioSesion = $modeloSesion->buscarUsuarioActivo(
                (int) $_SESSION['user']['id']
            );

            if (
                $usuarioSesion
                && crearSesionPersistente($usuarioSesion)
            ) {
                unset(
                    $_SESSION['intento_sesion_persistente_en']
                );
            }
        } catch (Throwable $e) {
            error_log(
                'La sesión persistente se reintentará más tarde: '
                . $e->getMessage()
            );
        }
    }
}

function isLoggedIn(): bool
{
    return isset($_SESSION['user']);
}

function esJefeAlmacenLimitado(?array $usuario = null): bool
{
    $usuario = $usuario ?? currentUser();

    return strtoupper(
        trim((string) ($usuario['rol'] ?? ''))
    ) === 'JEFE_ALMACEN';
}

function verificarAccesoJefeAlmacen(): void
{
    if (!esJefeAlmacenLimitado()) {
        return;
    }

    /*
     * El perfil móvil solo tiene dos módulos visibles. Salidas e impresión
     * son rutas auxiliares indispensables para surtir esas solicitudes; no
     * aparecen en su menú y cada archivo valida el registro solicitado.
     */
    $archivo = basename(
        (string) ($_SERVER['SCRIPT_NAME'] ?? '')
    );

    $archivosPermitidos = [
        'resurtidos.php',
        'tickets.php',
        'salidas.php',
        'imprimir_salida.php',
        'api_notificaciones_pendientes.php',
        'session_keepalive.php',
        'logout.php',
    ];

    if (in_array($archivo, $archivosPermitidos, true)) {
        return;
    }

    auditLog([
        'modulo' => auditModuleForScript($archivo),
        'accion' => 'ACCESO_DENEGADO',
        'descripcion' => 'La cuenta móvil del jefe de almacén intentó abrir un módulo no autorizado.',
        'metadata' => [
            'archivo' => $archivo,
            'rol_usuario' => 'JEFE_ALMACEN',
        ],
    ]);

    http_response_code(403);
    echo '<h2>Acceso denegado</h2>';
    echo '<p>Esta cuenta solo puede utilizar Resurtidos y Tickets.</p>';
    exit;
}

function paginaInicialUsuario(?array $usuario = null): string
{
    return esJefeAlmacenLimitado($usuario)
        ? 'resurtidos.php'
        : 'dashboard.php';
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        header("Location: login.php");
        exit;
    }

    $_SESSION['ultimo_acceso'] = time();
    renovarCookieSesion();

    verificarAccesoJefeAlmacen();

    auditTrackCurrentRequest();
}

function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

function logoutUser(): void
{
    if (isLoggedIn()) {
        $user = currentUser();

        auditLog([
            'modulo' => 'Autenticación',
            'accion' => 'CIERRE_SESION',
            'entidad' => 'usuario',
            'registro_id' => $user['id'] ?? null,
            'descripcion' => 'Cerró sesión en el sistema.',
        ]);
    }

    revocarSesionPersistenteActual();
    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            opcionesCookieSesion(time() - 42000)
        );
    }

    session_destroy();
}
