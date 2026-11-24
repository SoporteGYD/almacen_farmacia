<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
requireLogin();
require_once __DIR__.'/../app/models/TraspasosGyD.php';
$u=currentUser()?:[];
$rol=strtoupper(trim((string)($u['rol_original']??$u['rol']??'')));
if(!in_array($rol,['ADMINISTRADOR','ADMIN','DIRECCION','GERENTE','ENCARGADO'],true)){http_response_code(403);exit('Sin autorización para Traspasos.');}
$uid=(int)($u['id']??0);if($uid<1){http_response_code(403);exit('Cuenta inválida.');}
if(!isset($_SESSION['traspasos_csrf']))$_SESSION['traspasos_csrf']=bin2hex(random_bytes(32));
$csrf=(string)$_SESSION['traspasos_csrf'];
function tr_h($s):string{return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$model=new TraspasosGyD();$error='';$mensaje=(string)($_SESSION['traspasos_flash']??'');unset($_SESSION['traspasos_flash']);
$lista=[];$detalle=null;$sucursales=[];$selected=(int)($_GET['id']??0);
try {
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!hash_equals($csrf,(string)($_POST['csrf']??'')))throw new DomainException('Sesión expirada; recarga el formulario.');
        $accion=(string)($_POST['accion']??'');$id=(int)($_POST['id']??0);
        if($accion==='crear')$id=$model->crear($u,$_POST);
        elseif($accion==='preparar'){$model->preparar($u,$id,is_array($_POST['loc']??null)?$_POST['loc']:[]);}
        elseif($accion==='despachar')$model->despachar($u,$id);
        elseif($accion==='recibir')$model->recibir($u,$id,is_array($_POST['recibida']??null)?$_POST['recibida']:[],(string)($_POST['token']??''),trim((string)($_POST['observaciones']??'')));
        elseif($accion==='cancelar')$model->cancelar($u,$id,(string)($_POST['motivo']??''));
        else throw new DomainException('Acción inválida.');
        $_SESSION['traspasos_flash']='Operación registrada correctamente.';
        header('Location: traspasos.php?id='.$id,true,303);exit;
    }
    $sucursales=$model->sucursales();$lista=$model->listado($u);
    if($selected>0)$detalle=$model->ver($u,$selected);
}catch(DomainException $e){$error=$e->getMessage();
    try{$sucursales=$model->sucursales();$lista=$model->listado($u);if($selected>0)$detalle=$model->ver($u,$selected);}catch(Throwable $_){}}
