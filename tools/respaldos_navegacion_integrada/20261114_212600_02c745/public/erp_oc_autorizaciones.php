<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/config/erp_oc_politica.php';
requireLogin();
if(!gd_oc_requiere_autorizacion()){
    // El endpoint no puede aprobar/rechazar aunque exista un POST antiguo o una URL guardada.
    if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(403);exit('Autorizaciones de Compras temporalmente desactivadas.');}
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Autorizaciones en pausa</title><main style="max-width:650px;margin:8vh auto;font:16px system-ui;padding:24px"><h1>Autorizaciones de compra en pausa</h1><p>Por indicación de Dirección, las órdenes de compra documentan pedidos ya confirmados y no necesitan autorización previa. El historial y el módulo original permanecen conservados.</p><a href="erp_ordenes_compra.php">Volver a Órdenes de Compra</a></main></html>';
    exit;
}
requireLogin();
$usr=currentUser()?:[];
$rol=strtoupper(trim((string)($usr['rol']??'')));
if(!in_array($rol,['DIRECCION','COMPRAS','ADMINISTRADOR'],true)){http_response_code(403);exit('Acceso no autorizado.');}
$puedeAutorizar=$rol==='DIRECCION';
$uid=(int)($usr['id']??0);
if($uid<1){http_response_code(403);exit('Sesión inválida.');}
function aut_e($v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
if(empty($_SESSION['erp_oc_aut_csrf']))$_SESSION['erp_oc_aut_csrf']=bin2hex(random_bytes(32));
$csrf=$_SESSION['erp_oc_aut_csrf'];$error='';$flash=$_SESSION['erp_oc_aut_flash']??'';unset($_SESSION['erp_oc_aut_flash']);
$filas=[];
try{
 $pdo=(new Database())->connect();
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!$puedeAutorizar)throw new RuntimeException('Solo Dirección puede aprobar o rechazar órdenes.');
  if(!hash_equals($csrf,(string)($_POST['csrf']??'')))throw new RuntimeException('Formulario vencido, vuelve a cargar.');
  $id=(int)($_POST['orden_id']??0);$accion=(string)($_POST['accion']??'');
  $motivo=trim((string)($_POST['motivo']??''));
  if($id<1 || !in_array($accion,['AUTORIZADA','RECHAZADA'],true))throw new RuntimeException('Acción inválida.');
  if($accion==='RECHAZADA' && (mb_strlen($motivo)<5 || mb_strlen($motivo)>500))throw new RuntimeException('Indica un motivo de rechazo de 5 a 500 caracteres.');
  $pdo->beginTransaction();
  $st=$pdo->prepare('SELECT estado,usuario_id FROM erp_ordenes_compra WHERE id=? FOR UPDATE');$st->execute([$id]);$orden=$st->fetch(PDO::FETCH_ASSOC);
  if(!$orden || $orden['estado']!=='BORRADOR')throw new RuntimeException('Esta orden ya no está disponible para autorizar.');
  if((int)$orden['usuario_id']===$uid)throw new RuntimeException('No puedes autorizar tu propia orden.');
  $st=$pdo->prepare('SELECT estado FROM erp_oc_autorizaciones WHERE orden_id=? FOR UPDATE');$st->execute([$id]);
  if($st->fetchColumn()!=='PENDIENTE')throw new RuntimeException('Esta solicitud ya fue atendida.');
  $st=$pdo->prepare("UPDATE erp_oc_autorizaciones SET estado=?,decidido_por=?,decidido_en=NOW(),motivo_rechazo=? WHERE orden_id=? AND estado='PENDIENTE'");
  $st->execute([$accion,$uid,$accion==='RECHAZADA'?$motivo:null,$id]);
  if($accion==='AUTORIZADA')$pdo->prepare("UPDATE erp_ordenes_compra SET estado='EMITIDA' WHERE id=? AND estado='BORRADOR'")->execute([$id]);
  $st=$pdo->prepare('INSERT INTO erp_oc_autorizaciones_historial(orden_id,usuario_id,accion,observaciones) VALUES (?,?,?,?)');$st->execute([$id,$uid,$accion,$accion==='RECHAZADA'?$motivo:null]);
  $pdo->commit();$_SESSION['erp_oc_aut_flash']=$accion==='AUTORIZADA'?'Orden autorizada y emitida. Ya puede enviarse al proveedor.':'Orden rechazada. Compras puede revisar y volver a solicitar.';
  header('Location: erp_oc_autorizaciones.php',true,303);exit;
 }
 $sql="SELECT o.id,o.folio,o.fecha,o.destino,o.subtotal,o.usuario_id,o.estado AS estado_orden,p.nombre AS proveedor,a.estado AS autorizacion,a.solicitado_en,a.motivo_rechazo,u.nombre AS solicitante FROM erp_oc_autorizaciones a JOIN erp_ordenes_compra o ON o.id=a.orden_id JOIN proveedores p ON p.id=o.proveedor_id LEFT JOIN usuarios u ON u.id=a.solicitado_por ORDER BY (a.estado='PENDIENTE') DESC,a.solicitado_en DESC LIMIT 200";
 $filas=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();error_log('ERP autorizaciones: '.$e->getMessage());$error=$e instanceof RuntimeException?$e->getMessage():'No se pudo consultar. Comprueba que ejecutaste la migración SQL.';}
