<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';require_once __DIR__.'/../app/config/database.php';require_once __DIR__.'/../app/helpers/gyd_retiros_directos.php';
requireLogin();require_once __DIR__.'/../app/helpers/gyd_cortes_autorizados.php';$u=currentUser()?:[];if(!gyd_corte_permitido($u['rol_original']??$u['rol']??'')){http_response_code(403);exit('Acceso exclusivo de Gerencia, Dirección, Contabilidad o Administración.');}
$fecha=(string)($_GET['fecha']??date('Y-m-d'));$d=DateTimeImmutable::createFromFormat('!Y-m-d',$fecha);if(!$d||$d->format('Y-m-d')!==$fecha)$fecha=date('Y-m-d');$fin=(new DateTimeImmutable($fecha))->modify('+1 day')->format('Y-m-d');$turnos=[];$total=0;$error='';
try {
 $db=(new Database())->connect();gr_test_db($db);
 $turnos=gr_query($db,'SELECT t.*,s.nombre sucursal,c.nombre caja,u.nombre cajero FROM pos_turnos t JOIN pos_cajas c ON c.id=t.caja_id JOIN pos_sucursales s ON s.id=c.sucursal_id JOIN usuarios u ON u.id=t.usuario_apertura_id WHERE (t.cerrado_en>=? AND t.cerrado_en<?) OR (t.estado=\'ABIERTO\' AND t.abierto_en<?) ORDER BY s.nombre,t.id',[$fecha.' 00:00:00',$fin.' 00:00:00',$fin.' 00:00:00'])->fetchAll(PDO::FETCH_ASSOC);
 foreach($turnos as &$t){$s=gd_cash_totals($db,(int)$t['id']);$r=gd_retiros_turno($db,(int)$t['id']);$t['gd_s']=$s;$t['gd_retiros']=$r;$t['gd_res']=gd_resumen_corte($t,$s,$r);$total+=$t['gd_res']['tickets'];}unset($t);
}catch(Throwable $e){$error='No fue posible consultar cortes de caja.';error_log('G&D cortes bóveda: '.$e->getMessage());}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>G&D | Cortes diarios y bóveda</title><style>body{background:#edf3f8;color:#17385c;font:14px system-ui;margin:0}header{background:#174f7e;color:white;padding:18px}header a{color:white}main{max-width:1120px;margin:20px auto;padding:0 12px}.card{background:white;border-radius:12px;border:1px solid #dce7ef;padding:18px;margin:15px 0}table{width:100%;border-collapse:collapse;min-width:700px}th,td{text-align:left;border-bottom:1px solid #dce6f0;padding:9px}th{background:#16568e;color:white}td:last-child,th:last-child{text-align:right}.scroll{overflow:auto}.note{background:#fff2d8;padding:12px}.bad{color:#a02020}.good{color:green}input,button{padding:9px;font:inherit}@media print{header button,form{display:none}body{background:white}}</style></head><body><header><h1>Cortes de caja, retiros y bóveda</h1><a href="erp_control_cajas.php">Control gerencial de cajas</a></header><main><section class="card"><form method="get"><label>Fecha del cierre o turno activo <input name="fecha" type="date" value="<?=gr_h($fecha)?>"></label><button>Consultar</button></form><p>El corte concilia por <b>turno</b>, aunque atraviese medianoche. No se equiparan ventas del calendario con retiros de otro turno.</p></section>
<?php if($error):?><p class="note"><?=gr_h($error)?></p><?php endif;?><section class="card"><h2>Retiros documentados de turnos mostrados: <?=gr_money($total)?></h2><p class="note">La fecha agrupa cierres y turnos abiertos; no es el saldo global de bóveda. El retiro queda registrado al momento de su captura; no requiere verificación electrónica posterior.</p></section>
<?php foreach($turnos as $t):$r=$t['gd_retiros'];$s=$t['gd_s'];$res=$t['gd_res'];?>
<section class="card"><h2><?=gr_h($t['sucursal'])?> · <?=gr_h($t['caja'])?> · Turno #<?=(int)$t['id']?></h2><p>Cajero: <?=gr_h($t['cajero'])?> · Abierto: <?=gr_h($t['abierto_en'])?> · Cerrado: <?=gr_h($t['cerrado_en']??'PENDIENTE')?></p>
<div class="scroll"><table><thead><tr><th>Folio retiro</th><th>Fecha y hora</th><th>Importe</th></tr></thead><tbody><?php foreach($r as $x):?><tr><td><?=gr_h(gd_folio((int)$x['id']))?></td><td><?=gr_h($x['creado_en'])?></td><td><?=gr_money(gd_cents($x['importe']))?></td></tr><?php endforeach;?><?php if(!$r):?><tr><td colspan="3">Sin retiros nuevos documentados.</td></tr><?php endif;?></tbody></table></div>
<p>Fondo: <?=gr_money($s['fondo'])?> · Ventas efectivo: <?=gr_money($s['ventas'])?> · Ingresos: <?=gr_money($s['ingresos'])?></p><p>Retiros con folio: <b><?=gr_money($res['tickets'])?></b> · Otros retiros históricos: <b><?=gr_money($res['otros'])?></b> · Total retirado POS: <b><?=gr_money($s['retiros'])?></b></p><p><b>Esperado final:</b> <?=gd_signed($s['esperado'])?> · <b>Contado final:</b> <?=($res['contado']===null?'Pendiente':gr_money($res['contado']))?> · <b><?=($res['diferencia']===null?'Resultado del corte':($res['diferencia']>0?'SOBRANTE':($res['diferencia']<0?'FALTANTE':'CUADRE EXACTO')))?>:</b> <?=($res['diferencia']===null?'Pendiente':gr_money(abs($res['diferencia'])))?></p>
<a href="pos_corte_retiros.php?turno_id=<?=(int)$t['id']?>">Imprimir corte detallado</a></section>
<?php endforeach;?></main>
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
