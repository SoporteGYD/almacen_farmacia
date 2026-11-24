<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_oc_win_pdf.php';
require_once __DIR__.'/../app/helpers/erp_mail_secure.php';
requireLogin();$u=currentUser()?:[];if(!ocw_can_edit($u)){http_response_code(403);exit('No autorizado para enviar órdenes.');}
$id=filter_var($_POST['orden_id']??$_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$id){http_response_code(400);exit('Selecciona una orden.');}
$error='';$ok='';$destinatarios=[];$cuenta=null;$orden=[];$history=[];
try{
 $db=(new Database())->connect();$orden=ocw_order($db,(int)$id,$u)['orden'];
 if(!gd_oc_esta_lista((string)$orden['estado'],$orden['autorizacion']??null))throw new RuntimeException('La orden aún no está disponible para enviarse.');
 $st=$db->prepare("SELECT u.id,u.nombre,u.correo FROM erp_oc_win_gerentes g JOIN usuarios u ON u.id=g.usuario_id WHERE g.destino=? AND g.activo=1 AND u.estado=1 AND u.rol='GERENTE' ORDER BY u.nombre");
 $st->execute([$orden['destino']]);$destinatarios=$st->fetchAll(PDO::FETCH_ASSOC);
 if(!$destinatarios)throw new RuntimeException('No hay gerentes destinatarios activos para esta sucursal. Configúralos primero.');
 foreach($destinatarios as $gerente)if(!filter_var((string)$gerente['correo'],FILTER_VALIDATE_EMAIL))throw new RuntimeException('Hay un gerente sin correo de usuario válido.');
 $st=$db->prepare('SELECT correo,smtp_host,smtp_port,secreto_cifrado FROM erp_correos_usuario WHERE usuario_id=?');$st->execute([(int)$u['id']]);$cuenta=$st->fetch(PDO::FETCH_ASSOC)?:null;
 if($_SERVER['REQUEST_METHOD']==='POST'){
  ocw_check_token($_POST['csrf']??null);
  if(($_POST['confirmar']??'')!=='1')throw new RuntimeException('Confirma el envío de la orden a los gerentes.');
  if(!$cuenta)throw new RuntimeException('Configura primero el correo personal del usuario remitente.');
  $modoCostos=(string)($_POST['costos']??'');
  if(!in_array($modoCostos,['si','no'],true))throw new RuntimeException('Debes seleccionar si el PDF llevará costos o no.');
  $mostrarCostos=($modoCostos==='si');
  $autoload=__DIR__.'/../vendor/autoload.php';if(!is_file($autoload))throw new RuntimeException('Falta PHPMailer / vendor/autoload.php en el proyecto.');
  require_once $autoload;if(!class_exists(\PHPMailer\PHPMailer\PHPMailer::class))throw new RuntimeException('PHPMailer no está disponible.');
  $remitente=(string)$cuenta['correo'];if(!filter_var($remitente,FILTER_VALIDATE_EMAIL))throw new RuntimeException('El correo remitente es inválido.');
  $pdf=ocw_pdf_build($db,(int)$id,$u,$mostrarCostos);if(!str_starts_with($pdf,'%PDF-'))throw new RuntimeException('No se pudo crear el archivo PDF.');
  $enviados=[];$errores=[];
  foreach($destinatarios as $dest){
   $gerId=(int)$dest['id'];$destEmail=(string)$dest['correo'];
   $db->beginTransaction();
   // Bloqueo de envío repetido en concurrencia: el candado es la propia orden.
   $st=$db->prepare('SELECT estado FROM erp_ordenes_compra WHERE id=? FOR UPDATE');$st->execute([$id]);$estado=$st->fetchColumn();
   if($estado!=='EMITIDA'){$db->rollBack();throw new RuntimeException('La orden ya no está emitida.');}
   $st=$db->prepare("SELECT COUNT(*) FROM erp_oc_win_envios WHERE orden_id=? AND gerente_id=? AND (estado='ENVIADO' AND solicitado_en>=DATE_SUB(NOW(),INTERVAL 15 MINUTE) OR estado='EN_PROCESO' AND solicitado_en>=DATE_SUB(NOW(),INTERVAL 5 MINUTE))");
   $st->execute([$id,$gerId]);if((int)$st->fetchColumn()>0){$db->commit();$errores[]=$destEmail.' (envío reciente o en proceso)';continue;}
   $st=$db->prepare("INSERT INTO erp_oc_win_envios(orden_id,remitente_id,gerente_id,destinatario) VALUES(?,?,?,?)");$st->execute([$id,(int)$u['id'],$gerId,$destEmail]);$sendId=(int)$db->lastInsertId();$db->commit();
   try{
    $mailer=new \PHPMailer\PHPMailer\PHPMailer(true);
    $mailer->isSMTP();$mailer->Host=(string)$cuenta['smtp_host'];$mailer->Port=(int)$cuenta['smtp_port'];
    $mailer->SMTPAuth=true;$mailer->SMTPSecure=\PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mailer->Username=$remitente;$mailer->Password=erpMailDecrypt((string)$cuenta['secreto_cifrado']);
    $mailer->Timeout=25;$mailer->CharSet='UTF-8';$mailer->setFrom($remitente,'ERP G&D - Compras');
    $mailer->addAddress($destEmail,(string)$dest['nombre']); // Siempre usuario real con rol GERENTE.
    $mailer->Subject='DISTRIBUCIÓN G&D | Orden de compra '.ocw_numero((int)$id).' | '.(ocw_destinos()[$orden['destino']]??$orden['destino']).' | PDF '.($mostrarCostos?'CON COSTOS':'SIN COSTOS');
    $mailer->Body="Buen día, gerente.\n\nLe compartimos la orden de compra registrada ".(string)$orden['folio']." (N.º ".ocw_numero((int)$id).") para ".(ocw_destinos()[$orden['destino']]??$orden['destino']).".\n\nProveedor: ".$orden['proveedor']."\nEntrega: ".($orden['fecha_entrega']??$orden['fecha'])."\n\nAdjuntamos la orden en PDF ".($mostrarCostos?"con costos.":"sin costos ni importes.")."\n\nDistribución G&D";
    $mailer->addStringAttachment($pdf,'OC-'.ocw_numero((int)$id).($mostrarCostos?'-CON-COSTOS':'-SIN-COSTOS').'.pdf','base64','application/pdf');$mailer->send();
    $db->prepare("UPDATE erp_oc_win_envios SET estado='ENVIADO', finalizado_en=NOW() WHERE id=?")->execute([$sendId]);$enviados[]=$destEmail;
   }catch(Throwable $ex){error_log('OC correo gerente '.$gerId.': '.$ex->getMessage());$db->prepare("UPDATE erp_oc_win_envios SET estado='ERROR',error_detalle=?,finalizado_en=NOW() WHERE id=?")->execute([mb_substr($ex->getMessage(),0,300),$sendId]);$errores[]=$destEmail.' (falló SMTP)';}
  }
  $ok=$enviados?'SMTP aceptó el envío (PDF '.($mostrarCostos?'CON COSTOS':'SIN COSTOS').') para: '.implode(', ',$enviados).'.':'';
  if($errores)$error='No enviados o ya procesados: '.implode(', ',$errores).'. Revisa el historial antes de reintentar.';
 }
 $st=$db->prepare('SELECT e.destinatario,e.estado,e.solicitado_en,e.finalizado_en FROM erp_oc_win_envios e WHERE e.orden_id=? ORDER BY e.id DESC LIMIT 20');$st->execute([$id]);$history=$st->fetchAll(PDO::FETCH_ASSOC);
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();error_log('ERP OC email gerentes: '.$e->getMessage());$error=$e instanceof RuntimeException?$e->getMessage():'Error al consultar los destinatarios o enviar el correo.';}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>G&D · Enviar orden solo a gerentes</title><style>body{font:15px system-ui,sans-serif;margin:0;color:#193b58;background:#eff4fa}header{background:#123e6a;color:white;padding:22px}main{max-width:970px;margin:20px auto;padding:0 14px}.card{background:white;border-radius:12px;padding:22px;margin-bottom:16px}a,button{background:#145d9e;color:white;padding:10px 14px;border-radius:7px;border:0;text-decoration:none;display:inline-block;cursor:pointer;font:inherit;font-weight:700}.btn-alt{background:#e6eef8;color:#164677}.note{background:#e6f3ff;padding:13px;border-radius:8px}.error{background:#ffe5e7;padding:13px;border-radius:8px}.ok{background:#e3f7e8;padding:13px;border-radius:8px}table{width:100%;border-collapse:collapse}th,td{text-align:left;border-bottom:1px solid #e1e9f0;padding:10px;font-size:13px}th{background:#eaf1f9}input[type=checkbox]{width:18px;height:18px}</style></head><body><header><h1>Enviar orden de compra · Exclusivo gerentes</h1><p>Sin envío a proveedores; destinatarios obtenidos de usuarios activos.</p></header><main><div class="card"><a class="btn-alt" href="erp_oc_win.php?ver=<?=(int)$id?>">← Regresar a la orden</a> <a class="btn-alt" href="erp_oc_win_pdf.php?id=<?=(int)$id?>&amp;costos=si" target="_blank" rel="noopener">Vista con costos</a> <a class="btn-alt" href="erp_oc_win_pdf.php?id=<?=(int)$id?>&amp;costos=no" target="_blank" rel="noopener">Vista sin costos</a><p class="note"><b>Orden:</b> <?=ocw_e($orden['folio']??'')?> · <b>Sucursal:</b> <?=ocw_e(ocw_destinos()[$orden['destino']??'']??'')?> · <b>Remitente:</b> <?=ocw_e($cuenta['correo']??'SIN CONFIGURAR')?><br>La lista de gerentes no se puede modificar en esta pantalla ni sustituir por correos de proveedores.</p><?php if($error):?><p class="error"><?=ocw_e($error)?></p><?php endif;?><?php if($ok):?><p class="ok"><?=ocw_e($ok)?></p><?php endif;?><h2>Gerentes destinatarios</h2><?php foreach($destinatarios as $g):?><p>✓ <?=ocw_e($g['nombre'])?> — <?=ocw_e($g['correo'])?></p><?php endforeach;?><?php if(!$destinatarios):?><p>Sin gerentes configurados. Solicita a Dirección que los agregue.</p><?php else:?><form method="post"><input type="hidden" name="csrf" value="<?=ocw_e(ocw_token())?>"><input type="hidden" name="orden_id" value="<?=(int)$id?>"><p><label for="costos_pdf"><strong>¿Qué PDF quieres enviar a los gerentes?</strong></label><br><select id="costos_pdf" name="costos" required style="display:block;width:100%;max-width:340px;padding:12px;border-radius:7px;border:1px solid #adc7dd;margin-top:8px;font:inherit"><option value="no" selected>Sin costos (cantidades y artículos)</option><option value="si">Con costos (precios, impuestos y totales)</option></select></p><p class="note">La opción sin costos omite costo unitario, importes, descuentos, impuestos, total y observaciones libres. No modifica datos guardados.</p><p><label><input type="checkbox" name="confirmar" value="1" required> Confirmo que solo se enviará a los gerentes anteriores, con la versión de PDF seleccionada.</label></p><button type="submit" <?=!$cuenta?'disabled':''?>>Enviar orden por correo a los gerentes</button></form><?php endif;?></div><div class="card"><h2>Historial de envíos</h2><div style="overflow:auto"><table><thead><tr><th>Destinatario</th><th>Estado</th><th>Solicitado</th><th>Finalizado</th></tr></thead><tbody><?php foreach($history as $r):?><tr><td><?=ocw_e($r['destinatario'])?></td><td><?=ocw_e($r['estado'])?></td><td><?=ocw_e($r['solicitado_en'])?></td><td><?=ocw_e($r['finalizado_en']??'')?></td></tr><?php endforeach;?><?php if(!$history):?><tr><td colspan="4">Todavía no hay envíos.</td></tr><?php endif;?></tbody></table></div></div></main>
<script src="assets/js/gyd_excel_reportes.js" defer></script>

<?php if (!defined('GYD_HIDE_GLOBAL_NAV')): ?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=4">
<script>window.GYD_NAV_HOME='index.php';window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=4"></script>
<?php endif; ?>
</body></html>
