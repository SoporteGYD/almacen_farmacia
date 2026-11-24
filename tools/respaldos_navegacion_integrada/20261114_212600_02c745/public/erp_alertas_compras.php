<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_alertas_compras.php';
requireLogin();
$user=currentUser()??[];
$role=strtoupper(trim((string)($user['rol_original']??$user['rol']??'')));
if(!in_array($role,['ADMIN','ADMINISTRADOR','COMPRAS'],true)){http_response_code(403);exit('No tienes permiso para consultar costos y márgenes.');}
$error='';$alerts=[];$visible=[];$providers=[];$branches=[];$allCount=0;$page=1;$pages=1;
$f=gd_ac_filters([]);
try{
 $f=gd_ac_filters($_GET);
 $db=(new Database())->connect();
 foreach(['erp_facturas','erp_facturas_detalle','productos','proveedores'] as $t){
    if(!gd_cp_table($db,$t))throw new RuntimeException('Falta la tabla requerida '.$t);
 }
 $providers=$db->query('SELECT id,nombre FROM proveedores ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
 $hasPos=gd_cp_table($db,'pos_precios')&&gd_cp_table($db,'pos_sucursales');
 if($hasPos)$branches=$db->query('SELECT id,nombre FROM pos_sucursales ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
 if(!in_array($f['sucursal'],array_map(static fn($x)=>(int)$x['id'],$branches),true))$f['sucursal']=0;
 // Las facturas capturadas cuentan como antecedente documental, NO entrada aplicada al POS.
 [$sql,$args]=gd_cp_query($f,$hasPos);
 $offset=strripos($sql,' ORDER BY ');
 if($offset===false)throw new RuntimeException('Consulta financiera incompatible.');
 // Excluir anulaciones si existen. Estados activos del flujo existente.
 $sql=substr($sql,0,$offset)." AND f.estado IN ('REGISTRADA','EN_REVISION','VERIFICADA','CON_INCIDENCIAS')".substr($sql,$offset);
 $grouped=gd_cp_group(gd_cp_fetch($db,$sql,$args),$f['vigencia']);
 $alerts=gd_ac_type_filter(gd_ac_build($grouped,$f),$f['tipo']);
 $allCount=count($alerts);
 $format=is_string($_GET['formato']??null)?$_GET['formato']:'';
 if($format==='csv'){
   header('Content-Type: text/csv; charset=utf-8');
   header('Content-Disposition: attachment; filename="gyd_alertas_compras_'.date('Ymd_His').'.csv"');
   header('Cache-Control: no-store, private');
   $fp=fopen('php://output','wb');fwrite($fp,"\xEF\xBB\xBF");
   fputcsv($fp,['Nivel','Alerta','Código','Descripción','Proveedor última factura','Fecha factura','Número factura','Costo factura','Precio público actual','Variación (%)','Observación']);
   foreach($alerts as $a){
     $r=[$a['nivel'],gd_ac_type_name($a['tipo']),"'".$a['codigo'],$a['descripcion'],$a['proveedor'],$a['fecha'],$a['factura'],$a['costo'],$a['precio'],$a['variacion'],$a['texto']];
     fputcsv($fp,array_map('gd_ac_export_safe',$r));
   }
   fclose($fp);exit;
 }
 $pageSize=40;$pages=max(1,(int)ceil($allCount/$pageSize));$page=min($f['pagina'],$pages);
 $visible=array_slice($alerts,($page-1)*$pageSize,$pageSize);
}catch(InvalidArgumentException $e){$error=$e->getMessage();}
catch(Throwable $e){error_log('ERP G&D - Alertas de Compras: '.$e->getMessage());$error='No se pudieron calcular las alertas. Comprueba las tablas y la compatibilidad con el Comparador de Proveedores.';}
function gd_ac_link(array $f,array $extra=[]):string{return 'erp_alertas_compras.php?'.http_build_query(array_merge($f,$extra));}
$countType=[];$high=0;
foreach($alerts as $a){$countType[$a['tipo']]=($countType[$a['tipo']]??0)+1;if($a['nivel']==='ALTA')$high++;}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>G&D | Alertas de Compras</title><style>
:root{font-family:system-ui,'Segoe UI',sans-serif;color:#193e5e;background:#edf3f8}*{box-sizing:border-box}body{margin:0}header{background:#163f68;color:#fff;padding:18px max(15px,calc((100% - 1450px)/2));border-bottom:3px solid #d33c53;display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap}h1{margin:0;font-size:23px}header p{margin:5px 0 0;color:#dce8f5;font-size:13px}header a{color:white;padding:9px 12px;border:1px solid #8eb0cc;border-radius:8px;text-decoration:none;font-size:13px}main{max-width:1450px;margin:auto;padding:16px}.card{background:#fff;border:1px solid #dce5ee;border-radius:12px;padding:16px;margin-bottom:13px;box-shadow:0 3px 12px #124e7c0a}h2{margin:0 0 12px;font-size:18px}.notice{border:1px solid #ead5a4;background:#fff8e8;color:#695225;border-radius:8px;padding:12px;font-size:13px}.error{background:#ffebeb;color:#99243e;border:1px solid #e7b1bd;padding:12px;border-radius:8px}.grid{display:grid;grid-template-columns:repeat(4,minmax(160px,1fr));gap:10px}.kpis{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:13px}.kpi{background:#fff;padding:12px 18px;border:1px solid #dce5ee;border-radius:10px;min-width:170px}.kpi strong{font-size:24px;display:block}.kpi small{color:#5b748c}label{display:grid;gap:4px;font-size:12px;font-weight:700}input,select{min-height:40px;border:1px solid #bed1df;border-radius:8px;padding:8px 10px;font:inherit;width:100%}.actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:13px}.btn,button{border:0;border-radius:8px;background:#1b609e;color:white;padding:10px 14px;font-size:13px;font-weight:700;text-decoration:none;cursor:pointer}.btn.green,button.green{background:#15785c}.btn.alt{background:#e8f1fb;color:#18547e}.scroll{overflow:auto}table{border-collapse:collapse;width:100%;min-width:1030px;font-size:12px}th{background:#19527c;color:white;padding:10px;text-align:left}td{padding:10px;border-bottom:1px solid #e4ebf3;vertical-align:top}tr:hover{background:#f7fafc}.pill{display:inline-block;padding:4px 8px;border-radius:20px;font-size:11px;font-weight:800}.pill.high{background:#ffe1e6;color:#a9253f}.pill.mid{background:#fff0cf;color:#866018}.sub{color:#61788d;font-size:12px;margin-top:4px}.money{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}.pages{display:flex;gap:14px;justify-content:flex-end;margin-top:12px}.pages a{color:#1a6199}@media(max-width:950px){.grid{grid-template-columns:repeat(2,minmax(160px,1fr))}}@media(max-width:540px){.grid{grid-template-columns:1fr}main{padding:8px}header h1{font-size:19px}}@media print{.grid,.actions,.pages,.no-print{display:none!important}main{max-width:none}.card{border:0;box-shadow:none}table{font-size:10px;min-width:0}th,td{padding:5px}}
</style></head><body><header><div><h1>Alertas inteligentes de Compras</h1><p>ERP G&D · Costos y rentabilidad · Solo lectura</p></div><nav><a href="erp_compras_alertas_seguimiento.php">Seguimiento de alertas</a> <a href="erp_catalogo_comparar_proveedores.php">Comparar proveedores</a> <a href="erp_catalogo_finanzas.php">Costos y rentabilidad</a></nav></header><main>
<div class="notice">Las alertas se calculan <strong>cuando abres o actualizas esta pantalla</strong>, usando las facturas registradas. No son notificaciones push ni cambian precios o inventarios. Los márgenes usan el precio público actual y costo facturado, sin ajustar IVA, IEPS, bonificaciones ni gastos. Un costo histórico menor no es una cotización vigente.</div>
<?php if($error):?><p class="error" role="alert"><?=gd_cp_h($error)?></p><?php endif;?>
<?php if(!$error):?><div class="kpis"><div class="kpi"><strong><?=number_format($allCount)?></strong><small>Alertas con estos filtros</small></div><div class="kpi"><strong><?=number_format($high)?></strong><small>Prioridad alta</small></div><div class="kpi"><strong><?=number_format($countType['OPORTUNIDAD']??0)?></strong><small>Posibles oportunidades</small></div><div class="kpi"><strong><?=number_format($countType['MARGEN_BAJO']??0)?></strong><small>Márgenes bajos</small></div></div><?php endif;?>
<section class="card"><h2>Filtros de análisis</h2><form method="get" class="no-print"><div class="grid">
<label>Código o descripción<input name="q" value="<?=gd_cp_h($f['q'])?>" placeholder="Código o nombre"></label>
<label>Fecha desde<input name="desde" type="date" value="<?=gd_cp_h($f['desde'])?>"></label>
<label>Fecha hasta<input name="hasta" type="date" value="<?=gd_cp_h($f['hasta'])?>"></label>
<label>Proveedor<select name="proveedor"><option value="0">Todos</option><?php foreach($providers as $pr):?><option value="<?=(int)$pr['id']?>" <?=$f['proveedor']===(int)$pr['id']?'selected':''?>><?=gd_cp_h($pr['nombre'])?></option><?php endforeach;?></select></label>
<label>Sucursal / precio público<select name="sucursal"><option value="0">Precio base del catálogo</option><?php foreach($branches as $branch):?><option value="<?=(int)$branch['id']?>" <?=$f['sucursal']===(int)$branch['id']?'selected':''?>><?=gd_cp_h($branch['nombre'])?></option><?php endforeach;?></select></label>
<label>Tipo de alerta<select name="tipo"><?php foreach(['TODAS'=>'Todas','SUBIDA'=>'Aumento de costo','MARGEN_BAJO'=>'Margen bajo','PERDIDA'=>'Venta debajo del costo','OPORTUNIDAD'=>'Posible ahorro','SIN_PRECIO'=>'Sin precio'] as $k=>$v):?><option value="<?=gd_cp_h($k)?>" <?=$f['tipo']===$k?'selected':''?>><?=gd_cp_h($v)?></option><?php endforeach;?></select></label>
<label>Vigencia de factura (días)<input type="number" name="vigencia" min="1" max="3650" value="<?=(int)$f['vigencia']?>"></label>
<label>ID producto (opcional)<input name="producto_id" type="number" min="1" value="<?=$f['producto_id']?:''?>"></label>
<label>Aumento mínimo (%)<input type="number" name="subida" min="1" max="300" step="0.1" value="<?=gd_cp_h($f['subida'])?>"></label>
<label>Margen mínimo (%)<input type="number" name="margen" min="0" max="99" step="0.1" value="<?=gd_cp_h($f['margen'])?>"></label>
<label>Ahorro potencial mínimo (%)<input type="number" name="ahorro" min="1" max="99" step="0.1" value="<?=gd_cp_h($f['ahorro'])?>"></label>
</div><div class="actions"><button>Analizar productos</button><button class="green" name="formato" value="csv">Exportar CSV para Excel</button><a href="erp_alertas_compras.php" class="btn alt">Limpiar</a><button type="button" class="btn alt" onclick="window.print()">Imprimir / PDF</button></div></form><p class="sub">Solo se considera como vigente una factura dentro de la cantidad de días seleccionados. La búsqueda histórica usa el rango de fechas y los documentos anteriores del mismo proveedor cuando están incluidos.</p></section>
<section class="card"><h2>Resultados · <?=number_format($allCount)?> alertas</h2><div class="scroll"><table><thead><tr><th>Prioridad</th><th>Producto</th><th>Motivo</th><th>Última factura observada</th><th class="money">Costo</th><th class="money">Precio actual</th><th class="money">Indicador</th><th>Revisar</th></tr></thead><tbody>
<?php foreach($visible as $a):?><tr><td><span class="pill <?=$a['nivel']==='ALTA'?'high':'mid'?>"><?=gd_cp_h($a['nivel'])?></span></td><td><b><?=gd_cp_h($a['codigo'])?></b><div class="sub"><?=gd_cp_h($a['descripcion'])?></div></td><td><b><?=gd_cp_h(gd_ac_type_name($a['tipo']))?></b><div class="sub"><?=gd_cp_h($a['texto'])?></div></td><td><?=gd_cp_h($a['proveedor'])?><div class="sub"><?=gd_cp_h($a['fecha'])?> · <?=gd_cp_h($a['factura'])?></div></td><td class="money"><?=gd_cp_money($a['costo'])?></td><td class="money"><?=gd_cp_money($a['precio'])?></td><td class="money"><?=gd_cp_pct_text($a['variacion'])?></td><td><a href="<?=gd_cp_h('erp_catalogo_historial_costos.php?'.http_build_query(['producto_id'=>$a['producto_id'],'fuente'=>'FACTURA']))?>">Historial</a><div class="sub"><a href="<?=gd_cp_h('erp_catalogo_comparar_proveedores.php?'.http_build_query(['producto_id'=>$a['producto_id'],'vigencia'=>$f['vigencia'],'sucursal'=>$f['sucursal']]))?>">Proveedores</a></div></td></tr><?php endforeach;?><?php if(!$visible):?><tr><td colspan="8">Sin alertas con los filtros elegidos. Esto no garantiza ausencia de problemas fuera del periodo o sin datos documentados.</td></tr><?php endif;?></tbody></table></div>
<div class="pages">Página <?=$page?> de <?=$pages?> <?php if($page>1):?><a href="<?=gd_cp_h(gd_ac_link($f,['pagina'=>$page-1]))?>">Anterior</a><?php endif;?><?php if($page<$pages):?><a href="<?=gd_cp_h(gd_ac_link($f,['pagina'=>$page+1]))?>">Siguiente</a><?php endif;?></div></section>
<section class="card"><h2>Cómo interpretar las alertas</h2><p class="sub"><b>Aumento de costo:</b> compara las dos facturas más recientes del mismo proveedor por código. <b>Margen bajo o venta debajo del costo:</b> usa el último costo facturado observado y el precio público actual (sin tratamiento fiscal). <b>Posible ahorro:</b> compara el costo del último proveedor facturado con otro proveedor con factura reciente, no una oferta actual. Sin suficientes documentos o sin precio válido, algunas alertas no pueden calcularse.</p></section>
</main>
<script src="assets/js/gyd_excel_reportes.js" defer></script>

<?php if (!defined('GYD_HIDE_GLOBAL_NAV')): ?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=1">
<script>window.GYD_NAV_HOME='index.php';window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=1"></script>
<?php endif; ?>
</body></html>
