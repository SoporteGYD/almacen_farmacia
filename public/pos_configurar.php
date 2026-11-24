<?php
declare(strict_types=1);
/** GYD F2: configuracion manual POS retirada. La ficha comercial se captura al aplicar factura. */
require_once __DIR__.'/../app/helpers/auth.php';
requireLogin();
$u=currentUser()??[];
if(!in_array(($u['rol']??''),['ADMINISTRADOR','DIRECCION'],true)){
    http_response_code(403);
    exit('Acceso denegado.');
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Configuración de productos · ERP G&amp;D</title><style>body{font:16px system-ui,sans-serif;background:#eef3f9;margin:0;color:#153954}main{max-width:750px;margin:10vh auto;background:white;padding:30px;border-radius:14px;box-shadow:0 6px 30px #0001}a{display:inline-block;background:#145c9e;color:white;text-decoration:none;padding:11px 15px;border-radius:9px;margin:5px}p{line-height:1.55}</style></head><body><main><h1>Configuración centralizada en Compras</h1><p>La edición independiente de <b>precios, existencias y presentaciones POS</b> quedó deshabilitada para evitar diferencias con Entradas por factura.</p><p>Los precios públicos y las piezas por caja se revisan al <b>aplicar la mercancía verificada de una factura</b>. Las existencias solo aumentan al aplicar entradas y disminuyen con ventas, devoluciones o traspasos registrados.</p><a href="erp_facturas.php">Ir a Entradas por factura</a><a href="erp_cambio_precios.php">Cambios de precios autorizados</a><a href="pos.php">Volver a caja</a></main>
<?php /* GYD_NAV_ROLE_V1 */
$__gydNavigationAccount = function_exists('isLoggedIn') && isLoggedIn() && function_exists('currentUser')
    ? (currentUser() ?: []) : [];
$__gydNavigationRole = strtoupper(trim((string)($__gydNavigationAccount['rol_original'] ?? $__gydNavigationAccount['rol'] ?? '')));
$__gydNavigationAllowed = in_array($__gydNavigationRole, ['DIRECCION','ADMINISTRADOR','ADMIN','COMPRAS'], true);
if (!defined('GYD_HIDE_GLOBAL_NAV') && $__gydNavigationAllowed):
$__gydNavigationHome = $__gydNavigationRole === 'COMPRAS' ? 'erp_compras_inicio.php' : 'dashboard.php';
?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=5">
<script>window.GYD_NAV_ENABLED=true;window.GYD_NAV_HOME=<?=json_encode($__gydNavigationHome, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=5"></script>
<?php endif; unset($__gydNavigationAccount,$__gydNavigationRole,$__gydNavigationAllowed,$__gydNavigationHome); ?>
<!-- GYD_UNA_PESTANA_INTERNA -->
<script src="assets/js/gyd_una_pestana.js?v=1"></script>
</body></html>
