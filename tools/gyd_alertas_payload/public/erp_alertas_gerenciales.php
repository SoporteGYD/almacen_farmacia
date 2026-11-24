<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/gyd_alertas_gerenciales.php';
requireLogin();$u=currentUser()?:[];
if(!gag_role($u)){http_response_code(403);exit('No tienes permiso para consultar alertas gerenciales.');}
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
if(empty($_SESSION['gyd_alertas_csrf']))$_SESSION['gyd_alertas_csrf']=bin2hex(random_bytes(32));
$csrf=$_SESSION['gyd_alertas_csrf'];
$sucursal=filter_var($_GET['sucursal']??'0',FILTER_VALIDATE_INT);if($sucursal===false||$sucursal<0)$sucursal=0;
$dias=filter_var($_GET['dias']??'7',FILTER_VALIDATE_INT);if(!in_array($dias,[7,15,30],true))$dias=7;
$error='';$feed=null;
try{$feed=gag_feed((new Database())->connect(),$u,$sucursal,$dias);}catch(DomainException $e){$error=$e->getMessage();}catch(Throwable $e){error_log('Alertas gerenciales: '.$e->getMessage());$error='No se pudo cargar la información. Revisa el registro PHP y la instalación de las tablas del POS.';}
$branches=$feed['sucursales']??[];
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Centro de Alertas Gerenciales · Farmacia G&amp;D</title>
<link rel="stylesheet" href="assets/gyd_alertas_gerenciales.css"></head>
<body><main class="wrap" id="app" data-csrf="<?=gag_h($csrf)?>">
<header class="header"><div><div class="eyebrow">ERP G&amp;D · Supervisión de cajas</div><h1>Centro de Alertas Gerenciales</h1><p>Alertas de operación basadas en los movimientos registrados. Sin duplicar notificaciones ni modificar saldos.</p></div>
<nav class="nav"><a href="dashboard.php">Inicio</a><a href="erp_auditoria_cajas_v12.php">Auditoría de Cajas</a><a href="erp_reportes_ventas_cajas.php">Reportes</a></nav></header>
<?php if($error):?><div class="error" role="alert"><?=gag_h($error)?></div><?php else:?>
<form class="filters" method="get" id="filters"><div><label for="sucursal">Sucursal</label><select name="sucursal" id="sucursal"><option value="0">Todas mis sucursales</option>
<?php foreach($branches as $s):?><option value="<?=(int)$s['id']?>" <?=(int)$s['id']===$sucursal?'selected':''?>><?=gag_h($s['nombre'])?></option><?php endforeach;?></select></div>
<div><label for="dias">Periodo de incidencias</label><select name="dias" id="dias"><option value="7" <?=$dias===7?'selected':''?>>Últimos 7 días</option><option value="15" <?=$dias===15?'selected':''?>>Últimos 15 días</option><option value="30" <?=$dias===30?'selected':''?>>Últimos 30 días</option></select></div>
<div><label for="estado">Estado</label><select id="estado"><option value="pendientes">Pendientes</option><option value="todas">Todas</option><option value="atendidas">Atendidas</option></select></div>
<div class="actions"><button type="submit">Aplicar filtros</button><button type="button" id="refresh" class="outline">Actualizar</button><button type="button" id="print" class="outline">Imprimir / PDF</button></div></form>
<section class="stats" aria-label="Resumen de alertas"><div class="stat"><span>Pendientes</span><strong id="countPending"><?=(int)$feed['pendientes']?></strong></div><div class="stat"><span>Atendidas en la vista</span><strong id="countDone"><?=(int)$feed['atendidas']?></strong></div><div class="stat"><span>Total en la vista</span><strong id="countTotal"><?=count($feed['alertas'])?></strong></div></section>
<div class="message" id="message" role="status" aria-live="polite"></div>
<div id="warnings" class="warnings"><?php foreach($feed['avisos'] as $adv):?><div class="warning"><?=gag_h($adv)?></div><?php endforeach;?></div>
<section class="panel"><div class="panel-title"><h2>Incidencias y seguimiento</h2><small>Actualizado: <span id="stamp"><?=gag_h($feed['consultado_en'])?></span> · Consulta automática cada 60 segundos</small></div>
<div id="alerts" class="alerts" aria-live="polite">
<?php foreach($feed['alertas'] as $e):?>
 <article class="alert <?=gag_h($e['atendida']?'done':'')?>" data-estado="<?=($e['atendida']?'atendidas':'pendientes')?>">
 <div class="alert-head"><span class="severity <?=gag_h($e['nivel'])?>"><?=gag_h(strtoupper($e['nivel']))?></span><span class="scope"><?=gag_h($e['sucursal'])?></span><time><?=gag_h($e['fecha'])?></time></div>
 <h3><?=gag_h($e['titulo'])?></h3><p><?=gag_h($e['detalle'])?></p>
 <div class="alert-footer"><span><?= $e['atendida']?'Atendida por '.gag_h($e['atendida_por']).' · '.gag_h($e['atendida_en']):($e['atendible']?'Pendiente de revisión':'Se resuelve con el retiro correspondiente')?></span>
 <div><?php if($e['url']):?><a class="view" href="<?=gag_h($e['url'])?>">Ir al módulo</a><?php endif;?>
 <?php if(!$e['atendida']&&$e['atendible']):?><button class="ack" data-clave="<?=gag_h($e['clave'])?>" type="button">Marcar atendida</button><?php endif;?></div></div></article>
<?php endforeach;?></div><p id="empty" class="empty" hidden>No hay alertas para los filtros seleccionados.</p></section>
<div class="footer">Las alertas de efectivo reflejan el cálculo del POS. Las de seguridad se muestran globalmente porque sus registros no identifican sucursal. Marcar una alerta como atendida no cierra turnos, no autoriza retiros ni modifica dinero.</div>
<script src="assets/gyd_alertas_gerenciales.js" defer></script>
<?php endif;?></main></body></html>
