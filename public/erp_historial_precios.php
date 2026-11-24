<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_cambio_precios.php';
require_once __DIR__.'/../app/helpers/erp_historial_precios.php';
requireLogin();
$usr=currentUser()?:[];
if(!cp_autorizado($usr) || (int)($usr['id']??0)<1){http_response_code(403);exit('Acceso restringido a Compras, Dirección y Administración.');}
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');
$f=[];$datos=[];$sucursales=[];$responsables=[];$total=0;$resumen=[];$error='';$avisos=[];
$csv=isset($_GET['export'])&&$_GET['export']==='csv';
try {
  $f=hp_filtros($_GET);
  $db=(new Database())->connect();
  if(!hp_tabla_existe($db,'erp_precio_operaciones') || !hp_tabla_existe($db,'erp_precio_operacion_detalle')){
      throw new DomainException('Faltan las tablas del módulo Cambio de Precios. Importa primero sql/01_registro_cambios_precios.sql del ZIP anterior.');
  }
  $omisiones=hp_tabla_existe($db,'erp_precio_omisiones');
  if(!$omisiones)$avisos[]='No está instalada la tabla de decisiones omitidas. Solo se mostrarán cambios aplicados. Para incluir omisiones instala sql/02_omisiones_revision_automatica.sql del módulo anterior.';
  $sucursales=$db->query('SELECT id,nombre FROM pos_sucursales WHERE activo=1 ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
  $responsables=$db->query('SELECT DISTINCT u.id,u.nombre FROM usuarios u WHERE u.id IN (SELECT usuario_id FROM erp_precio_operaciones'.($omisiones?' UNION SELECT usuario_id FROM erp_precio_omisiones':'').') ORDER BY u.nombre')->fetchAll(PDO::FETCH_ASSOC);
  [$from,$args]=hp_consulta($f,$omisiones);
  $resumen=hp_ejecutar($db,'SELECT COUNT(*) total, SUM(h.tipo=\'APLICADO\') aplicados, SUM(h.tipo=\'OMITIDO\') omitidos, COUNT(DISTINCT CASE WHEN h.tipo=\'APLICADO\' THEN h.producto_id END) productos '.$from,$args)->fetch(PDO::FETCH_ASSOC)?:[];
  $total=(int)($resumen['total']??0);
  $order=' ORDER BY h.creado_en DESC, h.registro_id DESC, h.tipo ASC';
  if($csv){
    // Límite explícito para evitar exportaciones que consuman excesiva memoria.
    if($total>10000)throw new DomainException('Hay más de 10,000 registros en la selección. Reduce el periodo o los filtros para exportar todo sin recortes.');
    $st=hp_ejecutar($db,'SELECT h.*'.$from.$order,$args);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="G_y_D_historial_precios_'.date('Ymd_His').'.csv"');
    $out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,['Fecha','Tipo','Código','Artículo','Sucursal','Costo anterior','Costo factura','Precio anterior','Precio nuevo','Variación de precio','Responsable','Motivo','ID factura','Método','Cálculo'],',','"','');
    while($r=$st->fetch(PDO::FETCH_ASSOC)){
        $delta=$r['precio_nuevo']===null || $r['precio_anterior']===null ? '' : number_format((float)$r['precio_nuevo']-(float)$r['precio_anterior'],2,'.','');
        $c=[(string)$r['creado_en'],(string)$r['tipo'],(string)$r['codigo'],(string)$r['descripcion'],(string)($r['sucursal']??'No aplica'),
          $r['costo_antes'],$r['costo_factura'],$r['precio_anterior'],$r['precio_nuevo'],$delta,
          (string)($r['responsable']??('Usuario #'.$r['usuario_id'])),(string)$r['motivo'],(string)$r['factura_id'],(string)$r['modo'],(string)$r['calculo']];
        foreach([0,1,2,3,4,10,11,13,14] as $ix)$c[$ix]=hp_csv_seguro($c[$ix]);
        fputcsv($out,$c,',','"','');
    }
    fclose($out);exit;
  }
  $porPagina=30;$paginas=max(1,(int)ceil($total/$porPagina));$f['pag']=min($f['pag'],$paginas);
  $desde=($f['pag']-1)*$porPagina;
  $st=hp_ejecutar($db,'SELECT h.*'.$from.$order.' LIMIT '.$porPagina.' OFFSET '.$desde,$args);
  $datos=$st->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $ex){
    error_log('ERP historial precios: '.$ex->getMessage());
    $error=$ex instanceof DomainException || $ex instanceof InvalidArgumentException ? $ex->getMessage() : 'No fue posible consultar el historial. Revisa las tablas y el registro de Apache.';
    if($csv){http_response_code(422);header('Content-Type: text/plain; charset=UTF-8');exit($error);}
}
function hp_link(array $f,array $updates=[]):string {
   $params=array_merge(['desde'=>$f['desde'],'hasta'=>$f['hasta'],'tipo'=>$f['tipo'],'sucursal'=>$f['sucursal'],'usuario'=>$f['usuario'],'q'=>$f['q']],$updates);
   return '?'.http_build_query(array_filter($params,static fn($v)=>$v!==null&&$v!==''&&$v!==0));
}
?><!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Historial de precios · ERP G&amp;D</title>
<style>
:root{font-family:system-ui,-apple-system,Segoe UI,Arial,sans-serif;color:#193a55;background:#eef4f9}*{box-sizing:border-box}body{margin:0}.top{background:linear-gradient(115deg,#103b61,#19557d);color:#fff;border-bottom:4px solid #d83248}.topinner{max-width:1350px;margin:auto;padding:25px 20px;display:flex;align-items:center;justify-content:space-between;gap:20px}.top h1{font-size:23px;margin:0 0 6px}.top p{margin:0;color:#d0e4f4;font-size:13px}.btn,a.btn,button{cursor:pointer;display:inline-flex;align-items:center;gap:7px;border:0;border-radius:9px;padding:11px 15px;background:#1963a0;color:white;font:600 13px system-ui;text-decoration:none;white-space:nowrap}.top .btn{border:1px solid #8cb4d3;background:#22557d}.btn.alt{background:#eaf2fa;color:#204e74}.btn:focus-visible,input:focus-visible,select:focus-visible{outline:3px solid #7bbcea}main{max-width:1350px;padding:20px;margin:auto}.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:18px}.stat{background:#fff;border:1px solid #d9e7f1;padding:18px;border-radius:14px}.stat .title{font-size:12px;color:#5c7285}.stat .num{font-size:27px;font-weight:800;color:#173e61;margin-top:5px}.panel{background:#fff;border:1px solid #dbe7f0;border-radius:15px;padding:20px;margin-bottom:17px;box-shadow:0 5px 20px #183c620b}.panel h2{margin:0 0 14px;font-size:18px}.filters{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.filters label{font-size:12px;font-weight:700;color:#2b506d}.filters input,.filters select{margin-top:6px;width:100%;padding:10px;border-radius:8px;border:1px solid #bfcfde;background:#fff;font:inherit;color:#153b60;min-width:0}.filters .wide{grid-column:span 2}.actions{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-top:15px}.actions .group{display:flex;gap:9px;flex-wrap:wrap}.note{background:#edf6ff;color:#204969;border-left:3px solid #347cbe;padding:12px;border-radius:8px;font-size:13px;margin-bottom:15px}.error{background:#fff0f0;color:#8e2331;border-left-color:#c63e52}.tablewrap{overflow:auto}table{border-collapse:collapse;width:100%;min-width:1100px}th{font-size:12px;text-align:left;color:white;background:#194d76;padding:12px;position:sticky;top:0}td{padding:11px 12px;font-size:12px;border-bottom:1px solid #e4ebf2;vertical-align:top}tbody tr:hover{background:#f5faff}.badge{padding:5px 8px;border-radius:20px;font-size:11px;font-weight:700;background:#e7f5ed;color:#1c6541;white-space:nowrap}.badge.omit{background:#fff3e1;color:#915e1c}.muted{color:#657d91;font-size:12px}.money{font-variant-numeric:tabular-nums;white-space:nowrap}.plus{color:#087147}.minus{color:#a23d3d}.foot{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:14px}.foot nav{display:flex;gap:8px;align-items:center}.disabled{opacity:.45;pointer-events:none}.now{font-weight:700;color:#1a537e}strong.small{font-size:11px}.nowrap{white-space:nowrap}a{color:#165a99}@media(max-width:900px){.stats{grid-template-columns:repeat(2,minmax(0,1fr))}.filters{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:530px){main{padding:12px}.topinner{align-items:start;flex-direction:column;padding:18px}.top h1{font-size:19px}.panel{padding:14px}.filters{grid-template-columns:1fr}.filters .wide{grid-column:span 1}.stats{gap:8px}.stat{padding:12px}.stat .num{font-size:23px}.foot{flex-direction:column;align-items:start}}
@media print{body{background:white}.top{background:white;color:#111;border-bottom:1px solid #ccc}.top p{color:#555}.top .btn,.filters,.actions,.foot,.noprint{display:none!important}main{max-width:none;padding:0}.panel,.stat{box-shadow:none;border:1px solid #ddd;break-inside:avoid}.stats{grid-template-columns:repeat(4,minmax(0,1fr))}.tablewrap{overflow:visible}table{min-width:0}thead th{color:#000;background:#ddd;font-size:9px}td{font-size:9px;padding:6px;overflow-wrap:anywhere}.panel h2{font-size:14px}}
</style></head><body>
<?php $barra=__DIR__.'/../app/views/layouts/erp_barra_sesion.php';if(is_file($barra))require $barra; ?>
<header class="top"><div class="topinner"><div><h1>ERP G&amp;D · Historial de cambios de precios</h1><p>Auditoría de cambios y decisiones omitidas · Solo consulta · Compras y Dirección</p></div><a class="btn" href="erp_analisis_compras.php">Análisis de compras</a> <a class="btn" href="erp_cambio_precios.php">← Volver a Cambio de Precios</a></div></header>
<main>
<?php if($error):?><div class="note error" role="alert"><?=hp_esc($error)?></div><?php else:?>
<div class="stats"><div class="stat"><div class="title">Movimientos encontrados</div><div class="num"><?=number_format($total)?></div></div><div class="stat"><div class="title">Cambios por sucursal</div><div class="num"><?=number_format((int)($resumen['aplicados']??0))?></div></div><div class="stat"><div class="title">Decisiones omitidas</div><div class="num"><?=number_format((int)($resumen['omitidos']??0))?></div></div><div class="stat"><div class="title">Productos con cambio</div><div class="num"><?=number_format((int)($resumen['productos']??0))?></div></div></div>
<?php foreach($avisos as $aviso):?><div class="note"><?=hp_esc($aviso)?></div><?php endforeach;?>
<section class="panel"><h2>Buscar y filtrar movimientos</h2><form method="get" class="filters"><label>Desde<input type="date" name="desde" value="<?=hp_esc($f['desde'])?>"></label><label>Hasta<input type="date" name="hasta" value="<?=hp_esc($f['hasta'])?>"></label><label>Tipo de movimiento<select name="tipo"><option value="TODOS">Todos los movimientos</option><option value="APLICADO" <?=$f['tipo']==='APLICADO'?'selected':''?>>Cambios aplicados</option><option value="OMITIDO" <?=$f['tipo']==='OMITIDO'?'selected':''?>>Cambios omitidos</option></select></label><label>Sucursal<select name="sucursal"><option value="0">Todas las sucursales</option><?php foreach($sucursales as $s):?><option value="<?=(int)$s['id']?>" <?=$f['sucursal']===(int)$s['id']?'selected':''?>><?=hp_esc($s['nombre'])?></option><?php endforeach;?></select></label><label>Responsable<select name="usuario"><option value="0">Todos</option><?php foreach($responsables as $r):?><option value="<?=(int)$r['id']?>" <?=$f['usuario']===(int)$r['id']?'selected':''?>><?=hp_esc($r['nombre'])?></option><?php endforeach;?></select></label><label class="wide">Producto, código o motivo<input name="q" maxlength="90" value="<?=hp_esc($f['q'])?>" placeholder="Ej. PROXALIN, 7501825 o ajuste por costo"></label><div style="align-self:end"><button type="submit">Filtrar resultados</button></div></form><div class="actions"><p class="muted" style="margin:0">Las omisiones no están asociadas a una sucursal; al filtrar por sucursal solo verás cambios aplicados.</p><div class="group"><a class="btn alt" href="erp_historial_precios.php">Limpiar</a><a class="btn" href="<?=hp_esc(hp_link($f,['export'=>'csv']))?>">Exportar CSV (Excel)</a><button type="button" class="alt" onclick="window.print()">Imprimir / Guardar PDF</button></div></div></section>
<section class="panel"><h2>Movimientos registrados</h2><div class="tablewrap"><table><thead><tr><th>Fecha</th><th>Estado</th><th>Producto</th><th>Sucursal</th><th>Costo anterior → factura</th><th>Precio anterior → nuevo</th><th>Responsable</th><th>Factura / motivo</th></tr></thead><tbody>
<?php foreach($datos as $r):$delta=($r['precio_nuevo']!==null && $r['precio_anterior']!==null)?(float)$r['precio_nuevo']-(float)$r['precio_anterior']:null;?><tr><td class="nowrap"><?=hp_esc($r['creado_en'])?></td><td><span class="badge <?=$r['tipo']==='OMITIDO'?'omit':''?>"><?=hp_esc($r['tipo'])?></span></td><td><strong><?=hp_esc($r['descripcion']??('Producto #'.$r['producto_id']))?></strong><div class="muted"><?=hp_esc($r['codigo']??'Sin código')?></div></td><td><?=hp_esc($r['sucursal']??'No aplica')?></td><td class="money"><?=hp_esc(hp_formato_importe($r['costo_antes'],4))?><br><strong>→ <?=hp_esc(hp_formato_importe($r['costo_factura'],4))?></strong></td><td class="money"><?php if($r['tipo']==='OMITIDO'):?>Sin cambio<?php else:?><?=hp_esc(hp_formato_importe($r['precio_anterior']))?> → <strong><?=hp_esc(hp_formato_importe($r['precio_nuevo']))?></strong><?php if($delta!==null):?><div class="<?=($delta>=0?'plus':'minus')?>"><?=($delta>0?'+':'')?><?=hp_esc(hp_formato_importe($delta))?></div><?php endif;?><?php endif;?></td><td><?=hp_esc($r['responsable']??('Usuario #'.$r['usuario_id']))?></td><td>Factura #<?=(int)$r['factura_id']?><div class="muted"><?=hp_esc($r['motivo'])?></div></td></tr><?php endforeach;?>
<?php if(!$datos):?><tr><td colspan="8" class="muted">No se encontraron movimientos con los filtros seleccionados.</td></tr><?php endif;?></tbody></table></div><div class="foot"><span class="muted">Mostrando <?=count($datos)?> de <?=number_format($total)?> movimientos. 30 por página.</span><nav><?php if($f['pag']>1):?><a class="btn alt" href="<?=hp_esc(hp_link($f,['pag'=>$f['pag']-1]))?>">← Anterior</a><?php endif;?><span class="now"><?=number_format($f['pag'])?> / <?=number_format($paginas)?></span><?php if($f['pag']<$paginas):?><a class="btn alt" href="<?=hp_esc(hp_link($f,['pag'=>$f['pag']+1]))?>">Siguiente →</a><?php endif;?></nav></div><p class="muted">Los registros provienen del modal de cambios de precios de G&amp;D. No incluyen cambios hechos directamente en WinCaja u otras aplicaciones.</p></section>
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
<!-- GYD_UNA_PESTANA_INTERNA -->
<script src="assets/js/gyd_una_pestana.js?v=1"></script>
</body></html>
