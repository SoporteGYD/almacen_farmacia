<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_facturas.php';
require_once __DIR__.'/../app/helpers/erp_facturas_oc_documental.php'; // GYD_OC_PROTEGER_ACEPTACION_V1
requireLogin();
$usr=currentUser()?:[];$uid=(int)($usr['id']??0);
if(!$uid || !in_array(ef_rol($usr),['ADMINISTRADOR','ADMIN','DIRECCION','COMPRAS','GERENTE','ENCARGADO','JEFE_ALMACEN'],true)){http_response_code(403);exit('Acceso denegado.');}
$fid=(int)($_GET['id']??$_POST['factura_id']??0);
$flash=$_SESSION['ef_ver_flash']??'';unset($_SESSION['ef_ver_flash']);$error='';
$csrf=ef_csrf();$token=ef_token('ef_revision_token');$extraToken=ef_token('ef_extra_token');
$factura=null;$ds=[];$historico=[];$incidencias=[];$extras=[];$lista=[];
try{
 $db=(new Database())->connect();
 if($fid>0){
    $st=$db->prepare('SELECT f.*,p.nombre AS proveedor,o.folio AS oc_folio FROM erp_facturas f JOIN proveedores p ON p.id=f.proveedor_id LEFT JOIN erp_ordenes_compra o ON o.id=f.orden_id WHERE f.id=?');$st->execute([$fid]);$factura=$st->fetch(PDO::FETCH_ASSOC)?:null;
    if(!$factura){http_response_code(404);exit('Factura no encontrada.');}
    if(!ef_puede_ver($usr,(string)$factura['sucursal'])){http_response_code(403);exit('Sin acceso a esta sucursal.');}
 }
 if($_SERVER['REQUEST_METHOD']==='POST'){
    ef_verify_csrf((string)($_POST['csrf']??''));
    if(!$factura)throw new RuntimeException('Selecciona una factura válida.');
    if(!ef_puede_verificar($usr,(string)$factura['sucursal']))throw new RuntimeException('Solo Almacén asignado o Dirección pueden registrar la verificación.');
    $accion=(string)($_POST['accion']??'');
    $db->beginTransaction();
    // Serializa verificación y recepciones de esta OC sin cambiar existencias.
    if((int)($factura['orden_id']??0)>0){
        $lkOC=$db->prepare('SELECT id FROM erp_ordenes_compra WHERE id=? FOR UPDATE');
        $lkOC->execute([(int)$factura['orden_id']]);
    }
    $lk=$db->prepare('SELECT id FROM erp_facturas WHERE id=? FOR UPDATE');$lk->execute([$fid]);if(!$lk->fetch())throw new RuntimeException('Factura no disponible.');
    if($accion==='revisar'){
        $submitted=(string)($_POST['revision_token']??'');
        if(!preg_match('/^[0-9a-f]{32}$/D',$submitted)||!hash_equals($token,$submitted))throw new RuntimeException('Revisión ya usada o caducada. Recarga la pantalla.');
        $st=$db->prepare('SELECT id FROM erp_facturas_revisiones WHERE solicitud_token=?');$st->execute([$submitted]);$dup=$st->fetchColumn();
        if($dup){$db->commit();header('Location: erp_facturas_verificacion.php?id='.$fid,true,303);exit;}
        $st=$db->prepare('SELECT id FROM erp_facturas_detalle WHERE factura_id=? ORDER BY id FOR UPDATE');$st->execute([$fid]);$ids=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN));
        $details=ef_resumen_detalles($db,$fid);$out=[];
        $maps=['ACEPTADA'=>'aceptada','FALTANTE'=>'faltante','DANADA'=>'danada','CADUCADA'=>'caducada','EQUIVOCADA'=>'equivocada'];
        $lotes=$_POST['lote']??[];$cads=$_POST['caducidad']??[];$notas=$_POST['nota']??[];
        if(!is_array($lotes)||!is_array($cads)||!is_array($notas))throw new RuntimeException('Datos de recepción inválidos.');
        foreach($details as $d){
            $did=(int)$d['id'];if(!in_array($did,$ids,true))throw new RuntimeException('Producto fuera de factura.');
            $pendiente=(int)$d['cantidad_facturada']-(int)$d['aceptadas']-(int)$d['incidencias_pendientes'];
            if($pendiente<0)throw new RuntimeException('Diferencia de control. Revisa los movimientos existentes.');
            $suma=0;$nota=trim((string)($notas[$did]??''));$lote=trim((string)($lotes[$did]??''));$cad=trim((string)($cads[$did]??''));
            if(mb_strlen($nota)>500||mb_strlen($lote)>100||($cad!==''&&!ef_fecha($cad)))throw new RuntimeException('Observaciones, lote o caducidad no válidos.');
            foreach($maps as $tipo=>$campo){
                $field=$_POST[$campo]??[];if(!is_array($field))throw new RuntimeException('Formulario inválido.');
                $cantidad=ef_natural($field[$did]??'0');
                if(!$cantidad)continue;
                if($tipo==='ACEPTADA' && $cad!=='' && $cad<date('Y-m-d'))throw new RuntimeException('No puedes aceptar un lote caducado: '.(string)$d['descripcion_snapshot'].'. Regístralo como CADUCADA.');
                if($tipo!=='ACEPTADA' && mb_strlen($nota)<5)throw new RuntimeException('Explica con al menos 5 caracteres la incidencia de '.(string)$d['descripcion_snapshot']);
                $suma+=$cantidad;$out[]=['id'=>$did,'tipo'=>$tipo,'cantidad'=>$cantidad,'lote'=>$tipo==='ACEPTADA'&&$lote!==''?$lote:null,'cad'=>$tipo==='ACEPTADA'&&$cad!==''?$cad:null,'nota'=>$nota?:null];
            }
            if($suma>$pendiente)throw new RuntimeException('La distribución de '.(string)$d['descripcion_snapshot'].' excede '.(string)$pendiente.' piezas pendientes.');
        }
        if(!$out)throw new RuntimeException('Indica al menos una cantidad para verificar o reportar.');
        ef_oc_proteger_aceptacion($db,(int)($factura['orden_id']??0),$details,$out);
        $obs=trim((string)($_POST['observaciones']??''));if(mb_strlen($obs)>500)throw new RuntimeException('Observaciones demasiado largas.');
        $db->prepare('INSERT INTO erp_facturas_revisiones (factura_id,solicitud_token,usuario_id,observaciones) VALUES (?,?,?,?)')->execute([$fid,$submitted,$uid,$obs?:null]);
        $revisionId=(int)$db->lastInsertId();
        $ins=$db->prepare('INSERT INTO erp_facturas_revision_detalle (revision_id,factura_detalle_id,tipo,cantidad,lote,caducidad,observaciones) VALUES (?,?,?,?,?,?,?)');
        foreach($out as $a)$ins->execute([$revisionId,$a['id'],$a['tipo'],$a['cantidad'],$a['lote'],$a['cad'],$a['nota']]);
        ef_actualizar_estado($db,$fid);$db->commit();unset($_SESSION['ef_revision_token']);
        $_SESSION['ef_ver_flash']='Verificación registrada. Las piezas aceptadas ya pueden aplicarse mediante «Aplicar al inventario»; no se sumaron automáticamente.';
    }elseif($accion==='resolver'){
        $id=(int)($_POST['incidencia_id']??0);$res=(string)($_POST['resolucion']??'');
        $nota=trim((string)($_POST['nota_resolucion']??''));
        if($id<=0||!in_array($res,['NOTA_CREDITO','REPOSICION_RECIBIDA','DEVOLUCION','AJUSTE_DOCUMENTAL','OTRA'],true)||mb_strlen($nota)<5||mb_strlen($nota)>500)throw new RuntimeException('Selecciona resolución e introduce motivo de al menos 5 caracteres.');
        $st=$db->prepare("SELECT rd.id,rd.tipo,rd.cantidad,rd.resolucion,rd.factura_detalle_id FROM erp_facturas_revision_detalle rd JOIN erp_facturas_revisiones r ON r.id=rd.revision_id WHERE r.factura_id=? AND rd.id=? FOR UPDATE");$st->execute([$fid,$id]);$inc=$st->fetch(PDO::FETCH_ASSOC);
        if(!$inc||$inc['tipo']==='ACEPTADA'||$inc['resolucion']!==null)throw new RuntimeException('Incidencia inexistente o ya resuelta.');
        if($res==='REPOSICION_RECIBIDA'){
            $pCheck=$db->prepare('SELECT id,producto_id,descripcion_snapshot FROM erp_facturas_detalle WHERE id=? AND factura_id=?');
            $pCheck->execute([(int)$inc['factura_detalle_id'],$fid]);
            $dCheck=$pCheck->fetch(PDO::FETCH_ASSOC);
            if(!$dCheck)throw new RuntimeException('No se encontró la partida de la reposición.');
            ef_oc_proteger_aceptacion($db,(int)($factura['orden_id']??0),[$dCheck],[
                ['id'=>(int)$inc['factura_detalle_id'],'tipo'=>'ACEPTADA','cantidad'=>(int)$inc['cantidad']]
            ]);
            $newToken=bin2hex(random_bytes(16));
            $db->prepare('INSERT INTO erp_facturas_revisiones(factura_id,solicitud_token,usuario_id,observaciones) VALUES (?,?,?,?)')->execute([$fid,$newToken,$uid,'Reposición físicamente recibida: '.$nota]);
            $revisionId=(int)$db->lastInsertId();
            $db->prepare("INSERT INTO erp_facturas_revision_detalle(revision_id,factura_detalle_id,tipo,cantidad,observaciones) VALUES (?,?,'ACEPTADA',?,?)")->execute([$revisionId,$inc['factura_detalle_id'],$inc['cantidad'],'Reposición de incidencia #'.$id]);
        }
        $db->prepare('UPDATE erp_facturas_revision_detalle SET resolucion=?,resolucion_nota=?,resuelto_por=?,resuelto_en=NOW() WHERE id=? AND resolucion IS NULL')->execute([$res,$nota,$uid,$id]);
        ef_actualizar_estado($db,$fid);$db->commit();
        $_SESSION['ef_ver_flash']='Incidencia resuelta: '.str_replace('_',' ',$res).'.';
    }elseif($accion==='extra'){
        $submitted=(string)($_POST['extra_token']??'');
        if(!preg_match('/^[0-9a-f]{32}$/D',$submitted)||!hash_equals($extraToken,$submitted))throw new RuntimeException('Formulario de sobrante usado o caducado. Actualiza la página.');
        $st=$db->prepare('SELECT id FROM erp_facturas_incidencias_adicionales WHERE solicitud_token=?');$st->execute([$submitted]);
        if($st->fetchColumn()){$db->commit();header('Location: erp_facturas_verificacion.php?id='.$fid,true,303);exit;}
        $tipo=(string)($_POST['tipo_extra']??'');$cantidad=ef_natural($_POST['cantidad_extra']??'0');$descripcion=trim((string)($_POST['descripcion_extra']??''));
        if(!in_array($tipo,['SOBRANTE','SIN_FACTURA','OTRO'],true)||$cantidad<1||mb_strlen($descripcion)<6||mb_strlen($descripcion)>500)throw new RuntimeException('Indica tipo, cantidad y explicación de al menos 6 caracteres.');
        $prod=(int)($_POST['producto_extra']??0);
        if($prod>0){$ps=$db->prepare('SELECT COUNT(*) FROM erp_facturas_detalle WHERE factura_id=? AND producto_id=?');$ps->execute([$fid,$prod]);if(!(int)$ps->fetchColumn())throw new RuntimeException('El producto de la incidencia no pertenece a esta factura. Déjalo como no identificado.');}
        $db->prepare('INSERT INTO erp_facturas_incidencias_adicionales(factura_id,producto_id,tipo,cantidad,descripcion,registrado_por,solicitud_token) VALUES (?,?,?,?,?,?,?)')->execute([$fid,$prod?:null,$tipo,$cantidad,$descripcion,$uid,$submitted]);
        ef_actualizar_estado($db,$fid);$db->commit();unset($_SESSION['ef_extra_token']);
        $_SESSION['ef_ver_flash']='Incidencia adicional registrada en revisión (no se agregó existencia).';
    }elseif($accion==='resolver_extra'){
        $extraId=(int)($_POST['extra_id']??0);$nota=trim((string)($_POST['nota_extra']??''));
        if($extraId<1||mb_strlen($nota)<5||mb_strlen($nota)>500)throw new RuntimeException('Escribe una solución válida.');
        $st=$db->prepare("UPDATE erp_facturas_incidencias_adicionales SET estado='RESUELTA',resolucion=?,resuelto_por=?,resuelto_en=NOW() WHERE factura_id=? AND id=? AND estado='ABIERTA'");
        $st->execute([$nota,$uid,$fid,$extraId]);if($st->rowCount()!==1)throw new RuntimeException('Incidencia ya resuelta.');
        ef_actualizar_estado($db,$fid);$db->commit();$_SESSION['ef_ver_flash']='Incidencia adicional cerrada.';
    }else throw new RuntimeException('Acción desconocida.');
    header('Location: erp_facturas_verificacion.php?id='.$fid,true,303);exit;
 }
 if($factura){
     $ds=ef_resumen_detalles($db,$fid);
     $st=$db->prepare("SELECT rd.*,r.fecha,u.nombre AS usuario,ur.nombre AS usuario_resolvio,d.codigo_snapshot,d.descripcion_snapshot FROM erp_facturas_revision_detalle rd JOIN erp_facturas_revisiones r ON r.id=rd.revision_id JOIN erp_facturas_detalle d ON d.id=rd.factura_detalle_id LEFT JOIN usuarios u ON u.id=r.usuario_id LEFT JOIN usuarios ur ON ur.id=rd.resuelto_por WHERE r.factura_id=? ORDER BY rd.id DESC LIMIT 400");$st->execute([$fid]);$historico=$st->fetchAll(PDO::FETCH_ASSOC);
     foreach($historico as $h)if($h['tipo']!=='ACEPTADA')$incidencias[]=$h;
     $st=$db->prepare('SELECT * FROM erp_facturas_incidencias_adicionales WHERE factura_id=? ORDER BY id DESC');$st->execute([$fid]);$extras=$st->fetchAll(PDO::FETCH_ASSOC);
 }else{
    $sql='SELECT f.id,f.folio_interno,f.numero_factura,f.sucursal,f.estado,p.nombre proveedor FROM erp_facturas f JOIN proveedores p ON p.id=f.proveedor_id';$params=[];
    if(!ef_puede_capturar($usr)){
      $alm=(int)($usr['almacen_id']??0);$suc=$alm===1?'HIDALGO':(in_array($alm,[2,3],true)?'TUXTLA':'__N/A__');
      $sql.=' WHERE f.sucursal=?';$params[]=$suc;
    }
    $sql.=' ORDER BY f.id DESC LIMIT 150';$st=$db->prepare($sql);$st->execute($params);$lista=$st->fetchAll(PDO::FETCH_ASSOC);
 }
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();error_log('ERP verificación factura: '.$e->getMessage());$error=$e instanceof RuntimeException?$e->getMessage():'No fue posible registrar o consultar la verificación. Revisa el registro PHP y la instalación SQL.';}
ef_header('ERP G&D · Verificación de mercancía','Almacén revisa, reporta diferencias y deja evidencia para Compras y Dirección.');
if($error):?><div class="alert error" role="alert"><?=ef_e($error)?></div><?php endif;
if($flash):?><div class="alert success"><?=ef_e($flash)?></div><?php endif;
if(!$factura):?>
<section class="card"><h2>Facturas pendientes y verificadas</h2><div class="tablewrap"><table><thead><tr><th>Folio ERP</th><th>Factura</th><th>Proveedor</th><th>Sucursal</th><th>Estado</th><th></th></tr></thead><tbody><?php foreach($lista as $f):?><tr><td><b><?=ef_e($f['folio_interno'])?></b></td><td><?=ef_e($f['numero_factura'])?></td><td><?=ef_e($f['proveedor'])?></td><td><?=ef_e(ef_sucursales()[$f['sucursal']]??$f['sucursal'])?></td><td><?=ef_e($f['estado'])?></td><td><a class="btn alt" href="?id=<?=(int)$f['id']?>">Abrir</a></td></tr><?php endforeach;?><?php if(!$lista):?><tr><td colspan="6">Sin registros.</td></tr><?php endif;?></tbody></table></div></section>
<?php else:
  $puede=ef_puede_verificar($usr,(string)$factura['sucursal']);
  $total=0;$aceptadas=0;$incObs=0;foreach($ds as $d){$total+=(int)$d['cantidad_facturada'];$aceptadas+=(int)$d['aceptadas'];$incObs+=(int)$d['incidencias_pendientes'];}
