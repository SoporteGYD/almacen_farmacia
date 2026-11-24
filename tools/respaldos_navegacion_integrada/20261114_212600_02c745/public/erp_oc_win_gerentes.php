<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_oc_win.php';
requireLogin();$u=currentUser()?:[];if(!ocw_can_admin($u)){http_response_code(403);exit('Solo Administración o Dirección puede configurar destinatarios.');}
$error='';$ok='';
try{
 $db=(new Database())->connect();
 if($_SERVER['REQUEST_METHOD']==='POST'){
  ocw_check_token($_POST['csrf']??null);$dest=(string)($_POST['destino']??'');$gerente=(int)($_POST['gerente_id']??0);
  if(!isset(ocw_destinos()[$dest]))throw new RuntimeException('Destino no válido.');
  $st=$db->prepare("SELECT id FROM usuarios WHERE id=? AND rol='GERENTE' AND estado=1");$st->execute([$gerente]);if(!$st->fetchColumn())throw new RuntimeException('Solo puedes seleccionar un GERENTE activo.');
  $activo=($_POST['accion']??'')==='agregar'?1:0;
  $st=$db->prepare('INSERT INTO erp_oc_win_gerentes(destino,usuario_id,activo) VALUES(?,?,?) ON DUPLICATE KEY UPDATE activo=VALUES(activo)');$st->execute([$dest,$gerente,$activo]);
  $_SESSION['ocw_gerentes_flash']=$activo?'Gerente habilitado para recibir órdenes.':'Destinatario deshabilitado.';header('Location: erp_oc_win_gerentes.php',true,303);exit;
 }
 $gerentes=$db->query("SELECT id,nombre,correo FROM usuarios WHERE rol='GERENTE' AND estado=1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
 $reg=$db->query("SELECT g.*,u.nombre,u.correo,u.estado,u.rol FROM erp_oc_win_gerentes g JOIN usuarios u ON u.id=g.usuario_id ORDER BY g.destino,u.nombre")->fetchAll(PDO::FETCH_ASSOC);
}catch(Throwable $e){error_log('ERP OC gerentes: '.$e->getMessage());$error=$e instanceof RuntimeException?$e->getMessage():'Verifica el SQL de instalación.';$gerentes=$gerentes??[];$reg=$reg??[];}
$ok=$_SESSION['ocw_gerentes_flash']??'';unset($_SESSION['ocw_gerentes_flash']);
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>ERP G&D · Destinatarios gerentes</title><style>body{font:15px system-ui,sans-serif;color:#173b5b;background:#edf3f9;margin:0}header{background:#143d66;color:white;padding:22px}main{max-width:1020px;margin:20px auto;padding:0 15px}.card{background:white;border-radius:12px;padding:22px;margin-bottom:20px}.row{display:flex;gap:12px;flex-wrap:wrap;align-items:end}.row>label{flex:1;min-width:240px}label{display:grid;gap:7px;font-weight:700}select,button{padding:11px;font:inherit;border:1px solid #aac2d8;border-radius:8px}button,.btn{background:#1760a4;color:white;border:0;padding:11px 16px;border-radius:8px;text-decoration:none;cursor:pointer;font-weight:bold}table{border-collapse:collapse;width:100%}th,td{text-align:left;border-bottom:1px solid #e2e9f2;padding:12px}th{background:#e3eff9}.msg{background:#fff0db;padding:12px;border-radius:8px}.ok{background:#dcf7e6}.disable{background:#b53849}.small{font-size:12px;color:#657f94}</style></head><body><header><h1>Gerentes que reciben órdenes de compra</h1><p>Solo usuarios activos con rol GERENTE; sin direcciones libres ni proveedores.</p></header><main><div class="card"><a class="btn" href="erp_oc_win.php">← Órdenes de compra</a><p class="small">Una misma sucursal puede tener varios gerentes destinatarios. Solo se enviarán órdenes emitidas y disponibles según la política actual de Compras. Este cambio no modifica roles ni permisos de otros módulos.</p><?php if($error):?><p class="msg"><?=ocw_e($error)?></p><?php endif;?><?php if($ok):?><p class="msg ok"><?=ocw_e($ok)?></p><?php endif;?><form method="post" class="row"><input type="hidden" name="csrf" value="<?=ocw_e(ocw_token())?>"><label>Destino<select name="destino" required><?php foreach(ocw_destinos() as $k=>$v):?><option value="<?=ocw_e($k)?>"><?=ocw_e($v)?></option><?php endforeach;?></select></label><label>Gerente activo<select name="gerente_id" required><option value="">Selecciona un gerente</option><?php foreach($gerentes as $g):?><option value="<?=(int)$g['id']?>"><?=ocw_e($g['nombre'].' · '.$g['correo'])?></option><?php endforeach;?></select></label><button name="accion" value="agregar" type="submit">Agregar / activar</button></form></div><div class="card"><h2>Destinatarios configurados</h2><div style="overflow:auto"><table><thead><tr><th>Sucursal / Almacén</th><th>Gerente</th><th>Correo (de Usuarios)</th><th>Estado</th><th>Acción</th></tr></thead><tbody><?php foreach($reg as $r):?><tr><td><?=ocw_e(ocw_destinos()[$r['destino']]??$r['destino'])?></td><td><?=ocw_e($r['nombre'])?></td><td><?=ocw_e($r['correo'])?></td><td><?=((int)$r['activo']&&$r['estado']&&$r['rol']==='GERENTE')?'ACTIVO':'INACTIVO'?></td><td><?php if((int)$r['activo']):?><form method="post" onsubmit="return confirm('¿Desactivar este destinatario?')"><input type="hidden" name="csrf" value="<?=ocw_e(ocw_token())?>"><input type="hidden" name="destino" value="<?=ocw_e($r['destino'])?>"><input type="hidden" name="gerente_id" value="<?=(int)$r['usuario_id']?>"><button class="disable" name="accion" value="desactivar">Desactivar</button></form><?php endif;?></td></tr><?php endforeach;?><?php if(!$reg):?><tr><td colspan="5">No hay destinatarios configurados. No se enviará ninguna orden hasta agregar gerentes.</td></tr><?php endif;?></tbody></table></div></div></main>
<script src="assets/js/gyd_excel_reportes.js" defer></script>

<?php if (!defined('GYD_HIDE_GLOBAL_NAV')): ?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=1">
<script>window.GYD_NAV_HOME='index.php';window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=1"></script>
<?php endif; ?>
</body></html>
