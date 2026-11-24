<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
requireLogin();
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/gyd_reportes_gerenciales.php';
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
$usuario=currentUser()??[];
$userId=(int)($usuario['id']??0);
if($userId<1 || !gac_rol_permitido((string)($usuario['rol_original']??$usuario['rol']??''))){http_response_code(403);exit('Acceso restringido a responsables de caja.');}
$desde=(string)($_GET['desde']??date('Y-m-d',strtotime('-30 days')));
$hasta=(string)($_GET['hasta']??date('Y-m-d'));
$sucursal=filter_var($_GET['sucursal']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
$sucursal=$sucursal===false?-1:(int)$sucursal;
$estado=(string)($_GET['estado']??'TODOS');
$tipo=(string)($_GET['csv']??'');
$datos=null;$error=null;
try{
    $db=(new Database())->connect();
    $nombre=(string)$db->query('SELECT DATABASE()')->fetchColumn();
    $puerto=(int)$db->query('SELECT @@port')->fetchColumn();
    if($nombre!=='prueba'||$puerto!==3307)throw new DomainException('Este módulo únicamente puede usarse en prueba:3307.');
    $st=$db->prepare('SELECT estado,rol FROM usuarios WHERE id=? LIMIT 1');
    $st->execute([$userId]);$cuenta=$st->fetch(PDO::FETCH_ASSOC);
    if(!$cuenta || (int)$cuenta['estado']!==1 || !gac_rol_permitido((string)$cuenta['rol'])){
        http_response_code(403);throw new DomainException('Cuenta inactiva o sin permisos de consulta.');
    }
    $datos=gr_datos($db,$desde,$hasta,$sucursal,$estado);
    if($tipo!==''){
        gr_exportar_csv($datos,$tipo,$desde,$hasta);
        exit;
    }
}catch(Throwable $ex){
    if(!($ex instanceof DomainException))error_log('Informes gerenciales G&D: '.$ex->getMessage());
    $error=$ex instanceof DomainException?$ex->getMessage():'No fue posible generar el reporte. Revisa la conexión y los registros del servidor.';
    if(http_response_code()===200)http_response_code($ex instanceof DomainException?400:500);
}
$q=['desde'=>$desde,'hasta'=>$hasta,'sucursal'=>$sucursal,'estado'=>$estado];
$csvurl=static fn(string $tipo)=>'gyd_reportes_gerenciales.php?'.http_build_query(array_merge($q,['csv'=>$tipo]));
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>G&D | Reportes de caja</title><link rel="stylesheet" href="assets/css/gyd_panel_cajas.css"><link rel="stylesheet" href="assets/css/gyd_reportes_gerenciales.css"></head><body>
<header class="hero"><div class="wrap hero-inner"><div><div class="eyebrow">G&D FARMACIA · ADMINISTRACIÓN</div><h1>Reportes gerenciales</h1><p>Ventas, cobros, retiros y diferencias · Base de pruebas</p></div><nav aria-label="Navegación"><a href="gyd_panel_cajas.php">Control de cajas</a><a href="pos.php">Punto de venta</a><button type="button" onclick="window.print()">Imprimir</button></nav></div></header>
<main class="wrap">
<?php if($error):?><div class="alert danger" role="alert"><?=gac_html($error)?></div>
<?php else: $r=$datos['resumen'];$p=$datos['panel']['resumen']; ?>
<form class="filters" method="get" action="gyd_reportes_gerenciales.php">
<label>Desde<input type="date" name="desde" required value="<?=gac_html($desde)?>"></label>
<label>Hasta<input type="date" name="hasta" required value="<?=gac_html($hasta)?>"></label>
<label>Sucursal<select name="sucursal"><option value="0">Todas las sucursales</option><?php foreach($datos['panel']['sucursales'] as $s):?><option value="<?=(int)$s['id']?>" <?=$sucursal===(int)$s['id']?'selected':''?>><?=gac_html($s['nombre'])?></option><?php endforeach;?></select></label>
<label>Turnos<select name="estado"><?php foreach(['TODOS'=>'Todos','ABIERTO'=>'Abiertos','CERRADO'=>'Cerrados'] as $clave=>$etiqueta):?><option value="<?=gac_html($clave)?>" <?=$clave===$estado?'selected':''?>><?=gac_html($etiqueta)?></option><?php endforeach;?></select></label>
<button type="submit" class="filter-btn">Consultar</button>
</form>
<p class="explain">Periodo según apertura del turno. También incluye turnos que siguen abiertos, aunque su apertura sea anterior. Máximo 250 turnos por consulta; reduce el rango para obtener informes completos. Los datos abiertos son provisionales.</p>
<?php if($datos['limitado']):?><div class="alert warning"><strong>Consulta incompleta.</strong> Hay más de 250 turnos en el filtro. Los totales corresponden solo a los visibles; reduce el rango de fechas para habilitar la exportación de resultados completos.</div><?php else:?>
<div class="exports"><strong>Exportar CSV:</strong> <a href="<?=gac_html($csvurl('sucursales'))?>">Por sucursal</a><a href="<?=gac_html($csvurl('cajeros'))?>">Por cajero</a><a href="<?=gac_html($csvurl('turnos'))?>">Por turno</a></div>
<?php endif;?>
<section class="metrics" aria-label="Resumen de operación">
<div class="metric"><span>Ventas pagadas vigentes</span><strong><?=gac_dinero($r['ventas_vigentes'])?></strong><small><?=(int)$r['pagadas']?> ventas · <?=(int)$r['canceladas']?> canceladas no sumadas</small></div>
<div class="metric"><span>Cobros confirmados de caja</span><strong><?=gac_dinero($p['efectivo']+$p['tarjeta']+$p['transferencia'])?></strong><small>Efectivo, tarjeta y transferencia · criterio de conciliación</small></div>
<div class="metric"><span>Salidas de efectivo</span><strong><?=gac_dinero($r['retiros'])?></strong><small>Incluyen reembolsos en efectivo: <?=gac_dinero(array_sum(array_column($datos['sucursales'],'reembolso_efectivo')))?></small></div>
<div class="metric"><span>Faltantes acumulados</span><strong class="negative"><?=gac_dinero($r['faltantes'])?></strong><small>Sumados en valor absoluto, solo turnos cerrados</small></div>
<div class="metric"><span>Sobrantes acumulados</span><strong><?=gac_dinero($r['sobrantes'])?></strong><small>Solo turnos cerrados</small></div>
</section>
<div class="subnav"><a href="#sucursales">Sucursal</a><a href="#cajeros">Cajeros</a><a href="#turnos">Turnos</a></div>
<section id="sucursales" class="card"><div class="section-head"><h2>Ventas y caja por sucursal</h2><span>MXN · Turnos visibles</span></div><div class="overflow"><table><thead><tr><th>Sucursal</th><th>Turnos</th><th>Ventas vigentes</th><th>Pagadas / Canceladas</th><th>Efectivo cobrado</th><th>Tarjeta</th><th>Transferencia</th><th>Salidas de caja</th><th>Reembolsos efectivo*</th><th>Faltantes</th><th>Sobrantes</th></tr></thead><tbody>
<?php foreach($datos['sucursales'] as $s):?><tr><th><?=gac_html($s['nombre'])?></th><td><?=(int)$s['turnos']?></td><td class="number"><?=gac_dinero($s['venta_vigente'])?></td><td><?=(int)$s['pagadas']?> / <?=(int)$s['canceladas']?></td><td class="number"><?=gac_dinero($s['cobro_efectivo'])?></td><td class="number"><?=gac_dinero($s['cobro_tarjeta'])?></td><td class="number"><?=gac_dinero($s['cobro_transferencia'])?></td><td class="number"><?=gac_dinero($s['salidas'])?></td><td class="number"><?=gac_dinero($s['reembolso_efectivo'])?></td><td class="number negative"><?=gac_dinero($s['faltantes'])?></td><td class="number"><?=gac_dinero($s['sobrantes'])?></td></tr><?php endforeach;?></tbody></table></div>
<p class="footnote">*Los reembolsos en efectivo ya están incluidos en las salidas registradas de caja. No se restan dos veces. Los reembolsos electrónicos se consultan por separado en el detalle de cada turno.</p></section>
<section id="cajeros" class="card"><div class="section-head"><h2>Ventas por cajero</h2><span>Por usuario que registró la venta; no incluye operaciones canceladas en el importe</span></div><div class="overflow"><table><thead><tr><th>Sucursal</th><th>Cajero</th><th>Pagadas</th><th>Canceladas</th><th>Ventas vigentes</th><th>Efectivo de ventas vigentes</th><th>Tarjeta de ventas vigentes</th><th>Transferencia de ventas vigentes</th></tr></thead><tbody>
<?php foreach($datos['cajeros'] as $c):?><tr><td><?=gac_html($c['sucursal'])?></td><th><?=gac_html($c['cajero'])?></th><td><?=(int)$c['pagadas']?></td><td><?=(int)$c['canceladas']?></td><td class="number"><?=gac_dinero($c['venta_vigente'])?></td><td class="number"><?=gac_dinero($c['efectivo'])?></td><td class="number"><?=gac_dinero($c['tarjeta'])?></td><td class="number"><?=gac_dinero($c['transferencia'])?></td></tr><?php endforeach;?>
<?php if(!$datos['cajeros']):?><tr><td class="empty" colspan="8">No hay ventas pagadas o canceladas en los turnos consultados.</td></tr><?php endif;?></tbody></table></div><p class="footnote">No se asignan los reembolsos a un cajero sin evidencia del usuario responsable. Los pagos mixtos se distribuyen por el método registrado, sin multiplicar el importe de la venta.</p></section>
<section id="turnos" class="card"><div class="section-head"><h2>Diferencias y retiros por turno</h2><span>El resultado del corte no se recalcula ni se modifica en la base</span></div><div class="overflow"><table><thead><tr><th>Turno</th><th>Fecha de apertura</th><th>Sucursal / caja</th><th>Responsable</th><th>Estado</th><th>Salidas</th><th>Efectivo esperado</th><th>Contado</th><th>Diferencia</th><th>Alertas</th><th>Detalle</th></tr></thead><tbody>
<?php foreach($datos['turnos'] as $t):?><tr><th>#<?=(int)$t['id']?></th><td><?=gac_html($t['abierto_en'])?></td><td><?=gac_html($t['sucursal'])?><br><small><?=gac_html($t['caja'])?></small></td><td><?=gac_html($t['abierto_por']??'No identificado')?></td><td><?=gac_html($t['estado'])?></td><td class="number"><?=gac_dinero($t['retiros'])?></td><td class="number"><?=gac_dinero($t['esperado'])?></td><td class="number"><?=gac_dinero($t['contado'])?></td><td class="number <?=$t['diferencia']!==null&&$t['diferencia']<0?'negative':''?>"><?=gac_dinero($t['diferencia'])?></td><td><?php if($t['avisos']):?><details><summary><?=count($t['avisos'])?> aviso(s)</summary><ul><?php foreach($t['avisos'] as $av):?><li><?=gac_html($av)?></li><?php endforeach;?></ul></details><?php else:?>Sin alertas<?php endif;?></td><td><a href="pos_conciliacion_turno.php?turno_id=<?=(int)$t['id']?>">Ver corte</a></td></tr><?php endforeach;?>
<?php if(!$datos['turnos']):?><tr><td class="empty" colspan="11">No se encontraron turnos.</td></tr><?php endif;?></tbody></table></div></section>
<section class="card note"><h2>Notas de conciliación</h2><p><b>Ventas vigentes:</b> suma del total de ventas con estado PAGADA en los turnos visibles; las canceladas no se suman. Esta cifra puede diferir del cobro histórico de caja, que conserva ciertos pagos cancelados cuando su reembolso se hizo en el mismo turno.</p><p><b>Cobros de caja:</b> siguen las reglas existentes del Panel de Auditoría. No equivalen a la liquidación bancaria. <b>Salidas:</b> incluyen retiros y reembolsos registrados en el libro de caja. La sección de cajeros identifica a quien hizo la venta, no a quien autorizó una devolución.</p><p><b>Diferencias:</b> solo turnos cerrados; faltantes y sobrantes se acumulan por separado y nunca se compensan entre cajas. El reporte es de solo lectura y no altera saldos, inventarios, cortes ni conciliaciones.</p></section>
<?php endif;?></main><footer>G&D Farmacia · Información administrativa de prueba · Sin cambios en caja o inventario</footer>
<script src="assets/js/gyd_excel_reportes.js" defer></script>

<?php if (!defined('GYD_HIDE_GLOBAL_NAV')): ?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=4">
<script>window.GYD_NAV_HOME='index.php';window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=4"></script>
<?php endif; ?>
</body></html>