?>
<section class="card"><h2><?=ef_e($factura['folio_interno'])?> — <?=ef_e($factura['numero_factura'])?></h2><div class="grid"><div class="metric"><small>Proveedor</small><br><b><?=ef_e($factura['proveedor'])?></b></div><div class="metric"><small>Sucursal</small><br><b><?=ef_e(ef_sucursales()[$factura['sucursal']]??$factura['sucursal'])?></b></div><div class="metric"><small>Estado</small><br><b><?=ef_e($factura['estado'])?></b></div></div><p class="muted">Orden: <?=ef_e($factura['oc_folio']??'Captura extraordinaria por Dirección')?> · Fecha CFDI/factura: <?=ef_e($factura['fecha_factura'])?> · UUID: <?=ef_e($factura['uuid']??'No registrado')?></p></section>
<?php if(ef_puede_verificar($usr,(string)$factura['sucursal'])): ?><section class="card"><a class="btn" href="erp_facturas_aplicar.php?id=<?=$fid?>">📦 Aplicar partidas aceptadas al inventario</a><p class="muted">Sólo aparecerán las piezas que Almacén aceptó. La entrada no ocurre hasta pulsar el botón de confirmación de la pantalla siguiente.</p></section><?php endif; ?>
<!-- GYD_CAMBIO_PRECIOS_V1 -->
<?php if(ef_puede_capturar($usr) && $aceptadas>0): ?>
<section class="card"><h2>Revisar costo y precios de venta</h2><p class="muted">Si el proveedor modificó un costo, Compras puede revisar el precio antes de decidir si cambia el de las sucursales. No altera inventario.</p><a class="btn alt" href="erp_cambio_precios.php?factura_id=<?= (int)$fid ?>&amp;abrir=1">💲 Abrir cambio de precio</a></section>
<?php endif; ?>
<!-- GYD_CP_AUTO_COSTO_V2 -->
<?php
// Complemento aislado: si falla el módulo de precios NO detiene la verificación/recepción.
if ($fid>0 && ef_puede_ver($usr,(string)$factura['sucursal'])):
  require_once __DIR__.'/../app/helpers/erp_cambio_precios_auto.php';
  $cpAutoLista=[];
  if(cp_autorizado($usr)){
    try{$cpAutoLista=cp_auto_pendientes($db,$fid);}catch(Throwable $cpAutoEx){error_log('ERP aviso costo: '.$cpAutoEx->getMessage());}
  }
  if($cpAutoLista):
