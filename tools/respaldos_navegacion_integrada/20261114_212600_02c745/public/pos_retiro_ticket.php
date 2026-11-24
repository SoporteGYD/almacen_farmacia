<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';require_once __DIR__.'/../app/config/database.php';require_once __DIR__.'/../app/helpers/gyd_retiros_directos.php';
requireLogin();$u=currentUser()?:[];$uid=(int)($u['id']??0);
if($uid<1||!in_array(gr_role($u),['CAJERO','ADMINISTRADOR','ADMIN','DIRECCION'],true)){http_response_code(403);exit('Acceso denegado.');}
try{$db=(new Database())->connect();gr_test_db($db);$id=(int)($_GET['id']??0);$r=gd_detalle_retiro($db,$id);if(!$r)throw new DomainException('Comprobante no encontrado.');gr_permission_caja($db,$u,(int)$r['caja_id']);if(!gr_admin($u)&&(int)$r['cajero_id']!==$uid)throw new DomainException('No tienes permiso de consultar este comprobante.');}catch(Throwable $e){http_response_code(403);exit(gr_h($e instanceof DomainException?$e->getMessage():'No fue posible consultar el comprobante.'));}
$desglose=json_decode((string)$r['desglose_json'],true);if(!is_array($desglose))$desglose=[];
$reimpresion=($_GET['reimpresion']??'')==='1';
$nuevo=!empty($_SESSION['gyd_retiro_v5_exito_'.$id]);unset($_SESSION['gyd_retiro_v5_exito_'.$id]);
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>G&D | <?=gr_h(gd_folio($id))?></title><style>body{font:12px 'Courier New',monospace;margin:0;color:#111;background:#e9eef3}.tools{background:white;padding:12px;text-align:center}.tools button,.tools a{font:14px system-ui;padding:9px 14px;margin:2px}.paper{width:80mm;background:white;padding:4mm;margin:12px auto}.ticket{text-align:center;padding:3mm 0 6mm;border-bottom:2px dashed black}.ticket:last-child{border-bottom:0}.left{text-align:left}.right{text-align:right}.big{font-size:17px;font-weight:bold}.sep{border-top:1px dashed black;margin:7px 0}.label{font-weight:700}p{margin:5px 0}.warn{font-size:10px}h2{font-size:14px;margin:6px 0}@media print{@page{size:80mm auto;margin:1mm}body{background:white}.paper{width:auto;margin:0;padding:1mm}.tools{display:none}.ticket{page-break-after:always;break-after:page}.ticket:last-child{page-break-after:auto;break-after:auto}} </style></head><body>
<div class="tools"><button onclick="window.print()">Imprimir dos copias</button><a href="pos_retiros.php?caja_id=<?=(int)$r['caja_id']?>">Volver a retiros</a><a href="pos_corte_retiros.php?turno_id=<?=(int)$r['turno_id']?>">Ver corte del turno</a><p>El ticket debe coincidir por folio, fecha, hora e importe con el corte de caja.</p></div>
<div class="paper">
<?php foreach(['COPIA DEL CAJERO','COPIA PARA BÓVEDA'] as $copia):?>
<section class="ticket"><h2>G&D FARMACIA</h2><div class="big">RETIRO DE EFECTIVO</div><p><?=gr_h($copia)?></p><?php if($reimpresion):?><div class="big">REIMPRESIÓN</div><?php endif;?><div class="sep"></div>
<p class="big"><?=gr_h(gd_folio($id))?></p><p>Fecha y hora: <?=gr_h($r['creado_en'])?></p>
<p class="left"><span class="label">Sucursal:</span> <?=gr_h($r['sucursal'])?></p><p class="left"><span class="label">Caja:</span> <?=gr_h($r['caja'])?></p><p class="left"><span class="label">Turno:</span> #<?=(int)$r['turno_id']?></p><p class="left"><span class="label">Cajero que abrió caja:</span> <?=gr_h($r['cajero']??'')?></p>
<p class="left"><span class="label">Operó retiro:</span> <?=gr_h($r['operador_nombre']??'Sin dato histórico')?></p>
<p class="left"><span class="label">Autorizó con su clave:</span> <?=gr_h($r['autorizador_nombre']??'Sin dato histórico')?><?=empty($r['autorizador_rol'])?'':' ('.gr_h($r['autorizador_rol']).')'?></p>
<div class="sep"></div><p>IMPORTE RETIRADO DE CAJA</p><p class="big"><?=gr_money(gd_cents($r['importe']))?> MXN</p>
<div class="sep"></div><p class="left">Retiro registrado sin arqueo ni desglose de denominaciones.</p>
<div class="sep"></div><p class="left">Efectivo esperado antes: <?=gr_money(gd_cents($r['esperado_antes']))?></p><p class="left">Efectivo esperado después: <?=gr_money(gd_cents($r['efectivo_despues']))?></p>
<p class="warn">Conserva esta copia. El folio, la hora y el importe deben coincidir con el corte del turno. El ticket no acredita por sí solo la recepción física en bóveda.</p>
<p style="margin-top:17mm">Entregado: _____________________</p><p style="margin-top:8mm">Recibido en bóveda: _____________</p><p style="margin-top:7mm">Fecha/hora de entrega: __________</p></section>
<?php endforeach;?></div><script src="assets/js/gyd_feedback_modal.js?v=5"></script>
<script>
window.addEventListener('load',function(){
 if(<?= $nuevo ? 'true' : 'false' ?>){
   // El registro YA fue confirmado en BD. Imprimir no crea otro retiro.
   let notified=false;
   const done=()=>{if(notified)return;notified=true;window.GYDFeedback.show({type:'success',title:'Retiro registrado correctamente',message:<?=json_encode('Folio '.gd_folio($id).' · Importe '.gr_money(gd_cents($r['importe'])).' · Las dos copias están listas para conservarse.',JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>});};
   window.addEventListener('afterprint',done,{once:true});
   window.setTimeout(function(){window.print();window.setTimeout(done,500);},200);
 }
});</script>
<?php if (!defined('GYD_HIDE_GLOBAL_NAV')): ?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=1">
<script>window.GYD_NAV_HOME='index.php';window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=1"></script>
<?php endif; ?>
</body></html>
