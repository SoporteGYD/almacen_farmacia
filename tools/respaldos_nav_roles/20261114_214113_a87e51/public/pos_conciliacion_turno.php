<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
requireLogin();
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/gyd_cortes_autorizados.php';
require_once __DIR__.'/../app/helpers/gyd_pos_conciliacion_detalle.php';
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
$usuario=currentUser()??[];
$uid=(int)($usuario['id']??0);
$rol=gyd_corte_rol($usuario['rol_original']??$usuario['rol']??'');
$privilegiado=gyd_corte_permitido($rol);
if($uid<1 || (!$privilegiado && $rol!=='CAJERO')){http_response_code(403);exit('Acceso no autorizado.');}
$error='';$reporte=null;
try {
    $db=(new Database())->connect();
    $nombre=(string)$db->query('SELECT DATABASE()')->fetchColumn();
    $puerto=(int)$db->query('SELECT @@port')->fetchColumn();
    if($nombre!=='prueba' || $puerto!==3307)throw new DomainException('Consulta disponible únicamente en la base de pruebas.');
    $turnoId=filter_input(INPUT_GET,'turno_id',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
    if($turnoId<1 && $privilegiado){
        $cajaId=filter_input(INPUT_GET,'caja_id',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
        if($cajaId>0){
            $st=gcd_q($db,"SELECT id FROM pos_turnos WHERE caja_id=? ORDER BY (estado='ABIERTO') DESC,id DESC LIMIT 1",[$cajaId]);
            $turnoId=(int)$st->fetchColumn();
        }
    }
    if($turnoId<1)throw new DomainException('Selecciona un turno desde el corte de caja.');
    $st=gcd_q($db,'SELECT id,caja_id,estado,usuario_apertura_id FROM pos_turnos WHERE id=?',[$turnoId]);$t=$st->fetch(PDO::FETCH_ASSOC);
    if(!$t)throw new DomainException('Turno no encontrado.');
    // El cajero NO puede consultar el efectivo esperado mientras el turno esté abierto.
    if(!$privilegiado){
        if($t['estado']!=='CERRADO')throw new DomainException('El cajero solo puede consultar el resumen de un turno cerrado.');
        $permitido=(int)$t['usuario_apertura_id']===$uid;
        if(!$permitido){
            $st=gcd_q($db,'SELECT 1 FROM pos_cortes_denominaciones WHERE turno_id=? AND usuario_id=?',[$turnoId,$uid]);
            $permitido=(bool)$st->fetchColumn();
        }
        if(!$permitido)throw new DomainException('Solo puedes consultar un corte que te corresponde.');
        $st=gcd_q($db,'SELECT 1 FROM pos_usuario_asignaciones WHERE usuario_id=? AND caja_id=?',[$uid,(int)$t['caja_id']]);
        if(!$st->fetchColumn())throw new DomainException('No tienes asignada esta caja.');
    }
    $reporte=gcd_detalle_turno($db,$turnoId);
} catch(Throwable $ex){http_response_code($ex instanceof DomainException?403:500);$error=$ex instanceof DomainException?$ex->getMessage():'No se pudo consultar el reporte; revisa el registro del servidor.';error_log('G&D conciliación turno: '.$ex->getMessage());}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>G&D | Conciliación de caja</title>
<link rel="stylesheet" href="assets/css/gyd_conciliacion_turno.css"></head><body>
<header class="topbar"><div class="top-inner"><div><strong>G&D · FARMACIA</strong><h1>Conciliación y corte de caja</h1><p>Resumen por turno · Base de pruebas</p></div><div class="nav"><a href="pos.php">Punto de Venta</a><?php if($privilegiado):?><a href="gyd_cortes_caja.php">Cortes de caja</a><?php endif;?><button onclick="window.print()" type="button">Imprimir comprobante</button></div></div></header>
<main class="wrap">
<?php if($error):?><section class="alert error" role="alert"><?=gcd_h($error)?></section><?php elseif($reporte):
$t=$reporte['turno'];$s=$reporte['saldo'];$c=$reporte['calc'];$p=$reporte['pagos'];$ref=$reporte['reembolsos'];$firma=$reporte['firma'];
$estaCerrado=$t['estado']==='CERRADO';$dif=$c['diferencia'];?>
<section class="meta card"><div><small>Sucursal</small><b><?=gcd_h($t['sucursal'])?></b></div><div><small>Caja</small><b><?=gcd_h($t['caja'])?></b></div><div><small>Turno</small><b>#<?=(int)$t['id']?></b></div><div><small>Estado</small><b class="<?=$estaCerrado?'closed':'open'?>"><?=gcd_h($t['estado'])?></b></div></section>
<?php if(!$estaCerrado):?><section class="alert">PRECORTE INFORMATIVO PARA PERSONAL AUTORIZADO. El turno continúa abierto. Las cantidades pueden cambiar; este reporte no realiza cierres ni movimientos.</section><?php endif;?>
<?php foreach($reporte['avisos'] as $aviso):?><section class="alert warning" role="alert"><?=gcd_h($aviso)?></section><?php endforeach;?>
<section class="cols"><article class="card"><h2>Conciliación de efectivo</h2><table><tbody>
<tr><th>Fondo inicial</th><td><?=gcd_money($c['fondo'])?></td></tr>
<tr><th>Ventas cobradas en efectivo</th><td><?=gcd_money($c['efectivo'])?></td></tr>
<tr><th>Otros ingresos a caja</th><td><?=gcd_money($c['ingresos'])?></td></tr>
<tr><th>Retiros y devoluciones en efectivo *</th><td>−<?=gcd_money($c['retiros'])?></td></tr>
<tr class="highlight"><th>Efectivo esperado</th><td><?=gcd_money($c['esperado'])?></td></tr>
<tr><th>Efectivo contado</th><td><?=$c['contado']===null?'Pendiente':gcd_money($c['contado'])?></td></tr>
<tr class="highlight"><th><?=($dif===null?'Resultado pendiente':($dif>0?'Sobrante':($dif<0?'Faltante':'Cuadre exacto')))?></th><td class="<?=($dif!==null&&$dif!==0)?'red':'green'?>"><?=$dif===null?'Pendiente':gcd_money(abs($dif))?></td></tr>
</tbody></table><p class="footnote">* Las devoluciones en efectivo se encuentran dentro de las salidas del libro de caja. No se restan dos veces. Los pagos con tarjeta o transferencia no incrementan el efectivo físico.</p>
</article><article class="card"><h2>Pagos y devoluciones</h2><table><tbody>
<tr><th>Ventas incluidas</th><td><?=(int)$reporte['ventas']?></td></tr>
<tr><th>Cobros con tarjeta</th><td><?=gcd_money($p['TARJETA'])?></td></tr>
<tr><th>Cobros por transferencia</th><td><?=gcd_money($p['TRANSFERENCIA'])?></td></tr>
<tr><th>Reembolsos a tarjeta</th><td>−<?=gcd_money($ref['TARJETA'])?></td></tr>
<tr><th>Reembolsos por transferencia</th><td>−<?=gcd_money($ref['TRANSFERENCIA'])?></td></tr>
<tr class="highlight"><th>Saldo electrónico neto del turno</th><td><?=gcd_money($p['TARJETA']+$p['TRANSFERENCIA']-$ref['TARJETA']-$ref['TRANSFERENCIA'])?></td></tr>
<tr><th>Reembolsos en efectivo (informativo)</th><td><?=gcd_money($ref['EFECTIVO'])?></td></tr>
<tr><th>Reembolsos por otro medio</th><td><?=gcd_money($ref['OTRO'])?></td></tr>
<tr><th>Cancelaciones totales aplicadas</th><td><?=(int)$reporte['canceladas']?></td></tr>
<tr><th>Devoluciones parciales aplicadas</th><td><?=(int)$reporte['parciales']?></td></tr>
</tbody></table><p class="footnote">Los reembolsos electrónicos se informan en el turno en que se procesaron y deben conciliarse también contra el banco o la terminal. No representan efectivo en caja.</p></article></section>
<section class="cols"><article class="card"><h2>Retiros de efectivo</h2><table><tbody>
<tr><th>Retiros totales del libro de caja</th><td><?=gcd_money($s['retiros'])?></td></tr>
<tr><th>Comprobantes de retiros a bóveda</th><td><?=gcd_money($reporte['bovedaTotal'])?></td></tr>
<tr><th>Reembolsos en efectivo (incluidos)</th><td><?=gcd_money($ref['EFECTIVO'])?></td></tr>
</tbody></table><table class="smaller"><thead><tr><th>Comprobante</th><th>Fecha</th><th>Importe</th></tr></thead><tbody>
<?php foreach($reporte['boveda'] as $r):?><tr><td><?=gcd_h(gd_folio((int)$r['id']))?></td><td><?=gcd_h($r['creado_en'])?></td><td><?=gcd_money(gd_cents($r['importe']))?></td></tr><?php endforeach;?>
<?php if(!$reporte['boveda']):?><tr><td colspan="3">Sin retiros a bóveda documentados.</td></tr><?php endif;?></tbody></table>
</article><article class="card"><h2>Arqueo por billetes y monedas</h2>
<?php if($reporte['denominaciones']!==null):?><table><thead><tr><th>Denominación</th><th>Cantidad</th><th>Subtotal</th></tr></thead><tbody>
<?php foreach(PosCorteDenominaciones::VALORES as $codigo=>$valor):$cantidad=(int)$reporte['denominaciones'][$codigo];if($cantidad===0)continue;?><tr><th><?=gcd_h(($codigo[0]==='b'?'Billete':'Moneda').' '.gcd_money($valor))?></th><td><?=$cantidad?></td><td><?=gcd_money($valor*$cantidad)?></td></tr><?php endforeach;?>
<tr class="highlight"><th>Total contado por denominaciones</th><td></td><td><?=gcd_money((int)$reporte['totalDenom'])?></td></tr></tbody></table>
<p class="footnote">Conteo registrado por: <?=gcd_h($reporte['operadorConteo']?:'No identificado')?>.</p>
<?php else:?><p class="empty"><?=$estaCerrado?'No existe un desglose válido guardado.':'El conteo se captura al realizar el corte autorizado.'?></p><?php endif;?></article></section>
<section class="card signatures"><h2>Constancia y responsables</h2><div class="people"><div><small>Abrió la caja</small><b><?=gcd_h($t['abierto_por'])?></b></div><div><small>Solicitó el corte</small><b><?=gcd_h($firma['solicitante']??'Pendiente / no registrado')?></b></div><div><small>Autorizó el corte</small><b><?=gcd_h($firma['autorizador']??$t['cerrado_por']??'Pendiente')?></b><small><?=gcd_h($firma['autorizador_rol']??'')?></small></div></div>
<p><b>Apertura:</b> <?=gcd_h($t['abierto_en'])?> &nbsp; <b>Cierre:</b> <?=gcd_h($t['cerrado_en']??'Aún abierto')?></p>
<div class="signatures-lines"><div>Firma de entrega: __________________________</div><div>Firma del responsable: __________________________</div></div>
<p class="footnote">Generado: <?=gcd_h(date('Y-m-d H:i:s'))?>. Informe de solo lectura. No genera retiros, devoluciones, movimientos de inventario, ajustes de saldos ni un segundo cierre.</p></section>
<?php endif;?></main>
<script src="assets/js/gyd_excel_reportes.js" defer></script>

<?php if (!defined('GYD_HIDE_GLOBAL_NAV')): ?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=4">
<script>window.GYD_NAV_HOME='index.php';window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=4"></script>
<?php endif; ?>
</body></html>