?><!doctype html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ERP G&D | Autorizaciones</title><style>*{box-sizing:border-box}body{margin:0;font:15px system-ui,Segoe UI,sans-serif;background:#edf3fa;color:#193b5d}header{background:#123e6e;border-bottom:4px solid #d62f49;color:white;padding:22px max(18px,calc((100% - 1100px)/2))}header h1{margin:0}main{max-width:1120px;margin:22px auto;padding:0 15px}.card{background:white;border-radius:14px;padding:20px;box-shadow:0 6px 20px #14395f12;margin-bottom:15px}.btn,button{display:inline-block;background:#165ca7;color:white;text-decoration:none;border:0;padding:9px 12px;border-radius:9px;cursor:pointer;font:inherit;font-weight:600}.reject{background:#ba3549}.muted{color:#667b8d;font-size:13px}.message{background:#e6f4e9;padding:14px;border-radius:10px}.error{background:#fee8e8}.scroll{overflow:auto}table{width:100%;border-collapse:collapse;min-width:930px}th,td{text-align:left;padding:11px;border-bottom:1px solid #e2eaf4;font-size:13px}th{background:#194f86;color:white}form{display:flex;gap:6px;flex-wrap:wrap}input{padding:9px;border:1px solid #c9d6e5;border-radius:7px;max-width:205px}@media(max-width:650px){header h1{font-size:20px}}</style></head><body><header><h1>ERP G&amp;D · Autorizaciones de Compra</h1><p>Una autorización de Dirección por pedido · Sin movimientos de inventario</p></header><main><section class="card"><a class="btn" href="erp_ordenes_compra.php">← Órdenes de Compra</a><p class="muted">Los usuarios de Compras solicitan; Dirección aprueba o rechaza. Nadie puede autorizar su propia orden. Las órdenes anteriores sin autorización registrada no podrán enviarse.</p></section><?php if($error):?><p class="message error"><?=aut_e($error)?></p><?php endif;?><?php if($flash):?><p class="message"><?=aut_e($flash)?></p><?php endif;?><section class="card"><h2>Solicitudes de autorización</h2><div class="scroll"><table><thead><tr><th>Folio</th><th>Proveedor</th><th>Solicitado por</th><th>Importe</th><th>Estado</th><th>Documento</th><th>Acciones</th></tr></thead><tbody><?php foreach($filas as $f):?><tr><td><strong><?=aut_e($f['folio'])?></strong><div class="muted"><?=aut_e($f['fecha'])?></div></td><td><?=aut_e($f['proveedor'])?><div class="muted"><?=aut_e($f['destino'])?></div></td><td><?=aut_e($f['solicitante'])?></td><td>$<?=number_format((float)$f['subtotal'],2)?></td><td><?=aut_e($f['autorizacion'])?><?php if($f['motivo_rechazo']):?><div class="muted">Motivo: <?=aut_e($f['motivo_rechazo'])?></div><?php endif;?></td><td><a class="btn" target="_blank" rel="noopener" href="erp_orden_compra_pdf.php?id=<?=(int)$f['id']?>">Ver PDF</a></td><td><?php if($puedeAutorizar&&$f['autorizacion']==='PENDIENTE'&&$f['estado_orden']==='BORRADOR'&&(int)$f['usuario_id']!==$uid):?><form method="post"><input type="hidden" name="csrf" value="<?=aut_e($csrf)?>"><input type="hidden" name="orden_id" value="<?=(int)$f['id']?>"><button name="accion" value="AUTORIZADA" onclick="return confirm('¿Autorizar esta orden y habilitar su envío?')">Autorizar</button><input name="motivo" placeholder="Motivo si rechazas" maxlength="500"><button class="reject" name="accion" value="RECHAZADA">Rechazar</button></form><?php elseif($f['autorizacion']==='PENDIENTE'):?><span class="muted">Pendiente de otro dueño</span><?php else:?><span class="muted">Atendida</span><?php endif;?></td></tr><?php endforeach;if(!$filas):?><tr><td colspan="7">No hay solicitudes todavía.</td></tr><?php endif;?></tbody></table></div></section></main>
<script src="assets/js/gyd_excel_reportes.js" defer></script>

<?php if (!defined('GYD_HIDE_GLOBAL_NAV')): ?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=1">
<script>window.GYD_NAV_HOME='index.php';window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=1"></script>
<?php endif; ?>
</body></html>
