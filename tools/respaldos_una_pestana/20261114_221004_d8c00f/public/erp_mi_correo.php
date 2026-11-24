<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_mail_secure.php';
requireLogin();
$usuario=currentUser() ?: [];
$rol=strtoupper((string)($usuario['rol']??''));
if(!in_array($rol,['ADMINISTRADOR','COMPRAS','DIRECCION'],true)){http_response_code(403);exit('Acceso no autorizado.');}
$uid=(int)($usuario['id']??0);
if($uid<1){http_response_code(403);exit('Sesión de usuario inválida.');}
function cm_e(mixed $s):string{return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
if(empty($_SESSION['erp_mail_csrf']))$_SESSION['erp_mail_csrf']=bin2hex(random_bytes(32));
$csrf=$_SESSION['erp_mail_csrf'];$error='';$ok='';$cuenta=null;
try {
 $pdo=(new Database())->connect();
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($csrf,(string)($_POST['csrf']??'')))throw new RuntimeException('Sesión vencida. Actualiza la página.');
  $accion=(string)($_POST['accion']??'guardar');
  if($accion==='eliminar') {
    $st=$pdo->prepare('DELETE FROM erp_correos_usuario WHERE usuario_id=?');$st->execute([$uid]);$ok='La cuenta de envío fue desvinculada.';
  } elseif($accion==='guardar') {
    $proveedor=(string)($_POST['proveedor']??'');$correo=strtolower(trim((string)($_POST['correo']??'')));
    $secreto=(string)($_POST['secreto']??'');
    if(!in_array($proveedor,['GMAIL','ONECOM'],true))throw new RuntimeException('Selecciona Gmail o one.com. Microsoft 365 requerirá OAuth 2.0.');
    if(!filter_var($correo,FILTER_VALIDATE_EMAIL) || strlen($correo)>254)throw new RuntimeException('Correo electrónico inválido.');
    // La cuenta remitente debe ser del propio usuario, o requerir autorización del administrador.
    $correoLogin=strtolower(trim((string)($usuario['correo']??'')));
    if($correo!==$correoLogin)throw new RuntimeException('El remitente debe coincidir con tu correo registrado en Usuarios. Solicita al administrador que actualice tu perfil.');
    $host=$proveedor==='GMAIL'?'smtp.gmail.com':'send.one.com';
    if($secreto==='') {
      $prev=$pdo->prepare('SELECT proveedor,correo,secreto_cifrado FROM erp_correos_usuario WHERE usuario_id=?');$prev->execute([$uid]);$data=$prev->fetch(PDO::FETCH_ASSOC);
      if(!$data || $data['correo']!==$correo || $data['proveedor']!==$proveedor)throw new RuntimeException('Introduce la credencial SMTP de esta cuenta.');
      $cipher=(string)$data['secreto_cifrado'];
    } else {
      if(strlen($secreto)>1024)throw new RuntimeException('Credencial demasiado larga.');
      $cipher=erpMailEncrypt($secreto);
    }
    $stmt=$pdo->prepare('INSERT INTO erp_correos_usuario(usuario_id,proveedor,correo,smtp_host,smtp_port,secreto_cifrado) VALUES(?,?,?,?,587,?) ON DUPLICATE KEY UPDATE proveedor=VALUES(proveedor),correo=VALUES(correo),smtp_host=VALUES(smtp_host),smtp_port=VALUES(smtp_port),secreto_cifrado=VALUES(secreto_cifrado)');
    $stmt->execute([$uid,$proveedor,$correo,$host,$cipher]);$ok='Cuenta de envío guardada para tu usuario. Prueba el envío desde una orden EMITIDA.';
  }else{throw new RuntimeException('Acción no válida.');}
 }
 $st=$pdo->prepare('SELECT proveedor,correo,actualizado_en FROM erp_correos_usuario WHERE usuario_id=?');$st->execute([$uid]);$cuenta=$st->fetch(PDO::FETCH_ASSOC)?:null;
}catch(Throwable $e){error_log('ERP cuenta correo: '.$e->getMessage());$error=$e instanceof RuntimeException?$e->getMessage():'No se pudo guardar. Comprueba que ejecutaste la migración SQL.';}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ERP G&D | Mi correo de trabajo</title><style>*{box-sizing:border-box}body{margin:0;background:#eef3fa;font-family:system-ui,Arial,sans-serif;color:#15324f}header{background:#14395f;color:#fff;padding:24px max(22px,calc((100% - 900px)/2))}main{max-width:900px;margin:25px auto;padding:0 16px}.card{background:white;padding:26px;border-radius:17px;box-shadow:0 6px 25px #17365815}h1{margin:0;font-size:25px}label{display:block;margin:17px 0 6px;font-weight:700}input,select{width:100%;padding:12px;border-radius:9px;border:1px solid #bccde0;font:inherit}button,.btn{background:#195db6;color:white;padding:12px 18px;border:0;border-radius:9px;font:inherit;cursor:pointer}.btn{display:inline-block;text-decoration:none;background:#e4ecf6;color:#194473}.danger{background:#a51e35}.row{display:flex;flex-wrap:wrap;gap:12px;align-items:center}.msg{background:#fff0e8;padding:12px;border-radius:8px}.ok{background:#e3f7ea}.hint{background:#edf5ff;padding:14px;border-radius:9px;color:#284866}small{color:#64809b}</style></head><body><?php require __DIR__.'/../app/views/layouts/erp_barra_sesion.php'; ?><header><h1>ERP G&amp;D · Mi correo de trabajo</h1><p>Vincula una cuenta emisora para enviar órdenes desde tu propia sesión.</p></header><main><div class="card"><a class="btn" href="erp_ordenes_compra.php">← Órdenes de Compra</a><h2>Cuenta del usuario: <?=cm_e($usuario['nombre']??'')?></h2><p>Correo registrado en Usuarios: <strong><?=cm_e($usuario['correo']??'')?></strong></p><p class="hint">Este módulo no modifica tu contraseña del ERP. Las credenciales SMTP se cifran con una clave local y no se muestran después de guardar. La cuenta deberá permitir envíos SMTP.</p><?php if($error):?><p class="msg" role="alert"><?=cm_e($error)?></p><?php endif;?><?php if($ok):?><p class="msg ok" role="status"><?=cm_e($ok)?></p><?php endif;?><?php if($cuenta):?><p class="ok msg">Cuenta vinculada: <b><?=cm_e($cuenta['correo'])?></b> · <?=cm_e($cuenta['proveedor'])?> · Actualizada: <?=cm_e($cuenta['actualizado_en'])?></p><?php endif;?><form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?=cm_e($csrf)?>"><input type="hidden" name="accion" value="guardar"><label for="proveedor">Proveedor del correo</label><select id="proveedor" name="proveedor"><option value="GMAIL" <?=($cuenta['proveedor']??'')==='GMAIL'?'selected':''?>>Gmail (contraseña de aplicación)</option><option value="ONECOM" <?=($cuenta['proveedor']??'')==='ONECOM'?'selected':''?>>one.com (contraseña del buzón)</option></select><label for="correo">Dirección remitente</label><input id="correo" type="email" name="correo" readonly value="<?=cm_e($usuario['correo']??'')?>"><label for="secreto">Contraseña SMTP / de aplicación</label><input id="secreto" type="password" name="secreto" autocomplete="new-password" placeholder="<?= $cuenta?'Dejar vacío para conservar la existente':'Introduce tu credencial SMTP' ?>"><p><small>Nunca compartas credenciales. Para Microsoft 365 necesitaremos añadir OAuth 2.0 en otra etapa.</small></p><button>Guardar mi cuenta de envío</button></form><?php if($cuenta):?><form method="post" onsubmit="return confirm('¿Desvincular tu cuenta de correo del ERP?')"><input type="hidden" name="csrf" value="<?=cm_e($csrf)?>"><input type="hidden" name="accion" value="eliminar"><p><button class="danger">Desvincular cuenta</button></p></form><?php endif;?></div></main>
<?php /* GYD_NAV_ROLE_V1 */
$__gydNavigationAccount = function_exists('isLoggedIn') && isLoggedIn() && function_exists('currentUser')
    ? (currentUser() ?: []) : [];
$__gydNavigationRole = strtoupper(trim((string)($__gydNavigationAccount['rol_original'] ?? $__gydNavigationAccount['rol'] ?? '')));
$__gydNavigationAllowed = in_array($__gydNavigationRole, ['DIRECCION','ADMINISTRADOR','ADMIN','COMPRAS'], true);
if (!defined('GYD_HIDE_GLOBAL_NAV') && $__gydNavigationAllowed):
$__gydNavigationHome = $__gydNavigationRole === 'COMPRAS' ? 'erp_compras_inicio.php' : 'dashboard.php';
?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=5">
<script>window.GYD_NAV_ENABLED=true;window.GYD_NAV_HOME=<?=json_encode($__gydNavigationHome, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=5"></script>
<?php endif; unset($__gydNavigationAccount,$__gydNavigationRole,$__gydNavigationAllowed,$__gydNavigationHome); ?>
</body></html>
