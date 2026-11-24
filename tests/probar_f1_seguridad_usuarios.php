<?php
/** Ejecutar desde consola para comprobar el helper sin base de datos. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
session_start();
require_once __DIR__.'/../tools/_f1_usuarios_csrf_payload/app/helpers/gyd_f1_usuarios_csrf.php';
$token=gyd_f1_usuarios_csrf_token();
assert(strlen($token)===64);
if (!gyd_f1_usuarios_csrf_valido($token)) exit('Fallo: token legitimo.');
if (gyd_f1_usuarios_csrf_valido(str_repeat('0',64))) exit('Fallo: token invalido.');
if (gyd_f1_usuarios_csrf_valido(['valor'=>$token])) exit('Fallo: tipo incorrecto.');
if (gyd_f1_usuarios_csrf_valido('')) exit('Fallo: token vacio.');
echo "APROBADO: Token legitimo, invalido, array y vacio.\n";
