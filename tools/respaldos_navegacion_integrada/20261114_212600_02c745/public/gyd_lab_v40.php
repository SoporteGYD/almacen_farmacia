<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
requireLogin();
$usuario=currentUser()??[];
$rol=strtoupper((string)($usuario['rol']??''));
if(!in_array($rol,['ADMINISTRADOR','ADMIN','DIRECCION'],true)){
    http_response_code(403);exit('Laboratorio limitado a Administración y Dirección.');
}
if(empty($_SESSION['pos_csrf']))$_SESSION['pos_csrf']=bin2hex(random_bytes(32));
?><!doctype html>
<html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>G&D · Laboratorio de clientes, inventario y rentabilidad</title>
<link rel="stylesheet" href="assets/css/gyd_lab_v40.css">
</head><body>
<header class="header"><div class="container headrow"><div>
<div class="wordmark">G&D <span>ERP / POS</span></div>
<h1>Laboratorio de recompensas</h1>
<p>Clientes ficticios, inventario virtual y análisis de margen por sucursal.</p>
</div><nav><a href="gyd_lab_v41.php">Canjes de prueba</a><a href="gyd_canjes.php">Solicitudes de recompensas</a><a href="gyd_recompensas.php">Catálogo de recompensas</a><a href="gyd_clientes.php">Clientes</a><a href="pos.php">POS</a></nav></div></header>
<main class="container">
<section class="warning" role="note"><strong>MODO LABORATORIO · SÓLO prueba:3307</strong><p>Los artículos de esta pantalla usan existencias, precios y costos <strong>virtuales</strong>. No modifican el inventario general/farmacia, facturas, ventas, puntos reales, cortes ni conciliación. Los clientes [PRUEBA] son ficticios. <strong>Canje real BLOQUEADO</strong>.</p></section>
<div class="tools"><div><label for="sucursal">Sucursal</label><select id="sucursal"><option>Cargando…</option></select></div><div class="grow"><label for="buscar">Buscar producto del catálogo ERP</label><input id="buscar" placeholder="Nombre, código o barras (mínimo 2 letras)" maxlength="100" autocomplete="off"></div><button id="sembrar" type="button" class="outline">Cargar ejemplos de prueba</button></div>
<div class="layout"><section class="card">
<h2>1. Artículos e inventario virtual</h2><div id="encontrados" class="findbox" aria-live="polite">Busca un producto, o selecciona uno de los ejemplos ya cargados.</div>
<div class="tablewrap"><table><thead><tr><th>Producto / escenario</th><th>Precio</th><th>Costo</th><th>Stock virtual</th><th>Margen</th><th></th></tr></thead><tbody id="escenarios"><tr><td colspan="6">Cargando escenarios…</td></tr></tbody></table></div>
</section><section class="card">
<h2>2. Ajustar artículo de prueba</h2><div id="seleccion" class="selected">Selecciona un artículo.</div>
<form id="editar" novalidate>
<input id="producto_id" type="hidden">
<div class="fields"><div><label for="precio">Precio de venta virtual ($)</label><input id="precio" type="number" min="0.01" step="0.01" placeholder="150.00" required></div>
<div><label for="costo">Costo virtual por pieza ($)</label><input id="costo" type="number" min="0.01" step="0.01" placeholder="100.00" required></div>
<div><label for="stock">Existencia virtual</label><input id="stock" type="number" min="0" max="100000" step="1" required></div>
<div><label for="puntos">Puntos para una recompensa</label><input id="puntos" type="number" min="1" max="2000000" step="1" required></div></div>
<div id="evaluacion" class="infobox">Los cálculos aparecerán cuando selecciones un producto.</div>
<button type="submit" id="guardar" disabled>Guardar ajustes virtuales</button>
</form></section></div>
<div class="layout second"><section class="card">
<h2>3. Clientes ficticios y puntos</h2><p class="muted">Se guardan en el catálogo de clientes de <code>prueba</code> para poder buscarlos también desde el punto de venta. No corresponden a personas reales.</p>
<div id="clientes" class="client-list" aria-live="polite">Cargando clientes…</div>
</section><section class="card">
<h2>4. Simular venta y recompensa</h2><label for="cliente">Selecciona cliente de prueba</label><select id="cliente"><option value="">Selecciona cliente…</option></select>
<p class="muted">Selecciona también un producto virtual de la sección 1.</p>
<button id="simular" type="button" disabled>Calcular simulación</button>
<div id="resultado" class="infobox">No se ha calculado ninguna simulación.</div>
</section></div>
<section class="card"><h2>Historial de ajustes virtuales</h2><div id="auditoria" class="audit">No hay registros todavía.</div></section>
<p id="mensaje" class="feedback" role="alert" aria-live="assertive"></p>
</main>
<script>window.GYD_LAB_V40={csrf:<?=json_encode((string)$_SESSION['pos_csrf'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>};</script>
<script defer src="assets/js/gyd_lab_v40.js"></script>

<?php if (!defined('GYD_HIDE_GLOBAL_NAV')): ?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=1">
<script>window.GYD_NAV_HOME='index.php';window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=1"></script>
<?php endif; ?>
</body></html>
