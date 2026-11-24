<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/helpers/gyd_acl.php';
requireLogin();
$operador=currentUser()??[];
$rolReal=gyd_acl_rol_real($operador);
if(!in_array($rolReal,['ADMINISTRADOR','DIRECCION'],true)){
    http_response_code(403);exit('Acceso reservado a Administración y Dirección.');
}
require_once __DIR__.'/../app/config/database.php';
try { $db=(new Database())->connect(); }
catch(Throwable $e){http_response_code(503);exit('No se pudo acceder a MySQL.');}
$catalogo=gyd_acl_catalogo();
$roles=['ADMINISTRADOR','DIRECCION','GERENTE','ENCARGADO','JEFE_ALMACEN','CAJERO','COMPRAS','CONSULTA'];
$mensajes=[];$error='';
if(!isset($_SESSION['gyd_acl_csrf']))$_SESSION['gyd_acl_csrf']=bin2hex(random_bytes(32));
$csrf=(string)$_SESSION['gyd_acl_csrf'];
$e=static fn($v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$rolElegido=strtoupper(trim((string)($_GET['rol']??'GERENTE')));
if(!in_array($rolElegido,$roles,true))$rolElegido='GERENTE';
$usuarioElegido=(int)($_GET['usuario_id']??0);
try{
    $usuarios=$db->query('SELECT id,nombre,usuario,rol,estado,almacen_id FROM usuarios ORDER BY estado DESC,nombre ASC')->fetchAll(PDO::FETCH_ASSOC);
    if($usuarioElegido>0 && !in_array($usuarioElegido,array_map('intval',array_column($usuarios,'id')),true))$usuarioElegido=0;
    $opcionesSucursal=[];
    $q=$db->query('SELECT a.usuario_id,s.nombre AS sucursal,c.nombre AS caja FROM pos_usuario_asignaciones a JOIN pos_sucursales s ON s.id=a.sucursal_id JOIN pos_cajas c ON c.id=a.caja_id');
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$opcionesSucursal[(int)$row['usuario_id']]=$row['sucursal'].' / '.$row['caja'];
    $almacenes=$db->query('SELECT id,nombre FROM almacenes')->fetchAll(PDO::FETCH_KEY_PAIR);
}catch(Throwable $ex){error_log('ACL usuarios: '.$ex->getMessage());http_response_code(503);exit('Falta una tabla del ERP para administrar permisos. Revisa la instalación.');}
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
    if(!hash_equals($csrf,(string)($_POST['csrf']??'')))throw new DomainException('Formulario caducado. Recarga la página.');
    $password=(string)($_POST['clave_actual']??'');
    if($password===''||strlen($password)>200)throw new DomainException('Confirma tu contraseña para guardar los permisos.');
    $p=$db->prepare('SELECT password,estado,rol FROM usuarios WHERE id=?');$p->execute([(int)$operador['id']]);
    $actor=$p->fetch(PDO::FETCH_ASSOC);
    if(!$actor||(int)$actor['estado']!==1||!in_array(strtoupper((string)$actor['rol']),['ADMINISTRADOR','DIRECCION'],true)||!password_verify($password,(string)$actor['password']))
        throw new DomainException('Contraseña incorrecta o cuenta sin autorización.');
    $tipo=(string)($_POST['tipo']??'');
    $identificador='';$campo='';$tabla='';$prev=[];
    if($tipo==='rol'){
        $identificador=strtoupper((string)($_POST['rol_objetivo']??''));
        if(!in_array($identificador,$roles,true)||$identificador==='ADMINISTRADOR')throw new DomainException('No puedes modificar las restricciones del administrador.');
        $rolElegido=$identificador;$tabla='gyd_acl_roles';$campo='rol';
    }elseif($tipo==='usuario'){
        $identificador=(string)(int)($_POST['usuario_objetivo']??0);
        $usuarioElegido=(int)$identificador;
        if($usuarioElegido<=0 || !in_array($usuarioElegido,array_map('intval',array_column($usuarios,'id')),true))throw new DomainException('Selecciona una cuenta válida.');
        $targetRole='';foreach($usuarios as $ud)if((int)$ud['id']===$usuarioElegido)$targetRole=strtoupper((string)$ud['rol']);
        if($targetRole==='ADMINISTRADOR')throw new DomainException('El administrador no admite restricciones adicionales.');
        $tabla='gyd_acl_usuarios';$campo='usuario_id';
    }else throw new DomainException('Acción desconocida.');
    $new=[];
    foreach($catalogo as $modulo=>$meta){
        $raw=strtoupper((string)($_POST['permiso'][$modulo]??'HEREDAR'));
        if(!in_array($raw,['HEREDAR','ALLOW','DENY'],true))throw new DomainException('Valor de permiso no válido.');
        if($raw!=='HEREDAR')$new[$modulo]=$raw;
    }
    $db->beginTransaction();
    $get=$db->prepare('SELECT modulo,decision FROM '.$tabla.' WHERE '.$campo.'=? FOR UPDATE');
    $get->execute([$identificador]);foreach($get->fetchAll(PDO::FETCH_ASSOC) as $r)$prev[$r['modulo']]=$r['decision'];
    $db->prepare('DELETE FROM '.$tabla.' WHERE '.$campo.'=?')->execute([$identificador]);
    $ins=$db->prepare('INSERT INTO '.$tabla.'('.$campo.',modulo,decision) VALUES(?,?,?)');
    foreach($new as $k=>$v)$ins->execute([$identificador,$k,$v]);
    $log=$db->prepare('INSERT INTO gyd_acl_bitacora(actor_id,tipo,objetivo,antes_json,despues_json,direccion_ip) VALUES(?,?,?,?,?,?)');
    $log->execute([(int)$operador['id'],$tipo,$identificador,json_encode($prev,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),json_encode($new,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),substr((string)($_SERVER['REMOTE_ADDR']??''),0,45)]);
    $db->commit();$mensajes[]='Permisos guardados. Las nuevas restricciones se aplicarán en la siguiente solicitud del usuario.';
 }catch(Throwable $ex){if($db->inTransaction())$db->rollBack();$error=$ex instanceof DomainException?$ex->getMessage():'No fue posible guardar los permisos. Revisa el registro de errores.';error_log('ACL guardado: '.$ex->getMessage());}
}
$rolPolicies=[];$userPolicies=[];$logs=[];
try{
  $s=$db->prepare('SELECT modulo,decision FROM gyd_acl_roles WHERE rol=?');$s->execute([$rolElegido]);foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r)$rolPolicies[$r['modulo']]=$r['decision'];
  if($usuarioElegido>0){$s=$db->prepare('SELECT modulo,decision FROM gyd_acl_usuarios WHERE usuario_id=?');$s->execute([$usuarioElegido]);foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r)$userPolicies[$r['modulo']]=$r['decision'];}
  $logs=$db->query('SELECT b.creado_en,b.tipo,b.objetivo,u.nombre AS actor FROM gyd_acl_bitacora b LEFT JOIN usuarios u ON u.id=b.actor_id ORDER BY b.id DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
}catch(Throwable $ex){error_log('ACL lectura: '.$ex->getMessage());$error='No se pudieron consultar las tablas de permisos.';}
$nombreUsuario='';$infoUsuario=null;
foreach($usuarios as $usr)if((int)$usr['id']===$usuarioElegido){$infoUsuario=$usr;$nombreUsuario=$usr['nombre'];break;}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Usuarios y Permisos · ERP G&D</title><style>
:root{--blue:#123f75;--navy:#102b49;--accent:#1777c4;--ink:#16314e;--muted:#60758d;--border:#dfebf4}*{box-sizing:border-box}body{margin:0;background:#f3f7fb;color:var(--ink);font:14px system-ui,-apple-system,Segoe UI,sans-serif}header{background:linear-gradient(115deg,#0b2c55,#1264a8);color:white;padding:26px max(18px,calc((100vw - 1200px)/2))}header h1{font-size:clamp(22px,2.4vw,30px);margin:3px 0 7px}header p{margin:0;opacity:.84;max-width:850px}header a{color:white;text-decoration:none;font-weight:600}main{max-width:1200px;margin:26px auto;padding:0 16px 55px}.heading{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px}.card{background:white;border:1px solid var(--border);border-radius:16px;box-shadow:0 6px 18px #1237590a;padding:23px;margin-bottom:18px}h2{font-size:18px;margin:0 0 14px}h3{font-size:15px;margin:0 0 6px}.muted{color:var(--muted)}.small{font-size:12px}.topactions{display:flex;gap:10px;flex-wrap:wrap}.btn{border:0;border-radius:10px;padding:11px 15px;background:#1669b6;color:white;font-weight:650;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}.btn.secondary{background:#e9f1f9;color:var(--blue)}label{display:block;font-size:12px;font-weight:700;margin-bottom:6px;color:#3a536d}select,input[type=password]{border:1px solid #b6cadd;border-radius:9px;min-height:43px;padding:10px;width:100%;background:white;font:inherit}.selector{display:flex;gap:15px;flex-wrap:wrap;align-items:end}.selector>div{flex:1;min-width:220px}.notice{padding:13px 16px;border-radius:10px;margin-bottom:14px;background:#eef5ff;color:#214b79;border-left:4px solid #2475c2}.notice.danger{background:#fff1f1;border-color:#ba3d3d;color:#853232}.notice.ok{background:#edf9f0;border-color:#21965d;color:#1b6741}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.perm{border:1px solid #e0e9f1;border-radius:12px;padding:15px;display:flex;align-items:center;justify-content:space-between;gap:12px}.perm label{font-size:14px;color:var(--ink);margin:0}.perm p{font-size:12px;color:var(--muted);margin:5px 0 0}.perm select{width:165px;flex:none}.footerform{display:flex;align-items:end;gap:14px;justify-content:space-between;flex-wrap:wrap;margin-top:18px;padding-top:18px;border-top:1px solid #e7eef5}.footerform>div{width:290px;max-width:100%}table{border-collapse:collapse;width:100%}th,td{padding:12px;border-bottom:1px solid #eaf0f7;text-align:left;font-size:13px}th{background:#f3f7fc}.overflow{overflow:auto}.pill{border-radius:20px;font-size:12px;background:#edf2f9;padding:5px 10px} .warning{color:#995a13}.mini{display:flex;flex-wrap:wrap;gap:8px}.mini span{background:#f0f4f9;border-radius:6px;padding:7px 9px;font-size:12px}@media(max-width:760px){.grid{grid-template-columns:1fr}.perm{flex-wrap:wrap}.perm select{width:100%}.card{padding:16px}}
</style></head><body><header><div><a href="dashboard.php">← Volver al ERP</a></div><h1>Usuarios y Permisos</h1><p>Control de restricciones adicionales por rol y empleado. Conserva las protecciones originales de cada módulo.</p></header><main>
<div class="heading"><div class="muted">Administración de accesos · <?= $e($operador['nombre']??'') ?></div><div class="topactions"><a class="btn secondary" href="usuario.php">Administrar cuentas</a><a class="btn secondary" href="erp_auditoria_cajas_v12.php">Auditoría de cajas</a></div></div>
<?php foreach($mensajes as $message): ?><div class="notice ok"><?= $e($message) ?></div><?php endforeach; ?>
<?php if($error): ?><div class="notice danger"><?= $e($error) ?></div><?php endif; ?>
<div class="notice"><b>Importante:</b> “Permitir” elimina una restricción de este centro, pero <b>nunca reemplaza</b> las validaciones originales de permisos, caja o sucursal. “Denegar” bloquea la ruta y la oculta del menú; “Heredar” conserva la decisión del rol o los permisos actuales.</div>
<section class="card"><h2>Elegir configuración</h2><form method="get" class="selector"><div><label for="rol">Rol a configurar</label><select name="rol" id="rol"><?php foreach($roles as $r): ?><option value="<?= $e($r) ?>" <?= $r===$rolElegido?'selected':'' ?>><?= $e($r) ?></option><?php endforeach; ?></select></div><div><label for="usuario_id">Empleado (opcional)</label><select name="usuario_id" id="usuario_id"><option value="0">Sin empleado seleccionado</option><?php foreach($usuarios as $usr): ?><option value="<?= (int)$usr['id'] ?>" <?= (int)$usr['id']===$usuarioElegido?'selected':'' ?>><?= $e($usr['nombre']) ?> (<?= $e($usr['rol']) ?><?= (int)$usr['estado']===1?'':' · Inactivo' ?>)</option><?php endforeach; ?></select></div><button class="btn" type="submit">Consultar permisos</button></form></section>
<section class="card"><h2>Permisos del rol: <?= $e($rolElegido) ?></h2><?php if($rolElegido==='ADMINISTRADOR'): ?><p class="notice">La cuenta Administrador siempre conserva acceso de rescate. No puede ser bloqueada desde este panel.</p><?php else: ?><form method="post"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="tipo" value="rol"><input type="hidden" name="rol_objetivo" value="<?= $e($rolElegido) ?>"><div class="grid"><?php foreach($catalogo as $id=>$m): ?><div class="perm"><div><label for="rol_<?= $e($id) ?>"><?= $e($m['titulo']) ?></label><p><?= $e($m['descripcion']) ?></p></div><select name="permiso[<?= $e($id) ?>]" id="rol_<?= $e($id) ?>"><option value="HEREDAR" <?= !isset($rolPolicies[$id])?'selected':'' ?>>Sin restricción adicional</option><option value="ALLOW" <?= ($rolPolicies[$id]??'')==='ALLOW'?'selected':'' ?>>Permitir*</option><option value="DENY" <?= ($rolPolicies[$id]??'')==='DENY'?'selected':'' ?>>Denegar</option></select></div><?php endforeach; ?></div><div class="footerform"><div><label>Tu contraseña actual</label><input type="password" name="clave_actual" required autocomplete="current-password"></div><button class="btn" type="submit">Guardar permisos del rol</button></div></form><?php endif; ?></section>
<?php if($infoUsuario): ?><section class="card"><h2>Excepciones del empleado: <?= $e($nombreUsuario) ?></h2><div class="mini"><span>Usuario: <?= $e($infoUsuario['usuario']) ?></span><span>Rol: <?= $e($infoUsuario['rol']) ?></span><span>Estado: <?= (int)$infoUsuario['estado']===1?'Activo':'Inactivo' ?></span><span>Almacén asignado: <?= $e($almacenes[$infoUsuario['almacen_id']]??'Sin asignación') ?></span><span>POS: <?= $e($opcionesSucursal[$usuarioElegido]??'Sin caja POS asignada') ?></span></div><p class="muted small">Las asignaciones de sucursal y caja siguen administrándose desde Usuarios y el POS. Este panel no cambia esas asignaciones ni amplía el alcance geográfico de los reportes.</p><?php if(strtoupper((string)$infoUsuario['rol'])!=='ADMINISTRADOR'): ?><form method="post"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="tipo" value="usuario"><input type="hidden" name="usuario_objetivo" value="<?= $usuarioElegido ?>"><div class="grid"><?php foreach($catalogo as $id=>$m): ?><div class="perm"><div><label for="usr_<?= $e($id) ?>"><?= $e($m['titulo']) ?></label><p>Rol: <?= $e($rolPolicies[$id]??'Sin restricción adicional') ?></p></div><select name="permiso[<?= $e($id) ?>]" id="usr_<?= $e($id) ?>"><option value="HEREDAR" <?= !isset($userPolicies[$id])?'selected':'' ?>>Heredar del rol</option><option value="ALLOW" <?= ($userPolicies[$id]??'')==='ALLOW'?'selected':'' ?>>Permitir*</option><option value="DENY" <?= ($userPolicies[$id]??'')==='DENY'?'selected':'' ?>>Denegar</option></select></div><?php endforeach; ?></div><div class="footerform"><div><label>Tu contraseña actual</label><input type="password" name="clave_actual" required autocomplete="current-password"></div><button class="btn" type="submit">Guardar excepciones</button></div></form><?php endif; ?></section><?php endif; ?>
<section class="card"><h2>Historial de cambios de permisos</h2><div class="overflow"><table><thead><tr><th>Fecha</th><th>Responsable</th><th>Tipo</th><th>Objetivo</th></tr></thead><tbody><?php foreach($logs as $r): ?><tr><td><?= $e($r['creado_en']) ?></td><td><?= $e($r['actor']??'Cuenta anterior') ?></td><td><?= $e($r['tipo']) ?></td><td><?= $e($r['objetivo']) ?></td></tr><?php endforeach; ?><?php if(!$logs): ?><tr><td colspan="4" class="muted">Todavía no se han cambiado permisos.</td></tr><?php endif; ?></tbody></table></div></section><div class="muted small">*Permitir significa no aplicar una denegación adicional. Los permisos existentes, los modales de autorización y las validaciones de servidor siguen siendo obligatorios.</div></main>
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
<!-- GYD_UNA_PESTANA_INTERNA -->
<script src="assets/js/gyd_una_pestana.js?v=1"></script>
</body></html>
