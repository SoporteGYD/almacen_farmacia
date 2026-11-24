<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/gyd_retiros.php';
requireLogin();$u=currentUser()?:[];$uid=(int)($u['id']??0);
if($uid<1||!in_array(gr_role($u),['CAJERO','ADMINISTRADOR','ADMIN','DIRECCION'],true)){http_response_code(403);exit('Acceso denegado.');}
$error='';$message='';$info=null;$turno=null;$saldo=null;$historial=[];
try{
 $db=(new Database())->connect();gr_test_db($db);
 $caja=(int)($_POST['caja_id']??$_GET['caja_id']??0);
 if($caja<1){
  if(gr_admin($u)){$caja=(int)$db->query('SELECT id FROM pos_cajas WHERE activa=1 ORDER BY id LIMIT 1')->fetchColumn();}
  else{$caja=(int)gr_query($db,'SELECT caja_id FROM pos_usuario_asignaciones WHERE usuario_id=? LIMIT 1',[$uid])->fetchColumn();}
 }
 if($caja<1)throw new DomainException('No tienes una caja asignada.');
 $info=gr_permission_caja($db,$u,$caja);
 if($_SERVER['REQUEST_METHOD']==='POST'){
  gr_post_guard();$db->beginTransaction();
  try{
   $turno=gr_turno($db,$caja,true);
   if(!gr_admin($u)&&(int)$turno['usuario_apertura_id']!==$uid)throw new DomainException('Solo el cajero del turno puede solicitar su retiro.');
   if(gr_query($db,"SELECT id FROM erp_retiros_supervisados WHERE turno_id=? AND estado='SOLICITADO' LIMIT 1",[(int)$turno['id']])->fetchColumn())throw new DomainException('Ya existe un retiro pendiente de confirmar.');
   $saldo=gr_expected($db,(int)$turno['id']);$contado=gr_cents($_POST['contado']??'');$importe=gr_cents($_POST['importe']??'');
   gr_validar_monto($saldo['esperado'],$contado,$importe);
   $nota=trim((string)($_POST['motivo']??''));if(mb_strlen($nota,'UTF-8')>255)throw new DomainException('Motivo demasiado largo.');
   if($nota==='')$nota='Retiro preventivo por límite de $3,000 de caja';
   gr_query($db,'INSERT INTO erp_retiros_supervisados(turno_id,caja_id,cajero_id,contado_declarado,esperado_solicitud,importe,motivo) VALUES(?,?,?,?,?,?,?)',[(int)$turno['id'],$caja,$uid,gr_pesos($contado),gr_pesos($saldo['esperado']),gr_pesos($importe),$nota]);
   $id=(int)$db->lastInsertId();gr_audit($db,$uid,'SOLICITAR_RETIRO_CAJA',['retiro_id'=>$id,'turno'=>(int)$turno['id'],'importe'=>gr_pesos($importe)]);
   $db->commit();$_SESSION['gyd_retiro_flash']='Solicitud #'.$id.' registrada. El efectivo NO se descuenta hasta que el supervisor confirme la entrega.';
   header('Location: pos_retiros.php?caja_id='.$caja,true,303);exit;
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 }
 $message=(string)($_SESSION['gyd_retiro_flash']??'');unset($_SESSION['gyd_retiro_flash']);
 try{$turno=gr_turno($db,$caja);$saldo=gr_expected($db,(int)$turno['id']);$historial=gr_query($db,'SELECT r.*,ur.nombre cajero,ua.nombre supervisor FROM erp_retiros_supervisados r LEFT JOIN usuarios ur ON ur.id=r.cajero_id LEFT JOIN usuarios ua ON ua.id=r.revisor_id WHERE r.turno_id=? ORDER BY r.id DESC LIMIT 25',[(int)$turno['id']])->fetchAll(PDO::FETCH_ASSOC);}catch(DomainException $e){$turno=null;}
}catch(Throwable $e){$error=$e instanceof DomainException?$e->getMessage():'No fue posible consultar retiros. Revisa la instalación SQL.';error_log('GYD retiros POS: '.$e->getMessage());}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>G&D | Arqueo y retiro de caja</title><style>
*{box-sizing:border-box}body{font:15px system-ui,Arial;background:#ecf2f8;color:#143657;margin:0}header{background:#154779;color:white;padding:20px}main{max-width:1040px;margin:22px auto;padding:0 14px}article{background:white;border:1px solid #d8e4ef;padding:20px;border-radius:13px;margin:14px 0}a{color:#13559a}h1,h2{margin:0 0 12px}header a{color:#fff}label{display:block;font-weight:650;margin:14px 0 7px}input,textarea{width:100%;padding:12px;border-radius:8px;border:1px solid #aebfd2;font:inherit}button,.btn{display:inline-block;margin-top:16px;border:0;border-radius:8px;background:#135b9b;color:#fff;text-decoration:none;font-weight:700;padding:12px 16px;cursor:pointer}.metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.metrics div{background:#eff6ff;padding:12px;border-radius:9px}.metrics b{display:block;font-size:23px;margin-top:6px}.warn{padding:12px;border:1px solid #edc574;background:#fff6dd;border-radius:8px}.good{padding:12px;background:#e8f7ee}.error{padding:12px;background:#ffe5e7}.tablewrap{overflow:auto}table{border-collapse:collapse;width:100%;min-width:650px}th,td{border-bottom:1px solid #dbe6f0;text-align:left;padding:9px;font-size:13px}th{background:#195589;color:#fff}@media(max-width:600px){.metrics{grid-template-columns:1fr}}</style></head><body>
<header><h1>Arqueo y retiro preventivo de efectivo</h1><a href="pos.php">← Volver al Punto de Venta</a> <?php if(gr_admin($u)):?> · <a href="erp_retiros_diarios.php">Reporte diario (Supervisión)</a><?php endif;?></header><main>
<?php if($error):?><div class="error" role="alert"><?=gr_h($error)?></div><?php endif;?><?php if($message):?><div class="good"><?=gr_h($message)?></div><?php endif;?>
<?php if($info):?><article><h2><?=gr_h($info['sucursal'])?> · <?=gr_h($info['caja'])?></h2>
<?php if($turno && $saldo):?>
<p><strong>Turno #<?=(int)$turno['id']?></strong> · El sistema muestra el efectivo <em>esperado</em>; el cajero debe contar el dinero físico.</p>
<div class="metrics"><div>Fondo inicial<b><?=gr_money($saldo['fondo'])?></b></div><div>Ventas cobradas en efectivo<b><?=gr_money($saldo['ventas_efectivo'])?></b></div><div>Efectivo esperado en caja<b><?=gr_money($saldo['esperado'])?></b></div></div>
<p class="warn">Límite de aviso: <strong>$3,000.00</strong>. Fondo que debe permanecer: <strong>$1,000.00</strong>. Retiro sugerido: <strong><?=gr_money(gr_sugerencia($saldo['esperado']))?></strong>. Solo una solicitud pendiente por turno; el supervisor valida y confirma la entrega.</p>
<?php if(gr_sugerencia($saldo['esperado'])>0):?>
<form method="post"><input type="hidden" name="csrf" value="<?=gr_h(gr_csrf())?>"><input type="hidden" name="caja_id" value="<?=(int)$info['id']?>">
<label for="contado">Efectivo físico contado antes del retiro ($)</label><input name="contado" id="contado" type="number" min="0" step="0.01" required value="<?=gr_h(gr_pesos($saldo['esperado']))?>">
<label for="importe">Importe a entregar al supervisor ($)</label><input name="importe" id="importe" type="number" min="0.01" step="0.01" required value="<?=gr_h(gr_pesos(gr_sugerencia($saldo['esperado'])))?>">
<label for="motivo">Observación (opcional)</label><textarea id="motivo" name="motivo" maxlength="255" rows="2" placeholder="Detalles del retiro o conteo"></textarea>
<button onclick="return confirm('Confirma el efectivo contado y la solicitud. No entregues el efectivo hasta la confirmación de Supervisión.')">Solicitar retiro</button></form>
<?php else:?><div class="good">Todavía no se alcanza el límite. No es necesario retirar efectivo.</div><?php endif;?>
<?php else:?><div class="warn">No hay turno abierto para esta caja.</div><?php endif;?></article><?php endif;?>
<?php if($historial):?><article><h2>Movimientos solicitados de este turno</h2><div class="tablewrap"><table><thead><tr><th>#</th><th>Solicitado</th><th>Importe</th><th>Estado</th><th>Recibido por</th></tr></thead><tbody><?php foreach($historial as $x):?><tr><td>#<?=(int)$x['id']?></td><td><?=gr_h($x['solicitud_en'])?></td><td><?=gr_money(gr_cents($x['importe']))?></td><td><?=gr_h($x['estado'])?></td><td><?=gr_h($x['supervisor']??'Pendiente')?></td></tr><?php endforeach;?></tbody></table></div></article><?php endif;?>
</main></body></html>
