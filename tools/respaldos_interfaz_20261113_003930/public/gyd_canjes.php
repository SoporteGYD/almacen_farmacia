<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
requireLogin();
$u=currentUser()??[];
$rol=strtoupper((string)($u['rol']??''));
if(!in_array($rol,['ADMINISTRADOR','ADMIN','DIRECCION','CAJERO'],true)){
    http_response_code(403);exit('Esta página requiere una cuenta autorizada para el POS.');
}
if(empty($_SESSION['pos_csrf']))$_SESSION['pos_csrf']=bin2hex(random_bytes(32));
?><!doctype html>
<html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>G&D · Solicitudes de recompensas V39</title>
<link rel="stylesheet" href="assets/css/gyd_canjes_v39.css">
</head><body>
<header><div class="wrap header"><div><div class="brand">G&D <small>Fidelización de clientes</small></div><h1>Recompensas desde el POS <span>V39</span></h1><p>Consulta puntos y prepara solicitudes de productos canjeables.</p></div><nav><a href="pos.php">Punto de venta</a><a href="gyd_clientes.php">Control de clientes</a><?php if(in_array($rol,['ADMINISTRADOR','ADMIN','DIRECCION'],true)): ?><a href="gyd_lab_v41.php">Canjear premios (laboratorio V43)</a><a href="gyd_lab_v40.php">Editar márgenes V40</a><?php endif; ?><?php if(in_array($rol,['ADMINISTRADOR','ADMIN','DIRECCION'],true)): ?><a href="gyd_recompensas.php">Administrar catálogo</a><?php endif; ?></nav></div></header>
<main class="wrap">
<div class="important" role="alert"><strong>LABORATORIO · prueba:3307 · NO ES UN CANJE.</strong> Esta V39 únicamente simula el resultado y registra solicitudes pendientes. NO reserva ni descuenta puntos, NO modifica existencias y NO autoriza entregar artículos. Las devoluciones parciales siguen pendientes de integración. <?php if(in_array($rol,['ADMINISTRADOR','ADMIN','DIRECCION'],true)): ?><a href="gyd_lab_v41.php" style="font-weight:bold;text-decoration:underline">Abrir canjes virtuales V43 con productos disponibles</a><?php endif; ?></div>
<div class="columns">
<section class="card">
<h2><span class="number">1</span> Sucursal y caja autorizada</h2>
<label for="caja">Caja</label><select id="caja"><option value="">Cargando cajas...</option></select>
<h2><span class="number">2</span> Buscar cliente</h2>
<label for="q">Nombre, código o teléfono</label><input id="q" placeholder="Mínimo 2 caracteres" maxlength="100" autocomplete="off">
<div id="clientes" class="list" aria-live="polite">Busca un cliente registrado en G&D.</div>
<div id="seleccion" class="selection">Ningún cliente seleccionado.</div>
</section>
<section class="card">
<h2><span class="number">3</span> Elegir recompensa</h2>
<p class="muted">Se muestran los artículos aprobados en V38 para la sucursal de tu caja. No incluye artículos fuera del programa.</p>
<div id="ofertas" class="list" aria-live="polite">Selecciona una caja para consultar recompensas.</div>
<h2><span class="number">4</span> Simulación</h2>
<div id="cotizacion" class="selection">Elige un cliente y una recompensa para calcular.</div>
<button id="solicitar" disabled>Registrar solicitud de prueba</button>
<p class="footnote">Las solicitudes registradas no se convertirán automáticamente en canjes. Habrá una nueva validación cuando se active el flujo real.</p>
</section>
</div>
<section class="card"><div class="section-head"><h2>Solicitudes de prueba e historial</h2><button type="button" class="secondary" id="recargar">Actualizar</button></div><div id="historial" class="scroller">Selecciona una caja para ver el historial.</div></section>
<p id="mensaje" aria-live="assertive" role="alert"></p>
</main>
<script>window.GYD_CANJES_V39={csrf:<?=json_encode((string)$_SESSION['pos_csrf'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>};</script>
<script defer src="assets/js/gyd_canjes_v39.js"></script>
</body></html>
