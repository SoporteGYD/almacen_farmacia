<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_comparador_proveedores.php';
requireLogin();
$user=currentUser()??[];
$role=strtoupper(trim((string)($user['rol_original']??$user['rol']??'')));
if(!in_array($role,['ADMIN','ADMINISTRADOR','COMPRAS'],true)){http_response_code(403);exit('Sin permiso para ver costos y proveedores.');}
$f=[];$datos=[];$error='';$proveedores=[];$sucursales=[];$total=0;$pagina=1;$nPages=1;$hasPos=false;
try{
 $f=gd_cp_parameters($_GET);
 $db=(new Database())->connect();
 foreach(['erp_facturas','erp_facturas_detalle','proveedores','productos'] as $t){
   if(!gd_cp_table($db,$t))throw new RuntimeException('Falta la tabla requerida: '.$t);
 }
 $proveedores=$db->query('SELECT id,nombre FROM proveedores ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
 $hasPos=gd_cp_table($db,'pos_precios')&&gd_cp_table($db,'pos_sucursales');
 if($hasPos)$sucursales=$db->query('SELECT id,nombre FROM pos_sucursales ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
 $allowed=array_map(static fn($x)=>(int)$x['id'],$sucursales);
 if(!in_array($f['sucursal'],$allowed,true))$f['sucursal']=0;
 [$sql,$args]=gd_cp_query($f,$hasPos);
 $st=gd_cp_fetch($db,$sql,$args);
 // Una sola pasada ordenada; conserva 2 compras documentadas por proveedor y código.
 $todos=gd_cp_group($st,$f['vigencia']);
 $total=count($todos);
 $format=is_string($_GET['formato']??null)?$_GET['formato']:'';
 if($format==='csv'){
   header('Content-Type: text/csv; charset=utf-8');
   header('Content-Disposition: attachment; filename="gyd_comparacion_proveedores_'.date('Ymd_His').'.csv"');
   header('Cache-Control: no-store, private');
   $fp=fopen('php://output','wb');fwrite($fp,"\xEF\xBB\xBF");
   fputcsv($fp,['Código','Código barras','Descripción','Proveedor','Última factura','Fecha de factura','Piezas en última factura','Costo unitario último documentado','Costo en factura previa','Variación frente a factura previa (%)','Precio de venta actual','Margen teórico actual (%)','Último costo actual catálogo','Costo promedio actual catálogo','Días desde factura','Costo antiguo','Menor costo documentado vigente','Proveedor con menor costo documentado vigente','Diferencia entre costos documentados vigentes por pieza']);
   foreach($todos as $p){
    $mejor=$p['mejor_proveedor_id']===null?'':$p['proveedores'][$p['mejor_proveedor_id']]['proveedor'];
    foreach($p['proveedores'] as $d){
      $row=["'".$p['codigo'],"'".$p['codigo_barras'],$p['descripcion'],$d['proveedor'],$d['factura'],$d['fecha'],$d['piezas'],$d['costo'],$d['anterior'],gd_cp_percent($d['costo'],$d['anterior']),$p['precio'],gd_cp_margin($d['costo'],$p['precio']),$p['ultimo_actual'],$p['promedio_actual'],$d['dias'],$d['antiguo']?'SI':'NO',$p['mejor_costo_vigente'],$mejor,$p['hay_comparacion']?$p['diferencia_observada']:null];
      fputcsv($fp,array_map('gd_cp_csv_text',$row));
    }
   }
   fclose($fp);exit;
 }
 $pageSize=25;$nPages=max(1,(int)ceil($total/$pageSize));$pagina=min($f['pagina'],$nPages);
 $datos=array_slice($todos,($pagina-1)*$pageSize,$pageSize,true);
}catch(InvalidArgumentException $e){$error=$e->getMessage();}
catch(Throwable $e){error_log('Comparador proveedores G&D: '.$e->getMessage());$error='No se pudo consultar el historial. Comprueba que los módulos de Facturas y Catálogo Maestro estén instalados en esta base de datos.';}
function gd_cp_link(array $f,array $extra=[]):string{return 'erp_catalogo_comparar_proveedores.php?'.http_build_query(array_merge($f,$extra));}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>G&D · Comparador de proveedores</title><style>
:root{font-family:system-ui,'Segoe UI',Arial,sans-serif;color:#173c5d;background:#eef3f8}*{box-sizing:border-box}body{margin:0}
header{background:#153e68;border-bottom:3px solid #d33455;color:#fff;padding:19px clamp(14px,4vw,32px);display:flex;flex-wrap:wrap;gap:15px;justify-content:space-between;align-items:center}
header h1{margin:0;font-size:22px}header p{margin:5px 0 0;color:#d9e8f5;font-size:13px}header a{color:white;text-decoration:none;border:1px solid #9fbdd8;border-radius:8px;padding:9px 12px;font-size:13px}
main{max-width:1620px;margin:auto;padding:18px}.card{background:#fff;border-radius:13px;border:1px solid #dae4ed;padding:16px;margin-bottom:14px;box-shadow:0 4px 16px #15476a0a}
.note{background:#fff5e0;border:1px solid #ead3a8;border-radius:9px;padding:11px;font-size:13px;margin-bottom:13px;color:#5d4b20}.error{background:#ffe7e7;border:1px solid #e3a2a2;padding:12px;border-radius:8px;font-weight:700;color:#9c2332}
.grid{display:grid;grid-template-columns:repeat(5,minmax(140px,1fr));gap:12px}label{font-size:12px;font-weight:700;display:grid;gap:5px}label span{font-weight:400}input,select{border:1px solid #bed0de;border-radius:8px;padding:9px 10px;min-height:40px;width:100%;font:inherit}
.actions{display:flex;flex-wrap:wrap;gap:9px;margin-top:15px}.btn{background:#1d5da0;border:0;color:#fff;padding:10px 14px;border-radius:8px;text-decoration:none;font-size:13px;font-weight:700;cursor:pointer}.btn.green{background:#177956}.btn.alt{background:#e7effa;color:#20537c}
.pill{padding:4px 8px;border-radius:20px;font-size:11px;display:inline-block;background:#e7f0fa;color:#20537c;font-weight:700}.pill.warn{background:#fff0cf;color:#8a5a14}.pill.red{background:#ffe4e9;color:#a42342}.pill.good{background:#dff3e7;color:#107647}
.scroll{overflow:auto}table{border-collapse:collapse;width:100%;min-width:1030px;font-size:12px}th{background:#184b78;color:white;text-align:left;padding:9px}td{padding:10px;border-bottom:1px solid #e2eaf2;vertical-align:top}tr:hover{background:#f7fafd}small,.muted{color:#617a91}.mon{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}.strong{font-weight:800}.loss{color:#ad2842;font-weight:800}.gain{color:#117249;font-weight:700}
.product{display:flex;gap:7px;align-items:center;flex-wrap:wrap}.product b{font-size:14px}.sub{font-size:12px;color:#59748e}.pages{display:flex;gap:15px;justify-content:flex-end;align-items:center;margin-top:12px}.pages a{color:#155aa0}details summary{color:#155aa0;font-weight:700;cursor:pointer;padding:6px 0}h2{font-size:17px;margin:0 0 10px}
@media(max-width:980px){.grid{grid-template-columns:repeat(2,minmax(150px,1fr))}}@media(max-width:560px){.grid{grid-template-columns:1fr}main{padding:9px}}
@media print{header a,.actions,.grid,.pages,details summary,.note,.no-print{display:none!important}main{max-width:none;padding:0}.card{box-shadow:none}table{min-width:0;font-size:10px}th,td{padding:5px}}
</style></head><body>
<header><div><h1>Comparador de proveedores y rentabilidad</h1><p>ERP G&D · Catálogo Maestro · Facturas registradas · Solo consulta</p></div><nav><a href="erp_catalogo_finanzas.php">Costos y rentabilidad</a> <a href="erp_catalogo_historial_costos.php">Historial de costos</a></nav> <a href="erp_alertas_compras.php">Alertas de compras</a></header>
<main>
<div class="note"><b>Importante:</b> los costos mostrados son los de las últimas facturas registradas en el periodo elegido, <b>no cotizaciones vigentes</b>. Un proveedor con costo histórico menor no necesariamente mantiene ese precio. Los márgenes usan precios públicos actuales, no históricos, y no descuentan impuestos, descuentos ni gastos. Los inventarios del POS y Almacén permanecen independientes.</div>
<?php if($error!==''):?><div class="error"><?=gd_cp_h($error)?></div><?php endif;?>
<section class="card"><h2>Buscar y comparar por código</h2><form method="get" class="no-print"><div class="grid">
<label>Código / barras / descripción<input name="q" value="<?=gd_cp_h($f['q']??'')?>" placeholder="Ej. 650240..."></label>
<label>Desde<input type="date" name="desde" value="<?=gd_cp_h($f['desde']??'')?>"></label>
<label>Hasta<input type="date" name="hasta" value="<?=gd_cp_h($f['hasta']??'')?>"></label>
<label>Proveedor<select name="proveedor"><option value="0">Todos (comparar)</option><?php foreach($proveedores as $pr):?><option value="<?=(int)$pr['id']?>" <?=(int)($f['proveedor']??0)===(int)$pr['id']?'selected':''?>><?=gd_cp_h($pr['nombre'])?></option><?php endforeach;?></select></label>
<label>Precio para margen<select name="sucursal"><option value="0">Precio base catálogo</option><?php foreach($sucursales as $su):?><option value="<?=(int)$su['id']?>" <?=(int)($f['sucursal']??0)===(int)$su['id']?'selected':''?>>POS · <?=gd_cp_h($su['nombre'])?></option><?php endforeach;?></select></label>
<label>Antigüedad máxima para comparar (días)<input type="number" name="vigencia" min="1" max="3650" value="<?=(int)($f['vigencia']??90)?>"><span>Más antiguos: solo informativos</span></label>
<label>Producto exacto (ID)<input type="number" name="producto_id" min="1" placeholder="Todos" value="<?=((int)($f['producto_id']??0))?:''?>"></label>
</div><div class="actions"><button type="submit" class="btn">Generar comparación</button><button type="submit" class="btn green" name="formato" value="csv">Exportar CSV para Excel</button><a class="btn alt" href="erp_catalogo_comparar_proveedores.php">Limpiar filtros</a><button class="btn alt" type="button" onclick="window.print()">Imprimir página / Guardar PDF</button></div></form>
<p class="muted">Seleccionar un solo proveedor elimina la comparación entre proveedores. La exportación CSV contiene todos los productos filtrados, no solamente los de esta página.</p></section>
<section class="card"><h2>Resultados · <?=number_format($total)?> producto(s)</h2>
<?php if(!$datos):?><p class="muted">No hay registros de facturas que coincidan con estos filtros.</p><?php endif;?>
<?php foreach($datos as $p):?>
<div class="product"><b><?=gd_cp_h($p['codigo'])?> — <?=gd_cp_h($p['descripcion'])?></b><span class="sub">Barras: <?=gd_cp_h($p['codigo_barras']?:'—')?> · Precio actual: <?=gd_cp_money($p['precio'])?> · Último costo catálogo: <?=gd_cp_money($p['ultimo_actual'])?> · Promedio ponderado: <?=gd_cp_money($p['promedio_actual'])?></span></div>
<div class="scroll"><table><thead><tr><th>Proveedor</th><th>Última factura / fecha</th><th class="mon">Costo facturado</th><th class="mon">Costo anterior</th><th class="mon">Cambio</th><th class="mon">Margen estimado</th><th>Estado</th></tr></thead><tbody>
<?php foreach($p['proveedores'] as $pr): $variation=gd_cp_percent($pr['costo'],$pr['anterior']);$margen=gd_cp_margin($pr['costo'],$p['precio']);$isBest=$p['mejor_proveedor_id']===$pr['id']&&$p['hay_comparacion']; ?>
<tr><td><b><?=gd_cp_h($pr['proveedor'])?></b><?php if($isBest):?> <span class="pill good">Menor costo observado</span><?php endif;?></td><td><?=gd_cp_h($pr['factura'])?><br><small><?=gd_cp_h($pr['fecha'])?></small></td>
<td class="mon strong"><?=gd_cp_money($pr['costo'])?></td><td class="mon"><?=gd_cp_money($pr['anterior'])?></td>
<td class="mon <?=($variation!==null&&$variation>0)?'loss':(($variation!==null&&$variation<0)?'gain':'')?>"><?=gd_cp_pct_text($variation)?></td>
<td class="mon <?=($margen!==null&&$margen<0)?'loss':''?>"><?=gd_cp_pct_text($margen)?></td>
<td><?php if($pr['riesgo']):?><span class="pill red">Fecha futura: revisar</span><?php elseif($pr['antiguo']):?><span class="pill warn">Costo antiguo</span><?php else:?><span class="pill good">Dentro del periodo de vigencia</span><?php endif;?></td></tr>
<?php endforeach;?></tbody></table></div>
<p class="sub">Proveedores documentados: <?=count($p['proveedores'])?>. <?php if($p['hay_comparacion']):?>Diferencia entre costo vigente mayor y menor: <b><?=gd_cp_money($p['diferencia_observada'])?> por pieza</b>; es una referencia histórica, no ahorro garantizado.<?php else:?>No hay al menos dos proveedores con costos documentados recientes y comparables.<?php endif;?></p>
<hr style="border:0;border-top:1px solid #e5ecf3;margin:17px 0">
<?php endforeach;?>
<div class="pages"><span>Página <?=$pagina?> de <?=$nPages?></span><?php if($pagina>1):?><a href="<?=gd_cp_h(gd_cp_link($f,['pagina'=>$pagina-1]))?>">Anterior</a><?php endif;?><?php if($pagina<$nPages):?><a href="<?=gd_cp_h(gd_cp_link($f,['pagina'=>$pagina+1]))?>">Siguiente</a><?php endif;?></div>
</section>
<section class="card"><h2>Cómo interpretar este reporte</h2><p class="muted">La variación de costo compara la última factura y la factura anterior <b>del mismo proveedor para el mismo código</b>, dentro del rango consultado. El margen estimado es (precio público actual − costo facturado) ÷ precio público actual × 100. El importe mostrado no incluye ajustes de impuestos, descuentos o unidades diferentes. Si un producto está en cajas o bonificaciones, verifica su equivalencia en piezas antes de tomar una decisión. La última factura se toma por fecha, ID y renglón, y no por la fecha de aplicación al inventario.</p></section>
</main>
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
<!-- GYD_UNA_PESTANA_INTERNA -->
<script src="assets/js/gyd_una_pestana.js?v=1"></script>
</body></html>
