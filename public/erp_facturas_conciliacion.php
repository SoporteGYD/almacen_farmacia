<?php
declare(strict_types=1);
/** ERP G&D · Conciliación automática de sólo lectura. NO mueve inventario. */
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_facturas.php';
require_once __DIR__.'/../app/helpers/erp_conciliacion_automatica.php';
requireLogin();
header('Cache-Control: private, no-store, max-age=0');
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);exit('Consulta de sólo lectura.');}
$usr=currentUser()?:[];
if((int)($usr['id']??0)<1 || !in_array(ef_rol($usr),['ADMINISTRADOR','ADMIN','DIRECCION','COMPRAS','GERENTE','ENCARGADO','JEFE_ALMACEN'],true)){
  http_response_code(403);exit('No tienes acceso a este reporte.');
}
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
$pagina=filter_input(INPUT_GET,'pagina',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:1;
$pagina=min($pagina,1000000);
$exportar=isset($_GET['exportar']) && $_GET['exportar']==='csv';
$reporte=null;$lista=[];$error='';
try{
  $db=(new Database())->connect();
  if($id){
    // Control de acceso ANTES de obtener el detalle de existencias, facturas y costos.
    $s=$db->prepare('SELECT sucursal FROM erp_facturas WHERE id=?');$s->execute([$id]);$sucursal=$s->fetchColumn();
    if($sucursal===false){http_response_code(404);exit('Factura no encontrada.');}
    if(!ef_puede_ver($usr,(string)$sucursal)){http_response_code(403);exit('Sin acceso a esta sucursal.');}
    $reporte=gyd_concil_reporte($db,$id);
  }else{
    $sql='SELECT f.id,f.folio_interno,f.numero_factura,f.sucursal,f.estado,p.nombre AS proveedor FROM erp_facturas f JOIN proveedores p ON p.id=f.proveedor_id';$params=[];
    if(!ef_puede_capturar($usr)){
      $alm=(int)($usr['almacen_id']??0);$suc=$alm===1?'HIDALGO':(in_array($alm,[2,3],true)?'TUXTLA':'__N/A__');
      $sql.=' WHERE f.sucursal=?';$params[]=$suc;
    }
    $sql.=' ORDER BY f.id DESC LIMIT 150';$lista=gyd_concil_rows($db,$sql,$params);
  }
}catch(Throwable $e){error_log('ERP conciliación consulta: '.$e->getMessage());$error='No se pudo completar la conciliación. Verifica las tablas de OC, recepción, factura, verificación y aplicaciones. Consulta el log PHP para el detalle.';}
if($exportar){
  if(!$reporte || $error!==''){http_response_code(422);exit('No se pudo generar el reporte.');}
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="conciliacion_factura_'.$id.'.csv"');
  header('X-Content-Type-Options: nosniff');
  $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");
  fputcsv($out,['Código','Descripción','Solicitado OC','Recibido Bodega','Facturado OC','Aceptado OC','Aplicado','Por recibir','Por facturar','Pendiente aplicar','Por verificar','Factura actual','Aceptado factura','Aplicado factura','Estado','Observaciones']);
  foreach($reporte['partidas'] as $r)fputcsv($out,[gyd_concil_csv_seguro($r['codigo']),gyd_concil_csv_seguro($r['descripcion']),$r['solicitadas'],$r['recibidas'],$r['facturadas'],$r['aceptadas'],$r['aplicadas'],$r['por_recibir'],$r['por_facturar'],$r['pendiente_aplicar'],$r['por_verificar'],$r['facturada_actual'],$r['aceptada_actual'],$r['aplicada_actual'],$r['estado'],implode(' / ',$r['observaciones'])]);
  fclose($out);exit;
}
ef_header('ERP G&D · Conciliación automática','Orden de compra → recepción de Bodega → factura → verificación → aplicación al inventario');
?>
<style>
.gc-totals{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.gc-kpi{padding:12px;background:#eff5fa;border:1px solid #dae5ef;border-radius:9px}.gc-kpi small{display:block}.gc-kpi strong{font-size:23px}.gc-info{font-size:13px;color:#486680}.gc-pill{font-size:12px;display:inline-block;padding:4px 9px;border-radius:6px;font-weight:750;white-space:nowrap}.gc-good{background:#ddf5e7;color:#175b3b}.gc-warn{background:#fff0c7;color:#7b520a}.gc-bad{background:#fee3e5;color:#8a2632}.gc-tools{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px}.gc-table th,.gc-table td{white-space:nowrap}.gc-table td:first-child{white-space:normal;min-width:170px}.gc-pages{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:10px 0}@media(max-width:800px){.gc-totals{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
<?php if($error): ?><div class="alert error" role="alert"><?=ef_e($error)?></div><?php endif; ?>
<?php if(!$id): ?>
<section class="card"><h2>Selecciona una factura para conciliar</h2><p class="muted">Este reporte no aplica movimientos ni cambia existencias.</p><div class="tablewrap"><table><thead><tr><th>Factura ERP</th><th>Folio proveedor</th><th>Proveedor</th><th>Sucursal</th><th>Estado</th><th>Acción</th></tr></thead><tbody>
<?php foreach($lista as $r): ?><tr><td><?=ef_e($r['folio_interno'])?></td><td><?=ef_e($r['numero_factura'])?></td><td><?=ef_e($r['proveedor'])?></td><td><?=ef_e($r['sucursal'])?></td><td><?=ef_e($r['estado'])?></td><td><a class="btn alt" href="?id=<?=(int)$r['id']?>">Conciliar</a></td></tr><?php endforeach; ?>
<?php if(!$lista):?><tr><td colspan="6">Sin facturas disponibles.</td></tr><?php endif;?></tbody></table></div></section>
<?php elseif($reporte): $f=$reporte['factura'];$t=$reporte['totales'];$isOc=$reporte['tiene_oc']; $cuenta=count($reporte['partidas']);$paginas=max(1,(int)ceil($cuenta/100));$pagina=min($pagina,$paginas);$partidas=array_slice($reporte['partidas'],($pagina-1)*100,100);?>
<section class="card">
<div class="gc-tools"><div><h2 style="margin:0 0 5px">Conciliación <?=ef_e($f['folio_interno'])?> · <?=ef_e($f['numero_factura'])?></h2><div class="gc-info">Proveedor: <?=ef_e($f['proveedor'])?> · Sucursal: <?=ef_e($f['sucursal'])?> · <?= $isOc?'Orden '.ef_e($f['oc_folio']??''). ' (todas sus facturas)':'Factura sin OC vinculada' ?></div></div><span class="gc-pill <?= $reporte['estado']==='REVISAR'?'gc-bad':($reporte['estado']==='PENDIENTES'?'gc-warn':'gc-good') ?>"><?=ef_e($reporte['estado'])?></span></div>
<p class="gc-info">Resumen de <?= $isOc?'la OC completa':'esta factura'?>, agrupado por identificador único de producto. La cantidad aplicada procede de los registros de aplicación al inventario.</p>
<div class="gc-totals">
<?php foreach(([ 'Solicitadas OC'=>'solicitadas','Recibidas Bodega'=>'recibidas','Facturadas'=>'facturadas','Aceptadas'=>'aceptadas','Aplicadas a inventario'=>'aplicadas','Por recibir'=>'por_recibir','Pendientes de aplicar'=>'pendiente_aplicar','Pendientes de verificar'=>'por_verificar']) as $label=>$campo): if((!$isOc && in_array($campo,['solicitadas','recibidas','por_recibir'],true)) || ($isOc && !$reporte['tiene_recepciones'] && $campo==='por_recibir'))continue; ?>
<div class="gc-kpi"><small><?=ef_e($label)?></small><strong><?=number_format($t[$campo])?></strong></div>
<?php endforeach; ?></div>
<?php if($isOc && !$reporte['tiene_recepciones']):?><p class="alert">Esta orden no tiene recepciones registradas en Bodega. Se muestra 0 en recibidas documentadas; <b>no significa que no haya llegado mercancía</b>, ni se calcula saldo físico por recibir.</p><?php endif; ?>
<?php if($reporte['alertas']>0):?><p class="alert error"><b><?=number_format($reporte['alertas'])?> producto(s) con diferencias:</b> revisa las filas marcadas REVISAR antes de efectuar nuevos movimientos de inventario.</p><?php elseif($reporte['pendientes']>0):?><p class="alert"><b>Registros conciliados sin exceso detectado.</b> Aún existen piezas por recibir, facturar, verificar o aplicar. No están todas cerradas.</p><?php else:?><p class="alert success"><b>Sin diferencias detectadas en los registros de este flujo.</b> Esto no certifica ausencia de movimientos provenientes de WinCaja u otros módulos.</p><?php endif; ?>
<p class="gc-info">Factura actual: <?=number_format($t['facturada_actual'])?> facturadas · <?=number_format($t['aceptada_actual'])?> aceptadas · <?=number_format($t['aplicada_actual'])?> aplicadas · <?=number_format($t['pendiente_aplicar_actual'])?> pendientes de aplicar.</p>
<div class="gc-tools"><a class="btn alt" href="erp_facturas_aplicar.php?id=<?=$id?>">← Volver a Aplicación</a><a class="btn alt" href="?id=<?=$id?>&amp;exportar=csv">Exportar CSV completo</a></div>
</section>
<section class="card"><div class="gc-tools"><h2 style="margin:0">Detalle por código (<?=number_format($cuenta)?>)</h2><span class="gc-info">Página <?=$pagina?> de <?=$paginas?> · 100 filas por página</span></div>
<div class="tablewrap"><table class="gc-table"><thead><tr><th>Producto / Código</th><?php if($isOc):?><th>OC</th><th>Recibidas</th><?php endif; ?><th>Facturadas</th><th>Aceptadas</th><th>Aplicadas</th><?php if($isOc):?><th>Por recibir</th><?php endif; ?><th>Por aplicar</th><th>Estado / Detalle</th></tr></thead><tbody>
<?php foreach($partidas as $r):?><tr>
<td><b><?=ef_e($r['descripcion'])?></b><br><small><?=ef_e($r['codigo'])?></small></td>
<?php if($isOc):?><td class="num"><?=number_format($r['solicitadas'])?></td><td class="num"><?=number_format($r['recibidas'])?></td><?php endif; ?>
<td class="num"><?=number_format($r['facturadas'])?></td><td class="num"><?=number_format($r['aceptadas'])?></td><td class="num"><?=number_format($r['aplicadas'])?></td>
<?php if($isOc):?><td class="num"><?=number_format($r['por_recibir'])?></td><?php endif; ?><td class="num"><?=number_format($r['pendiente_aplicar'])?></td>
<td><span class="gc-pill <?= $r['estado']==='REVISAR'?'gc-bad':($r['estado']==='PENDIENTE'?'gc-warn':'gc-good') ?>"><?=ef_e($r['estado'])?></span><?php if($r['observaciones']):?><br><small><?=ef_e(implode('; ',$r['observaciones']))?></small><?php endif;?></td>
</tr><?php endforeach;?></tbody></table></div>
<div class="gc-pages"><?php if($pagina>1):?><a class="btn alt" href="?id=<?=$id?>&amp;pagina=<?=$pagina-1?>">← Anterior</a><?php endif;?><span class="gc-info">Página <?=$pagina?> / <?=$paginas?></span><?php if($pagina<$paginas):?><a class="btn alt" href="?id=<?=$id?>&amp;pagina=<?=$pagina+1?>">Siguiente →</a><?php endif;?></div>
</section>
<section class="card"><h2>Alcance de esta conciliación</h2><p class="gc-info">Compara cantidades registradas en la orden, recepciones NO anuladas, facturas ligadas, verificaciones y auditoría de aplicaciones. <b>Para Tapachula y Toscana:</b> las aplicaciones registradas tienen destino FARMACIA/POS. <b>Para Hidalgo y Tuxtla:</b> el destino es ALMACEN; no implica entrada directa al POS. Las existencias actuales pueden variar por ventas, salidas y transferencias. No es posible identificar automáticamente duplicados realizados fuera del flujo de aplicaciones sin trazabilidad adicional.</p></section>
<?php endif;ef_footer();
