<?php
declare(strict_types=1);
/** Entregas parciales con incidencias; buenas en fuente canónica, otras solo evidencia. */
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_oc_recepciones.php';
require_once __DIR__.'/../app/helpers/erp_recepcion_calidad_reglas.php';
require_once __DIR__.'/../app/helpers/erp_oc_cierre_controlado.php'; // GYD_GCC_REQUIRE_V1
requireLogin();
$usr=currentUser()?:[];
$acceso=erpr_acceso($usr);
$id=(int)($_POST['orden_id']??$_GET['id']??0);
if($id<1){http_response_code(400);exit('Indica una orden de compra válida.');}
if(empty($_SESSION['oc_calidad_csrf']))$_SESSION['oc_calidad_csrf']=bin2hex(random_bytes(32));
if(empty($_SESSION['oc_calidad_token']))$_SESSION['oc_calidad_token']=bin2hex(random_bytes(16));
$csrf=(string)$_SESSION['oc_calidad_csrf'];$token=(string)$_SESSION['oc_calidad_token'];
$orden=null;$detalles=[];$error='';$puede=false;
function rq_entero(mixed $v,string $etiqueta):int{
    $t=trim((string)$v);
    if($t===''||!ctype_digit($t)||strlen($t)>9)throw new RuntimeException('Cantidad inválida en '.$etiqueta.'.');
    return (int)$t;
}
function rq_tasa(PDO $db):bool{
    $st=$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='erp_oc_avisos_compras'");
    return (int)$st->fetchColumn()>0;
}
try{
 $db=(new Database())->connect();
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($csrf,(string)($_POST['csrf']??'')))throw new RuntimeException('Sesión de formulario vencida. Recarga.');
  $enviado=(string)($_POST['token']??'');
  if(!preg_match('/^[a-f0-9]{32}$/D',$enviado)||!hash_equals($token,$enviado))throw new RuntimeException('Este formulario ya fue enviado o caducó. Actualiza la pantalla.');
  $fecha=trim((string)($_POST['fecha']??''));$ref=trim((string)($_POST['referencia']??''));$obs=trim((string)($_POST['observaciones']??''));
  if(!erpr_fecha_valida($fecha)||$fecha>date('Y-m-d'))throw new RuntimeException('Fecha de entrega inválida.');
  if(mb_strlen($ref)>100||mb_strlen($obs)>500)throw new RuntimeException('Referencia o observaciones demasiado largas.');
  $campos=['buena','danada','faltante','sobrante','caducada','equivocada','lote','caducidad','nota'];
  foreach($campos as $c)if(!is_array($_POST[$c]??[]))throw new RuntimeException('Formato de captura inválido.');
  $extrasCantidad=rq_entero($_POST['extra_qty']??'0','sobrante no incluido en OC');
  $extrasCodigo=trim((string)($_POST['extra_codigo']??''));$extrasDesc=trim((string)($_POST['extra_descripcion']??''));$extrasNota=trim((string)($_POST['extra_nota']??''));
  if($extrasCantidad>0 && (mb_strlen($extrasCodigo)>150||mb_strlen($extrasDesc)<4||mb_strlen($extrasDesc)>255||mb_strlen($extrasNota)<5||mb_strlen($extrasNota)>500))throw new RuntimeException('Describe el producto sobrante no incluido en la OC (descripción y motivo).');
  $db->beginTransaction();
  // Cada recepción de una OC se serializa junto con la verificación de facturas.
  $st=$db->prepare("SELECT o.id,o.folio,o.destino,o.estado,a.estado autorizacion FROM erp_ordenes_compra o LEFT JOIN erp_oc_autorizaciones a ON a.orden_id=o.id WHERE o.id=? FOR UPDATE");
  $st->execute([$id]);$oc=$st->fetch(PDO::FETCH_ASSOC);
  if(!$oc||!gd_oc_esta_lista((string)$oc['estado'],$oc['autorizacion']??null))throw new RuntimeException('Solo pueden recibirse OC emitidas y autorizadas cuando corresponda.');
  gcc_exigir_abierta($db,$id); // GYD_GCC_GUARD_RECEPCION_V1
  if(!erpr_puede_registrar_destino($acceso,(string)$oc['destino']))throw new RuntimeException('No puedes registrar entregas para ese destino.');
  $st=$db->prepare('SELECT id FROM erp_oc_recepciones WHERE orden_id=? AND solicitud_token=?');$st->execute([$id,$enviado]);
  if($anterior=(int)$st->fetchColumn()){$db->commit();header('Location: erp_recepciones_calidad_historial.php?rec='.$anterior,true,303);exit;}
  $det=erpr_detalles($db,$id);$buenas=[];$inc=[];
  foreach($det as $d){
   $did=(int)$d['id'];$restante=(int)$d['solicitadas']-(int)$d['recibidas'];
   if($restante<0)throw new RuntimeException('Recepciones anteriores superan cantidades autorizadas; requiere conciliación.');
   $q=[];
   foreach(['buena','danada','faltante','sobrante','caducada','equivocada'] as $col)$q[$col]=rq_entero($_POST[$col][$did]??'0',$col.' de '.$d['codigo_snapshot']);
   rq_validar_partida($restante,$q,(string)$d['codigo_snapshot']);
   $lote=trim((string)($_POST['lote'][$did]??''));$cad=trim((string)($_POST['caducidad'][$did]??''));$nota=trim((string)($_POST['nota'][$did]??''));
   if(mb_strlen($lote)>100||mb_strlen($nota)>500||($cad!==''&&!erpr_fecha_valida($cad)))throw new RuntimeException('Lote, fecha o comentario inválidos en '.$d['codigo_snapshot'].'.');
   if($q['buena']>0 && $cad!=='' && $cad<=date('Y-m-d'))throw new RuntimeException('Un lote vencido no puede registrarse como bueno: '.$d['codigo_snapshot'].'. Utiliza «Caducadas».');
   if(array_sum(array_intersect_key($q,array_flip(['danada','faltante','sobrante','caducada','equivocada'])))>0 && mb_strlen($nota)<5)throw new RuntimeException('Explica la incidencia de '.$d['codigo_snapshot'].' con al menos cinco caracteres.');
   if($q['buena']>0)$buenas[]=['detalle_id'=>$did,'producto_id'=>(int)$d['producto_id'],'qty'=>$q['buena'],'lote'=>$lote?:null,'cad'=>$cad?:null];
   foreach(['danada'=>'DANADA','faltante'=>'FALTANTE','sobrante'=>'SOBRANTE','caducada'=>'CADUCADA','equivocada'=>'EQUIVOCADA'] as $campo=>$tipo){
    if($q[$campo]>0)$inc[]=['detalle_id'=>$did,'producto_id'=>(int)$d['producto_id'],'tipo'=>$tipo,'qty'=>$q[$campo],'codigo'=>null,'desc'=>null,'nota'=>$nota];
   }
  }
  if($extrasCantidad>0)$inc[]=['detalle_id'=>null,'producto_id'=>null,'tipo'=>'SOBRANTE','qty'=>$extrasCantidad,'codigo'=>$extrasCodigo?:null,'desc'=>$extrasDesc,'nota'=>$extrasNota];
  if(!$buenas&&!$inc)throw new RuntimeException('Indica por lo menos una pieza buena o una incidencia.');
  $st=$db->prepare("INSERT INTO erp_oc_recepciones(orden_id,destino,fecha_recepcion,referencia_factura,observaciones,usuario_id,solicitud_token,estado) VALUES(?,?,?,?,?,?,?,'REGISTRADA')");
  $st->execute([$id,$oc['destino'],$fecha,$ref?:null,$obs?:null,$acceso['uid'],$enviado]);
  $recId=(int)$db->lastInsertId();$folio='REC-'.str_pad((string)$recId,7,'0',STR_PAD_LEFT);
  $db->prepare('UPDATE erp_oc_recepciones SET folio=? WHERE id=?')->execute([$folio,$recId]);
  $st=$db->prepare('INSERT INTO erp_oc_recepciones_detalle(recepcion_id,orden_detalle_id,producto_id,cantidad_piezas,lote,caducidad) VALUES(?,?,?,?,?,?)');
  foreach($buenas as $b)$st->execute([$recId,$b['detalle_id'],$b['producto_id'],$b['qty'],$b['lote'],$b['cad']]);
  $st=$db->prepare('INSERT INTO erp_oc_recepcion_incidencias(recepcion_id,orden_detalle_id,producto_id,tipo,cantidad_piezas,codigo_reportado,descripcion_reportada,observaciones) VALUES(?,?,?,?,?,?,?,?)');
  foreach($inc as $i)$st->execute([$recId,$i['detalle_id'],$i['producto_id'],$i['tipo'],$i['qty'],$i['codigo'],$i['desc'],$i['nota']]);
  if(rq_tasa($db)){
   $piezas=array_sum(array_column($buenas,'qty'));$ir=array_sum(array_column($inc,'qty'));
   $mensaje='Recepción '.$folio.': '.$piezas.' piezas buenas y '.$ir.' piezas reportadas en incidencias. Validar factura y pendientes de OC.';
   $db->prepare('INSERT INTO erp_oc_avisos_compras (recepcion_id,orden_id,usuario_emisor_id,mensaje) VALUES(?,?,?,?)')->execute([$recId,$id,$acceso['uid'],$mensaje]);
  }
  $db->commit();unset($_SESSION['oc_calidad_token']);
  header('Location: erp_recepciones_calidad_historial.php?rec='.$recId.'&ok=1',true,303);exit;
 }
 $st=$db->prepare('SELECT o.id,o.folio,o.estado,o.fecha,o.destino,p.nombre proveedor,a.estado autorizacion FROM erp_ordenes_compra o JOIN proveedores p ON p.id=o.proveedor_id LEFT JOIN erp_oc_autorizaciones a ON a.orden_id=o.id WHERE o.id=?');
 $st->execute([$id]);$orden=$st->fetch(PDO::FETCH_ASSOC)?:null;
 if(!$orden){http_response_code(404);exit('OC no encontrada.');}
 if(!erpr_puede_ver_destino($acceso,(string)$orden['destino'])){http_response_code(403);exit('Sin acceso al destino de esta OC.');}
 $detalles=erpr_detalles($db,$id);
 $puede=erpr_puede_registrar_destino($acceso,(string)$orden['destino'])&&gd_oc_esta_lista((string)$orden['estado'],$orden['autorizacion']??null);
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();error_log('OC calidad: '.$e->getMessage());$error=$e instanceof RuntimeException?$e->getMessage():'Error al registrar recepción. Revisa la migración y registros PHP.';}
// Ante datos inválidos, volver a mostrar la OC y permitir corregir sin guardar nada.
if($orden===null && isset($db)){
 try{
  $q=$db->prepare('SELECT o.id,o.folio,o.estado,o.fecha,o.destino,p.nombre proveedor,a.estado autorizacion FROM erp_ordenes_compra o JOIN proveedores p ON p.id=o.proveedor_id LEFT JOIN erp_oc_autorizaciones a ON a.orden_id=o.id WHERE o.id=?');
  $q->execute([$id]);$c=$q->fetch(PDO::FETCH_ASSOC);
  if($c && erpr_puede_ver_destino($acceso,(string)$c['destino'])){
   $orden=$c;$detalles=erpr_detalles($db,$id);
   $puede=erpr_puede_registrar_destino($acceso,(string)$orden['destino'])&&gd_oc_esta_lista((string)$orden['estado'],$orden['autorizacion']??null);
  }
 }catch(Throwable $ignorado){error_log('OC calidad al recargar: '.$ignorado->getMessage());}
}
$pedido=0;$aceptado=0;foreach($detalles as $d){$pedido+=(int)$d['solicitadas'];$aceptado+=(int)$d['recibidas'];}
$e=static fn(mixed $v):string=>erpr_html($v);
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>G&D · Registrar recepción</title>
<style>body{font:14px system-ui,sans-serif;background:#eff4fa;color:#173a5d;margin:0}*{box-sizing:border-box}.top{background:#123e69;color:#fff;padding:20px max(15px,calc((100% - 1280px)/2));display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}.top a{color:white}main{max-width:1280px;margin:20px auto;padding:0 14px}.card{background:white;border:1px solid #dce7f3;border-radius:12px;padding:18px;margin:13px 0}.alert{border:1px solid #d6bb88;background:#fff5df;padding:13px;border-radius:10px;margin:14px 0}.error{background:#ffe7e7;border-color:#e9acac}.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.metric{background:#eef5fc;padding:13px;border-radius:10px}.metric b{display:block;font-size:23px;color:#12549b}.scroll{overflow:auto}table{width:100%;border-collapse:collapse;min-width:1340px}td,th{text-align:left;padding:10px;border-bottom:1px solid #e1e8f0;font-size:12px}th{background:#184977;color:#fff;white-space:nowrap}td small{display:block;color:#68829c}.num{width:72px;max-width:85px}input,textarea{font:inherit;padding:8px;border:1px solid #b8cadc;border-radius:7px}input[type=date]{max-width:170px}textarea{width:100%}.wide{min-width:140px;width:100%}.actions{display:flex;justify-content:flex-end;gap:9px;align-items:center;flex-wrap:wrap}.btn,button{background:#145a9e;color:white;padding:11px 14px;text-decoration:none;border:0;border-radius:8px;cursor:pointer;font-weight:700}.secondary{background:#e7f0fa;color:#245276}h1{margin:0 0 4px;font-size:22px}h2{font-size:18px;margin-top:0}label{display:grid;gap:5px;font-size:13px}label b{font-size:12px}@media(max-width:720px){.grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:470px){.grid{grid-template-columns:1fr}}</style></head><body>
<header class="top"><div><h1>G&D · Registrar recepción</h1><small>Buenas, faltantes, dañadas, caducadas y sobrantes · Seguimiento por OC</small></div><nav><a href="erp_recepciones_pendientes.php">Pendientes</a> · <a href="erp_recepciones_calidad_historial.php?oc=<?=$id?>">Historial detallado</a></nav></header><main>
<div class="alert"><b>Regla de inventario:</b> solo las piezas <b>BUENAS</b> pasan a la recepción documental. El registro por sí solo NO suma stock: Bodega prepara su Entrada y Farmacia/Compras verifica la factura y aplica al POS. Las incidencias no se aplican al inventario y permanecen auditables.</div>
<?php if($error):?><div class="alert error" role="alert"><?=$e($error)?></div><?php endif;?>
<?php if($orden):?><section class="card"><h2><?=$e($orden['folio'])?></h2><p><b>Proveedor:</b> <?=$e($orden['proveedor'])?> · <b>Destino:</b> <?=$e(erpr_destinos()[$orden['destino']]??$orden['destino'])?> · <b>Estado:</b> <?=$e($orden['estado'])?></p><div class="grid"><div class="metric"><b><?=number_format($pedido)?></b>Solicitadas</div><div class="metric"><b><?=number_format($aceptado)?></b>Buenas registradas</div><div class="metric"><b><?=number_format(max(0,$pedido-$aceptado))?></b>Por recibir buenas</div><div class="metric"><b><?=count($detalles)?></b>Partidas</div></div></section>
<?php if(!$puede):?><div class="alert">Tu usuario solo puede consultar. Esta OC debe estar emitida y asignada a tu destino.</div><?php endif;?>
<section class="card"><h2>Nueva entrega / complemento</h2><p>Captura exclusivamente lo que llegó en esta entrega. «Faltante» es la cantidad reportada hoy; «Por recibir» se calcula automáticamente por el total de piezas buenas pendientes.</p>
<form method="post" id="rqForm"><input type="hidden" name="orden_id" value="<?=$id?>"><input type="hidden" name="csrf" value="<?=$e($csrf)?>"><input type="hidden" name="token" value="<?=$e($token)?>">
<div class="grid"><label>Fecha de entrega<input type="date" name="fecha" value="<?=date('Y-m-d')?>" max="<?=date('Y-m-d')?>" required <?=$puede?'':'disabled'?>></label><label>Factura o remisión<input name="referencia" maxlength="100" placeholder="Número o referencia" <?=$puede?'':'disabled'?>></label><label style="grid-column:span 2">Observaciones generales<textarea name="observaciones" maxlength="500" rows="2" placeholder="Proveedor, cajas, transportista, datos útiles" <?=$puede?'':'disabled'?>></textarea></label></div>
<div class="scroll" style="margin-top:15px"><table><thead><tr><th>Código / artículo</th><th>OC</th><th>Buenas previas</th><th>Por recibir</th><th>Buenas hoy</th><th>Dañadas</th><th>Faltantes reportadas</th><th>Caducadas</th><th>Equivocadas</th><th>Sobrantes</th><th>Lote buenas</th><th>Caducidad buenas</th><th>Motivo incidencia</th></tr></thead><tbody>
<?php foreach($detalles as $d):$did=(int)$d['id'];$rest=max(0,(int)$d['solicitadas']-(int)$d['recibidas']);?><tr><td><b><?=$e($d['descripcion_snapshot'])?></b><small><?=$e($d['codigo_snapshot'])?></small></td><td><?=number_format((int)$d['solicitadas'])?></td><td><?=number_format((int)$d['recibidas'])?></td><td><b><?=number_format($rest)?></b></td>
<?php foreach(['buena','danada','faltante','caducada','equivocada','sobrante'] as $campo):?><td><input class="num" type="number" name="<?=$campo?>[<?=$did?>]" value="0" min="0" max="<?=$campo==='sobrante'?'999999999':$rest?>" step="1" <?=$puede?'':'disabled'?>></td><?php endforeach;?><td><input class="wide" name="lote[<?=$did?>]" maxlength="100" placeholder="Lote (si aplica)" <?=$puede?'':'disabled'?>></td><td><input name="caducidad[<?=$did?>]" type="date" <?=$puede?'':'disabled'?>></td><td><input class="wide" name="nota[<?=$did?>]" maxlength="500" placeholder="Ej. caja rota, 25 no enviadas" <?=$puede?'':'disabled'?>></td></tr><?php endforeach;?></tbody></table></div>
<h2 style="margin-top:22px">Artículo sobrante que NO aparece en la orden (opcional)</h2><div class="grid"><label>Cantidad<input type="number" name="extra_qty" value="0" min="0" max="999999999" <?=$puede?'':'disabled'?>></label><label>Código visto en etiqueta<input name="extra_codigo" maxlength="150" <?=$puede?'':'disabled'?>></label><label>Descripción<input name="extra_descripcion" maxlength="255" <?=$puede?'':'disabled'?>></label><label>Motivo / observaciones<input name="extra_nota" maxlength="500" placeholder="No pertenece a OC" <?=$puede?'':'disabled'?>></label></div>
<div class="actions" style="margin-top:20px"><a class="btn secondary" href="erp_recepciones_pendientes.php">Volver</a><?php if($puede):?><button type="submit">Registrar entrega y guardar pendientes</button><?php endif;?></div></form></section>
<?php endif;?></main><script>const f=document.getElementById('rqForm');if(f)f.addEventListener('submit',e=>{let p=0;for(const el of f.querySelectorAll('input[type=number]:not(:disabled)'))p+=Number(el.value)||0;if(!p){e.preventDefault();alert('Captura alguna cantidad buena o incidencia.');return;}if(!confirm('¿Confirmas la inspección? Solo las piezas buenas quedarán disponibles para el siguiente paso, NO se sumarán automáticamente al inventario.'))e.preventDefault();});</script>
<script src="assets/js/gyd_excel_reportes.js" defer></script>

<?php if (!defined('GYD_HIDE_GLOBAL_NAV')): ?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=1">
<script>window.GYD_NAV_HOME='index.php';window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=1"></script>
<?php endif; ?>
</body></html>
