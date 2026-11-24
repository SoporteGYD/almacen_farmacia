<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_cambio_precios.php';
require_once __DIR__.'/../app/helpers/erp_cambio_precios_auto.php';
requireLogin();
$usuario=currentUser()?:[];
if(!cp_autorizado($usuario)||(int)($usuario['id']??0)<1){http_response_code(403);exit('El cambio de precios está reservado a Compras y Dirección.');}
if(empty($_SESSION['cp_csrf']))$_SESSION['cp_csrf']=bin2hex(random_bytes(24));
$csrf=$_SESSION['cp_csrf'];$autoModo=(($_GET['auto']??'')==='1'||($_POST['auto']??'')==='1');$embed=(($_GET['embed']??'')==='1'||($_POST['embed']??'')==='1');$pendientes=[];$error='';$ok=(string)($_SESSION['cp_flash']??'');unset($_SESSION['cp_flash']);
$facturaId=(int)($_GET['factura_id']??$_POST['factura_id']??0);$facturas=[];$factura=null;$lineas=[];$sucursales=[];$prices=[];$historial=[];
try {
    $db=(new Database())->connect();
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!hash_equals($csrf,(string)($_POST['csrf']??'')))throw new DomainException('Sesión vencida. Actualiza la página.');
        if(($_POST['accion']??'guardar')==='omitir'){
            $res=cp_auto_omitir($db,$usuario,$_POST);
            $_SESSION['cp_flash']='Decisión registrada: se omitió el cambio de precio para esa partida. El stock no se modificó.';
        }else{
            $res=cp_guardar($db,$usuario,$_POST);
            // GYD_AUTO_ALERTAS_PRECIO_GUARDADO
            // Revalidar inmediatamente después del COMMIT; no bloquear el cambio si falla la alerta.
            if(empty($res['duplicada']) && (int)($res['cambios']??0)>0){
                try {
                    require_once __DIR__.'/../app/helpers/erp_compras_alertas_seguimiento.php';
                    gdca_sync($db,true);
                } catch(Throwable $e){error_log('G&D alerta post cambio de precio: '.$e->getMessage());}
            }
            $_SESSION['cp_flash']=$res['duplicada']?'Este registro ya había sido procesado. No se repitió el cambio.':'Se actualizaron '.$res['cambios'].' precio(s) de venta en las sucursales seleccionadas. Inventario y costos permanecen intactos.';
        }
        $destino='erp_cambio_precios.php?factura_id='.$facturaId.($autoModo?'&auto=1':'').($embed?'&embed=1':'');
        header('Location: '.$destino,true,303);exit;
    }
    $facturas=$db->query("SELECT f.id,f.folio_interno,f.numero_factura,f.fecha_factura,pr.nombre AS proveedor
      FROM erp_facturas f JOIN proveedores pr ON pr.id=f.proveedor_id
      WHERE EXISTS(SELECT 1 FROM erp_facturas_detalle d JOIN erp_facturas_revision_detalle r ON r.factura_detalle_id=d.id AND r.tipo='ACEPTADA' AND r.cantidad>0 WHERE d.factura_id=f.id)
      ORDER BY f.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    if($facturaId>0){
        $st=$db->prepare('SELECT f.id,f.folio_interno,f.numero_factura,f.fecha_factura,f.sucursal,pr.nombre AS proveedor FROM erp_facturas f JOIN proveedores pr ON pr.id=f.proveedor_id WHERE f.id=?');
        $st->execute([$facturaId]);$factura=$st->fetch(PDO::FETCH_ASSOC);
        if(!$factura)throw new DomainException('La factura indicada no existe.');
        $st=$db->prepare("SELECT d.id AS detalle_id,d.producto_id,d.codigo_snapshot,d.descripcion_snapshot,d.costo_unitario AS costo_nuevo,
          COALESCE(NULLIF(p.costo_ultimo,0),p.precio_compra,0) AS costo_anterior,
          a.aceptadas FROM erp_facturas_detalle d JOIN productos p ON p.id=d.producto_id AND p.estado=1
          JOIN (SELECT factura_detalle_id,SUM(cantidad) aceptadas FROM erp_facturas_revision_detalle WHERE tipo='ACEPTADA' GROUP BY factura_detalle_id) a ON a.factura_detalle_id=d.id AND a.aceptadas>0
          WHERE d.factura_id=? ORDER BY d.id");
        $st->execute([$facturaId]);$lineas=$st->fetchAll(PDO::FETCH_ASSOC);
        $pendientes=cp_auto_pendientes($db,$facturaId);
        $sucursales=$db->query('SELECT id,nombre FROM pos_sucursales WHERE activo=1 ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
        if(!$sucursales)throw new DomainException('No hay sucursales POS activas; configura primero los destinos en el sistema.');
        $stPrice=$db->prepare('SELECT sucursal_id,precio_publico FROM pos_precios WHERE producto_id=?');
        foreach($lineas as $r){$pid=(int)$r['producto_id'];if(isset($prices[$pid]))continue;
            $stPrice->execute([$pid]);$prices[$pid]=[];
            foreach($stPrice->fetchAll(PDO::FETCH_ASSOC) as $p)$prices[$pid][(int)$p['sucursal_id']]=(string)$p['precio_publico'];
        }
        $st=$db->prepare('SELECT o.creado_en,o.modo,o.calculo,o.motivo,o.usuario_id,d.sucursal_id,d.precio_anterior,d.precio_nuevo,s.nombre AS sucursal,p.descripcion AS producto FROM erp_precio_operaciones o JOIN erp_precio_operacion_detalle d ON d.operacion_id=o.id JOIN pos_sucursales s ON s.id=d.sucursal_id JOIN productos p ON p.id=o.producto_id WHERE o.factura_id=? ORDER BY o.id DESC,d.id DESC LIMIT 50');
        $st->execute([$facturaId]);$historial=$st->fetchAll(PDO::FETCH_ASSOC);
    }
}catch(Throwable $ex){if(isset($db)&&$db->inTransaction())$db->rollBack();error_log('ERP cambio precios: '.$ex->getMessage());$error=($ex instanceof DomainException)?$ex->getMessage():'No fue posible cargar o guardar precios. Revisa la instalación SQL y el registro de errores de Apache.';}
$datos=[];
foreach($lineas as $r){
    $r['sucursales']=[];
    foreach($sucursales as $s){$sid=(int)$s['id'];$r['sucursales'][]=['id'=>$sid,'nombre'=>$s['nombre'],'actual'=>$prices[(int)$r['producto_id']][$sid]??null];}
    $datos[]=$r;
}
$json=json_encode($datos,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE);
$automatico=$autoModo|| (($_GET['abrir']??'')==='1');
$jsonPendientes=json_encode(array_map('intval',array_column($pendientes,'detalle_id'))); 
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Cambio de precios · ERP G&D</title>
<style>
:root{font-family:system-ui,Segoe UI,Arial,sans-serif;color:#153450;background:#f0f5fa}*{box-sizing:border-box}body{margin:0}.banner{background:linear-gradient(110deg,#103d6b,#1b567e);color:white;padding:22px max(18px,calc((100% - 1200px)/2));border-bottom:4px solid #db394e}.banner h1{font-size:23px;margin:0 0 4px}.banner p{margin:0;color:#d8e9fa;font-size:14px}main{max-width:1220px;margin:auto;padding:18px}.toolbar{display:flex;gap:9px;flex-wrap:wrap;margin-bottom:15px}a.btn,button{display:inline-block;text-decoration:none;background:#185e9a;color:white;border:0;border-radius:9px;padding:10px 15px;font:600 14px system-ui;cursor:pointer}a.btn.secondary,button.secondary{background:#e9f1fa;color:#204c70}.card{background:white;border-radius:15px;border:1px solid #dae6ef;box-shadow:0 6px 19px #1a40610c;padding:20px;margin-bottom:16px}.card h2{margin:0 0 14px;font-size:18px}.sub{color:#587189;font-size:13px}.info{padding:13px 15px;border:1px solid #bad6ee;background:#eff7ff;border-radius:10px;margin-bottom:14px}.error{background:#ffebed;border-color:#edafb6}.success{background:#e3f8ed;border-color:#a3dfbc}.select-row{display:flex;gap:10px;flex-wrap:wrap;align-items:end}.select-row label{min-width:280px;flex:1}label{font-size:13px;font-weight:700;display:block}input,select,textarea{font:inherit;border:1px solid #bed1e1;border-radius:9px;padding:10px;width:100%;background:white;min-width:0;margin-top:5px}input[type=checkbox],input[type=radio]{width:auto;margin:0 7px 0 0;accent-color:#155d96}.wrap{overflow:auto}table{border-collapse:collapse;width:100%;min-width:700px}th{background:#1b4c78;color:white;text-align:left}th,td{font-size:13px;padding:12px;border-bottom:1px solid #e0eaf3}.status{border-radius:9px;padding:4px 7px;background:#f0f3f8;color:#37506d;font-size:12px}.warn{color:#9a590e;background:#fff5dc}.num{text-align:right;font-variant-numeric:tabular-nums}dialog{border:0;border-radius:17px;width:min(980px,calc(100% - 20px));max-height:94dvh;padding:0;box-shadow:0 22px 85px #091d36a1;color:#143a58}dialog::backdrop{background:#051526a6}.modalhead{background:#164c76;color:white;padding:19px 23px}.modalhead h2{font-size:20px;margin:0 0 4px}.modalhead small{color:#d8eaf8}.modalbody{padding:18px 23px;max-height:calc(94dvh - 160px);overflow:auto}.summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px;margin:13px 0}.stat{background:#f0f6fb;padding:11px;border-radius:10px}.stat small{display:block;color:#5d748d;font-size:11px;margin-bottom:4px}.stat strong{font-size:17px}.controls{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:14px 0}.choice{padding:12px;border:1px solid #d9e5ee;border-radius:10px}.choice label{margin-bottom:9px}.stores{display:grid;gap:9px}.store{display:grid;grid-template-columns:36px minmax(160px,1.5fr) repeat(3,minmax(100px,1fr));gap:8px;align-items:center;border:1px solid #dbe7f1;padding:12px;border-radius:11px}.store:nth-child(2n){background:#f8fbfd}.store label span{display:block;font-size:11px;font-weight:500;color:#537087}.store small{color:#627c92;font-size:12px}.actions{display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;padding:15px 23px;background:#f0f6fb;border-top:1px solid #dce9f4}.acts{display:flex;gap:8px;flex-wrap:wrap}.help{font-size:13px;color:#556e84}.muted{color:#60788c}.hidden{display:none}@media(max-width:710px){.summary{grid-template-columns:1fr 1fr}.controls{grid-template-columns:1fr}.store{grid-template-columns:30px 1fr 1fr}.store .title{grid-column:span 2}.modalbody{padding:13px}.card{padding:13px}}
</style><?php if($embed):?><style>.banner,main>.toolbar,main>.info,main>.card{display:none!important} main>.info.error{display:block!important}body{background:#edf4fa} main{padding:0} .modalbody{max-height:calc(94dvh - 170px)}</style><?php endif;?></head><body><?php
$sesionBarra=__DIR__.'/../app/views/layouts/erp_barra_sesion.php';if(is_file($sesionBarra))require $sesionBarra;
?><header class="banner"><h1>ERP G&amp;D · Cambio de precios</h1><p>Compara el costo recibido, calcula márgenes y elige qué sucursales actualizar. Inspirado en el modal de WinCaja.</p></header><main>
<nav class="toolbar"><a class="btn secondary" href="erp_historial_precios.php">Historial de precios</a><a class="btn secondary" href="erp_facturas.php">Entradas generales</a><a class="btn secondary" href="erp_facturas_verificacion.php<?= $facturaId?'?id='.$facturaId:'' ?>">Verificación de factura</a><a class="btn secondary" href="erp_catalogo_maestro.php">Catálogo Maestro</a></nav>
<div class="info"><b>Seguridad:</b> este módulo únicamente cambia <b>precios públicos POS</b> en las sucursales que marques. No modifica costo de compra, costo promedio, costos históricos, inventarios, mayoreo ni registros de facturas. No cambia precios de WinCaja externo sin sincronización. El precio capturado es el precio final de venta POS, con impuestos cuando correspondan.</div>
<?php if($error):?><div class="info error"><?=cp_html($error)?></div><?php endif;?>
<?php if($ok):?><div class="info success"><?=cp_html($ok)?></div><?php endif;?>
<section class="card"><h2>1. Elige la factura de entrada</h2><form method="get" class="select-row"><label>Factura con mercancía aceptada<select name="factura_id" required><option value="">Seleccionar factura verificada...</option><?php foreach($facturas as $f):?><option value="<?=(int)$f['id']?>" <?=(int)$f['id']===$facturaId?'selected':''?>><?=cp_html($f['folio_interno'].' · '.$f['numero_factura'].' · '.$f['proveedor'])?></option><?php endforeach;?></select></label><button>Cargar productos</button></form><p class="sub">Solo se muestran artículos con al menos una pieza físicamente aceptada. La orden de compra por sí sola no genera cambios de precios.</p></section>
<?php if($factura):?><section class="card"><h2>2. Revisar variaciones de costo</h2><p class="sub"><b><?=cp_html($factura['folio_interno'])?></b> · <?=cp_html($factura['proveedor'])?> · <?=cp_html($factura['numero_factura'])?></p><div class="wrap"><table><thead><tr><th>Artículo</th><th class="num">Costo anterior*</th><th class="num">Costo en factura</th><th class="num">Piezas aceptadas</th><th>Acción</th></tr></thead><tbody>
<?php foreach($lineas as $r):$change=abs((float)$r['costo_anterior']-(float)$r['costo_nuevo'])>0.00009;?><tr><td><b><?=cp_html($r['descripcion_snapshot'])?></b><br><small><?=cp_html($r['codigo_snapshot'])?></small></td><td class="num">$<?=number_format((float)$r['costo_anterior'],4)?></td><td class="num"><b>$<?=number_format((float)$r['costo_nuevo'],4)?></b></td><td class="num"><?=(int)$r['aceptadas']?></td><td><div class="acts"><span class="status <?=$change?'warn':''?>"><?=$change?'Costo distinto':'Sin diferencia'?></span><button class="btn-open" type="button" data-detalle="<?=(int)$r['detalle_id']?>">Revisar precio</button></div></td></tr><?php endforeach;?>
<?php if(!$lineas):?><tr><td colspan="5">Esta factura todavía no tiene piezas aceptadas. Regresa a Verificación.</td></tr><?php endif;?></tbody></table></div><p class="help">* Costo anterior = costo último del catálogo (o costo inicial, si aún no hay costo último). El costo recibido se toma de la factura registrada en el ERP, no de WinCaja.</p></section><?php endif;?>
<?php if($historial):?><section class="card"><h2>Historial de cambios de esta factura</h2><div class="wrap"><table><thead><tr><th>Fecha</th><th>Artículo</th><th>Sucursal</th><th>Antes</th><th>Después</th><th>Usuario ID</th><th>Motivo</th></tr></thead><tbody><?php foreach($historial as $h):?><tr><td><?=cp_html($h['creado_en'])?></td><td><?=cp_html($h['producto'])?></td><td><?=cp_html($h['sucursal'])?></td><td><?=cp_html($h['precio_anterior']??'Sin precio previo')?></td><td><b>$<?=cp_html($h['precio_nuevo'])?></b></td><td><?=(int)$h['usuario_id']?></td><td><?=cp_html($h['motivo'])?></td></tr><?php endforeach;?></tbody></table></div></section><?php endif;?>
<dialog id="precioModal" aria-labelledby="titModal"><div class="modalhead"><h2 id="titModal">Cambio de precio</h2><small>Selecciona tiendas, revisa sugerencias y confirma antes de aplicar.</small></div><form method="post" id="precioForm"><input type="hidden" name="accion" value="guardar"><input type="hidden" name="auto" value="<?= $autoModo?'1':'0' ?>"><input type="hidden" name="embed" value="<?= $embed?'1':'0' ?>"><input type="hidden" name="csrf" value="<?=cp_html($csrf)?>"><input type="hidden" name="factura_id" value="<?=$facturaId?>"><input type="hidden" name="detalle_id" id="detalle_id"><input type="hidden" name="operacion_token" value="<?=bin2hex(random_bytes(16))?>"><div class="modalbody">
<div id="productoDescripcion" style="font-weight:700;font-size:17px"></div><div id="productoCodigo" class="sub"></div>
<div class="summary"><div class="stat"><small>Último costo registrado</small><strong id="costoAnterior">—</strong></div><div class="stat"><small>Costo factura (referencia)</small><strong id="costoNuevo">—</strong></div><div class="stat"><small>Variación</small><strong id="variacionCosto">—</strong></div><div class="stat"><small>Piezas aceptadas</small><strong id="aceptadas">—</strong></div></div>
<div class="controls"><div class="choice"><label>Forma de cambio</label><label><input type="radio" name="modo" value="MANUAL" checked>Manual: captura precio</label><label><input type="radio" name="modo" value="AUTOMATICO">Automático: recalcular</label></div><div class="choice"><label>Método para calcular el precio de venta</label><label><input type="radio" name="calculo" value="MARK_UP" checked>Ganancia sobre el costo de compra<small style="display:block;margin:3px 0 8px 23px;color:#587189;font-size:12px;font-weight:400;line-height:1.4">Suma el porcentaje al costo. Ejemplo: si cuesta $100 y eliges 30%, el precio sugerido será $130.00.</small></label><label><input type="radio" name="calculo" value="MARK_DOWN">Margen de ganancia sobre el precio de venta<small style="display:block;margin:3px 0 6px 23px;color:#587189;font-size:12px;font-weight:400;line-height:1.4">La ganancia representa ese porcentaje del precio de venta. Ejemplo: costo $100 y margen de 30% = precio sugerido $142.86.</small></label></div><div class="choice"><label>Selección de tiendas</label><button type="button" class="secondary" id="todas">Seleccionar todas</button><button type="button" class="secondary" id="ninguna">Quitar selección</button></div></div>
<p class="sub">El porcentaje sugerido viene del precio vigente y costo anterior; <strong>MARK UP calcula ganancia sobre COSTO, no margen real sobre VENTA.</strong> Para evitar la alerta de margen bajo, verifica que el precio nuevo alcance un margen real del 15% respecto al ultimo costo facturado. Los cambios solo se aplican al confirmar.</p><!-- GYD_MARGEN_V3_EDITOR -->
<div class="stores" id="listaTiendas"></div><label style="margin-top:15px">Motivo del ajuste<textarea name="motivo" maxlength="250" minlength="6" required placeholder="Ej. Ajuste comercial por nuevo costo de factura y margen objetivo de la sucursal"></textarea></label><p class="help">No se modifican los precios mayoreo: revisa posteriormente esas tarifas si corresponde. Si otro usuario cambió un precio desde que abriste este modal, el sistema detendrá el guardado.</p></div>
<div class="actions"><button type="button" class="secondary" id="despues">Decidir después</button><button type="button" class="secondary" id="omitir">Omitir este cambio</button><button type="submit" id="guardar">Guardar precios seleccionados</button></div></form></dialog>
</main>
<script>
const PRODUCTO_ENFOCADO=<?=max(0,(int)($_GET['producto_id']??0))?>;
const DATOS=<?=$json?:'[]'?>;
const PENDIENTES=<?=$jsonPendientes?:'[]'?>;
const rowsById=new Map(DATOS.map(p=>[String(p.detalle_id),p]));
const modal=document.getElementById('precioModal'), frm=document.getElementById('precioForm');
const moneda=(v)=>'$'+Number(v).toLocaleString('es-MX',{minimumFractionDigits:2,maximumFractionDigits:2});
const f=(v)=>Number.isFinite(Number(v))?Number(v):0;
let item=null;
function elemento(tag,text,cls){const n=document.createElement(tag);if(cls)n.className=cls;if(text!==undefined)n.textContent=String(text);return n;}
function render(){if(!item)return;const dest=document.getElementById('listaTiendas');dest.replaceChildren();const metodo=frm.querySelector('input[name="modo"]:checked').value;const calc=frm.querySelector('input[name="calculo"]:checked').value;
 item.sucursales.forEach(s=>{
   const sid=String(s.id),wrap=elemento('div',undefined,'store');
   const sel=document.createElement('input');sel.type='checkbox';sel.name='sucursales[]';sel.value=sid;sel.className='marca';sel.setAttribute('aria-label','Cambiar precio en '+s.nombre);
   const name=elemento('div',undefined,'title');name.append(elemento('b',s.nombre),elemento('small','Precio actual: '+(s.actual===null?'Sin precio configurado':moneda(s.actual))));
   const exp=document.createElement('input');exp.type='hidden';exp.name='precio_anterior['+sid+']';exp.value=s.actual===null?'':Number(s.actual).toFixed(2);
   const pctLabel=elemento('label');pctLabel.append(elemento('span','Margen %'));const margen=document.createElement('input');margen.type='number';margen.name='margen['+sid+']';margen.min='0';margen.max='99.99';margen.step='.01';const costo=f(item.costo_anterior),price=f(s.actual);
   const pct=costo>0&&price>0?(price/costo-1)*100:0;margen.value=Math.max(0,Math.min(99.99,pct)).toFixed(2);pctLabel.append(margen);
   const newLabel=elemento('label');newLabel.append(elemento('span','Precio nuevo $'));const nuevo=document.createElement('input');nuevo.type='number';nuevo.name='precio_nuevo['+sid+']';nuevo.min='0.01';nuevo.step='.01';nuevo.max='99999999.99';nuevo.value=s.actual===null?'':Number(s.actual).toFixed(2);newLabel.append(nuevo);
   const prevLabel=elemento('div');prevLabel.append(elemento('small','Resultado automático: '));const sug=elemento('b','—');prevLabel.append(sug);
   // GYD_MARGEN_V3_MARGEN_REAL: margen sobre venta, precio minimo y sucursal (sin cambiar el precio por sí solo).
   const margenReal=elemento('div',undefined,'sub');prevLabel.append(margenReal);
   function update(){const m=Number(margen.value),c=f(item.costo_nuevo);const t=calc==='MARK_DOWN'?(m>=100?NaN:c/(1-m/100)):c*(1+m/100);sug.textContent=Number.isFinite(t)&&t>0?moneda(t):'—';if(metodo==='AUTOMATICO')nuevo.value=Number.isFinite(t)&&t>0?t.toFixed(2):'';
      // GYD_MARGEN_V3_CALCULO: redondeo conservador hacia arriba, no modifica inputs.
      const elegido=Number(nuevo.value);const real=c>0&&elegido>0?100*(elegido-c)/elegido:null;
      const minimo=c>0?Math.ceil((c/.85)*100-1e-8)/100:null;
      margenReal.textContent=(real===null?'Margen real: sin datos':'Margen real sobre venta: '+real.toFixed(2)+'%')+
        (minimo===null?'':' · Precio minimo para 15% real: '+moneda(minimo))+
        (real!==null&&real<15?' · Este precio MANTIENE la alerta.':'');
   }
   margen.addEventListener('input',update);nuevo.addEventListener('input',update);/* GYD_MARGEN_V3_INPUT */nuevo.readOnly=metodo==='AUTOMATICO';update();wrap.append(sel,name,exp,pctLabel,newLabel,prevLabel);dest.append(wrap);
 });}
function abrir(id){item=rowsById.get(String(id));if(!item)return;frm.elements.namedItem('detalle_id').value=String(item.detalle_id);document.getElementById('productoDescripcion').textContent=item.descripcion_snapshot;document.getElementById('productoCodigo').textContent='Código: '+item.codigo_snapshot;document.getElementById('costoAnterior').textContent=moneda(item.costo_anterior);document.getElementById('costoNuevo').textContent=moneda(item.costo_nuevo);const d=f(item.costo_nuevo)-f(item.costo_anterior);document.getElementById('variacionCosto').textContent=(d>=0?'+':'')+moneda(d);document.getElementById('aceptadas').textContent=item.aceptadas;frm.elements.namedItem('motivo').value='';frm.querySelector('input[name="modo"][value="MANUAL"]').checked=true;frm.querySelector('input[name="calculo"][value="MARK_UP"]').checked=true;render();modal.showModal();}
document.querySelectorAll('.btn-open').forEach(b=>b.addEventListener('click',()=>abrir(b.dataset.detalle)));
frm.querySelectorAll('input[name="modo"],input[name="calculo"]').forEach(e=>e.addEventListener('change',()=>{const sel=[...frm.querySelectorAll('.marca:checked')].map(x=>x.value);const original={};frm.querySelectorAll('input[name^="margen["], input[name^="precio_nuevo["]').forEach(x=>original[x.name]=x.value);render();frm.querySelectorAll('.marca').forEach(x=>x.checked=sel.includes(x.value));frm.querySelectorAll('input[name^="margen["], input[name^="precio_nuevo["]').forEach(x=>{if(x.name in original && (frm.querySelector('input[name="modo"]:checked').value==='MANUAL'||x.name.startsWith('margen[')))x.value=original[x.name];});}));
document.getElementById('todas').addEventListener('click',()=>frm.querySelectorAll('.marca').forEach(x=>x.checked=true));
document.getElementById('ninguna').addEventListener('click',()=>frm.querySelectorAll('.marca').forEach(x=>x.checked=false));
document.getElementById('despues').addEventListener('click',()=>{modal.close();<?php if($embed):?>window.parent.postMessage({tipo:'gyd-cp-auto-later',factura:<?=$facturaId?>},location.origin);<?php endif;?>});
document.getElementById('omitir').addEventListener('click',()=>{
  if(!confirm('¿Confirmas OMITIR el cambio de precio para este artículo? Se guardará la decisión, y las cantidades y los costos no cambiarán.'))return;
  frm.querySelector('input[name="accion"]').value='omitir';
  frm.noValidate=true;
  HTMLFormElement.prototype.submit.call(frm);
});
frm.addEventListener('submit',e=>{if(frm.querySelectorAll('.marca:checked').length===0){e.preventDefault();alert('Selecciona al menos una sucursal.');return;}if(!confirm('¿Confirmas los cambios en las sucursales elegidas? Los precios de venta del POS se actualizarán inmediatamente. No se afectarán existencias ni costos.'))e.preventDefault();});
<?php if($automatico):?>
const first=DATOS.find(x=>PENDIENTES.includes(Number(x.detalle_id)));
if(first){abrir(first.detalle_id);}else if(<?= ($embed&&!$error)?'true':'false' ?>){window.parent.postMessage({tipo:'gyd-cp-auto-done',factura:<?=$facturaId?>},location.origin);}
<?php endif;?>
// GYD_ALERTAS_ACCIONABLES_FOCUS_V1
if(PRODUCTO_ENFOCADO>0){const enfocado=DATOS.find(p=>Number(p.producto_id)===PRODUCTO_ENFOCADO);if(enfocado)abrir(enfocado.detalle_id);}
</script>
<script src="assets/js/gyd_feedback_modal.js?v=5" defer></script>

<script src="assets/js/gyd_excel_reportes.js" defer></script>

<?php /* GYD_NAV_ROLE_V1 */
$__gydNavigationAccount = function_exists('isLoggedIn') && isLoggedIn() && function_exists('currentUser')
    ? (currentUser() ?: []) : [];
$__gydNavigationRole = strtoupper(trim((string)($__gydNavigationAccount['rol_original'] ?? $__gydNavigationAccount['rol'] ?? '')));
$__gydNavigationAllowed = in_array($__gydNavigationRole, ['DIRECCION','ADMINISTRADOR','ADMIN','COMPRAS'], true);
if (!defined('GYD_HIDE_GLOBAL_NAV') && $__gydNavigationAllowed):
$__gydNavigationHome = $__gydNavigationRole === 'COMPRAS' ? 'erp_compras_inicio.php' : 'dashboard.php';
?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=5">
<script>window.GYD_NAV_ENABLED=true;window.GYD_NAV_HOME=<?=json_encode($__gydNavigationHome, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=5"></script>
<?php endif; unset($__gydNavigationAccount,$__gydNavigationRole,$__gydNavigationAllowed,$__gydNavigationHome); ?>
</body></html>
