<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
requireLogin();
$usuario=currentUser()??[];
$rol=strtoupper(trim((string)($usuario['rol_original']??$usuario['rol']??'')));
if(!in_array($rol,['ADMINISTRADOR','ADMIN','DIRECCION'],true)){http_response_code(403);exit('Acceso exclusivo para ADMINISTRADOR y DIRECCIÓN.');}
require_once __DIR__.'/../app/models/PosReversiones.php';
function rev_html(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
if(empty($_SESSION['pos_rev_csrf']))$_SESSION['pos_rev_csrf']=bin2hex(random_bytes(32));
$csrf=(string)$_SESSION['pos_rev_csrf'];
$consulta=trim((string)($_GET['q']??''));
$ventaId=(int)($_GET['venta']??0);
$error='';$exito=$_SESSION['pos_rev_flash']??'';unset($_SESSION['pos_rev_flash']);
$ventas=[];$detalle=null;$tickets=[];
try{
 $mod=new PosReversiones();
 if($_SERVER['REQUEST_METHOD']==='POST'){
     if(!hash_equals($csrf,(string)($_POST['csrf']??'')))throw new DomainException('Token vencido. Recarga la página.');
     $accion=(string)($_POST['accion']??'');
     if($accion==='verificar_origen'){
         $flash=$mod->verificarOrigenHistorico($usuario,$_POST);
         $next='erp_pos_reversiones.php?venta='.(int)($_POST['venta_id']??0);
     }elseif($accion==='cancelar_reserva'){
         $flash=$mod->cancelarReserva((int)($_POST['ticket_id']??0),$usuario,(string)($_POST['motivo']??''));
         $next='erp_pos_reversiones.php';
     }else{
         $flash=$mod->procesar($usuario,$_POST);
         $next='erp_pos_reversiones.php?venta='.(int)($_POST['venta_id']??0);
     }
     $_SESSION['pos_rev_flash']=$flash;
     header('Location: '.$next,true,303);exit;
 }
 $ventas=$mod->buscar($consulta);
 if($ventaId>0)$detalle=$mod->venta($ventaId);
 $tickets=$mod->ticketsSinCobrar();
}catch(DomainException $e){$error=$e->getMessage();}
catch(Throwable $e){error_log('ERP reversiones POS: '.$e->getMessage());$error='No se pudo consultar o registrar. Verifica la migración SQL y los registros PHP.';}
?><!doctype html>
<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ERP G&D | Cancelaciones y devoluciones POS</title>
<style>
:root{font:15px system-ui,Segoe UI,Arial,sans-serif;color:#173755;background:#ecf2f9}*{box-sizing:border-box}body{margin:0}
header{padding:23px max(18px,calc((100% - 1150px)/2));background:linear-gradient(120deg,#133c67,#215eb0);color:white;border-bottom:4px solid #db344c}
header h1{margin:0;font-size:25px}header p{margin:7px 0 0;color:#dceaf9}
main{max-width:1190px;margin:22px auto;padding:0 14px}.links{display:flex;gap:9px;flex-wrap:wrap;margin-bottom:16px}.btn,button{border:0;background:#185eab;color:white;padding:11px 15px;border-radius:9px;font:700 14px system-ui;display:inline-flex;text-decoration:none;cursor:pointer;align-items:center;justify-content:center}.btn.alt{background:#e4ecf8;color:#194675}.btn.red,button.red{background:#ba2948}.btn:disabled,button:disabled{opacity:.5;cursor:not-allowed}.card{background:white;padding:23px;border-radius:15px;box-shadow:0 5px 18px #1c507d13;margin-bottom:18px}.muted{color:#657e96;font-size:13px}.alert{padding:15px;border:1px solid #dfbf7c;border-radius:10px;background:#fff5e2;line-height:1.55}.alert.bad{background:#fee8e8;border-color:#ec939e;color:#81243a}.alert.good{background:#e7f8ef;border-color:#aad6bf;color:#225b3e}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(245px,1fr));gap:14px}.fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(215px,1fr));gap:13px}label{display:block;font-weight:700;font-size:13px;margin:6px 0}input,textarea,select{width:100%;padding:11px;border-radius:8px;border:1px solid #bfcee0;font:inherit}textarea{min-height:81px;resize:vertical}input[type=checkbox]{width:auto}form .row{display:flex;gap:10px;align-items:end;flex-wrap:wrap}.row>*{flex:1}table{border-collapse:collapse;width:100%;min-width:820px}th,td{padding:11px;text-align:left;border-bottom:1px solid #e2eaf3;font-size:13px}th{background:#194c82;color:white}.overflow{overflow:auto}.tag{display:inline-block;padding:5px 9px;border-radius:99px;background:#edf2f9;font-weight:700;font-size:11px}.danger{color:#a42945}.check{display:flex;align-items:flex-start;gap:9px;margin:11px 0;font-weight:500;font-size:13px}.box{border:1px solid #d8e3ef;border-radius:12px;padding:17px}.box h3{margin:0 0 10px}.small{font-size:12px;color:#66809a}.historico{border:1px solid #dbbd85;background:#fffaee;border-radius:14px;padding:18px;margin-top:18px}.historico h3{color:#73521a}.pill-ok{color:#14583e;background:#dff5e9}.pill-missing{color:#8b3442;background:#fde8ed}.origin-claim{border-bottom:1px solid #e3d7bb;padding:17px 0}.origin-claim:last-child{border-bottom:0}.critical{background:#fff2dc;border-left:4px solid #d59426;padding:12px;border-radius:7px}.hint-note{font-size:13px;line-height:1.5;color:#5b6780}button:disabled{opacity:.5;cursor:not-allowed}h2{font-size:20px;margin:0 0 16px}h3{font-size:16px}@media(max-width:650px){.card{padding:14px}header h1{font-size:20px}}
</style></head><body>
<header><h1>Cancelaciones, devoluciones y reversas POS</h1><p>Farmacias G&amp;D · Control por sucursal, ticket de Almacén, auditoría y reembolsos</p></header>
<main><nav class="links"><a class="btn alt" href="pos.php">← Punto de Venta</a><a class="btn alt" href="erp_pos_devoluciones_almacen.php<?= $ventaId>0?'?venta='.$ventaId:'' ?>">Devoluciones de Almacén</a><a class="btn alt" href="erp_reservas_almacen.php">Reservas de Almacén</a><a class="btn alt" href="erp_conciliacion_inventarios.php">Conciliación</a><a class="btn alt" href="dashboard.php">Inicio</a></nav>
<section class="card"><div class="alert"><strong>Controles para evitar diferencias:</strong> las ventas antiguas también pueden revisarse y documentarse por producto antes de una devolución; no se presupone ningún origen. Una solicitud de Almacén sin cobro puede liberarse si no ha salido ninguna pieza. Si Almacén ya surtió, si la mercancía está dañada o si el turno original está cerrado para una cancelación completa, primero registra una incidencia; nunca se incrementa stock sin comprobación física. Los reembolsos electrónicos se realizan fuera del ERP y requieren referencia real.</div></section>
<?php if($exito):?><section class="card alert good" role="status"><?=rev_html($exito)?></section><?php endif;?>
<?php if($error):?><section class="card alert bad" role="alert"><?=rev_html($error)?></section><?php endif;?>
<section class="card"><h2>Buscar venta</h2><form method="get"><div class="row"><div><label for="q">Folio de venta POS</label><input id="q" name="q" value="<?=rev_html($consulta)?>" placeholder="PV-01-0000123"></div><div style="flex:0 0 auto"><button>Buscar</button></div></div></form>
<?php if($ventas):?><div class="overflow" style="margin-top:14px"><table><thead><tr><th>Folio</th><th>Sucursal</th><th>Cajero</th><th>Total</th><th>Estado</th><th>Fecha</th><th>Acción</th></tr></thead><tbody><?php foreach($ventas as $v):?><tr><td><?=rev_html($v['folio'])?></td><td><?=rev_html($v['sucursal'])?></td><td><?=rev_html($v['cajero'])?></td><td>$<?=rev_html($v['total'])?></td><td><span class="tag"><?=rev_html($v['estado'])?></span></td><td><?=rev_html($v['pagada_en'])?></td><td><a class="btn alt" href="?venta=<?=(int)$v['id']?>">Ver</a></td></tr><?php endforeach;?></tbody></table></div><?php elseif($consulta!==''):?><p class="muted">No se encontraron ventas con ese folio.</p><?php endif;?></section>
<?php if($detalle):?>
<?php
$pendientesOrigen=[];$verificacionesPorProducto=[];$devolvibles=[];
foreach(($detalle['origen_legacy']??[]) as $ver)$verificacionesPorProducto[(int)$ver['producto_id']]=$ver;
foreach($detalle['detalle'] as $d){
 $pid=(int)$d['producto_id'];
 if(!$d['origen'] && !isset($pendientesOrigen[$pid]))$pendientesOrigen[$pid]=$d;
 if(in_array($d['origen']??'',['FARMACIA','FARMACIA_GENERAL'],true))$devolvibles[]=$d;
}
$esSucursalConAlmacen=pi_almacen((int)$detalle['sucursal_id'])>0;
?>
<section class="card"><h2>Venta <?=rev_html($detalle['folio'])?> · <?=rev_html($detalle['estado'])?></h2><div class="grid"><div><b>Sucursal</b><p><?=rev_html($detalle['sucursal'])?></p></div><div><b>Cajero</b><p><?=rev_html($detalle['cajero'])?></p></div><div><b>Importe original</b><p><strong>$<?=rev_html($detalle['total'])?></strong></p></div><div><b>Turno</b><p>#<?=rev_html($detalle['turno_id'])?></p></div></div>
<div class="overflow"><table><thead><tr><th>Partida</th><th>Producto</th><th>Vendidas</th><th>Equivalencia</th><th>Total</th><th>Origen físico</th></tr></thead><tbody><?php foreach($detalle['detalle'] as $d):?><tr><td>#<?=(int)$d['id']?></td><td><?=rev_html($d['descripcion_snapshot'])?></td><td><?=rev_html($d['cantidad'])?> <?=rev_html($d['presentacion']??'PIEZA')?></td><td><?=rev_html($d['piezas_por_presentacion']??1)?> piezas</td><td>$<?=rev_html($d['total_renglon'])?></td><td><span class="tag <?=empty($d['origen'])?'pill-missing':'pill-ok'?>"><?=rev_html($d['origen']??'SIN REGISTRO · revisión manual')?></span><?php if(isset($verificacionesPorProducto[(int)$d['producto_id']])):?><div class="small">Validó: <?=rev_html($verificacionesPorProducto[(int)$d['producto_id']]['verificado_por'])?> · <?=rev_html($verificacionesPorProducto[(int)$d['producto_id']]['verificado_en'])?></div><?php endif;?></td></tr><?php endforeach;?></tbody></table></div>
<?php if($pendientesOrigen):?>
<div class="historico" id="verificar-origen-historico">
 <h3>Verificar origen de venta antigua</h3>
 <p class="hint-note">Esta venta se hizo antes de registrar el origen físico en POS. El origen debe ser verificado por ADMINISTRADOR o DIRECCIÓN mediante comprobantes o movimiento físico; no es válido adivinarlo. <strong>Registrar el origen NO cambia el inventario, pagos ni caja.</strong></p>
 <p class="critical"><strong>Importante:</strong> si la misma mercancía se surtió parcialmente desde Farmacia y parcialmente desde Almacén, no debes asignar un solo origen; registra una incidencia. Si ya se sumó el producto devuelto en algún conteo o ajuste, tampoco debe acreditarse de nuevo.</p>
 <?php foreach($pendientesOrigen as $historico):?>
 <div class="origin-claim">
  <p><strong><?=rev_html($historico['descripcion_snapshot'])?></strong><br><span class="small">Producto #<?=(int)$historico['producto_id']?> · <?=rev_html($historico['codigo_snapshot'])?> · Partida #<?=(int)$historico['id']?> · <?=rev_html($historico['cantidad'])?> <?=rev_html($historico['presentacion']??'PIEZA')?></span></p>
  <form method="post" onsubmit="return confirm('¿Confirmas que comprobaste el origen real de este producto? Esta acción quedará auditada y no modifica existencias.');">
   <input type="hidden" name="csrf" value="<?=rev_html($csrf)?>">
   <input type="hidden" name="accion" value="verificar_origen">
   <input type="hidden" name="venta_id" value="<?=(int)$detalle['id']?>">
   <input type="hidden" name="producto_id" value="<?=(int)$historico['producto_id']?>">
   <div class="fields">
    <div><label>Origen físico comprobado</label><select name="origen_historico" required>
      <option value="">Selecciona el origen comprobado</option>
      <?php if($esSucursalConAlmacen):?>
      <option value="FARMACIA">Farmacia — descontado físicamente desde Farmacia</option>
      <option value="ALMACEN">Almacén — requiere conciliación física para devolver</option>
      <?php else:?>
      <option value="FARMACIA_GENERAL">Inventario único de Farmacia</option>
      <?php endif;?>
    </select></div>
    <div><label>Escribe exactamente el folio para autorizar</label><input name="confirmacion_folio" required placeholder="<?=rev_html($detalle['folio'])?>" autocomplete="off"></div>
   </div>
   <label>Documento o evidencia de verificación (obligatorio)</label>
   <textarea name="evidencia" required minlength="15" maxlength="500" placeholder="Indica cómo se comprobó: nombre del responsable, ticket, comprobante físico, fecha o referencia verificable."></textarea>
   <label class="check"><input type="checkbox" name="verificacion_documental" value="1" required> Verifiqué personalmente el origen con evidencia. Entiendo que este registro es irreversible desde esta pantalla y no altera existencias.</label>
   <button type="submit">Registrar origen verificado</button>
  </form>
 </div>
 <?php endforeach;?>
</div>
<?php elseif($verificacionesPorProducto):?>
<div class="alert good" style="margin-top:20px"><strong>Origen histórico documentado.</strong> Se verificó sin modificar existencias. Para una devolución, verifica físicamente que el retorno no haya sido contado o ajustado antes. Los productos identificados como ALMACÉN no se acreditan automáticamente.</div>
<?php endif;?>
<?php if($detalle['reversiones']):?><h3 style="margin-top:23px">Historial de reversas e incidencias</h3><div class="overflow"><table><thead><tr><th>Fecha</th><th>Tipo</th><th>Estado</th><th>Piezas</th><th>Importe</th><th>Usuario</th><th>Motivo</th><th>Comprobante</th></tr></thead><tbody><?php foreach($detalle['reversiones'] as $r):?><tr><td><?=rev_html($r['creado_en'])?></td><td><?=rev_html($r['tipo'])?></td><td><?=rev_html($r['estado'])?></td><td><?=rev_html($r['piezas'])?></td><td>$<?=rev_html($r['importe'])?></td><td><?=rev_html($r['autorizado_por'])?></td><td><?=rev_html($r['motivo'])?></td><td><?php if($r['estado']==='APLICADA'):?><a class="btn alt" href="erp_pos_reversion_ticket.php?id=<?=(int)$r['id']?>" target="_blank" rel="noopener">Imprimir</a><?php else:?>Pendiente<?php endif;?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
<?php if($detalle['estado']==='PAGADA'):?>
<div class="grid"><section class="card"><h2>Cancelar venta completa</h2><p class="muted">Solo si el turno original sigue abierto, se regresó físicamente toda la mercancía (o Almacén nunca la entregó), y se realizó el reembolso. Reversión atómica de existencias y tickets sin surtir.</p>
<?php if(($detalle['turno_estado']??'')!=='ABIERTO'):?><p class="alert">El turno original está <strong>CERRADO</strong>. La cancelación total automática no está disponible; registra una incidencia para conciliación de caja, reembolso y movimientos físicos.</p><?php endif;?>
<form method="post" onsubmit="return confirm('¿Confirmas que ya verificaste físicamente TODOS los productos y realmente se hizo el reembolso? Esta operación cambia existencias y cancela la venta.');">
<input type="hidden" name="csrf" value="<?=rev_html($csrf)?>"><input type="hidden" name="venta_id" value="<?=(int)$detalle['id']?>"><input type="hidden" name="operacion_clave" value="<?=rev_html(sprintf('%s-%s-%s-%s-%s',bin2hex(random_bytes(4)),bin2hex(random_bytes(2)),bin2hex(random_bytes(2)),bin2hex(random_bytes(2)),bin2hex(random_bytes(6))))?>"><input type="hidden" name="tipo" value="CANCELACION_TOTAL"><input type="hidden" name="accion" value="procesar">
<?php require __DIR__.'/../app/views/layouts/erp_reversion_campos.php'; ?>
<?php if($verificacionesPorProducto):?><label class="check"><input type="checkbox" name="historico_fuera_de_conteo" value="1" required> Para esta venta antigua comprobé que ninguna pieza devuelta fue incluida ya en el conteo físico actual ni acreditada anteriormente.</label><?php endif;?>
<button class="red" style="margin-top:9px" <?=($detalle['turno_estado']??'')!=='ABIERTO'?'disabled':''?>>Confirmar cancelación completa</button></form></section>
<section class="card"><h2>Devolución parcial de Farmacia</h2><p class="muted">Admite piezas o cajas completas de partidas cuyo origen es Farmacia. Las ventas históricas requieren verificar el origen antes. La devolución aumenta el POS general y, cuando corresponde, el conteo físico de Farmacia; no es para productos dañados ni mercancía surtida desde Almacén.</p>
<form method="post" onsubmit="return confirm('¿La mercancía fue entregada y revisada como APTA, y el reembolso ya fue realizado?');">
<input type="hidden" name="csrf" value="<?=rev_html($csrf)?>"><input type="hidden" name="venta_id" value="<?=(int)$detalle['id']?>"><input type="hidden" name="operacion_clave" value="<?=rev_html(sprintf('%s-%s-%s-%s-%s',bin2hex(random_bytes(4)),bin2hex(random_bytes(2)),bin2hex(random_bytes(2)),bin2hex(random_bytes(2)),bin2hex(random_bytes(6))))?>"><input type="hidden" name="tipo" value="DEVOLUCION_PARCIAL"><input type="hidden" name="accion" value="procesar">
<?php if(!$devolvibles):?><p class="alert">No hay partidas de Farmacia habilitadas para devolución automática. <?php if($pendientesOrigen):?>Primero verifica el origen físico en la sección superior; luego recarga la venta.<?php else:?>Si el origen es Almacén, utiliza el registro de incidencia y una revisión física.<?php endif;?></p><?php endif;?>
<label for="detalle_id">Partida del ticket</label><select id="detalle_id" name="detalle_id" required><option value="">Selecciona una partida</option><?php foreach($detalle['detalle'] as $d):if(!in_array($d['origen']??'',['FARMACIA','FARMACIA_GENERAL'],true))continue; ?><option value="<?=(int)$d['id']?>">#<?=(int)$d['id']?> · <?=rev_html($d['descripcion_snapshot'])?> · <?=rev_html($d['cantidad'].' '.$d['presentacion'])?></option><?php endforeach;?></select>
<label for="cantidad">Cantidad de presentaciones a devolver (caja = caja completa)</label><input id="cantidad" name="cantidad" type="number" min="1" step="1" required placeholder="1">
<?php require __DIR__.'/../app/views/layouts/erp_reversion_campos.php'; ?>
<?php if($verificacionesPorProducto):?><label class="check"><input type="checkbox" name="historico_fuera_de_conteo" value="1" required> Confirmo que las piezas históricas devueltas NO se han incorporado previamente al conteo físico o mediante otro ajuste.</label><?php endif;?>
<button style="margin-top:9px" <?=!$devolvibles?'disabled':''?>>Confirmar devolución parcial</button></form></section></div>
<?php endif;?>
<section class="card"><h2>Registrar incidencia sin modificar existencias</h2><p class="muted">Para ventas históricas, turnos cerrados, mercancía dañada, devoluciones de Almacén ya surtidas o aclaraciones pendientes. Después se resuelve con el procedimiento físico y contable correspondiente.</p>
<form method="post"><input type="hidden" name="csrf" value="<?=rev_html($csrf)?>"><input type="hidden" name="venta_id" value="<?=(int)$detalle['id']?>"><input type="hidden" name="operacion_clave" value="<?=rev_html(sprintf('%s-%s-%s-%s-%s',bin2hex(random_bytes(4)),bin2hex(random_bytes(2)),bin2hex(random_bytes(2)),bin2hex(random_bytes(2)),bin2hex(random_bytes(6))))?>"><input type="hidden" name="tipo" value="INCIDENCIA"><input type="hidden" name="accion" value="procesar"><label>Descripción de incidencia</label><textarea name="motivo" required minlength="8" maxlength="500" placeholder="Ej. Almacén surtió parcialmente; faltan 12 piezas y se debe resolver con supervisor..."></textarea><button>Registrar para revisión</button></form></section>
<?php endif;?>
<section class="card"><h2>Solicitudes POS a Almacén todavía SIN COBRAR</h2><p class="muted">Una solicitud no cobrada puede cancelarse sin tocar la existencia POS si Almacén no ha registrado ninguna salida. Si hay surtido parcial, se debe revisar físicamente.</p>
<div class="overflow"><table><thead><tr><th>Solicitud</th><th>Producto</th><th>Cajero</th><th>Cantidad</th><th>Surtidas</th><th>Estado</th><th>Cancelar reserva</th></tr></thead><tbody>
<?php foreach($tickets as $t):?><tr><td><?=rev_html($t['folio'])?><br><span class="small">Ticket #<?=(int)$t['id']?></span></td><td><?=rev_html($t['descripcion'])?></td><td><?=rev_html($t['cajero'])?></td><td><?=rev_html($t['cantidad'])?></td><td><?=rev_html($t['cantidad_surtida'])?></td><td><?=rev_html($t['estado'])?></td><td><?php if((float)$t['cantidad_surtida']==0 && $t['salida_id']===null):?><form method="post" onsubmit="return confirm('¿Cancelar esta reserva no cobrada?');"><input type="hidden" name="csrf" value="<?=rev_html($csrf)?>"><input type="hidden" name="accion" value="cancelar_reserva"><input type="hidden" name="ticket_id" value="<?=(int)$t['id']?>"><input name="motivo" required minlength="8" maxlength="500" placeholder="Motivo" aria-label="Motivo para <?=rev_html($t['folio'])?>"><button class="red" style="margin-top:5px">Cancelar</button></form><?php else:?><span class="muted">Requiere revisión física</span><?php endif;?></td></tr><?php endforeach;?>
<?php if(!$tickets):?><tr><td colspan="7">No existen solicitudes POS sin cobrar en este momento.</td></tr><?php endif;?></tbody></table></div></section>
</main>
<?php if($exito || $error): ?>
<dialog id="gyd-reversion-feedback" aria-labelledby="gyd-reversion-feedback-titulo" style="border:0;border-radius:16px;padding:24px;max-width:min(92vw,480px);box-shadow:0 18px 60px #11233c44;color:#173755">
 <h2 id="gyd-reversion-feedback-titulo"><?= $error?'No se registró la operación':'Operación registrada' ?></h2>
 <p><?=rev_html($error?:$exito)?></p><form method="dialog"><button type="submit">Entendido</button></form>
</dialog>
<script>document.addEventListener('DOMContentLoaded',()=>{const d=document.getElementById('gyd-reversion-feedback');if(d&&typeof d.showModal==='function')d.showModal();});</script>
<?php endif; ?>

<script src="assets/js/gyd_excel_reportes.js" defer></script>

<?php if (!defined('GYD_HIDE_GLOBAL_NAV')): ?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=1">
<script>window.GYD_NAV_HOME='index.php';window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=1"></script>
<?php endif; ?>
</body></html>
