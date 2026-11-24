<?php
declare(strict_types=1);
/** Panel operativo de solo consulta: ni DML, ni cronometraje nuevo. */
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/gyd_rendimiento_cajeros.php';
requireLogin();
$user=currentUser()??[];
if(!grd_permitido($user)){http_response_code(403);exit('Acceso reservado a Administración y Dirección.');}
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);exit('Solo se permiten consultas.');}
function grd_param(string $key,string $default=''):string{
 $v=$_GET[$key]??$default;return is_string($v)?trim($v):$default;
}
$hoy=(new DateTimeImmutable('today'))->format('Y-m-d');
$desde=grd_param('desde',(new DateTimeImmutable('-6 days'))->format('Y-m-d'));
$hasta=grd_param('hasta',$hoy);
$sucursal=filter_var(grd_param('sucursal','0'),FILTER_VALIDATE_INT,['options'=>['min_range'=>0]])?:0;
$cajero=filter_var(grd_param('cajero','0'),FILTER_VALIDATE_INT,['options'=>['min_range'=>0]])?:0;
$exportar=grd_param('exportar');$reporte=null;$error='';
try {
 if($exportar!=='' && $exportar!=='csv')throw new DomainException('Formato de exportación inválido.');
 $db=(new Database())->connect();
 if((string)$db->query('SELECT DATABASE()')->fetchColumn()!=='prueba')throw new DomainException('Por seguridad, este panel está habilitado únicamente en la base de pruebas «prueba».');
 $reporte=grd_reporte($db,$desde,$hasta,$sucursal,$cajero);
} catch(Throwable $e){
 $error=$e instanceof DomainException?$e->getMessage():'No se pudo consultar el rendimiento. Revisa la conexión y el registro de errores de PHP.';
 error_log('G&D rendimiento cajeros: '.$e->getMessage());
}
if($reporte!==null && $exportar==='csv'){
 header('Content-Type: text/csv; charset=UTF-8');
 header('Content-Disposition: attachment; filename="G_D_Rendimiento_Cajeros_'.$desde.'_a_'.$hasta.'.csv"');
 $fp=fopen('php://output','wb');
 fwrite($fp,"\xEF\xBB\xBF");
 fwrite($fp,"sep=;\r\n");
 $write=static function(array $r)use($fp):void{fputcsv($fp,array_map('grd_csv_text',$r),';','"','');};
 $write(['Farmacia G&D - Rendimiento de Cajeros']);
 $write(['Desde',$desde,'Hasta',$hasta]);
 $write(['Montos originales de tickets, no utilidad ni neto después de devoluciones. Los tiempos son aproximados y solo corresponden a tickets medidos.']);
 $write([]);
 $write(['Cajero','Sucursal','Tickets cobrados','Cancelados posteriormente','Ventas originales (MXN)','Ticket promedio (MXN)','Unidades según detalle','Líneas','Tickets medidos','Cobertura %','Promedio total (seg)','Promedio captura (seg)','Promedio pago (seg)','Promedio procesamiento (seg)','Promedio pausas (seg)','Errores en tickets medidos','Escaneos en tickets medidos','Búsquedas en tickets medidos','Solicitudes almacén en tickets medidos']);
 foreach($reporte['rows'] as $r){
  $write([$r['cajero'],$r['sucursal'],$r['tickets'],$r['canceladas'],number_format($r['importe_c']/100,2,'.',''),number_format($r['ticket_medio_c']/100,2,'.',''),$r['piezas'],$r['lineas'],$r['medidos'],number_format($r['cobertura'],1,'.',''),
    $r['prom_total'],$r['prom_captura'],$r['prom_pago'],$r['prom_proceso'],$r['prom_pausas'],$r['errores'],$r['escaneos'],$r['busquedas'],$r['almacen']]);
 }
 fclose($fp);exit;
}
$qs=['desde'=>$desde,'hasta'=>$hasta,'sucursal'=>$sucursal,'cajero'=>$cajero];
$t=$reporte['totales']??['tickets'=>0,'importe'=>0,'piezas'=>0,'medidos'=>0,'canceladas'=>0];
$cobertura=$t['tickets']>0?round(100*$t['medidos']/$t['tickets'],1):0;
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Farmacia G&amp;D | Rendimiento de Cajeros</title>
<style>
:root{font-family:system-ui,-apple-system,"Segoe UI",Arial,sans-serif;background:#f3f6fb;color:#172b49;font-synthesis:none}
*{box-sizing:border-box}body{margin:0}a{color:#1957a0}header{background:linear-gradient(120deg,#102e55,#1a5793);color:#fff;padding:27px max(18px,calc((100% - 1400px)/2)) 24px}
.head{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;flex-wrap:wrap}h1{font-size:clamp(23px,3vw,32px);margin:3px 0 7px;letter-spacing:-.5px}header p{margin:0;color:#d5e8fa;line-height:1.45;max-width:730px;font-size:14px}
nav{display:flex;gap:8px;flex-wrap:wrap}nav a{display:inline-block;border-radius:9px;text-decoration:none;color:white;border:1px solid #ffffff5e;padding:9px 12px;font-weight:650;font-size:13px}
main{max-width:1440px;margin:auto;padding:20px 20px 60px}.card{background:white;border:1px solid #dce5f1;border-radius:14px;margin:0 0 17px;padding:19px;box-shadow:0 3px 14px #1a46700b}
h2{font-size:18px;margin:0 0 10px}.sub{font-size:12px;color:#5c6d82;line-height:1.5;margin:3px 0 12px}.filters{display:flex;align-items:end;flex-wrap:wrap;gap:12px}label{display:grid;gap:6px;font-size:12px;font-weight:750;color:#345477}input,select,.btn,button{font:inherit;border-radius:8px;border:1px solid #bfcddd;padding:10px 12px;min-height:42px;background:#fff;color:#183653}
button,.btn{background:#175b9f;color:#fff;border:0;text-decoration:none;cursor:pointer;font-weight:750;display:inline-flex;align-items:center;justify-content:center;font-size:13px}.btn.ghost,button.ghost{background:#e8f0f8;color:#164274}.actions{display:flex;gap:9px;flex-wrap:wrap;align-items:center;justify-content:flex-end;margin-bottom:14px}
.stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin:0 0 17px}.stat{background:#fff;border:1px solid #dce5f1;border-radius:12px;padding:15px;min-width:0}.stat span{font-size:12px;color:#5f728d}.stat b{display:block;font-size:clamp(19px,2.1vw,27px);letter-spacing:-.4px;margin-top:6px;word-break:break-word}.stat small{font-size:11px;color:#6b7f98}
.notice{border:1px solid #e2ce9a;background:#fffbeb;color:#685024;border-radius:9px;padding:12px 14px;font-size:13px;line-height:1.5;margin-bottom:15px}.error{border:1px solid #e4adaf;background:#fff0f0;color:#842d33;padding:14px;border-radius:9px;margin-bottom:15px}.scroll{width:100%;overflow:auto}table{width:100%;border-collapse:collapse;font-size:13px;white-space:nowrap}th{text-align:left;font-size:12px;color:#49617d;background:#f2f6fb}td,th{padding:12px;border-bottom:1px solid #e7edf5}tr:hover td{background:#f8fbff}.n{text-align:right;font-variant-numeric:tabular-nums}.empty{text-align:center;padding:22px;color:#688098}.bar{height:9px;background:#dce9f5;min-width:90px;max-width:190px;border-radius:10px;overflow:hidden;margin-top:4px}.bar>span{display:block;background:#2773b8;height:100%;border-radius:10px}.pill{font-size:11px;background:#e5f2fb;color:#1b537f;border-radius:100px;padding:4px 8px;white-space:nowrap}.pill.miss{background:#f2f3f5;color:#5c6472}.muted{color:#64778f}.note{font-size:12px;color:#61758e;line-height:1.65}.card-head{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px}
@media(max-width:1040px){.stats{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:650px){main{padding:12px}.stats{grid-template-columns:repeat(2,minmax(0,1fr))}.card{padding:14px}header{padding:21px 15px}}
@media print{header{background:white;color:#111;padding:10px 0}header p{color:#333}nav,.filters,.actions,.noprint{display:none!important}main{padding:0}.stats{grid-template-columns:repeat(5,1fr)}.card,.stat{box-shadow:none;break-inside:avoid}.scroll{overflow:visible}table{font-size:10px;white-space:normal}th,td{padding:7px 5px}@page{size:A4 landscape;margin:9mm}}
</style></head><body>
<header><div class="head"><div><h1>Rendimiento de Cajeros</h1><p>Farmacia G&amp;D · Indicadores reales de ventas, artículos y medición de tiempos por cajero y sucursal.</p></div><nav><a href="dashboard.php">Inicio</a><a href="erp_eficiencia_cajeros.php">Detalle de eficiencia</a><a href="erp_eficiencia_diagnostico.php">Diagnóstico de tiempos</a></nav></div></header>
<main>
<section class="card noprint"><h2>Consultar resultados</h2><form class="filters" method="get">
<label>Desde<input type="date" name="desde" value="<?=grd_h($desde)?>" required></label>
<label>Hasta<input type="date" name="hasta" value="<?=grd_h($hasta)?>" required></label>
<label>Sucursal<select name="sucursal"><option value="0">Todas las sucursales</option><?php foreach(($reporte['sucursales']??[]) as $s):?><option value="<?=(int)$s['id']?>" <?=((int)$s['id']===$sucursal)?'selected':''?>><?=grd_h($s['nombre'])?></option><?php endforeach;?></select></label>
<label>Cajero<select name="cajero"><option value="0">Todos los cajeros</option><?php foreach(($reporte['cajeros']??[]) as $u):?><option value="<?=(int)$u['id']?>" <?=((int)$u['id']===$cajero)?'selected':''?>><?=grd_h($u['nombre'])?></option><?php endforeach;?></select></label>
<button type="submit">Consultar</button><a class="btn ghost" href="erp_rendimiento_cajeros.php">Limpiar filtros</a></form></section>
<?php if($error!==''):?><div class="error" role="alert"><?=grd_h($error)?></div><?php else:?>
<div class="actions noprint"><a class="btn" href="?<?=grd_h(http_build_query($qs+['exportar'=>'csv']))?>">Exportar a Excel (CSV)</a><button class="btn ghost" onclick="window.print()" type="button">Imprimir / Guardar PDF</button></div>
<?php foreach($reporte['avisos'] as $msg):?><div class="notice" role="status"><?=grd_h($msg)?></div><?php endforeach;?>
<section class="stats" aria-label="Indicadores del periodo">
<div class="stat"><span>Tickets cobrados</span><b><?=number_format($t['tickets'])?></b><small>Incluye tickets luego cancelados</small></div>
<div class="stat"><span>Ventas originales</span><b><?=grd_h(grd_dinero($t['importe']))?></b><small>No representa venta neta ni utilidad</small></div>
<div class="stat"><span>Unidades según detalle</span><b><?=number_format($t['piezas'])?></b><small><?=$reporte['piezas_exactas']?'Piezas de tickets originales':'Equivalencias no confirmadas'?></small></div>
<div class="stat"><span>Tickets con medición</span><b><?=number_format($t['medidos'])?></b><small>Sobre <?=number_format($t['tickets'])?> tickets</small></div>
<div class="stat"><span>Cobertura de medición</span><b><?=number_format($cobertura,1)?>%</b><small>No es una calificación del cajero</small></div>
</section>
<section class="card"><div class="card-head"><div><h2>Comparativo por cajero y sucursal</h2><p class="sub">Las operaciones se atribuyen al usuario que cobró la venta, incluso si hubo relevos de caja.</p></div><span class="pill">Periodo: <?=grd_h($desde)?> a <?=grd_h($hasta)?></span></div>
<div class="scroll"><table><thead><tr><th>Cajero</th><th>Sucursal</th><th class="n">Tickets</th><th class="n">Ventas originales</th><th class="n">Ticket promedio</th><th class="n">Unidades</th><th class="n">Medidos</th><th>Cobertura</th><th class="n">Tiempo prom.</th><th class="n">Captura prom.</th><th class="n">Pago prom.</th><th class="n">Procesamiento prom.</th><th class="n">Pausas prom.</th><th class="n">Errores registrados*</th><th class="n">Cancelados después</th></tr></thead><tbody>
<?php foreach($reporte['rows'] as $r):?><tr>
<td><strong><?=grd_h($r['cajero'])?></strong></td><td><?=grd_h($r['sucursal'])?></td>
<td class="n"><?=number_format($r['tickets'])?></td><td class="n"><?=grd_dinero($r['importe_c'])?></td><td class="n"><?=grd_dinero($r['ticket_medio_c']??0)?></td><td class="n"><?=number_format($r['piezas'])?></td><td class="n"><?=number_format($r['medidos'])?></td>
<td><?=number_format($r['cobertura'],1)?>%<div class="bar"><span style="width:<?=max(0,min(100,(int)round($r['cobertura'])))?>%"></span></div></td>
<td class="n"><?=grd_h(grd_tiempo($r['prom_total']))?></td><td class="n"><?=grd_h(grd_tiempo($r['prom_captura']))?></td><td class="n"><?=grd_h(grd_tiempo($r['prom_pago']))?></td><td class="n"><?=grd_h(grd_tiempo($r['prom_proceso']))?></td><td class="n"><?=grd_h(grd_tiempo($r['prom_pausas']))?></td><td class="n"><?=$r['errores']===null?'Sin medición':number_format($r['errores'])?></td><td class="n"><?=number_format($r['canceladas'])?></td></tr><?php endforeach;?>
<?php if(!$reporte['rows']):?><tr><td colspan="15" class="empty">No hay tickets cobrados para los filtros seleccionados.</td></tr><?php endif;?></tbody></table></div></section>
<section class="card"><h2>Volumen de tickets por cajero</h2><p class="sub">Comparativo del número de tickets en el periodo, no ranking ni calificación de desempeño.</p>
<?php foreach($reporte['rows'] as $r):?><div style="display:grid;grid-template-columns:minmax(150px,240px) 1fr 62px;align-items:center;gap:12px;margin:12px 0"><div style="font-size:13px"><strong><?=grd_h($r['cajero'])?></strong><div class="sub" style="margin:2px 0"><?=grd_h($r['sucursal'])?></div></div><div class="bar" style="max-width:none;width:100%"><span style="width:<?=max(0,min(100,(int)round(100*$r['tickets']/$reporte['max_tickets'])))?>%"></span></div><strong style="text-align:right"><?=number_format($r['tickets'])?></strong></div><?php endforeach;?>
<?php if(!$reporte['rows']):?><p class="muted">Sin datos para graficar.</p><?php endif;?></section>
<div class="note">* Errores registrados durante los tickets medidos: intentos fallidos de cobro en el sistema, no errores disciplinarios. Los tiempos provienen del cronómetro del navegador y son aproximados; no incluyen necesariamente esperas entre clientes ni todo el turno. La cobertura indica qué proporción de tickets tiene medición, no un porcentaje de eficiencia. Las ventas y unidades corresponden a los tickets originales y pueden incluir operaciones canceladas posteriormente; este panel no sustituye los reportes financieros ni la conciliación de devoluciones. No se modifican ventas, turnos, retiros o existencias.</div>
<?php endif;?></main>
<script src="assets/js/gyd_excel_reportes.js" defer></script>

<?php if (!defined('GYD_HIDE_GLOBAL_NAV')): ?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=1">
<script>window.GYD_NAV_HOME='index.php';window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=1"></script>
<?php endif; ?>
</body></html>
