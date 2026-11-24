<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
requireLogin();
$user=currentUser()??[];
$role=strtoupper(trim((string)($user['rol_original']??$user['rol']??'')));
if(!in_array($role,['ADMINISTRADOR','ADMIN','DIRECCION'],true)){http_response_code(403);exit('No autorizado.');}
header('Cache-Control: private,no-store');
function hediag($v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$error='';$counts=[];$last=[];$daily=[];$missing=[];
try{
 $pdo=(new Database())->connect();
 $exists=$pdo->query("SHOW TABLES LIKE 'erp_pos_eficiencia_cajeros'")->fetchColumn();
 if(!$exists)throw new RuntimeException('No existe erp_pos_eficiencia_cajeros. Ejecuta el SQL del ZIP inicial de Eficiencia de Cajeros en la base prueba.');
 $cols=$pdo->query('SHOW COLUMNS FROM pos_venta_detalle')->fetchAll(PDO::FETCH_COLUMN,0);
 foreach(['unidades_descontadas','piezas_por_presentacion'] as $optional)if(!in_array($optional,$cols,true))$missing[]=$optional;
 $counts=$pdo->query("SELECT estado,COUNT(*) AS total FROM erp_pos_eficiencia_cajeros GROUP BY estado")->fetchAll(PDO::FETCH_ASSOC);
 $last=$pdo->query("SELECT e.id,e.estado,e.venta_id,e.inicio_en,e.final_en,e.total_seg,e.captura_seg,u.nombre AS usuario,s.nombre AS sucursal FROM erp_pos_eficiencia_cajeros e JOIN usuarios u ON u.id=e.usuario_id JOIN pos_sucursales s ON s.id=e.sucursal_id ORDER BY e.id DESC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);
 $daily=$pdo->query("SELECT DATE(v.pagada_en) AS fecha,COUNT(*) AS ventas,COUNT(e.venta_id) AS con_tiempo FROM pos_ventas v LEFT JOIN erp_pos_eficiencia_cajeros e ON e.venta_id=v.id AND e.estado='COMPLETADA' WHERE v.pagada_en >= DATE_SUB(CURDATE(),INTERVAL 7 DAY) GROUP BY DATE(v.pagada_en) ORDER BY fecha DESC")->fetchAll(PDO::FETCH_ASSOC);
}catch(Throwable $e){$error=$e->getMessage();error_log('ERP diag eficiencia: '.$e->getMessage());}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Diagnóstico de Tiempos | ERP G&amp;D</title>
<style>*{box-sizing:border-box}body{font:15px system-ui,Segoe UI,sans-serif;background:#eff4fa;color:#163855;margin:0}main{max-width:1100px;padding:22px;margin:auto}header{background:#163f70;color:white;padding:22px max(20px,calc((100% - 1100px)/2))}h1{margin:0;font-size:26px}.card{background:white;border:1px solid #dee7f1;border-radius:14px;padding:20px;margin:16px 0;box-shadow:0 3px 15px #1d3e6711}.err{background:#ffedf0;color:#8e1537;padding:12px;border-radius:9px}.note{background:#e9f3ff;padding:12px;border-radius:9px}a{color:#155aaa}header a{color:white}table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #dee7f1;font-size:13px;text-align:left}th{background:#eaf1f8}.scroll{overflow-x:auto}.tag{font-weight:750}.muted{color:#60758b}ul{line-height:1.8}</style></head><body><header><h1>Diagnóstico del cronómetro POS</h1><p><a href="erp_eficiencia_cajeros.php">← Volver al reporte</a> · Solo ADMINISTRADOR / DIRECCIÓN</p></header><main>
<?php if($error):?><div class="card err"><?=hediag($error)?></div><?php else:?>
<section class="card"><h2>Estado de la medición</h2><p>Los registros <strong>INICIADA</strong> significan que se inició un cronómetro pero no se vinculó una venta; <strong>COMPLETADA</strong> significa que el tiempo quedó registrado en una venta.</p><div class="scroll"><table><tr><th>Estado</th><th>Registros</th></tr><?php foreach($counts as $c):?><tr><td class="tag"><?=hediag($c['estado'])?></td><td><?=number_format((int)$c['total'])?></td></tr><?php endforeach;?><?php if(!$counts):?><tr><td colspan="2">Aún no existe ninguna medición: revisa si el JavaScript nuevo está cargando y el turno está abierto.</td></tr><?php endif;?></table></div>
<?php if($missing):?><p class="note">Esta base no tiene las columnas opcionales <?=hediag(implode(', ',$missing))?> del detalle del POS. Esta corrección es compatible y no perderá la medición por esa causa.</p><?php endif;?></section>
<section class="card"><h2>Ventas de los últimos siete días</h2><div class="scroll"><table><tr><th>Fecha</th><th>Ventas</th><th>Con tiempo</th></tr><?php foreach($daily as $d):?><tr><td><?=hediag($d['fecha'])?></td><td><?=number_format((int)$d['ventas'])?></td><td><?=number_format((int)$d['con_tiempo'])?></td></tr><?php endforeach;?></table></div></section>
<section class="card"><h2>Últimas mediciones</h2><div class="scroll"><table><tr><th>Fecha inicio</th><th>Usuario</th><th>Sucursal</th><th>Estado</th><th>Venta ID</th><th>Duración</th><th>Captura</th></tr><?php foreach($last as $m):?><tr><td><?=hediag($m['inicio_en'])?></td><td><?=hediag($m['usuario'])?></td><td><?=hediag($m['sucursal'])?></td><td><?=hediag($m['estado'])?></td><td><?=hediag($m['venta_id']??'—')?></td><td><?=$m['total_seg']===null?'—':(int)$m['total_seg'].' s'?></td><td><?=$m['captura_seg']===null?'—':(int)$m['captura_seg'].' s'?></td></tr><?php endforeach;?><?php if(!$last):?><tr><td colspan="7">Sin mediciones.</td></tr><?php endif;?></table></div></section>
<?php endif;?>
<section class="card"><h2>Comprobación de extremo a extremo</h2><ul><li>Entra al POS, abre caja y agrega un producto.</li><li>Observa el nuevo indicador «⏱ Medición». Debe decir «cronometrando ticket».</li><li>Completa una venta de prueba (sin cerrar la pestaña). Debe cambiar a «ticket #... registrado».</li><li>Actualiza este diagnóstico y el reporte. Comprueba una fila COMPLETADA con el ID de esa venta.</li><li>Si ves un error, revisa <code>C:\xampp\apache\logs\error.log</code> y el estado «Medición» del POS.</li></ul><p class="muted">No se reconstruyen tiempos históricos. El cronómetro es orientativo y no debe utilizarse como calificación automática del personal.</p></section></main></body></html>
