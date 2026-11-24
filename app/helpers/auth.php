<?php

declare(strict_types=1);

require_once __DIR__ . '/../models/SesionPersistente.php';
require_once __DIR__ . '/audit.php';

/*
 * Límite máximo deslizante de 30 días para la sesión persistente (antes 10 años).
 * Esta es una reducción de riesgo, NO sustituye expiración por inactividad,
 * MFA, control de dispositivos, cierre remoto ni rotación de credenciales.
 * Las cookies antiguas existentes deben revocarse en el despliegue.
 */
$tiempoSesion = 60 * 60 * 24 * 30;
$nombreCookiePersistente = 'almacen2_pos_sesion_persistente';
// Separar la sesión de almacen-farmacia2 de la aplicación original.
if (session_status() === PHP_SESSION_NONE) { session_name('almacen2_pos_sesion'); }

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
        'path' => '/almacen-farmacia2/public/',
        'domain' => '',
        'secure' => solicitudEsHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => $tiempoSesion,
        'path' => '/almacen-farmacia2/public/',
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
        // Aplicar equivalencia ANTES de las validaciones de rutas/módulos.
    aplicarPermisosDireccion();

    // CONTABILIDAD solo consulta y realiza cortes con autorización personal.
    if (strtoupper(trim((string)(currentUser()['rol_original'] ?? currentUser()['rol'] ?? ''))) === 'CONTABILIDAD') {
        $archivoContable=basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if (!in_array($archivoContable,['gyd_cortes_caja.php','pos_corte_retiros.php','erp_cortes_boveda.php','logout.php','session_keepalive.php'],true)) {
            http_response_code(403);exit('La cuenta de Contabilidad solo puede acceder a los cortes de caja autorizados.');
        }
    }
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
        'peticiones_traspasos.php',
        'peticiones_traspasos_api.php',
        'peticiones_traspasos_imprimir.php',
        'traspasos.php',
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
    $usuario = $usuario ?? currentUser();
    if (strtoupper(trim((string)($usuario['rol'] ?? ''))) === 'CAJERO') { return 'pos.php'; }
    if (strtoupper(trim((string)($usuario['rol'] ?? ''))) === 'COMPRAS') {return 'erp_compras_inicio.php';}
    if (strtoupper(trim((string)($usuario['rol'] ?? ''))) === 'CONTABILIDAD') {return 'gyd_cortes_caja.php';}
    return esJefeAlmacenLimitado($usuario)
        ? 'resurtidos.php'
        : 'dashboard.php';
}

function restringirCajeroAlPos(): void
{
    if (strtoupper((string)(currentUser()['rol'] ?? '')) !== 'CAJERO') { return; }
    $archivo = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    /* GYD_PERFIL_CAJERO_RUTAS: exclusivamente operación POS y alta/búsqueda de clientes. */
    $permitidos = [
        'pos.php','pos_api.php','pos_ticket.php','pos_historial.php',
        'pos_retiros.php','pos_retiro_ticket.php','pos_retiros_estado.php',
        'pos_corte_retiros.php','pos_eficiencia_api.php',
        'gyd_clientes.php','gyd_clientes_api.php',
        'logout.php','session_keepalive.php'
    ];
    if (!in_array($archivo, $permitidos, true)) {
        http_response_code(403); exit('Acceso denegado: este usuario solo puede utilizar Punto de Venta.');
    }
}

/**
 * DIRECCION disfruta de los mismos permisos operativos que ADMINISTRADOR.
 * La identidad y el rol real se conservan para la autorización de compras,
 * en la que solamente otro usuario de DIRECCION puede aprobar.
 * No modifica la base de datos ni el rol guardado en la tabla usuarios.
 */
function aplicarPermisosDireccion(): void
{
    if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
        return;
    }
    $original = strtoupper(trim((string)(
        $_SESSION['user']['rol_original'] ?? $_SESSION['user']['rol'] ?? ''
    )));
    if ($original === 'DIRECCION') {
        $_SESSION['user']['rol_original'] = 'DIRECCION';
        $_SESSION['user']['rol'] = 'ADMINISTRADOR';
    }
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        header("Location: login.php");
        exit;
    }

    // DIRECCION mantiene su rol original visible y recibe permisos de ADMINISTRADOR
    // también después de un inicio de sesión normal (no solo al restaurar cookies).
    aplicarPermisosDireccion();

    // CONTABILIDAD solo consulta y realiza cortes con autorización personal.
    if (strtoupper(trim((string)(currentUser()['rol_original'] ?? currentUser()['rol'] ?? ''))) === 'CONTABILIDAD') {
        $archivoContable=basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if (!in_array($archivoContable,['gyd_cortes_caja.php','pos_corte_retiros.php','erp_cortes_boveda.php','logout.php','session_keepalive.php'],true)) {
            http_response_code(403);exit('La cuenta de Contabilidad solo puede acceder a los cortes de caja autorizados.');
        }
    }
    $_SESSION['ultimo_acceso'] = time();
    renovarCookieSesion();

    verificarAccesoJefeAlmacen();
    restringirCajeroAlPos();

    // ENCARGADO pertenece exclusivamente a la operación de Almacén.
    // Ocultar enlaces no basta: impedir también el acceso directo a ERP/POS.
    // No afecta a Tickets, Resurtidos, Entradas, Salidas ni Existencias.
    $rolRealActual = strtoupper(trim((string)(
        currentUser()['rol_original'] ?? currentUser()['rol'] ?? ''
    )));
    if ($rolRealActual === 'ENCARGADO' && !in_array(
    basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')),
    ['erp_notificaciones.php', 'erp_notificaciones_api.php'],
    true
)) { /* G&D CENTRO AVISOS ENCARGADO V2 */
        $paginaActual = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if ((str_starts_with($paginaActual, 'erp_') && !in_array($paginaActual,['erp_recepciones_pendientes.php','erp_recepciones_historial.php','erp_recepcion_registrar.php','erp_oc_avisos_api.php','erp_oc_inventarios.php'],true))
            || $paginaActual === 'pos.php' // OCAV_ENCARGADO_RECEPCIONES_V1
            || str_starts_with($paginaActual, 'pos_')) {
            http_response_code(403);
            exit('Acceso denegado: el perfil ENCARGADO solo tiene acceso a las funciones autorizadas de Almacén.');
        }
    }

    $rolErp=strtoupper(trim((string)(currentUser()['rol']??'')));
    if($rolErp==='COMPRAS' && !in_array(basename((string)($_SERVER['SCRIPT_NAME']??'')),['erp_compras_avisos.php','erp_oc_avisos_api.php','erp_oc_inventarios.php','peticiones_traspasos.php','peticiones_traspasos_api.php','peticiones_traspasos_imprimir.php','traspasos.php'],true)) { // OCAV_COMPRAS_ACCESOS_V1
        $rutaActual=basename((string)($_SERVER['SCRIPT_NAME']??''));
        require_once __DIR__ . '/erp_compras_modulos.php';
        if(!gyd_compras_ruta_permitida($rutaActual)){http_response_code(403);exit('Acceso limitado al Área de Compras.');}
    }

    // GYD_ACL_CONTROL_CENTRAL_RUTAS
    require_once __DIR__.'/gyd_acl.php';
    gyd_acl_exigir_ruta(currentUser() ?? [], basename((string)($_SERVER['SCRIPT_NAME'] ?? '')));
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
