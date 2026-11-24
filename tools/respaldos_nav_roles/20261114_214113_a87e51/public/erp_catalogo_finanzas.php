<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_catalogo_finanzas.php';
require_once __DIR__.'/../app/helpers/pos_inteligente.php';
requireLogin();
$usr=currentUser()??[];
if(!gd_fc_admin((string)($usr['rol']??''))){http_response_code(403);exit('El análisis de costos y rentabilidad está disponible para Administración y Compras.');}
$db=(new Database())->connect();
$hasFicha=gd_fc_tabla($db,'erp_producto_ficha');
$hasSucursal=gd_fc_tabla($db,'pos_sucursales');
$hasPrecios=$hasSucursal&&gd_fc_tabla($db,'pos_precios');
$hasMayor=$hasSucursal&&gd_fc_tabla($db,'pos_precios_mayoreo');
$hasHist=$hasSucursal&&gd_fc_tabla($db,'pos_precios_historial');
$hasStock=$hasSucursal&&gd_fc_tabla($db,'pos_existencias_farmacia');
$sucursales=[];
if($hasSucursal){$sucursales=$db->query('SELECT id,nombre FROM pos_sucursales ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);}
$sucId=max(0,(int)($_GET['sucursal']??0));
if(!in_array($sucId,array_map(static fn($r)=>(int)$r['id'],$sucursales),true))$sucId=0;
// Cada sucursal conserva precio y existencias propios; nunca usar stock de otra sucursal.
$stockSid=$sucId>0?pi_tt_stock_sid($db,$sucId):0;
$comparteStock=in_array($sucId,[3,4],true) && $stockSid===3 && pi_tt_activo($db);
$nombreSucursal='Precio base del catálogo';
foreach($sucursales as $s){if((int)$s['id']===$sucId)$nombreSucursal=(string)$s['nombre'];}
$busca=mb_substr(trim((string)($_GET['q']??'')),0,120);
$estado=(string)($_GET['estado']??'todos');if(!in_array($estado,['todos','activos','inactivos'],true))$estado='todos';
$alerta=(string)($_GET['alerta']??'todos');
if(!in_array($alerta,['todos','sin_precio','sin_costo','precio_bajo_costo','sin_promedio'],true))$alerta='todos';
$id=max(0,(int)($_GET['producto_id']??0));
$metas=gd_fc_metas();
foreach($sucursales as $s){
 $sid=(int)$s['id'];
 $metas['precio_pos_'.$sid]=['Precio público POS · '.$s['nombre'],'moneda','Comparación de sucursales'];
 $metas['margen_pos_'.$sid]=['Margen s/ venta · '.$s['nombre'].' (%)','porcentaje','Comparación de sucursales'];
 $metas['markup_pos_'.$sid]=['Ganancia s/ último costo · '.$s['nombre'].' (%)','porcentaje','Comparación de sucursales'];
}
$pred=['codigo','descripcion','costo_ultimo','costo_promedio','precio_compra','precio_activo','utilidad_ultimo','margen_ultimo','ganancia_sobre_ultimo','margen_promedio','alerta_margen'];
$colsRaw=$_GET['columnas']??$pred;
if(!is_array($colsRaw))$colsRaw=$pred;
$columnas=[];foreach($colsRaw as $col){if(is_string($col)&&isset($metas[$col])&&!in_array($col,$columnas,true))$columnas[]=$col;}
if(!$columnas)$columnas=$pred;
$pagina=max(1,(int)($_GET['pagina']??1));$pagina=min(1000000,$pagina);
$limit=40;
$propsFicha=['precio_sugerido','margen_sugerido','metodo_precio','costo_flete_unitario','factor_compra','unidad_compra','iva_compra','iva_venta','ieps','descuento_general','descuento_1','descuento_2','descuento_3','descuento_4'];
$fields=[];
foreach($propsFicha as $campo)$fields[]=($hasFicha?'f.`'.$campo.'`':'NULL').' AS `'.$campo.'`';
$colStock=($sucId>0&&$hasStock?'st.existencia':'NULL').' AS stock_sucursal';
$fields[]=$colStock;
foreach($sucursales as $s){
 $sid=(int)$s['id'];
 $fields[]=(($hasPrecios)?'(SELECT ppa.precio_publico FROM pos_precios ppa WHERE ppa.producto_id=p.id AND ppa.sucursal_id='.$sid.' LIMIT 1)':'NULL').' AS precio_pos_'.$sid;
}
$fields[]=($sucId>0&&$hasPrecios?'pp.precio_publico':'NULL').' AS precio_sucursal';
$fields[]=($sucId>0&&$hasMayor?'pm.precio_mayoreo':'NULL').' AS precio_mayoreo';
$fields[]=($sucId>0&&$hasMayor?'pm.cantidad_minima':'NULL').' AS cantidad_minima';
$fields[]=($sucId>0&&$hasHist?'(SELECT ph.precio_anterior FROM pos_precios_historial ph WHERE ph.producto_id=p.id AND ph.sucursal_id='.$sucId.' ORDER BY ph.creado_en DESC,ph.id DESC LIMIT 1)':'NULL').' AS precio_anterior';
$baseSql=' FROM productos p LEFT JOIN categorias cat ON cat.id=p.categoria_id LEFT JOIN proveedores prov ON prov.id=p.proveedor_id';
if($hasFicha)$baseSql.=' LEFT JOIN erp_producto_ficha f ON f.producto_id=p.id';
if($sucId>0&&$hasPrecios)$baseSql.=' LEFT JOIN pos_precios pp ON pp.producto_id=p.id AND pp.sucursal_id='.$sucId;
if($sucId>0&&$hasMayor)$baseSql.=' LEFT JOIN pos_precios_mayoreo pm ON pm.producto_id=p.id AND pm.sucursal_id='.$sucId;
if($sucId>0&&$hasStock)$baseSql.=' LEFT JOIN pos_existencias_farmacia st ON st.producto_id=p.id AND st.sucursal_id='.$stockSid;
$precioExpr=($sucId>0?($hasPrecios?'pp.precio_publico':'NULL'):'p.precio_venta');
$conds=[];$args=[];
if($id){$conds[]='p.id=:id';$args['id']=$id;}
if($busca!==''){
 $conds[]='(p.codigo LIKE :q1 OR p.codigo_barras LIKE :q2 OR p.descripcion LIKE :q3)';
 foreach(['q1','q2','q3'] as $k)$args[$k]='%'.$busca.'%';
}
if($estado==='activos')$conds[]='p.estado=1';
if($estado==='inactivos')$conds[]='p.estado=0';
if($alerta==='sin_precio')$conds[]='('.$precioExpr.' IS NULL OR '.$precioExpr.'<=0)';
if($alerta==='sin_costo')$conds[]='(p.costo_ultimo IS NULL OR p.costo_ultimo<=0)';
if($alerta==='sin_promedio')$conds[]='(p.costo_promedio IS NULL OR p.costo_promedio<=0)';
if($alerta==='precio_bajo_costo')$conds[]='('.$precioExpr.'>0 AND p.costo_ultimo>0 AND '.$precioExpr.'<p.costo_ultimo)';
$where=$conds?' WHERE '.implode(' AND ',$conds):'';
$st=$db->prepare('SELECT COUNT(*)'.$baseSql.$where);$st->execute($args);$total=(int)$st->fetchColumn();
$kpiSql='SELECT COUNT(*) AS total, '
 . 'COALESCE(SUM(CASE WHEN '.$precioExpr.' IS NULL OR '.$precioExpr.'<=0 THEN 1 ELSE 0 END),0) AS sin_precio, '
 . 'COALESCE(SUM(CASE WHEN p.costo_ultimo IS NULL OR p.costo_ultimo<=0 THEN 1 ELSE 0 END),0) AS sin_ultimo, '
 . 'COALESCE(SUM(CASE WHEN p.costo_promedio IS NULL OR p.costo_promedio<=0 THEN 1 ELSE 0 END),0) AS sin_promedio, '
 . 'COALESCE(SUM(CASE WHEN '.$precioExpr.'>0 AND p.costo_ultimo>0 AND '.$precioExpr.'<p.costo_ultimo THEN 1 ELSE 0 END),0) AS bajo_costo'
 .$baseSql.$where;
$kpiSt=$db->prepare($kpiSql);$kpiSt->execute($args);$kpis=$kpiSt->fetch(PDO::FETCH_ASSOC)?:[];
$paginas=max(1,(int)ceil($total/$limit));$pagina=min($pagina,$paginas);
$select='SELECT p.id,p.codigo,p.codigo_barras,p.descripcion,p.laboratorio,p.unidad_medida,p.estado,\n  p.precio_compra,p.costo_ultimo,p.costo_promedio,p.precio_venta,\n  prov.nombre AS proveedor,cat.nombre AS categoria,'.implode(',',$fields);
$select=str_replace('\\n',"\n",$select);
$sql=$select.$baseSql.$where.' ORDER BY p.descripcion,p.id';
function gd_fc_lista(PDO $db,string $sql,array $params,?int $limite=null,int $desde=0):PDOStatement{
 if($limite!==null)$sql.=' LIMIT '.max(1,$limite).' OFFSET '.max(0,$desde);
 $st=$db->prepare($sql);$st->execute($params);return $st;
}
function gd_fc_convertir(array $r,bool $sucursal):array{
 $r['estado_texto']=((int)$r['estado']===1?'Activo':'Inactivo');
 $met=(string)($r['metodo_precio']??'');
 $r['metodo_precio_texto']=$met==='MARK_UP'?'Ganancia sobre costo':($met==='MARK_DOWN'?'Margen sobre precio de venta':'Sin configurar');
 return gd_fc_calc($r,$sucursal);
}
function gd_fc_salida(array $r,array $columnas,array $metas):array{
 $salida=[];
 foreach($columnas as $col){$v=$r[$col]??null;$salida[]=($v===null?'':($metas[$col][1]==='texto'?(string)$v:(string)$v));}
 return $salida;
}
$modo=(string)($_GET['formato']??'');
$filename='gyd_costos_rentabilidad_'.date('Ymd_His');
if($modo==='csv'){
 header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="'.$filename.'.csv"');header('Cache-Control: no-store, private');
 $f=fopen('php://output','wb');fwrite($f,"\xEF\xBB\xBF");fputcsv($f,array_map(static fn($c)=>$metas[$c][0],$columnas));
 $st=gd_fc_lista($db,$sql,$args);
 while($r=$st->fetch(PDO::FETCH_ASSOC)){
  $r=gd_fc_convertir($r,$sucId>0);$valores=gd_fc_salida($r,$columnas,$metas);
  foreach($valores as $i=>&$v){if($metas[$columnas[$i]][1]==='texto' && $v!=='' && preg_match('/^\s*[=+@\-]/u',$v))$v="'".$v;}unset($v);
  fputcsv($f,$valores);
 }
 fclose($f);exit;
}
if($modo==='xlsx'){
 $auto=__DIR__.'/../vendor/autoload.php';
 if(!is_file($auto)){http_response_code(503);exit('La librería de Excel no está disponible. Usa CSV.');}
 require_once $auto;
 if(!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)){http_response_code(503);exit('PhpSpreadsheet no está disponible. Usa CSV.');}
 $book=new \PhpOffice\PhpSpreadsheet\Spreadsheet();$hoja=$book->getActiveSheet();$hoja->setTitle('Rentabilidad');
 foreach($columnas as $i=>$c){$hoja->setCellValueExplicit(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i+1).'1',$metas[$c][0],\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);}
 $n=2;$st=gd_fc_lista($db,$sql,$args);
 while($r=$st->fetch(PDO::FETCH_ASSOC)){
   $r=gd_fc_convertir($r,$sucId>0);
   foreach($columnas as $i=>$c){
      $v=$r[$c]??null;
      $celda=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i+1).$n;
      if($v===null||$v==='')continue;
      if($metas[$c][1]==='texto')$hoja->setCellValueExplicit($celda,(string)$v,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
      else {
       $hoja->setCellValue($celda,(float)$v);
       $formato=($metas[$c][1]==='porcentaje'||$metas[$c][1]==='impuesto')?'0.00"%"':($metas[$c][1]==='numero'?'#,##0.00':'"$"#,##0.0000');
       $hoja->getStyle($celda)->getNumberFormat()->setFormatCode($formato);
      }
   }
   $n++;
 }
 $last=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($columnas));
 $hoja->getStyle('A1:'.$last.'1')->getFont()->setBold(true);
 $hoja->freezePane('A2');$hoja->setAutoFilter('A1:'.$last.'1');
 foreach($columnas as $i=>$c)$hoja->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i+1))->setWidth($c==='descripcion'?55:24);
 header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
 header('Content-Disposition: attachment; filename="'.$filename.'.xlsx"');header('Cache-Control: no-store,private');
 (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');$book->disconnectWorksheets();exit;
}
if($modo==='pdf'){
 $st=gd_fc_lista($db,$sql,$args);
 ?><!doctype html><html lang="es"><head><meta charset="utf-8"><title>G&D - Costos y Rentabilidad</title><style>@page{size:landscape;margin:9mm}body{font-family:Arial,sans-serif;font-size:10px}h1{color:#173b65}table{border-collapse:collapse;width:100%}th,td{padding:6px;border:1px solid #ced6e0;text-align:left;overflow-wrap:anywhere}th{background:#173b65;color:white}thead{display:table-header-group}tr{break-inside:avoid}.toolbar{margin:14px}@media print{.toolbar{display:none}}</style></head><body><div class="toolbar"><button onclick="window.print()">Imprimir / Guardar PDF</button></div><h1>G&D · Costos, precios y rentabilidad</h1><p>Precio de comparación: <?=gd_fc_h($nombreSucursal)?>. Utilidad teórica sin separar IVA/IEPS, descuentos ni gastos operativos. <?=date('d/m/Y H:i')?></p><table><thead><tr><?php foreach($columnas as $c):?><th><?=gd_fc_h($metas[$c][0])?></th><?php endforeach;?></tr></thead><tbody><?php while($r=$st->fetch(PDO::FETCH_ASSOC)):$r=gd_fc_convertir($r,$sucId>0);?><tr><?php foreach($columnas as $c):?><td><?=gd_fc_h(gd_fc_fmt($r[$c]??null,$metas[$c][1]))?></td><?php endforeach;?></tr><?php endwhile;?></tbody></table>
<?php if (!defined('GYD_HIDE_GLOBAL_NAV')): ?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=4">
<script>window.GYD_NAV_HOME='index.php';window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=4"></script>
<?php endif; ?>
</body></html><?php exit;
}
$filas=[];$st=gd_fc_lista($db,$sql,$args,$limit,($pagina-1)*$limit);
while($r=$st->fetch(PDO::FETCH_ASSOC))$filas[]=gd_fc_convertir($r,$sucId>0);
$grupos=[];foreach($metas as $k=>$v)$grupos[$v[2]][$k]=$v;
$query=$_GET;unset($query['formato']);
function gd_fc_url(array $query,array $new=[]):string{return 'erp_catalogo_finanzas.php?'.http_build_query(array_merge($query,$new));}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>G&D | Costos y Rentabilidad</title><style>
:root{font-family:system-ui,-apple-system,'Segoe UI',Arial,sans-serif;color:#183956;background:#edf3f9}*{box-sizing:border-box}body{margin:0}header{background:#153e68;color:#fff;padding:18px 24px;display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;border-bottom:3px solid #d0264d}h1{font-size:23px;margin:0}header p{font-size:13px;color:#dce9f5;margin:4px 0}header a{color:#fff;text-decoration:none;border:1px solid #acc7e0;padding:10px 14px;border-radius:8px;font-weight:700}main{max-width:1670px;margin:0 auto;padding:18px}.card{background:#fff;border:1px solid #dbe5ef;border-radius:14px;padding:17px;margin:0 0 15px;box-shadow:0 2px 12px #123f6110}.card h2{font-size:18px;margin:0 0 12px}.help{font-size:13px;color:#506982}.note{background:#fff6df;border:1px solid #e9cf98;color:#684c14;padding:12px 14px;border-radius:9px;margin-bottom:14px;font-size:13px}.grid{display:grid;grid-template-columns:repeat(5,minmax(155px,1fr));gap:10px}label.field{font-size:13px;font-weight:700;display:grid;gap:5px}input,select{font:inherit;border:1px solid #c8d6e6;border-radius:8px;padding:9px 10px;background:#fff;min-height:41px;max-width:100%}.sections{display:grid;grid-template-columns:repeat(3,minmax(220px,1fr));gap:10px}.sub{border:1px solid #e1e8f2;border-radius:9px;padding:11px}.sub b{display:block;margin-bottom:10px;font-size:13px}.check{font-size:12px;display:flex;gap:5px;margin:6px 0;align-items:flex-start}.check input{min-height:0;margin:2px 4px 0 0}.btn{background:#1660a8;color:#fff;border:0;padding:10px 15px;border-radius:8px;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;font-weight:700;cursor:pointer;font-size:13px}.btn.green{background:#187a55}.btn.gray{background:#e8f0fa;color:#1a4e7c}.btn.red{background:#b9364f}.actions{display:flex;flex-wrap:wrap;gap:9px;margin-top:13px}.scroll{overflow-x:auto}table{border-collapse:collapse;min-width:100%;font-size:12px}th{background:#174877;color:#fff;padding:9px;text-align:left;white-space:nowrap}td{padding:9px;border-bottom:1px solid #e4ebf2;white-space:nowrap}tr:hover{background:#f6f9fd}.loss{color:#b82339;font-weight:800}.ok{color:#15754c;font-weight:700}.small{font-size:12px;color:#667c90}.detail{white-space:normal;min-width:220px;max-width:330px}details{margin-top:6px}details summary{cursor:pointer;color:#135baa;font-weight:700}dl{display:grid;grid-template-columns:1fr auto;gap:5px;padding:10px 0;margin:0}dt{color:#5c6e7f}dd{margin:0;text-align:right;font-weight:600}fieldset{border:0;margin:0;padding:0}fieldset legend{font-size:13px;font-weight:700}.paging{display:flex;align-items:center;justify-content:flex-end;gap:13px;margin-top:13px}.paging a{color:#155aaa}.kpis{display:grid;grid-template-columns:repeat(4,minmax(155px,1fr));gap:10px}.kpi{background:#edf4fc;border-radius:9px;padding:13px}.kpi strong{display:block;color:#175492;font-size:24px}.kpi small{color:#526c84}@media(max-width:720px){.kpis{grid-template-columns:repeat(2,minmax(150px,1fr))}}.narrow{max-width:1000px}.info{color:#314b62;font-size:13px}@media(max-width:1050px){.grid{grid-template-columns:repeat(2,minmax(160px,1fr))}.sections{grid-template-columns:repeat(2,minmax(180px,1fr))}}@media(max-width:650px){.grid,.sections{grid-template-columns:1fr}main{padding:10px}}
</style></head><body>
<header><div><h1>G&D · Costos, precios y rentabilidad</h1><p>Catálogo Maestro · Análisis por código y sucursal · Solo lectura</p></div><a href="erp_catalogo_maestro.php">← Catálogo Maestro</a><a href="erp_catalogo_reportes.php">Reportes generales</a> <a href="erp_catalogo_historial_costos.php">Historial de costos y facturas</a> <a href="erp_catalogo_comparar_proveedores.php">Comparador de proveedores</a> <a href="erp_alertas_compras.php">Alertas de compras</a></header>
<main><div class="note"><b>Importante:</b> El margen y la utilidad mostrados son <b>teóricos por unidad</b>, calculados con el precio registrado y costos del catálogo. No equivalen a la utilidad neta ni descuentan IVA, IEPS, comisiones, promociones o gastos operativos. Verifica que todos los costos y precios estén expresados por pieza. Las existencias POS jamás se mezclan con las de Almacén.</div>
<?php if($comparteStock):?><div class="note"><b>Inventario compartido Tapachula/Toscana:</b> la existencia POS y su valoración se consultan desde el saldo general de Tapachula (sucursal 3). Los precios y los márgenes se siguen calculando con el precio individual de la sucursal seleccionada. Este saldo no debe sumarse dos veces al comparar ambas sucursales.</div><?php endif;?>
<section class="card"><h2>Indicadores del catálogo filtrado</h2><div class="kpis"><div class="kpi"><strong><?=number_format((int)($kpis['total']??0))?></strong><small>Productos encontrados</small></div><div class="kpi"><strong><?=number_format((int)($kpis['sin_precio']??0))?></strong><small>Sin precio de referencia</small></div><div class="kpi"><strong><?=number_format((int)($kpis['sin_ultimo']??0))?></strong><small>Sin último costo positivo</small></div><div class="kpi"><strong><?=number_format((int)($kpis['bajo_costo']??0))?></strong><small>Precio menor al último costo</small></div></div></section>
<section class="card"><h2>Consultar productos y rentabilidad</h2><form method="get" id="frm"><div class="grid">
<label class="field">Código, barras o descripción<input name="q" value="<?=gd_fc_h($busca)?>" placeholder="Buscar artículo..."></label>
<label class="field">Precio de comparación<select name="sucursal"><option value="0">Precio base del catálogo</option><?php foreach($sucursales as $s):?><option value="<?=(int)$s['id']?>" <?=$sucId===(int)$s['id']?'selected':''?>>POS · <?=gd_fc_h($s['nombre'])?></option><?php endforeach;?></select></label>
<label class="field">Estado<select name="estado"><option value="todos">Todos</option><option value="activos" <?=$estado==='activos'?'selected':''?>>Activos</option><option value="inactivos" <?=$estado==='inactivos'?'selected':''?>>Inactivos</option></select></label>
<label class="field">Situación<select name="alerta"><?php foreach(['todos'=>'Todas','sin_precio'=>'Sin precio configurado','sin_costo'=>'Sin último costo','sin_promedio'=>'Sin costo promedio','precio_bajo_costo'=>'Precio menor que último costo'] as $k=>$label):?><option value="<?=gd_fc_h($k)?>" <?=$alerta===$k?'selected':''?>><?=gd_fc_h($label)?></option><?php endforeach;?></select></label>
<label class="field">Producto exacto (ID)<input name="producto_id" inputmode="numeric" type="number" min="0" value="<?=$id?:''?>" placeholder="Todos"></label>
</div><h2 style="margin-top:20px">Columnas que llevará el reporte</h2><p class="help">Elige qué parámetros mostrar y exportar. Las fichas individuales pueden desplegar todos los parámetros independientemente de las columnas seleccionadas.</p><div class="actions" style="margin-bottom:10px"><button type="button" class="btn gray" onclick="document.querySelectorAll('#frm input[type=checkbox]').forEach(x=>x.checked=true)">Seleccionar todos los parámetros</button><button type="button" class="btn gray" onclick="document.querySelectorAll('#frm input[type=checkbox]').forEach(x=>x.checked=false)">Quitar selección</button></div><div class="sections">
<?php foreach($grupos as $grupo=>$columnasGrupo):?><div class="sub"><b><?=gd_fc_h($grupo)?></b><?php foreach($columnasGrupo as $key=>$meta):?><label class="check"><input type="checkbox" name="columnas[]" value="<?=gd_fc_h($key)?>" <?=in_array($key,$columnas,true)?'checked':''?>><span><?=gd_fc_h($meta[0])?></span></label><?php endforeach;?></div><?php endforeach;?></div>
<div class="actions"><button class="btn" type="submit">Generar reporte</button><button class="btn green" type="submit" name="formato" value="xlsx">Exportar Excel</button><button class="btn gray" type="submit" name="formato" value="csv">Exportar CSV</button><button class="btn red" type="submit" name="formato" value="pdf" formtarget="_blank">Imprimir / Guardar PDF</button><a class="btn gray" href="erp_catalogo_finanzas.php">Limpiar</a></div></form></section>
<section class="card"><h2>Resultados · <?=number_format($total)?> producto(s)</h2><p class="help">Precio de referencia actual: <b><?=gd_fc_h($nombreSucursal)?></b>. Sin precio configurado se indica «—», no se utiliza automáticamente el precio de otra sucursal.</p>
<?php if($sucId>0&&!$hasPrecios):?><div class="note">En esta instalación no existe la tabla de precios POS; no es posible calcular la rentabilidad de esta sucursal.</div><?php endif;?>
<div class="scroll"><table><thead><tr><?php foreach($columnas as $col):?><th><?=gd_fc_h($metas[$col][0])?></th><?php endforeach;?><th>Desglose</th></tr></thead><tbody><?php foreach($filas as $r):?><tr><?php foreach($columnas as $col):$val=$r[$col]??null;?><td class="<?=($col==='alerta_margen'&&$val==='Venta bajo último costo')?'loss':((in_array($col,['margen_ultimo','margen_promedio'],true)&&$val!==null&&$val<0)?'loss':'')?>"><?=gd_fc_h(gd_fc_fmt($val,$metas[$col][1]))?></td><?php endforeach;?><td class="detail"><details><summary>Ver todos los parámetros</summary><?php foreach($grupos as $grupo=>$lista):?><h4><?=gd_fc_h($grupo)?></h4><dl><?php foreach($lista as $col=>$meta):?><dt><?=gd_fc_h($meta[0])?></dt><dd><?=gd_fc_h(gd_fc_fmt($r[$col]??null,$meta[1]))?></dd><?php endforeach;?></dl><?php endforeach;?></details></td></tr><?php endforeach;?><?php if(!$filas):?><tr><td colspan="<?=count($columnas)+1?>">Sin resultados. Revisa tus filtros.</td></tr><?php endif;?></tbody></table></div>
<div class="paging"><span>Página <?=$pagina?> de <?=$paginas?></span><?php if($pagina>1):?><a href="<?=gd_fc_h(gd_fc_url($query,['pagina'=>$pagina-1]))?>">← Anterior</a><?php endif;?><?php if($pagina<$paginas):?><a href="<?=gd_fc_h(gd_fc_url($query,['pagina'=>$pagina+1]))?>">Siguiente →</a><?php endif;?></div></section>
<section class="card narrow"><h2>Cómo interpretar las mediciones</h2><p class="info"><b>Ganancia sobre costo:</b> (precio − costo) ÷ costo × 100. <b>Margen sobre venta:</b> (precio − costo) ÷ precio × 100. <b>Costo promedio:</b> promedio ponderado registrado, no promedio simple. <b>Precio objetivo:</b> calculado con el porcentaje y método registrados en la ficha. <b>Valores de stock:</b> solo POS de la sucursal seleccionada, sin movimientos automáticos. Los descuentos de ficha son informativos y no se aplican al precio de venta en este reporte.</p></section>
</main>
<script src="assets/js/gyd_excel_reportes.js" defer></script>
</body></html>
