<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
requireLogin();
$usuario=currentUser()?:[];
$rol=strtoupper(trim((string)($usuario['rol_original']??$usuario['rol']??'')));
if(!in_array($rol,['ADMINISTRADOR','ADMIN','DIRECCION'],true)){
    http_response_code(403);exit('Acceso restringido: Reservas de Almacén.');
}
function ra_h(mixed $s):string{return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function ra_n(mixed $n):string{$n=(float)$n;return number_format($n,abs($n-round($n))<0.0005?0:3,'.',',');}
$sid=(int)($_GET['sucursal']??1);if(!in_array($sid,[1,2],true))$sid=1;
$almacen=$sid===1?1:3;
$soloActivos=($_GET['estado']??'activas')!=='todos';
$buscar=mb_substr(trim((string)($_GET['q']??'')),0,80);
$sucursales=[1=>'Ciudad Hidalgo',2=>'Tuxtla Gutiérrez'];
$filas=[];$error='';$conteoPendiente=0;$pendientePOS=0;$cobradoNoEntregado=0;
try {
 $db=(new Database())->connect();
 $st=$db->prepare("SELECT COALESCE(SUM(GREATEST(d.cantidad_solicitada-d.cantidad_surtida,0)),0) FROM resurtidos r JOIN resurtido_detalles d ON d.resurtido_id=r.id WHERE r.almacen_id=? AND r.estado IN ('PENDIENTE','EN_PROCESO','PARCIAL')");
 $st->execute([$almacen]);$conteoPendiente=(float)$st->fetchColumn();
 $st=$db->prepare("SELECT COALESCE(SUM(CASE WHEN t.venta_id IS NULL THEN GREATEST(t.cantidad-d.cantidad_surtida,0) ELSE 0 END),0) sin_cobrar,COALESCE(SUM(CASE WHEN t.venta_id IS NOT NULL THEN GREATEST(t.cantidad-d.cantidad_surtida,0) ELSE 0 END),0) pendiente_entrega FROM erp_pos_tickets_almacen t JOIN resurtidos r ON r.id=t.resurtido_id JOIN resurtido_detalles d ON d.resurtido_id=t.resurtido_id AND d.producto_id=t.producto_id WHERE r.almacen_id=? AND r.estado IN ('PENDIENTE','EN_PROCESO','PARCIAL')");
 $st->execute([$almacen]);$s=$st->fetch(PDO::FETCH_ASSOC)?:[];$pendientePOS=(float)($s['sin_cobrar']??0);$cobradoNoEntregado=(float)($s['pendiente_entrega']??0);
 $where="r.almacen_id=?"; $params=[$almacen];
 if($soloActivos)$where.=" AND r.estado IN ('PENDIENTE','EN_PROCESO','PARCIAL')";
 if($buscar!==''){$where.=' AND (p.codigo LIKE ? OR p.descripcion LIKE ? OR r.folio LIKE ? OR r.folio_documento LIKE ?)';$b='%'.$buscar.'%';array_push($params,$b,$b,$b,$b);}
 $sql="SELECT r.id,r.folio,r.folio_documento,r.tipo_solicitud,r.estado,r.fecha_solicitud,d.producto_id,d.cantidad_solicitada,d.cantidad_surtida,p.codigo,p.descripcion,u.nombre solicitante,t.id pos_ticket_id,t.venta_id,v.folio folio_venta
  FROM resurtidos r INNER JOIN resurtido_detalles d ON d.resurtido_id=r.id INNER JOIN productos p ON p.id=d.producto_id
  LEFT JOIN usuarios u ON u.id=r.solicitante_id LEFT JOIN erp_pos_tickets_almacen t ON t.resurtido_id=r.id AND t.producto_id=d.producto_id
  LEFT JOIN pos_ventas v ON v.id=t.venta_id WHERE ".$where." ORDER BY CASE WHEN r.estado IN ('PENDIENTE','EN_PROCESO','PARCIAL') THEN 0 ELSE 1 END,r.fecha_solicitud DESC,r.id DESC LIMIT 150";
 $st=$db->prepare($sql);$st->execute($params);$filas=$st->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e){error_log('ERP reservas almacén: '.$e->getMessage());$error='No se pudo consultar las reservas. Revisa que esté instalado POS Inteligente y la conexión a MariaDB.';}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ERP G&D | Reservas de Almacén</title>
<style>
:root{font-family:system-ui,Segoe UI,sans-serif;background:#edf3fa;color:#163958}*{box-sizing:border-box}body{margin:0}header{background:linear-gradient(105deg,#123963,#1e64ac);color:#fff;padding:25px max(16px,calc((100% - 1210px)/2))}header h1{margin:0 0 5px;font-size:25px}header p{margin:0;opacity:.88}main{max-width:1250px;margin:22px auto;padding:0 16px}.links{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}.btn,button{display:inline-block;border:0;border-radius:9px;padding:10px 13px;background:#155eaf;color:#fff;font:600 14px system-ui;text-decoration:none;cursor:pointer}.btn.light{background:#e4edf8;color:#174777}.card{background:white;border-radius:14px;padding:20px;margin-bottom:14px;box-shadow:0 5px 20px #184c8212}.muted{color:#647c96;font-size:13px}.controls{display:flex;align-items:end;gap:12px;flex-wrap:wrap}label{display:block;font-size:13px;font-weight:700;margin-bottom:5px}select,input{padding:10px 11px;border:1px solid #c8d6e8;border-radius:8px;font:inherit}.metricbox{display:flex;gap:12px;flex-wrap:wrap;margin:16px 0}.metric{padding:14px;border-radius:11px;background:#edf5fd;min-width:180px;flex:1}.metric b{display:block;font-size:23px;margin-top:4px}.notice{background:#fff2dd;padding:13px;border-radius:10px;border:1px solid #f2d09b}.error{background:#ffe4e5}.scroll{overflow:auto}table{border-collapse:collapse;width:100%;min-width:1000px}th,td{font-size:13px;padding:11px;border-bottom:1px solid #e4edf5;text-align:left}th{background:#184e86;color:#fff}td.num{text-align:right;font-variant-numeric:tabular-nums}.pill{display:inline-block;border-radius:99px;padding:5px 9px;font-size:11px;font-weight:800;background:#e7edf5;color:#315273}.pill.warn{background:#fff1d5;color:#875000}.pill.bad{background:#ffe4e7;color:#a6203e}.pill.ok{background:#e1f7e8;color:#187249}a{color:#1f62ad}@media(max-width:650px){.card{padding:14px}header h1{font-size:21px}}
</style></head><body>
<header><h1>Reservas de Almacén · ERP G&amp;D</h1><p>Seguimiento de pedidos del POS y resurtidos existentes · Sin generar movimientos nuevos</p></header>
<main><div class="links"><a href="dashboard.php" class="btn light">← Inicio</a><a href="erp_conciliacion_inventarios.php?sucursal=<?=$sid?>" class="btn light">📊 Conciliación</a><a href="<?= $sid===1?'tickets.php':'resurtidos.php' ?>" class="btn light">Ver solicitudes de Almacén</a><a href="logout.php" class="btn light">Cerrar sesión</a></div>
<section class="card"><form method="get" class="controls"><div><label for="sucursal">Sucursal</label><select name="sucursal" id="sucursal"><?php foreach($sucursales as $key=>$val):?><option value="<?= $key ?>" <?= $key===$sid?'selected':''?>><?=ra_h($val)?></option><?php endforeach; ?></select></div><div><label for="estado">Solicitudes</label><select name="estado" id="estado"><option value="activas" <?= $soloActivos?'selected':'' ?>>Pendientes / Parciales</option><option value="todos" <?= !$soloActivos?'selected':'' ?>>Todas (hasta 150)</option></select></div><div><label for="q">Código, producto o folio</label><input name="q" id="q" value="<?=ra_h($buscar)?>" placeholder="Buscar"></div><button>Filtrar</button></form></section>
<section class="card"><div class="notice"><strong>Reservas sin doble descuento:</strong> al crear un ticket de Almacén las piezas se apartan dentro del sistema de resurtidos; no se descuentan todavía del POS. Al cobrar, el POS descuenta la venta general una sola vez. La salida física se registra después en Almacén, sin volver a descontar el POS. Las cantidades mostradas se consultan en tiempo real.</div>
<?php if($error):?><p class="notice error" role="alert"><?=ra_h($error)?></p><?php endif; ?>
<div class="metricbox"><div class="metric">Piezas reservadas en Almacén (todos los pedidos activos)<b><?=ra_n($conteoPendiente)?></b></div><div class="metric">Tickets POS todavía sin cobrar<b><?=ra_n($pendientePOS)?></b></div><div class="metric">Tickets POS cobrados aún no entregados<b><?=ra_n($cobradoNoEntregado)?></b></div></div>
<div class="scroll"><table><thead><tr><th>Folio</th><th>Origen / Cajero</th><th>Producto</th><th>Solicitado</th><th>Surtido</th><th>Reservado pendiente</th><th>Estado</th><th>Venta POS</th><th>Fecha</th></tr></thead><tbody>
<?php foreach($filas as $r):
$restante=max(0,(float)$r['cantidad_solicitada']-(float)$r['cantidad_surtida']);
if($r['estado']==='CANCELADO'){$etiqueta='CANCELADO';$clase='bad';}
elseif($r['pos_ticket_id']!==null && $r['venta_id']!==null && $restante>0){$etiqueta='COBRADO · PENDIENTE DE ENTREGA';$clase='warn';}
elseif($r['pos_ticket_id']!==null && $r['venta_id']===null && $restante>0){$etiqueta='RESERVADO · SIN COBRAR';$clase='warn';}
elseif($restante>0){$etiqueta='RESERVA ACTIVA';$clase='warn';}
else{$etiqueta='SURTIDO';$clase='ok';}
?><tr><td><strong><?=ra_h($r['folio'])?></strong><br><span class="muted"><?=ra_h($r['folio_documento']??'')?></span></td><td><?= $r['pos_ticket_id']!==null?'CAJERO POS':'SOLICITANTE' ?><br><strong><?=ra_h($r['solicitante']??'Sin identificar')?></strong></td><td><?=ra_h($r['codigo'])?><br><span class="muted"><?=ra_h($r['descripcion'])?></span></td><td class="num"><?=ra_n($r['cantidad_solicitada'])?></td><td class="num"><?=ra_n($r['cantidad_surtida'])?></td><td class="num"><?=ra_n($restante)?></td><td><span class="pill <?=$clase?>"><?=ra_h($etiqueta)?></span></td><td><?=ra_h($r['folio_venta']??'—')?></td><td><?=ra_h($r['fecha_solicitud'])?></td></tr><?php endforeach; ?>
<?php if(!$filas):?><tr><td colspan="9">No hay reservas en esta consulta.</td></tr><?php endif; ?></tbody></table></div><p class="muted">Se muestran hasta 150 renglones recientes según los filtros. Las reservas solo se liberan por el flujo existente de surtido/cancelación; esta pantalla no modifica sus estados.</p></section></main>
<script src="assets/js/gyd_excel_reportes.js" defer></script>

<?php if (!defined('GYD_HIDE_GLOBAL_NAV')): ?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=1">
<script>window.GYD_NAV_HOME='index.php';window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=1"></script>
<?php endif; ?>
</body></html>