?>
<style>
#cpAutoDialog{border:0;border-radius:17px;padding:0;width:min(1040px,calc(100% - 18px));height:min(92vh,820px);box-shadow:0 25px 90px #091d3770}
#cpAutoDialog::backdrop{background:#10243fb0}
#cpAutoDialog .cpAutoHead{padding:14px 20px;background:#154b78;color:white;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
#cpAutoDialog .cpAutoHead strong{font-size:17px}
#cpAutoDialog .cpAutoHead p{margin:4px 0 0;font-size:12px;color:#d4e9fa}
#cpAutoDialog iframe{border:0;width:100%;height:calc(100% - 114px);background:#edf4fa}
#cpAutoDialog .cpAutoFoot{padding:10px 15px;background:#f3f8fe;display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap}
#cpAutoDialog button{background:#e8f1f8;border:1px solid #caddea;border-radius:8px;padding:9px 12px;color:#214563;font-weight:600;cursor:pointer}
</style>
<dialog id="cpAutoDialog" aria-labelledby="cpAutoTitle">
 <header class="cpAutoHead">
  <div><strong id="cpAutoTitle">Se detectó un cambio de costo</strong><p><?=count($cpAutoLista)?> artículo(s) aceptado(s) tienen costo distinto al último registrado. Solo Compras/Dirección modifica precios.</p></div>
  <button type="button" id="cpAutoCloseTop">Continuar sin revisar ahora</button>
 </header>
 <iframe id="cpAutoIframe" title="Revisión automática de precios de la factura" src="erp_cambio_precios.php?factura_id=<?=(int)$fid?>&amp;auto=1&amp;embed=1"></iframe>
 <footer class="cpAutoFoot"><button type="button" id="cpAutoCloseBottom">Continuar con recepción / facturación</button></footer>
