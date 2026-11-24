<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_oc_cierre_controlado.php';
requireLogin();
header('Cache-Control: private, no-store, max-age=0');
$user=currentUser()?:[];
if(!gcc_puede_ver($user)){http_response_code(403);exit('Solo Administración y Compras pueden consultar cierres.');}
if(empty($_SESSION['gcc_csrf']))$_SESSION['gcc_csrf']=bin2hex(random_bytes(32));
$csrf=(string)$_SESSION['gcc_csrf'];
$id=(int)($_GET['oc']??$_POST['oc']??0);$error='';$ok='';$detalle=null;$cierre=null;$evaluacion=null;$log=[];$ultimas=[];
try {
 $db=(new Database())->connect();
 $st=$db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('erp_oc_cierres_controlados','erp_oc_cierres_partidas','erp_oc_cierres_bitacora')");$st->execute();
 if((int)$st->fetchColumn()!==3)throw new RuntimeException('Faltan tablas de cierre. Ejecuta el instalador con --apply.');
 if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
   if(!hash_equals($csrf,(string)($_POST['csrf']??'')))throw new RuntimeException('Formulario caducado; actualiza la pantalla.');
   $accion=(string)($_POST['accion']??'');$motivo=(string)($_POST['motivo']??'');$evidencia=(string)($_POST['evidencia']??'');
   if($id<1)throw new RuntimeException('Selecciona una OC.');
   if($accion==='total')gcc_cerrar($db,$id,$user,'TOTAL',$motivo,$evidencia);
   elseif($accion==='diferencias')gcc_cerrar($db,$id,$user,'CON_DIFERENCIAS',$motivo,$evidencia);
   elseif($accion==='reabrir')gcc_reabrir($db,$id,$user,$motivo);
   else throw new RuntimeException('Acción no reconocida.');
   $_SESSION['gcc_flash']='Operación registrada en la bitácora de cierre.';
   header('Location: erp_oc_cierre_controlado.php?oc='.$id,true,303);exit;
 }
 if($id>0){
  require_once __DIR__.'/../app/helpers/erp_conciliacion_integral.php';
  $detalle=ci_detalle($db,$id);
  if(!$detalle){http_response_code(404);throw new RuntimeException('Orden de compra no encontrada.');}
  $cierre=gcc_clausura($db,$id);
  $est=ci_estructura($db);
  if(!$est['ajustes'])throw new RuntimeException('No se puede validar el cierre: falta el historial de ajustes de conciliación.');
  $bodega=gcc_entradas_bodega($db,$id,(string)$detalle['orden']['destino']);
  $evaluacion=gcc_evaluar($detalle,$bodega,gcc_incidencias_especiales($db,$id));
  $q=$db->prepare('SELECT b.accion,b.observaciones,b.evidencia,b.registrado_en,u.nombre usuario FROM erp_oc_cierres_bitacora b LEFT JOIN usuarios u ON u.id=b.usuario_id WHERE b.orden_id=? ORDER BY b.id DESC LIMIT 50');$q->execute([$id]);$log=$q->fetchAll(PDO::FETCH_ASSOC);
 } else {
  $ultimas=$db->query("SELECT o.id,o.folio,o.fecha,o.destino,p.nombre proveedor,c.estado AS estado_cierre,c.tipo,c.faltantes_definitivas,c.cerrado_en FROM erp_ordenes_compra o LEFT JOIN proveedores p ON p.id=o.proveedor_id LEFT JOIN erp_oc_cierres_controlados c ON c.orden_id=o.id ORDER BY o.id DESC LIMIT 60")->fetchAll(PDO::FETCH_ASSOC);
 }
 $ok=(string)($_SESSION['gcc_flash']??'');unset($_SESSION['gcc_flash']);
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();error_log('ERP cierre controlado: '.$e->getMessage());$error=$e instanceof RuntimeException?$e->getMessage():'No se pudo cargar el cierre. Revisa el log de PHP.';}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ERP G&D · Cierre controlado de OC</title>
<style>body{font:15px system-ui,Segoe UI,Arial,sans-serif;background:#eff4f9;color:#1b3a5c;margin:0}header{background:#153e6d;color:white;padding:19px max(20px,calc((100% - 1180px)/2))}header h1{margin:0;font-size:23px}.wrap{max-width:1200px;margin:22px auto;padding:0 18px 50px}section{background:white;border:1px solid #e0e8f0;border-radius:13px;padding:19px;margin-bottom:17px}a{color:#145d9e}a.btn,button{display:inline-block;background:#165d9c;color:white;border:0;padding:10px 14px;border-radius:8px;font:inherit;text-decoration:none;cursor:pointer}button:disabled{background:#b5c6d6;cursor:not-allowed}.btn.alt{background:#e4edf7;color:#174978}.err{background:#ffe9ea;color:#8c2731;padding:13px;border-radius:9px}.ok{background:#e3f6ed;color:#165c36;padding:13px;border-radius:9px}.warn{background:#fff5df;border:1px solid #eecb89;border-radius:9px;padding:12px}.small{color:#5c7790;font-size:13px;line-height:1.5}.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.num{font-weight:800;font-size:25px;margin-top:6px}table{border-collapse:collapse;width:100%;font-size:13px}td,th{padding:9px;border-bottom:1px solid #e2ebf3;text-align:left}th{background:#e9f2fb}textarea,input{display:block;width:100%;font:inherit;padding:10px;border:1px solid #b6cbe0;border-radius:8px;margin:6px 0 13px;box-sizing:border-box}form.box{border:1px solid #dfebf3;border-radius:9px;padding:15px;margin-top:14px}details{margin-top:15px}h2{font-size:19px}.scroll{overflow:auto}.cols{display:grid;grid-template-columns:1fr 1fr;gap:14px}@media(max-width:700px){.grid{grid-template-columns:repeat(2,1fr)}.cols{grid-template-columns:1fr}}@media print{button,form,.noprint{display:none}}</style></head>
<body><header><h1>Cierre Inteligente de Órdenes de Compra</h1><p>Conciliación, saldos definitivos y bitácora · G&D</p></header><main class="wrap"><section class="noprint"><a class="btn alt" href="erp_conciliacion_integral.php">Conciliación integral</a> <a class="btn alt" href="erp_oc_win.php">Órdenes de compra</a> <a class="btn alt" href="erp_recepciones_pendientes.php">Recepciones</a></section>
<?php if($error):?><section class="err"><?=gcc_e($error)?></section><?php endif;?><?php if($ok):?><section class="ok"><?=gcc_e($ok)?></section><?php endif;?>
<?php if(!$id):?><section><h2>Seleccionar una orden</h2><form method="get"><label>Número interno de OC<input name="oc" type="number" min="1" required placeholder="Por ejemplo: 10"></label><button>Consultar conciliación</button></form><h2>Órdenes recientes</h2><div class="scroll"><table><thead><tr><th>OC</th><th>Proveedor</th><th>Destino</th><th>Estado de cierre</th><th>Faltantes definitivos</th><th>Acción</th></tr></thead><tbody><?php foreach($ultimas as $u):?><tr><td><?=gcc_e($u['folio'])?></td><td><?=gcc_e($u['proveedor'])?></td><td><?=gcc_e($u['destino'])?></td><td><?=gcc_e($u['estado_cierre']??'ABIERTA')?></td><td><?=gcc_e($u['faltantes_definitivas']??'—')?></td><td><a href="?oc=<?=(int)$u['id']?>">Revisar</a></td></tr><?php endforeach;?></tbody></table></div></section>
<?php elseif($detalle): $o=$detalle['orden'];$tt=$evaluacion['totales'];$bloqueada=$cierre && $cierre['estado']==='CERRADA';?>
<section><h2><?=gcc_e($o['folio'])?> · <?=gcc_e($o['proveedor'])?></h2><p class="small">Destino: <?=gcc_e($o['destino'])?> · Estado operativo histórico: <?=gcc_e($o['estado'])?> · Cierre administrativo: <strong><?=gcc_e($bloqueada?'CERRADA':($cierre['estado']??'ABIERTA'))?></strong></p><div class="grid"><?php foreach(['Solicitadas'=>'solicitadas','Recibidas buenas'=>'recibidas','Facturadas'=>'facturadas','Aplicadas'=>'aplicadas'] as $et=>$key):?><div><span class="small"><?=gcc_e($et)?></span><div class="num"><?=number_format($tt[$key])?></div></div><?php endforeach;?></div><p class="warn"><b><?=number_format($evaluacion['faltantes'])?> piezas pendientes de aceptación buena.</b> No se fabrican entradas para cerrarlas. Incidencias especiales (dañados, caducados, sobrantes o equivocados): <?=number_format($evaluacion['incidencias_especiales'])?> piezas. Las recepciones complementarias continúan mientras la OC esté abierta.</p>
<p>Validación: <b><?=!$evaluacion['errores']?($evaluacion['total']?'LISTA PARA CIERRE TOTAL':($evaluacion['excepcion']?'REQUIERE AUTORIZACIÓN POR DIFERENCIAS / INCIDENCIAS':'REVISAR')):'BLOQUEADA POR CONCILIACIÓN'?></b></p><?php if($evaluacion['errores']):?><ul><?php foreach(array_slice($evaluacion['errores'],0,30) as $v):?><li><?=gcc_e($v)?></li><?php endforeach;?></ul><?php endif;?></section>
<section><h2>Detalle por producto</h2><div class="scroll"><table><thead><tr><th>Código / producto</th><th>Solicitadas</th><th>Buenas</th><th>Factura</th><th>Verificadas</th><th>Aplicadas</th><th>Pendientes definitivas</th></tr></thead><tbody><?php foreach($detalle['partidas'] as $p):$a=gcc_validar_fila($p,(int)($bodega[(int)$p['producto_id']]??0));?><tr><td><?=gcc_e($p['codigo'].' · '.$p['descripcion'])?></td><td><?=$a['s']?></td><td><?=$a['b']?></td><td><?=$a['f']?></td><td><?=$a['a']?></td><td><?=$a['ap']?></td><td><?=$a['faltante']?></td></tr><?php endforeach;?></tbody></table></div></section>
<section><h2>Acciones autorizadas</h2><?php if($bloqueada):?><p class="ok">La orden está cerrada. No se permiten nuevas recepciones ni nuevas facturas vinculadas desde las rutas protegidas.</p><?php if(gcc_puede_excepcion($user)):?><form class="box" method="post"><input type="hidden" name="csrf" value="<?=gcc_e($csrf)?>"><input type="hidden" name="oc" value="<?=$id?>"><input type="hidden" name="accion" value="reabrir"><label>Motivo de reapertura (mínimo 10 caracteres)<textarea name="motivo" rows="3" minlength="10" maxlength="500" required></textarea></label><button onclick="return confirm('¿Reabrir esta OC y habilitar nuevas recepciones?')">Reabrir OC (Administración)</button></form><?php endif;?>
<?php else:?><div class="cols"><form class="box" method="post"><h3>Cierre total</h3><p class="small">Todos los productos deben estar completamente recibidos, facturados, verificados y aplicados.</p><input type="hidden" name="csrf" value="<?=gcc_e($csrf)?>"><input type="hidden" name="oc" value="<?=$id?>"><input type="hidden" name="accion" value="total"><label>Nota opcional<textarea name="motivo" rows="3" maxlength="500"></textarea></label><button <?=!$evaluacion['total']?'disabled':''?> onclick="return confirm('¿Confirmar cierre TOTAL de esta OC?')">Cerrar OC conciliada</button></form>
<?php if(gcc_puede_excepcion($user)):?><form class="box" method="post"><h3>Cierre con diferencias · Administración</h3><p class="small">Solo después de conciliar las piezas buenas y documentar la resolución de las faltantes, dañadas, sobrantes o equivocadas. No implica aceptación de mercancía no recibida.</p><input type="hidden" name="csrf" value="<?=gcc_e($csrf)?>"><input type="hidden" name="oc" value="<?=$id?>"><input type="hidden" name="accion" value="diferencias"><label>Justificación (mínimo 10 caracteres)<textarea name="motivo" rows="3" minlength="10" maxlength="500" required></textarea></label><label>Referencia documental (acta / folio / correo)<input name="evidencia" minlength="4" maxlength="255" required></label><button <?=!$evaluacion['excepcion']?'disabled':''?> onclick="return confirm('¿Cerrar definitivamente las piezas faltantes de esta OC sin ingresar inventario?')">Cerrar con diferencias</button></form><?php endif;?></div><?php endif;?></section>
<section><h2>Historial de decisiones</h2><div class="scroll"><table><thead><tr><th>Fecha</th><th>Acción</th><th>Usuario</th><th>Motivo</th><th>Evidencia</th></tr></thead><tbody><?php foreach($log as $entry):?><tr><td><?=gcc_e($entry['registrado_en'])?></td><td><?=gcc_e($entry['accion'])?></td><td><?=gcc_e($entry['usuario']??'')?></td><td><?=gcc_e($entry['observaciones'])?></td><td><?=gcc_e($entry['evidencia']??'')?></td></tr><?php endforeach;?><?php if(!$log):?><tr><td colspan="5">Sin cierres previos</td></tr><?php endif;?></tbody></table></div><p class="small">El cierre administrativo no reemplaza la conciliación fiscal, devolución al proveedor ni ajustes de inventario autorizados.</p></section><?php endif;?></main>
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
