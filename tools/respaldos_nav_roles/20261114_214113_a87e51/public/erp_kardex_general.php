<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/pos_inteligente.php'; // GYD_F1_POOL_TT_20261103
require_once __DIR__.'/../app/helpers/erp_kardex_unificado.php';
requireLogin();
$usr=currentUser()?:[];
$rol=strtoupper(trim((string)($usr['rol_original']??$usr['rol']??'')));
if(!in_array($rol,['ADMINISTRADOR','ADMIN','DIRECCION'],true)){http_response_code(403);exit('No tienes permiso para consultar el Kardex General.');}
header('Cache-Control: private, no-store, max-age=0');
$tipos=['TODOS'=>'Todos los movimientos','VENTA'=>'Ventas POS','ALMACEN'=>'Entradas y salidas de Almacén','FACTURA'=>'Facturas aplicadas','RESURTIDO'=>'Resurtidos','TICKET'=>'Tickets del POS','DEVOLUCION'=>'Devoluciones y reversas','AJUSTE'=>'Ajustes autorizados','CONTEO'=>'Conteos físicos'];
$hoy=(new DateTimeImmutable('today'))->format('Y-m-d');
$desde=(string)($_GET['desde']??(new DateTimeImmutable('today -13 days'))->format('Y-m-d'));
$hasta=(string)($_GET['hasta']??$hoy);
$sid=(int)($_GET['sucursal']??1);
$pid=max(0,(int)($_GET['producto']??0));
$usuarioFiltrado=max(0,(int)($_GET['usuario']??0));
$tipo=(string)($_GET['tipo']??'TODOS');
$q=mb_substr(trim((string)($_GET['q']??'')),0,90);
$pagina=max(1,min(100,(int)($_GET['pagina']??1)));
$validDate=static function(string $value):bool{$d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);return $d!==false&&$d->format('Y-m-d')===$value;};
$error='';$warnings=[];$events=[];$producto=[];$found=[];$usuarios=[];$sucursales=[];$snapshot=[];
if(!$validDate($desde)||!$validDate($hasta)||$desde>$hasta||$hasta>$hoy||(strtotime($hasta)-strtotime($desde))>366*86400){$error='Selecciona fechas válidas dentro de los últimos 366 días de consulta (máximo 367 días por rango).';}
if(!array_key_exists($tipo,$tipos))$tipo='TODOS';
try{
    $db=(new Database())->connect();
    $sucursales=$db->query('SELECT id,nombre FROM pos_sucursales WHERE activo=1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $map=array_column($sucursales,'nombre','id');
    if(!isset($map[$sid])){$sid=(int)($sucursales[0]['id']??1);}
    $usuarios=$db->query("SELECT id,nombre,rol FROM usuarios WHERE estado=1 ORDER BY nombre LIMIT 250")->fetchAll(PDO::FETCH_ASSOC);
    if($q!==''){
        $s=$db->prepare('SELECT id,codigo,descripcion FROM productos WHERE codigo LIKE ? OR codigo_barras LIKE ? OR descripcion LIKE ? ORDER BY CASE WHEN codigo=? OR codigo_barras=? THEN 0 ELSE 1 END,descripcion LIMIT 30');
        $w='%'.$q.'%';$s->execute([$w,$w,$w,$q,$q]);$found=$s->fetchAll(PDO::FETCH_ASSOC);
    }
    if($pid){$s=$db->prepare('SELECT id,codigo,descripcion FROM productos WHERE id=?');$s->execute([$pid]);$producto=$s->fetch(PDO::FETCH_ASSOC)?:[];if(!$producto)$error='Producto no encontrado.';}
    if(!$error&&$producto){
        $events=kg_movimientos($db,$sid,$pid,$desde,$hasta,$tipo,$usuarioFiltrado,$warnings);
        if(kg_table($db,'pos_existencias_farmacia')){
            $s=$db->prepare('SELECT existencia FROM pos_existencias_farmacia WHERE sucursal_id=? AND producto_id=?');$s->execute([pi_tt_stock_sid($db,$sid),$pid]);$snapshot['pos']=$s->fetchColumn();
        }
        if(kg_table($db,'erp_pos_farmacia_control')){
            $s=$db->prepare('SELECT existencia,minimo FROM erp_pos_farmacia_control WHERE sucursal_id=? AND producto_id=?');$s->execute([$sid,$pid]);$row=$s->fetch(PDO::FETCH_ASSOC);$snapshot['farmacia']=$row!==false?$row['existencia']:null;$snapshot['minimo']=$row!==false?$row['minimo']:null;
        }
        if(in_array($sid,[1,2],true)&&kg_table($db,'producto_existencias')){
            $names=$sid===1?['CIUDAD HIDALGO','CD HIDALGO']:['TUXTLA','TUXTLA GUTIERREZ','TUXTLA GUTIÉRREZ'];
            $sq='SELECT COALESCE(SUM(GREATEST(existencia,0)),0) FROM producto_existencias WHERE producto_id=? AND UPPER(TRIM(sucursal)) COLLATE utf8mb4_general_ci IN ('.implode(',',array_fill(0,count($names),'?')).") AND TRIM(COALESCE(ubicacion,''))<>'' AND UPPER(TRIM(ubicacion)) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION','SIN UBICACIÓN')";
            $s=$db->prepare($sq);$s->execute([$pid,...$names]);$snapshot['almacen']=$s->fetchColumn();
        }
    }
}catch(Throwable $e){error_log('ERP Kardex consulta: '.$e->getMessage());$error='No se pudo completar la consulta del Kardex. Revisa el registro PHP y la conexión a la base.';}
$limit=70;$count=count($events);$pages=max(1,(int)ceil($count/$limit));$pagina=min($pagina,$pages);$rows=array_slice($events,($pagina-1)*$limit,$limit);
$base=['sucursal'=>$sid,'producto'=>$pid,'q'=>$q,'tipo'=>$tipo,'usuario'=>$usuarioFiltrado,'desde'=>$desde,'hasta'=>$hasta];
$export=(($_GET['exportar']??'')==='csv');
if($export&&!$error&&$producto){
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="kardex_'.(int)$sid.'_'.(int)$pid.'_'.str_replace('-','',$desde).'_'.str_replace('-','',$hasta).'.csv"');
    $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,['Fecha','Tipo','Movimiento','Folio','Descripción','Usuario','Δ POS general','Δ Farmacia física','Δ Almacén físico','Estado','Tabla','ID de registro'],';');
    $csvSafe=static function(mixed $value):string{$v=(string)$value;return preg_match('/^\s*[=+\-@]/u',$v)?"'".$v:$v;};
    foreach($events as $r){fputcsv($out,[$r['fecha'],$csvSafe($r['grupo']),$csvSafe($r['tipo']),$csvSafe($r['folio']),$csvSafe($r['detalle']),$csvSafe($r['usuario']),$r['pos']??'NO DETERMINADO',$r['farmacia']??'NO DETERMINADO',$r['almacen']??'NO DETERMINADO',$csvSafe($r['estado']),$csvSafe($r['origen']),$r['id']],';');}
    fclose($out);exit;
}
function kg_number(?int $n):string {return $n===null?'—':($n>0?'+':'').number_format($n,0,'.',',');}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ERP G&D · Kardex General Unificado</title>
<style>
:root{color-scheme:light;font:15px system-ui,-apple-system,Segoe UI,Arial,sans-serif;color:#16385e;background:#eef3fa}*{box-sizing:border-box}body{margin:0}header{background:linear-gradient(115deg,#12345d,#2468b8);color:white;padding:25px max(16px,calc((100vw - 1290px)/2))}header h1{margin:0 0 5px;font-size:clamp(23px,3vw,29px)}header p{margin:0;opacity:.87}main{max-width:1310px;padding:18px 16px 55px;margin:0 auto}.links{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:15px}.btn,button{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:0;background:#175ba8;color:#fff;padding:11px 14px;border-radius:9px;text-decoration:none;font:650 13px system-ui;cursor:pointer}.btn.light{background:#e5edf7;color:#16466f}.card{background:#fff;border-radius:15px;box-shadow:0 5px 19px #20476e12;padding:19px;margin-bottom:15px}.filters{display:grid;grid-template-columns:repeat(4,minmax(145px,1fr));gap:13px}.filters label{display:block;font-size:12px;font-weight:700;margin-bottom:5px}input,select{width:100%;padding:10px 11px;border:1px solid #c2d2e6;border-radius:8px;font:inherit;min-width:0}.actions{display:flex;flex-wrap:wrap;gap:8px;align-items:end;margin-top:14px}.note{border-radius:10px;padding:13px 15px;background:#fff6e2;border:1px solid #eed49b;font-size:13px;line-height:1.55}.note.error{background:#fee9e9;border-color:#e7a9aa}.muted{font-size:12px;color:#657d98}.suggestions{display:flex;flex-wrap:wrap;gap:8px}.suggestions a{color:#185da7;background:#eaf2fc;border-radius:7px;padding:8px 11px;font-size:12px;text-decoration:none}.metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:13px 0}.metric{background:#f0f5fc;border-radius:10px;padding:12px}.metric small{display:block;color:#526d86}.metric b{font-size:21px;font-variant-numeric:tabular-nums}.scroll{overflow:auto;border-radius:11px;border:1px solid #e0e9f4}table{border-collapse:collapse;width:100%;min-width:1210px}th,td{padding:12px 11px;border-bottom:1px solid #e7eef7;font-size:12px;vertical-align:top}th{background:#184779;color:#fff;text-align:left;position:sticky;top:0}tbody tr:nth-child(even){background:#f7faff}td.num{text-align:right;font-variant-numeric:tabular-nums;font-weight:700;white-space:nowrap}.pos{color:#217348}.neg{color:#ae3544}.unknown{color:#9d641d}.badge{display:inline-block;padding:5px 9px;background:#ebf3fa;color:#174c7a;border-radius:30px;font-size:11px;font-weight:800}.pager{display:flex;justify-content:space-between;align-items:center;margin-top:15px;gap:10px;flex-wrap:wrap}.sectionTitle{margin:0 0 13px;font-size:18px}a{color:#1d62a8}@media(max-width:840px){.filters{grid-template-columns:repeat(2,minmax(0,1fr))}.metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.card{padding:14px}}@media(max-width:440px){.filters{grid-template-columns:1fr}}@media print{.links,.filters,.actions,.suggestions{display:none}header{background:#fff;color:#000}.card{box-shadow:none}}
</style></head><body>
<header><h1>Kardex General Unificado · ERP G&amp;D</h1><p>Trazabilidad documental de compras, ventas, Almacén y ajustes · Consulta sin modificaciones</p></header>
<main><div class="links"><a class="btn light" href="dashboard.php">← Inicio</a><a class="btn light" href="erp_conciliacion_inventarios.php">Conciliación</a><a class="btn light" href="erp_conciliacion_gestion.php">Conteos y ajustes</a><a class="btn light" href="logout.php">Cerrar sesión</a></div>
<section class="card"><form method="get"><div class="filters"><div><label for="sucursal">Sucursal</label><select id="sucursal" name="sucursal"><?php foreach($sucursales as $s):?><option value="<?=(int)$s['id']?>" <?=((int)$s['id']===$sid)?'selected':''?>><?=kg_h($s['nombre'])?></option><?php endforeach;?></select></div>
<div><label for="q">Buscar producto</label><input name="q" id="q" placeholder="Código, barras o descripción" value="<?=kg_h($q)?>"></div>
<div><label for="tipo">Tipo de movimiento</label><select id="tipo" name="tipo"><?php foreach($tipos as $k=>$v):?><option value="<?=kg_h($k)?>" <?=$tipo===$k?'selected':''?>><?=kg_h($v)?></option><?php endforeach;?></select></div>
<div><label for="usuario">Usuario responsable</label><select id="usuario" name="usuario"><option value="0">Todos los usuarios</option><?php foreach($usuarios as $u):?><option value="<?=(int)$u['id']?>" <?=$usuarioFiltrado===(int)$u['id']?'selected':''?>><?=kg_h($u['nombre'].' · '.$u['rol'])?></option><?php endforeach;?></select></div>
<div><label for="desde">Desde</label><input type="date" name="desde" id="desde" value="<?=kg_h($desde)?>" required></div><div><label for="hasta">Hasta</label><input type="date" name="hasta" id="hasta" value="<?=kg_h($hasta)?>" max="<?=kg_h($hoy)?>" required></div></div>
<input type="hidden" name="producto" value="<?=$pid?>"><div class="actions"><button type="submit">Consultar Kardex</button><a class="btn light" href="erp_kardex_general.php">Limpiar filtros</a><?php if($producto&&!$error):?><a class="btn light" href="?<?=kg_h(http_build_query(array_merge($base,['exportar'=>'csv'])))?>">↓ Exportar CSV</a><?php endif;?></div></form>
<?php if($q!==''):?><div style="margin-top:16px"><strong>Productos encontrados</strong><div class="suggestions" style="margin-top:10px"><?php foreach($found as $f):?><a href="?<?=kg_h(http_build_query(array_merge($base,['producto'=>$f['id'],'q'=>$q,'pagina'=>1])))?>"><?=kg_h($f['codigo'].' · '.$f['descripcion'])?></a><?php endforeach;?><?php if(!$found):?><span class="muted">No se encontraron coincidencias.</span><?php endif;?></div></div><?php endif;?></section>
<?php if($error):?><section class="card"><div class="note error" role="alert"><?=kg_h($error)?></div></section><?php endif;?>
<?php if(!$producto&&!$error):?><section class="card"><h2 class="sectionTitle">Selecciona un producto para iniciar</h2><p>Busca por código de barras o descripción, y haz clic en el producto de la lista. Así podrás consultar sus movimientos sin mezclar existencias de distintos productos.</p></section><?php endif;?>
<?php if($producto&&!$error):?>
<section class="card"><h2 class="sectionTitle"><?=kg_h($producto['codigo'].' · '.$producto['descripcion'])?></h2><div class="note"><strong>Cómo interpretar este Kardex:</strong> la columna <b>POS general</b> refleja movimientos registrados como ventas, devoluciones y ajustes. Las columnas <b>Farmacia</b> y <b>Almacén</b> reflejan movimientos físicos conocidos. <b>«—» indica que el efecto no pudo determinarse</b> y no equivale a cero. Los folios de factura, tickets, conteos y devoluciones pueden ser referencias del mismo proceso, no entradas adicionales. <b>No sumes estos renglones para reconstruir el saldo:</b> pueden faltar movimientos anteriores, iniciales, correcciones históricas o integraciones de otras versiones.</div>
<div class="metrics"><div class="metric"><small>POS general actual</small><b><?=array_key_exists('pos',$snapshot)&&$snapshot['pos']!==false?number_format((int)$snapshot['pos']):'—'?></b></div><div class="metric"><small>Farmacia física actual</small><b><?=isset($snapshot['farmacia'])?number_format((int)$snapshot['farmacia']):'Sin conteo'?></b></div><div class="metric"><small>Almacén físico actual</small><b><?=isset($snapshot['almacen'])?number_format((int)$snapshot['almacen']):($sid>2?'No aplica':'—')?></b></div><div class="metric"><small>Eventos del período</small><b><?=number_format($count)?></b></div></div><p class="muted">Existencias actuales: corte al momento de consulta. Los movimientos filtrados pertenecen al período <?=kg_h($desde)?> a <?=kg_h($hasta)?>. No equivalen a un saldo histórico en esa fecha.</p>
<?php foreach($warnings as $w):?><p class="note error" role="alert"><?=kg_h($w)?></p><?php endforeach;?></section>
<section class="card"><h2 class="sectionTitle">Historial de movimientos</h2><div class="scroll"><table><thead><tr><th>Fecha y hora</th><th>Tipo</th><th>Folio / referencia</th><th>Detalle</th><th>Responsable</th><th>Δ POS general</th><th>Δ Farmacia</th><th>Δ Almacén</th><th>Estado / fuente</th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr><td style="white-space:nowrap"><?=kg_h($r['fecha'])?></td><td><span class="badge"><?=kg_h($r['grupo'])?></span><br><span class="muted"><?=kg_h($r['tipo'])?></span></td><td><strong><?=kg_h($r['folio'])?></strong></td><td><?=kg_h($r['detalle'])?></td><td><?=kg_h($r['usuario'])?></td>
<?php foreach(['pos','farmacia','almacen'] as $k):$delta=$r[$k];?><td class="num <?=($delta===null?'unknown':($delta<0?'neg':($delta>0?'pos':'')))?>"><?=kg_number($delta)?></td><?php endforeach;?><td><?=kg_h($r['estado'])?><br><span class="muted"><?=kg_h($r['origen'].' #'.$r['id'])?></span></td></tr><?php endforeach;?>
<?php if(!$rows):?><tr><td colspan="9">No hay movimientos de las fuentes disponibles para estos filtros. Amplía las fechas o comprueba si ese producto tiene movimientos registrados.</td></tr><?php endif;?></tbody></table></div><div class="pager"><span class="muted">Página <?=$pagina?> de <?=$pages?> · <?=count($rows)?> eventos mostrados</span><div class="links"><?php if($pagina>1):?><a class="btn light" href="?<?=kg_h(http_build_query(array_merge($base,['pagina'=>$pagina-1])))?>">← Anterior</a><?php endif;?><?php if($pagina<$pages):?><a class="btn light" href="?<?=kg_h(http_build_query(array_merge($base,['pagina'=>$pagina+1])))?>">Siguiente →</a><?php endif;?></div></div></section>
<?php endif;?></main>
<script src="assets/js/gyd_excel_reportes.js" defer></script>

<?php if (!defined('GYD_HIDE_GLOBAL_NAV')): ?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=4">
<script>window.GYD_NAV_HOME='index.php';window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=4"></script>
<?php endif; ?>
</body></html>
