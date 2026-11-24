<?php
declare(strict_types=1);
/** Ruta legada bloqueada: un retiro registrado ya afecta el saldo POS. */
require_once __DIR__.'/../app/helpers/auth.php';
requireLogin();
if($_SERVER['REQUEST_METHOD']!=='GET'){
 http_response_code(405);header('Allow: GET');exit('La verificación electrónica de retiros fue deshabilitada. Ningún retiro requiere aprobación posterior.');
}
header('Location: erp_cortes_boveda.php',true,303);exit;
