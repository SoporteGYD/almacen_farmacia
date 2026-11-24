<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/helpers/gyd_simulador_lab.php';
requireLogin();
$user=currentUser()??[];
if(!gyd_sim_role($user)){http_response_code(403);exit('Acceso reservado a Administración y Dirección.');}
$aclFile=__DIR__.'/../app/helpers/gyd_acl.php';
if(is_file($aclFile)){
    require_once $aclFile;
    try {if(function_exists('gyd_acl_puede')&&!gyd_acl_puede($user,'seguridad')){http_response_code(403);exit('Acceso denegado por permisos centrales.');}}
    catch(Throwable $e){error_log('Simulador ACL: '.$e->getMessage());http_response_code(403);exit('No se pudieron comprobar los permisos centrales.');}
}
header('Cache-Control: no-store, private');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
$ok=false;$error='';$runs=[];$details=[];$selected=null;
$labels=gyd_sim_scenarios();
try {
    $db=gyd_sim_connection();gyd_sim_guard($db);
    $ok=true;
    $runs=$db->query("SELECT run_key,iniciado_en,finalizado_en,estado,aprobadas,fallidas,no_ejecutadas FROM sim_runs ORDER BY iniciado_en DESC LIMIT 30")->fetchAll();
    $target=(string)($_GET['run']??($runs[0]['run_key']??''));
    if($target!=='' && preg_match('/^[a-f0-9]{24}$/',$target)){
        $stmt=$db->prepare('SELECT run_key,iniciado_en,finalizado_en,estado,aprobadas,fallidas,no_ejecutadas FROM sim_runs WHERE run_key=?');
        $stmt->execute([$target]);$selected=$stmt->fetch()?:null;
        if($selected){$stmt=$db->prepare('SELECT codigo,estado,detalle,trabajadores_json FROM sim_results WHERE run_key=? ORDER BY id');$stmt->execute([$target]);$details=$stmt->fetchAll();}
    }
    if(($_GET['export']??'')==='csv' && $selected){
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="simulador_gyd_'.(string)$selected['run_key'].'.csv"');
        $h=fopen('php://output','wb');fwrite($h,"\xEF\xBB\xBF");
        fputcsv($h,['Escenario','Estado','Resultado','Trabajadores']);
        foreach($details as $r){
            $cols=[$labels[$r['codigo']]??$r['codigo'],$r['estado'],$r['detalle'],$r['trabajadores_json']];
            $cols=array_map(static function($x){$x=(string)$x;return preg_match('/^[\s]*[=+@\-\t\r]/u',$x)?"'".$x:$x;},$cols);
            fputcsv($h,$cols);
        }
        fclose($h);exit;
    }
}catch(Throwable $e){error_log('Simulador G&D: '.$e->getMessage());$error='No se pudo acceder al laboratorio. Comprueba que esté creado e inicializado y que la configuración de MySQL sea correcta.';}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Simulador de Operaciones | G&amp;D</title>
<style>
:root{font:15px/1.5 system-ui,'Segoe UI',sans-serif;color:#193252;background:#f2f6fc}*{box-sizing:border-box}body{margin:0}a{color:#165eaa}.header{background:#153d69;color:#fff;padding:22px max(18px,calc((100vw - 1220px)/2));display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}.header a{color:#fff}.header h1{margin:0;font-size:25px}.header small{color:#cadff4}.main{width:min(1220px,95%);margin:28px auto 60px}.panel{border:1px solid #d8e2ee;border-radius:14px;background:#fff;padding:22px;margin:15px 0;box-shadow:0 3px 12px #1d36540b}.notice{background:#fff5df;border:1px solid #edd4a0;padding:13px;border-radius:9px;color:#71510d}.error{background:#ffeeed;border:1px solid #eecccc;padding:13px;border-radius:9px;color:#912f2f}.muted{color:#60748c}.stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.stat{padding:17px;background:#f4f8fe;border-radius:12px;border:1px solid #d9e5f1}.stat strong{font-size:30px;display:block}.stat small{color:#657890}.table{overflow:auto}table{border-collapse:collapse;width:100%;min-width:740px}th,td{text-align:left;vertical-align:top;padding:12px;border-bottom:1px solid #e4ebf2}th{background:#f3f7fc}.tag{font-size:12px;font-weight:750;border-radius:100px;padding:5px 11px;white-space:nowrap}.correcto{background:#dff5e8;color:#0a6b47}.fallido{background:#fce6e8;color:#a52032}.no_ejecutada,.running{background:#edf0f5;color:#54667a}.actions{display:flex;flex-wrap:wrap;gap:9px}.btn{background:#155ea8;color:#fff;text-decoration:none;padding:10px 13px;border-radius:8px;font-weight:650;display:inline-block}.btn.light{background:#e8f1fd;color:#18558d}.code{padding:12px;background:#edf3fb;border-radius:7px;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere}h2{font-size:20px;margin:0 0 12px}p{margin:8px 0 12px}@media(max-width:750px){.stats{grid-template-columns:1fr}}@media print{.header{background:#fff;color:#000}.header a{color:#333}.noprint{display:none}.main{width:100%}.panel{box-shadow:none}}
</style></head><body><header class="header"><div><h1>Simulador de Operaciones</h1><small>Farmacia G&amp;D · Pruebas transaccionales en laboratorio aislado</small></div><nav class="noprint"><a href="dashboard.php">Inicio</a> · <a href="erp_pruebas_integrales.php">Pruebas integrales</a></nav></header>
<main class="main"><div class="notice"><strong>Seguridad del laboratorio.</strong> Estas pruebas usan exclusivamente la base <code>gyd_lab_simulador</code>, con datos ficticios. No ejecutan las pantallas ni las API operativas del POS. Un resultado correcto no significa que el ERP completo esté validado para producción.</div>
<?php if($error):?><div class="error" role="alert"><?=gyd_sim_h($error)?></div><?php endif;?>
<section class="panel"><h2>Ejecutar simulación</h2><p>Las transacciones que escriben datos solo se inician desde una terminal autorizada, nunca desde esta página.</p><p>En phpMyAdmin crea primero una base <strong>vacía</strong> llamada <code>gyd_lab_simulador</code>. Después ejecuta los comandos en PowerShell:</p>
<div class="code">&amp; "C:\xampp\php\php.exe" "C:\xampp\htdocs\almacen-farmacia2\tools\gyd_simulador_operaciones_v21.php" --setup</div>
<div class="code">&amp; "C:\xampp\php\php.exe" "C:\xampp\htdocs\almacen-farmacia2\tools\gyd_simulador_operaciones_v21.php" --run</div>
<p class="muted">La conexión de laboratorio usa, de forma predeterminada, 127.0.0.1:3307, usuario root y contraseña vacía, sin cambiar la configuración de <code>prueba</code>. Si tu servidor utiliza otras credenciales, configúralas mediante las variables de entorno GYD_SIM_HOST, GYD_SIM_PORT, GYD_SIM_USER y GYD_SIM_PASS antes de ejecutar.</p></section>
<?php if($ok):?>
<section class="panel"><h2>Resultados recientes</h2>
<?php if($selected):?><div class="stats"><div class="stat"><small>Pruebas correctas</small><strong><?=intval($selected['aprobadas'])?></strong></div><div class="stat"><small>Fallidas</small><strong><?=intval($selected['fallidas'])?></strong></div><div class="stat"><small>No ejecutadas</small><strong><?=intval($selected['no_ejecutadas'])?></strong></div></div>
<p class="muted">Ejecución <?=gyd_sim_h($selected['run_key'])?> · Inicio <?=gyd_sim_h($selected['iniciado_en'])?> · Estado <?=gyd_sim_h($selected['estado'])?></p>
<div class="actions noprint"><a class="btn" href="?run=<?=gyd_sim_h($selected['run_key'])?>&amp;export=csv">Exportar CSV</a><a class="btn light" href="#" onclick="window.print();return false">Imprimir / PDF</a></div>
<div class="table"><table><thead><tr><th>Prueba</th><th>Estado</th><th>Resultado observado en laboratorio</th></tr></thead><tbody>
<?php foreach($details as $r):?><tr><td><strong><?=gyd_sim_h($labels[$r['codigo']]??$r['codigo'])?></strong></td><td><span class="tag <?=in_array($r['estado'],['correcto','fallido','no_ejecutada'],true)?$r['estado']:'running'?>"><?=gyd_sim_h($r['estado'])?></span></td><td><?=gyd_sim_h($r['detalle'])?></td></tr><?php endforeach;?></tbody></table></div>
<?php else:?><p>Todavía no hay simulaciones ejecutadas. Inicializa el laboratorio y utiliza <code>--run</code>.</p><?php endif;?></section>
<section class="panel noprint"><h2>Historial</h2><div class="table"><table><thead><tr><th>Fecha</th><th>Ejecución</th><th>Aprobadas</th><th>Fallidas</th><th>No ejecutadas</th></tr></thead><tbody>
<?php foreach($runs as $r):?><tr><td><?=gyd_sim_h($r['iniciado_en'])?></td><td><a href="?run=<?=gyd_sim_h($r['run_key'])?>"><?=gyd_sim_h($r['run_key'])?></a></td><td><?=intval($r['aprobadas'])?></td><td><?=intval($r['fallidas'])?></td><td><?=intval($r['no_ejecutadas'])?></td></tr><?php endforeach;?></tbody></table></div></section>
<?php endif;?>
<section class="panel"><h2>Alcance de esta batería</h2><p>Se prueban restricciones UNIQUE y operaciones transaccionales con dos procesos simultáneos para ventas, existencias, retiros, cortes, recepciones y devoluciones en <em>tablas ficticias exclusivas del laboratorio</em>. Las autorizaciones por contraseña, las interfaces, el proceso completo de cobro y la restauración de respaldos <strong>requieren pruebas de integración separadas</strong>.</p></section></main>
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
