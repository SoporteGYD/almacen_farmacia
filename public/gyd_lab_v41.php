<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
requireLogin();
$usuario=currentUser()??[];
if(!in_array(strtoupper((string)($usuario['rol']??'')),['ADMINISTRADOR','ADMIN','DIRECCION'],true)){
    http_response_code(403);exit('Acceso exclusivo para Administración/Dirección.');
}
if(empty($_SESSION['pos_csrf']))$_SESSION['pos_csrf']=bin2hex(random_bytes(32));
?><!doctype html>
<html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>G&D · Canjes de prueba</title>
<link rel="stylesheet" href="assets/css/gyd_lab_v41.css">
</head><body>
<header><div class="wrap top"><div><div class="brand">G&D <small>ERP / POS</small></div><h1>Canjes de laboratorio</h1><p>Premios habilitados automáticamente con costos registrados o simulados y existencias virtuales.</p></div><nav><a href="gyd_lab_v40.php">Editar inventario</a><a href="gyd_clientes.php">Clientes</a><a href="gyd_canjes.php">Solicitudes de recompensas</a><a href="pos.php">POS</a></nav></div></header>
<main class="wrap">
<section class="alert"><b>SOLO PRUEBAS · Base prueba:3307</b> — Aquí sí se registra el canje <b>VIRTUAL</b>: baja 1 unidad del stock de laboratorio y descuenta puntos disponibles <b>solamente dentro del laboratorio</b>. NO genera entregas, tickets de venta ni descuenta existencias reales. El punto de venta mantiene bloqueado el canje definitivo.</section>
<section class="toolbar panel"><label>Sucursal<select id="sucursal"><option value="">Cargando...</option></select></label><label>Cliente ficticio<select id="cliente"><option value="">Cargando...</option></select></label><button id="agregar" type="button" class="outline">+ Habilitar otros 20 productos de prueba</button></section>
<section class="metrics" aria-live="polite"><div class="metric"><small>Saldo disponible de prueba</small><strong id="saldo">—</strong><span>puntos virtuales</span></div><div class="metric"><small>Productos canjeables para el cliente</small><strong id="aptos">—</strong><span>de este inventario virtual</span></div><div class="metric"><small>Recompensas virtuales entregadas</small><strong id="entregadas">—</strong><span>registradas en el laboratorio</span></div></section>
<div class="layout"><section class="panel"><div class="sectionrow"><div><h2>Productos y premios</h2><p>Artículos del catálogo existente. El costo de catálogo es orientativo; el costo simulado es ficticio y no muestra utilidad real.</p></div><label class="search">Buscar en premios<input id="filtrar" type="search" placeholder="Nombre o código..."></label></div><div id="productos" class="cards" aria-live="polite">Cargando productos...</div></section>
<section class="panel checkout"><h2>Canje de prueba</h2><div id="seleccion" class="selection">Selecciona un producto de la izquierda.</div><div id="resumen"></div><button id="canjear" type="button" disabled>Canjear en laboratorio</button><p class="muted">El descuento es real en <em>las tablas virtuales de prueba</em>, no en el inventario de farmacia. Se registra la operación con una clave única para evitar dobles canjes.</p></section></div>
<section class="panel"><div class="sectionrow"><div><h2>Historial de canjes de prueba</h2><p>Movimientos de puntos, productos y costo de recompensa.</p></div><button class="outline" id="recargar" type="button">Actualizar</button></div><div class="tablewrap"><table><thead><tr><th>Fecha / ID</th><th>Sucursal</th><th>Cliente</th><th>Producto</th><th>Puntos</th><th>Costo premio</th></tr></thead><tbody id="historial"><tr><td colspan="6">Sin movimientos.</td></tr></tbody></table></div></section>
<p id="mensaje" role="status" aria-live="polite"></p>
</main>
<script>window.GYD_LAB_V41={csrf:<?=json_encode((string)$_SESSION['pos_csrf'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>};</script>
<script defer src="assets/js/gyd_lab_v41.js?v=43"></script>

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
