<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/gyd_conciliacion_diaria.php';
requireLogin();
$user=currentUser()?:[];$rol=strtoupper(trim((string)($user['rol_original']??$user['rol']??'')));
if(!in_array($rol,['ADMIN','ADMINISTRADOR','DIRECCION'],true)){http_response_code(403);exit('Consulta exclusiva de Administración y Dirección.');}
$fecha=(string)($_GET['fecha']??date('Y-m-d'));if(!gdcd_day($fecha))$fecha=date('Y-m-d');
$sucursal=max(0,(int)($_GET['sucursal']??0));$detalle=max(0,(int)($_GET['turno']??0));
$lista=[];$turnos=[];$fallo='';$sucursales=[];$sum=['esperado'=>0,'contado'=>0,'retiros'=>0,'boveda'=>0,'reembolsos'=>0,'manuales'=>0,'diferencia'=>0,'n_revisar'=>0];
try {
 $db=(new Database())->connect();$dbName=(string)$db->query('SELECT DATABASE()')->fetchColumn();
 if($dbName!=='prueba')throw new DomainException('Esta versión está restringida a la base de pruebas "prueba".');
 foreach(['pos_turnos','pos_pagos','pos_ventas','pos_movimientos_caja','pos_cajas','pos_sucursales'] as $tab){if(!gdcd_table($db,$tab))throw new DomainException('Falta tabla '.$tab.'.');}
 $hasRetiros=gdcd_table($db,'gyd_retiros_boveda');$hasRev=gdcd_table($db,'pos_reversiones');
 $sucursales=gdcd_q($db,'SELECT id,nombre FROM pos_sucursales ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
 $valid=array_map(static fn($s)=>(int)$s['id'],$sucursales);if($sucursal && !in_array($sucursal,$valid,true))$sucursal=0;
 // Día de cierre, o día de apertura en turnos todavía abiertos.
 $sql="SELECT t.*,c.nombre caja,s.id sucursal_id,s.nombre sucursal,u.nombre cajero FROM pos_turnos t JOIN pos_cajas c ON c.id=t.caja_id JOIN pos_sucursales s ON s.id=c.sucursal_id LEFT JOIN usuarios u ON u.id=t.usuario_apertura_id WHERE ((t.estado='CERRADO' AND t.cerrado_en>=? AND t.cerrado_en<?) OR (t.estado='ABIERTO' AND t.abierto_en>=? AND t.abierto_en<?))";
 $next=(new DateTimeImmutable($fecha))->modify('+1 day')->format('Y-m-d');$params=[$fecha.' 00:00:00',$next.' 00:00:00',$fecha.' 00:00:00',$next.' 00:00:00'];
 if($sucursal){$sql.=' AND s.id=?';$params[]=$sucursal;}
 $sql.=' ORDER BY s.nombre,c.nombre,t.id DESC LIMIT 250';
 $lista=gdcd_q($db,$sql,$params)->fetchAll(PDO::FETCH_ASSOC);
 foreach($lista as $t){
    try {$x=gdcd_turno($db,$t,$hasRetiros,$hasRev);$turnos[]=$x;
       $a=$x['resultado'];$sum['esperado']+=$a['esperado'];$sum['retiros']+=$x['otros']['retiros_total'];$sum['boveda']+=$x['otros']['boveda'];$sum['reembolsos']+=$x['otros']['reembolsos'];$sum['manuales']+=$x['otros']['otros_retiros'];
       if($a['contado']!==null){$sum['contado']+=$a['contado'];$sum['diferencia']+=$a['diferencia'];}if($a['estado']==='REVISAR')$sum['n_revisar']++;
    }catch(Throwable $e){error_log('Conciliación turno '.$t['id'].': '.$e->getMessage());$t['error']='No se pudo conciliar este turno. Consulta los registros PHP.';$turnos[]=$t;$sum['n_revisar']++;}
 }
 if(($_GET['exportar']??'')==='csv'){
    header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="gyd_conciliacion_cajas_'.preg_replace('/[^0-9-]/','',$fecha).'.csv"');echo "\xEF\xBB\xBF";$fp=fopen('php://output','w');
    fputcsv($fp,['Turno','Sucursal','Caja','Cajero','Apertura','Cierre','Estado','Fondo inicial','Ventas efectivo','Ventas tarjeta','Ventas transferencia','Otros ingresos','Retiros bóveda comprobantes','Reembolsos movimiento','Otros retiros','Total retiros POS','Esperado final','Contado final','Diferencia','Observaciones']);
    foreach($turnos as $t){$a=$t['resultado']??null;$o=$t['otros']??null;$p=$t['pagos']??null;$cent=static fn(?int $n)=>$n===null?'':number_format($n/100,2,'.','');
      fputcsv($fp,[(int)$t['id'],gdcd_csv($t['sucursal']),gdcd_csv($t['caja']),gdcd_csv($t['cajero']??''),$t['abierto_en'],$t['cerrado_en'],$a['estado']??'ERROR',$cent(gdcd_cents($t['fondo_inicial'])),$cent($p['EFECTIVO']??null),$cent($p['TARJETA']??null),$cent($p['TRANSFERENCIA']??null),$cent($o['ingresos']??null),$cent($o['boveda']??null),$cent($o['reembolsos']??null),$cent($o['otros_retiros']??null),$cent($o['retiros_total']??null),$cent($a['esperado']??null),$cent($a['contado']??null),$cent($a['diferencia']??null),gdcd_csv(implode(' | ',$a['alertas']??[$t['error']??'']))]);
    }fclose($fp);exit;
 }
}catch(Throwable $e){$fallo=$e instanceof DomainException?$e->getMessage():'No se pudo consultar los cortes; revisa PHP/MySQL.';error_log('Conciliación diaria G&D: '.$e->getMessage());}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>ERP G&D | Conciliación diaria de cajas</title><style>
:root{font-family:system-ui,'Segoe UI',Arial,sans-serif;color:#173e65;background:#eff4fa}*{box-sizing:border-box}body{margin:0}.head{background:#173f6b;color:#fff;padding:18px 4vw}.head h1{margin:2px 0 5px;font-size:23px}.head a{color:#fff}.wrap{max-width:1400px;margin:auto;padding:17px}.panel{background:white;border:1px solid #d8e4f1;border-radius:12px;margin-bottom:13px;padding:17px}.filters{display:flex;flex-wrap:wrap;align-items:end;gap:12px}label{display:grid;gap:5px;font-weight:650;font-size:13px}select,input,button,.btn{padding:10px;border:1px solid #c0d2e7;border-radius:8px;background:white;font:inherit}button,.btn{background:#135ca5;color:#fff;text-decoration:none;border:0;cursor:pointer;font-size:13px}.btn.alt{background:#e8f2fc;color:#174c78}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px}.metric{background:#f3f7fc;border:1px solid #e0e9f3;border-radius:10px;padding:12px}.metric span{display:block;font-size:12px;color:#64788e}.metric strong{font-size:19px;white-space:nowrap}.scroll{overflow:auto}table{width:100%;border-collapse:collapse;min-width:950px}th,td{border-bottom:1px solid #e1e8f1;padding:9px;font-size:12px;text-align:left;vertical-align:top}th{background:#195b91;color:#fff}.num{text-align:right;font-variant-numeric:tabular-nums}.muted{font-size:12px;color:#667e94}.status{font-weight:750;padding:5px 8px;border-radius:14px;background:#e9eff6}.danger{color:#a21f32}.warn{background:#fff5df;padding:10px;border-radius:8px;margin:7px 0}.ok{color:#17643b}.detail{border-top:1px solid #e2eaf4;padding-top:12px;margin-top:12px}summary{cursor:pointer;font-weight:bold}a{color:#135ca5}h2{font-size:18px}h3{font-size:15px}@media(max-width:760px){.grid{grid-template-columns:repeat(2,minmax(0,1fr))}.wrap{padding:8px}}@media print{.head .nav,.filters,.print-hide{display:none}.panel{break-inside:avoid}body{background:white}}
</style></head><body><header class="head"><h1>G&D · Conciliación diaria de cajas</h1><p>Ventas, retiros, devoluciones y diferencias por turno · Consulta de solo lectura</p><nav class="nav"><a href="erp_control_cajas.php">Control de cajas</a> · <a href="erp_cortes_boveda.php">Cortes y retiros</a> · <a href="pos.php">POS</a></nav></header><main class="wrap">
<section class="panel"><form class="filters" method="get"><label>Fecha del cierre / turno abierto<input type="date" name="fecha" value="<?=gdcd_h($fecha)?>"></label><label>Sucursal<select name="sucursal"><option value="0">Todas</option><?php foreach($sucursales as $s):?><option value="<?=(int)$s['id']?>" <?=($sucursal===(int)$s['id']?'selected':'')?>><?=gdcd_h($s['nombre'])?></option><?php endforeach;?></select></label><button>Consultar</button><a class="btn alt" href="?<?=gdcd_h(http_build_query(['fecha'=>$fecha,'sucursal'=>$sucursal,'exportar'=>'csv']))?>">Exportar CSV</a><button type="button" onclick="window.print()">Imprimir / PDF</button></form><p class="muted">Turnos cerrados que finalizaron en la fecha indicada y turnos abiertos iniciados en esa fecha. Un turno nunca se parte por medianoche. Máximo 250 turnos por consulta.</p></section>
<?php if($fallo):?><div class="panel warn"><?=gdcd_h($fallo)?></div><?php else:?>
<section class="panel"><div class="grid"><div class="metric"><span>Turnos encontrados</span><strong><?=count($turnos)?></strong></div><div class="metric"><span>Retiros comprobantes bóveda</span><strong><?=gdcd_money($sum['boveda'])?></strong></div><div class="metric"><span>Otros retiros y reembolsos</span><strong><?=gdcd_money($sum['manuales']+$sum['reembolsos'])?></strong></div><div class="metric"><span>Turnos para revisar</span><strong><?=($sum['n_revisar'])?></strong></div></div><p class="muted">Los totales combinan turnos distintos: no representan el saldo de una bóveda. Los reembolsos de efectivo ya están incluidos en los movimientos RETIRO del POS; no se vuelven a restar.</p></section>
<?php foreach($turnos as $t):$r=$t['resultado']??null;$o=$t['otros']??[];$p=$t['pagos']??[];?><section class="panel"><h2><?=gdcd_h($t['sucursal'])?> · <?=gdcd_h($t['caja'])?> · Turno #<?=(int)$t['id']?> <span class="status"><?=gdcd_h($r['estado']??'ERROR')?></span></h2><p class="muted">Cajero: <?=gdcd_h($t['cajero']??'')?> · Abrió: <?=gdcd_h($t['abierto_en'])?> · Cerró: <?=gdcd_h($t['cerrado_en']??'Aún abierto')?></p>
<?php if(!$r):?><div class="warn"><?=gdcd_h($t['error']??'Error al revisar turno')?></div><?php else:?>
<div class="scroll"><table><thead><tr><th>Concepto</th><th class="num">Importe</th><th>Tratamiento</th></tr></thead><tbody>
<tr><td>Fondo inicial</td><td class="num"><?=gdcd_money(gdcd_cents($t['fondo_inicial']))?></td><td>Se conserva hasta el corte</td></tr>
<tr><td>Ventas en efectivo confirmadas</td><td class="num"><?=gdcd_money($p['EFECTIVO'])?></td><td>Se suman</td></tr>
<tr><td>Ventas tarjeta</td><td class="num"><?=gdcd_money($p['TARJETA'])?></td><td>No son efectivo</td></tr>
<tr><td>Ventas transferencia</td><td class="num"><?=gdcd_money($p['TRANSFERENCIA'])?></td><td>No son efectivo</td></tr>
<tr><td>Otros ingresos de efectivo</td><td class="num"><?=gdcd_money($o['ingresos'])?></td><td>Se suman</td></tr>
<tr><td>Retiros a bóveda con comprobante</td><td class="num"><?=gdcd_money($o['boveda'])?></td><td>Componente del total de retiros</td></tr>
<tr><td>Devoluciones en efectivo registradas como RETIRO</td><td class="num"><?=gdcd_money($o['reembolsos'])?></td><td>Componente del total de retiros (no es bóveda)</td></tr>
<tr><td>Otros retiros</td><td class="num"><?=gdcd_money($o['otros_retiros'])?></td><td>Componente del total de retiros</td></tr>
<tr><td><b>Total salidas RETIRO del POS</b></td><td class="num"><b><?=gdcd_money($o['retiros_total'])?></b></td><td>Se resta una sola vez</td></tr>
<tr><td><b>Esperado al cierre</b></td><td class="num"><b><?=gdcd_money($r['esperado'])?></b></td><td>Cálculo según libro POS</td></tr>
<tr><td>Contado</td><td class="num"><?=$r['contado']===null?'Pendiente':gdcd_money($r['contado'])?></td><td>Conteo registrado al cerrar</td></tr>
<tr><td><b>Diferencia</b></td><td class="num"><b><?=($r['diferencia']===null?'Pendiente':gdcd_money($r['diferencia']))?></b></td><td>No se ajusta automáticamente</td></tr>
</tbody></table></div>
<?php foreach($r['alertas'] as $warning):?><p class="warn">⚠ <?=gdcd_h($warning)?></p><?php endforeach;?>
<details class="detail" <?=($detalle===(int)$t['id']?'open':'')?>><summary>Ver comprobantes, movimientos y devoluciones</summary><h3>Movimientos de caja por folio, fecha y tipo</h3><div class="scroll"><table><thead><tr><th>Folio</th><th>Fecha/hora</th><th>Clasificación</th><th>Motivo</th><th class="num">Importe</th></tr></thead><tbody><?php foreach($t['movimientos'] as $m):?><tr><td><?=gdcd_h($m['folio']?:('#MOV-'.$m['id']))?></td><td><?=gdcd_h($m['fecha'])?></td><td><?=gdcd_h($m['clase'])?></td><td><?=gdcd_h($m['motivo'])?></td><td class="num"><?=gdcd_money($m['monto'])?></td></tr><?php endforeach;?><?php if(!$t['movimientos']):?><tr><td colspan="5">Sin movimientos.</td></tr><?php endif;?></tbody></table></div>
<h3>Cancelaciones y devoluciones documentadas</h3><div class="scroll"><table><thead><tr><th>Venta</th><th>Fecha</th><th>Tipo</th><th>Medio de reembolso</th><th class="num">Importe</th></tr></thead><tbody><?php foreach($t['reversiones'] as $v):?><tr><td><?=gdcd_h($v['folio'])?></td><td><?=gdcd_h($v['creado_en'])?></td><td><?=gdcd_h($v['tipo'])?></td><td><?=gdcd_h($v['medio_reembolso']??'NO DEFINIDO')?></td><td class="num"><?=gdcd_money(gdcd_cents($v['importe']))?></td></tr><?php endforeach;?><?php if(!$t['reversiones']):?><tr><td colspan="5">Sin cancelaciones/devoluciones vinculadas a este turno.</td></tr><?php endif;?></tbody></table></div>
<p class="muted">Los retiros impresos deben coincidir con el archivo físico de tickets de la cajera; los reembolsos deben respaldarse con su evidencia. Las diferencias no modifican ventas, movimientos ni inventario.</p></details>
<a class="btn alt print-hide" href="pos_corte_retiros.php?turno_id=<?=(int)$t['id']?>">Ver corte térmico</a>
<?php endif;?></section><?php endforeach;?>
<?php if(!$turnos):?><section class="panel">No se encontraron turnos para el periodo y sucursal seleccionados.</section><?php endif;endif;?></main></body></html>