</dialog>
<section class="card" style="background:#f0f8ff;border-left:4px solid #2a6ca6">
 <strong>Revisión de precios pendiente:</strong> <?=count($cpAutoLista)?> artículo(s) con costo diferente.
 <a class="btn alt" href="erp_cambio_precios.php?factura_id=<?=(int)$fid?>&amp;auto=1">Abrir revisión de costos</a>
 <span class="muted">La verificación y la aplicación de inventario continúan aunque no cambies precios.</span>
</section>
<script>
(()=>{
 const d=document.getElementById('cpAutoDialog');
 const frame=document.getElementById('cpAutoIframe');
 const close=()=>{if(d.open)d.close();};
 document.getElementById('cpAutoCloseTop').addEventListener('click',close);
 document.getElementById('cpAutoCloseBottom').addEventListener('click',close);
 window.addEventListener('message',e=>{
  if(e.origin!==location.origin||e.source!==frame.contentWindow||!e.data||e.data.factura!==<?=(int)$fid?>)return;
  if(e.data.tipo==='gyd-cp-auto-done'||e.data.tipo==='gyd-cp-auto-later')close();
 });
 const show=()=>{if(!d.open&&typeof d.showModal==='function')d.showModal();};
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',show,{once:true});else show();
})();
</script>
<?php endif;endif; ?>

