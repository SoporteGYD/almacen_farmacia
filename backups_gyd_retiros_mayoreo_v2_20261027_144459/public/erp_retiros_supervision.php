<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/gyd_retiros.php';
requireLogin();$u=currentUser()?:[];$uid=(int)($u['id']??0);
if(!gr_admin($u)||$uid<1){http_response_code(403);exit('Solo Administración o Dirección pueden confirmar retiros.');}
$error='';$msg='';$pendientes=[];$ultimos=[];
try{
 $db=(new Database())->connect();gr_test_db($db);
 if($_SERVER['REQUEST_METHOD']==='POST'){
  gr_post_guard();$id=(int)($_POST['retiro_id']??0);$accion=(string)($_POST['accion']??'');
  if($id<1||!in_array($accion,['confirmar','rechazar'],true))throw new DomainException('Acción inválida.');
  $nota=trim((string)($_POST['nota']??''));$referencia=trim((string)($_POST['referencia']??''));
  if(mb_strlen($nota,'UTF-8')>255||mb_strlen($referencia,'UTF-8')>120)throw new DomainException('Observación o referencia demasiado larga.');
  // El turno se bloquea antes que la solicitud: mismo orden que el cajero y cerrarTurno().
  $base=gr_query($db,'SELECT turno_id FROM erp_retiros_supervisados WHERE id=?',[$id])->fetchColumn();
  if(!$base)throw new DomainException('Solicitud inexistente.');
  $db->beginTransaction();
  try{
   $t=gr_query($db,'SELECT id,caja_id,usuario_apertura_id,estado FROM pos_turnos WHERE id=? FOR UPDATE',[(int)$base])->fetch(PDO::FETCH_ASSOC);
   if(!$t)throw new DomainException('Turno inexistente.');
   $sol=gr_query($db,'SELECT * FROM erp_retiros_supervisados WHERE id=? FOR UPDATE',[$id])->fetch(PDO::FETCH_ASSOC);
   if(!$sol || $sol['estado']!=='SOLICITADO')throw new DomainException('La solicitud ya fue resuelta; recarga la pantalla.');
   if((int)$sol['turno_id']!==(int)$t['id'])throw new DomainException('Los datos de turno no coinciden.');
   if((int)$sol['cajero_id']===$uid)throw new DomainException('El retiro debe confirmarlo una persona distinta a quien lo solicitó.');
   if($accion==='confirmar'){
    if($t['estado']!=='ABIERTO')throw new DomainException('No se puede confirmar un retiro con el turno cerrado; rechaza la solicitud.');
    $s=gr_expected($db,(int)$t['id']);$importe=gr_cents($sol['importe']);
    if($importe>max(0,$s['esperado']-GYD_RETIRO_RESERVA_CENTAVOS))throw new DomainException('El efectivo disponible ha cambiado. El retiro dejaría menos de $1,000; rechaza y solicita otro.');
    $motivo='Retiro supervisado #'.$id.' | '.(string)$sol['motivo'];
    gr_query($db,'INSERT INTO pos_movimientos_caja(turno_id,usuario_id,tipo,monto,motivo) VALUES (?,?,\'RETIRO\',?,?)',[(int)$t['id'],$uid,gr_pesos($importe),mb_substr($motivo,0,255,'UTF-8')]);
    $mov=(int)$db->lastInsertId();
    // Se reutiliza la bitácora monetaria existente cuando está instalada, sin duplicar el movimiento POS.
    $reg=gr_query($db,"SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='erp_caja_movimientos_control'")->fetchColumn();
    if($reg){gr_query($db,'INSERT INTO erp_caja_movimientos_control(operacion_clave,turno_id,movimiento_id,usuario_id,tipo,monto,motivo,referencia) VALUES (?,?,?,? ,\'RETIRO\',?,?,?)',[bin2hex(random_bytes(16)),(int)$t['id'],$mov,$uid,gr_pesos($importe),mb_substr($motivo,0,255,'UTF-8'),$referencia?:'RETIRO-'.$id]);}
    gr_query($db,"UPDATE erp_retiros_supervisados SET estado='CONFIRMADO',revisor_id=?,movimiento_id=?,resolucion_en=NOW(),nota_revisor=?,referencia=? WHERE id=?",[$uid,$mov,$nota?:null,$referencia?:('RETIRO-'.$id),$id]);
    gr_audit($db,$uid,'RETIRO_CAJA_CONFIRMADO',['retiro_id'=>$id,'movimiento_id'=>$mov,'turno_id'=>(int)$t['id'],'importe'=>gr_pesos($importe)]);
    $msg='Retiro #'.$id.' confirmado y descontado UNA vez del efectivo esperado del turno.';
   }else{
    gr_query($db,"UPDATE erp_retiros_supervisados SET estado='RECHAZADO',revisor_id=?,resolucion_en=NOW(),nota_revisor=? WHERE id=?",[$uid,$nota?:'Solicitud rechazada',$id]);
    gr_audit($db,$uid,'RETIRO_CAJA_RECHAZADO',['retiro_id'=>$id,'turno_id'=>(int)$t['id']]);
    $msg='Solicitud #'.$id.' rechazada. No se descontó efectivo.';
   }
   $db->commit();$_SESSION['gyd_retiro_supervision_flash']=$msg;header('Location: erp_retiros_supervision.php',true,303);exit;
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 }
 $msg=(string)($_SESSION['gyd_retiro_supervision_flash']??'');unset($_SESSION['gyd_retiro_supervision_flash']);
 $baseSql='FROM erp_retiros_supervisados r JOIN pos_turnos t ON t.id=r.turno_id JOIN pos_cajas c ON c.id=r.caja_id JOIN pos_sucursales s ON s.id=c.sucursal_id LEFT JOIN usuarios uc ON uc.id=r.cajero_id LEFT JOIN usuarios us ON us.id=r.revisor_id';
 $pendientes=gr_query($db,'SELECT r.*,c.nombre caja,s.nombre sucursal,uc.nombre cajero,us.nombre supervisor '.$baseSql." WHERE r.estado='SOLICITADO' ORDER BY r.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
 $ultimos=gr_query($db,'SELECT r.*,c.nombre caja,s.nombre sucursal,uc.nombre cajero,us.nombre supervisor '.$baseSql." WHERE r.estado<>'SOLICITADO' ORDER BY r.id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
}catch(Throwable $e){$error=$e instanceof DomainException?$e->getMessage():'Error al cargar retiros; verifica la migración.';error_log('G&D autorización retiros: '.$e->getMessage());}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>G&D | Confirmación de retiros</title><style>*{box-sizing:border-box}body{font:15px system-ui;background:#eef3f9;color:#173856;margin:0}header{padding:22px;background:#174d7a;color:white}header a{color:#fff}main{max-width:1250px;margin:16px auto;padding:0 16px}article{padding:20px;background:#fff;border:1px solid #dde8f2;border-radius:13px;margin:14px 0}table{border-collapse:collapse;width:100%;min-width:1050px}th,td{padding:10px;border-bottom:1px solid #e0e8f1;font-size:13px;text-align:left;vertical-align:top}th{color:white;background:#155584}.scroll{overflow:auto}input,textarea{padding:10px;border:1px solid #b5c6d9;border-radius:8px;font:inherit;width:100%}button{padding:10px 13px;border:0;background:#165a9c;color:white;border-radius:8px;font-weight:700;cursor:pointer}button.red{background:#a64035}.warn{padding:12px;background:#fff4df}.ok{padding:12px;background:#e2f7e9}.error{padding:12px;background:#ffe5e5}.forms{display:flex;gap:8px}.forms form{flex:1;min-width:120px}.forms input{margin:3px 0 6px}.forms button{width:100%}h1{margin:0 0 10px}small{color:#64768a}</style></head><body>
<header><h1>Confirmación de retiros · G&D</h1><a href="erp_control_cajas.php">← Control de Cajas</a> · <a href="erp_retiros_diarios.php">Cortes y retiros por día</a></header><main>
<?php if($error):?><div class="error"><?=gr_h($error)?></div><?php endif;?><?php if($msg):?><div class="ok"><?=gr_h($msg)?></div><?php endif;?>
<div class="warn">Un retiro solicitado <strong>todavía no afecta el corte</strong>. Confirma únicamente cuando recibiste físicamente el dinero y cotejaste el importe con el cajero. Al aprobar se registra un movimiento RETIRO en el mismo libro del POS. No lo captures de nuevo desde movimientos manuales.</div>
<article><h2>Pendientes de recibir y confirmar (<?=count($pendientes)?>)</h2><div class="scroll"><table><thead><tr><th># / fecha</th><th>Sucursal / caja</th><th>Cajero</th><th>Esperado / Contado</th><th>Retiro solicitado</th><th>En caja declarado después</th><th>Confirmación o rechazo</th></tr></thead><tbody>
<?php foreach($pendientes as $p):?><tr><td>#<?=(int)$p['id']?><br><small><?=gr_h($p['solicitud_en'])?></small></td><td><?=gr_h($p['sucursal'])?><br><?=gr_h($p['caja'])?> · Turno #<?=(int)$p['turno_id']?></td><td><?=gr_h($p['cajero'])?></td><td>Esperado: <?=gr_money(gr_cents($p['esperado_solicitud']))?><br>Contado: <?=gr_money(gr_cents($p['contado_declarado']))?><br><small>Diferencia: <?=gr_money(gr_cents($p['contado_declarado'])-gr_cents($p['esperado_solicitud']))?></small></td><td><b><?=gr_money(gr_cents($p['importe']))?></b></td><td><?=gr_money(gr_cents($p['contado_declarado'])-gr_cents($p['importe']))?></td><td class="forms">
<form method="post" onsubmit="return confirm('¿Confirmas que recibiste físicamente el importe exacto?')"><input type="hidden" name="csrf" value="<?=gr_h(gr_csrf())?>"><input type="hidden" name="retiro_id" value="<?=(int)$p['id']?>"><input type="hidden" name="accion" value="confirmar"><input name="referencia" maxlength="120" placeholder="Recibo / folio"><input name="nota" maxlength="255" placeholder="Nota opcional"><button>Confirmar entrega</button></form>
<form method="post" onsubmit="return confirm('¿Rechazar la solicitud sin descontar la caja?')"><input type="hidden" name="csrf" value="<?=gr_h(gr_csrf())?>"><input type="hidden" name="retiro_id" value="<?=(int)$p['id']?>"><input type="hidden" name="accion" value="rechazar"><input name="nota" maxlength="255" placeholder="Motivo (opcional)"><button class="red">Rechazar</button></form></td></tr><?php endforeach;?>
<?php if(!$pendientes):?><tr><td colspan="7">No hay solicitudes pendientes.</td></tr><?php endif;?></tbody></table></div></article>
<article><h2>Últimas solicitudes resueltas</h2><div class="scroll"><table><thead><tr><th>Fecha</th><th>Folio</th><th>Sucursal</th><th>Importe</th><th>Estado</th><th>Supervisor</th><th>Referencia</th></tr></thead><tbody><?php foreach($ultimos as $p):?><tr><td><?=gr_h($p['resolucion_en'])?></td><td>#<?=(int)$p['id']?></td><td><?=gr_h($p['sucursal'])?></td><td><?=gr_money(gr_cents($p['importe']))?></td><td><?=gr_h($p['estado'])?></td><td><?=gr_h($p['supervisor'])?></td><td><?=gr_h($p['referencia']??'—')?></td></tr><?php endforeach;?></tbody></table></div></article>
</main></body></html>
