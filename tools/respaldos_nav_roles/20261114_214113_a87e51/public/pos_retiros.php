<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/gyd_retiros_directos.php';
require_once __DIR__.'/../app/helpers/gyd_acceso_previo.php';
requireLogin();$u=currentUser()?:[];$uid=(int)($u['id']??0);
if($uid<1||!in_array(gr_role($u),['CAJERO','ADMINISTRADOR','ADMIN','DIRECCION'],true)){http_response_code(403);exit('Acceso denegado.');}
$error='';$turno=null;$saldo=null;$info=null;$retiros=[];$maximo=0;
try{
 $db=(new Database())->connect();gr_test_db($db);
 $caja=(int)($_POST['caja_id']??$_GET['caja_id']??0);
 if($caja<1){$caja=gr_admin($u)?(int)$db->query('SELECT id FROM pos_cajas WHERE activa=1 ORDER BY id LIMIT 1')->fetchColumn():(int)gr_query($db,'SELECT caja_id FROM pos_usuario_asignaciones WHERE usuario_id=? ORDER BY caja_id LIMIT 1',[$uid])->fetchColumn();}
 if($caja<1)throw new DomainException('No tienes una caja asignada.');
 $info=gr_permission_caja($db,$u,$caja);
 $turno=gr_turno($db,$caja);
 if(!gd_can_operate($u,$turno))throw new DomainException('Este retiro sólo corresponde a la caja del turno o a Administración.');
 gyd_gate_validar('retiro',$u,$caja,(int)$turno['id']);
 // GYD_RETIRO_AUTO_V6: consulta autenticada de saldo, sin registrar movimientos.
 if($_SERVER['REQUEST_METHOD']==='GET' && isset($_GET['saldo_automatico'])) {
  $actual=gd_cash_totals($db,(int)$turno['id']);
  $sugerido=gr_sugerencia($actual['esperado'],$actual['fondo']);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: private, no-store, max-age=0');
  header('X-Content-Type-Options: nosniff');
  echo json_encode(['ok'=>true,'maximoCentavos'=>$sugerido,'fondoCentavos'=>$actual['fondo'],'esperadoCentavos'=>$actual['esperado']],JSON_THROW_ON_ERROR);
  exit;
 }
 if($_SERVER['REQUEST_METHOD']==='POST'){
  gr_post_guard();$token=(string)($_POST['operacion_clave']??'');
  $r=gd_guardar_retiro($db,$u,$caja,gd_cents($_POST['importe']??''),$token);
  unset($_SESSION['gyd_retiro_directo_token']);
  $_SESSION['gyd_retiro_v5_exito_'.(int)$r['id']]=true;
  header('Location: pos_retiro_ticket.php?id='.$r['id'],true,303);exit;
 }
 $saldo=gd_cash_totals($db,(int)$turno['id']);$maximo=gr_sugerencia($saldo['esperado'],$saldo['fondo']);$retiros=gd_retiros_turno($db,(int)$turno['id']);
}catch(Throwable $e){
 $error=$e instanceof DomainException?$e->getMessage():'No fue posible registrar o consultar el retiro. Reintenta sin generar un segundo registro.';
 if($_SERVER['REQUEST_METHOD']==='GET' && str_contains($error,'La autorización expiró')){http_response_code(403);exit('Acceso denegado: ingresa al retiro desde el botón del POS y confirma tu contraseña en el modal. <a href="pos.php">Regresar al POS</a>');}
 error_log('G&D retiro directo: '.$e->getMessage());
 if(isset($db,$turno)&&$db instanceof PDO&&is_array($turno)){
  try{$saldo=gd_cash_totals($db,(int)$turno['id']);$maximo=gr_sugerencia($saldo['esperado'],$saldo['fondo']);$retiros=gd_retiros_turno($db,(int)$turno['id']);}catch(Throwable $ignored){}
 }
}
if(empty($_SESSION['gyd_retiro_directo_token']))$_SESSION['gyd_retiro_directo_token']=bin2hex(random_bytes(16));
$token=(string)$_SESSION['gyd_retiro_directo_token'];
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>G&D | Retiro de efectivo</title><style>*{box-sizing:border-box}body{font:15px system-ui,Arial;background:#edf3f8;color:#173b60;margin:0}header{background:#154876;color:white;padding:20px}header a{color:white}main{max-width:1060px;margin:20px auto;padding:0 12px}.card{background:white;border:1px solid #d8e4ef;border-radius:12px;margin:12px 0;padding:20px}.cols{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.metric{background:#eff6ff;padding:12px;border-radius:9px}.metric strong{display:block;font-size:22px;margin-top:6px}input{display:block;width:100%;padding:13px;border-radius:8px;border:1px solid #abc2d9;font:inherit}label{display:block;font-weight:650;margin:15px 0 6px}button,.button{padding:12px 16px;border:0;border-radius:8px;background:#135b99;color:white;font-weight:700;text-decoration:none;cursor:pointer;display:inline-block;margin-top:14px}.warn{padding:12px;background:#fff3dc;border-radius:8px}.ok{padding:12px;background:#eaf8ee}.error{padding:12px;background:#ffe8ea}.scroll{overflow:auto}table{border-collapse:collapse;width:100%;min-width:660px}th,td{padding:9px;border-bottom:1px solid #dbe7f0;text-align:left;font-size:13px}th{background:#195a90;color:white}@media(max-width:650px){.cols{grid-template-columns:1fr}}</style></head><body>
<header><h1>Retiro de efectivo · Sin corte de caja</h1><a href="pos.php">← Regresar al POS</a> · <a href="pos_corte_retiros.php">Consultar corte / retiros</a></header>
<main><?php if($error):?><p class="error" role="alert" id="gyd-retiro-error" data-gyd-error="1"><?=gr_h($error)?><?php if($saldo):?>. Máximo disponible conservando tu fondo inicial: <strong><?=gr_money($maximo)?></strong>.<?php endif;?></p><?php endif;?>
<?php if($turno&&$saldo&&$info):?>
<div class="card"><h2><?=gr_h($info['sucursal'])?> · <?=gr_h($info['caja'])?></h2><p>Turno #<?=(int)$turno['id']?> · Cajero: <?=gr_h($u['nombre']??'')?></p>
<div class="cols"><div class="metric">Fondo inicial<strong><?=gr_money($saldo['fondo'])?></strong></div><div class="metric">Retiros ya registrados<strong><?=gr_money($saldo['retiros'])?></strong></div><div class="metric">Efectivo esperado<strong><?=gd_signed($saldo['esperado'])?></strong></div></div>
<p class="warn"><b>Retiro autorizado desde el POS.</b> La cuenta autorizadora fue verificada en el POS y quedará registrada en el comprobante. No necesitas volver a escribirla. Se calcula el retiro automáticamente sin tocar el fondo inicial; confirma e imprime las copias para cajero y bóveda.</p>

<form method="post" id="retiroForm" autocomplete="off" data-maximo-centavos="<?=$maximo?>" data-fondo-centavos="<?=$saldo['fondo']?>"><input type="hidden" name="csrf" value="<?=gr_h(gr_csrf())?>"><input type="hidden" name="caja_id" value="<?=(int)$info['id']?>"><input type="hidden" name="operacion_clave" value="<?=gr_h($token)?>">
<label for="importe">Importe calculado para retirar ($)</label><input id="importe" name="importe" type="text" readonly aria-readonly="true" required value="<?=gr_pesos($maximo)?>" style="font-weight:800;font-size:25px;background:#f1f7ff;border:2px solid #6fa7d8">
<p>Retiro automático: <strong id="gyd-importemax"><?=gr_money($maximo)?></strong>. Se conservará el fondo inicial de <strong><?=gr_money($saldo['fondo'])?></strong>.</p><p id="gyd-estatus-saldo" aria-live="polite" style="font-size:13px;color:#47647f">Se revisará el saldo nuevamente antes de registrar el retiro.</p>
<button type="submit" id="confirmar">Registrar e imprimir ticket</button></form>
<script src="assets/js/gyd_feedback_modal.js?v=5" defer></script>
<script>
(function(){
 // GYD_RETIRO_AUTO_V6: monto precargado y verificación de saldo antes del envío.
 const f=document.getElementById('retiroForm'),b=document.getElementById('confirmar');
 const i=document.getElementById('importe'),label=document.getElementById('gyd-importemax');
 const estado=document.getElementById('gyd-estatus-saldo');
 let max=Number(f.dataset.maximoCentavos)||0,ocupado=false,finalizando=false;
 const mxn=c=>(c/100).toLocaleString('es-MX',{style:'currency',currency:'MXN'});
 function mostrarError(m){if(window.GYDFeedback&&typeof window.GYDFeedback.show==='function'){window.GYDFeedback.show({type:'error',title:'Retiro no realizado',message:m});}else{alert(m);}}
 function pintar(n){max=n;i.value=(n/100).toFixed(2);f.dataset.maximoCentavos=String(n);label.textContent=mxn(n);estado.textContent=n>0?'Listo para registrar sin tocar el fondo inicial.':'No hay excedente para retirar sin tocar el fondo inicial.';}
 async function consultarSaldo(){
  const u=new URL(window.location.href);u.searchParams.set('saldo_automatico','1');
  const response=await fetch(u.toString(),{cache:'no-store',credentials:'same-origin',headers:{Accept:'application/json'}});
  if(!response.ok)throw new Error('No se pudo consultar el saldo actual.');
  const json=await response.json(),nuevo=Number(json.maximoCentavos);
  if(json.ok!==true||!Number.isSafeInteger(nuevo)||nuevo<0)throw new Error('El servidor devolvió un saldo no válido.');
  const cambio=nuevo!==max;pintar(nuevo);return cambio;
 }
 f.addEventListener('submit',async function(e){
  e.preventDefault();if(ocupado)return;ocupado=true;b.disabled=true;b.textContent='Comprobando saldo...';
  try{
   const cambio=await consultarSaldo();
   if(cambio){mostrarError('El saldo de la caja cambió. El retiro se actualizó a '+mxn(max)+'. Revisa el importe y pulsa nuevamente Registrar e imprimir ticket.');return;}
   if(max<=0){mostrarError('No hay importe disponible para retiro. Debes conservar el fondo inicial de la caja.');return;}
   if(max>99999999999){mostrarError('Importe demasiado grande para el registro del POS. Solicita revisión del saldo.');return;}
   i.value=(max/100).toFixed(2);finalizando=true;b.textContent='Registrando retiro...';
   HTMLFormElement.prototype.submit.call(f);
  }catch(err){mostrarError('No se ha registrado ningún retiro. '+(err&&err.message?err.message:'No se pudo verificar el saldo.'));}
  finally{if(!finalizando){ocupado=false;b.disabled=false;b.textContent='Registrar e imprimir ticket';}}
 });
 function refrescar(){if(!ocupado)consultarSaldo().catch(()=>{estado.textContent='No se pudo actualizar el saldo; se verificará antes de registrar.';});}
 window.setInterval(refrescar,15000);window.addEventListener('focus',refrescar);
 pintar(max);
 const err=document.getElementById('gyd-retiro-error');
 if(err){window.addEventListener('DOMContentLoaded',()=>{const message=err.innerText.trim();if(window.GYDFeedback){err.hidden=true;mostrarError(message);}});}
})();
</script>
</div>
<div class="card"><h2>Retiros realizados durante este turno</h2><div class="scroll"><table><thead><tr><th>Folio</th><th>Fecha y hora</th><th>Importe</th><th>Autorizó</th><th>Comprobante</th></tr></thead><tbody><?php foreach($retiros as $r):?><tr><td><?=gr_h(gd_folio((int)$r['id']))?></td><td><?=gr_h($r['creado_en'])?></td><td><?=gr_money(gd_cents($r['importe']))?></td><td><?=gr_h($r['autorizador_nombre']??'Sin dato histórico')?></td><td><a href="pos_retiro_ticket.php?id=<?=(int)$r['id']?>&amp;reimpresion=1">Reimprimir</a></td></tr><?php endforeach;?><?php if(!$retiros):?><tr><td colspan="5">Todavía no se han registrado retiros en este turno.</td></tr><?php endif;?></tbody></table></div></div>
<?php endif;?></main>
<?php if (!defined('GYD_HIDE_GLOBAL_NAV')): ?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=4">
<script>window.GYD_NAV_HOME='index.php';window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=4"></script>
<?php endif; ?>
</body></html>
