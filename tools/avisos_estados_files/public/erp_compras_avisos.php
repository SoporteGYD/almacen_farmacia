<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_oc_recepciones.php';
require_once __DIR__.'/../app/helpers/erp_oc_aviso_estado.php';
requireLogin();
$u=currentUser()?:[];$rol=strtoupper(trim((string)($u['rol']??'')));
if(!in_array($rol,['ADMINISTRADOR','ADMIN','DIRECCION','COMPRAS'],true)){http_response_code(403);exit('Sin acceso a avisos de Compras.');}
if(empty($_SESSION['ocav_csrf']))$_SESSION['ocav_csrf']=bin2hex(random_bytes(32));
$csrf=(string)$_SESSION['ocav_csrf'];$err='';$rows=[];$estados=[];
try{
 $db=(new Database())->connect();
 if($_SERVER['REQUEST_METHOD']==='POST'){
   if(!hash_equals($csrf,(string)($_POST['csrf']??'')))throw new RuntimeException('Formulario vencido. Actualiza la página.');
   $id=filter_var($_POST['aviso_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
   if(!$id)throw new RuntimeException('Aviso inválido.');
   $st=$db->prepare("UPDATE erp_oc_avisos_compras SET estado='REVISADO',revisado_por=?,revisado_en=NOW() WHERE id=? AND estado='PENDIENTE'");
   $st->execute([(int)$u['id'],$id]);
   header('Location: erp_compras_avisos.php?ok=1',true,303);exit;
 }
 $rows=$db->query("SELECT a.id,a.orden_id,a.recepcion_id,a.estado,a.creado_en,
  r.folio AS recepcion_folio,r.destino,r.fecha_recepcion,o.folio AS oc_folio,
  p.nombre AS proveedor,COALESCE(SUM(rd.cantidad_piezas),0) AS piezas
  FROM erp_oc_avisos_compras a JOIN erp_oc_recepciones r ON r.id=a.recepcion_id
  JOIN erp_ordenes_compra o ON o.id=a.orden_id JOIN proveedores p ON p.id=o.proveedor_id
  LEFT JOIN erp_oc_recepciones_detalle rd ON rd.recepcion_id=r.id
  GROUP BY a.id,a.orden_id,a.recepcion_id,a.estado,a.creado_en,r.folio,r.destino,r.fecha_recepcion,o.folio,p.nombre
  ORDER BY a.id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
 $estados=oc_status_batch($db,$rows);
}catch(Throwable $e){error_log('ERP Compras avisos: '.$e->getMessage());$err=$e instanceof RuntimeException?$e->getMessage():'No fue posible consultar los avisos y sus estados.';}
function aviso_tag(string $txt,string $clase):string {return '<span class="tag '.htmlspecialchars($clase,ENT_QUOTES,'UTF-8').'">'.erpr_html($txt).'</span>';}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>G&D | Avisos de Compras</title>
<style>
:root{font-family:system-ui,-apple-system,Segoe UI,sans-serif;color:#153d63}*{box-sizing:border-box}body{margin:0;background:#eef4fa;font-size:14px}header{background:#153f6c;color:white;padding:22px max(18px,calc((100% - 1170px)/2))}header h1{font-size:23px;margin:4px 0 14px}header a{color:white}main{max-width:1170px;margin:22px auto;padding:0 16px}.card{background:white;padding:16px;border-radius:12px;margin:12px 0;box-shadow:0 4px 16px #18406313}.hint{color:#4c6986;line-height:1.45}.hint strong{color:#153f6c}.scroll{overflow-x:auto}table{width:100%;border-collapse:collapse;min-width:960px}th,td{text-align:left;padding:10px 8px;border-bottom:1px solid #dfeaf5;font-size:13px;vertical-align:middle}th{background:#174c80;color:white}tr.finished{background:#f3fbf6}.tag{display:inline-block;font-size:11px;padding:5px 8px;font-weight:700;border-radius:20px;white-space:nowrap}.tag.verde{background:#d9f4e3;color:#166534}.tag.amarillo{background:#fff0c6;color:#87590a}.tag.rojo{background:#fee2e2;color:#9f1239}.btn,button{display:inline-block;background:#175d9f;color:white;padding:8px 10px;margin:2px 1px;border:0;border-radius:7px;text-decoration:none;font:inherit;cursor:pointer;white-space:nowrap}.btn.alt{background:#e5eef7;color:#164b7d}.muted{color:#70869d;font-size:12px}.success{padding:11px;background:#e3f5e9;border-radius:8px}.error{padding:11px;background:#fee2e2;border-radius:8px}.num{text-align:right;white-space:nowrap}details summary{cursor:pointer;color:#135994;font-weight:600;font-size:12px}details table{min-width:610px;margin-top:8px}details th,details td{font-size:12px;padding:6px}details th{background:#dae8f7;color:#19466c}.legend{display:flex;gap:12px;flex-wrap:wrap;margin-top:8px}.footer{color:#5a718c;font-size:12px}
</style></head><body><header><h1>🔔 Recepciones notificadas a Compras</h1><a href="erp_oc_win.php">← Órdenes de compra</a></header><main>
<section class="card"><p class="hint">El aviso se actualiza con los registros reales de <strong>facturas y aplicaciones al inventario</strong>. No es necesario marcarlo manualmente al terminar. El verde confirma aplicación, el amarillo indica seguimiento y el rojo señala un pendiente sin registrar. <strong>Registrar una factura no aumenta el stock.</strong></p>
<p class="footer">Las cantidades se concilian por código de producto y antigüedad de recepción dentro de cada OC. Si varias facturas o recibos comparten códigos, revisa los documentos para confirmar su correspondencia exacta.</p>
<?php if($err):?><p class="error" role="alert"><?=erpr_html($err)?></p><?php endif;?><?php if(isset($_GET['ok'])):?><p class="success">Aviso marcado como leído. No se modificaron existencias.</p><?php endif;?></section>
<section class="card scroll"><table><thead><tr><th>Lectura</th><th>Orden / Recepción</th><th>Proveedor y destino</th><th>Factura</th><th>Inventario</th><th>Piezas recibidas</th><th>Acciones</th></tr></thead><tbody>
<?php foreach($rows as $r):$s=$estados[(int)$r['id']]??['recibidas'=>0,'facturadas'=>0,'aplicadas'=>0,'lectura'=>false,'factura_estado'=>'PENDIENTE','inventario_estado'=>'PENDIENTE','lineas'=>[]];
$fac=(string)$s['factura_estado'];$inv=(string)$s['inventario_estado'];
$clF=$fac==='REGISTRADA'?'verde':($fac==='PARCIAL'?'amarillo':'rojo');
$clI=$inv==='APLICADO'?'verde':($inv==='PARCIAL'?'amarillo':'rojo');
$sinFacturar=max(0,(int)$s['recibidas']-(int)$s['facturadas']);
$sinAplicar=max(0,(int)$s['recibidas']-(int)$s['aplicadas']);
?><tr class="<?=($inv==='APLICADO'&&$fac==='REGISTRADA')?'finished':''?>">
<td><?=aviso_tag($s['lectura']?'LEÍDO':'SIN LEER',$s['lectura']?'verde':'amarillo')?></td>
<td><b><?=erpr_html($r['oc_folio'])?></b><div class="muted"><?=erpr_html($r['recepcion_folio'])?> · <?=erpr_html($r['fecha_recepcion'])?></div></td>
<td><?=erpr_html($r['proveedor'])?><div class="muted"><?=erpr_html($r['destino'])?></div></td>
<td><?=aviso_tag($fac,$clF)?><div class="muted"><?=number_format((int)$s['facturadas'])?> / <?=number_format((int)$s['recibidas'])?> documentadas</div></td>
<td><?=aviso_tag($inv,$clI)?><div class="muted"><?=number_format((int)$s['aplicadas'])?> / <?=number_format((int)$s['recibidas'])?> aplicadas</div></td>
<td class="num"><?=number_format((int)$r['piezas'])?></td>
<td>
<?php if($sinFacturar>0):?><a class="btn" href="erp_facturas.php?orden_id=<?=(int)$r['orden_id']?>"><?=((int)$s['facturadas']>0)?'Registrar complemento':'Registrar factura'?></a>
<?php else:?><a class="btn" href="erp_oc_facturas_seguimiento.php?oc=<?=(int)$r['orden_id']?>">Continuar seguimiento</a><?php endif;?>
<a class="btn alt" href="erp_oc_inventarios.php?oc=<?=(int)$r['orden_id']?>">Comparar existencias</a>
<details><summary>Ver partidas</summary><table><thead><tr><th>Código / Artículo</th><th>Recibidas</th><th>Facturadas</th><th>Aplicadas</th></tr></thead><tbody>
<?php foreach($s['lineas'] as $l):?><tr><td><?=erpr_html($l['codigo'])?><div class="muted"><?=erpr_html($l['descripcion'])?></div></td><td><?=(int)$l['recibidas']?></td><td class="<?=($l['facturadas']<$l['recibidas'])?'tag amarillo':''?>"><?=(int)$l['facturadas']?></td><td class="<?=($l['aplicadas']<$l['recibidas'])?'tag amarillo':'tag verde'?>"><?=(int)$l['aplicadas']?></td></tr><?php endforeach;?></tbody></table></details>
<?php if(!$s['lectura']):?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=erpr_html($csrf)?>"><input type="hidden" name="aviso_id" value="<?=(int)$r['id']?>"><button type="submit" class="btn alt">Marcar leído</button></form><?php endif;?>
</td></tr><?php endforeach;?>
<?php if(!$rows):?><tr><td colspan="7">No hay avisos de recepción.</td></tr><?php endif;?></tbody></table></section>
<p class="footer">«APLICADO» refleja partidas registradas en el inventario mediante una aplicación de factura o una entrada vinculada a una recepción de Bodega. No representa una comparación del stock actual, que puede cambiar por ventas y movimientos posteriores.</p>
</main></body></html>
