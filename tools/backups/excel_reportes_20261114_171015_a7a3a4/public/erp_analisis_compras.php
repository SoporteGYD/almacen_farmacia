<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_analisis_compras.php';
requireLogin();
$user=currentUser()?:[];
if(!ac_autorizado($user) || (int)($user['id']??0)<1){http_response_code(403);exit('Acceso restringido a Compras, Administración y Dirección.');}
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
$f=[];$error='';$proveedores=[];$d=[];$pagina=[];$pages=1;$totalVista=0;$enfoque=[];
$export=is_scalar($_GET['export']??null)?(string)($_GET['export']??''):'';
try {
 $f=ac_filtros($_GET);
 if($export!=='' && !in_array($export,['productos','proveedores','alertas','movimientos'],true))throw new DomainException('Tipo de exportación no válido.');
 $db=(new Database())->connect();
 $needed=$f['fuente']==='FACTURAS'?['erp_facturas','erp_facturas_detalle']:['erp_ordenes_compra','erp_ordenes_compra_detalle'];
 foreach(array_merge(['productos','proveedores'],$needed) as $table){if(!ac_tabla($db,$table))throw new DomainException('Falta la tabla '.$table.'. Revisa el módulo de Compras y Facturas antes de abrir este reporte.');}
 $proveedores=$db->query('SELECT id,nombre FROM proveedores ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
 [$sql,$params]=ac_sql($f);$st=$db->prepare($sql);
 foreach($params as $k=>$v)$st->bindValue(':'.$k,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
 $st->execute();$d=ac_resumir($st,(float)$f['umbral']);
 if($f['producto']>0){
  foreach($d['pares'] as $p)if((int)$p['producto_id']===$f['producto'])$enfoque[]=$p;
  usort($enfoque,static fn($a,$b)=>$a['ultimo_costo']<=>$b['ultimo_costo']);
 }
 $rows=match($export?:$f['vista']){
  'productos','PRODUCTOS'=>$d['productos'],
  'proveedores','PROVEEDORES'=>array_values($d['proveedores']),
  'alertas','ALERTAS'=>$d['alertas'],
  'movimientos'=>$d['movimientos'],
  default=>[]
 };
 if($export){
  if(count($rows)>10000)throw new DomainException('Más de 10,000 renglones: reduce filtros antes de exportar.');
  $headers=match($export){
    'productos'=>['Código','Artículo','Proveedores','Proveedor con menor último costo','Menor último costo','Mayor último costo','Diferencia $','Diferencia %','Último movimiento'],
    'proveedores'=>['Proveedor','Documentos','Productos','Piezas registradas','Importe bruto estimado','Último movimiento'],
    'alertas'=>['Código','Artículo','Proveedor','Costo anterior','Último costo','Variación %','Fecha último movimiento','Folio último'],
    'movimientos'=>['Fecha','Folio','Referencia','Proveedor','Sucursal','Código','Artículo','Cantidad en piezas','Costo unitario','Importe bruto','Estado']
  };
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="G_y_D_analisis_'. $export.'_'.date('Ymd_His').'.csv"');
  $o=fopen('php://output','wb');fwrite($o,"\xEF\xBB\xBF");fputcsv($o,$headers,',','"','');
  foreach($rows as $r){
   $c=match($export){
    'productos'=>[$r['codigo'],$r['descripcion'],$r['proveedores'],$r['mejor_proveedor'],number_format($r['menor'],4,'.',''),number_format($r['mayor'],4,'.',''),number_format($r['diferencia'],4,'.',''),number_format($r['brecha_pct'],2,'.',''),$r['fecha_ultima']],
    'proveedores'=>[$r['nombre'],$r['documentos_count'],$r['productos_count'],$r['cantidad'],number_format($r['valor'],2,'.',''),$r['ultima_fecha']],
    'alertas'=>[$r['codigo'],$r['descripcion'],$r['proveedor'],number_format($r['costo_previo'],4,'.',''),number_format($r['ultimo_costo'],4,'.',''),number_format($r['variacion'],2,'.',''),$r['fecha_ultima'],$r['folio_ultimo']],
    'movimientos'=>[$r['fecha'],$r['folio'],$r['referencia'],$r['proveedor'],$r['sucursal'],$r['codigo'],$r['descripcion'],$r['cantidad'],number_format($r['costo'],4,'.',''),number_format($r['cantidad']*$r['costo'],4,'.',''),$r['estado']]
   };
   fputcsv($o,array_map('ac_csv',$c),',','"','');
  }
  fclose($o);exit;
 }
 $totalVista=count($rows);$perPage=30;$pages=max(1,(int)ceil($totalVista/$perPage));$f['pagina']=min($f['pagina'],$pages);
 $pagina=array_slice($rows,($f['pagina']-1)*$perPage,$perPage);
}catch(Throwable $e){error_log('ERP G&D análisis de compras: '.$e->getMessage());$error=$e instanceof DomainException?$e->getMessage():'No se pudo generar el análisis. Consulta el registro de Apache y revisa la conexión a MySQL.';if($export){http_response_code(422);header('Content-Type: text/plain; charset=UTF-8');exit($error);}}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Análisis de compras y proveedores · ERP G&amp;D</title>
<style>
:root{font:14px system-ui,-apple-system,Segoe UI,Arial,sans-serif;color:#17364f;background:#eef3f8}*{box-sizing:border-box}body{margin:0}.head{background:linear-gradient(115deg,#113c62,#1b587b);color:#fff;border-bottom:3px solid #bf3852}.head-inner{max-width:1380px;margin:auto;padding:23px 20px;display:flex;justify-content:space-between;align-items:center;gap:20px}.head h1{font-size:23px;margin:0 0 6px}.head p{margin:0;color:#d2e4f3}.nav{display:flex;gap:9px;flex-wrap:wrap}.btn,button{cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:6px;border:0;border-radius:9px;background:#165c97;color:#fff;padding:10px 14px;text-decoration:none;font:650 13px system-ui;white-space:nowrap}.btn.ghost{background:#e9f2fa;color:#1b527b}.head .btn{background:#2a6086;border:1px solid #8fb4d1}main{max-width:1380px;margin:auto;padding:20px}.hint{background:#eff7ff;border-left:4px solid #2a7cc0;padding:12px 15px;border-radius:8px;margin-bottom:16px;color:#244e6c}.hint.warning{background:#fff4e0;border-color:#d8a050;color:#775320}.hint.error{background:#ffe9eb;border-color:#cc5360;color:#872d3b}.card{background:white;border:1px solid #dce6ef;border-radius:14px;padding:20px;margin-bottom:17px;box-shadow:0 5px 18px #173e6010}.card h2{font-size:18px;margin:0 0 13px}.grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:13px}.field label{display:block;font-size:12px;font-weight:700;margin-bottom:5px;color:#365c77}.field input,.field select{width:100%;padding:10px;border:1px solid #bfcfdc;border-radius:8px;background:#fff;color:#17364f;min-width:0;font:inherit}.field.wide{grid-column:span 2}.filter-actions{display:flex;gap:9px;justify-content:flex-end;flex-wrap:wrap;margin-top:15px}.metrics{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-bottom:18px}.metric{background:white;border-radius:13px;border:1px solid #dce6ef;padding:16px}.metric .label{font-size:12px;color:#637e92}.metric strong{display:block;font-size:24px;margin-top:7px;color:#15486e}.toolbar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 14px}.tab{padding:10px 14px;background:#eaf1f8;color:#205576;border-radius:9px;font-weight:700;text-decoration:none}.tab.active{background:#1d5a86;color:white}.toolbar .spacer{flex:1}.tablewrap{overflow-x:auto}table{width:100%;border-collapse:collapse;min-width:950px}th{background:#1e527b;color:white;text-align:left;font-size:12px;padding:12px 11px;white-space:nowrap}td{border-bottom:1px solid #e5edf4;padding:11px;font-size:12px;vertical-align:top}tbody tr:hover{background:#f6faff}.money,.num{font-variant-numeric:tabular-nums;white-space:nowrap;text-align:right}.muted{font-size:12px;color:#677e92}.strong{font-weight:750}.pill{display:inline-block;font-size:11px;border-radius:30px;padding:5px 9px;font-weight:750;background:#e7f1fb;color:#225780}.pill.good{background:#ddf4e8;color:#126140}.pill.risk{background:#ffebdd;color:#934622}.progress{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-top:14px}.progress .pages{display:flex;gap:8px;align-items:center}.empty{text-align:center;padding:28px;color:#687f91}.plus{color:#a44323}.explain{background:#f4f8fc;padding:14px;border-radius:10px;color:#425c70;font-size:12px;line-height:1.6}.alt{display:flex;gap:12px;flex-wrap:wrap}.alt .btn{background:#edf4fa;color:#215a81}.subhead{display:flex;align-items:center;justify-content:space-between;gap:15px;flex-wrap:wrap}.subhead h2{margin:0}.tag{font-size:12px;background:#e9f1f9;padding:7px 10px;border-radius:9px}.nowrap{white-space:nowrap}a{color:#125f95}a:focus-visible,input:focus-visible,select:focus-visible,button:focus-visible{outline:3px solid #82b9e2;outline-offset:2px}
@media(max-width:1050px){.grid{grid-template-columns:repeat(3,minmax(0,1fr))}.metrics{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:720px){main{padding:12px}.head-inner{display:block}.head h1{font-size:19px}.nav{margin-top:14px}.grid{grid-template-columns:repeat(2,minmax(0,1fr))}.field.wide{grid-column:span 2}.metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.card{padding:14px}.toolbar .spacer{display:none}.progress{display:block}.progress .pages{margin-top:10px}}@media(max-width:450px){.grid{grid-template-columns:1fr}.field.wide{grid-column:auto}.metric strong{font-size:19px}}
@media print{body{background:white}.head{color:#111;background:white;border-bottom:1px solid #ddd}.head p{color:#555}.nav,.noprint,.filter-actions,.toolbar,.progress{display:none!important}main{padding:0;max-width:none}.card,.metric{box-shadow:none;break-inside:avoid}.filters,.tablewrap{overflow:visible}table{min-width:0}td,th{padding:6px;font-size:10px;overflow-wrap:anywhere}th{color:#111;background:#ddd}.grid{display:none}.metrics{grid-template-columns:repeat(5,1fr)}}
</style></head><body>
<?php $barra=__DIR__.'/../app/views/layouts/erp_barra_sesion.php';if(is_file($barra))require $barra; ?>
<header class="head"><div class="head-inner"><div><h1>ERP G&amp;D · Análisis de Compras y Proveedores</h1><p>Costos por producto, comparativos entre proveedores y alertas de aumento · Solo lectura</p></div><div class="nav"><a class="btn" href="erp_oc_win.php">Órdenes de Compra</a><a class="btn" href="erp_facturas.php">Entradas por factura</a><a class="btn" href="erp_historial_precios.php">Historial de Precios</a></div></div></header>
<main>
<?php if($error):?><div class="hint error" role="alert"><strong>No se pudo completar la consulta.</strong> <?=ac_e($error)?></div><?php else:?>
<div class="hint"><strong>Origen: <?= $f['fuente']==='FACTURAS'?'Facturas capturadas':'Órdenes emitidas'?>.</strong> En facturas se usa el costo registrado en el documento, no el costo final aceptado ni el importe con impuestos. En órdenes son costos pactados/estimados. <strong>No se mezclan ambas fuentes</strong>, no se cuentan dos veces y no se realizan cambios en precios o existencias.</div>
<section class="card"><h2>Filtros del análisis</h2><form method="get" class="grid filters">
<div class="field"><label for="desde">Desde</label><input type="date" id="desde" name="desde" value="<?=ac_e($f['desde'])?>" required></div>
<div class="field"><label for="hasta">Hasta</label><input type="date" id="hasta" name="hasta" value="<?=ac_e($f['hasta'])?>" required></div>
<div class="field"><label for="fuente">Fuente de datos</label><select id="fuente" name="fuente"><option value="FACTURAS" <?=$f['fuente']==='FACTURAS'?'selected':''?>>Facturas (registrado)</option><option value="ORDENES" <?=$f['fuente']==='ORDENES'?'selected':''?>>Órdenes (pactado)</option></select></div>
<div class="field"><label for="sucursal">Sucursal destino</label><select id="sucursal" name="sucursal"><option value="TODAS">Todas</option><?php foreach(['TAPACHULA'=>'Tapachula','TOSCANA'=>'Plaza Toscana','HIDALGO'=>'Ciudad Hidalgo','TUXTLA'=>'Tuxtla Gutiérrez'] as $k=>$v):?><option value="<?=ac_e($k)?>" <?=$f['sucursal']===$k?'selected':''?>><?=ac_e($v)?></option><?php endforeach;?></select></div>
<div class="field"><label for="proveedor">Proveedor</label><select id="proveedor" name="proveedor"><option value="0">Todos los proveedores</option><?php foreach($proveedores as $pr):?><option value="<?=(int)$pr['id']?>" <?=(int)$pr['id']===$f['proveedor']?'selected':''?>><?=ac_e($pr['nombre'])?></option><?php endforeach;?></select></div>
<div class="field wide"><label for="q">Producto / código de barras / descripción</label><input id="q" name="q" maxlength="90" placeholder="Ej. PROXALIN o 7501825" value="<?=ac_e($f['q'])?>"></div>
<div class="field"><label for="estado">Estado de las facturas</label><select id="estado" name="estado"><option value="TODAS" <?=$f['estado']==='TODAS'?'selected':''?>>Todas las capturadas</option><option value="VERIFICADA" <?=$f['estado']==='VERIFICADA'?'selected':''?>>Solo verificadas</option></select></div>
<div class="field"><label for="umbral">Avisar aumentos desde (%)</label><input id="umbral" name="umbral" inputmode="decimal" pattern="[0-9]{1,2}(\.[0-9])?" value="<?=ac_e($f['umbral'])?>" title="Entre 0 y 99"></div>
<input type="hidden" name="vista" value="<?=ac_e($f['vista'])?>"><div class="field" style="align-self:end"><button type="submit">Aplicar filtros</button></div>
</form><div class="filter-actions noprint"><a href="erp_analisis_compras.php" class="btn ghost">Restablecer</a><button class="btn ghost" type="button" onclick="window.print()">Imprimir / Guardar PDF</button></div></section>
<section class="metrics">
<div class="metric"><div class="label">Documentos</div><strong><?=number_format($d['kpi']['documentos'])?></strong></div>
<div class="metric"><div class="label">Proveedores</div><strong><?=number_format($d['kpi']['proveedores'])?></strong></div>
<div class="metric"><div class="label">Productos distintos</div><strong><?=number_format($d['kpi']['productos'])?></strong></div>
<div class="metric"><div class="label">Piezas registradas</div><strong><?=number_format($d['kpi']['piezas'])?></strong></div>
<div class="metric"><div class="label">Importe bruto (sin descuentos/impuestos)</div><strong><?=ac_e(ac_money($d['kpi']['importe']))?></strong></div>
</section>
<div class="hint warning"><strong>Comparación histórica:</strong> el menor costo indica el precio más bajo de la <em>última compra registrada por cada proveedor en el periodo</em>. No garantiza que el proveedor conserve ese precio hoy. Las cantidades y totales son documentales; no equivalen a existencias, entradas aceptadas ni ahorro obtenido.</div>
<section class="card">
<div class="toolbar noprint">
<?php foreach(['PRODUCTOS'=>'Comparar productos','PROVEEDORES'=>'Resumen por proveedor','ALERTAS'=>'Alertas de aumentos'] as $key=>$title):?><a class="tab <?=$f['vista']===$key?'active':''?>" href="<?=ac_e(ac_query($f,['vista'=>$key,'pagina'=>1,'producto'=>0]))?>"><?=ac_e($title)?></a><?php endforeach;?><span class="spacer"></span>
<a class="btn ghost" href="<?=ac_e(ac_query($f,['export'=>strtolower($f['vista'])]))?>">Descargar CSV (Excel)</a>
</div>
<?php if($f['vista']==='PRODUCTOS'):?>
<div class="subhead"><h2>Comparación de costos por producto</h2><span class="tag"><?=number_format($d['kpi']['comparables'])?> productos tienen más de un proveedor registrado</span></div>
<div class="tablewrap"><table><thead><tr><th>Código / producto</th><th class="num">Proveedores</th><th>Proveedor con menor costo</th><th class="num">Menor costo</th><th class="num">Mayor costo</th><th class="num">Diferencia</th><th>Último registro</th><th>Detalle</th></tr></thead><tbody>
<?php foreach($pagina as $r):?><tr><td><strong><?=ac_e($r['descripcion'])?></strong><div class="muted"><?=ac_e($r['codigo'])?></div></td><td class="num"><span class="pill <?=$r['proveedores']>1?'good':''?>"><?=(int)$r['proveedores']?></span></td><td><?=ac_e($r['mejor_proveedor'])?></td><td class="money"><?=ac_e(ac_money($r['menor'],4))?></td><td class="money"><?=ac_e(ac_money($r['mayor'],4))?></td><td class="money"><?php if($r['proveedores']>1):?><?=ac_e(ac_money($r['diferencia'],4))?><div class="muted"><?=number_format($r['brecha_pct'],1)?> %</div><?php else:?>—<?php endif;?></td><td class="nowrap"><?=ac_e($r['fecha_ultima'])?></td><td><a href="<?=ac_e(ac_query($f,['producto'=>$r['id'],'pagina'=>1]))?>#detalle">Ver proveedores</a></td></tr><?php endforeach;?>
<?php if(!$pagina):?><tr><td colspan="8" class="empty">No hay productos en el periodo elegido.</td></tr><?php endif;?></tbody></table></div>
<?php elseif($f['vista']==='PROVEEDORES'):?>
<h2>Concentrado por proveedor</h2><div class="tablewrap"><table><thead><tr><th>Proveedor</th><th class="num">Documentos</th><th class="num">Productos distintos</th><th class="num">Piezas</th><th class="num">Importe bruto documental</th><th>Último movimiento</th></tr></thead><tbody>
<?php foreach($pagina as $r):?><tr><td><strong><?=ac_e($r['nombre'])?></strong></td><td class="num"><?=number_format($r['documentos_count'])?></td><td class="num"><?=number_format($r['productos_count'])?></td><td class="num"><?=number_format($r['cantidad'])?></td><td class="money"><?=ac_e(ac_money($r['valor']))?></td><td><?=ac_e($r['ultima_fecha'])?></td></tr><?php endforeach;?>
<?php if(!$pagina):?><tr><td colspan="6" class="empty">No existen documentos de proveedores en el periodo.</td></tr><?php endif;?></tbody></table></div>
<?php else:?>
<h2>Alertas de aumento de costo (≥ <?=number_format($f['umbral'],1)?> %)</h2><p class="muted">Compara los dos registros más recientes del mismo producto y proveedor. No compara proveedores diferentes.</p>
<div class="tablewrap"><table><thead><tr><th>Producto</th><th>Proveedor</th><th class="num">Costo anterior</th><th class="num">Último costo</th><th class="num">Variación</th><th>Último registro</th><th>Documento</th></tr></thead><tbody>
<?php foreach($pagina as $r):?><tr><td><strong><?=ac_e($r['descripcion'])?></strong><div class="muted"><?=ac_e($r['codigo'])?></div></td><td><?=ac_e($r['proveedor'])?></td><td class="money"><?=ac_e(ac_money($r['costo_previo'],4))?></td><td class="money"><?=ac_e(ac_money($r['ultimo_costo'],4))?></td><td class="num plus"><span class="pill risk">+<?=number_format($r['variacion'],2)?>%</span></td><td><?=ac_e($r['fecha_ultima'])?></td><td><?=ac_e($r['folio_ultimo'])?></td></tr><?php endforeach;?>
<?php if(!$pagina):?><tr><td colspan="7" class="empty">No hay aumentos superiores al umbral dentro del periodo.</td></tr><?php endif;?></tbody></table></div>
<?php endif;?>
<div class="progress noprint"><span class="muted">Mostrando <?=count($pagina)?> de <?=number_format($totalVista)?> registros · Página <?=$f['pagina']?> de <?=$pages?></span><div class="pages"><?php if($f['pagina']>1):?><a class="btn ghost" href="<?=ac_e(ac_query($f,['pagina'=>$f['pagina']-1]))?>">← Anterior</a><?php endif;?><?php if($f['pagina']<$pages):?><a class="btn ghost" href="<?=ac_e(ac_query($f,['pagina'=>$f['pagina']+1]))?>">Siguiente →</a><?php endif;?></div></div>
</section>
<?php if($f['producto']>0):?>
<section class="card" id="detalle"><div class="subhead"><h2>Detalle comparativo del artículo #<?=$f['producto']?></h2><a class="btn ghost noprint" href="<?=ac_e(ac_query($f,['producto'=>0]))?>">Cerrar detalle</a></div>
<?php if($enfoque):?><div class="tablewrap"><table><thead><tr><th>Proveedor</th><th class="num">Último costo</th><th class="num">Costo previo</th><th class="num">Costo promedio ponderado</th><th class="num">Costo mínimo</th><th class="num">Costo máximo</th><th class="num">Piezas</th><th class="num">Documentos</th><th>Última fecha</th></tr></thead><tbody>
<?php foreach($enfoque as $r):?><tr><td><?=ac_e($r['proveedor'])?></td><td class="money"><?=ac_e(ac_money($r['ultimo_costo'],4))?></td><td class="money"><?=$r['costo_previo']!==null?ac_e(ac_money($r['costo_previo'],4)):'—'?></td><td class="money"><?=ac_e(ac_money($r['promedio'],4))?></td><td class="money"><?=ac_e(ac_money($r['min'],4))?></td><td class="money"><?=ac_e(ac_money($r['max'],4))?></td><td class="num"><?=number_format($r['cantidad'])?></td><td class="num"><?=number_format($r['documentos_count'])?></td><td><?=ac_e($r['fecha_ultima'])?></td></tr><?php endforeach;?></tbody></table></div>
<?php else:?><p class="empty">El artículo no tiene compras para los filtros seleccionados.</p><?php endif;?></section>
<?php endif;?>
<section class="card"><div class="subhead"><h2>Exportaciones adicionales</h2><div class="alt noprint"><a class="btn" href="<?=ac_e(ac_query($f,['export'=>'movimientos']))?>">Exportar detalle documental CSV</a><a class="btn" href="<?=ac_e(ac_query($f,['export'=>'proveedores']))?>">Exportar proveedores CSV</a></div></div><p class="explain">Los reportes provienen de la BD del ERP (MySQL), no del SQL Server de WinCaja. Las órdenes son documentos de pedido, las facturas son documentos de compra, y los registros de recepción no se cuentan de nuevo. Los costos por pieza se muestran sin descuentos ni impuestos. Este módulo no actualiza catálogo, existencias, facturas ni precios. Para reportes de costos netos después de bonificaciones e impuestos se requerirá incorporar las reglas fiscales y descuentos documentales.</p></section>
<?php endif;?></main></body></html>