<section class="card"><h2>Resumen por cantidades</h2><div class="grid"><div class="metric"><small>Facturadas · registro general</small><strong><?=number_format($total)?></strong></div><div class="metric"><small>Aceptadas físicamente</small><strong><?=number_format($aceptadas)?></strong></div><div class="metric"><small>En incidencias (incluso acreditadas)</small><strong><?=number_format($incObs)?></strong></div></div><p class="muted">Importante: Las aceptadas NO incrementan existencias automáticamente. Abre «Aplicar al inventario» para seleccionar y confirmar las partidas pendientes. Una partida sólo puede aplicarse una vez.</p></section>
<?php // GYD_OC_VISTA_PARCIAL_V2 - Sólo lectura; historial histórico intacto.
if ($factura && (int)($factura['orden_id']??0)>0):
    $seguimiento=erp_oc_seguimiento_por_factura($db,$fid);
?>
<section class="card" style="border-left:5px solid #1b6aa2">
<h2>Recepciones parciales vinculadas a esta factura</h2>
<p class="muted">Los datos "aceptadas" y "aplicadas" muestran saldos <b>netos</b> después de ajustes auditados. Las verificaciones históricas y la factura original permanecen intactas.</p>
<div class="tablewrap"><table><thead><tr><th>Producto</th><th>Facturadas</th><th>Recibidas (OC)</th><th>Aceptadas netas</th><th>Aplicadas netas</th><th>Por recibir</th><th>Por verificar</th></tr></thead><tbody>
<?php foreach($seguimiento as $r): ?>
<tr><td><?=ef_e($r['producto'])?></td><td class="num"><?=(int)$r['facturadas']?></td><td class="num"><?=(int)$r['recibidas']?></td><td class="num"><?=(int)$r['aceptadas_netas']?></td><td class="num"><?=(int)$r['aplicadas_netas']?></td><td class="num"><?=(int)$r['por_recibir']?></td><td class="num"><?=(int)$r['por_verificar']?></td></tr>
<?php endforeach; ?></tbody></table></div>
<p class="muted">Cuando llegue mercancía faltante, primero regístrala en <b>Recepciones por OC</b>, vuelve aquí para verificar sólo el saldo y, al final, utiliza <b>Aplicar al inventario</b>. No registres una segunda factura por las mismas piezas.</p>
<a class="btn alt" href="erp_recepciones_pendientes.php">Ver órdenes pendientes de recepción</a>
</section>
<?php endif; ?>
<section class="card"><h2>Verificar físicamente los productos</h2>
<?php if(!$puede):?><div class="alert">Tu rol puede consultar, pero no registrar la verificación de este destino.</div><?php else:?>
<p class="muted">Captura buenas, faltantes, dañadas, caducadas o equivocadas en cada renglón. Para mercancía que llegará después puedes dejar la cantidad pendiente en cero y reportarla en una segunda revisión.</p>
<form method="post" onsubmit="return confirm('¿Registrar esta verificación física? Las cantidades quedarán listas para aplicación posterior, sin sumar todavía stock.')"><input type="hidden" name="accion" value="revisar"><input type="hidden" name="csrf" value="<?=ef_e($csrf)?>"><input type="hidden" name="revision_token" value="<?=ef_e($token)?>"><input type="hidden" name="factura_id" value="<?=$fid?>">
<div class="tablewrap"><table style="min-width:1210px"><thead><tr><th>Producto</th><th>Factura</th><th>Aceptadas</th><th>Incid.</th><th>Pendiente</th><th>Buenas</th><th>Faltantes</th><th>Dañadas</th><th>Caducadas</th><th>Equivocadas</th><th>Lote</th><th>Caducidad</th><th>Observación</th></tr></thead><tbody>
<?php foreach($ds as $d):$did=(int)$d['id'];$pen=max(0,(int)$d['cantidad_facturada']-(int)$d['aceptadas']-(int)$d['incidencias_pendientes']);?><tr><td><b><?=ef_e($d['descripcion_snapshot'])?></b><br><small><?=ef_e($d['codigo_snapshot'])?></small></td><td class="num"><?=number_format((int)$d['cantidad_facturada'])?></td><td class="num"><?=number_format((int)$d['aceptadas'])?></td><td class="num"><?=number_format((int)$d['incidencias_pendientes'])?></td><td class="num"><b><?=$pen?></b></td>
<?php foreach(['aceptada','faltante','danada','caducada','equivocada'] as $k):?><td><input style="width:80px" aria-label="<?=ef_e($k.' '.$d['descripcion_snapshot'])?>" name="<?=ef_e($k)?>[<?=$did?>]" type="number" min="0" max="<?=$pen?>" value="0" step="1" <?=$pen===0?'disabled':''?>></td><?php endforeach;?><td><input style="width:115px" name="lote[<?=$did?>]" maxlength="100" placeholder="Lote" <?=$pen===0?'disabled':''?>></td><td><input style="width:145px" name="caducidad[<?=$did?>]" type="date" <?=$pen===0?'disabled':''?>></td><td><input style="width:210px" name="nota[<?=$did?>]" maxlength="500" placeholder="Explicar cualquier diferencia" <?=$pen===0?'disabled':''?>></td></tr><?php endforeach;?></tbody></table></div>
<div class="field"><label>Observaciones generales</label><textarea maxlength="500" name="observaciones"></textarea></div>
<button type="submit">Guardar verificación</button></form>
<?php endif;?></section>
<section class="card"><h2>Incidencias detectadas</h2><div class="tablewrap"><table><thead><tr><th>Producto / causa</th><th>Cantidad</th><th>Estado</th><th>Resolución</th><th>Acciones</th></tr></thead><tbody>
<?php foreach($incidencias as $i):?><tr><td><?=ef_e($i['descripcion_snapshot'])?><br><span class="badge"><?=ef_e($i['tipo'])?></span><br><small><?=ef_e($i['observaciones']??'')?></small></td><td><?=$i['cantidad']?></td><td><?= $i['resolucion'] ? 'Resuelta' : 'Abierta'?></td><td><?=ef_e($i['resolucion']??'Pendiente')?><?php if($i['resolucion_nota']):?><br><small><?=ef_e($i['resolucion_nota'])?> · <?=ef_e($i['usuario_resolvio']??'')?></small><?php endif;?></td><td>
<?php if($puede&&!$i['resolucion']):?><form method="post" onsubmit="return confirm('¿Confirmas que ya ocurrió la solución seleccionada? Para reposición, las piezas DEBEN haber llegado físicamente.')"><input type="hidden" name="accion" value="resolver"><input type="hidden" name="csrf" value="<?=ef_e($csrf)?>"><input type="hidden" name="factura_id" value="<?=$fid?>"><input type="hidden" name="incidencia_id" value="<?=(int)$i['id']?>"><div class="field"><select name="resolucion" required><option value="">Seleccionar...</option><option value="NOTA_CREDITO">Nota de crédito recibida</option><option value="REPOSICION_RECIBIDA">Reposición físicamente recibida</option><option value="DEVOLUCION">Devolución física registrada</option><option value="AJUSTE_DOCUMENTAL">Ajuste documental autorizado</option><option value="OTRA">Otra resolución</option></select></div><div class="field"><input name="nota_resolucion" maxlength="500" minlength="5" placeholder="Folio NC, remisión o evidencia" required></div><button>Resolver</button></form><?php else:?>—<?php endif;?></td></tr><?php endforeach;?>
<?php if(!$incidencias):?><tr><td colspan="5">Aún no hay incidencias en productos facturados.</td></tr><?php endif;?></tbody></table></div></section>
<section class="card"><h2>Sobrantes y mercancía no identificada en factura</h2>
<?php if($puede):?><form method="post" class="grid"><input type="hidden" name="accion" value="extra"><input type="hidden" name="extra_token" value="<?=ef_e($extraToken)?>"><input type="hidden" name="csrf" value="<?=ef_e($csrf)?>"><input type="hidden" name="factura_id" value="<?=$fid?>"><div class="field"><label>Tipo</label><select name="tipo_extra"><option value="SOBRANTE">Sobrante físico</option><option value="SIN_FACTURA">Producto sin factura</option><option value="OTRO">Otra incidencia</option></select></div><div class="field"><label>Cantidad</label><input type="number" name="cantidad_extra" min="1" max="10000000" required></div><div class="field"><label>Producto en catálogo (opcional)</label><select name="producto_extra"><option value="0">No identificado</option><?php foreach($ds as $d):?><option value="<?=(int)$d['producto_id']?>"><?=ef_e($d['codigo_snapshot'].' · '.$d['descripcion_snapshot'])?></option><?php endforeach;?></select></div><div class="field" style="grid-column:1/-1"><label>Descripción y motivo</label><textarea name="descripcion_extra" minlength="6" maxlength="500" required></textarea></div><div><button>Registrar sobrante / incidencia</button></div></form><?php endif;?>
<div class="tablewrap"><table><thead><tr><th>Tipo</th><th>Cantidad</th><th>Explicación</th><th>Estado</th><th>Acción</th></tr></thead><tbody><?php foreach($extras as $e):?><tr><td><?=ef_e($e['tipo'])?></td><td><?=(int)$e['cantidad']?></td><td><?=ef_e($e['descripcion'])?><?php if($e['resolucion']):?><br><small>Resuelto: <?=ef_e($e['resolucion'])?></small><?php endif;?></td><td><?=ef_e($e['estado'])?></td><td><?php if($puede && $e['estado']==='ABIERTA'):?><form method="post" onsubmit="return confirm('¿Cerrar esta incidencia documentada? No aplica movimiento de inventario.')"><input type="hidden" name="accion" value="resolver_extra"><input type="hidden" name="csrf" value="<?=ef_e($csrf)?>"><input type="hidden" name="factura_id" value="<?=$fid?>"><input type="hidden" name="extra_id" value="<?=(int)$e['id']?>"><input name="nota_extra" placeholder="Resolución y respaldo" minlength="5" maxlength="500" required><button>Resolver</button></form><?php else:?>—<?php endif;?></td></tr><?php endforeach;?><?php if(!$extras):?><tr><td colspan="5">Sin sobrantes registrados.</td></tr><?php endif;?></tbody></table></div></section>
<section class="card"><h2>Historial de verificaciones</h2><div class="tablewrap"><table><thead><tr><th>Fecha</th><th>Producto</th><th>Tipo</th><th>Cantidad</th><th>Lote / caducidad</th><th>Verificado por</th><th>Observación</th></tr></thead><tbody><?php foreach($historico as $h):?><tr><td><?=ef_e($h['fecha'])?></td><td><?=ef_e($h['codigo_snapshot'].' · '.$h['descripcion_snapshot'])?></td><td><?=ef_e($h['tipo'])?></td><td><?=(int)$h['cantidad']?></td><td><?=ef_e($h['lote']??'')?> <?=ef_e($h['caducidad']??'')?></td><td><?=ef_e($h['usuario']??'')?></td><td><?=ef_e($h['observaciones']??'')?></td></tr><?php endforeach;?><?php if(!$historico):?><tr><td colspan="7">Sin verificaciones todavía.</td></tr><?php endif;?></tbody></table></div></section>
<?php endif;ef_footer();
