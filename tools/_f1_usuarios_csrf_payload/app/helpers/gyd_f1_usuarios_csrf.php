<?php
/**
 * G&D | Proteccion CSRF de acciones de administracion de usuarios.
 * No altera roles, sesiones ni contrasenas: valida un token ligado a la sesion.
 */
declare(strict_types=1);

function gyd_f1_usuarios_csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('La sesion debe estar iniciada para proteger usuarios.');
    }
    $token = $_SESSION['gyd_f1_usuarios_csrf'] ?? null;
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
        $token = bin2hex(random_bytes(32));
        $_SESSION['gyd_f1_usuarios_csrf'] = $token;
    }
    return $token;
}

function gyd_f1_usuarios_csrf_valido(mixed $recibido): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE || !is_string($recibido)) {
        return false;
    }
    if (!preg_match('/^[a-f0-9]{64}$/D', $recibido)) {
        return false;
    }
    $token = $_SESSION['gyd_f1_usuarios_csrf'] ?? null;
    return is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token) === 1
        && hash_equals($token, $recibido);
}
