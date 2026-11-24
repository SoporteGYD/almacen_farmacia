<?php
declare(strict_types=1);
/** Dashboard auxiliar de desempeño operacional, lectura únicamente. */
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
requireLogin();
$me=currentUser()?:[];
$rol=strtoupper(trim((string)($me['rol_original']??$me['rol']??'')));
if(!in_array($rol,['ADMINISTRADOR','ADMIN','DIRECCION'],true)){http_response_code(403);exit('Acceso exclusivo a Administración y Dirección.');}
header('Cache-Control: private, no-store, max-age=0');header('X-Content-Type-Options: nosniff');
function ef_h(mixed $x):string{return htmlspecialchars((string)$x,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function ef_number(mixed $x,int $precision=1):string{return $x===null?'—':number_format((float)$x,$precision,'.',',');}
function ef_time(mixed $x):string{if($x===null)return '—';$v=(int)round((float)$x);return $v>=3600?sprintf('%d h %02d m',intdiv($v,3600),intdiv($v%3600,60)):sprintf('%d:%02d',intdiv($v,60),$v%60);}
function ef_percentile(array $nums,float $q):?float{if(!$nums)return null;sort($nums,SORT_NUMERIC);$index=($q/100)*(count($nums)-1);$lo=(int)floor($index);$hi=(int)ceil($index);return $nums[$lo]+($nums[$hi]-$nums[$lo])*($index-$lo);}
function ef_validDate(string $s):bool{$x=DateTimeImmutable::createFromFormat('!Y-m-d',$s);return $x!==false&&$x->format('Y-m-d')===$s;}
function ef_csvCell(mixed $v):string{$s=(string)$v;return preg_match('/^[\s]*[=+@\-\t\r]/u',$s)?"'".$s:$s;}
$hoy=(new DateTimeImmutable('today'))->format('Y-m-d');
$desde=trim((string)($_GET['desde']??(new DateTimeImmutable('first day of this month'))->format('Y-m-d')));
$hasta=trim((string)($_GET['hasta']??$hoy));
$sucursal=max(0,(int)($_GET['sucursal']??0));$cajero=max(0,(int)($_GET['cajero']??0));
$error='';$users=[];$branches=[];$rows=[];$details=[];$methods=[];$totales=['tickets'=>0,'medidos'=>0,'piezas'=>0,'importe'=>0];
try {
 if(!ef_validDate($desde)||!ef_validDate($hasta)||$desde>$hasta||$hasta>$hoy||(strtotime($hasta)-strtotime($desde)>366*86400))throw new RuntimeException('Selecciona un periodo válido de hasta 367 días, sin fechas futuras.');
 $db=(new Database())->connect();
 $db->query('SELECT 1 FROM erp_pos_eficiencia_cajeros LIMIT 0');
 $branches=$db->query('SELECT id,nombre FROM pos_sucursales ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
 $users=$db->query("SELECT DISTINCT u.id,u.nombre FROM usuarios u JOIN pos_ventas v ON v.usuario_id=u.id ORDER BY u.nombre")->fetchAll(PDO::FETCH_ASSOC);
 $ini=$desde.' 00:00:00';$fin=(new DateTimeImmutable($hasta))->modify('+1 day')->format('Y-m-d 00:00:00');
 $cond=" v.pagada_en>=? AND v.pagada_en<? AND v.estado IN ('PAGADA','CANCELADA')";$args=[$ini,$fin];
 if($sucursal>0){$cond.=' AND v.sucursal_id=?';$args[]=$sucursal;}
 if($cajero>0){$cond.=' AND v.usuario_id=?';$args[]=$cajero;}
 $sql="SELECT u.id usuario_id,u.nombre cajero,s.id sucursal_id,s.nombre sucursal,COUNT(*) tickets, SUM(v.total) importe, SUM(v.descuento) descuentos, SUM(CASE WHEN v.estado='CANCELADA' THEN 1 ELSE 0 END) canceladas, COUNT(e.venta_id) medidos,
   COALESCE(SUM(e.piezas),0) piezas, COALESCE(SUM(e.lineas),0) lineas,
   AVG(e.total_seg) promedio_total,AVG(e.captura_seg) promedio_captura,AVG(e.pago_seg) promedio_pago,AVG(e.cobro_red_seg) promedio_red,
   AVG(e.activo_seg) promedio_activo,AVG(e.inactivo_seg+e.oculto_seg) promedio_pausas,AVG(e.gestion_almacen_seg) promedio_gestion,
   COALESCE(SUM(e.busquedas),0) busquedas, COALESCE(SUM(e.escaneos),0) escaneos,COALESCE(SUM(e.seleccion_catalogo),0) catalogo,
   COALESCE(SUM(e.cambios_cantidad),0) cambios,COALESCE(SUM(e.productos_quitados),0) eliminados,
   COALESCE(SUM(e.intentos_cobro),0) intentos,COALESCE(SUM(e.errores_cobro),0) errores,
   COALESCE(SUM(e.solicitudes_almacen),0) solicitudes,
   COALESCE(SUM(e.activo_seg),0) segundos_activos,
   SUM(CASE WHEN e.total_seg IS NOT NULL AND e.total_seg<=120 THEN 1 ELSE 0 END) menores_dos_min
   FROM pos_ventas v
   JOIN usuarios u ON u.id=v.usuario_id
   JOIN pos_sucursales s ON s.id=v.sucursal_id
   LEFT JOIN erp_pos_eficiencia_cajeros e ON e.venta_id=v.id AND e.estado='COMPLETADA'
   WHERE $cond GROUP BY u.id,u.nombre,s.id,s.nombre ORDER BY tickets DESC,u.nombre";
 $stmt=$db->prepare($sql);$stmt->execute($args);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
 $sql2="SELECT v.id, v.folio,v.pagada_en,v.total,v.estado,v.usuario_id,v.sucursal_id,u.nombre cajero,s.nombre sucursal,
   e.total_seg,e.captura_seg,e.pago_seg,e.cobro_red_seg,e.activo_seg,e.inactivo_seg,e.oculto_seg,e.gestion_almacen_seg,
   e.lineas,e.piezas,e.busquedas,e.escaneos,e.seleccion_catalogo,e.cambios_cantidad,e.productos_quitados,e.intentos_cobro,e.errores_cobro,e.solicitudes_almacen,e.metodo_pago
   FROM pos_ventas v JOIN usuarios u ON u.id=v.usuario_id JOIN pos_sucursales s ON s.id=v.sucursal_id
   LEFT JOIN erp_pos_eficiencia_cajeros e ON e.venta_id=v.id AND e.estado='COMPLETADA'
   WHERE $cond ORDER BY v.pagada_en DESC, v.id DESC LIMIT 10000";
 $stmt=$db->prepare($sql2);$stmt->execute($args);$details=$stmt->fetchAll(PDO::FETCH_ASSOC);
 $lookup=[];foreach($details as $d)if($d['total_seg']!==null){$lookup[$d['usuario_id'].'|'.$d['sucursal_id']][]=(int)$d['total_seg'];}
 foreach($rows as &$r){
   $duration=$lookup[$r['usuario_id'].'|'.$r['sucursal_id']]??[];
   $r['mediana']=ef_percentile($duration,50);$r['p90']=ef_percentile($duration,90);
   $r['cobertura']=$r['tickets']>0?100*(int)$r['medidos']/(int)$r['tickets']:0;
   $r['ticket_medio']=$r['tickets']>0?(float)$r['importe']/(int)$r['tickets']:null;
   $r['tickets_hora']=$r['segundos_activos']>0?3600*(int)$r['medidos']/(int)$r['segundos_activos']:null;
   $r['piezas_ticket']=$r['medidos']>0?(int)$r['piezas']/(int)$r['medidos']:null;
   $r['lineas_ticket']=$r['medidos']>0?(int)$r['lineas']/(int)$r['medidos']:null;
   foreach(['tickets','medidos','piezas'] as $key)$totales[$key]+=(int)$r[$key];
   $totales['importe']+=(float)$r['importe'];
 }unset($r);
 $qPay="SELECT pg.metodo AS metodo,COUNT(DISTINCT v.id) AS ventas,COALESCE(SUM(pg.monto),0) AS total FROM pos_pagos pg JOIN pos_ventas v ON v.id=pg.venta_id WHERE pg.estado='CONFIRMADO' AND ".$cond.' GROUP BY pg.metodo ORDER BY total DESC';
 $pay=$db->prepare($qPay);$pay->execute($args);$methods=$pay->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e){$error=$e instanceof RuntimeException?$e->getMessage():'No fue posible generar el reporte. Revisa que hayas ejecutado el SQL y consulta el log de PHP.';error_log('ERP eficiencia: '.$e->getMessage());}
if(!$error&&($_GET['exportar']??'')==='csv'){
 header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="GYD_Eficiencia_Cajeros_'.$desde.'_a_'.$hasta.'.csv"');echo "\xEF\xBB\xBF";
 $f=fopen('php://output','w');$line=static function(array $cells)use($f):void{fputcsv($f,array_map('ef_csvCell',$cells),';','"','\\');};
 $line(['ERP G&D · Eficiencia de cajeros']);$line(['Periodo',$desde,$hasta]);$line(['Las duraciones son aproximadas; medición desde primer producto hasta confirmación de venta.']);$line([]);
 $line(['Cajero','Sucursal','Tickets','Medidos','Cobertura %','Promedio total segundos','Mediana segundos','P90 segundos','Captura segundos','Pago segundos','Procesamiento segundos','Inactividad promedio segundos','Tickets por hora activa medida','Piezas por ticket','Líneas por ticket','Escaneos','Búsquedas','Catálogo','Cambios de cantidad','Eliminaciones','Intentos de cobro','Errores al cobrar','Solicitudes de Almacén','Ticket medio (MXN)','Cancelaciones','Descuentos (MXN)']);
 foreach($rows as $r)$line([$r['cajero'],$r['sucursal'],$r['tickets'],$r['medidos'],round($r['cobertura'],1),$r['promedio_total'],$r['mediana'],$r['p90'],$r['promedio_captura'],$r['promedio_pago'],$r['promedio_red'],$r['promedio_pausas'],$r['tickets_hora'],$r['piezas_ticket'],$r['lineas_ticket'],$r['escaneos'],$r['busquedas'],$r['catalogo'],$r['cambios'],$r['eliminados'],$r['intentos'],$r['errores'],$r['solicitudes'],$r['ticket_medio'],$r['canceladas'],$r['descuentos']]);
 $line([]);$line(['DETALLE - máximo 10,000 operaciones']);
 $line(['Folio','Fecha','Cajero','Sucursal','Total de venta','Tiempo total seg','Captura seg','Pago seg','Procesamiento seg','Inactividad seg','Pestaña oculta seg','Gestión solicitudes Almacén seg','Líneas','Piezas','Escaneos','Catálogo','Cambios cantidad','Eliminaciones','Intentos cobro','Errores cobro','Solicitudes Almacén']);
 foreach($details as $d)$line([$d['folio'],$d['pagada_en'],$d['cajero'],$d['sucursal'],$d['total'],$d['total_seg'],$d['captura_seg'],$d['pago_seg'],$d['cobro_red_seg'],$d['inactivo_seg'],$d['oculto_seg'],$d['gestion_almacen_seg'],$d['lineas'],$d['piezas'],$d['escaneos'],$d['seleccion_catalogo'],$d['cambios_cantidad'],$d['productos_quitados'],$d['intentos_cobro'],$d['errores_cobro'],$d['solicitudes_almacen']]);
 fclose($f);exit;
}
$qs=['desde'=>$desde,'hasta'=>$hasta,'sucursal'=>$sucursal,'cajero'=>$cajero];
?><!doctype html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ERP G&D | Eficiencia de Cajeros</title>
<style>
:root{font-family:system-ui,-apple-system,Segoe UI,Arial,sans-serif;background:#f0f4fa;color:#173a61}*{box-sizing:border-box}body{margin:0}
header{background:linear-gradient(110deg,#133960,#2369a4);color:#fff;padding:25px max(18px,calc((100% - 1350px)/2))}
header h1{font-size:clamp(24px,3vw,32px);margin:0}header p{color:#d5e5f7;margin:8px 0 14px;max-width:880px}
.links{display:flex;flex-wrap:wrap;gap:10px}.links a{color:#fff;border:1px solid #b2cce755;text-decoration:none;padding:9px 12px;border-radius:9px;background:#ffffff18;font-weight:600;font-size:13px}
main{max-width:1390px;margin:auto;padding:20px 20px 55px}.card{background:#fff;border-radius:16px;padding:20px;margin-bottom:18px;border:1px solid #dce5ef;box-shadow:0 5px 24px #1533540c}
h2{font-size:18px;margin:0 0 15px}.filters{display:flex;align-items:end;gap:12px;flex-wrap:wrap}.field{display:grid;gap:5px;flex:1;min-width:165px}.field label{font-size:12px;font-weight:650;color:#48637e}
input,select{min-height:41px;padding:9px 11px;border:1px solid #b8cadc;border-radius:9px;background:#fff;font:inherit;color:#183958;width:100%}
.btn{border:0;display:inline-block;text-decoration:none;padding:12px 15px;border-radius:9px;background:#1764ae;color:white;font:650 13px system-ui;cursor:pointer}.btn.secondary{background:#e9f1f9;color:#194f7a}
.metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:13px;margin:18px 0}.metric{border:1px solid #d8e3f0;border-radius:15px;background:#fff;padding:20px;min-width:0}.metric span{font-size:12px;color:#5e748b;display:block;margin-bottom:9px}.metric strong{font-size:clamp(21px,2.5vw,29px);display:block}.metric small{color:#6e8297;display:block;margin-top:8px}
.notice{background:#fff9eb;border-left:4px solid #db9e37;padding:14px 17px;margin-bottom:16px;border-radius:9px;font-size:13px}.error{background:#fff0f2;border-color:#bd4050}.muted{color:#60778e;font-size:13px}table{width:100%;border-collapse:collapse}th,td{border-bottom:1px solid #e7edf5;text-align:left;padding:12px 10px;font-size:13px;white-space:nowrap}th{background:#eaf1fa;color:#244c71;font-size:12px}td.num,th.num{text-align:right;font-variant-numeric:tabular-nums}.scroll{overflow-x:auto}.score{display:inline-flex;align-items:center;gap:7px;font-weight:700}
.progress{display:inline-block;height:8px;width:68px;border-radius:20px;background:#e3eaf2;overflow:hidden}.progress>span{display:block;background:#2683ae;height:100%}.empty{text-align:center;color:#627c95;padding:22px!important}.foot{font-size:12px;line-height:1.6;color:#61748c;margin-top:18px}
@media(max-width:900px){.metrics{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:580px){main{padding:12px}.card{padding:14px}.metrics{gap:7px}.metric{padding:12px}.metric strong{font-size:21px}header{padding:19px 14px}}
@media print{header{background:#fff;color:#111}header p{color:#333}.links,.filters,.actions{display:none}.card,.metric{break-inside:avoid;box-shadow:none}main{padding:0}.scroll{overflow:visible}table{font-size:10px}th,td{font-size:10px}@page{size:A4 landscape;margin:10mm}}
</style></head><body>
<header><h1>Eficiencia de Cajeros · Farmacias G&amp;D</h1><p>Velocidad de captura, pago y productividad por cajero y sucursal. No confundir tiempo de atención con rendimiento individual sin considerar el tipo de cliente, las esperas y los problemas técnicos.</p>
<nav class="links"><a href="erp_eficiencia_diagnostico.php">Diagnóstico del cronómetro</a><a href="erp_dashboard_gerencial.php">← Dashboard Gerencial</a><a href="dashboard.php">Inicio</a><a href="logout.php">Cerrar sesión</a></nav></header>
<main>
<section class="card"><h2>Filtros</h2><form class="filters" method="get">
<div class="field"><label for="desde">Desde</label><input type="date" id="desde" name="desde" value="<?=ef_h($desde)?>" required></div>
<div class="field"><label for="hasta">Hasta</label><input type="date" id="hasta" name="hasta" value="<?=ef_h($hasta)?>" required></div>
<div class="field"><label for="sucursal">Sucursal</label><select name="sucursal" id="sucursal"><option value="0">Todas</option><?php foreach($branches as $b):?><option value="<?=(int)$b['id']?>" <?=$sucursal===(int)$b['id']?'selected':''?>><?=ef_h($b['nombre'])?></option><?php endforeach;?></select></div>
<div class="field"><label for="cajero">Cajero</label><select name="cajero" id="cajero"><option value="0">Todos</option><?php foreach($users as $a):?><option value="<?=(int)$a['id']?>" <?=$cajero===(int)$a['id']?'selected':''?>><?=ef_h($a['nombre'])?></option><?php endforeach;?></select></div>
<button type="submit" class="btn">Consultar</button><a href="erp_eficiencia_cajeros.php" class="btn secondary">Restablecer</a></form></section>
<?php if($error):?><div class="notice error"><?=ef_h($error)?></div><?php else:?>
<div class="actions" style="margin-bottom:16px"><a class="btn" href="?<?=ef_h(http_build_query($qs+['exportar'=>'csv']))?>">Exportar CSV a Excel</a><button class="btn secondary" type="button" onclick="window.print()">Imprimir / Guardar PDF</button></div>
<div class="notice">La medición inicia al agregar el primer producto y concluye al confirmarse la venta. Solo aplica a tickets nuevos desde la instalación. <strong>Cobertura:</strong> <?=ef_number($totales['tickets']>0?$totales['medidos']/$totales['tickets']*100:0)?>% de ventas medidas. Las pausas y demoras por Almacén o por la red se informan por separado; no determinan una calificación automática.</div>
<?php if ($totales['tickets']>0 && $totales['medidos']===0): ?>
<div class="notice error"><strong>No hay tiempos vinculados a las ventas de este periodo.</strong> Abre <a href="erp_eficiencia_diagnostico.php">Diagnóstico del cronómetro</a>, revisa si hay registros INICIADA, realiza una venta nueva y consulta el indicador «Medición» dentro del POS.</div>
<?php endif; ?>
<div class="metrics">
<div class="metric"><span>Tickets del periodo</span><strong><?=number_format($totales['tickets'])?></strong><small>Ventas registradas por cajeros</small></div>
<div class="metric"><span>Tickets con cronómetro</span><strong><?=number_format($totales['medidos'])?></strong><small><?=ef_number($totales['tickets']?100*$totales['medidos']/$totales['tickets']:0)?>% de cobertura</small></div>
<div class="metric"><span>Piezas en tickets medidos</span><strong><?=number_format($totales['piezas'])?></strong><small>Contabilizadas desde partidas reales</small></div>
<div class="metric"><span>Ventas brutas registradas</span><strong>$<?=number_format($totales['importe'],2)?></strong><small>Dato de contexto, no medida aislada de eficiencia</small></div>
</div>
<section class="card"><h2>Comparación por cajero y sucursal</h2><div class="scroll"><table><thead><tr><th>Cajero</th><th>Sucursal</th><th class="num">Tickets</th><th class="num">Medidos</th><th>Cobertura</th><th class="num">Tiempo promedio</th><th class="num">Mediana</th><th class="num">P90</th><th class="num">Captura/prom.</th><th class="num">Pago/prom.</th><th class="num">Proceso/prom.</th><th class="num">Pausas/prom.</th><th class="num">Ritmo/h activa*</th><th class="num">Piezas/ticket</th><th class="num">Líneas/ticket</th><th class="num">Escaneos</th><th class="num">Buscador</th><th class="num">Catálogo</th><th class="num">Cambios cant.</th><th class="num">Quitados</th><th class="num">Intentos cobro</th><th class="num">Errores cobro</th><th class="num">Solicitudes a Almacén</th><th class="num">Ticket promedio</th><th class="num">Canceladas</th><th class="num">Descuentos</th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr><td><strong><?=ef_h($r['cajero'])?></strong></td><td><?=ef_h($r['sucursal'])?></td><td class="num"><?=number_format((int)$r['tickets'])?></td><td class="num"><?=number_format((int)$r['medidos'])?></td><td><span class="score"><?=ef_number($r['cobertura'])?>% <span class="progress"><span style="width:<?=max(0,min(100,round($r['cobertura'])))?>%"></span></span></span></td><td class="num"><?=ef_time($r['promedio_total'])?></td><td class="num"><?=ef_time($r['mediana'])?></td><td class="num"><?=ef_time($r['p90'])?></td><td class="num"><?=ef_time($r['promedio_captura'])?></td><td class="num"><?=ef_time($r['promedio_pago'])?></td><td class="num"><?=ef_time($r['promedio_red'])?></td><td class="num"><?=ef_time($r['promedio_pausas'])?></td><td class="num"><?=ef_number($r['tickets_hora'])?></td><td class="num"><?=ef_number($r['piezas_ticket'])?></td><td class="num"><?=ef_number($r['lineas_ticket'])?></td><td class="num"><?=number_format((int)$r['escaneos'])?></td><td class="num"><?=number_format((int)$r['busquedas'])?></td><td class="num"><?=number_format((int)$r['catalogo'])?></td><td class="num"><?=number_format((int)$r['cambios'])?></td><td class="num"><?=number_format((int)$r['eliminados'])?></td><td class="num"><?=number_format((int)$r['intentos'])?></td><td class="num"><?=number_format((int)$r['errores'])?></td><td class="num"><?=number_format((int)$r['solicitudes'])?></td><td class="num">$<?=ef_number($r['ticket_medio'],2)?></td><td class="num"><?=number_format((int)$r['canceladas'])?></td><td class="num">$<?=ef_number($r['descuentos'],2)?></td></tr><?php endforeach;?><?php if(!$rows):?><tr><td colspan="26" class="empty">Todavía no hay ventas en el periodo. Realiza una venta de prueba después de instalar el cronómetro.</td></tr><?php endif;?></tbody></table></div></section>
<section class="card"><h2>Formas de pago registradas</h2><div class="scroll"><table><thead><tr><th>Forma de pago</th><th class="num">Ventas con ese medio</th><th class="num">Monto cobrado</th></tr></thead><tbody><?php foreach($methods as $mt):?><tr><td><?=ef_h($mt['metodo'])?></td><td class="num"><?=number_format((int)$mt['ventas'])?></td><td class="num">$<?=ef_number($mt['total'],2)?></td></tr><?php endforeach;?><?php if(!$methods):?><tr><td colspan="3" class="empty">Sin pagos.</td></tr><?php endif;?></tbody></table></div><p class="muted">Una venta mixta puede figurar en más de un medio. Estas cifras no consideran reembolsos bancarios.</p></section>
<section class="card"><h2>Detalle de operaciones recientes</h2><div class="scroll"><table><thead><tr><th>Folio</th><th>Fecha</th><th>Cajero</th><th>Sucursal</th><th class="num">Total</th><th class="num">Duración</th><th class="num">Captura</th><th class="num">Pago</th><th class="num">Proceso</th><th class="num">Pausas</th><th class="num">Gestión Almacén</th><th class="num">Líneas</th><th class="num">Piezas</th><th>Estado</th></tr></thead><tbody>
<?php foreach(array_slice($details,0,150) as $d):?><tr><td><strong><?=ef_h($d['folio'])?></strong></td><td><?=ef_h($d['pagada_en'])?></td><td><?=ef_h($d['cajero'])?></td><td><?=ef_h($d['sucursal'])?></td><td class="num">$<?=number_format((float)$d['total'],2)?></td><td class="num"><?=ef_time($d['total_seg'])?></td><td class="num"><?=ef_time($d['captura_seg'])?></td><td class="num"><?=ef_time($d['pago_seg'])?></td><td class="num"><?=ef_time($d['cobro_red_seg'])?></td><td class="num"><?=ef_time($d['total_seg']===null?null:((int)$d['inactivo_seg']+(int)$d['oculto_seg']))?></td><td class="num"><?=ef_time($d['gestion_almacen_seg'])?></td><td class="num"><?=$d['lineas']===null?'—':(int)$d['lineas']?></td><td class="num"><?=$d['piezas']===null?'—':(int)$d['piezas']?></td><td><?=ef_h($d['estado'])?></td></tr><?php endforeach;?><?php if(!$details):?><tr><td colspan="14" class="empty">No hay operaciones.</td></tr><?php endif;?></tbody></table></div></section>
<div class="foot">* Ritmo/h activa medida = tickets con medición divididos por la suma del tiempo activo de captura de esos tickets; <strong>no equivale a tickets por hora de turno completo</strong> y no debe emplearse como único indicador de desempeño. P90 = el 90% de los tickets medidos tienen una duración menor o igual al valor mostrado (aproximado). Cancelaciones, descuentos y forma de pago se muestran como contexto y no atribuyen automáticamente errores al cajero. Los tiempos dependen de la interacción del navegador y podrían faltar ante cortes de red, pestañas cerradas o ventas previas a la instalación. Busca mejoras de procesos y capacitación considerando complejidad, cliente, método de pago, afluencia y solicitudes a Almacén. <strong>Sin ranking automático ni decisiones disciplinarias.</strong></div>
<?php endif;?></main></body></html>
