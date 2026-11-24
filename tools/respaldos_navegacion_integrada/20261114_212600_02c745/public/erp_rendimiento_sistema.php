<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/gyd_optimizacion.php';
requireLogin();$u=currentUser()??[];
if(!go_role($u)){http_response_code(403);exit('Acceso reservado a Administración y Dirección.');}
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);exit('Método no permitido.');}
header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: same-origin');
$error='';$rows=[];$explain=[];$sample=[];$tables=[];
try{
  $db=(new Database())->connect();go_check_db($db);
  $rows=go_review($db);$tables=go_tables($db);
  $explain=go_explains($db,$tables);
  if(($_GET['muestra']??'')==='1'){
    if((time()-(int)($_SESSION['gyd_opt_last']??0))<15)throw new DomainException('Las muestras solo pueden ejecutarse cada 15 segundos por sesión.');
    $_SESSION['gyd_opt_last']=time();$sample=go_samples($db,$tables);
  }
  if(($_GET['export']??'')==='csv'){
    header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="gyd_revision_rendimiento.csv"');
    $h=fopen('php://output','wb');fwrite($h,"\xEF\xBB\xBF");
    fputcsv($h,['Tabla','Columnas sugeridas','Estado','Indice actual','Filas aproximadas','Datos bytes','Indices bytes']);
    foreach($rows as $r)fputcsv($h,array_map('go_csv_cell',[$r['table'],implode(', ',$r['cols']),$r['status'],$r['index_actual'],$r['filas_estimadas'],$r['datos_bytes'],$r['indice_bytes']]));
    fputcsv($h,[]);fputcsv($h,['Consulta','Estado','Plan estimado EXPLAIN']);
    foreach($explain as $r)fputcsv($h,array_map('go_csv_cell',[$r['label'],$r['status'],$r['detalle']]));
    fclose($h);exit;
  }
}catch(Throwable $e){error_log('G&D optimizacion: '.$e->getMessage());$error=$e instanceof DomainException?$e->getMessage():'No se pudo consultar el rendimiento. Revisa el log de PHP y la configuración MySQL.';}
$covered=count(array_filter($rows,static fn($r)=>$r['status']==='cubierto'));
$proposed=count(array_filter($rows,static fn($r)=>$r['status']==='recomendado'));
$unavailable=count($rows)-$covered-$proposed;
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Rendimiento del ERP · Farmacia G&amp;D</title>
<style>
:root{font-family:system-ui,-apple-system,'Segoe UI',sans-serif;color:#122946;background:#f2f6fb}*{box-sizing:border-box}body{margin:0}header{background:#123b66;color:white;padding:24px max(18px,calc((100vw - 1180px)/2))}header h1{font-size:clamp(23px,3vw,32px);margin:0 0 5px}header p{color:#d5e6f8;margin:0}a{color:#145a9f}.wrap{max-width:1220px;margin:auto;padding:24px 18px 60px}.card{background:#fff;border:1px solid #d7e2ef;border-radius:13px;margin:15px 0;padding:20px;box-shadow:0 3px 10px #163a6110}h2{font-size:19px;margin:0 0 11px}.muted{color:#5b6e83;font-size:13px;line-height:1.55}.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.stat{border:1px solid #d8e2ef;border-radius:10px;padding:14px}.stat span{font-size:12px;color:#5b6e83}.stat b{display:block;font-size:29px;margin-top:4px}.bar{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}.btn{background:#18598f;border:1px solid #18598f;color:white;padding:9px 14px;border-radius:8px;text-decoration:none;font-weight:650;font-size:13px;cursor:pointer}.btn.alt{background:#fff;color:#18598f}.table-scroll{overflow:auto}table{border-collapse:collapse;width:100%;min-width:750px;font-size:13px}th,td{padding:12px;text-align:left;border-bottom:1px solid #e5edf6}th{background:#f1f6fc}.pill{padding:4px 9px;border-radius:20px;font-weight:700;font-size:11px;background:#e6f0fa;color:#254d76;white-space:nowrap}.pill.cubierto{background:#e1f4e8;color:#12623c}.pill.recomendado{background:#fff0cf;color:#704900}.error{background:#fff0f0;color:#942435;border:1px solid #f3bec5;padding:14px;border-radius:10px}.note{background:#f1f7ff;border:1px solid #cfddf2;border-radius:8px;padding:12px;color:#274566;font-size:13px}code{white-space:nowrap;color:#254b71}.nav a{color:white;margin-right:12px}.align-r{text-align:right}@media(max-width:650px){.stats{grid-template-columns:1fr}header{padding:22px 15px}.card{padding:13px}}@media print{body{background:white}.noprint{display:none!important}.wrap{padding:0}.card{box-shadow:none;break-inside:avoid}@page{size:A4 landscape}}
</style></head><body><header><h1>Rendimiento y Optimización del ERP</h1><p>Farmacia G&amp;D · Evaluación técnica de consultas e índices · Base de pruebas</p><nav class="nav noprint" style="margin-top:12px"><a href="dashboard.php">Inicio</a><a href="erp_diagnostico.php">Centro de Diagnóstico</a></nav></header>
<main class="wrap"><section class="card"><h2>Diagnóstico de rendimiento</h2><p class="muted">Esta pantalla no cambia ventas, existencias, movimientos ni estructura MySQL. Verifica índices de consultas habituales e inspecciona planes estimados mediante EXPLAIN. Los tiempos de muestra son puntuales, no una medición de carga real.</p><div class="bar noprint"><a class="btn" href="?muestra=1">Medir consultas pequeñas</a><a class="btn alt" href="?export=csv">Exportar a Excel (CSV)</a><button class="btn alt" type="button" onclick="window.print()">Imprimir / PDF</button></div></section>
<?php if($error!==''):?><div class="error" role="alert"><?=go_h($error)?></div><?php endif;?>
<?php if($rows):?><section class="stats"><div class="stat"><span>Índices ya cubiertos</span><b><?=$covered?></b></div><div class="stat"><span>Índices a evaluar</span><b><?=$proposed?></b></div><div class="stat"><span>Esquema por revisar</span><b><?=$unavailable?></b></div></section>
<section class="card"><h2>Índices para reportes y cajas</h2><div class="note">«Recomendado» significa que no se detectó un índice con ese prefijo; no demuestra por sí solo que crearlo mejore todas las consultas. Revísalo con EXPLAIN y realiza una copia de seguridad antes de cualquier DDL.</div><div class="table-scroll"><table><thead><tr><th>Tabla</th><th>Campos</th><th>Estado</th><th>Filas aprox.*</th><th>Uso</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><strong><?=go_h($r['table'])?></strong></td><td><code><?=go_h(implode(', ',$r['cols']))?></code></td><td><span class="pill <?=go_h($r['status'])?>"><?=go_h($r['status'])?></span><?php if($r['index_actual']!==''):?><div class="muted"><?=go_h($r['index_actual'])?></div><?php endif;?></td><td><?=number_format($r['filas_estimadas'])?></td><td><?=go_h($r['desc'])?></td></tr><?php endforeach;?></tbody></table></div><p class="muted">*Filas estimadas por InnoDB; no son un conteo exacto. Los índices pueden encarecer escrituras y crear bloqueos durante su construcción. Se instalan solo mediante un procedimiento separado y voluntario.</p></section>
<section class="card"><h2>Planes de consultas (EXPLAIN)</h2><div class="table-scroll"><table><thead><tr><th>Consulta</th><th>Estado</th><th>Plan aproximado</th></tr></thead><tbody><?php foreach($explain as $e):?><tr><td><?=go_h($e['label'])?></td><td><?=go_h($e['status'])?></td><td><?=go_h($e['detalle'])?></td></tr><?php endforeach;?></tbody></table></div></section>
<?php if($sample):?><section class="card"><h2>Mediciones puntuales</h2><div class="table-scroll"><table><thead><tr><th>Consulta pequeña</th><th>Tiempo</th><th>Filas devueltas</th><th>Estado</th></tr></thead><tbody><?php foreach($sample as $m):?><tr><td><?=go_h($m['label'])?></td><td><?=$m['ms']===null?'Sin medición':number_format($m['ms'],2).' ms'?></td><td><?=(int)$m['filas']?></td><td><?=go_h($m['status'])?></td></tr><?php endforeach;?></tbody></table></div><p class="muted">Estas mediciones no reproducen usuarios simultáneos ni consultas completas de reportes, y no permiten prometer una mejora porcentual.</p></section><?php endif;?>
<?php endif;?><section class="card"><h2>Cómo aplicar las mejoras</h2><p class="muted">El paquete contiene un programa independiente que revisa índices existentes y propone sentencias SQL únicamente para los que falten. No se ejecutan automáticamente al abrir el panel ni al instalarlo. Antes de crear índices realiza respaldo, revisa el plan y programa una ventana sin actividad.</p><p class="muted">Otras oportunidades pendientes de medir con datos reales: búsquedas con <code>LIKE '%texto%'</code> del catálogo, consultas con agregados por sucursal y conexiones de múltiples cajeros. No se cambia su lógica hasta disponer de mediciones y pruebas de regresión.</p></section></main>
<script src="assets/js/gyd_excel_reportes.js" defer></script>

<?php if (!defined('GYD_HIDE_GLOBAL_NAV')): ?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=1">
<script>window.GYD_NAV_HOME='index.php';window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=1"></script>
<?php endif; ?>
</body></html>
