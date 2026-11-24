<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/gyd_retiros.php';
require_once __DIR__.'/../app/helpers/gyd_arqueos.php';
requireLogin();
header('Cache-Control: private, no-store, max-age=0');
$u=currentUser()?:[];$uid=(int)($u['id']??0);
if($uid<1 || !in_array(gr_role($u),['CAJERO','ADMINISTRADOR','ADMIN','DIRECCION'],true)){http_response_code(403);exit('Acceso al POS no autorizado.');}
$error='';$caja=0;$turno=null;$info=null;$grant=null;
try{
 $db=(new Database())->connect();gr_test_db($db);arq_comprobar_tablas($db);
 $caja=(int)($_POST['caja_id']??$_GET['caja_id']??0);
 if($caja<1)throw new DomainException('Selecciona una caja desde el POS.');
 $info=gr_permission_caja($db,$u,$caja);
 $turno=gr_turno($db,$caja);
 if(!gr_admin($u) && (int)$turno['usuario_apertura_id']!==$uid)throw new DomainException('Solo el cajero de este turno puede solicitar un arqueo.');
 $turnoId=(int)$turno['id'];
 $accion=(string)($_POST['accion']??'');
 if($_SERVER['REQUEST_METHOD']==='POST'){
  arq_csrf_check($_POST['csrf']??null);
  if($accion==='autorizar'){
   // Se valida SIEMPRE la contraseña contra el hash actual de la cuenta autorizadora.
   $login=trim((string)($_POST['autorizador']??''));
   $password=(string)($_POST['password']??'');
   if(mb_strlen($login,'UTF-8')>120 || strlen($password)>512)throw new DomainException('Credenciales inválidas.');
   $fails=(int)arq_db($db,'SELECT COUNT(*) FROM gyd_arqueos_intentos WHERE solicitante_id=? AND exitoso=0 AND creado_en > DATE_SUB(NOW(),INTERVAL 15 MINUTE)',[$uid])->fetchColumn();
   if($fails>=5)throw new DomainException('Demasiados intentos. Solicita a Administración esperar 15 minutos.');
   $aut=arq_credenciales($db,$login,$password);
   // No guardar contraseñas, ni siquiera si la verificación es fallida.
   arq_db($db,'INSERT INTO gyd_arqueos_intentos(solicitante_id,usuario_intentado,exitoso,ip_hash) VALUES (?,?,?,?)',[
    $uid,mb_substr($login,0,120,'UTF-8'),$aut?1:0,hash('sha256',(string)($_SERVER['REMOTE_ADDR']??''))]);
   unset($password,$_POST['password']);
   if(!$aut)throw new DomainException('Usuario o contraseña de Gerencia/Dirección/Contabilidad no válidos.');
   session_regenerate_id(true);
   $_SESSION['gyd_arqueo_permiso']=[
    'solicitante'=>$uid,'autorizador'=>(int)$aut['id'],'rol'=>(string)$aut['rol'],
    'caja'=>$caja,'turno'=>$turnoId,'vence'=>time()+180,
    'nonce'=>bin2hex(random_bytes(16)),
   ];
   header('Location: pos_arqueos.php?caja_id='.$caja,true,303);exit;
  }
  if($accion==='registrar'){
   $grant=$_SESSION['gyd_arqueo_permiso']??[];
   if(!is_array($grant)||!arq_grant_valido($grant,$uid,$caja,$turnoId,time()))throw new DomainException('Autorización vencida: vuelve a solicitar contraseña del supervisor.');
   if(!hash_equals((string)($grant['nonce']??''),(string)($_POST['nonce']??'')))throw new DomainException('Arqueo inválido o ya confirmado.');
   $resultado=arq_total_denominaciones((array)($_POST['denominaciones']??[]));
   $nota=trim((string)($_POST['observaciones']??''));
   if(mb_strlen($nota,'UTF-8')>450)throw new DomainException('La observación puede tener máximo 450 caracteres.');
   if($nota==='')$nota='Arqueo autorizado con conteo de denominaciones (sin ajuste de caja)';
   $db->beginTransaction();
   try {
    $turno=gr_turno($db,$caja,true); // turno ABIERTO, bloqueado durante esta captura
    if((int)$turno['id']!==$turnoId)throw new DomainException('El turno cambió: requiere autorización nueva.');
    $saldo=gr_expected($db,$turnoId);
    $contado=$resultado['centavos'];$dif=$contado-$saldo['esperado'];
    $clave=bin2hex(random_bytes(16));
    arq_db($db,'INSERT INTO erp_caja_arqueos(operacion_clave,turno_id,usuario_id,esperado_corte,efectivo_contado,diferencia,motivo) VALUES (?,?,?,?,?,?,?)',[
     $clave,$turnoId,$uid,gr_pesos($saldo['esperado']),gr_pesos($contado),gr_pesos($dif),$nota]);
    $arqueoId=(int)$db->lastInsertId();
    arq_db($db,'INSERT INTO gyd_arqueos_autorizaciones(arqueo_id,turno_id,caja_id,solicitante_id,autorizador_id,autorizador_rol,desglose_json) VALUES(?,?,?,?,?,?,?)',[
     $arqueoId,$turnoId,$caja,$uid,(int)$grant['autorizador'],(string)$grant['rol'],json_encode($resultado['detalle'],JSON_THROW_ON_ERROR)]);
    arq_db($db,'INSERT INTO pos_auditoria(usuario_id,venta_id,accion,detalle) VALUES(?,NULL,?,?)',[
     $uid,'ARQUEO_AUTORIZADO',json_encode(['arqueo_id'=>$arqueoId,'turno_id'=>$turnoId,'autorizador_id'=>(int)$grant['autorizador']],JSON_THROW_ON_ERROR)]);
    $db->commit();
    unset($_SESSION['gyd_arqueo_permiso']);
    $_SESSION['gyd_arqueo_flash']='Arqueo #'.$arqueoId.' guardado sin modificar ventas, retiros ni el corte. El resultado está disponible para Gerencia/Administración.';
    header('Location: pos_arqueos.php?caja_id='.$caja.'&resultado=1',true,303);exit;
   }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
  }
  throw new DomainException('Acción no autorizada.');
 }
 $grant=$_SESSION['gyd_arqueo_permiso']??[];
 if(!is_array($grant)||!arq_grant_valido($grant,$uid,$caja,$turnoId,time())){
  unset($_SESSION['gyd_arqueo_permiso']);
  if(isset($_GET['resultado']) && isset($_SESSION['gyd_arqueo_flash'])){
   // Mostrar únicamente constancia final del arqueo, sin habilitar otro conteo.
  }else{http_response_code(403);throw new DomainException('Antes de ingresar, solicita la contraseña de Gerencia/Dirección/Contabilidad desde el botón Arqueo del POS.');}
 }
}catch(Throwable $e){$error=$e instanceof DomainException?$e->getMessage():'No fue posible abrir el arqueo. Consulta el registro del servidor.';error_log('G&D arqueos seguros: '.$e->getMessage());}
$flash=(string)($_SESSION['gyd_arqueo_flash']??'');unset($_SESSION['gyd_arqueo_flash']);
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>G&D · Arqueo protegido</title><link rel="stylesheet" href="assets/css/gyd_arqueo_modal.css?v=20261027"></head><body class="arq-page">
<header><strong>G&D · ARQUEO PROTEGIDO</strong><a href="pos.php">← Regresar al Punto de Venta</a></header>
<main class="arq-main">
<?php if($error):?><div class="arq-error" role="alert"><?=arq_h($error)?></div><?php endif;?>
<?php if($flash):?><div class="arq-success" role="status"><?=arq_h($flash)?></div><?php endif;?>
<?php if(!$error && $grant && $info && $turno):?>
<section class="arq-card"><h1>Conteo físico autorizado</h1>
<p><strong><?=arq_h($info['sucursal'])?> · <?=arq_h($info['caja'])?></strong> · Turno #<?=(int)$turno['id']?></p>
<p class="arq-note">El conteo es <strong>ciego</strong>: no se muestra el efectivo esperado antes de terminar. El resultado se registrará para revisión del supervisor. <strong>No se ajustan saldos ni se realiza retiro o corte.</strong></p>
<form method="post" id="gyd-arq-form"><input type="hidden" name="accion" value="registrar"><input type="hidden" name="csrf" value="<?=arq_h(arq_csrf())?>"><input type="hidden" name="nonce" value="<?=arq_h($grant['nonce'])?>"><input type="hidden" name="caja_id" value="<?=(int)$info['id']?>">
<div class="arq-denoms">
<?php foreach(arq_denominaciones() as $codigo=>$centavos):$etiqueta=($codigo[0]==='b'?'Billete':'Moneda').' · $'.number_format($centavos/100,($centavos%100?2:0),'.',',');?>
<label><?=arq_h($etiqueta)?><input type="number" name="denominaciones[<?=arq_h($codigo)?>]" value="0" min="0" max="100000" step="1" inputmode="numeric" required data-cents="<?=$centavos?>"></label>
<?php endforeach;?>
</div><div class="arq-total">TOTAL CONTADO <strong id="arq-total">$0.00</strong></div>
<label>Observación (opcional)<textarea name="observaciones" maxlength="450" rows="2" placeholder="Incidencias detectadas durante el conteo"></textarea></label>
<button type="submit" class="arq-primary">Guardar arqueo y cerrar acceso</button>
<p class="arq-note">La autorización dura 3 minutos y solo sirve para un arqueo. Si vence, se pedirá la contraseña nuevamente.</p>
</form></section>
<?php endif;?>
</main><script src="assets/js/gyd_arqueo_modal.js?v=20261027" defer></script>
<?php if (!defined('GYD_HIDE_GLOBAL_NAV')): ?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=4">
<script>window.GYD_NAV_HOME='index.php';window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=4"></script>
<?php endif; ?>
</body></html>
