<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/helpers/erp_oc_recepciones.php';
require_once __DIR__.'/../app/helpers/pos_inteligente.php';
require_once __DIR__.'/../app/config/database.php';
requireLogin();
$usr=currentUser()?:[];$perm=erpr_acceso($usr);
$id=(int)($_GET['oc']??0);if($id<1){http_response_code(400);exit('Selecciona una OC válida.');}
$page=max(1,(int)($_GET['pag']??1));$limit=100;$offset=($page-1)*$limit;$posSinConfigurar=false;$esDestinoBodega=false;$error='';$items=[];$oc=null;$more=false;$posStockId=0;$poolCompartido=false;
try{
  $db=(new Database())->connect();
  $s=$db->prepare('SELECT o.id,o.folio,o.destino,p.nombre proveedor FROM erp_ordenes_compra o JOIN proveedores p ON p.id=o.proveedor_id WHERE o.id=?');
  $s->execute([$id]);$oc=$s->fetch(PDO::FETCH_ASSOC);
  if(!$oc||!erpr_puede_ver_destino($perm,(string)$oc['destino'])){http_response_code(403);exit('Orden no encontrada o no autorizada para este destino.');}
  $dest=(string)$oc['destino'];$whName=str_starts_with($dest,'HIDALGO')?'CIUDAD HIDALGO':(str_starts_with($dest,'TUXTLA')?'TUXTLA':'');
  $posName=match(true){str_starts_with($dest,'HIDALGO')=>'CIUDAD HIDALGO',str_starts_with($dest,'TUXTLA')=>'TUXTLA GUTIERREZ',$dest==='TAPACHULA'=>'TAPACHULA',$dest==='TOSCANA'=>'PLAZA TOSCANA',default=>''};
  $s=$db->prepare('SELECT id FROM pos_sucursales WHERE UPPER(TRIM(nombre))=? LIMIT 1');$s->execute([$posName]);$posId=(int)$s->fetchColumn();
  $posSinConfigurar=$posId<1;
  $posStockId=$posId>0?pi_tt_stock_sid($db,$posId):0;
  $poolCompartido=in_array($posId,[3,4],true) && $posStockId===3 && pi_tt_activo($db);
  $esDestinoBodega=str_ends_with($dest,'_BODEGA');
  $sql="SELECT d.producto_id,MAX(d.codigo_snapshot) AS codigo,MAX(d.descripcion_snapshot) AS descripcion,
          SUM(d.cantidad_piezas) AS solicitadas,
          COALESCE(r.recibidas,0) AS recibidas,
          COALESCE(al.aplicadas_bodega,0) AS entradas_vinculadas,
          COALESCE(ex.existencia,0) AS actual_almacen,
          COALESCE(pos.existencia,0) AS actual_pos
        FROM erp_ordenes_compra_detalle d
        LEFT JOIN (SELECT rd.producto_id,r.orden_id,SUM(rd.cantidad_piezas) AS recibidas
                   FROM erp_oc_recepciones r JOIN erp_oc_recepciones_detalle rd ON rd.recepcion_id=r.id
                   WHERE r.estado='REGISTRADA' GROUP BY rd.producto_id,r.orden_id) r
          ON r.producto_id=d.producto_id AND r.orden_id=d.orden_id
        LEFT JOIN (SELECT rd.producto_id,r.orden_id,SUM(rd.cantidad_piezas) AS aplicadas_bodega
                   FROM erp_oc_entrada_vinculos v JOIN erp_oc_recepciones r ON r.id=v.recepcion_id
                   JOIN erp_oc_recepciones_detalle rd ON rd.recepcion_id=r.id
                   GROUP BY rd.producto_id,r.orden_id) al
          ON al.producto_id=d.producto_id AND al.orden_id=d.orden_id
        LEFT JOIN (SELECT producto_id,SUM(existencia) existencia FROM producto_existencias
                   WHERE sucursal=? GROUP BY producto_id) ex ON ex.producto_id=d.producto_id
        LEFT JOIN (SELECT producto_id,SUM(existencia) existencia FROM pos_existencias_farmacia
                   WHERE sucursal_id=? GROUP BY producto_id) pos ON pos.producto_id=d.producto_id
        WHERE d.orden_id=?
        GROUP BY d.producto_id,r.recibidas,al.aplicadas_bodega,ex.existencia,pos.existencia
        ORDER BY codigo LIMIT ".($limit+1)." OFFSET ".$offset;
  $s=$db->prepare($sql);$s->execute([$whName,$posStockId,$id]);$items=$s->fetchAll(PDO::FETCH_ASSOC);
  $more=count($items)>$limit;if($more)array_pop($items);
}catch(Throwable $e){error_log('OCAV inventarios: '.$e->getMessage());$error='No se pudieron consultar ambas fuentes. Revisa la base de datos y la instalación del parche.';}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>G&D | Inventarios separados</title><style>
body{margin:0;background:#eef3f9;color:#163c60;font:15px system-ui,sans-serif}header{background:#143f6b;color:white;padding:22px max(16px,calc((100% - 1180px)/2))}a{color:#1361a4}main{max-width:1180px;margin:20px auto;padding:0 16px}.card{background:white;border-radius:12px;padding:18px;margin:14px 0}.warn{background:#fff3d9;padding:15px;border:1px solid #e5c58a;border-radius:9px}.scroll{overflow:auto}table{border-collapse:collapse;width:100%;min-width:900px}th,td{padding:10px;text-align:left;border-bottom:1px solid #e5ebf1;font-size:13px}th{background:#164b7b;color:white}.btn{display:inline-block;background:#195c9c;color:white;text-decoration:none;padding:10px;border-radius:8px}</style></head><body><header><h1>Existencias separadas: Almacén y Farmacia</h1><a href="erp_recepciones_pendientes.php" style="color:white">← Recepciones OC</a></header><main>
<div class="card"><h2>OC <?=erpr_html($oc['folio']??'#'.$id)?></h2><p>Proveedor: <?=erpr_html($oc['proveedor']??'')?> · Destino: <?=erpr_html($oc['destino']??'')?></p><p class="warn">Las existencias de Bodega y del POS <b>no tienen que coincidir</b>: corresponden a espacios físicos distintos, con ventas y movimientos diferentes. Este reporte no ajusta inventarios. La comparación sirve para investigar diferencias documentadas; no representa un saldo que debamos forzar a cero. Para Tuxtla, la existencia de Almacén puede agrupar distintas bodegas de la misma sucursal.</p><?php if($poolCompartido):?><p class="warn"><b>Inventario compartido:</b> Tapachula y Plaza Toscana consultan el mismo saldo general POS. El número mostrado corresponde a ese saldo único, no a existencias independientes de la sucursal. No sumes ambas consultas como si fueran dos inventarios.</p><?php endif;?><?php if(!$error && $posSinConfigurar):?><p class="warn">La sucursal del POS no pudo identificarse en esta base. No interpretaremos ese dato faltante como existencia cero.</p><?php endif;?><?php if($error):?><p role="alert"><?=erpr_html($error)?></p><?php endif;?></div>
<div class="card scroll"><table><thead><tr><th>Código y producto</th><th>OC solicitadas</th><th>Recepciones Bodega</th><th>Entradas vinculadas</th><th>Existencia actual Almacén</th><th>Existencia actual Farmacia POS</th></tr></thead><tbody>
<?php foreach($items as $r):?><tr><td><b><?=erpr_html($r['codigo'])?></b><br><?=erpr_html($r['descripcion'])?></td><td><?=number_format((int)$r['solicitadas'])?></td><td><?=number_format((int)$r['recibidas'])?></td><td><?=number_format((int)$r['entradas_vinculadas'])?></td><td><?= $esDestinoBodega ? number_format((int)$r['actual_almacen']) : 'No corresponde' ?></td><td><?= $posSinConfigurar ? 'No configurada' : number_format((int)$r['actual_pos']) ?></td></tr><?php endforeach;?><?php if(!$items):?><tr><td colspan="6">No hay datos disponibles.</td></tr><?php endif;?></tbody></table></div>
<?php if($page>1):?><a class="btn" href="?oc=<?=$id?>&amp;pag=<?=$page-1?>">← Anterior</a><?php endif;?> <?php if($more):?><a class="btn" href="?oc=<?=$id?>&amp;pag=<?=$page+1?>">Siguiente →</a><?php endif;?></div></main>
<script src="assets/js/gyd_excel_reportes.js" defer></script>

<?php if (!defined('GYD_HIDE_GLOBAL_NAV')): ?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=1">
<script>window.GYD_NAV_HOME='index.php';window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=1"></script>
<?php endif; ?>
</body></html>
