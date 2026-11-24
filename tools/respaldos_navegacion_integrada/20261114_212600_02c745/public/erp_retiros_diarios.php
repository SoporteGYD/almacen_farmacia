<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/gyd_retiros.php';
requireLogin();$u=currentUser()?:[];
if(!gr_admin($u)){http_response_code(403);exit('Reporte exclusivo de Administración y Dirección.');}
$fecha=(string)($_GET['fecha']??date('Y-m-d'));
$dt=DateTimeImmutable::createFromFormat('!Y-m-d',$fecha);
if(!$dt||$dt->format('Y-m-d')!==$fecha)$fecha=date('Y-m-d');
$error='';$retiros=[];$ventas=[];$turnos=[];$res=[];$total=0;
try{
 $db=(new Database())->connect();gr_test_db($db);
 $retiros=gr_query($db,"SELECT r.id,r.turno_id,r.importe,r.resolucion_en,r.referencia,s.nombre sucursal,c.nombre caja,uc.nombre cajero,us.nombre supervisor FROM erp_retiros_supervisados r JOIN pos_cajas c ON c.id=r.caja_id JOIN pos_sucursales s ON s.id=c.sucursal_id LEFT JOIN usuarios uc ON uc.id=r.cajero_id LEFT JOIN usuarios us ON us.id=r.revisor_id WHERE r.estado='CONFIRMADO' AND r.resolucion_en>=? AND r.resolucion_en<? ORDER BY s.nombre,r.resolucion_en",[$fecha.' 00:00:00',(new DateTimeImmutable($fecha))->modify('+1 day')->format('Y-m-d').' 00:00:00'])->fetchAll(PDO::FETCH_ASSOC);
 foreach($retiros as $r){$total+=gr_cents($r['importe']);$k=(string)$r['sucursal'];if(!isset($res[$k]))$res[$k]=['ventas'=>0,'retiros'=>0,'cuenta'=>0];$res[$k]['retiros']+=gr_cents($r['importe']);$res[$k]['cuenta']++;}
 $ventas=gr_query($db,"SELECT s.nombre sucursal,COALESCE(SUM(pg.monto),0) efectivo FROM pos_pagos pg JOIN pos_ventas v ON v.id=pg.venta_id JOIN pos_sucursales s ON s.id=v.sucursal_id WHERE v.estado='PAGADA' AND pg.estado='CONFIRMADO' AND pg.metodo='EFECTIVO' AND v.pagada_en>=? AND v.pagada_en<? GROUP BY s.id,s.nombre ORDER BY s.nombre",[$fecha.' 00:00:00',(new DateTimeImmutable($fecha))->modify('+1 day')->format('Y-m-d').' 00:00:00'])->fetchAll(PDO::FETCH_ASSOC);
 foreach($ventas as $v){$k=(string)$v['sucursal'];if(!isset($res[$k]))$res[$k]=['ventas'=>0,'retiros'=>0,'cuenta'=>0];$res[$k]['ventas']=gr_cents($v['efectivo']);}
 $turnos=gr_query($db,"SELECT t.id,t.estado,t.fondo_inicial,t.efectivo_contado,t.diferencia_efectivo,t.abierto_en,t.cerrado_en,s.nombre sucursal,c.nombre caja,COALESCE(m.retiros,0) retiros_turno FROM pos_turnos t JOIN pos_cajas c ON c.id=t.caja_id JOIN pos_sucursales s ON s.id=c.sucursal_id LEFT JOIN (SELECT turno_id,SUM(monto) retiros FROM pos_movimientos_caja WHERE tipo='RETIRO' GROUP BY turno_id) m ON m.turno_id=t.id WHERE t.abierto_en>=? AND t.abierto_en<? ORDER BY s.nombre,t.id DESC LIMIT 200",[$fecha.' 00:00:00',(new DateTimeImmutable($fecha))->modify('+1 day')->format('Y-m-d').' 00:00:00'])->fetchAll(PDO::FETCH_ASSOC);
 if(($_GET['exportar']??'')==='csv'){
  header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="gyd_retiros_'.$fecha.'.csv"');echo "\xEF\xBB\xBF";
  $out=fopen('php://output','w');fputcsv($out,['Fecha confirmación','Sucursal','Caja','Turno','Folio retiro','Importe MXN','Cajero','Supervisor','Referencia']);
  foreach($retiros as $r){$safe=static function($s):string{$s=(string)$s;return preg_match('/^[\\x00-\\x20]*[=+@\\-]/',$s)?"'".$s:$s;};fputcsv($out,[$r['resolucion_en'],$safe($r['sucursal']),$safe($r['caja']),$r['turno_id'],$r['id'],$r['importe'],$safe($r['cajero']),$safe($r['supervisor']),$safe($r['referencia'])]);}
  fclose($out);exit;
 }
}catch(Throwable $e){$error='No se pudo generar el corte diario. Revisa la instalación.';error_log('G&D reporte retiros: '.$e->getMessage());}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>G&D | Corte diario y retiros</title><style>*{box-sizing:border-box}body{margin:0;background:#edf3f9;font:15px system-ui;color:#173857}header{background:#174875;color:white;padding:23px}header a{color:white}main{max-width:1210px;margin:20px auto;padding:0 16px}section{background:white;padding:19px;border-radius:12px;margin:15px 0;border:1px solid #dbe6f0}.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.stats div{padding:15px;background:#f0f6fc;border-radius:9px}.stats b{display:block;font-size:24px}table{width:100%;border-collapse:collapse;min-width:650px}th,td{padding:9px;border-bottom:1px solid #dee7ef;text-align:left;font-size:13px}th{background:#165489;color:white}.scroll{overflow:auto}input,button,a.btn{padding:10px;font:inherit;border-radius:8px}button,.btn{background:#195d9a;border:0;color:white;text-decoration:none;cursor:pointer}button{font-weight:700}.warn{padding:12px;background:#fff4dc}.error{background:#ffe5e5;padding:10px}@media(max-width:600px){.stats{grid-template-columns:1fr}}@media print{button,.btn,form{display:none!important}body{background:white}}</style></head><body>
<header><h1>Cortes y retiros del día · ERP G&D</h1><a href="erp_control_cajas.php">← Control de Cajas</a> · <a href="erp_cortes_boveda.php">Cortes detallados por turno</a> · <a href="erp_boveda_verificacion.php">Verificar bóveda</a></header><main>
<?php if($error):?><p class="error"><?=gr_h($error)?></p><?php endif;?>
<section><form method="get"><label>Fecha del corte <input type="date" name="fecha" value="<?=gr_h($fecha)?>" required></label> <button>Consultar</button> <a class="btn" href="?fecha=<?=gr_h($fecha)?>&amp;exportar=csv">Exportar retiros CSV</a> <button type="button" onclick="window.print()">Imprimir / PDF</button></form>
<p class="warn">Los retiros nuevos son registrados inmediatamente por el cajero; bóveda los verifica posteriormente. Aquí también aparecen los retiros históricos confirmados por un supervisor. Ya se descuentan del efectivo esperado en el cierre del POS; no los vuelvas a capturar como movimientos manuales. Los ingresos de efectivo, devoluciones y turnos que cruzan de fecha deben revisarse individualmente.</p></section>
<section><div class="stats"><div>Retiros confirmados del día<b><?=gr_money($total)?></b></div><div>Entregas confirmadas<b><?=count($retiros)?></b></div><div>Un mismo libro POS<b>Sin doble descuento</b></div></div></section>
<section><h2>Totales por sucursal — <?=gr_h($fecha)?></h2><div class="scroll"><table><thead><tr><th>Sucursal</th><th>Ventas en efectivo del día</th><th>Retiros supervisados</th><th>Cantidad de retiros</th></tr></thead><tbody><?php foreach($res as $s=>$v):?><tr><td><?=gr_h($s)?></td><td><?=gr_money($v['ventas'])?></td><td><?=gr_money($v['retiros'])?></td><td><?=$v['cuenta']?></td></tr><?php endforeach;?></tbody></table></div><small>Las ventas y los retiros están agrupados por su propia fecha. No se interpreta esta diferencia como saldo de un turno ni como dinero disponible.</small></section>
<section><h2>Retiros registrados en el libro POS</h2><div class="scroll"><table><thead><tr><th>Fecha</th><th>Folio</th><th>Sucursal</th><th>Caja / turno</th><th>Cajero</th><th>Supervisor</th><th>Importe</th><th>Referencia</th></tr></thead><tbody><?php foreach($retiros as $r):?><tr><td><?=gr_h($r['resolucion_en'])?></td><td>#<?=(int)$r['id']?></td><td><?=gr_h($r['sucursal'])?></td><td><?=gr_h($r['caja'])?> / #<?=(int)$r['turno_id']?></td><td><?=gr_h($r['cajero'])?></td><td><?=gr_h($r['supervisor'])?></td><td><?=gr_money(gr_cents($r['importe']))?></td><td><?=gr_h($r['referencia'])?></td></tr><?php endforeach;?></tbody></table></div></section>
<section><h2>Turnos abiertos en el día (resumen de cortes)</h2><div class="scroll"><table><thead><tr><th>Turno</th><th>Sucursal</th><th>Caja</th><th>Apertura</th><th>Estado</th><th>Retiros TOTAL turno (incluye manuales)</th><th>Contado al cierre</th><th>Diferencia</th></tr></thead><tbody><?php foreach($turnos as $t):?><tr><td>#<?=(int)$t['id']?></td><td><?=gr_h($t['sucursal'])?></td><td><?=gr_h($t['caja'])?></td><td><?=gr_h($t['abierto_en'])?></td><td><?=gr_h($t['estado'])?></td><td><?=gr_money(gr_cents($t['retiros_turno']))?></td><td><?=($t['efectivo_contado']===null?'—':gr_money(gr_cents($t['efectivo_contado'])))?></td><td><?=($t['diferencia_efectivo']===null?'—':gr_money((int)round((float)$t['diferencia_efectivo']*100)))?></td></tr><?php endforeach;?></tbody></table></div></section>
</main>
<script src="assets/js/gyd_excel_reportes.js" defer></script>

<?php if (!defined('GYD_HIDE_GLOBAL_NAV')): ?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=1">
<script>window.GYD_NAV_HOME='index.php';window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=1"></script>
<?php endif; ?>
</body></html>
