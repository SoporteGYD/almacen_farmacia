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
  $r=gd_guardar_retiro($db,$u,$caja,gd_cents($_POST['contado']??''),gd_cents($_POST['importe']??''),$token,(array)($_POST['denominaciones']??[]));
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
<p class="warn"><b>Retiro directo, sin autorización previa.</b> Cada retiro imprime dos copias: una para conservar en caja y otra para la bóveda. Antes de entregar cuenta físicamente el dinero. El sistema no modifica las ventas ni hace corte. No se puede utilizar un retiro para ocultar diferencias; para eso existe el arqueo protegido.</p>
<?php if($saldo['fondo']>0&&$saldo['esperado']>$saldo['fondo']):?>
<form method="post" id="retiroForm" autocomplete="off"><input type="hidden" name="csrf" value="<?=gr_h(gr_csrf())?>"><input type="hidden" name="caja_id" value="<?=(int)$info['id']?>"><input type="hidden" name="operacion_clave" value="<?=gr_h($token)?>">
<label for="contado">Efectivo total contado actualmente en caja ($)</label><input id="contado" name="contado" inputmode="decimal" type="text" pattern="[0-9]+(\.[0-9]{1,2})?" required placeholder="Cuenta el efectivo físico antes de retirar">
<label for="importe">Importe que se retira y llevará a bóveda ($)</label><input id="importe" name="importe" inputmode="decimal" type="text" pattern="[0-9]+(\.[0-9]{1,2})?" required placeholder="Ejemplo: 5000.00"><p>Máximo disponible conservando el fondo inicial: <strong><?=gr_money(max(0,$saldo['esperado']-$saldo['fondo']))?></strong>.</p>
<h3>Denominaciones que entregas a bóveda</h3><p>Captura la cantidad de cada billete y moneda del dinero retirado. <b>La suma debe coincidir exactamente con el importe a retirar.</b></p>
<div class="cols" style="grid-template-columns:repeat(auto-fit,minmax(175px,1fr))">
<?php foreach(PosCorteDenominaciones::VALORES as $clave=>$valor):?><label><?=gr_h(($clave[0]==='b'?'Billete de $':'Moneda de $').number_format($valor/100,2,'.',','))?><input type="number" name="denominaciones[<?=gr_h($clave)?>]" min="0" max="99999" step="1" value="0" inputmode="numeric" required></label><?php endforeach;?></div>
<p>Total calculado de billetes y monedas: <strong id="denom_total">$0.00</strong></p>
<button type="submit" id="confirmar">Registrar retiro y generar tickets</button></form>
<script>const valoresDenom=<?=json_encode(PosCorteDenominaciones::VALORES)?>;const formRetiro=document.getElementById('retiroForm');function totalDenoms(){let cents=0;for(const [k,v] of Object.entries(valoresDenom)){const n=Number(formRetiro.querySelector('[name="denominaciones['+k+']"]').value||0);if(!Number.isSafeInteger(n)||n<0)return -1;cents+=n*v;}document.getElementById('denom_total').textContent=(cents/100).toLocaleString('es-MX',{style:'currency',currency:'MXN'});return cents;}formRetiro.addEventListener('input',totalDenoms);document.getElementById('retiroForm').addEventListener('submit',function(e){if(totalDenoms()!==Math.round(Number(document.getElementById('importe').value)*100)){alert('El importe no coincide con las denominaciones del retiro.');e.preventDefault();return;}if(!confirm('Confirma que el dinero se retira físicamente ahora, se entregará en bóveda y el folio quedará registrado en el corte. ¿Continuar?')){e.preventDefault();return;}document.getElementById('confirmar').disabled=true;});</script>
<?php else:?><p class="ok">No hay excedente disponible conservando el fondo inicial. Si la caja se abrió con $0 de fondo, debe regularizarse el fondo operativo antes de retirar.</p><?php endif;?></div>
<div class="card"><h2>Retiros realizados durante este turno</h2><div class="scroll"><table><thead><tr><th>Folio</th><th>Fecha y hora</th><th>Importe</th><th>Verificación bóveda</th><th>Comprobante</th></tr></thead><tbody><?php foreach($retiros as $r):?><tr><td><?=gr_h(gd_folio((int)$r['id']))?></td><td><?=gr_h($r['creado_en'])?></td><td><?=gr_money(gd_cents($r['importe']))?></td><td><?=gr_h($r['boveda_estado'])?></td><td><a href="pos_retiro_ticket.php?id=<?=(int)$r['id']?>&amp;reimpresion=1">Reimprimir</a></td></tr><?php endforeach;?><?php if(!$retiros):?><tr><td colspan="5">Todavía no se han registrado retiros en este turno.</td></tr><?php endif;?></tbody></table></div></div>
<?php endif;?></main></body></html>