catch(Throwable $e){error_log('ERP traspasos: '.$e->getMessage());$error='No fue posible consultar o procesar el traspaso. Revisa las tablas y el registro PHP.';}
$global=TraspasosGyD::global($u);$asig=TraspasosGyD::sucursalUsuario($u);
$puedeOrigen=$detalle && ($global||$asig===(int)$detalle['origen_id']);
$puedeDestino=$detalle && ($global||$asig===(int)$detalle['destino_id']);
$puedeCancelar=$detalle && ($puedeOrigen || ($detalle['modalidad']==='SOLICITUD' && $detalle['estado']==='SOLICITADO' && (int)$detalle['solicitado_por']===$uid));
$imp=(isset($_GET['imprimir'])&&$detalle);
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>G&D · Traspasos de Mercancía</title>
<style>
:root{font-family:system-ui,-apple-system,Segoe UI,Arial,sans-serif;color:#1b3957;background:#edf3fa;line-height:1.5}*{box-sizing:border-box}body{margin:0}header{background:linear-gradient(110deg,#12395f,#1767ad);color:#fff;padding:22px max(16px,calc((100% - 1230px)/2))}h1{font-size:25px;margin:0}header p{margin:4px 0;color:#d5e8f9}.wrap{max-width:1240px;margin:19px auto;padding:0 16px}.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(310px,1fr));gap:14px}.card{background:white;border-radius:14px;box-shadow:0 3px 20px #16487618;margin:0 0 16px;padding:20px}h2{font-size:19px;margin:0 0 13px}h3{font-size:15px;margin:0 0 10px}.muted,.small{color:#647d93;font-size:13px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}label{display:block;font-weight:700;font-size:13px;margin:8px 0 5px}input,select,textarea{font:inherit;width:100%;border:1px solid #bccde0;border-radius:9px;padding:10px;background:white;color:#1c3954}textarea{min-height:64px}button,.btn{display:inline-block;text-decoration:none;color:#fff;background:#1761ae;border:none;border-radius:9px;padding:10px 13px;font:bold 14px system-ui;cursor:pointer}.btn.alt,button.alt{background:#e7eef8;color:#174e85}.btn.red,button.red{background:#bd3853}.actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:12px 0}.notice{background:#fff7e5;border:1px solid #e3c37f;border-radius:10px;padding:12px;margin:12px 0}.bad{background:#ffecec;border-color:#e9a1a6;color:#86263b}.good{background:#e8f9f0;border-color:#a7d7bb;color:#145c39}.scroll{overflow:auto}table{border-collapse:collapse;width:100%;min-width:720px}th{background:#184d83;color:white;text-align:left}th,td{padding:10px;border-bottom:1px solid #dce6f0;font-size:13px}tr:nth-child(even) td{background:#f9fbfe}.pill{display:inline-block;padding:5px 9px;border-radius:30px;background:#e9f1fa;color:#19578d;font:bold 11px system-ui}.pill.complete{background:#dff5e6;color:#216f43}.pill.warn{background:#fff0d5;color:#785619}.kbd{background:#e8eef6;padding:2px 5px;border-radius:4px}.formline{display:grid;grid-template-columns:minmax(150px,2fr) minmax(80px,1fr) auto;gap:9px;align-items:end}.formline button{height:44px}.pending{font-weight:700;color:#b05228}.labelinfo{padding:8px;border-radius:6px;background:#f2f7fc}.right{text-align:right}@media(max-width:660px){.card{padding:14px}.formline{grid-template-columns:1fr 1fr}.formline label:first-child{grid-column:span 2}.formline button{grid-column:span 2}}@media print{header,.noprint,.actions,form,button{display:none!important}.wrap{margin:0;max-width:none}.card{box-shadow:none;border:1px solid #aaa;break-inside:avoid}body{background:white;color:#000}}
</style></head><body>
<header><h1>Traspasos de Mercancía</h1><p>Solicitudes · Preparación · En tránsito · Recepción verificada</p></header>
<main class="wrap">
<div class="actions noprint"><a class="btn alt" href="dashboard.php">← Inicio</a><a class="btn alt" href="resurtidos.php">Resurtidos internos</a><a class="btn alt" href="erp_conciliacion_inventarios.php">Conciliación de inventarios</a></div>
<?php if($mensaje):?><div class="notice good" role="status"><?=tr_h($mensaje)?></div><?php endif;?>
<?php if($error):?><div class="notice bad" role="alert"><?=tr_h($error)?></div><?php endif;?>
<?php if($detalle):?>
<section class="card">
<div class="actions"><h2 style="margin:0">Traspaso <?=tr_h($detalle['folio'])?></h2><span class="pill <?=($detalle['estado']==='COMPLETADO'?'complete':'warn')?>"><?=tr_h(str_replace('_',' ',$detalle['estado']))?></span><a class="btn alt noprint" href="traspasos.php">Volver</a> <!-- GYD_CARTA_PORTE_DEMO --> <a class="btn alt noprint" href="carta_porte_demo.php?id=<?=(int)$detalle['id']?>">Carta Porte DEMO</a><a class="btn alt noprint" href="traspasos.php?id=<?=(int)$detalle['id']?>&amp;imprimir=1" target="_blank">Imprimir comprobante</a><?php if($imp):?><button class="noprint" onclick="window.print()">Imprimir</button><?php endif;?></div>
<div class="grid"><div><div class="small">Origen</div><b><?=tr_h($detalle['origen'])?></b> · <?=tr_h($detalle['origen_tipo'])?></div><div><div class="small">Destino</div><b><?=tr_h($detalle['destino'])?></b> · <?=tr_h($detalle['destino_tipo'])?></div><div><div class="small">Modalidad</div><?=tr_h($detalle['modalidad'])?></div><div><div class="small">Registro</div><?=tr_h($detalle['creado_en'])?></div></div>
<?php if($detalle['observaciones']):?><p class="notice"><?=tr_h($detalle['observaciones'])?></p><?php endif;?>
<div class="scroll"><table><thead><tr><th>Código / Producto</th><th>Ubicación origen</th><th>Solicitado</th><th>Despachado</th><th>Recibido bueno</th><th>Pendiente</th></tr></thead><tbody>
<?php foreach($detalle['items'] as $d):$pend=(int)$d['enviado']-(int)$d['recibido'];?><tr><td><b><?=tr_h($d['codigo'])?></b><div><?=tr_h($d['descripcion'])?></div></td><td><?=tr_h($d['ubicacion']??'—')?></td><td><?= (int)$d['solicitado']?></td><td><?=(int)$d['enviado']?></td><td><?=(int)$d['recibido']?></td><td class="pending"><?=$pend?></td></tr><?php endforeach;?></tbody></table></div>
<?php if($puedeOrigen && in_array($detalle['estado'],['SOLICITADO','POR_PREPARAR'],true)):?>
<form method="post" class="card noprint" style="box-shadow:none;border:1px solid #d5e3f0;margin-top:16px"><input type="hidden" name="csrf" value="<?=tr_h($csrf)?>"><input type="hidden" name="accion" value="preparar"><input type="hidden" name="id" value="<?=(int)$detalle['id']?>"><h3>Preparar mercancía</h3><p class="small">Selecciona las ubicaciones físicas de salida; las cantidades se verificarán otra vez al despachar.</p>
<?php if($detalle['origen_tipo']==='ALMACEN'):?>
<?php foreach($detalle['items'] as $d):$ubicaciones=$model->ubicaciones((int)$detalle['origen_id'],(int)$d['producto_id']);?><label><?=tr_h($d['codigo'])?> — <?=tr_h($d['descripcion'])?></label><select name="loc[<?=(int)$d['producto_id']?>]" required><option value="">Selecciona ubicación</option><?php foreach($ubicaciones as $loc):?><option value="<?=(int)$loc['id']?>"><?=tr_h($loc['ubicacion'])?> · <?=(int)$loc['existencia']?> disponibles</option><?php endforeach;?></select><?php endforeach;?>
<?php endif;?><div class="actions"><button>Marcar preparado</button></div></form>
<?php endif;?>
<?php if($puedeOrigen && $detalle['estado']==='PREPARADO'):?><form method="post" class="actions noprint" onsubmit="return confirm('¿Confirmas la salida física de la mercancía? Se descontará del origen y quedará en tránsito.');"><input type="hidden" name="csrf" value="<?=tr_h($csrf)?>"><input type="hidden" name="accion" value="despachar"><input type="hidden" name="id" value="<?=(int)$detalle['id']?>"><button>Confirmar despacho y descontar origen</button></form><?php endif;?>
<?php if($puedeDestino && in_array($detalle['estado'],['EN_TRANSITO','PARCIAL','INCIDENCIA'],true)):?>
<form method="post" class="card noprint" style="box-shadow:none;border:1px solid #d5e3f0;margin-top:16px"><input type="hidden" name="csrf" value="<?=tr_h($csrf)?>"><input type="hidden" name="token" value="<?=bin2hex(random_bytes(16))?>"><input type="hidden" name="id" value="<?=(int)$detalle['id']?>"><input type="hidden" name="accion" value="recibir"><h3>Registrar recepción física</h3><p class="small">Captura solo unidades buenas realmente recibidas. Si hay faltantes, piezas dañadas o sobrantes, descríbelos abajo. No se acreditan automáticamente.</p>
<?php foreach($detalle['items'] as $d):$pend=max(0,(int)$d['enviado']-(int)$d['recibido']);?><label for="rec_<?=(int)$d['producto_id']?>"><?=tr_h($d['codigo'])?> — <?=tr_h($d['descripcion'])?> · Pendiente <?=$pend?></label><input type="number" id="rec_<?=(int)$d['producto_id']?>" name="recibida[<?=(int)$d['producto_id']?>]" min="0" max="<?=$pend?>" value="0" required><?php endforeach;?><label>Observaciones / Diferencias</label><textarea name="observaciones" maxlength="500" placeholder="Registra faltantes, dañados y excedentes, si los hay."></textarea><div class="actions"><button>Confirmar recepción e ingresar piezas buenas</button></div></form>
<?php endif;?>
<?php if($puedeCancelar && in_array($detalle['estado'],['SOLICITADO','POR_PREPARAR','PREPARADO'],true)):?><form method="post" class="noprint" onsubmit="return confirm('¿Cancelar el traspaso?');"><input type="hidden" name="csrf" value="<?=tr_h($csrf)?>"><input type="hidden" name="accion" value="cancelar"><input type="hidden" name="id" value="<?=(int)$detalle['id']?>"><label>Motivo de cancelación</label><textarea name="motivo" minlength="5" maxlength="500" required></textarea><div class="actions"><button class="red">Cancelar antes del despacho</button></div></form><?php endif;?>
<div class="grid" style="margin-top:35px;page-break-inside:avoid"><div>____________________________<br><b>Entrega / Despacha</b></div><div>____________________________<br><b>Transporte</b></div><div>____________________________<br><b>Recibe / Verifica</b></div></div>
</section>
<section class="card"><h2>Historial de movimientos</h2><div class="scroll"><table><thead><tr><th>Fecha</th><th>Acción</th><th>Responsable</th><th>Detalle</th></tr></thead><tbody><?php foreach($detalle['eventos'] as $e):?><tr><td><?=tr_h($e['creado_en'])?></td><td><?=tr_h($e['accion'])?></td><td><?=tr_h($e['autor'])?></td><td><?=tr_h($e['detalle'])?></td></tr><?php endforeach;?></tbody></table></div></section>
<?php else:?>
<section class="card noprint"><h2>Registrar traspaso</h2><div class="notice">Puedes solicitar desde destino o enviar directamente desde origen. En ambos casos se preparan las partidas y solo se descuentan cuando se confirma el despacho. Los traspasos internos Almacén → Farmacia continúan en <b>Resurtidos</b>.</div>
<form method="post" id="crear"><input type="hidden" name="csrf" value="<?=tr_h($csrf)?>"><input type="hidden" name="accion" value="crear"><input type="hidden" name="items_json" id="items_json">
<div class="grid"><div><label>Modalidad</label><select name="modalidad" required><option value="SOLICITUD">Solicitud desde destino</option><option value="DIRECTO">Envío directo desde origen</option></select></div>
<div><label>Sucursal origen</label><select name="origen_id" id="origen" required><option value="">Selecciona origen</option><?php foreach($sucursales as $s):?><option value="<?=(int)$s['id']?>"><?=tr_h($s['nombre'])?></option><?php endforeach;?></select></div>
<div><label>Ubicación origen</label><select name="origen_tipo" id="origen_tipo"><option value="ALMACEN">Almacén</option><option value="FARMACIA">Farmacia</option><option value="UNICO">Inventario único</option></select></div>
<div><label>Sucursal destino</label><select name="destino_id" id="destino" required><option value="">Selecciona destino</option><?php foreach($sucursales as $s):?><option value="<?=(int)$s['id']?>"><?=tr_h($s['nombre'])?></option><?php endforeach;?></select></div>
<div><label>Ubicación destino</label><select name="destino_tipo" id="destino_tipo"><option value="ALMACEN">Almacén</option><option value="FARMACIA">Farmacia</option><option value="UNICO">Inventario único</option></select></div></div>
<h3 style="margin-top:16px">Agregar productos</h3><div class="formline"><label>Código o código de barras<input id="codigo" placeholder="Escanear código" autocomplete="off" maxlength="150"></label><label>Cantidad en piezas<input id="cantidad" type="number" value="1" min="1" max="100000"></label><button type="button" id="agregar">Agregar</button></div><div class="scroll"><table style="min-width:480px"><thead><tr><th>Producto / código</th><th>Piezas</th><th>Acciones</th></tr></thead><tbody id="items_tbody"><tr><td colspan="3">Agrega los productos a traspasar.</td></tr></tbody></table></div>
<label>Observaciones</label><textarea name="observaciones" maxlength="500"></textarea><div class="actions"><button>Registrar solicitud o envío directo</button></div>
</form></section>
<section class="card"><h2>Historial y seguimiento</h2><div class="scroll"><table><thead><tr><th>Folio</th><th>Fecha</th><th>Origen</th><th>Destino</th><th>Modalidad</th><th>Estado</th><th></th></tr></thead><tbody><?php foreach($lista as $r):?><tr><td><b><?=tr_h($r['folio'])?></b></td><td><?=tr_h($r['creado_en'])?></td><td><?=tr_h($r['origen'])?></td><td><?=tr_h($r['destino'])?></td><td><?=tr_h($r['modalidad'])?></td><td><span class="pill <?=($r['estado']==='COMPLETADO'?'complete':'warn')?>"><?=tr_h(str_replace('_',' ',$r['estado']))?></span></td><td><a class="btn alt" href="?id=<?=(int)$r['id']?>">Ver / gestionar</a></td></tr><?php endforeach;?><?php if(!$lista):?><tr><td colspan="7">Aún no hay traspasos.</td></tr><?php endif;?></tbody></table></div></section>
<?php endif;?>
<div class="notice"><b>Control de diferencias:</b> si la recepción es parcial, las piezas faltantes permanecen pendientes. No se acreditan faltantes, mercancía dañada ni excedentes sin una gestión formal. Los traslados directos Tapachula ↔ Toscana permanecen bloqueados hasta configurar sus ubicaciones físicas compartidas.</div>
</main>
<?php if(!$detalle):?><script>
(()=>{'use strict';const items=[];const $=id=>document.getElementById(id);const tb=$('items_tbody');
function sync(){ $('items_json').value=JSON.stringify(items);tb.innerHTML='';if(items.length===0){const tr=document.createElement('tr'),td=document.createElement('td');td.colSpan=3;td.textContent='Agrega los productos a traspasar.';tr.appendChild(td);tb.appendChild(tr);return;}
 items.forEach((p,i)=>{const tr=document.createElement('tr');for(const val of [p.codigo,String(p.cantidad)]){const td=document.createElement('td');td.textContent=val;tr.appendChild(td);}const td=document.createElement('td'),b=document.createElement('button');b.type='button';b.className='red';b.textContent='Quitar';b.addEventListener('click',()=>{items.splice(i,1);sync()});td.appendChild(b);tr.appendChild(td);tb.appendChild(tr);});}
$('agregar').addEventListener('click',()=>{const codigo=$('codigo').value.trim(),cant=Number($('cantidad').value);if(!codigo||!Number.isSafeInteger(cant)||cant<=0||cant>100000){alert('Indica código y cantidad válida.');return;}if(items.some(i=>i.codigo.toUpperCase()===codigo.toUpperCase())){alert('El producto ya está agregado.');return;}if(items.length>=60){alert('Máximo 60 productos.');return;}items.push({codigo,cantidad:cant});$('codigo').value='';$('cantidad').value='1';sync();$('codigo').focus();});
$('crear').addEventListener('submit',ev=>{if(!items.length){ev.preventDefault();alert('Agrega productos antes de continuar.');return;}const ori=Number($('origen').value),des=Number($('destino').value);if(ori===des||(ori===3||ori===4)&&(des===3||des===4)){ev.preventDefault();alert('Selecciona sucursales diferentes. Tapachula y Toscana comparten inventario y requieren configuración especial para traslados entre ambas.');}});
function tipo(s,t){const v=Number($(s).value);const e=$(t);if(v===1||v===2){e.innerHTML='<option value="ALMACEN">Almacén</option><option value="FARMACIA">Farmacia</option>';}else{e.innerHTML='<option value="UNICO">Inventario único</option>';}}
$('origen').addEventListener('change',()=>tipo('origen','origen_tipo'));$('destino').addEventListener('change',()=>tipo('destino','destino_tipo'));sync();})();
</script><?php endif;?></body></html>
