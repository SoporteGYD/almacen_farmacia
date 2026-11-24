<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
requireLogin();
$u=currentUser()?:[];
$rol=strtoupper(trim((string)($u['rol_original']??$u['rol']??'')));
if(!in_array($rol,['DIRECCION','ADMINISTRADOR','ADMIN'],true)){
    http_response_code(403); exit('Acceso restringido a Administración y Dirección.');
}
function rvh47(mixed $s):string{return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$venta=filter_input(INPUT_GET,'venta',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
$folio=trim((string)($_GET['folio']??''));
$rows=[];$detalle=[];$error='';
try {
    $db=(new Database())->connect();
    $meta=$db->query('SELECT DATABASE() bd,@@port puerto')->fetch(PDO::FETCH_ASSOC);
    if(strtolower((string)$meta['bd'])!=='prueba'||(int)$meta['puerto']!==3307)
        throw new DomainException('Consulta disponible únicamente en la base de pruebas.');
    $p=[];$where='';
    if($venta>0){$where=' WHERE v.id=?';$p[]=$venta;}
    elseif($folio!==''){$where=' WHERE v.folio LIKE ?';$p[]='%'.mb_substr($folio,0,75).'%';}
    $sql="SELECT v.id,v.folio,v.estado,v.pagada_en,v.total,s.nombre sucursal,
      c.nombre cliente,cv.puntos_otorgados,
      COALESCE((SELECT SUM(-m.puntos) FROM gyd_puntos_movimientos m
         WHERE m.venta_id=v.id AND m.tipo='REVERSO' AND m.puntos<0),0) puntos_revertidos,
      (SELECT COUNT(*) FROM pos_reversiones r WHERE r.venta_id=v.id
         AND r.tipo='DEVOLUCION_PARCIAL' AND r.estado='APLICADA') devoluciones,
      (SELECT COUNT(*) FROM gyd_rewards_revision_v37 re WHERE re.venta_id=v.id
         AND re.estado_revision='PENDIENTE') revisiones
      FROM gyd_clientes_ventas cv
      JOIN gyd_clientes c ON c.id=cv.cliente_id
      JOIN pos_ventas v ON v.id=cv.venta_id
      JOIN pos_sucursales s ON s.id=v.sucursal_id $where
      ORDER BY v.id DESC LIMIT 80";
    $q=$db->prepare($sql);$q->execute($p);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if($venta>0){
      $q=$db->prepare("SELECT d.id,d.descripcion_snapshot,d.cantidad,d.presentacion,
       l.ingreso_elegible_centavos,l.puntos_asignados,
       COALESCE((SELECT SUM(rd.puntos_revertidos) FROM gyd_rewards_devoluciones_v47 rd
          WHERE rd.detalle_id=d.id AND rd.estado='APLICADA'),0) puntos_devuelto,
       COALESCE((SELECT SUM(r.cantidad_presentaciones) FROM pos_reversiones r
          WHERE r.detalle_id=d.id AND r.estado='APLICADA'
          AND r.tipo='DEVOLUCION_PARCIAL'),0) cantidad_devuelta
       FROM pos_venta_detalle d LEFT JOIN gyd_rewards_lineas_v47 l ON l.detalle_id=d.id
       WHERE d.venta_id=? ORDER BY d.id");
      $q->execute([$venta]);$detalle=$q->fetchAll(PDO::FETCH_ASSOC);
    }
} catch(Throwable $e){error_log('Auditoría Rewards devoluciones: '.$e->getMessage());$error='No fue posible consultar la auditoría de Rewards. Confirma que el paquete esté instalado en prueba:3307.';}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>G&amp;D · Auditoría de puntos por devoluciones</title><style>
*{box-sizing:border-box}body{margin:0;background:#eef3fa;color:#173755;font:15px/1.5 system-ui,Segoe UI,Arial,sans-serif}
header{background:linear-gradient(110deg,#113961,#2362a4);color:#fff;padding:24px max(18px,calc((100% - 1150px)/2))}
header h1{font-size:24px;margin:0}header p{margin:4px 0;color:#d5e6f8}
main{max-width:1150px;margin:24px auto;padding:0 16px}.card{background:#fff;border-radius:15px;padding:20px;margin-bottom:16px;box-shadow:0 8px 28px #123e6b12}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin:0 0 18px}.btn,button{background:#165a9d;color:#fff;text-decoration:none;border:0;border-radius:9px;font:700 13px system-ui;padding:10px 14px;cursor:pointer}
.btn.alt{background:#e5eef8;color:#164a7c}label{font-weight:700;display:block;margin-bottom:5px}input{font:inherit;padding:10px 12px;border-radius:9px;border:1px solid #c3d2e0;max-width:440px;width:100%}
form{display:flex;gap:10px;flex-wrap:wrap;align-items:end}table{width:100%;border-collapse:collapse;min-width:760px}th,td{text-align:left;padding:11px;border-bottom:1px solid #e3eaf3;font-size:13px}th{background:#1a538a;color:white}.overflow{overflow:auto}
.hint{padding:13px;border-radius:10px;background:#fff7e7;color:#684915;border:1px solid #e6cb8d}small{color:#55718f}
@media print{.actions,form{display:none}body{background:#fff}}
</style></head><body><header><h1>Auditoría de puntos por devoluciones</h1><p>Consulta de ventas y reversos — sin modificar puntos ni inventario</p></header>
<main><div class="actions"><a class="btn alt" href="erp_pos_reversiones.php">Regresar a devoluciones</a><a class="btn alt" href="pos.php">Punto de Venta</a><a class="btn" href="dashboard.php">Inicio ERP</a></div>
<?php if($error):?><div class="card hint"><?=rvh47($error)?></div><?php endif;?>
<div class="card"><h2>Consultar ventas con clientes</h2><form method="get"><div><label for="folio">Folio de venta</label><input id="folio" name="folio" value="<?=rvh47($folio)?>" placeholder="PV-01-0000123"></div><button>Buscar</button></form>
<p><small>Una venta histórica sin desglose no se recalcula de forma arbitraria: aparece como pendiente de revisión.</small></p></div>
<div class="card overflow"><table><thead><tr><th>Folio</th><th>Sucursal</th><th>Cliente</th><th>Estado</th><th>Puntos ganados</th><th>Puntos reversados</th><th>Parciales</th><th>Revisión</th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr><td><a href="?venta=<?=(int)$r['id']?>"><?=rvh47($r['folio'])?></a></td><td><?=rvh47($r['sucursal'])?></td><td><?=rvh47($r['cliente'])?></td><td><?=rvh47($r['estado'])?></td><td><?=rvh47($r['puntos_otorgados'])?></td><td><?=rvh47($r['puntos_revertidos'])?></td><td><?=rvh47($r['devoluciones'])?></td><td><?=((int)$r['revisiones']>0)?'Pendiente':'Sin pendientes'?></td></tr><?php endforeach;?>
<?php if(!$rows):?><tr><td colspan="8">Sin resultados.</td></tr><?php endif;?></tbody></table></div>
<?php if($venta>0):?><div class="card"><h2>Detalle por partida</h2><div class="overflow"><table><thead><tr><th>Producto</th><th>Vendidas</th><th>Devueltas</th><th>Ingreso elegible</th><th>Puntos asignados</th><th>Puntos reversados</th></tr></thead><tbody>
<?php foreach($detalle as $d):?><tr><td><?=rvh47($d['descripcion_snapshot'])?></td><td><?=rvh47($d['cantidad'].' '.$d['presentacion'])?></td><td><?=rvh47($d['cantidad_devuelta'])?></td><td><?=isset($d['ingreso_elegible_centavos'])?'$'.number_format((int)$d['ingreso_elegible_centavos']/100,2):'Histórica'?></td><td><?=isset($d['puntos_asignados'])?rvh47($d['puntos_asignados']):'Sin desglose'?></td><td><?=rvh47($d['puntos_devuelto'])?></td></tr><?php endforeach;?>
</tbody></table></div></div><?php endif;?></main></body></html>
