<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/gyd_diagnostico.php';
requireLogin();
$usuario=currentUser()??[];
if(!gdiag_role($usuario)){http_response_code(403);exit('Acceso reservado a Administración y Dirección.');}
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
if(empty($_SESSION['gyd_diagnostico_csrf']))$_SESSION['gyd_diagnostico_csrf']=bin2hex(random_bytes(32));
$csrf=(string)$_SESSION['gyd_diagnostico_csrf'];
$mensaje='';$error='';$informe=null;$historial=[];$db=null;$id=filter_var($_GET['id']??0,FILTER_VALIDATE_INT);
if($id===false||$id<0)$id=0;
try {
 $db=(new Database())->connect();
 if((string)gdiag_one($db,'SELECT DATABASE()')!=='prueba')throw new DomainException('Este panel está habilitado únicamente para la base de pruebas «prueba».');
 if(!gdiag_table($db,'gyd_diagnosticos'))throw new DomainException('Falta la tabla de historial del diagnóstico. Ejecuta el instalador del módulo.');
 if($_SERVER['REQUEST_METHOD']==='POST'){
   if(!hash_equals($csrf,(string)($_POST['csrf']??'')))throw new DomainException('Sesión del formulario caducada. Recarga la página.');
   if(($_POST['accion']??'')!=='diagnosticar')throw new DomainException('Operación no válida.');
   if((time()-(int)($_SESSION['gyd_diag_ultimo']??0))<10)throw new DomainException('Ya se ejecutó una verificación hace unos segundos.');
   $_SESSION['gyd_diag_ultimo']=time();
   $informe=gdiag_run($db,dirname(__DIR__));
   $summary=$informe['resumen'];
   $statement=$db->prepare('INSERT INTO gyd_diagnosticos(usuario_id,entorno,ok_count,atencion_count,problema_count,no_disponible_count,informe_json) VALUES(?,?,?,?,?,?,?)');
   $statement->execute([(int)$usuario['id'],'prueba',$summary['ok'],$summary['atencion'],$summary['problema'],$summary['no_disponible'],json_encode($informe,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
   $id=(int)$db->lastInsertId();$mensaje='Diagnóstico realizado y guardado en el historial.';
 }
 if($id>0 && $informe===null){
    $st=$db->prepare('SELECT informe_json FROM gyd_diagnosticos WHERE id=?');$st->execute([$id]);
    $raw=$st->fetchColumn();if($raw===false)throw new DomainException('No se encontró ese informe.');
    $informe=json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR);
 }
 if($informe===null){$last=$db->query('SELECT id,informe_json FROM gyd_diagnosticos ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);if($last){$id=(int)$last['id'];$informe=json_decode((string)$last['informe_json'],true,512,JSON_THROW_ON_ERROR);}}
 $historial=gdiag_q($db,'SELECT d.id,d.creado_en,d.ok_count,d.atencion_count,d.problema_count,d.no_disponible_count,u.nombre AS responsable FROM gyd_diagnosticos d LEFT JOIN usuarios u ON u.id=d.usuario_id ORDER BY d.id DESC LIMIT 15');
 if(isset($_GET['export']) && $_GET['export']==='csv'){
    if(!$informe || $id<=0)throw new DomainException('Selecciona un informe guardado para exportarlo.');
    header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="diagnostico_gyd_'.(int)$id.'.csv"');
    $handle=fopen('php://output','wb');fwrite($handle,"\xEF\xBB\xBF");
    fputcsv($handle,['Área','Verificación','Estado','Detalle','Acción recomendada']);
    foreach(($informe['resultados']??[]) as $c){
      $cell=static function($s):string{$s=(string)$s;return preg_match('/^\s*[=+@\-]/u',$s)?"'".$s:$s;};
      fputcsv($handle,[$cell($c['area']??''),$cell($c['titulo']??''),$cell($c['estado']??''),$cell($c['detalle']??''),$cell($c['accion']??'')]);
    }
    fclose($handle);exit;
 }
}catch(DomainException $e){$error=$e->getMessage();}
catch(Throwable $e){error_log('Centro Diagnóstico G&D: '.$e->getMessage());$error='No se pudo completar la solicitud. Revisa la instalación y los errores PHP.';}
$rows=$informe['resultados']??[];$res=$informe['resumen']??['ok'=>0,'atencion'=>0,'problema'=>0,'no_disponible'=>0];
$classes=['ok'=>'correcto','atencion'=>'atención','problema'=>'problema','no_disponible'=>'no disponible'];
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Centro de Diagnóstico · ERP G&amp;D</title>
<style>
:root{color-scheme:light;--azul:#1453b1;--fondo:#f3f6fb;--texto:#19243a;--muted:#5f6c82;--border:#dce3ef;--card:#fff}*{box-sizing:border-box}body{margin:0;background:var(--fondo);color:var(--texto);font:15px/1.48 system-ui,-apple-system,Segoe UI,Arial,sans-serif}a{color:var(--azul)}.wrap{width:min(1280px,96%);margin:auto;padding:34px 0 60px}.top{display:flex;justify-content:space-between;gap:24px;align-items:start;flex-wrap:wrap}.eyebrow{text-transform:uppercase;letter-spacing:.12em;font-size:12px;font-weight:750;color:#1453b1}h1{font-size:clamp(25px,4vw,35px);line-height:1.15;margin:8px 0}h2{font-size:20px}p{color:var(--muted);margin:8px 0}.actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.btn{background:var(--azul);border:1px solid var(--azul);color:#fff;border-radius:10px;padding:11px 16px;text-decoration:none;font-weight:650;font:inherit;cursor:pointer}.btn.light{background:white;color:var(--azul);border-color:#bacbe3}.btn:focus-visible,a:focus-visible{outline:3px solid #f5b942;outline-offset:2px}.panel{background:white;border:1px solid var(--border);border-radius:17px;padding:22px;margin:22px 0;box-shadow:0 6px 18px rgba(12,35,72,.035)}.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:20px}.stat{background:#fff;padding:19px;border:1px solid var(--border);border-radius:14px}.stat strong{display:block;font-size:32px;margin:6px 0}.stat span{color:var(--muted)}.stat.problema strong{color:#b52838}.stat.atencion strong{color:#9a590c}.stat.ok strong{color:#157450}.status{font-weight:700;border-radius:30px;padding:4px 10px;display:inline-flex;font-size:12px;white-space:nowrap}.status.ok{background:#e2f6ec;color:#14764b}.status.atencion{background:#fff0d3;color:#875000}.status.problema{background:#ffe2e5;color:#a51b2b}.status.no_disponible{background:#e9edf2;color:#47566a}.tablewrap{overflow:auto}table{border-collapse:collapse;width:100%;min-width:760px}th,td{text-align:left;border-bottom:1px solid #e8edf5;padding:13px 11px;vertical-align:top}th{background:#f8fafe;color:#47566a;font-size:13px}td:nth-child(1){font-weight:700;white-space:nowrap}td:nth-child(2){min-width:200px}.notice{padding:15px 17px;background:#edf4ff;border:1px solid #c6d9f9;border-radius:11px;margin-top:18px}.error{background:#fff0f0;color:#9b2630;border:1px solid #f5b9c0}.notice p{color:inherit}.small{font-size:13px;color:#66768c}.history a{font-weight:650}code{font-size:12px}footer{margin-top:30px;font-size:13px;color:#64748b}@media(max-width:760px){.wrap{padding-top:20px}.stats{grid-template-columns:repeat(2,1fr)}.stat strong{font-size:26px}.panel{padding:14px}}@media print{body{background:white}.noprint{display:none!important}.wrap{width:100%;padding:0}.panel{box-shadow:none;break-inside:avoid}th{background:#fff}.stats{grid-template-columns:repeat(4,1fr)}}
</style></head><body><main class="wrap">
<header class="top"><div><div class="eyebrow">Farmacia G&amp;D · Administración del sistema</div><h1>Centro de Diagnóstico del ERP</h1><p>Estado del sistema y revisión técnica sin cambios en las operaciones de la farmacia.</p></div><nav class="actions noprint"><a class="btn light" href="dashboard.php">Inicio</a><a class="btn light" href="erp_permisos_usuarios.php">Usuarios y Permisos</a></nav></header>
<?php if($error):?><div class="notice error" role="alert"><?=gdiag_h($error)?></div><?php endif;?>
<?php if($mensaje):?><div class="notice" role="status"><?=gdiag_h($mensaje)?></div><?php endif;?>
<section class="panel noprint"><h2>Ejecutar comprobación</h2><p>Analiza los últimos 30 días en ventas y cortes, el estado actual de cajas, inventarios, facturas, protección de rutas y respaldos. No corrige cifras automáticamente.</p><div class="actions"><form method="post"><input type="hidden" name="csrf" value="<?=gdiag_h($csrf)?>"><input type="hidden" name="accion" value="diagnosticar"><button class="btn" type="submit">Ejecutar diagnóstico</button></form><?php if($informe && $id>0):?><a class="btn light" href="?id=<?=(int)$id?>&amp;export=csv">Descargar CSV / Excel</a><button class="btn light" onclick="window.print()" type="button">Imprimir / Guardar PDF</button><?php endif;?></div></section>
<?php if($informe):?><p class="small">Informe del <?=gdiag_h($informe['fecha']??'')?> · Base de pruebas</p>
<section class="stats"><div class="stat ok"><span>Correctas</span><strong><?=(int)$res['ok']?></strong></div><div class="stat atencion"><span>Requieren revisión</span><strong><?=(int)$res['atencion']?></strong></div><div class="stat problema"><span>Problemas</span><strong><?=(int)$res['problema']?></strong></div><div class="stat"><span>No evaluadas</span><strong><?=(int)$res['no_disponible']?></strong></div></section>
<section class="panel"><h2>Resultado por módulo</h2><p class="small">Las comprobaciones reflejan únicamente las reglas que este módulo puede verificar; no equivalen a una auditoría completa.</p><div class="tablewrap"><table><thead><tr><th>Área</th><th>Comprobación</th><th>Estado</th><th>Resultado y acción recomendada</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=gdiag_h($r['area']??'')?></td><td><?=gdiag_h($r['titulo']??'')?></td><td><span class="status <?=gdiag_h($r['estado']??'no_disponible')?>"><?=gdiag_h($classes[$r['estado']??'no_disponible']??'no disponible')?></span></td><td><?=gdiag_h($r['detalle']??'')?><?php if(!empty($r['accion'])):?><div class="small"><strong>Acción:</strong> <?=gdiag_h($r['accion'])?></div><?php endif;?></td></tr><?php endforeach;?></tbody></table></div></section>
<?php else:?><section class="notice"><p>Todavía no se ha generado ningún diagnóstico. Presiona «Ejecutar diagnóstico» para empezar.</p></section><?php endif;?>
<section class="panel noprint"><h2>Historial de verificaciones</h2><?php if($historial):?><div class="tablewrap"><table class="history"><thead><tr><th>Fecha</th><th>Responsable</th><th>Correctas</th><th>Revisión</th><th>Problemas</th><th>Informe</th></tr></thead><tbody><?php foreach($historial as $h):?><tr><td><?=gdiag_h($h['creado_en'])?></td><td><?=gdiag_h($h['responsable']??'Usuario no disponible')?></td><td><?=(int)$h['ok_count']?></td><td><?=(int)$h['atencion_count']?></td><td><?=(int)$h['problema_count']?></td><td><a href="?id=<?=(int)$h['id']?>">Consultar</a></td></tr><?php endforeach;?></tbody></table></div><?php else:?><p>No hay verificaciones guardadas.</p><?php endif;?></section>
<footer>El diagnóstico es de consulta. Solo crea registros en su propio historial. La presencia de respaldos no garantiza que se puedan restaurar. Sistema de pruebas «prueba».</footer></main>
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
