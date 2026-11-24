<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_cxp.php';
requireLogin();
$usuario=currentUser()?:[];
$uid=(int)($usuario['id']??0);
if (!$uid || !cxp_compras($usuario)) {http_response_code(403);exit('Este módulo está reservado para Compras, Dirección y Administración.');}
$admin=cxp_admin($usuario);
$pdo=(new Database())->connect();
$errores='';$exito=(string)($_SESSION['tes_flash']??'');unset($_SESSION['tes_flash']);
if (empty($_SESSION['tes_csrf'])) $_SESSION['tes_csrf']=bin2hex(random_bytes(24));
if (empty($_SESSION['tes_token'])) $_SESSION['tes_token']=bin2hex(random_bytes(16));
$csrf=(string)$_SESSION['tes_csrf'];$token=(string)$_SESSION['tes_token'];
$h=static fn(mixed $v):string=>cxp_e($v);
$dinero=static fn(int $c):string=>'$'.number_format($c/100,2,'.',',');
$today=date('Y-m-d');
function tes_auditar(PDO $db,int $id,string $accion,string $fecha,string $prioridad,int $uid,string $detail):void {
 $st=$db->prepare('INSERT INTO erp_tes_programaciones_historial(programacion_id,accion,fecha_programada,prioridad,usuario_id,detalle) VALUES(?,?,?,?,?,?)');
 $st->execute([$id,$accion,$fecha,$prioridad,$uid,mb_substr($detail,0,600)]);
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
 try {
  if (!hash_equals($csrf,(string)($_POST['csrf']??''))) throw new RuntimeException('Formulario vencido. Recarga la página.');
  if (!hash_equals($token,(string)($_POST['token']??''))) throw new RuntimeException('Esta operación ya fue procesada. Recarga la página.');
  $accion=(string)($_POST['accion']??'');
  if (!in_array($accion,['solicitar','reprogramar','autorizar','rechazar','cancelar'],true))throw new RuntimeException('Operación no válida.');
  $razon=trim((string)($_POST['observaciones']??''));
  if (mb_strlen($razon)>500)throw new RuntimeException('La observación supera 500 caracteres.');
  $pdo->beginTransaction();
  if ($accion==='solicitar') {
   $sid=(int)($_POST['solicitud_id']??0);
   $st=$pdo->prepare('SELECT s.id,s.documento_id,s.estado,s.tipo,s.importe FROM erp_cxp_solicitudes s WHERE s.id=? FOR UPDATE');$st->execute([$sid]);$s=$st->fetch(PDO::FETCH_ASSOC);
   if (!$s || $s['tipo']!=='PAGO' || $s['estado']!=='AUTORIZADA') throw new RuntimeException('Selecciona una solicitud de pago autorizada en Cuentas por Pagar.');
   $st=$pdo->prepare('SELECT id FROM erp_tes_programaciones WHERE solicitud_id=? FOR UPDATE');$st->execute([$sid]);
   if ($st->fetchColumn())throw new RuntimeException('Esta solicitud ya tiene una programación; edita o revisa la existente.');
   $fecha=(string)($_POST['fecha_programada']??'');$prioridad=(string)($_POST['prioridad']??'NORMAL');
   if(!cxp_date($fecha) || $fecha<$today || $fecha>date('Y-m-d',strtotime('+2 years')) || !in_array($prioridad,['BAJA','NORMAL','ALTA','URGENTE'],true))throw new RuntimeException('Elige una fecha futura válida y una prioridad.');
   if (mb_strlen($razon)<8)throw new RuntimeException('Escribe el motivo de la programación (mínimo 8 caracteres).');
   $st=$pdo->prepare("INSERT INTO erp_tes_programaciones(solicitud_id,documento_id,fecha_programada,prioridad,observaciones,solicitada_por) VALUES(?,?,?,?,?,?)");
   $st->execute([$sid,$s['documento_id'],$fecha,$prioridad,$razon,$uid]);
   $id=(int)$pdo->lastInsertId();tes_auditar($pdo,$id,'SOLICITADA',$fecha,$prioridad,$uid,$razon);
   $msg='Programación enviada a Dirección. No se registró ningún pago.';
  } else {
   $id=(int)($_POST['programacion_id']??0);
   $st=$pdo->prepare('SELECT p.*,s.estado AS estado_solicitud,s.tipo FROM erp_tes_programaciones p JOIN erp_cxp_solicitudes s ON s.id=p.solicitud_id WHERE p.id=? FOR UPDATE');
   $st->execute([$id]);$p=$st->fetch(PDO::FETCH_ASSOC);
   if (!$p)throw new RuntimeException('La programación solicitada no existe.');
   if($p['tipo']!=='PAGO' || $p['estado_solicitud']!=='AUTORIZADA')throw new RuntimeException('Esta solicitud ya no está autorizada para pagar. Revisa Cuentas por Pagar.');
   if ($accion==='reprogramar') {
    if (!in_array($p['estado'],['PENDIENTE','AUTORIZADA','RECHAZADA'],true)) throw new RuntimeException('No se puede reprogramar un registro cancelado.');
    if (!$admin && (int)$p['solicitada_por']!==$uid) throw new RuntimeException('No puedes reprogramar la solicitud de otra persona.');
    $fecha=(string)($_POST['fecha_programada']??'');$prioridad=(string)($_POST['prioridad']??'NORMAL');
    if(!cxp_date($fecha) || $fecha<$today || $fecha>date('Y-m-d',strtotime('+2 years')) || !in_array($prioridad,['BAJA','NORMAL','ALTA','URGENTE'],true))throw new RuntimeException('Fecha o prioridad inválida.');
    if(mb_strlen($razon)<8)throw new RuntimeException('Escribe el motivo de la reprogramación.');
    $st=$pdo->prepare("UPDATE erp_tes_programaciones SET fecha_programada=?,prioridad=?,observaciones=?,estado='PENDIENTE',solicitada_por=?,solicitada_en=NOW(),autorizada_por=NULL,autorizada_en=NULL,revision_observaciones=NULL WHERE id=?");
    $st->execute([$fecha,$prioridad,$razon,$uid,$id]);
    tes_auditar($pdo,$id,'REPROGRAMADA',$fecha,$prioridad,$uid,$razon);
    $msg='Reprogramación enviada para nueva autorización. No se ha realizado ningún pago.';
   } elseif($accion==='autorizar' || $accion==='rechazar') {
    if (!$admin)throw new RuntimeException('Solo Dirección o Administración pueden autorizar.');
    if($p['estado']!=='PENDIENTE')throw new RuntimeException('La programación ya fue revisada.');
    if((int)$p['solicitada_por']===$uid)throw new RuntimeException('No puedes autorizar tu propia programación; debe revisarla otra persona.');
    if ($accion==='rechazar' && mb_strlen($razon)<8)throw new RuntimeException('Indica el motivo del rechazo, mínimo 8 caracteres.');
    $status=$accion==='autorizar'?'AUTORIZADA':'RECHAZADA';
    $st=$pdo->prepare('UPDATE erp_tes_programaciones SET estado=?,autorizada_por=?,autorizada_en=NOW(),revision_observaciones=? WHERE id=? AND estado=\'PENDIENTE\'');
    $st->execute([$status,$uid,$razon?:null,$id]);if($st->rowCount()!==1)throw new RuntimeException('Otra persona modificó la programación.');
    tes_auditar($pdo,$id,$status,$p['fecha_programada'],$p['prioridad'],$uid,$razon?:$status);
    $msg=$status==='AUTORIZADA'?'Fecha autorizada. El pago todavía no está realizado.':'Programación rechazada; sigue sin afectar el saldo.';
   } else {
    if(!in_array($p['estado'],['PENDIENTE','AUTORIZADA','RECHAZADA'],true))throw new RuntimeException('La programación ya fue cancelada.');
    if (!$admin && (int)$p['solicitada_por']!==$uid)throw new RuntimeException('Sin permiso para cancelar esta programación.');
    if (!$admin && $p['estado']==='AUTORIZADA')throw new RuntimeException('Solo Dirección puede cancelar una programación aprobada.');
    if(mb_strlen($razon)<8)throw new RuntimeException('Escribe el motivo de la cancelación.');
    $st=$pdo->prepare("UPDATE erp_tes_programaciones SET estado='CANCELADA',revision_observaciones=? WHERE id=?");$st->execute([$razon,$id]);
    tes_auditar($pdo,$id,'CANCELADA',$p['fecha_programada'],$p['prioridad'],$uid,$razon);
    $msg='Programación cancelada. La solicitud de pago original no se canceló.';
   }
  }
  $pdo->commit();$_SESSION['tes_token']=bin2hex(random_bytes(16));$_SESSION['tes_flash']=$msg;
  header('Location: erp_tesoreria.php',true,303);exit;
 } catch(Throwable $e) {
  if($pdo->inTransaction())$pdo->rollBack();
  error_log('ERP tesoreria: '.$e->getMessage());
  $errores=$e instanceof RuntimeException?$e->getMessage():'No se pudo guardar. Revisa el registro de errores de PHP.';
 }
}
$mes=(string)($_GET['mes']??substr($today,0,7));
if(!preg_match('/^20\d\d-(?:0[1-9]|1[0-2])$/D',$mes))$mes=substr($today,0,7);
$sucursal=(string)($_GET['sucursal']??'');
if(!in_array($sucursal,['','TAPACHULA','TOSCANA','HIDALGO','TUXTLA'],true))$sucursal='';
$proveedor=(int)($_GET['proveedor']??0);
$prioridades=['BAJA','NORMAL','ALTA','URGENTE'];
$firstMonth=$mes.'-01';$lastMonth=date('Y-m-t',strtotime($firstMonth));
// Totales del saldo actual, independientemente de la fecha de programación.
$docs=[];$programaciones=[];$elegibles=[];$proveedores=[];$audit=[];
try {
 $provs=$pdo->query('SELECT id,nombre FROM proveedores ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);$proveedores=$provs;
 $params=[];$conditions=[];
 if ($sucursal!==''){$conditions[]='d.sucursal=?';$params[]=$sucursal;}
 if($proveedor>0){$conditions[]='d.proveedor_id=?';$params[]=$proveedor;}
 $where=$conditions?'WHERE '.implode(' AND ',$conditions):'';
 $sql="SELECT d.id,d.proveedor_id,d.sucursal,d.total_documento,d.fecha_vencimiento,d.factura_id,f.numero_factura,pr.nombre AS proveedor,
        COALESCE(a.pagado,0) AS aplicado
        FROM erp_cxp_documentos d JOIN erp_facturas f ON f.id=d.factura_id JOIN proveedores pr ON pr.id=d.proveedor_id
        LEFT JOIN (SELECT documento_id,SUM(importe) pagado FROM erp_cxp_aplicaciones GROUP BY documento_id) a ON a.documento_id=d.id
        $where ORDER BY d.fecha_vencimiento,d.id LIMIT 1000";
 $st=$pdo->prepare($sql);$st->execute($params);$docs=$st->fetchAll(PDO::FETCH_ASSOC);
 $sql="SELECT p.*,s.importe,s.estado estado_solicitud,s.tipo,d.sucursal,d.fecha_vencimiento,f.numero_factura,pr.nombre proveedor,
       u.nombre solicitante,rev.nombre aprobador
       FROM erp_tes_programaciones p
       JOIN erp_cxp_solicitudes s ON s.id=p.solicitud_id
       JOIN erp_cxp_documentos d ON d.id=p.documento_id
       JOIN erp_facturas f ON f.id=d.factura_id JOIN proveedores pr ON pr.id=d.proveedor_id
       LEFT JOIN usuarios u ON u.id=p.solicitada_por LEFT JOIN usuarios rev ON rev.id=p.autorizada_por
       $where ORDER BY p.fecha_programada,p.id DESC LIMIT 500";
 $st=$pdo->prepare($sql);$st->execute($params);$programaciones=$st->fetchAll(PDO::FETCH_ASSOC);
 // Disponibles: solo solicitudes de abono ya aprobadas, sin programación previa.
 $sql="SELECT s.id,s.importe,d.id documento_id,d.sucursal,d.fecha_vencimiento,f.numero_factura,pr.nombre proveedor
       FROM erp_cxp_solicitudes s JOIN erp_cxp_documentos d ON d.id=s.documento_id
       JOIN erp_facturas f ON f.id=d.factura_id JOIN proveedores pr ON pr.id=d.proveedor_id
       LEFT JOIN erp_tes_programaciones p ON p.solicitud_id=s.id
       WHERE s.tipo='PAGO' AND s.estado='AUTORIZADA' AND p.id IS NULL".
       ($conditions?' AND '.implode(' AND ',$conditions):'')." ORDER BY d.fecha_vencimiento,s.id LIMIT 250";
 $st=$pdo->prepare($sql);$st->execute($params);$elegibles=$st->fetchAll(PDO::FETCH_ASSOC);
 $audit=$pdo->query('SELECT h.*,u.nombre FROM erp_tes_programaciones_historial h LEFT JOIN usuarios u ON u.id=h.usuario_id ORDER BY h.id DESC LIMIT 70')->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e) {error_log('ERP tesoreria consultas: '.$e->getMessage());$errores=$errores?:'No se pudieron consultar datos. Verifica que ejecutaste sql/erp_tesoreria_calendario.sql y la instalación de Cuentas por Pagar.';}
$kpi=['saldo'=>0,'vencido'=>0,'pronto'=>0,'hoy'=>0,'aprobado'=>0,'pendiente_aprobacion'=>0];
$antiguedad=['0-30'=>0,'31-60'=>0,'61-90'=>0,'91+'=>0];
$calendario=[];$alertas=[];
$limit7=date('Y-m-d',strtotime('+7 days'));
foreach($docs as $d) {
 $saldo=max(0,cxp_db_cents($d['total_documento'])-cxp_db_cents($d['aplicado']));if($saldo===0)continue;
 $kpi['saldo']+=$saldo;
 if($d['fecha_vencimiento']<$today){
  $kpi['vencido']+=$saldo;
  $diasVencidos=(int)floor((strtotime($today)-strtotime($d['fecha_vencimiento']))/86400);
  $rango=$diasVencidos<=30?'0-30':($diasVencidos<=60?'31-60':($diasVencidos<=90?'61-90':'91+'));
  $antiguedad[$rango]+=$saldo;
 }
 elseif($d['fecha_vencimiento']<=$limit7)$kpi['pronto']+=$saldo;
 if($d['fecha_vencimiento']===$today)$kpi['hoy']+=$saldo;
 $fecha=$d['fecha_vencimiento'];
 $calendario[$fecha]['vencimientos'][]=['etiqueta'=>$d['proveedor'].' · '.$d['numero_factura'],'importe'=>$saldo];
 if($fecha<=date('Y-m-d',strtotime('+14 days')))$alertas[]=['fecha'=>$fecha,'titulo'=>$d['proveedor'].' · '.$d['numero_factura'],'importe'=>$saldo,'tipo'=>'Vencimiento','id'=>$d['id']];
}
foreach($programaciones as $p) {
 if($p['estado_solicitud']==='APLICADA'||$p['estado']==='CANCELADA'||$p['estado']==='RECHAZADA')continue;
 $amt=cxp_db_cents($p['importe']);
 if($p['estado']==='AUTORIZADA')$kpi['aprobado']+=$amt;
 if($p['estado']==='PENDIENTE')$kpi['pendiente_aprobacion']+=$amt;
 $calendario[$p['fecha_programada']]['programados'][]=['etiqueta'=>$p['proveedor'].' · '.$p['numero_factura'],'importe'=>$amt,'estado'=>$p['estado']];
 if($p['estado']==='AUTORIZADA'&&$p['fecha_programada']<=date('Y-m-d',strtotime('+7 days')))$alertas[]=['fecha'=>$p['fecha_programada'],'titulo'=>$p['proveedor'].' · '.$p['numero_factura'],'importe'=>$amt,'tipo'=>'Pago programado','id'=>$p['documento_id']];
}
usort($alertas,static fn($a,$b)=>strcmp($a['fecha'],$b['fecha']));
if(($_GET['export']??'')==='programaciones') {
 header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="GYD_programaciones_'.$mes.'.csv"');
 echo "\xEF\xBB\xBF";$out=fopen('php://output','wb');
 fputcsv($out,['Proveedor','Factura','Sucursal','Solicitud','Importe MXN','Fecha programada','Prioridad','Estado fecha','Estado pago','Solicitante','Aprobador'],';');
 foreach($programaciones as $p) fputcsv($out,[$p['proveedor'],$p['numero_factura'],$p['sucursal'],$p['solicitud_id'],$p['importe'],$p['fecha_programada'],$p['prioridad'],$p['estado'],$p['estado_solicitud'],$p['solicitante'],$p['aprobador']],';');
 fclose($out);exit;
}
if(($_GET['export']??'')==='csv') {
 header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="GYD_tesoreria_'.$mes.'.csv"');
 echo "\xEF\xBB\xBF";$out=fopen('php://output','wb');fputcsv($out,['Factura','Proveedor','Sucursal','Vencimiento','Importe MXN','Aplicado MXN','Saldo MXN'],';');
 foreach($docs as $d)fputcsv($out,[$d['numero_factura'],$d['proveedor'],$d['sucursal'],$d['fecha_vencimiento'],$d['total_documento'],$d['aplicado'],cxp_sql_money(max(0,cxp_db_cents($d['total_documento'])-cxp_db_cents($d['aplicado'])))],';');
 fclose($out);exit;
}
$startWeek=(int)date('N',strtotime($firstMonth));$days=(int)date('t',strtotime($firstMonth));
$prevMes=date('Y-m',strtotime($firstMonth.' -1 month'));$nextMes=date('Y-m',strtotime($firstMonth.' +1 month'));
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ERP G&amp;D | Tesorería y vencimientos</title>
<style>
*{box-sizing:border-box}body{margin:0;color:#163655;background:#edf3f9;font:14px system-ui,Segoe UI,Arial,sans-serif}a{color:#165caa;text-decoration:none}a:hover{text-decoration:underline}.head{background:linear-gradient(120deg,#153959,#215e9d);color:#fff;padding:24px max(16px,calc((100% - 1280px)/2))}.head h1{margin:0 0 5px;font-size:25px}.head p{margin:0;color:#dceafe}.wrap{max-width:1300px;margin:0 auto;padding:18px 16px 50px}.toolbar{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:15px}.toolbar a,.button,button{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:9px;background:#1b5ea5;color:#fff;font:650 13px system-ui,Arial;min-height:39px;padding:9px 13px;cursor:pointer}.toolbar a{background:white;color:#194a80;border:1px solid #d7e2f2}.toolbar a:hover{background:#e5f0ff}.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:13px}.kpis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.card{background:#fff;border:1px solid #dbe5f1;border-radius:14px;padding:18px;margin-bottom:15px;box-shadow:0 3px 14px #1737590b}.metric{background:#f5f8ff;border-radius:12px;padding:14px}.metric label{display:block;color:#526b82;font-size:12px;font-weight:650}.metric strong{font-size:21px;font-variant-numeric:tabular-nums;overflow-wrap:anywhere}.warning{background:#fff4da;border:1px solid #efd7a4;color:#765211;padding:12px;border-radius:9px;margin-bottom:12px}.error{background:#fdeaea;border-color:#ecb7ba;color:#8e2936}.success{background:#e7f6e9;border-color:#bfe7c8;color:#235b35}.muted{color:#667d96}.small{font-size:12px}.form-row{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px;align-items:end}label{display:block;margin-bottom:5px;font-weight:700}input,select,textarea{width:100%;padding:10px;border:1px solid #c7d6e7;border-radius:9px;font:inherit}textarea{min-height:64px;resize:vertical}.tablewrap{overflow-x:auto}table{width:100%;border-collapse:collapse;min-width:850px}th,td{text-align:left;padding:10px;border-bottom:1px solid #e3edf5;vertical-align:top}th{background:#f0f6ff;color:#234e7b;font-size:12px}td{font-size:13px}.actions{display:flex;align-items:center;gap:6px;flex-wrap:wrap}.actions form{display:flex;gap:5px;align-items:center;flex-wrap:wrap}.actions input{max-width:170px}.actions button{min-height:32px;padding:6px 9px}.bad{background:#bc3d4f}.gray{background:#edf3f9;color:#21517c;border:1px solid #cbd9e7}.badge{display:inline-block;border-radius:99px;background:#eaf1fb;color:#1b5a98;padding:3px 8px;font-size:11px;font-weight:800}.calendar-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:5px}.calendar-name{text-align:center;padding:9px 2px;font-size:12px;color:#5a7087}.calendar-day{min-height:95px;border:1px solid #dce6f0;background:#fff;border-radius:10px;padding:8px;overflow:hidden}.calendar-day.empty{background:#f4f7fa}.calendar-day.today{outline:2px solid #1668bc}.calendar-day .num{font-weight:800;color:#224466}.calendar-entry{font-size:10px;margin-top:5px;padding:5px;border-radius:5px;overflow-wrap:anywhere}.calendar-entry.due{background:#fff0dd;color:#84551e}.calendar-entry.plan{background:#eaf2ff;color:#154d86}.calendar-entry strong{display:block}.note{border-left:3px solid #2971b9;padding:10px 12px;background:#edf5ff}.print-only{display:none}@media(max-width:950px){.kpis{grid-template-columns:repeat(2,1fr)}.form-row{grid-template-columns:repeat(2,1fr)}.calendar-day{min-height:70px;padding:5px}.calendar-entry{font-size:9px}}@media(max-width:600px){.head h1{font-size:21px}.kpis,.form-row{grid-template-columns:1fr}.calendar-grid{gap:2px}.calendar-day{min-height:55px;padding:3px;border-radius:5px}.calendar-entry{font-size:8px;padding:2px}.calendar-name{font-size:10px}.metric strong{font-size:19px}}@media print{.no-print,.actions,form,.toolbar{display:none!important}body{background:#fff}.card{box-shadow:none;page-break-inside:avoid}.wrap{max-width:none}.calendar-day{min-height:70px}}
</style></head><body>
<header class="head"><h1>ERP G&amp;D · Tesorería y Calendario de Pagos</h1><p>Compromisos autorizados, vencimientos, alertas y programación · Sin movimientos bancarios automáticos</p></header>
<main class="wrap"><div class="toolbar no-print"><a href="dashboard.php">← Inicio</a><a href="erp_cuentas_por_pagar.php">Cuentas por Pagar</a><a href="erp_tesoreria.php?mes=<?=urlencode($mes)?>&amp;sucursal=<?=urlencode($sucursal)?>&amp;proveedor=<?=$proveedor?>&amp;export=csv">Exportar CSV</a><a href="erp_tesoreria.php?mes=<?=urlencode($mes)?>&amp;sucursal=<?=urlencode($sucursal)?>&amp;proveedor=<?=$proveedor?>&amp;export=programaciones">Exportar programaciones</a><button type="button" onclick="window.print()">Imprimir / Guardar PDF</button><a href="logout.php">Cerrar sesión</a></div>
<?php if($errores):?><div class="warning error" role="alert"><?=$h($errores)?></div><?php endif;?>
<?php if($exito):?><div class="warning success" role="status"><?=$h($exito)?></div><?php endif;?>
<div class="kpis">
<?php foreach([['Saldo total por pagar','saldo'],['Deuda vencida','vencido'],['Vence en los próximos 7 días','pronto'],['Vence hoy','hoy'],['Pagos programados aprobados','aprobado'],['Programaciones por aprobar','pendiente_aprobacion']] as $item):?><div class="metric"><label><?=$h($item[0])?></label><strong><?=$dinero($kpi[$item[1]])?></strong></div><?php endforeach;?></div>
<section class="card" style="margin-top:15px"><h2>Antigüedad de saldos vencidos</h2><div class="kpis">
<?php foreach($antiguedad as $rango=>$importe):?><div class="metric"><label><?=$h($rango)?> días de atraso</label><strong><?=$dinero($importe)?></strong></div><?php endforeach;?></div></section>
<p class="muted small">Totales calculados sobre hasta 1,000 facturas filtradas. Los importes programados ya están incluidos en la deuda; no deben sumarse al saldo total. Alertas visibles al abrir esta página, no son correos automáticos.</p>
<section class="card no-print"><h2>Filtrar y consultar</h2><form method="get" class="form-row"><div><label>Mes</label><input type="month" name="mes" value="<?=$h($mes)?>"></div><div><label>Sucursal</label><select name="sucursal"><option value="">Todas</option><?php foreach(['TAPACHULA'=>'Tapachula','TOSCANA'=>'Toscana','HIDALGO'=>'Ciudad Hidalgo','TUXTLA'=>'Tuxtla'] as $code=>$name):?><option value="<?=$code?>" <?=$sucursal===$code?'selected':''?>><?=$name?></option><?php endforeach;?></select></div><div><label>Proveedor</label><select name="proveedor"><option value="0">Todos</option><?php foreach($proveedores as $p):?><option value="<?=(int)$p['id']?>" <?=$proveedor===(int)$p['id']?'selected':''?>><?=$h($p['nombre'])?></option><?php endforeach;?></select></div><div><button>Aplicar filtros</button></div></form></section>
<section class="card"><h2>Alertas de vencimiento y pagos próximos</h2><div class="tablewrap"><table><thead><tr><th>Fecha</th><th>Tipo</th><th>Proveedor / Factura</th><th>Importe</th><th>Situación</th><th></th></tr></thead><tbody><?php foreach(array_slice($alertas,0,80) as $a):?><tr><td><?=$h($a['fecha'])?></td><td><?=$h($a['tipo'])?></td><td><?=$h($a['titulo'])?></td><td><?=$dinero($a['importe'])?></td><td><?=$a['fecha']<$today?'Vencido':($a['fecha']===$today?'Hoy':'Próximo')?></td><td><a href="erp_cuentas_por_pagar.php?id=<?=(int)$a['id']?>">Ver cuenta</a></td></tr><?php endforeach;?><?php if(!$alertas):?><tr><td colspan="6">No hay vencimientos o programaciones urgentes para el filtro.</td></tr><?php endif;?></tbody></table></div></section>
<section class="card"><div style="display:flex;justify-content:space-between;gap:10px;align-items:center"><h2>Calendario de <?=$h($mes)?></h2><div class="toolbar no-print"><a href="?mes=<?=$h($prevMes)?>&amp;sucursal=<?=$h($sucursal)?>&amp;proveedor=<?=$proveedor?>">‹ Anterior</a><a href="?mes=<?=$h($nextMes)?>&amp;sucursal=<?=$h($sucursal)?>&amp;proveedor=<?=$proveedor?>">Siguiente ›</a></div></div>
<div class="calendar-grid"><?php foreach(['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'] as $dw):?><div class="calendar-name"><?=$dw?></div><?php endforeach;?>
<?php for($i=1;$i<$startWeek;$i++):?><div class="calendar-day empty"></div><?php endfor; ?>
<?php for($dia=1;$dia<=$days;$dia++):$fecha=$mes.'-'.str_pad((string)$dia,2,'0',STR_PAD_LEFT);$c=$calendario[$fecha]??[];?><div class="calendar-day <?=$fecha===$today?'today':''?>"><span class="num"><?=$dia?></span>
<?php if(!empty($c['vencimientos'])):$tot=array_sum(array_column($c['vencimientos'],'importe'));?><div class="calendar-entry due"><strong><?=$dinero($tot)?></strong><?=count($c['vencimientos'])?> vencimiento(s)</div><?php endif;?>
<?php if(!empty($c['programados'])):$tot=array_sum(array_column($c['programados'],'importe'));?><div class="calendar-entry plan"><strong><?=$dinero($tot)?></strong><?=count($c['programados'])?> pago(s) programado(s)</div><?php endif;?></div><?php endfor;?></div>
<p class="muted small">Naranja = facturas que vencen; azul = pagos planificados. Son dos vistas de los mismos compromisos y sus cifras no deben sumarse entre sí.</p></section>
<?php if($elegibles):?><section class="card no-print"><h2>Programar una solicitud de pago ya autorizada</h2><p class="note small">Primero crea y autoriza una solicitud de PAGO en Cuentas por Pagar. Aquí solo se aprueba la FECHA. La deuda no cambia.</p><form method="post" class="form-row"><input type="hidden" name="csrf" value="<?=$h($csrf)?>"><input type="hidden" name="token" value="<?=$h($token)?>"><input type="hidden" name="accion" value="solicitar">
<div><label>Solicitud autorizada</label><select name="solicitud_id" required><option value="">Selecciona...</option><?php foreach($elegibles as $s):?><option value="<?=(int)$s['id']?>">#<?=(int)$s['id']?> · <?=$h($s['proveedor'])?> · <?=$h($s['numero_factura'])?> · <?=$dinero(cxp_db_cents($s['importe']))?></option><?php endforeach;?></select></div>
<div><label>Fecha propuesta</label><input name="fecha_programada" type="date" min="<?=$today?>" required value="<?=$today?>"></div>
<div><label>Prioridad</label><select name="prioridad"><?php foreach($prioridades as $prio):?><option value="<?=$prio?>"><?=$prio?></option><?php endforeach;?></select></div>
<div><label>Motivo de la programación</label><textarea name="observaciones" minlength="8" maxlength="500" required placeholder="Justifica fecha y prioridad"></textarea><button type="submit" style="margin-top:8px">Solicitar aprobación de fecha</button></div></form></section><?php endif;?>
<section class="card"><h2>Programaciones y autorizaciones</h2><div class="tablewrap"><table><thead><tr><th>Proveedor y factura</th><th>Sucursal</th><th>Solicitud</th><th>Programado para</th><th>Prioridad</th><th>Estado</th><th>Responsables</th><th class="no-print">Acciones</th></tr></thead><tbody>
<?php foreach($programaciones as $p):$yaPagado=$p['estado_solicitud']==='APLICADA';$estado=$yaPagado?'PAGO REGISTRADO':($p['estado_solicitud']!=='AUTORIZADA'?'SOLICITUD CAMBIÓ':$p['estado']);?><tr><td><b><?=$h($p['proveedor'])?></b><div class="muted small"><?=$h($p['numero_factura'])?></div></td><td><?=$h($p['sucursal'])?></td><td><?=$dinero(cxp_db_cents($p['importe']))?> <div class="muted small">#<?=(int)$p['solicitud_id']?></div></td><td><?=$h($p['fecha_programada'])?></td><td><span class="badge"><?=$h($p['prioridad'])?></span></td><td><span class="badge"><?=$h($estado)?></span></td><td><?=$h($p['solicitante']??'')?><div class="muted small">Autoriza: <?=$h($p['aprobador']??'—')?></div></td><td class="actions no-print">
<?php if(!$yaPagado&&$p['estado_solicitud']==='AUTORIZADA'):?>
<?php if($p['estado']==='PENDIENTE'&&$admin&&(int)$p['solicitada_por']!==$uid):?><form method="post"><input type="hidden" name="csrf" value="<?=$h($csrf)?>"><input type="hidden" name="token" value="<?=$h($token)?>"><input type="hidden" name="programacion_id" value="<?=(int)$p['id']?>"><input type="hidden" name="accion" value="autorizar"><button onclick="return confirm('¿Aprobar fecha? No se registrará el pago.')">Autorizar</button></form><form method="post"><input type="hidden" name="csrf" value="<?=$h($csrf)?>"><input type="hidden" name="token" value="<?=$h($token)?>"><input type="hidden" name="programacion_id" value="<?=(int)$p['id']?>"><input type="hidden" name="accion" value="rechazar"><input name="observaciones" minlength="8" maxlength="500" placeholder="Motivo obligatorio" required><button class="bad">Rechazar</button></form><?php endif;?>
<?php if(in_array($p['estado'],['PENDIENTE','AUTORIZADA','RECHAZADA'],true)&&($admin||(int)$p['solicitada_por']===$uid)):?><details><summary style="cursor:pointer">Reprogramar</summary><form method="post"><input type="hidden" name="csrf" value="<?=$h($csrf)?>"><input type="hidden" name="token" value="<?=$h($token)?>"><input type="hidden" name="accion" value="reprogramar"><input type="hidden" name="programacion_id" value="<?=(int)$p['id']?>"><input type="date" min="<?=$today?>" name="fecha_programada" value="<?=$h(max($today,$p['fecha_programada']))?>" required><select name="prioridad"><?php foreach($prioridades as $pr):?><option value="<?=$pr?>" <?=$p['prioridad']===$pr?'selected':''?>><?=$pr?></option><?php endforeach;?></select><input name="observaciones" minlength="8" maxlength="500" required placeholder="Motivo de cambio"><button>Solicitar nueva aprobación</button></form></details><?php if($admin||$p['estado']!=='AUTORIZADA'):?><form method="post"><input type="hidden" name="csrf" value="<?=$h($csrf)?>"><input type="hidden" name="token" value="<?=$h($token)?>"><input type="hidden" name="accion" value="cancelar"><input type="hidden" name="programacion_id" value="<?=(int)$p['id']?>"><input name="observaciones" minlength="8" maxlength="500" required placeholder="Motivo de cancelación"><button class="gray" onclick="return confirm('¿Cancelar la fecha programada? La solicitud CXP seguirá existiendo.')">Cancelar</button></form><?php endif;?><?php endif;?><?php endif;?>
<a href="erp_cuentas_por_pagar.php?id=<?=(int)$p['documento_id']?>">Ver CXP</a>
</td></tr><?php endforeach;?><?php if(!$programaciones):?><tr><td colspan="8">No hay programaciones todavía. Crea primero una solicitud autorizada desde Cuentas por Pagar.</td></tr><?php endif;?></tbody></table></div></section>
<section class="card"><h2>Facturas pendientes y antigüedad</h2><div class="tablewrap"><table><thead><tr><th>Factura</th><th>Proveedor</th><th>Sucursal</th><th>Vence</th><th>Saldo</th><th>Situación</th></tr></thead><tbody><?php foreach($docs as $d):$saldo=max(0,cxp_db_cents($d['total_documento'])-cxp_db_cents($d['aplicado']));if(!$saldo)continue;$dias=(int)floor((strtotime($today)-strtotime($d['fecha_vencimiento']))/86400);?><tr><td><?=$h($d['numero_factura'])?></td><td><?=$h($d['proveedor'])?></td><td><?=$h($d['sucursal'])?></td><td><?=$h($d['fecha_vencimiento'])?></td><td><?=$dinero($saldo)?></td><td><?=$dias>0?$dias.' días vencida':($dias===0?'Vence hoy':'En '.abs($dias).' días')?></td></tr><?php endforeach;?><?php if(!$docs):?><tr><td colspan="6">No hay cuentas registradas con este filtro.</td></tr><?php endif;?></tbody></table></div></section>
<details class="card"><summary style="cursor:pointer;font-weight:800">Bitácora de programación reciente</summary><div class="tablewrap"><table><thead><tr><th>Fecha</th><th>Acción</th><th>Usuario</th><th>Detalle</th></tr></thead><tbody><?php foreach($audit as $a):?><tr><td><?=$h($a['creado_en'])?></td><td><?=$h($a['accion'])?></td><td><?=$h($a['nombre']??$a['usuario_id'])?></td><td><?=$h($a['detalle'])?></td></tr><?php endforeach;?><?php if(!$audit):?><tr><td colspan="4">Sin eventos todavía.</td></tr><?php endif;?></tbody></table></div></details>
</main></body></html>
