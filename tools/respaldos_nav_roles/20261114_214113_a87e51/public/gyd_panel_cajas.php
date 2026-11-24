<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
requireLogin();
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/gyd_panel_cajas.php';
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
$usuario=currentUser()??[];$usuarioId=(int)($usuario['id']??0);
// Doble comprobación: sesión y rol ACTUAL de la base. Una sesión antigua no eleva privilegios.
if($usuarioId<1 || !gac_rol_permitido((string)($usuario['rol_original']??$usuario['rol']??''))){http_response_code(403);exit('Acceso restringido a responsables de caja.');}
$error=null;$datos=null;
$desde=(string)($_GET['desde']??date('Y-m-d',strtotime('-30 days')));
$hasta=(string)($_GET['hasta']??date('Y-m-d'));
$sucursal=filter_var($_GET['sucursal']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
$sucursal=$sucursal===false?-1:(int)$sucursal;
$estado=(string)($_GET['estado']??'TODOS');
try {
    $db=(new Database())->connect();
    $name=(string)$db->query('SELECT DATABASE()')->fetchColumn();
    $port=(int)$db->query('SELECT @@port')->fetchColumn();
    if($name!=='prueba'||$port!==3307)throw new DomainException('Este panel está habilitado exclusivamente en la base de pruebas (puerto 3307).');
    $st=$db->prepare('SELECT estado,rol FROM usuarios WHERE id=? LIMIT 1');$st->execute([$usuarioId]);$cuenta=$st->fetch(PDO::FETCH_ASSOC);
    if(!$cuenta||(int)$cuenta['estado']!==1||!gac_rol_permitido((string)$cuenta['rol'])){
        http_response_code(403);throw new DomainException('Tu cuenta no está activa o no tiene autorización vigente.');
    }
    $datos=gac_cargar($db,$desde,$hasta,$sucursal,$estado);
} catch(Throwable $ex){
    if(!($ex instanceof DomainException))error_log('Panel de cajas G&D: '.$ex->getMessage());
    $error=$ex instanceof DomainException?$ex->getMessage():'No fue posible cargar el panel. Revisa la conexión o los registros de Apache.';
    if(http_response_code()===200)http_response_code($ex instanceof DomainException?400:500);
}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>G&D | Control de cajas</title><link rel="stylesheet" href="assets/css/gyd_panel_cajas.css"></head><body>
<header class="hero"><div class="wrap hero-inner"><div><div class="eyebrow">G&D FARMACIA · ADMINISTRACIÓN</div><h1>Control y auditoría de cajas</h1><p>Seguimiento de turnos, cobros, retiros y diferencias · Base de pruebas</p></div><nav aria-label="Accesos de caja"><a href="pos.php">Punto de venta</a><!-- GYD_ENLACE_REPORTES_GERENCIA --><a href="gyd_reportes_gerenciales.php">Reportes gerenciales</a><a href="gyd_cortes_caja.php">Cortes</a><button type="button" onclick="window.print()">Imprimir resumen</button></nav></div></header>
<main class="wrap">
<?php if($error):?><div class="alert danger" role="alert"><?=gac_html($error)?></div><?php else:
$r=$datos['resumen']; ?>
<form class="filters" method="get"><label>Desde<input type="date" name="desde" required value="<?=gac_html($desde)?>"></label><label>Hasta<input type="date" name="hasta" required value="<?=gac_html($hasta)?>"></label>
<label>Sucursal<select name="sucursal"><option value="0">Todas las sucursales</option><?php foreach($datos['sucursales'] as $s):?><option value="<?=(int)$s['id']?>" <?=$sucursal===(int)$s['id']?'selected':''?>><?=gac_html($s['nombre'])?></option><?php endforeach;?></select></label>
<label>Estado<select name="estado"><?php foreach(['TODOS'=>'Todos','ABIERTO'=>'Abiertos','CERRADO'=>'Cerrados'] as $e=>$l):?><option value="<?=gac_html($e)?>" <?=$estado===$e?'selected':''?>><?=gac_html($l)?></option><?php endforeach;?></select></label>
<button class="filter-btn" type="submit">Consultar</button></form>
<p class="explain">Se muestran turnos iniciados entre las fechas seleccionadas y también los turnos que siguen abiertos, aunque hayan comenzado antes. Los importes de turnos abiertos son provisionales. Máximo 250 turnos por consulta.</p>
<?php if($datos['limitado']):?><div class="alert warning">Se muestran los 250 turnos más recientes del filtro. Reduce el rango para consultar el resto. Los totales de esta pantalla corresponden sólo a los turnos visibles.</div><?php endif;?>
<section class="metrics" aria-label="Indicadores principales">
<div class="metric"><span>Turnos consultados</span><strong><?=(int)$r['turnos']?></strong><small><?=(int)$r['abiertos']?> abiertos · <?=(int)$r['cerrados']?> cerrados</small></div>
<div class="metric"><span>Cobros confirmados en efectivo</span><strong><?=gac_dinero($r['efectivo'])?></strong><small>No incluye fondo inicial</small></div>
<div class="metric"><span>Cobros electrónicos</span><strong><?=gac_dinero($r['tarjeta']+$r['transferencia'])?></strong><small>Tarjeta y transferencia</small></div>
<div class="metric"><span>Salidas del libro de caja</span><strong><?=gac_dinero($r['retiros'])?></strong><small>Incluyen retiros y reembolsos en efectivo registrados</small></div>
<div class="metric"><span>Turnos con alertas</span><strong><?= (int)$r['alertas']?></strong><small><?= (int)$r['cortes_diferencia']?> cortes con diferencia calculada</small></div>
</section>
<section class="card"><div class="section-head"><h2>Resumen por sucursal</h2><span>Indicadores de los turnos visibles</span></div><div class="overflow"><table><thead><tr><th>Sucursal</th><th>Abiertos</th><th>Cerrados</th><th>Efectivo</th><th>Tarjeta</th><th>Transferencia</th><th>Salidas</th><th>Alertas</th></tr></thead><tbody>
<?php foreach($datos['sucresumen'] as $s):?><tr><th><?=gac_html($s['nombre'])?></th><td><?=(int)$s['abiertos']?></td><td><?=(int)$s['cerrados']?></td><td><?=gac_dinero($s['efectivo'])?></td><td><?=gac_dinero($s['tarjeta'])?></td><td><?=gac_dinero($s['transferencia'])?></td><td><?=gac_dinero($s['retiros'])?></td><td><?=(int)$s['alertas']?></td></tr><?php endforeach;?>
</tbody></table></div></section>
<section class="card"><div class="section-head"><h2>Turnos y seguimiento</h2><span>Haz clic en “Detalle” para ver el arqueo y los responsables</span></div><div class="overflow"><table><thead><tr><th>Turno / caja</th><th>Apertura / responsable</th><th>Estado</th><th>Ventas</th><th>Efectivo esperado</th><th>Contado</th><th>Diferencia</th><th>Control</th><th>Acciones</th></tr></thead><tbody>
<?php foreach($datos['turnos'] as $t):
$dif=$t['diferencia'];$nivel=$dif===null?'':($dif===0?'fine':'bad');?>
<tr><td><b>#<?=(int)$t['id']?></b><br><?=gac_html($t['sucursal'])?><br><small><?=gac_html($t['caja'])?></small></td>
<td><?=gac_html($t['abierto_en'])?><br><small><?=gac_html($t['abierto_por']??'Sin identificar')?></small></td>
<td><span class="pill <?=$t['estado']==='ABIERTO'?'open':'closed'?>"><?=gac_html($t['estado'])?></span></td>
<td><?=(int)$t['ventas_pagadas']?> pagadas <br><small><?=(int)$t['ventas_canceladas']?> canceladas</small></td>
<td class="number"><?=gac_dinero($t['esperado'])?></td><td class="number"><?=gac_dinero($t['contado'])?></td><td class="number <?=$nivel?>"><?=gac_dinero($dif)?></td>
<td><?php if($t['avisos']):?><details><summary class="alert-count"><?=count($t['avisos'])?> aviso(s)</summary><ul><?php foreach($t['avisos'] as $av):?><li><?=gac_html($av)?></li><?php endforeach;?></ul></details><?php else:?><span class="ok">Sin alertas detectadas</span><?php endif;?></td>
<td><a class="detail-btn" href="pos_conciliacion_turno.php?turno_id=<?=(int)$t['id']?>">Detalle</a></td></tr>
<?php endforeach;?>
<?php if(!$datos['turnos']):?><tr><td class="empty" colspan="9">No hay turnos para estos filtros.</td></tr><?php endif;?></tbody></table></div></section>
<section class="card note"><h2>Cómo interpretar los datos</h2><p><b>Efectivo esperado</b> = fondo inicial + cobros confirmados en efectivo + ingresos de caja − salidas registradas. Los reembolsos en efectivo ya forman parte de las salidas y no se restan por segunda vez. Los cobros con tarjeta y transferencia no forman parte del efectivo físico.</p><p><b>Diferencia</b> = efectivo contado − efectivo esperado. Negativo indica faltante; positivo, sobrante. Cuando un turno está abierto, la diferencia se deja pendiente.</p><p>Los avisos señalan información que requiere revisión (por ejemplo, faltan firmas históricas o el resultado guardado no coincide con el cálculo actual). Esta pantalla es de solo lectura y no certifica la liquidación bancaria ni modifica ventas, inventarios, retiros o cortes.</p></section>
<?php endif;?></main><footer>G&D Farmacia · Consulta administrativa de solo lectura · Sin cambios de inventario ni caja</footer>
<script src="assets/js/gyd_excel_reportes.js" defer></script>

<?php if (!defined('GYD_HIDE_GLOBAL_NAV')): ?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=4">
<script>window.GYD_NAV_HOME='index.php';window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=4"></script>
<?php endif; ?>
</body></html>
