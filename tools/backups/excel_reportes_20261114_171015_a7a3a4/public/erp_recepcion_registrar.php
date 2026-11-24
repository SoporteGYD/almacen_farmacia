<?php
declare(strict_types=1);
// ERP_GYD_RECEPCION_UNIFICADA_V1: URL tradicional, formulario único de recepción con incidencias.
require __DIR__ . '/erp_recepcion_calidad.php';
exit;

require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_oc_recepciones.php';
requireLogin();
$user=currentUser() ?: [];
$acceso=erpr_acceso($user);
$ordenId=(int)($_POST['orden_id']??$_GET['id']??0);
if($ordenId<1){http_response_code(400);exit('Selecciona una orden de compra válida.');}
if(empty($_SESSION['erp_recepcion_csrf']))$_SESSION['erp_recepcion_csrf']=bin2hex(random_bytes(32));
if(empty($_SESSION['erp_recepcion_token']))$_SESSION['erp_recepcion_token']=bin2hex(random_bytes(16));
$csrf=(string)$_SESSION['erp_recepcion_csrf'];
$token=(string)$_SESSION['erp_recepcion_token'];
$error='';$orden=null;$detalles=[];$puede=false;
try{
    $pdo=(new Database())->connect();
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!hash_equals($csrf,(string)($_POST['csrf']??'')))throw new RuntimeException('El formulario caducó. Actualiza la pantalla.');
        $enviadoToken=(string)($_POST['token']??'');
        if(!preg_match('/^[0-9a-f]{32}$/D',$enviadoToken)||!hash_equals($token,$enviadoToken))throw new RuntimeException('Formulario inválido o ya utilizado. Actualiza la página.');
        $fecha=trim((string)($_POST['fecha_recepcion']??''));
        $ref=trim((string)($_POST['referencia_factura']??''));
        $obs=trim((string)($_POST['observaciones']??''));
        if(!erpr_fecha_valida($fecha)||$fecha>date('Y-m-d'))throw new RuntimeException('La fecha de recepción debe ser válida y no puede estar en el futuro.');
        if(mb_strlen($ref)>100||mb_strlen($obs)>500)throw new RuntimeException('Factura u observaciones demasiado largas.');
        // OCAV_REC_JSON_V1: recibir listas grandes en un único campo.
        if(isset($_POST['recepcion_lineas_json'])){
            $datos=json_decode((string)$_POST['recepcion_lineas_json'],true,512,JSON_THROW_ON_ERROR);
            if(!is_array($datos)||!array_is_list($datos))throw new RuntimeException('Captura de recepción incompleta.');
            $_POST['cantidad']=[];$_POST['lote']=[];$_POST['caducidad']=[];
            foreach($datos as $item){
                if(!is_array($item))throw new RuntimeException('Producto de recepción inválido.');
                $id=filter_var($item['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                if(!$id || isset($_POST['cantidad'][$id]))throw new RuntimeException('Renglón duplicado o inválido.');
                $_POST['cantidad'][$id]=(string)($item['cantidad']??'');
                $_POST['lote'][$id]=(string)($item['lote']??'');
                $_POST['caducidad'][$id]=(string)($item['caducidad']??'');
            }
        }
        $entradas=$_POST['cantidad']??[]; $lotes=$_POST['lote']??[]; $caducidades=$_POST['caducidad']??[];
        if(!is_array($entradas)||!is_array($lotes)||!is_array($caducidades))throw new RuntimeException('Cantidades inválidas.');
        $pdo->beginTransaction();
        // El bloqueo de la orden serializa las recepciones simultáneas del mismo pedido.
        $st=$pdo->prepare("SELECT o.id,o.folio,o.estado,o.destino,a.estado AS autorizacion
                           FROM erp_ordenes_compra o
                           LEFT JOIN erp_oc_autorizaciones a ON a.orden_id=o.id
                           WHERE o.id=? FOR UPDATE");
        $st->execute([$ordenId]);$ordenBloqueada=$st->fetch(PDO::FETCH_ASSOC);
        if(!$ordenBloqueada||!gd_oc_esta_lista((string)$ordenBloqueada['estado'],$ordenBloqueada['autorizacion']??null))throw new RuntimeException('Solo puedes recibir órdenes de compra emitidas.');
        if(!erpr_puede_registrar_destino($acceso,(string)$ordenBloqueada['destino']))throw new RuntimeException('No puedes registrar recepciones para este destino.');
        // Repetición del mismo POST: mostrar comprobante sin incrementar unidades dos veces.
        $st=$pdo->prepare('SELECT id FROM erp_oc_recepciones WHERE solicitud_token=? AND orden_id=?');
        $st->execute([$enviadoToken,$ordenId]);$existente=(int)$st->fetchColumn();
        if($existente>0){$pdo->commit();header('Location: erp_recepciones_historial.php?ver='.$existente,true,303);exit;}
        $lineas=erpr_detalles($pdo,$ordenId);
        if(!$lineas)throw new RuntimeException('Esta orden no tiene productos.');
        $registrar=[];
        foreach($lineas as $d){
            $detalleId=(int)$d['id'];
            $texto=trim((string)($entradas[$detalleId]??'0'));
            if($texto===''||!ctype_digit($texto)||strlen($texto)>10)throw new RuntimeException('La cantidad de un producto no es válida.');
            $cantidad=(int)$texto;
            $restantes=(int)$d['solicitadas']-(int)$d['recibidas'];
            if($restantes<0)throw new RuntimeException('Se detectó una inconsistencia en recepciones anteriores. Contacta a Sistemas.');
            if($cantidad>$restantes)throw new RuntimeException('La cantidad de '.(string)$d['descripcion_snapshot'].' supera lo pendiente ('.$restantes.' piezas).');
            if($cantidad===0)continue;
            $lote=trim((string)($lotes[$detalleId]??''));$cad=trim((string)($caducidades[$detalleId]??''));
            if(mb_strlen($lote)>100)throw new RuntimeException('El lote supera 100 caracteres.');
            if($cad!==''&&!erpr_fecha_valida($cad))throw new RuntimeException('Hay una fecha de caducidad inválida.');
            $registrar[]=['detalle_id'=>$detalleId,'producto_id'=>(int)$d['producto_id'],'cantidad'=>$cantidad,'lote'=>$lote?:null,'caducidad'=>$cad?:null];
        }
        if(!$registrar)throw new RuntimeException('Indica una cantidad mayor que cero en al menos un producto.');
        $st=$pdo->prepare("INSERT INTO erp_oc_recepciones
          (orden_id,destino,fecha_recepcion,referencia_factura,observaciones,usuario_id,solicitud_token,estado)
          VALUES (?,?,?,?,?,?,?,'REGISTRADA')");
        $st->execute([$ordenId,$ordenBloqueada['destino'],$fecha,$ref?:null,$obs?:null,$acceso['uid'],$enviadoToken]);
        $recId=(int)$pdo->lastInsertId();
        $folio='REC-'.str_pad((string)$recId,7,'0',STR_PAD_LEFT);
        $pdo->prepare('UPDATE erp_oc_recepciones SET folio=? WHERE id=?')->execute([$folio,$recId]);
        $ins=$pdo->prepare('INSERT INTO erp_oc_recepciones_detalle (recepcion_id,orden_detalle_id,producto_id,cantidad_piezas,lote,caducidad) VALUES (?,?,?,?,?,?)');
        foreach($registrar as $r)$ins->execute([$recId,$r['detalle_id'],$r['producto_id'],$r['cantidad'],$r['lote'],$r['caducidad']]);
        // OCAV_RECEPCION_AVISO_V1: aviso para Compras, atómico con la recepción.
        $totalRecibido=array_sum(array_column($registrar,'cantidad'));
        $pdo->prepare('INSERT INTO erp_oc_avisos_compras (recepcion_id,orden_id,usuario_emisor_id,mensaje) VALUES (?,?,?,?)')
            ->execute([$recId,$ordenId,$acceso['uid'],'Bodega confirmó '.$totalRecibido.' piezas en '.$folio.'. Revisar faltantes e iniciar facturación.']);
        $pdo->commit();
        unset($_SESSION['erp_recepcion_token']);
        header('Location: erp_recepciones_historial.php?ver='.$recId.'&ok=1',true,303);exit;
    }
    $st=$pdo->prepare("SELECT o.id,o.folio,o.destino,o.estado,o.fecha,o.observaciones,o.subtotal,
       a.estado AS autorizacion,p.nombre AS proveedor
       FROM erp_ordenes_compra o
       JOIN proveedores p ON p.id=o.proveedor_id
       LEFT JOIN erp_oc_autorizaciones a ON a.orden_id=o.id WHERE o.id=?");
    $st->execute([$ordenId]);$orden=$st->fetch(PDO::FETCH_ASSOC)?:null;
    if(!$orden){http_response_code(404);exit('Orden no encontrada.');}
    if(!erpr_puede_ver_destino($acceso,(string)$orden['destino'])){http_response_code(403);exit('Este destino no está asignado a tu usuario.');}
    $detalles=erpr_detalles($pdo,$ordenId);
    $puede=erpr_puede_registrar_destino($acceso,(string)$orden['destino']) && gd_oc_esta_lista((string)$orden['estado'],$orden['autorizacion']??null);
}catch(Throwable $e){
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    error_log('ERP registrar recepción: '.$e->getMessage());
    $error=$e instanceof RuntimeException?$e->getMessage():'No se pudo consultar o registrar la recepción. Verifica la migración SQL.';
}
// Si se rechazó el guardado, recargar el resumen para permitir corregir y reintentar.
if($orden===null && isset($pdo)){
    try{
        $st=$pdo->prepare("SELECT o.id,o.folio,o.destino,o.estado,o.fecha,o.observaciones,o.subtotal,
          a.estado AS autorizacion,p.nombre AS proveedor
          FROM erp_ordenes_compra o JOIN proveedores p ON p.id=o.proveedor_id
          LEFT JOIN erp_oc_autorizaciones a ON a.orden_id=o.id WHERE o.id=?");
        $st->execute([$ordenId]);$posible=$st->fetch(PDO::FETCH_ASSOC);
        if($posible && erpr_puede_ver_destino($acceso,(string)$posible['destino'])){
            $orden=$posible;$detalles=erpr_detalles($pdo,$ordenId);
            $puede=erpr_puede_registrar_destino($acceso,(string)$orden['destino']) && gd_oc_esta_lista((string)$orden['estado'],$orden['autorizacion']??null);
        }
    }catch(Throwable $ignorado){error_log('ERP recepción consulta post-error: '.$ignorado->getMessage());}
}
$totPedido=0;$totRecibido=0;
foreach($detalles as $d){$totPedido+=(int)$d['solicitadas'];$totRecibido+=(int)$d['recibidas'];}
$pendientes=max(0,$totPedido-$totRecibido);
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ERP G&D | Registrar recepción</title><style>
:root{font:15px system-ui,Segoe UI,Arial,sans-serif;color:#193958;background:#eef3f9}*{box-sizing:border-box}body{margin:0}.top{background:#133f6d;color:#fff;border-bottom:4px solid #cf2947;padding:22px max(18px,calc((100% - 1190px)/2));display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}.top h1{font-size:23px;margin:0}.top small{color:#cadff3}.top a{color:#fff;text-decoration:none;border:1px solid #8baccd;border-radius:9px;padding:10px 12px}main{max-width:1180px;margin:20px auto;padding:0 15px}.card{background:white;border:1px solid #e2eaf3;box-shadow:0 4px 22px #14385e0d;border-radius:14px;padding:20px;margin-bottom:16px}.hint{background:#fff4df;border:1px solid #e6cb94;padding:13px 16px;border-radius:10px;margin-bottom:18px}.error{background:#fee8e8;border-color:#e8b8b8}.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.metric{background:#eff5fc;border-radius:11px;padding:15px}.metric strong{font-size:25px;display:block;color:#145da8}.metric small{color:#526e89}.labels{display:flex;gap:12px;flex-wrap:wrap}.labels b{color:#123e70}label{display:grid;gap:6px;font-size:13px;font-weight:700}input,textarea{font:inherit;padding:10px;border:1px solid #bacbdd;border-radius:9px;min-width:0;width:100%}textarea{min-height:82px;resize:vertical}input[type=number]{max-width:130px}input[type=date]{max-width:220px}.scroll{overflow-x:auto}table{border-collapse:collapse;width:100%;min-width:920px}th{color:white;background:#194d80;text-align:left}th,td{padding:12px 10px;border-bottom:1px solid #e0eaf3;font-size:13px}td small{display:block;color:#6e8194;margin-top:5px}.count{font-size:13px;color:#315d85}.actions{display:flex;justify-content:flex-end;gap:10px;align-items:center;flex-wrap:wrap}.btn,button{display:inline-block;border:0;background:#1760a9;color:white;border-radius:9px;padding:12px 15px;text-decoration:none;font:inherit;font-weight:700;cursor:pointer}.secondary{background:#eaf2fa;color:#24517a}.btn:focus-visible,button:focus-visible,input:focus-visible{outline:3px solid #f5c751;outline-offset:2px}h2{font-size:18px;margin-top:0}@media(max-width:760px){.grid{grid-template-columns:1fr}.top h1{font-size:19px}.card{padding:14px}}
</style></head><body><header class="top"><div><h1>ERP G&amp;D · Recepción de mercancía</h1><small>Registro físico contra orden de compra emitida</small></div><div><a href="erp_recepciones_pendientes.php">← Pendientes</a> <a href="erp_recepcion_calidad.php?id=<?=$ordenId?>">Registrar diferencias (buenas/dañadas/faltantes)</a> <a href="erp_recepciones_historial.php">Historial</a></div></header><main>
<div class="hint"><b>Importante:</b> este registro confirma cantidades recibidas para seguimiento, pero <b>no aumenta existencias ni genera una entrada</b>. La aplicación a inventario se implementará posteriormente, una sola vez y con validación.</div>
<?php if($error):?><div class="hint error" role="alert"><?=erpr_html($error)?></div><?php endif;?>
<?php if($orden):?>
<section class="card"><h2>Orden <?=erpr_html($orden['folio'])?></h2><div class="labels"><span><b>Proveedor:</b> <?=erpr_html($orden['proveedor'])?></span><span><b>Destino:</b> <?=erpr_html(erpr_destinos()[$orden['destino']]??$orden['destino'])?></span><span><b>Estado:</b> <?=erpr_html($orden['estado'])?></span><?php if(gd_oc_requiere_autorizacion()):?><span><b>Autorización:</b> <?=erpr_html($orden['autorizacion']??'Pendiente')?></span><?php else:?><span><b>Autorización:</b> No requerida</span><?php endif;?></div></section>
<section class="card"><div class="grid"><div class="metric"><strong><?=number_format($totPedido)?></strong><small>Piezas solicitadas</small></div><div class="metric"><strong><?=number_format($totRecibido)?></strong><small>Recepciones registradas</small></div><div class="metric"><strong><?=number_format($pendientes)?></strong><small>Piezas pendientes</small></div></div></section>
<section class="card"><h2>Capturar entrega</h2>
<?php if(!$puede):?><div class="hint">Esta orden está disponible únicamente para consulta. Para registrar necesitas una orden emitida y permisos sobre su destino.</div><?php endif;?>
<?php if($pendientes===0):?><div class="hint">Ya se registró la totalidad de las piezas de esta orden. Consulta el historial para ver las entregas.</div><?php endif;?>
<form method="post" id="formRec"><input type="hidden" name="csrf" value="<?=erpr_html($csrf)?>"><input type="hidden" name="token" value="<?=erpr_html($token)?>"><input type="hidden" name="orden_id" value="<?=$ordenId?>">
<div class="grid" style="margin-bottom:18px"><label>Fecha de recepción<input type="date" name="fecha_recepcion" max="<?=date('Y-m-d')?>" value="<?=date('Y-m-d')?>" required <?=$puede&&$pendientes>0?'':'disabled'?>></label><label>Factura o remisión del proveedor (opcional)<input name="referencia_factura" maxlength="100" placeholder="Factura, remisión, referencia" <?=$puede&&$pendientes>0?'':'disabled'?>></label><label>Observaciones<textarea name="observaciones" maxlength="500" placeholder="Estado de los productos, diferencias o incidencias" <?=$puede&&$pendientes>0?'':'disabled'?>></textarea></label></div>
<div class="scroll"><table><thead><tr><th>Producto</th><th>Solicitadas</th><th>Ya recibidas</th><th>Pendientes</th><th>Recibir ahora (pzas.)</th><th>Lote (opcional)</th><th>Caducidad (opcional)</th></tr></thead><tbody>
<?php foreach($detalles as $d):$rest=max(0,(int)$d['solicitadas']-(int)$d['recibidas']);?><tr><td><b><?=erpr_html($d['descripcion_snapshot'])?></b><small><?=erpr_html($d['codigo_snapshot'])?></small></td><td><?=number_format((int)$d['solicitadas'])?></td><td><?=number_format((int)$d['recibidas'])?></td><td><b><?=number_format($rest)?></b></td><td><input type="number" name="cantidad[<?=(int)$d['id']?>]" min="0" max="<?=$rest?>" step="1" value="0" aria-label="Cantidad recibida de <?=erpr_html($d['descripcion_snapshot'])?>" <?=$puede&&$rest>0?'':'disabled'?>></td><td><input name="lote[<?=(int)$d['id']?>]" maxlength="100" placeholder="Ej. L24A" <?=$puede&&$rest>0?'':'disabled'?>></td><td><input type="date" name="caducidad[<?=(int)$d['id']?>]" <?=$puede&&$rest>0?'':'disabled'?>></td></tr><?php endforeach;?></tbody></table></div>
<div class="actions" style="margin-top:18px"><a class="btn secondary" href="erp_recepciones_pendientes.php">Volver</a><?php if($puede&&$pendientes>0):?><button type="submit" id="btnGuardar" onclick="return confirm('¿Confirmas que verificaste físicamente las cantidades? Se registrará la recepción SIN mover inventario.')">Guardar recepción</button><?php endif;?></div></form></section>
<?php endif;?></main><script>const form=document.getElementById('formRec');if(form)form.addEventListener('submit',e=>{let total=0;for(const el of form.querySelectorAll('input[name^="cantidad["]:not(:disabled)'))total+=Number(el.value)||0;
if(total<1){e.preventDefault();alert('Debes recibir al menos una pieza.');return;}const boton=document.getElementById('btnGuardar');if(boton){setTimeout(()=>{boton.disabled=true;boton.textContent='Registrando...';},0);}});
// OCAV_REC_FORM_V1: agrupar cantidades, lotes y caducidades en un único JSON.
if(form)form.addEventListener('submit',function(e){
  if(e.defaultPrevented)return;
  const items=[];
  for(const el of form.querySelectorAll('input[name^="cantidad["]')){
    const m=el.name.match(/^cantidad\[(\d+)\]$/);if(!m)continue;
    const tr=el.closest('tr');
    items.push({id:Number(m[1]),cantidad:el.value,lote:tr?.querySelector('input[name^="lote["]')?.value||'',caducidad:tr?.querySelector('input[name^="caducidad["]')?.value||''});
  }
  let compact=form.querySelector('input[name="recepcion_lineas_json"]');
  if(!compact){compact=document.createElement('input');compact.type='hidden';compact.name='recepcion_lineas_json';form.appendChild(compact);}
  compact.value=JSON.stringify(items);
  for(const control of form.querySelectorAll('input[name^="cantidad["],input[name^="lote["],input[name^="caducidad["]'))control.disabled=true;
});
</script>
<script src="assets/js/gyd_feedback_modal.js?v=5" defer></script>
</body></html>
