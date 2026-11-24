<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/gyd_validacion_operativa.php';
requireLogin();
$usuario=currentUser()??[];
if(!gvo_authorized($usuario)){http_response_code(403);exit('Acceso reservado a Administración y Dirección.');}
if(is_file(__DIR__.'/../app/helpers/gyd_acl.php')){
    require_once __DIR__.'/../app/helpers/gyd_acl.php';
    try{if(function_exists('gyd_acl_puede')&&!gyd_acl_puede($usuario,'seguridad')){http_response_code(403);exit('Acceso restringido por permisos.');}}
    catch(Throwable $e){error_log('Validación operativa: ACL '.$e->getMessage());http_response_code(403);exit('No fue posible comprobar el permiso central.');}
}
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
if(!isset($_SESSION['gvo_csrf']))$_SESSION['gvo_csrf']=bin2hex(random_bytes(32));
$csrf=(string)$_SESSION['gvo_csrf'];$root=dirname(__DIR__);
$error='';$info='';$scan=null;$scenarios=gvo_scenarios();
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(!hash_equals($csrf,(string)($_POST['csrf']??'')))throw new DomainException('Token vencido. Actualiza la pantalla.');
        if((int)($_SESSION['gvo_last_save']??0)>time()-3)throw new DomainException('Operación repetida demasiado rápido.');
        gvo_save($root,$usuario,(string)($_POST['escenario']??''),(string)($_POST['resultado']??''),(string)($_POST['observacion']??''),isset($_POST['laboratorio']));
        $_SESSION['gvo_last_save']=time();$info='Resultado manual documentado. Esto no supone una prueba automática.';
    }catch(Throwable $e){$error=$e->getMessage();}
}
if(isset($_GET['scan'])){
    try{$db=(new Database())->connect();$scan=gvo_scan($db,$usuario,$root);}catch(Throwable $e){error_log('Validación operativa: '.$e->getMessage());$error='No se pudo ejecutar la revisión. Comprueba conexión y esquema «prueba»; revisa el log PHP.';}
}
$hist=gvo_history($root);
if(($_GET['export']??'')==='csv'&&$scan){
    header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="validacion_operativa_gyd.csv"');
    $f=fopen('php://output','wb');fwrite($f,"\xEF\xBB\xBF");fputcsv($f,['Área','Comprobación','Estado','Detalle']);
    foreach($scan['resultados'] as $r){$values=[$r['area'],$r['titulo'],$r['estado'],$r['detalle']];$values=array_map(static fn($v)=>preg_match('/^\s*[=+@\-\t\r]/u',(string)$v)?"'".$v:$v,$values);fputcsv($f,$values);}
    fclose($f);exit;
}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Centro de Validación Operativa | G&amp;D</title>
<style>
:root{font:15px/1.5 system-ui,'Segoe UI',sans-serif;color:#173352;background:#f2f5fa}*{box-sizing:border-box}body{margin:0}a{color:#185c9e}.head{background:#153c67;color:white;padding:20px max(16px,calc((100vw - 1180px)/2));display:flex;gap:14px;align-items:center;justify-content:space-between;flex-wrap:wrap}.head h1{margin:0;font-size:25px}.head small{color:#d2e1f2}.head a{color:white}.main{width:min(1180px,95%);margin:24px auto 80px}.panel{background:white;border:1px solid #dbe5f0;border-radius:14px;padding:20px;margin-bottom:16px;box-shadow:0 5px 14px #1f365408}.notice{background:#fff5db;border:1px solid #efdaa7;border-radius:10px;padding:14px;color:#715011}.alert{background:#ffe9e7;border:1px solid #f1c4be;color:#8b2020;padding:12px;border-radius:9px;margin:8px 0}.ok{background:#e8f6ee;border:1px solid #bfe6cd;color:#215d3b;padding:12px;border-radius:9px;margin:8px 0}.muted{color:#667b92}.row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.btn{background:#155dae;color:white;padding:10px 13px;border-radius:8px;border:none;cursor:pointer;text-decoration:none;display:inline-block;font-weight:650}.btn.alt{background:#e8f1fd;color:#19588c}.btn:disabled{opacity:.55;cursor:wait}h2{font-size:19px;margin:0 0 12px}h3{font-size:16px;margin:0 0 6px}.counts{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.count{background:#f2f7fe;border:1px solid #dce7f3;border-radius:11px;padding:15px}.count strong{display:block;font-size:28px}.count small{color:#536e8c}.table{overflow:auto}table{width:100%;border-collapse:collapse;min-width:690px}th,td{padding:11px;text-align:left;border-bottom:1px solid #e3e9f1;vertical-align:top}th{background:#f2f6fb}.tag{border-radius:100px;padding:5px 10px;display:inline-block;font-size:12px;font-weight:700;white-space:nowrap}.correcto,.aprobado{background:#e0f5e9;color:#12703e}.fallido{background:#ffe4e6;color:#a71d33}.revisar{background:#fff0cd;color:#946000}.no_disponible,.bloqueado{background:#edf0f4;color:#5c6778}.scenarios{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.scenario{border:1px solid #dce6f0;border-radius:10px;padding:15px}.scenario p{margin:3px 0 10px}label{display:block;font-size:13px;font-weight:700;margin:8px 0 4px}select,textarea{width:100%;padding:10px;border:1px solid #b9cbdf;border-radius:8px;background:#fff;color:#16334f}textarea{min-height:66px;resize:vertical}.checkbox{display:flex;gap:8px;align-items:flex-start;font-weight:500}.checkbox input{margin-top:4px}.statusline{padding:12px;border:1px solid #dce8f4;border-radius:9px;margin:8px 0}.noprint{}@media(max-width:800px){.scenarios{grid-template-columns:1fr}.counts{grid-template-columns:repeat(2,1fr)}}@media print{.noprint{display:none!important}.head{background:white;color:#222}.head a{color:#222}.main{width:100%}.panel{box-shadow:none}}
</style></head><body><header class="head"><div><h1>Centro de Validación Operativa</h1><small>Farmacia G&amp;D · Integración real de lectura y escenarios de laboratorio</small></div><nav class="noprint"><a href="dashboard.php">Inicio</a> · <a href="erp_pruebas_integrales.php">Pruebas integrales</a> · <a href="erp_simulador_operaciones.php">Simulador</a></nav></header><main class="main">
<div class="notice"><b>Alcance y seguridad.</b> Este centro no cobra ventas, no retira efectivo y no cierra cajas. Ejecuta consultas de lectura contra «prueba» y llamadas GET reales a la API del POS. Las operaciones que modifican datos se prueban manualmente en un clon aislado del ERP, nunca desde este panel. Una prueba manual documentada no equivale a aprobación automática.</div>
<?php if($error):?><div class="alert" role="alert"><?=gvo_h($error)?></div><?php endif;?><?php if($info):?><div class="ok" role="status"><?=gvo_h($info)?></div><?php endif;?>
<section class="panel"><h2>Comprobaciones del sistema real</h2><p class="muted">Consulta el modelo POS y verifica consistencia en registros existentes. No realiza escrituras en tablas operativas.</p><div class="row noprint"><a class="btn" href="?scan=1">Ejecutar comprobaciones</a><?php if($scan):?><a class="btn alt" href="?scan=1&amp;export=csv">Exportar CSV</a><button class="btn alt" onclick="window.print()">Imprimir / PDF</button><?php endif;?></div>
<?php if($scan):?><p class="muted">Ejecución <?=gvo_h($scan['fecha'])?></p><div class="counts"><?php foreach(['correcto'=>'Correctas','revisar'=>'Revisar','fallido'=>'Fallidas','no_disponible'=>'Sin evaluar'] as $k=>$title):?><div class="count"><small><?=gvo_h($title)?></small><strong><?=intval($scan['resumen'][$k])?></strong></div><?php endforeach;?></div><div class="table"><table><thead><tr><th>Área</th><th>Comprobación</th><th>Estado</th><th>Observación</th></tr></thead><tbody><?php foreach($scan['resultados'] as $r):?><tr><td><?=gvo_h($r['area'])?></td><td><b><?=gvo_h($r['titulo'])?></b></td><td><span class="tag <?=gvo_h($r['estado'])?>"><?=gvo_h($r['estado'])?></span></td><td><?=gvo_h($r['detalle'])?></td></tr><?php endforeach;?></tbody></table></div><?php else:?><p class="muted">Aún no has ejecutado la comprobación de esta sesión.</p><?php endif;?></section>
<section class="panel"><h2>API POS real — comprobación de lectura</h2><p class="muted">Usa tu sesión actual para llamar a <code>pos_api.php?action=sucursales</code> y, si hay una caja autorizada, <code>pos_api.php?action=estado</code>. Solo GET; no ejecuta apertura, cobro ni corte.</p><button id="gvoTestApi" class="btn noprint" type="button">Probar API POS</button><div id="gvoApiResults" aria-live="polite"><p class="muted">Pendiente de ejecutar.</p></div></section>
<section class="panel"><h2>Pruebas operativas en laboratorio aislado</h2><p class="muted">Abre el módulo real en una <b>copia del proyecto con base propia</b>, ejecuta los pasos del escenario y guarda únicamente la observación; no pegues contraseñas, nombres de clientes ni tokens de sesión.</p><div class="scenarios"><?php foreach($scenarios as $code=>$parts):?><article class="scenario"><h3><?=gvo_h($parts[0])?> · <?=gvo_h($parts[1])?></h3><p class="muted"><b>Resultado esperado:</b> <?=gvo_h($parts[2])?></p><form method="post" class="noprint"><input type="hidden" name="csrf" value="<?=gvo_h($csrf)?>"><input type="hidden" name="escenario" value="<?=gvo_h($code)?>"><label>Resultado observado</label><select name="resultado" required><option value="">Seleccionar</option><option value="aprobado">Aprobado manualmente</option><option value="fallido">Fallido</option><option value="bloqueado">No se pudo ejecutar</option></select><label>Observación / evidencia (sin datos sensibles)</label><textarea maxlength="500" minlength="12" required name="observacion" placeholder="Ej.: El segundo intento devolvió el folio original y el inventario no volvió a descontarse."></textarea><label class="checkbox"><input type="checkbox" name="laboratorio" value="1" required> Confirmo que utilicé una copia aislada, sin acceder a «prueba» ni producción.</label><button class="btn" type="submit">Guardar observación</button></form></article><?php endforeach;?></div></section>
<section class="panel"><h2>Historial de evidencia manual</h2><?php if(!$hist):?><p class="muted">Todavía no hay resultados documentados.</p><?php else:?><div class="table"><table><thead><tr><th>Fecha</th><th>Escenario</th><th>Resultado declarado</th><th>Responsable</th><th>Evidencia</th></tr></thead><tbody><?php foreach($hist as $r):?><tr><td><?=gvo_h($r['fecha']??'')?></td><td><?=gvo_h($scenarios[(string)($r['escenario']??'')][1]??($r['escenario']??''))?></td><td><?=gvo_h(gvo_result_label((string)($r['resultado']??'')))?></td><td>#<?=intval($r['usuario_id']??0)?></td><td><?=gvo_h($r['observacion']??'')?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
</main><script>
'use strict';
const b=document.getElementById('gvoTestApi');const target=document.getElementById('gvoApiResults');
function note(heading,detail,ok){const box=document.createElement('div');box.className='statusline';const bold=document.createElement('strong');bold.textContent=heading+(ok?' — correcto':' — revisar');box.append(bold);const p=document.createElement('p');p.textContent=detail;box.append(p);target.append(box);}
async function getJson(params){const res=await fetch('pos_api.php?'+new URLSearchParams(params),{method:'GET',credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});const txt=await res.text();let body;try{body=JSON.parse(txt);}catch{throw new Error('HTTP '+res.status+'; respuesta no JSON.');}if(!res.ok || body.error)throw new Error('HTTP '+res.status+': '+String(body.error??'Respuesta no válida').slice(0,160));return body;}
b.addEventListener('click',async()=>{b.disabled=true;target.textContent='';try{const x=await getJson({action:'sucursales'});if(!Array.isArray(x.sucursales))throw new Error('No se recibió la lista de sucursales.');note('API de sucursales',x.sucursales.length+' cajas accesibles desde la sesión actual.',true);if(x.sucursales.length){const caja=Number(x.sucursales[0].caja_id);if(!Number.isInteger(caja)||caja<1)throw new Error('Identificador de caja inválido.');try{const y=await getJson({action:'estado',caja_id:String(caja)});note('API de estado de caja',('turno' in y && Array.isArray(y.ventas))?'Respondió con turno y últimas ventas de la caja.':'No se encontró la estructura esperada de turno y ventas.',('turno' in y && Array.isArray(y.ventas)));}catch(e){note('API de estado de caja',String(e.message),false);}}else note('API de estado de caja','Sin cajas autorizadas para verificar el estado.',false);}catch(e){note('API de sucursales',String(e.message),false);}finally{b.disabled=false;}});
</script>
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
