<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_facturas.php';
require_once __DIR__.'/../app/helpers/erp_facturas_oc_parciales.php';
require_once __DIR__.'/../app/helpers/erp_facturas_oc_documental.php'; // GYD_FACTURAS_DOCUMENTAL_V1
require_once __DIR__.'/../app/config/erp_oc_politica.php';
requireLogin();
$usr=currentUser()?:[];
$rol=ef_rol($usr);$uid=(int)($usr['id']??0);
if(!$uid || !in_array($rol,['ADMINISTRADOR','ADMIN','DIRECCION','COMPRAS','GERENTE','ENCARGADO','JEFE_ALMACEN'],true)) {http_response_code(403);exit('Sin acceso a facturas.');}
$error='';$flash=$_SESSION['ef_factura_flash']??'';unset($_SESSION['ef_factura_flash']);
$token=ef_token('ef_factura_captura_token');$csrf=ef_csrf();
$facturas=[];$proveedores=[];$ordenes=[];$producto=null;
try{
 $db=(new Database())->connect();

 // Precarga controlada de partidas PENDIENTES desde una orden emitida según política vigente.
 // No registra factura ni modifica inventario. Costos y cantidades se comprueban al guardar.
 if(($_GET['ajax']??'')==='detalle_oc'){
   header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
   if(!ef_puede_capturar($usr)){http_response_code(403);echo json_encode(['error'=>'Sin permisos para preparar una factura.']);exit;}
   try{
     $ocid=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
     if(!$ocid)throw new RuntimeException('Selecciona una orden de compra válida.');
     $st=$db->prepare("SELECT o.id,o.folio,o.proveedor_id,o.destino,o.observaciones,o.estado, a.estado AS autorizacion, p.nombre AS proveedor
       FROM erp_ordenes_compra o JOIN proveedores p ON p.id=o.proveedor_id
       LEFT JOIN erp_oc_autorizaciones a ON a.orden_id=o.id WHERE o.id=?");
     $st->execute([$ocid]);$ocRow=$st->fetch(PDO::FETCH_ASSOC);
     if(!$ocRow||!gd_oc_esta_lista((string)$ocRow['estado'],$ocRow['autorizacion']??null))
       throw new RuntimeException('La orden todavía no está disponible para registrar la factura.');
     $dest=ef_sucursal_oc((string)$ocRow['destino']);
     if(!isset(ef_sucursales()[$dest]))throw new RuntimeException('La orden tiene un almacén no reconocido.');
     // Conciliar recepciones PARCIALES y facturas anteriores.
     // Si ya hay recepciones, la cantidad precargada no supera lo realmente recibido.
     $saldo=ef_oc_saldo_documental($db,(int)$ocid);
     $partidas=$saldo['productos'];
     if(!$partidas)throw new RuntimeException($saldo['tiene_recepciones']
       ? 'Esta orden ya tiene todas sus piezas facturadas. No registres otra factura del mismo producto: revisa las facturas anteriores y registra la recepción de faltantes.'
       : 'La orden no tiene piezas pendientes de facturar.');
     echo json_encode(['ok'=>true,'orden'=>['id'=>(int)$ocRow['id'],'folio'=>$ocRow['folio'],
          'proveedor_id'=>(int)$ocRow['proveedor_id'],'proveedor'=>$ocRow['proveedor'],
          'sucursal'=>$dest,'observaciones'=>$ocRow['observaciones']??'',
          'piezas_pendientes'=>$saldo['totales']['facturables'],'piezas_solicitadas'=>$saldo['totales']['solicitadas'],
          'piezas_recibidas'=>$saldo['totales']['recibidas'],
          'piezas_facturadas'=>$saldo['totales']['facturadas'],
          'piezas_por_recibir'=>$saldo['totales']['por_recibir'],
          'usa_recepciones'=>$saldo['tiene_recepciones']],
          'productos'=>$partidas],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;
   }catch(Throwable $ex){
     error_log('ERP facturas precarga OC: '.$ex->getMessage());http_response_code(422);
     echo json_encode(['error'=>$ex instanceof RuntimeException?$ex->getMessage():'No fue posible consultar el detalle de la orden.'],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;
   }
 }
 if(($_GET['ajax']??'')==='productos'){
   header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
   if(!ef_puede_capturar($usr)){http_response_code(403);echo json_encode(['error'=>'Sin permisos']);exit;}
   $q=trim((string)($_GET['q']??''));
   if(mb_strlen($q)<2){echo json_encode(['productos'=>[]]);exit;}
   $st=$db->prepare("SELECT id,codigo,codigo_barras,descripcion,COALESCE(NULLIF(costo_ultimo,0),precio_compra,0) AS costo FROM productos WHERE estado=1 AND (codigo LIKE ? OR codigo_barras LIKE ? OR descripcion LIKE ?) ORDER BY descripcion LIMIT 30");
   $q='%'.mb_substr($q,0,70).'%';$st->execute([$q,$q,$q]);
   echo json_encode(['productos'=>$st->fetchAll(PDO::FETCH_ASSOC)],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;
 }
 if($_SERVER['REQUEST_METHOD']==='POST'){
   if(!ef_puede_capturar($usr))throw new RuntimeException('Solo Compras y Dirección pueden registrar facturas.');
   ef_verify_csrf((string)($_POST['csrf']??''));
   $submitted=(string)($_POST['captura_token']??'');
   if(!preg_match('/^[a-f0-9]{32}$/D',$submitted) || !hash_equals($token,$submitted)) throw new RuntimeException('Formulario vencido o repetido. Recarga antes de continuar.');
   $numero=mb_strtoupper(trim((string)($_POST['numero_factura']??'')));
   $fecha=trim((string)($_POST['fecha_factura']??''));
   $uuid=strtoupper(trim((string)($_POST['uuid']??'')));
   $prov=(int)($_POST['proveedor_id']??0);$sucursal=(string)($_POST['sucursal']??'');
   $oc=(int)($_POST['orden_id']??0);
   $obs=trim((string)($_POST['observaciones']??''));
   if($prov<1 || !isset(ef_sucursales()[$sucursal]))throw new RuntimeException('Selecciona proveedor y sucursal válidos.');
   if(mb_strlen($numero)<1||mb_strlen($numero)>100||!ef_fecha($fecha)||$fecha>date('Y-m-d'))throw new RuntimeException('Revisa el folio y fecha de factura.');
   if($uuid!==''&&!preg_match('/^[A-F0-9]{8}-(?:[A-F0-9]{4}-){3}[A-F0-9]{12}$/D',$uuid))throw new RuntimeException('UUID CFDI inválido.');
   if(mb_strlen($obs)>500)throw new RuntimeException('Observaciones: máximo 500 caracteres.');
   if($oc===0 && !ef_es_admin($usr))throw new RuntimeException('Compras debe vincular una orden emitida. Las excepciones sin orden requieren Dirección.');
   $pids=$_POST['producto_id']??[];$qtys=$_POST['cantidad']??[];$costos=$_POST['costo']??[];
   if(!is_array($pids)||!is_array($qtys)||!is_array($costos)||count($pids)<1||count($pids)>60||count($pids)!==count($qtys)||count($pids)!==count($costos))throw new RuntimeException('Agrega entre 1 y 60 productos.');
   $lineas=[];
   foreach($pids as $i=>$p){
     $pid=filter_var($p,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
     $cantidad=ef_natural($qtys[$i]);$costo=ef_costo($costos[$i]);
     if(!$pid || $cantidad<1 || isset($lineas[$pid]))throw new RuntimeException('Producto duplicado o cantidad inválida.');
     $lineas[$pid]=['qty'=>$cantidad,'costo'=>$costo];
   }
   $db->beginTransaction();
   // PRIMERA LECTURA transaccional: bloquear orden antes de cualquier lectura
   // consistente. Registrar recepciones y facturas usa el mismo bloqueo de OC.
   $o=null;
   if($oc>0){
     $st=$db->prepare("SELECT o.id,o.proveedor_id,o.destino,o.estado,a.estado AS autorizacion
       FROM erp_ordenes_compra o LEFT JOIN erp_oc_autorizaciones a ON a.orden_id=o.id WHERE o.id=? FOR UPDATE");
     $st->execute([$oc]);$o=$st->fetch(PDO::FETCH_ASSOC);
   }
   $st=$db->prepare('SELECT id FROM erp_facturas WHERE captura_token=?');$st->execute([$submitted]);
   if($existing=$st->fetchColumn()){$db->commit();header('Location: erp_facturas_verificacion.php?id='.(int)$existing,true,303);exit;}
   $ps=$db->prepare('SELECT id,nombre FROM proveedores WHERE id=? AND estado=1');$ps->execute([$prov]);
   if(!$ps->fetch())throw new RuntimeException('Proveedor inactivo o no encontrado.');
   $q=$db->prepare('SELECT id,codigo,descripcion FROM productos WHERE id=? AND estado=1');
   foreach($lineas as $pid=>&$l){$q->execute([$pid]);$p=$q->fetch(PDO::FETCH_ASSOC);if(!$p)throw new RuntimeException('Producto inactivo: '.$pid);$l['codigo']=$p['codigo'];$l['descripcion']=$p['descripcion'];}unset($l);
   if($oc>0){
     if(!$o||!gd_oc_esta_lista((string)$o['estado'],$o['autorizacion']??null)||(int)$o['proveedor_id']!==$prov||ef_sucursal_oc((string)$o['destino'])!==$sucursal)
       throw new RuntimeException('La orden debe estar emitida y corresponder al mismo proveedor y sucursal.');
     $saldo=ef_oc_saldo_documental($db,$oc);
     foreach($lineas as $pid=>$l){
       $disponible=(int)($saldo['por_producto'][$pid]['disponible']??0);
       if($disponible<$l['qty']){
         throw new RuntimeException('Cantidad no disponible en la orden para «'.$l['descripcion'].'». '
           .'Máximo facturable: '.$disponible.' piezas; cantidad capturada: '.$l['qty'].'. '
           .'El máximo corresponde a piezas aún no facturadas de la orden, con independencia de las recepciones. Confirma el documento original.');
       }
     }
   }
   $ins=$db->prepare('INSERT INTO erp_facturas(proveedor_id,sucursal,orden_id,numero_factura,uuid,fecha_factura,observaciones,usuario_id,captura_token) VALUES (?,?,?,?,?,?,?,?,?)');
   $ins->execute([$prov,$sucursal,$oc?:null,$numero,$uuid?:null,$fecha,$obs?:null,$uid,$submitted]);
   $fid=(int)$db->lastInsertId();$folio='FAC-'.str_pad((string)$fid,7,'0',STR_PAD_LEFT);
   $db->prepare('UPDATE erp_facturas SET folio_interno=? WHERE id=?')->execute([$folio,$fid]);
   $ins=$db->prepare('INSERT INTO erp_facturas_detalle(factura_id,producto_id,codigo_snapshot,descripcion_snapshot,cantidad_facturada,costo_unitario) VALUES (?,?,?,?,?,?)');
   foreach($lineas as $pid=>$l)$ins->execute([$fid,$pid,$l['codigo'],$l['descripcion'],$l['qty'],$l['costo']]);
   $db->commit();unset($_SESSION['ef_factura_captura_token']);
   $_SESSION['ef_factura_flash']='Entrada general '.$folio.' registrada como CONTABLE. Falta verificación física.';
   header('Location: erp_facturas_verificacion.php?id='.$fid,true,303);exit;
 }
 $proveedores=$db->query("SELECT id,nombre FROM proveedores WHERE estado=1 AND UPPER(nombre) NOT LIKE '%TRASPASO%' ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
 $filtroOC=gd_oc_requiere_autorizacion()?" JOIN erp_oc_autorizaciones a ON a.orden_id=o.id AND a.estado='AUTORIZADA' ":" ";
 $ordenes=$db->query("SELECT o.id,o.folio,o.destino,o.proveedor_id,p.nombre AS proveedor FROM erp_ordenes_compra o JOIN proveedores p ON p.id=o.proveedor_id ".$filtroOC." WHERE o.estado='EMITIDA' ORDER BY o.id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
 $sql="SELECT f.id,f.folio_interno,f.numero_factura,f.sucursal,f.fecha_factura,f.estado,p.nombre AS proveedor,COALESCE(x.piezas,0) AS piezas
        FROM erp_facturas f JOIN proveedores p ON p.id=f.proveedor_id LEFT JOIN
        (SELECT factura_id,SUM(cantidad_facturada) AS piezas FROM erp_facturas_detalle GROUP BY factura_id) x ON x.factura_id=f.id";
 $args=[];$where=[];
 if(!ef_es_admin($usr)&&!ef_puede_capturar($usr)){
  $alm=(int)($usr['almacen_id']??0);
  $suc=$alm===1?'HIDALGO':(in_array($alm,[2,3],true)?'TUXTLA':'__DENEGADO__');
  $where[]='f.sucursal=?';$args[]=$suc;
 }
 if($where)$sql.=' WHERE '.implode(' AND ',$where);
 $sql.=' ORDER BY f.id DESC LIMIT 120';$st=$db->prepare($sql);$st->execute($args);$facturas=$st->fetchAll(PDO::FETCH_ASSOC);
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();error_log('ERP facturas: '.$e->getMessage());$error=$e instanceof RuntimeException?$e->getMessage():'Error consultando facturas. Verifica la migración SQL y revisa el registro PHP.';}
ef_header('ERP G&D · Entradas generales por factura','Compras registra el documento; Almacén verifica después la mercancía.');
if($error):?><div class="alert error" role="alert"><?=ef_e($error)?></div><?php endif;
if($flash):?><div class="alert success"><?=ef_e($flash)?></div><?php endif;
if(ef_puede_capturar($usr)):?>
<!-- GYD_CP_PENDIENTES_COMPRAS_V2 -->
<?php
// Solo Compras y Dirección ven avisos; no altera la captura de facturas.
require_once __DIR__.'/../app/helpers/erp_cambio_precios_auto.php';
$cpAutoResumen=[];
if(cp_autorizado($usr)){
 try{$cpAutoResumen=cp_auto_facturas_pendientes($db,10);}catch(Throwable $cpAutoEx){error_log('ERP pendientes de precios: '.$cpAutoEx->getMessage());}
}
if($cpAutoResumen):?>
<section class="card" style="background:#f2f7fc;border-left:4px solid #28679b">
 <h2>Revisiones de costo pendientes</h2>
 <p>Almacén ya aceptó mercancía con un costo distinto. Revisa u omite los cambios de precio cuando puedas; <b>no se detienen las recepciones</b>.</p>
 <div style="display:flex;gap:8px;flex-wrap:wrap">
 <?php foreach($cpAutoResumen as $c):?><a class="btn alt" href="erp_facturas_verificacion.php?id=<?=(int)$c['id']?>"><?=ef_e($c['folio_interno'])?> · <?=ef_e($c['proveedor'])?></a><?php endforeach; ?>
 </div>
</section>
<?php endif;?>

<section class="card"><h2>Registrar factura</h2><p class="muted">El registro genera la entrada general contable. La mercancía no se libera a stock vendible hasta que Almacén la verifique y después se integre esa operación. <b>Captura lo que ampara la factura real, aunque existan faltantes físicos. Las piezas no recibidas quedarán pendientes para Verificación y Recepción; registrar no suma existencias.</b></p>
<form method="post" id="facturaForm" autocomplete="off"><input type="hidden" name="csrf" value="<?=ef_e($csrf)?>"><input type="hidden" name="captura_token" value="<?=ef_e($token)?>">
<div class="grid">
<div class="field"><label>Proveedor</label><select name="proveedor_id" required><option value="">Seleccionar proveedor...</option><?php foreach($proveedores as $p):?><option value="<?=(int)$p['id']?>"><?=ef_e($p['nombre'])?></option><?php endforeach;?></select></div>
<div class="field"><label>Sucursal que recibirá</label><select name="sucursal" required><option value="">Seleccionar...</option><?php foreach(ef_sucursales() as $k=>$v):?><option value="<?=ef_e($k)?>"><?=ef_e($v)?></option><?php endforeach;?></select></div>
<div class="field"><label>Orden de compra emitida</label><select name="orden_id" id="orden_id"><option value="0">Sin OC (solo Dirección)</option><?php foreach($ordenes as $o):?><option value="<?=(int)$o['id']?>" data-proveedor="<?=(int)$o['proveedor_id']?>" data-sucursal="<?=ef_e(ef_sucursal_oc((string)$o['destino']))?>"><?=ef_e($o['folio'].' · '.$o['proveedor'])?></option><?php endforeach;?></select></div>
<div class="field"><label>Número de factura</label><input name="numero_factura" maxlength="100" placeholder="Folio real del proveedor" required></div>
<div class="field"><label>Fecha de factura</label><input type="date" name="fecha_factura" max="<?=date('Y-m-d')?>" value="<?=date('Y-m-d')?>" required></div>
<div class="field"><label>UUID CFDI (opcional)</label><input name="uuid" maxlength="36" placeholder="XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX"></div>
</div>
<div class="alert" id="ocAutollenadoEstado" role="status" aria-live="polite" style="display:none"></div>
<div class="field"><label>Observaciones (opcional)</label><textarea name="observaciones" maxlength="500" placeholder="Remisión, entrega programada, referencia..."></textarea></div>
<details id="efImportadorHgo" style="margin:12px 0;padding:14px;border:1px solid #bdd4ef;border-radius:9px;background:#f4f9ff">
 <summary style="cursor:pointer;font-weight:bold;color:#17456e">Importar artículos de factura (TXT, CSV, Excel o PDF)</summary>
 <p class="muted">Formato G&D sin encabezados: <code>CÓDIGO,CANTIDAD,COSTO</code>. Puedes revisar los renglones antes de agregarlos. La importación no registra factura ni afecta inventario.</p>
 <div class="twocol"><div class="field"><label>Archivo del proveedor (máx. 6 MB)</label><input type="file" id="efImportArchivo" accept=".txt,.csv,.xlsx,.pdf,.xls"></div><div class="field"><label>&nbsp;</label><button class="btn alt" type="button" id="efImportAnalizar">Analizar TXT / archivo</button></div></div>
 <p id="efImportEstado" class="muted" role="status" aria-live="polite"></p><div id="efImportResultado" hidden>
 <div class="tablewrap"><table><thead><tr><th>Código</th><th>Producto</th><th>Piezas</th><th>Costo</th><th>Validación</th></tr></thead><tbody id="efImportFilas"></tbody></table></div>
 <button type="button" id="efImportAgregar" class="btn">Agregar artículos válidos a la factura</button></div>
 </details>
<fieldset><legend>Productos de la factura</legend><div class="twocol"><div class="field"><label>Buscar por código o descripción</label><input id="buscar" placeholder="Escribe al menos dos caracteres"></div><div class="field"><label>&nbsp;</label><button type="button" id="buscarBoton">Buscar producto</button></div></div><div id="resultados" aria-live="polite" class="muted"></div><div class="tablewrap"><table><thead><tr><th>Producto</th><th>Cantidad en piezas</th><th>Costo unitario</th><th>Quitar</th></tr></thead><tbody id="lineas"></tbody></table></div></fieldset>
<button type="submit" id="guardar">Registrar entrada general</button>
</form></section>
<?php endif;?>
<section class="card"><h2>Facturas registradas</h2><div class="tablewrap"><table><thead><tr><th>Folio ERP</th><th>Factura</th><th>Proveedor</th><th>Sucursal</th><th>Fecha</th><th>Piezas contables</th><th>Estado</th><th></th></tr></thead><tbody>
<?php foreach($facturas as $f):?><tr><td><b><?=ef_e($f['folio_interno'])?></b></td><td><?=ef_e($f['numero_factura'])?></td><td><?=ef_e($f['proveedor'])?></td><td><?=ef_e(ef_sucursales()[$f['sucursal']]??$f['sucursal'])?></td><td><?=ef_e($f['fecha_factura'])?></td><td class="num"><?=number_format((int)$f['piezas'])?></td><td><span class="badge"><?=ef_e($f['estado'])?></span></td><td><a class="btn alt" href="erp_facturas_verificacion.php?id=<?=(int)$f['id']?>">Ver / verificar</a></td></tr><?php endforeach;?>
<?php if(!$facturas):?><tr><td colspan="8">No hay facturas registradas en este módulo.</td></tr><?php endif;?></tbody></table></div></section>
<?php if(ef_puede_capturar($usr)):?>
<script>
const productos=new Map(), form=document.getElementById('facturaForm'), tbody=document.getElementById('lineas'),resultados=document.getElementById('resultados');
const get=(name)=>form.elements.namedItem(name);
const selectOC=get('orden_id');
const ocStatus=document.getElementById('ocAutollenadoEstado');
const autoOrder = new URLSearchParams(location.search).get('orden_id');
let ocLoadSeq=0;
function ocMensaje(text,isError=false){ocStatus.style.display='block';ocStatus.textContent=text;ocStatus.classList.toggle('error',isError);}
async function precargarOC(){
 const id=Number(selectOC.value), seq=++ocLoadSeq;
 if(!id){
   if(selectOC.dataset.lastOk && selectOC.dataset.lastOk!=='0' && productos.size && !confirm('¿Desvincular la orden y quitar los artículos precargados?')){
     selectOC.value=selectOC.dataset.lastOk;return;
   }
   if(selectOC.dataset.lastOk && selectOC.dataset.lastOk!=='0'){
     productos.clear();dibujar();get('proveedor_id').value='';get('sucursal').value='';get('observaciones').value='';
   }
   selectOC.dataset.lastOk='0';ocStatus.style.display='none';return;
 }
 if(productos.size>0 && !confirm('¿Reemplazar los productos de la factura con los pendientes de la orden seleccionada?')){
   selectOC.value=selectOC.dataset.lastOk||'0';return;
 }
 ocMensaje('Consultando artículos y cantidades pendientes de esta orden...');
 selectOC.disabled=true;
 try{
   const response=await fetch('erp_facturas.php?ajax=detalle_oc&id='+encodeURIComponent(String(id)),{credentials:'same-origin',headers:{'Accept':'application/json'}});
   const data=await response.json();
   if(!response.ok||!data.ok)throw new Error(data.error||'No se pudo recuperar la orden.');
   if(seq!==ocLoadSeq)return;
   const prev=new Map();
   for(const item of data.productos){prev.set(String(item.id),{...item,qty:Number(item.qty)});}
   if(prev.size===0)throw new Error('La orden ya está facturada en su totalidad; revisa el historial para los faltantes físicos.');
   productos.clear();for(const [k,v] of prev)productos.set(k,v);
   get('proveedor_id').value=String(data.orden.proveedor_id);
   get('sucursal').value=data.orden.sucursal;
   get('observaciones').value=data.orden.observaciones||'';
   selectOC.dataset.lastOk=String(id);dibujar();
   const t=data.orden;
   ocMensaje('✓ '+t.folio+': '+t.piezas_pendientes+' piezas disponibles para facturar. '+
     (t.usa_recepciones ? ('Recibidas físicamente: '+t.piezas_recibidas+' de '+t.piezas_solicitadas+
     '; pendientes de entrega: '+t.piezas_por_recibir+'; ya facturadas: '+t.piezas_facturadas+'. ')
     : 'Sin recepciones previas: se permite captura documental, pendiente de verificación. ')+
     'La cantidad propuesta es editable: captura exactamente lo facturado por el proveedor. Las piezas pendientes de recibir no entran al stock hasta su verificación. Registrar la factura NO modifica inventario.');
 }catch(e){if(seq===ocLoadSeq){ocMensaje('No se cargaron productos: '+e.message,true);selectOC.value=selectOC.dataset.lastOk||'0';}}
 finally{selectOC.disabled=false;}
}
selectOC.addEventListener('change',precargarOC);
if(autoOrder && [...selectOC.options].some(o=>o.value===autoOrder)){
 selectOC.value=autoOrder;precargarOC();
}
function dibujar(){tbody.replaceChildren();for(const p of productos.values()){
 const tr=document.createElement('tr');const td=document.createElement('td');td.textContent=p.codigo+' · '+p.descripcion;
 const hidden=document.createElement('input');hidden.type='hidden';hidden.name='producto_id[]';hidden.value=p.id;td.append(hidden);
 const cant=document.createElement('td'),q=document.createElement('input');q.type='number';q.name='cantidad[]';q.min='1';q.max=String(p.disponible??10000000);q.step='1';q.required=true;q.value=p.qty??1;q.addEventListener('input',()=>{p.qty=q.value;});cant.append(q);
 const cos=document.createElement('td'),c=document.createElement('input');c.type='number';c.name='costo[]';c.min='0';c.max='999999999';c.step='.0001';c.required=true;c.value=p.costo??'0.0000';c.addEventListener('input',()=>{p.costo=c.value;});cos.append(c);
 const rm=document.createElement('td'),btn=document.createElement('button');btn.type='button';btn.textContent='Quitar';btn.addEventListener('click',()=>{productos.delete(String(p.id));dibujar()});rm.append(btn);
 tr.append(td,cant,cos,rm);tbody.append(tr);
 }}
async function buscar(){const q=document.getElementById('buscar').value.trim();if(q.length<2){resultados.textContent='Escribe al menos dos caracteres.';return}resultados.textContent='Buscando...';try{const r=await fetch('erp_facturas.php?ajax=productos&q='+encodeURIComponent(q));const data=await r.json();if(!r.ok)throw new Error(data.error||'Sin acceso');resultados.replaceChildren();for(const p of data.productos){const b=document.createElement('button');b.type='button';b.className='btn alt';b.style.margin='4px';b.textContent=p.codigo+' · '+p.descripcion;b.addEventListener('click',()=>{if(Number(selectOC.value)>0&&!productos.has(String(p.id))){alert('Con una orden seleccionada sólo puedes capturar productos incluidos en ella.');return;}if(productos.size>=60&&!productos.has(String(p.id)))return alert('Máximo 60 productos.');productos.set(String(p.id),{...p,qty:1});dibujar()});resultados.append(b)}if(!data.productos.length)resultados.textContent='Sin resultados.';}catch(e){resultados.textContent='Error al buscar: '+e.message}}
document.getElementById('buscarBoton').addEventListener('click',buscar);document.getElementById('buscar').addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();buscar()}});
/* G&D: vista previa de importación; nunca registra la factura desde este botón. */
(()=>{
 const file=document.getElementById('efImportArchivo'),analyze=document.getElementById('efImportAnalizar');
 const status=document.getElementById('efImportEstado'),result=document.getElementById('efImportResultado'),body=document.getElementById('efImportFilas');
 const btn=document.getElementById('efImportAgregar');let ready=[];
 const say=(msg,err=false)=>{status.textContent=msg;status.style.color=err?'#a0262b':'';};
 analyze.addEventListener('click',async()=>{
  if(!file.files?.length){say('Selecciona un archivo primero.',true);return;}
  if(file.files[0].size>6*1024*1024){say('El archivo supera 6 MB.',true);return;}
  result.hidden=true;ready=[];body.replaceChildren();say('Leyendo y buscando productos del Catálogo Maestro...');
  const formData=new FormData();formData.append('csrf',get('csrf').value);formData.append('archivo',file.files[0]);
  try{
   const response=await fetch('erp_facturas_importar.php',{method:'POST',credentials:'same-origin',body:formData,headers:{'Accept':'application/json'}});
   const payload=await response.json();if(!response.ok||!payload.ok)throw Error(payload.error||'No se pudo leer el archivo');
   for(const item of payload.items){const tr=document.createElement('tr');
    for(const text of [item.codigo||'',item.descripcion||'',item.qty??'',item.costo_original??'',item.status]){const cell=document.createElement('td');cell.textContent=String(text);tr.append(cell);}
    body.append(tr);if(item.ok)ready.push(item);
   }
   btn.disabled=ready.length===0;result.hidden=false;
   say(`${payload.total} líneas examinadas · ${ready.length} artículos válidos · ${payload.revision} por revisar. Los costos con más de 4 decimales serán redondeados a 4 al preparar la factura.`);
  }catch(e){say('No se pudo analizar: '+e.message,true);}
 });
 btn.addEventListener('click',()=>{
  if(!ready.length)return;
  let added=0,skipped=0;const ocActive=Number(selectOC.value)>0;
  for(const item of ready){const id=String(item.id);let current=productos.get(id);
   if(ocActive){if(!current||item.qty>Number(current.disponible??current.qty??0)){skipped++;continue;}}
   if(!current && productos.size>=60){skipped++;continue;}
   if(current && !ocActive){skipped++;continue;}
   if(current){current.qty=item.qty;current.costo=item.costo;}else productos.set(id,{id:item.id,codigo:item.codigo,descripcion:item.descripcion,qty:item.qty,costo:item.costo});
   added++;
  }
  dibujar();say(`${added} artículos preparados para factura; ${skipped} omitidos por duplicado, límite o diferencias con la orden. Revisa y confirma antes de registrar.`,skipped>0);
 });
})();
form.addEventListener('submit',e=>{if(selectOC.disabled){e.preventDefault();alert('Espera a que termine de cargar la orden.');return;}if(!productos.size){e.preventDefault();alert('Agrega al menos un producto.');return;}if(!confirm('¿Confirmar factura y cantidades? La entrada quedará registrada contablemente, pendiente de revisión física.'))e.preventDefault();});
</script>
<?php endif;ef_footer();
