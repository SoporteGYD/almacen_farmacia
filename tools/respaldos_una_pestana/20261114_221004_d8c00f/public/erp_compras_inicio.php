<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/helpers/erp_compras_modulos.php';
requireLogin();
$u=currentUser()?:[];
$rol=strtoupper(trim((string)($u['rol_original']??$u['rol']??'')));
if(!in_array($rol,['COMPRAS','ADMINISTRADOR','ADMIN','DIRECCION'],true)){
    http_response_code(403);exit('Este panel está destinado al departamento de Compras.');
}
header('Cache-Control: private, no-store');
function compras_e(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$secciones=gyd_compras_modulos();
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Compras | ERP G&amp;D</title>
<style>
:root{font-family:system-ui,-apple-system,Segoe UI,Arial,sans-serif;color:#133b61;background:#f0f4fa}*{box-sizing:border-box}body{margin:0}.cab{background:#123b65;color:#fff;padding:19px max(16px,calc((100% - 1190px)/2));display:flex;justify-content:space-between;gap:15px;align-items:center;flex-wrap:wrap}.cab h1{font-size:22px;margin:0}.cab p{margin:6px 0 0;color:#d7e7f5;font-size:13px}.link{display:inline-block;border-radius:9px;background:#e9f2fc;color:#164d7b;padding:10px 14px;text-decoration:none;font-weight:700}.cab .link{background:#fff;color:#123b65}.salir{background:#b6314e!important;color:white!important}.main{max-width:1190px;margin:auto;padding:22px 16px 40px}.intro{background:white;border:1px solid #dbe7f2;border-radius:13px;padding:16px 19px;margin-bottom:20px;color:#3a5570}.grupo{margin:0 0 26px}.grupo h2{font-size:18px;margin:0 0 12px}.tarjetas{display:grid;grid-template-columns:repeat(auto-fit,minmax(235px,1fr));gap:13px}.item{display:block;color:inherit;text-decoration:none;background:#fff;border:1px solid #dae6f3;border-radius:14px;padding:19px;min-height:113px;box-shadow:0 3px 13px #113a620b}.item:hover,.item:focus-visible{border-color:#3280c6;box-shadow:0 5px 16px #113a6222;outline:2px solid transparent}.item strong{display:block;margin-bottom:9px;font-size:15px}.item span{font-size:13px;color:#59718b}.pie{font-size:12px;color:#526b85;margin:20px 0} @media(max-width:560px){.cab h1{font-size:19px}.main{padding:16px 12px}}
</style></head><body><header class="cab"><div><h1>ERP G&amp;D · Área de Compras</h1><p><?=compras_e((string)($u['nombre']??'Usuario'))?> · <?=compras_e($rol)?></p></div><div><a class="link" href="<?= $rol==='COMPRAS' ? 'erp_compras_inicio.php' : 'dashboard.php' ?>">Inicio</a> <a class="link salir" href="logout.php">Cerrar sesión</a></div></header>
<main class="main"><div class="intro"><strong>Tu espacio de trabajo</strong><p>Órdenes, proveedores, facturas y control de pagos en un solo lugar. La recepción física y los ajustes del inventario corresponden a Bodega y al personal autorizado.</p></div>
<?php foreach($secciones as $grupo=>$modulos): ?><section class="grupo"><h2><?=compras_e($grupo)?></h2><div class="tarjetas">
<?php foreach($modulos as $modulo): if(!is_file(__DIR__.'/'.$modulo['ruta']))continue; ?><a class="item" href="<?=compras_e($modulo['ruta'])?>"><strong><?=compras_e($modulo['titulo'])?></strong><span><?=compras_e($modulo['detalle'])?></span></a><?php endforeach; ?>
</div></section><?php endforeach; ?><p class="pie">Los permisos se comprueban también al abrir cada página; el menú no reemplaza la validación del servidor.</p></main><script defer src="assets/js/erp_notificaciones.js?v=1"></script>
<script src="assets/js/gyd_excel_reportes.js" defer></script>

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
</body></html>
