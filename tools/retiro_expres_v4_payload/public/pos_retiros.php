<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/gyd_retiros_directos.php';
requireLogin();$u=currentUser()?:[];$uid=(int)($u['id']??0);
if($uid<1||!in_array(gr_role($u),['CAJERO','ADMINISTRADOR','ADMIN','DIRECCION'],true)){http_response_code(403);exit('Acceso denegado.');}
$error='';$turno=null;$saldo=null;$info=null;$retiros=[];
try{
 $db=(new Database())->connect();gr_test_db($db);
 $caja=(int)($_POST['caja_id']??$_GET['caja_id']??0);
 if($caja<1){$caja=gr_admin($u)?(int)$db->query('SELECT id FROM pos_cajas WHERE activa=1 ORDER BY id LIMIT 1')->fetchColumn():(int)gr_query($db,'SELECT caja_id FROM pos_usuario_asignaciones WHERE usuario_id=? ORDER BY caja_id LIMIT 1',[$uid])->fetchColumn();}
 if($caja<1)throw new DomainException('No tienes una caja asignada.');
 $info=gr_permission_caja($db,$u,$caja);
 $turno=gr_turno($db,$caja);
 if(!gd_can_operate($u,$turno))throw new DomainException('Solo el cajero del turno puede registrar sus retiros.');
 if($_SERVER['REQUEST_METHOD']==='POST'){
  gr_post_guard();$token=(string)($_POST['operacion_clave']??'');
  $r=gd_guardar_retiro($db,$u,$caja,gd_cents($_POST['importe']??''),$token);
  unset($_SESSION['gyd_retiro_directo_token']);
  header('Location: pos_retiro_ticket.php?id='.$r['id'],true,303);exit;
 }
 $saldo=gd_cash_totals($db,(int)$turno['id']);$retiros=gd_retiros_turno($db,(int)$turno['id']);
}catch(Throwable $e){$error=$e instanceof DomainException?$e->getMessage():'No fue posible consultar retiros. Comprueba los módulos instalados.';error_log('G&D retiro directo: '.$e->getMessage());}
if(empty($_SESSION['gyd_retiro_directo_token']))$_SESSION['gyd_retiro_directo_token']=bin2hex(random_bytes(16));
$token=(string)$_SESSION['gyd_retiro_directo_token'];
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>G&D | Retiro de efectivo</title><style>*{box-sizing:border-box}body{font:15px system-ui,Arial;background:#edf3f8;color:#173b60;margin:0}header{background:#154876;color:white;padding:20px}header a{color:white}main{max-width:1060px;margin:20px auto;padding:0 12px}.card{background:white;border:1px solid #d8e4ef;border-radius:12px;margin:12px 0;padding:20px}.cols{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.metric{background:#eff6ff;padding:12px;border-radius:9px}.metric strong{display:block;font-size:22px;margin-top:6px}input{display:block;width:100%;padding:13px;border-radius:8px;border:1px solid #abc2d9;font:inherit}label{display:block;font-weight:650;margin:15px 0 6px}button,.button{padding:12px 16px;border:0;border-radius:8px;background:#135b99;color:white;font-weight:700;text-decoration:none;cursor:pointer;display:inline-block;margin-top:14px}.warn{padding:12px;background:#fff3dc;border-radius:8px}.ok{padding:12px;background:#eaf8ee}.error{padding:12px;background:#ffe8ea}.scroll{overflow:auto}table{border-collapse:collapse;width:100%;min-width:660px}th,td{padding:9px;border-bottom:1px solid #dbe7f0;text-align:left;font-size:13px}th{background:#195a90;color:white}@media(max-width:650px){.cols{grid-template-columns:1fr}}</style></head><body>
<header><h1>Retiro de efectivo · Sin corte de caja</h1><a href="pos.php">← Regresar al POS</a> · <a href="pos_corte_retiros.php">Consultar corte / retiros</a></header>
<main><?php if($error):?><p class="error" role="alert"><?=gr_h($error)?></p><?php endif;?>
<?php if($turno&&$saldo&&$info):?>
<div class="card"><h2><?=gr_h($info['sucursal'])?> · <?=gr_h($info['caja'])?></h2><p>Turno #<?=(int)$turno['id']?> · Cajero: <?=gr_h($u['nombre']??'')?></p>
<div class="cols"><div class="metric">Fondo inicial<strong><?=gr_money($saldo['fondo'])?></strong></div><div class="metric">Retiros ya registrados<strong><?=gr_money($saldo['retiros'])?></strong></div><div class="metric">Efectivo esperado<strong><?=gd_signed($saldo['esperado'])?></strong></div></div>
<p class="warn"><b>Retiro rápido sin corte ni autorización.</b> Escribe solamente la cantidad que vas a retirar. Se conserva el fondo inicial y el sistema genera el folio automáticamente. Se imprimen dos copias del comprobante: cajero y bóveda.</p>
<?php if($saldo['fondo']>0&&$saldo['esperado']>$saldo['fondo']):?>
<form method="post" id="retiroForm" autocomplete="off"><input type="hidden" name="csrf" value="<?=gr_h(gr_csrf())?>"><input type="hidden" name="caja_id" value="<?=(int)$info['id']?>"><input type="hidden" name="operacion_clave" value="<?=gr_h($token)?>">
<label for="importe">¿Cuánto vas a retirar? ($)</label><input id="importe" name="importe" inputmode="decimal" type="text" pattern="[0-9]+(\.[0-9]{1,2})?" required placeholder="Ejemplo: 5000.00" autofocus>
<p>Máximo permitido conservando tu fondo inicial: <strong><?=gr_money(max(0,$saldo['esperado']-$saldo['fondo']))?></strong>.</p>
<button type="submit" id="confirmar">Registrar e imprimir ticket</button></form>
<script>document.getElementById('retiroForm').addEventListener('submit',function(){document.getElementById('confirmar').disabled=true;});</script>
<?php else:?><p class="ok">Por ahora no hay excedente disponible sobre el fondo inicial. El retiro se habilita cuando haya efectivo adicional registrado.</p><?php endif;?></div>
<div class="card"><h2>Retiros realizados durante este turno</h2><div class="scroll"><table><thead><tr><th>Folio</th><th>Fecha y hora</th><th>Importe</th><th>Verificación bóveda</th><th>Comprobante</th></tr></thead><tbody><?php foreach($retiros as $r):?><tr><td><?=gr_h(gd_folio((int)$r['id']))?></td><td><?=gr_h($r['creado_en'])?></td><td><?=gr_money(gd_cents($r['importe']))?></td><td><?=gr_h($r['boveda_estado'])?></td><td><a href="pos_retiro_ticket.php?id=<?=(int)$r['id']?>&amp;reimpresion=1">Reimprimir</a></td></tr><?php endforeach;?><?php if(!$retiros):?><tr><td colspan="5">Todavía no se han registrado retiros en este turno.</td></tr><?php endif;?></tbody></table></div></div>
<?php endif;?></main></body></html>
