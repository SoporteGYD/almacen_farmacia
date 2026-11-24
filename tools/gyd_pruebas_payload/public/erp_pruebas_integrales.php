<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/gyd_pruebas_integrales.php';
requireLogin();
$user=currentUser()??[];
if(!gpi_role($user)){http_response_code(403);exit('Acceso reservado a Administración y Dirección.');}
$aclPath=__DIR__.'/../app/helpers/gyd_acl.php';
if(is_file($aclPath)){
    require_once $aclPath;
    try{if(function_exists('gyd_acl_puede') && !gyd_acl_puede($user,'seguridad')){http_response_code(403);exit('Acceso restringido por permisos centrales.');}}
    catch(Throwable $e){error_log('G&D permisos de pruebas: '.$e->getMessage());http_response_code(403);exit('No se pudieron verificar los permisos centrales.');}
}
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: SAMEORIGIN');
if(!isset($_SESSION['gpi_csrf']))$_SESSION['gpi_csrf']=bin2hex(random_bytes(32));
$token=(string)$_SESSION['gpi_csrf'];
$error='';$message='';$run=null;$runs=[];$manual=[];$runId=filter_var($_GET['run']??0,FILTER_VALIDATE_INT);
if($runId===false||$runId<0)$runId=0;
$scenarios=gpi_scenarios();
try {
    $db=(new Database())->connect();gpi_guard($db);
    $tables=['gyd_pruebas_integrales_runs','gyd_pruebas_integrales_manual'];
    foreach($tables as $t){$s=$db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->execute([$t]);if((int)$s->fetchColumn()!==1)throw new DomainException('Falta la tabla del Centro de Pruebas: '.$t.'. Ejecuta el instalador.');}
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!hash_equals($token,(string)($_POST['csrf']??'')))throw new DomainException('Formulario caducado. Actualiza la página.');
        $op=(string)($_POST['accion']??'');
        if($op==='automatica'){
            if(time()-(int)($_SESSION['gpi_ultimo']??0)<15)throw new DomainException('Acabas de ejecutar una prueba. Vuelve a intentarlo después.');
            $_SESSION['gpi_ultimo']=time();
            $run=gpi_run($db,dirname(__DIR__));
            $json=json_encode($run,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            $s=$db->prepare('INSERT INTO gyd_pruebas_integrales_runs(usuario_id,informe_json,correctas,revision,fallidas,no_disponibles) VALUES(?,?,?,?,?,?)');
            $s->execute([(int)$user['id'],$json,$run['resumen']['correcto'],$run['resumen']['revisar'],$run['resumen']['fallido'],$run['resumen']['no_disponible']]);
            $runId=(int)$db->lastInsertId();$message='Comprobación de solo lectura terminada y guardada.';
        }elseif($op==='manual'){
            $code=(string)($_POST['escenario']??'');$status=(string)($_POST['resultado']??'');
            $note=trim((string)($_POST['observacion']??''));
            if(!isset($scenarios[$code]))throw new DomainException('Escenario desconocido.');
            if(!in_array($status,['correcto','fallido','pendiente'],true))throw new DomainException('Resultado inválido.');
            if(empty($_POST['confirmar_laboratorio']))throw new DomainException('Confirma que la prueba se realizó en un laboratorio aislado.');
            if(mb_strlen($note)<5 || mb_strlen($note)>500)throw new DomainException('Registra una observación entre 5 y 500 caracteres, sin contraseñas ni datos personales.');
            $s=$db->prepare('INSERT INTO gyd_pruebas_integrales_manual(usuario_id,escenario,resultado,observacion) VALUES(?,?,?,?)');
            $s->execute([(int)$user['id'],$code,$status,$note]);$message='Resultado manual documentado. No se modificaron operaciones del ERP.';
        }else throw new DomainException('Acción no permitida.');
    }
    if($run===null && $runId>0){$s=$db->prepare('SELECT informe_json FROM gyd_pruebas_integrales_runs WHERE id=?');$s->execute([$runId]);$raw=$s->fetchColumn();if($raw===false)throw new DomainException('No existe el informe solicitado.');$run=json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR);}
    if($run===null){$last=$db->query('SELECT id,informe_json FROM gyd_pruebas_integrales_runs ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);if($last){$runId=(int)$last['id'];$run=json_decode($last['informe_json'],true,512,JSON_THROW_ON_ERROR);}}
    $runs=gpi_rows($db,'SELECT id,creado_en,correctas,revision,fallidas,no_disponibles FROM gyd_pruebas_integrales_runs ORDER BY id DESC LIMIT 12');
    $saved=gpi_rows($db,'SELECT id,escenario,resultado,observacion,creado_en FROM gyd_pruebas_integrales_manual ORDER BY id DESC LIMIT 150');
    foreach($saved as $v)if(!isset($manual[$v['escenario']]))$manual[$v['escenario']]=$v;
    if(isset($_GET['export'])&&$_GET['export']==='csv'){
        if($run===null||$runId<1)throw new DomainException('Primero ejecuta una revisión.');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="pruebas_integrales_gyd_'.(int)$runId.'.csv"');
        $h=fopen('php://output','wb');fwrite($h,"\xEF\xBB\xBF");
        fputcsv($h,['Área','Prueba','Estado','Detalle','Acción']);
        foreach(($run['resultados']??[]) as $v)fputcsv($h,array_map('gpi_cell',[$v['area']??'',$v['titulo']??'',$v['estado']??'',$v['detalle']??'',$v['accion']??'']));
        fputcsv($h,[]);fputcsv($h,['Prueba manual','Resultado registrado','Observación','Fecha']);
        foreach($scenarios as $key=>$v){$saved=$manual[$key]??[];fputcsv($h,array_map('gpi_cell',[$v[0],$saved['resultado']??'pendiente',$saved['observacion']??'No ejecutada',$saved['creado_en']??'']));}
        fclose($h);exit;
    }
}catch(DomainException $e){$error=$e->getMessage();}
catch(Throwable $e){error_log('Pruebas integrales G&D: '.$e->getMessage());$error='No se pudo completar la consulta. Consulta el log de PHP y revisa las tablas.';}
$r=$run['resumen']??['correcto'=>0,'revisar'=>0,'fallido'=>0,'no_disponible'=>0];
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Centro de Pruebas Integrales | G&amp;D</title><style>
:root{font:15px/1.5 system-ui,-apple-system,'Segoe UI',sans-serif;color:#142c48;background:#f2f6fc}*{box-sizing:border-box}body{margin:0}a{color:#165fa4}.top{background:#153f6e;color:white;padding:24px max(18px,calc((100vw - 1250px)/2));display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap}.top h1{font-size:26px;margin:0}.top small{color:#d1e8ff}.top a{color:#fff}.wrap{width:min(1250px,95%);margin:28px auto 60px}.panel{background:white;border:1px solid #d5e0ec;border-radius:15px;padding:21px;margin-bottom:17px;box-shadow:0 5px 18px #10284508}h2{margin:0 0 8px;font-size:20px}p{margin:8px 0;color:#50657c}.danger{background:#fff1ee;color:#922b25;border:1px solid #efc6c1;padding:13px 15px;border-radius:10px}.notice{background:#edf6ff;color:#164d80;border:1px solid #c4dff7;padding:12px 15px;border-radius:10px;margin-bottom:16px}.warning{background:#fff7e6;border:1px solid #f4dfb4;color:#714d12;padding:13px 16px;border-radius:10px;margin-bottom:15px}.btn{border:1px solid #1861a9;background:#1760ab;color:white;font:inherit;padding:10px 16px;border-radius:9px;cursor:pointer;text-decoration:none;display:inline-block;font-weight:650}.btn.alt{background:white;color:#165d9a;border-color:#b8d0e8}.btn:focus-visible,a:focus-visible{outline:3px solid #f8c746;outline-offset:2px}.buttons{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:17px}.stat{border:1px solid #d5e0ec;border-radius:13px;padding:17px;background:white}.stat strong{display:block;font-size:31px}.stat span,.small{font-size:12px;color:#64748b}.table{overflow:auto}table{width:100%;border-collapse:collapse;min-width:750px}th{text-align:left;background:#f4f8fc;color:#52657a;font-size:13px}td,th{padding:11px;border-bottom:1px solid #e6ebf2;vertical-align:top}.tag{display:inline-block;white-space:nowrap;border-radius:50px;padding:4px 10px;font-size:12px;font-weight:750}.correcto{color:#096944;background:#e1f5ea}.revisar{color:#85510a;background:#fff0d6}.fallido{color:#a32732;background:#ffe5e7}.no_disponible,.pendiente{color:#53667c;background:#e8edf3}.scenario{border:1px solid #e0e9f3;border-radius:12px;padding:15px;margin:12px 0}.scenario h3{margin:0 0 7px;font-size:16px}.scenario .hint{font-size:13px}.formgrid{display:grid;grid-template-columns:170px 1fr auto;gap:10px;align-items:end;margin-top:14px}label{display:block;font-weight:650;font-size:13px;margin-bottom:4px}select,textarea{font:inherit;padding:10px;border:1px solid #b6cbe0;border-radius:9px;width:100%;background:white}textarea{min-height:44px;resize:vertical}.confirm{display:flex;gap:9px;align-items:flex-start;color:#425771;font-size:13px;margin:10px 0}.confirm input{margin-top:3px}.history{font-size:13px}@media(max-width:900px){.formgrid{grid-template-columns:1fr}.stats{grid-template-columns:repeat(2,1fr)}}@media print{.noprint{display:none!important}.panel{box-shadow:none;break-inside:avoid}.top{background:white;color:black}.top small{color:black}.wrap{width:100%}body{background:white}}
</style></head><body>
<header class="top"><div><h1>Centro de Pruebas Integrales</h1><small>Farmacia G&amp;D · Verificación de calidad y seguridad operativa</small></div><nav class="noprint"><a href="dashboard.php">Inicio</a> · <a href="erp_diagnostico.php">Diagnóstico</a> · <a href="erp_rendimiento_sistema.php">Rendimiento</a></nav></header>
<main class="wrap">
<div class="warning"><strong>Entorno de pruebas:</strong> las comprobaciones automáticas solo leen registros de «prueba». Las pruebas guiadas que generan ventas, retiros o cortes deben realizarse fuera de esta base, en un laboratorio aislado. Registrar un resultado manual NO ejecuta la operación.</div>
<?php if($error):?><div class="danger" role="alert"><?=gpi_h($error)?></div><?php endif;?><?php if($message):?><div class="notice" role="status"><?=gpi_h($message)?></div><?php endif;?>
<section class="panel noprint"><h2>Comprobación automática</h2><p>Valida integridad de pagos, turnos, existencias, facturas, devoluciones, índices e infraestructura transaccional. Algunas verificaciones usan muestras acotadas y no sustituyen pruebas reales de concurrencia.</p><div class="buttons"><form method="post"><input type="hidden" name="csrf" value="<?=gpi_h($token)?>"><input type="hidden" name="accion" value="automatica"><button class="btn" type="submit">Ejecutar comprobaciones</button></form><?php if($run && $runId):?><a class="btn alt" href="?run=<?=$runId?>&amp;export=csv">Exportar CSV</a><button class="btn alt" type="button" onclick="window.print()">Imprimir / PDF</button><?php endif;?></div></section>
<?php if($run):?><div class="small" style="margin-bottom:10px">Último informe: <?=gpi_h($run['fecha']??'')?> · Base «prueba» · Consulta de solo lectura</div><section class="stats"><?php foreach(['correcto'=>'Correctas','revisar'=>'Revisar','fallido'=>'Fallidas','no_disponible'=>'No evaluadas'] as $k=>$v):?><div class="stat"><span><?=gpi_h($v)?></span><strong><?=intval($r[$k]??0)?></strong></div><?php endforeach;?></section><section class="panel"><h2>Resultados automáticos</h2><div class="table"><table><thead><tr><th>Área</th><th>Comprobación</th><th>Resultado</th><th>Detalle / Acción</th></tr></thead><tbody><?php foreach(($run['resultados']??[]) as $v):?><tr><td><strong><?=gpi_h($v['area']??'')?></strong></td><td><?=gpi_h($v['titulo']??'')?></td><td><span class="tag <?=gpi_h($v['estado']??'no_disponible')?>"><?=gpi_h($v['estado']??'no_disponible')?></span></td><td><?=gpi_h($v['detalle']??'')?><?php if(!empty($v['accion'])):?><p class="small"><strong>Acción:</strong> <?=gpi_h($v['accion'])?></p><?php endif;?></td></tr><?php endforeach;?></tbody></table></div></section><?php else:?><section class="panel"><p>No existen resultados automáticos todavía. Usa el botón «Ejecutar comprobaciones».</p></section><?php endif;?>
<section class="panel"><h2>Escenarios guiados — laboratorio aislado</h2><p>Estos escenarios requieren dos sesiones o una base restaurada de laboratorio. Se guardan sus resultados como evidencia manual; nunca se ejecutan desde este panel.</p><?php foreach($scenarios as $code=>$s):$last=$manual[$code]??null;$status=$last['resultado']??'pendiente';?><article class="scenario"><h3><?=gpi_h($s[0])?> <span class="tag <?=gpi_h($status)?>"><?=gpi_h($status)?></span></h3><p class="hint"><strong>Procedimiento:</strong> <?=gpi_h($s[1])?></p><p class="hint"><strong>Resultado esperado:</strong> <?=gpi_h($s[2])?></p><?php if($last):?><p class="small">Última evaluación: <?=gpi_h($last['creado_en'])?> · <?=gpi_h($last['observacion'])?></p><?php endif;?><form class="noprint" method="post"><input type="hidden" name="csrf" value="<?=gpi_h($token)?>"><input type="hidden" name="accion" value="manual"><input type="hidden" name="escenario" value="<?=gpi_h($code)?>"><div class="formgrid"><div><label>Resultado real</label><select name="resultado"><option value="pendiente">Pendiente</option><option value="correcto">Correcto</option><option value="fallido">Fallido</option></select></div><div><label>Observación / evidencia (sin datos personales)</label><textarea name="observacion" required minlength="5" maxlength="500" placeholder="Ej. Probado en dos navegadores del laboratorio; sin doble descuento."></textarea></div><div><button class="btn alt" type="submit">Guardar resultado</button></div></div><label class="confirm"><input type="checkbox" name="confirmar_laboratorio" value="1" required>Confirmo que esta prueba se realizó únicamente en una base aislada de laboratorio; no en «prueba» ni producción.</label></form></article><?php endforeach;?></section>
<section class="panel noprint"><h2>Historial de verificaciones</h2><div class="table"><table class="history"><thead><tr><th>Fecha</th><th>Correctas</th><th>Revisar</th><th>Fallidas</th><th>No evaluadas</th><th>Informe</th></tr></thead><tbody><?php foreach($runs as $v):?><tr><td><?=gpi_h($v['creado_en'])?></td><td><?=intval($v['correctas'])?></td><td><?=intval($v['revision'])?></td><td><?=intval($v['fallidas'])?></td><td><?=intval($v['no_disponibles'])?></td><td><a href="?run=<?=(int)$v['id']?>">Abrir</a></td></tr><?php endforeach;?></tbody></table></div></section><p class="small">El estado «correcto» solo aplica a la comprobación concreta o muestra indicada. El panel no acredita por sí solo la preparación para producción. Solo almacena resultados e informes, no modifica movimientos de la farmacia.</p>
</main></body></html>
