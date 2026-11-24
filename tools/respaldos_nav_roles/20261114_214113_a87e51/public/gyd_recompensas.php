<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
requireLogin();
$u=currentUser()??[];
if(!in_array(strtoupper((string)($u['rol']??'')),['ADMINISTRADOR','ADMIN','DIRECCION'],true)){
    http_response_code(403);exit('Este catálogo sólo puede administrarlo Administración o Dirección.');
}
if(empty($_SESSION['pos_csrf']))$_SESSION['pos_csrf']=bin2hex(random_bytes(32));
$csrf=(string)$_SESSION['pos_csrf'];
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>G&D · Catálogo de recompensas</title>
<link rel="stylesheet" href="assets/css/gyd_recompensas_v38.css"></head><body>
<header class="top"><div class="top-inner"><div><strong class="wordmark">G&D</strong><span class="eyebrow"> ERP / Centro de fidelización</span><h1>Catálogo de recompensas</h1><p>Configura productos canjeables y controla su costo por sucursal.</p></div><nav><a href="gyd_clientes.php">Control de clientes</a><a href="gyd_canjes.php">Solicitudes de recompensas</a><a href="erp_catalogo_maestro.php">Catálogo Maestro</a><a href="pos.php">Volver al POS</a></nav></div></header>
<main>
 <div class="alert" role="note"><strong>Solo laboratorio · prueba:3307.</strong> Las recompensas se configuran, pero <strong>NINGÚN canje puede realizarse todavía</strong>. Los puntos del cliente son provisionales mientras existan devoluciones parciales sin conciliar. No se modifican precios, ventas, tickets ni existencias.</div>
 <div class="page-grid">
  <section class="panel" aria-labelledby="searchTitle"><div class="section-heading"><h2 id="searchTitle">Seleccionar producto</h2><span class="small-note">Mismo catálogo de artículos del POS</span></div>
    <label for="sucursal">Sucursal</label><select id="sucursal"></select>
    <label for="q">Código, barras o descripción del producto</label><input id="q" maxlength="100" placeholder="Busca por nombre o código (mínimo 2 caracteres)" autocomplete="off" aria-controls="encontrados">
    <div id="encontrados" class="found" aria-live="polite">Selecciona una sucursal y busca un artículo.</div>
  </section>
  <section class="panel" aria-labelledby="configTitle"><div class="section-heading"><h2 id="configTitle">Configurar recompensa</h2><span class="pill">Sin canje</span></div>
    <p id="productoActual" class="selection">Elige un producto del buscador.</p>
    <div class="facts"><div><small>Precio de venta normal</small><strong id="precio">—</strong></div><div><small>Costo máximo de lote</small><strong id="costo">—</strong></div><div><small>Margen bruto estimado</small><strong id="margen">—</strong></div><div><small>Mínimo recomendado</small><strong id="minimo">—</strong></div></div>
    <div id="elegibilidad" class="status">Consulta un producto para evaluar la rentabilidad.</div>
    <form id="configForm" novalidate>
      <label for="puntos">Puntos requeridos para una pieza</label><input id="puntos" type="number" step="1" min="1" max="2000000" required disabled>
      <p class="hint">Fórmula base: 1 punto = $0.10 de reserva futura y el costo del artículo no puede superar 80% de esa reserva. Se exige margen bruto estimado ≥20% y revisión comercial.</p>
      <label for="estado">Estado administrativo</label><select id="estado"><option value="BORRADOR">Borrador</option><option value="ACTIVO">Aprobada para catálogo (sin canje)</option><option value="PAUSADO">Pausada</option></select>
      <label for="observaciones">Observaciones de uso (opcional)</label><textarea id="observaciones" maxlength="240" rows="2" placeholder="Ej. revisar requisitos fiscales y producto apto para promoción"></textarea>
      <label for="motivo">Motivo de configuración o cambio (obligatorio)</label><textarea id="motivo" maxlength="240" minlength="10" rows="2" required placeholder="Ej. Alta aprobada por administración para sucursal"></textarea>
      <button type="submit" id="guardar" disabled>Guardar en catálogo</button>
    </form>
  </section>
 </div>
 <section class="panel" aria-labelledby="listTitle"><div class="section-heading"><h2 id="listTitle">Recompensas configuradas</h2><button class="secondary" type="button" id="refrescar">Actualizar</button></div><p class="small-note">Los importes son referencias de costo y precio; no se descuentan inventarios. Un cambio de costo o precio puede suspender la elegibilidad hasta nueva revisión.</p><div class="table-scroller"><table><thead><tr><th>Producto / sucursal</th><th>Precio</th><th>Costo</th><th>Puntos</th><th>Estado</th><th>Acciones</th></tr></thead><tbody id="registros"><tr><td colspan="6">Cargando catálogo...</td></tr></tbody></table></div></section>
 <section class="panel audit" aria-labelledby="auditTitle"><div class="section-heading"><h2 id="auditTitle">Historial de ajustes</h2><span class="small-note">Últimos 30 movimientos del producto seleccionado</span></div><div id="auditoria">Selecciona un registro para consultar su auditoría.</div></section>
 <div id="feedback" role="alert" aria-live="assertive"></div>
</main>
<script>window.GYD_REWARDS_V38={csrf:<?=json_encode($csrf,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>};</script>
<script src="assets/js/gyd_recompensas_v38.js" defer></script>

<?php if (!defined('GYD_HIDE_GLOBAL_NAV')): ?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=4">
<script>window.GYD_NAV_HOME='index.php';window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=4"></script>
<?php endif; ?>
</body></html>
