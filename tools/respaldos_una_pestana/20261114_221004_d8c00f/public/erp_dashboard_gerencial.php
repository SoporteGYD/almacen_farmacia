<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_dashboard_gerencial.php';
requireLogin();
$u=currentUser()?:[];
$rol=strtoupper(trim((string)($u['rol_original']??$u['rol']??'')));
if(!in_array($rol,['ADMINISTRADOR','ADMIN','DIRECCION'],true)){http_response_code(403);exit('No tienes permiso para consultar información financiera del ERP.');}
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
function gd_h(mixed $x):string {return htmlspecialchars((string)$x,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function gd_money(mixed $n):string {return $n===null?'No disponible':'$'.number_format((float)$n,2,'.',',');}
$today=new DateTimeImmutable('today');
$defDesde=$today->modify('first day of this month')->format('Y-m-d');
$desde=trim((string)($_GET['desde']??$defDesde));$hasta=trim((string)($_GET['hasta']??$today->format('Y-m-d')));
$sucursal=filter_var($_GET['sucursal']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]])?:0;
$producto=filter_var($_GET['producto']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]])?:0;
$accion=(string)($_GET['exportar']??'');
$error='';$data=null;$productoElegido=null;$opcionesProductos=[];
try {
    if(!gd_dash_fecha($desde)||!gd_dash_fecha($hasta)||$desde>$hasta||$hasta>$today->format('Y-m-d')||(strtotime($hasta)-strtotime($desde)>366*86400)){
        throw new RuntimeException('Fechas inválidas: selecciona un periodo de hasta 367 días, sin fechas futuras.');
    }
    $db=(new Database())->connect();
    if($producto>0){
        $s=$db->prepare('SELECT id,codigo,descripcion FROM productos WHERE id=?');$s->execute([$producto]);$productoElegido=$s->fetch(PDO::FETCH_ASSOC);
        if(!$productoElegido)throw new RuntimeException('Producto seleccionado no existe.');
    }
    $q=mb_substr(trim((string)($_GET['buscar']??'')),0,80);
    if($q!==''){
        $s=$db->prepare('SELECT id,codigo,descripcion FROM productos WHERE codigo LIKE ? OR codigo_barras LIKE ? OR descripcion LIKE ? ORDER BY descripcion LIMIT 15');
        $s->execute(['%'.$q.'%','%'.$q.'%','%'.$q.'%']);$opcionesProductos=$s->fetchAll(PDO::FETCH_ASSOC);
    }
    $data=gd_dash_informe($db,$desde,$hasta,$sucursal,$producto);
} catch(Throwable $e){$error=$e instanceof RuntimeException?$e->getMessage():'No se pudo generar el reporte. Comprueba la conexión y revisa el log de PHP.';error_log('ERP Dashboard: '.$e->getMessage());}
$qs=['desde'=>$desde,'hasta'=>$hasta,'sucursal'=>$sucursal,'producto'=>$producto];
if($data && $accion==='csv'){
    $name='GYD_Dashboard_'.preg_replace('/[^0-9-]/','',$desde).'_'.preg_replace('/[^0-9-]/','',$hasta).'.csv';
    header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="'.$name.'"');
    echo "\xEF\xBB\xBF";
    $f=fopen('php://output','w');
    // Previene fórmulas maliciosas al abrir campos de texto en Excel.
    $line=static function($f,array $arr):void{$arr=array_map(static function($v){if(is_string($v)&&preg_match('/^[\s]*[=+@\-\t\r]/u',$v)){return "'".$v;}return $v;},$arr);fputcsv($f,$arr,';', '"','\\');};
    $line($f,['G&D - Dashboard Gerencial']);$line($f,['Periodo',$desde.' a '.$hasta]);$line($f,['Criterio','Operaciones registradas en POS G&D, no WinCaja']);
    $line($f,[]);$line($f,['INDICADORES']);$line($f,['Ventas brutas',$data['totales']['ventas']]);$line($f,['Devoluciones y cancelaciones',$data['totales']['devoluciones']]);$line($f,['Ventas netas',$data['totales']['neto']]);$line($f,['Costo neto (solo si cobertura completa)',$data['totales']['costo_neto']??'NO DISPONIBLE']);$line($f,['Utilidad bruta (solo si cobertura completa)',$data['totales']['utilidad']??'NO DISPONIBLE']);$line($f,['Cobertura de costos (%)',$data['totales']['cobertura']]);
    $line($f,[]);$line($f,['SUCURSALES']);$line($f,['Sucursal','Ventas brutas','Devoluciones','Ventas netas','Costo neto','Utilidad bruta','Margen %','Tickets','Piezas']);
    foreach($data['filas'] as $r)$line($f,[$r['nombre'],$r['ventas'],$r['devoluciones'],$r['neto'],$r['costo_neto']??'N/D',$r['utilidad']??'N/D',$r['margen']??'N/D',$r['tickets'],$r['piezas']]);
    $line($f,[]);$line($f,['TENDENCIA DIARIA']);$line($f,['Fecha','Ventas','Reversiones','Neto']);foreach($data['diario'] as $r)$line($f,[$r['dia'],$r['ventas'],$r['devoluciones'],$r['neto']]);
    $line($f,[]);$line($f,['PRODUCTOS MAS VENDIDOS (VALORES BRUTOS)']);$line($f,['Código','Descripción','Piezas','Ventas brutas']);foreach($data['top'] as $r)$line($f,[$r['codigo'],$r['descripcion'],$r['piezas'],$r['importe']]);
    $line($f,[]);$line($f,['COMPRAS REGISTRADAS POR FACTURA - NO SON COSTO DE VENTAS']);$line($f,['Proveedor','Facturas','Subtotal capturado']);foreach($data['compras'] as $r)$line($f,[$r['proveedor'],$r['facturas'],$r['subtotal_capturado']]);
    $line($f,[]);$line($f,['CAJEROS (VALORES BRUTOS)']);$line($f,['Cajero','Tickets','Ventas brutas']);foreach($data['cajeros'] as $r)$line($f,[$r['nombre']??'Sin nombre',$r['tickets'],$r['bruto']]);
    $line($f,[]);$line($f,['COBROS CONFIRMADOS EN PERIODO DE VENTA (BRUTOS)']);$line($f,['Método','Importe','Operaciones']);foreach($data['pagos'] as $r)$line($f,[$r['metodo'],$r['importe'],$r['operaciones']]);
    $line($f,[]);$line($f,['OBSERVACIONES']);foreach($data['avisos'] as $msg)$line($f,[$msg]);
    fclose($f);exit;
}
$tab=$data['totales']??[];
$maxVentas=$data?max(1,max(array_map(static fn($x)=>(float)$x['neto'],$data['filas']))):1;
$chartDays=$data?array_slice(array_values($data['diario']),-14):[];
$maxDay=$chartDays?max(1,max(array_map(static fn($r)=>max(0,(float)$r['neto']),$chartDays))):1;
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>G&D ERP | Dashboard Gerencial</title>
<style>
:root{font-family:system-ui,-apple-system,Segoe UI,Arial,sans-serif;color:#193654;background:#edf3f9}*{box-sizing:border-box}
body{margin:0}.top{background:#133b68;color:white;padding:24px max(18px,calc((100% - 1320px)/2)) 27px;border-bottom:4px solid #d52f4a}
.top h1{margin:0;font-size:clamp(23px,3vw,32px)}.top p{margin:7px 0 0;color:#cbdcf1}.toplinks{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}.toplinks a{color:#fff;text-decoration:none;background:#ffffff25;border:1px solid #ffffff35;padding:9px 14px;border-radius:9px;font-weight:650}
main{max-width:1360px;margin:22px auto;padding:0 20px 50px}.card{background:#fff;border:1px solid #dbe5f0;border-radius:16px;padding:21px;box-shadow:0 5px 20px #153d6410;margin-bottom:20px}
h2{font-size:19px;margin:0 0 15px}.muted{color:#63778b}.small{font-size:12px}.filters{display:flex;gap:14px;flex-wrap:wrap;align-items:end}.field{display:flex;flex-direction:column;gap:5px;flex:1;min-width:150px}.field label{font-size:12px;font-weight:700;color:#36546f}input,select{min-height:42px;border:1px solid #b7cadc;border-radius:9px;padding:9px 12px;font:inherit;background:#fff;max-width:100%}.actions{display:flex;gap:9px;flex-wrap:wrap;align-items:center}.btn{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;border:0;background:#1664b3;color:#fff;border-radius:9px;padding:11px 15px;font:650 14px system-ui;cursor:pointer}.btn.secondary{background:#ebf1f8;color:#244f76}.btn.ink{background:#205074}.metricGrid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:13px;margin:18px 0}.metric{background:#fff;border:1px solid #dae4ed;border-radius:14px;padding:19px;min-width:0}.metric span{display:block;font-size:12px;color:#5e758c;margin-bottom:10px}.metric strong{display:block;font-size:clamp(21px,2.3vw,29px);overflow-wrap:anywhere;line-height:1.2}.metric small{display:block;color:#61768c;margin-top:8px}.metric.highlight{border-top:3px solid #2475c2}.notice{border-left:4px solid #d59525;background:#fff8e9;padding:13px 15px;border-radius:9px;margin-bottom:11px;font-size:13px}.error{border-left:4px solid #d9364b;background:#fff0f2}.twocol{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:20px}.tablewrap{overflow-x:auto}table{border-collapse:collapse;width:100%;min-width:510px}th,td{text-align:left;border-bottom:1px solid #edf1f7;padding:11px 10px;font-size:13px}th{background:#eaf1fa;color:#264b71;white-space:nowrap}td.num,th.num{text-align:right;font-variant-numeric:tabular-nums}.barWrap{min-width:130px;background:#e9f0f8;border-radius:50px;height:12px;overflow:hidden}.bar{background:linear-gradient(90deg,#2663ab,#46a4c5);height:100%;border-radius:50px;min-width:0}.baritem{display:grid;grid-template-columns:minmax(95px,1fr) minmax(80px,2fr) minmax(95px,1fr);align-items:center;gap:10px;padding:11px 0;border-bottom:1px solid #edf1f7}.daybars{display:flex;align-items:end;gap:9px;overflow-x:auto;min-height:186px;border-bottom:1px solid #d7e4f0;padding-top:20px}.daybar{flex:1;min-width:38px;display:flex;flex-direction:column;align-items:center;justify-content:end;gap:6px;height:170px}.daybar .pillar{background:linear-gradient(0deg,#1b6ea8,#55b7c5);width:70%;border-radius:6px 6px 0 0;min-height:0}.daybar b{font-size:10px;color:#506982}.daybar span{font-size:10px;color:#557086}.tag{display:inline-block;background:#eaf2f9;color:#275579;padding:5px 9px;border-radius:20px;font-size:12px}.legend{font-size:12px;color:#5f758d;margin:8px 0 0}.footer{margin-top:14px;padding:12px;background:#f2f6fb;border-radius:10px;font-size:12px;color:#53697f} .results{margin-top:12px;display:grid;gap:6px}.results a{color:#145992}.empty{color:#657c91;padding:20px 0}a{color:inherit}
@media(max-width:1050px){.metricGrid{grid-template-columns:repeat(2,minmax(0,1fr))}.twocol{grid-template-columns:1fr}}@media(max-width:600px){main{padding:0 12px 30px}.top{padding:18px 14px 20px}.card{padding:15px}.metricGrid{gap:8px}.metric{padding:14px}.metric strong{font-size:20px}.baritem{grid-template-columns:minmax(80px,1fr) minmax(70px,1fr)}.baritem .val{grid-column:2;text-align:right}}
@media print{body{background:#fff;color:#111}.top{background:#fff;color:#111;border:0;padding:5px 15px}.top p{color:#444}.toplinks,.filters,.exportbuttons,.results,.noprint{display:none!important}main{margin:0;max-width:none;padding:0}.card,.metric{box-shadow:none;break-inside:avoid;border-color:#c5c5c5}.twocol{grid-template-columns:1fr 1fr}.metricGrid{grid-template-columns:repeat(4,1fr)}.bar,.pillar{-webkit-print-color-adjust:exact;print-color-adjust:exact}table{min-width:0}th,td{font-size:10px;padding:6px}h2{font-size:15px}@page{size:A4 landscape;margin:10mm}}
</style>
</head><body>
<header class="top"><h1>Dashboard Gerencial · Farmacias G&amp;D</h1><p>Ventas, devoluciones, costos documentados y comparativos de sucursales · <?=gd_h($desde)?> al <?=gd_h($hasta)?></p>
<div class="toplinks"><a href="dashboard.php">← Inicio</a><a href="erp_kardex_general.php">Kardex General</a><a href="erp_eficiencia_cajeros.php">Eficiencia de Cajeros</a><a href="erp_conciliacion_inventarios.php">Conciliación</a><a href="logout.php">Cerrar sesión</a></div></header>
<main>
<section class="card noprint"><h2>Filtros del reporte</h2>
<form method="get" class="filters">
 <div class="field"><label for="desde">Desde</label><input type="date" id="desde" name="desde" value="<?=gd_h($desde)?>" required></div>
 <div class="field"><label for="hasta">Hasta</label><input type="date" id="hasta" name="hasta" value="<?=gd_h($hasta)?>" required></div>
 <div class="field"><label for="sucursal">Sucursal</label><select name="sucursal" id="sucursal"><option value="0">Todas las sucursales</option><?php foreach($data['sucursales']??[] as $s):?><option value="<?=(int)$s['id']?>" <?=$sucursal===(int)$s['id']?'selected':''?>><?=gd_h($s['nombre'])?></option><?php endforeach;?></select></div>
 <div class="field"><label for="producto">Producto (ID)</label><input type="number" name="producto" id="producto" min="0" value="<?=$producto?:''?>" placeholder="Todos los productos"></div>
 <div class="actions"><button class="btn" type="submit">Consultar</button><a class="btn secondary" href="erp_dashboard_gerencial.php">Limpiar</a></div>
</form>
<?php if($productoElegido):?><p class="tag">Filtrando: <?=gd_h($productoElegido['codigo'].' · '.$productoElegido['descripcion'])?></p><?php endif;?>
<form method="get" style="margin-top:15px" class="filters"><input type="hidden" name="desde" value="<?=gd_h($desde)?>"><input type="hidden" name="hasta" value="<?=gd_h($hasta)?>"><input type="hidden" name="sucursal" value="<?=$sucursal?>"><div class="field"><label for="buscar">Buscar producto por nombre o código</label><input type="search" id="buscar" name="buscar" value="<?=gd_h($_GET['buscar']??'')?>" placeholder="Ej. NEUROBION o código de barras"></div><div class="actions"><button type="submit" class="btn secondary">Buscar producto</button></div></form>
<?php if($opcionesProductos):?><div class="results"><?php foreach($opcionesProductos as $p):?><a href="?<?=gd_h(http_build_query(array_replace($qs,['producto'=>(int)$p['id']])))?>" onclick="">#<?=(int)$p['id']?> · <?=gd_h($p['codigo'].' — '.$p['descripcion'])?></a><?php endforeach;?></div><?php endif;?>
</section>
<?php if($error):?><section class="notice error" role="alert"><?=gd_h($error)?></section><?php endif;?>
<?php if($data):?>
<section class="exportbuttons actions" style="margin:0 0 18px"><a class="btn" href="?<?=gd_h(http_build_query($qs+['exportar'=>'csv']))?>">Exportar Excel (CSV)</a><button class="btn ink" type="button" onclick="window.print()">Imprimir / Guardar PDF</button><span class="muted small">Importes en MXN · Datos reales registrados en ERP, no WinCaja</span></section>
<?php foreach($data['avisos'] as $adv):?><div class="notice" role="note"><?=gd_h($adv)?></div><?php endforeach;?>
<section class="metricGrid" aria-label="Indicadores principales">
 <div class="metric highlight"><span>Ventas brutas del periodo</span><strong><?=gd_money($tab['ventas'])?></strong><small><?=number_format((int)$tab['tickets'])?> tickets registrados</small></div>
 <div class="metric"><span>Devoluciones y cancelaciones aplicadas</span><strong><?=gd_money($tab['devoluciones'])?></strong><small><?=number_format((int)($tab['devoluciones_count']+$tab['cancelaciones']))?> operaciones</small></div>
 <div class="metric highlight"><span>Ventas netas</span><strong><?=gd_money($tab['neto'])?></strong><small>Brutas menos devoluciones aplicadas</small></div>
 <div class="metric"><span>Costo neto de ventas</span><strong><?=gd_money($tab['costo_neto'])?></strong><small>Cobertura documental de costos: <?=number_format((float)$tab['cobertura'],1)?>%</small></div>
 <div class="metric"><span>Utilidad bruta <?= $tab['utilidad']===null?'(sin datos suficientes)':'' ?></span><strong><?=gd_money($tab['utilidad'])?></strong><small>Antes de gastos y obligaciones fiscales</small></div>
 <div class="metric"><span>Margen bruto</span><strong><?=$tab['margen']===null?'No disponible':number_format((float)$tab['margen'],2).'%'?></strong><small>Solo con costo histórico completo</small></div>
 <div class="metric"><span>Piezas vendidas</span><strong><?=number_format((int)$tab['piezas'])?></strong><small>Antes de piezas devueltas</small></div>
 <div class="metric"><span>Cancelaciones / devoluciones</span><strong><?=number_format((int)$tab['cancelaciones'])?> / <?=number_format((int)$tab['devoluciones_count'])?></strong><small>Reversiones confirmadas</small></div>
</section>
<section class="twocol">
 <div class="card"><h2>Ventas netas por sucursal</h2><?php foreach($data['filas'] as $row):?><div class="baritem"><strong style="font-size:13px"><?=gd_h($row['nombre'])?></strong><div class="barWrap"><div class="bar" style="width:<?=max(0,min(100,round($row['neto']/$maxVentas*100,1)))?>%"></div></div><strong class="val" style="text-align:right;font-size:13px"><?=gd_money($row['neto'])?></strong></div><?php endforeach;?><p class="legend">Neto = ventas registradas − devoluciones/cancelaciones aplicadas en el periodo.</p></div>
 <div class="card"><h2>Tendencia neta de los últimos 14 días con movimientos</h2><?php if($chartDays):?><div class="daybars"><?php foreach($chartDays as $r):?><div class="daybar" title="<?=gd_h($r['dia'])?>: <?=gd_h(gd_money($r['neto']))?>"><b><?=gd_h(gd_money($r['neto']))?></b><div class="pillar" style="height:<?=max(0,min(120,round(max(0,$r['neto'])/$maxDay*120)))?>px"></div><span><?=gd_h(substr($r['dia'],5))?></span></div><?php endforeach;?></div><?php else:?><p class="empty">No hay movimientos para graficar.</p><?php endif;?><p class="legend">Fecha del cobro para ventas; fecha de aplicación para devoluciones.</p></div>
</section>
<section class="card"><h2>Comparativo por sucursal</h2><div class="tablewrap"><table><thead><tr><th>Sucursal</th><th class="num">Ventas brutas</th><th class="num">Devoluciones</th><th class="num">Ventas netas</th><th class="num">Costo neto</th><th class="num">Utilidad bruta</th><th class="num">Margen</th><th class="num">Tickets</th></tr></thead><tbody>
<?php foreach($data['filas'] as $r):?><tr><td><strong><?=gd_h($r['nombre'])?></strong></td><td class="num"><?=gd_money($r['ventas'])?></td><td class="num"><?=gd_money($r['devoluciones'])?></td><td class="num"><strong><?=gd_money($r['neto'])?></strong></td><td class="num"><?=gd_money($r['costo_neto'])?></td><td class="num"><?=gd_money($r['utilidad'])?></td><td class="num"><?=$r['margen']===null?'—':number_format((float)$r['margen'],2).'%'?></td><td class="num"><?=number_format((int)$r['tickets'])?></td></tr><?php endforeach;?>
</tbody></table></div></section>
<section class="twocol">
 <div class="card"><h2>15 productos más vendidos</h2><div class="tablewrap"><table><thead><tr><th>Producto</th><th class="num">Piezas</th><th class="num">Ventas brutas</th></tr></thead><tbody><?php foreach($data['top'] as $r):?><tr><td><?=gd_h($r['descripcion'])?><div class="small muted"><?=gd_h($r['codigo'])?></div></td><td class="num"><?=number_format((int)$r['piezas'])?></td><td class="num"><?=gd_money($r['importe'])?></td></tr><?php endforeach;?><?php if(!$data['top']):?><tr><td colspan="3">Sin ventas en el periodo</td></tr><?php endif;?></tbody></table></div><p class="legend">Ranking de venta bruta; no resta piezas devueltas.</p></div>
 <div class="card"><h2>Ventas registradas por cajero</h2><div class="tablewrap"><table><thead><tr><th>Cajero</th><th class="num">Tickets</th><th class="num">Bruto</th></tr></thead><tbody><?php foreach($data['cajeros'] as $r):?><tr><td><?=gd_h($r['nombre']??'Sin identificar')?></td><td class="num"><?=number_format((int)$r['tickets'])?></td><td class="num"><?=gd_money($r['bruto'])?></td></tr><?php endforeach;?><?php if(!$data['cajeros']):?><tr><td colspan="3">Sin ventas en el periodo</td></tr><?php endif;?></tbody></table></div><p class="legend">Las devoluciones se registran al autorizarlas y no se asignan retroactivamente al cajero.</p></div>
</section>
<section class="card"><h2>Compras registradas por proveedor</h2><div class="tablewrap"><table><thead><tr><th>Proveedor</th><th class="num">Facturas registradas</th><th class="num">Subtotal capturado</th></tr></thead><tbody><?php foreach($data['compras'] as $c):?><tr><td><?=gd_h($c['proveedor'])?></td><td class="num"><?=number_format((int)$c['facturas'])?></td><td class="num"><?=gd_money($c['subtotal_capturado'])?></td></tr><?php endforeach;?><?php if(!$data['compras']):?><tr><td colspan="3">No hay facturas registradas en el ERP durante el periodo o no se instaló el módulo de Compras.</td></tr><?php endif;?></tbody></table></div><p class="legend">Compras documentadas según las partidas registradas, sin integrar impuestos/descuentos fiscales. No se utilizan como costo de ventas.</p></section>
<section class="card"><h2>Medios de pago confirmados (brutos)</h2><?php if($producto>0):?><p class="muted">No se muestra el desglose de pagos al filtrar por producto: una venta puede incluir varios productos y varias formas de pago.</p><?php elseif(!$data['tienePagos']):?><p class="muted">La tabla pos_pagos no está disponible.</p><?php else:?><div class="tablewrap"><table><thead><tr><th>Método de pago</th><th class="num">Operaciones de pago</th><th class="num">Cobros confirmados</th></tr></thead><tbody><?php foreach($data['pagos'] as $p):?><tr><td><?=gd_h($p['metodo'])?></td><td class="num"><?=number_format((int)$p['operaciones'])?></td><td class="num"><?=gd_money($p['importe'])?></td></tr><?php endforeach;?><?php if(!$data['pagos']):?><tr><td colspan="3">No hay pagos registrados.</td></tr><?php endif;?></tbody></table></div><p class="legend">Cifras brutas por fecha de venta; no representan depósitos bancarios ni efectivo en caja. Los reembolsos se presentan separadamente.</p><?php endif;?></section>
<section class="card"><h2>Detalle diario</h2><div class="tablewrap"><table><thead><tr><th>Fecha</th><th class="num">Ventas brutas</th><th class="num">Devoluciones</th><th class="num">Ventas netas</th></tr></thead><tbody><?php foreach(array_reverse($data['diario']) as $r):?><tr><td><?=gd_h($r['dia'])?></td><td class="num"><?=gd_money($r['ventas'])?></td><td class="num"><?=gd_money($r['devoluciones'])?></td><td class="num"><?=gd_money($r['neto'])?></td></tr><?php endforeach;?><?php if(!$data['diario']):?><tr><td colspan="4">Sin movimientos.</td></tr><?php endif;?></tbody></table></div></section>
<div class="footer">Este reporte consulta registros de tu ERP G&D, no los de WinCaja. La utilidad usa exclusivamente instantáneas de costos al momento de vender, cuando existen, y requiere que el costo base esté expresado por pieza de POS. Las ventas anteriores al registro de costos se muestran con utilidad no disponible; no se inventan importes. Las devoluciones/cancelaciones se asignan a la fecha de su registro y las ventas a su fecha de pago. Es un informe de utilidad bruta operativa, no una declaración contable o fiscal.</div>
<?php endif;?></main>
<script src="assets/js/gyd_excel_reportes.js" defer></script>

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
