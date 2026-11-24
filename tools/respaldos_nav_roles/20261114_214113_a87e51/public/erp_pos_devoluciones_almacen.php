<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
requireLogin();
$usr=currentUser()??[];
$rol=strtoupper(trim((string)($usr['rol_original']??$usr['rol']??'')));
if(!in_array($rol,['ADMINISTRADOR','ADMIN','DIRECCION'],true)){http_response_code(403);exit('Acceso exclusivo de ADMINISTRADOR y DIRECCIÓN.');}
require_once __DIR__.'/../app/models/PosDevolucionesAlmacen.php';
function devh(mixed $n):string{return htmlspecialchars((string)$n,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
if(empty($_SESSION['erp_pos_dev_alm_csrf']))$_SESSION['erp_pos_dev_alm_csrf']=bin2hex(random_bytes(32));
$csrf=(string)$_SESSION['erp_pos_dev_alm_csrf'];
$ventaId=(int)($_GET['venta']??0);$error='';$ok=(string)($_SESSION['erp_pos_dev_alm_ok']??'');unset($_SESSION['erp_pos_dev_alm_ok']);
$lista=[];$detalles=[];$venta=null;$mod=null;
try{
    $mod=new PosDevolucionesAlmacen();
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!hash_equals($csrf,(string)($_POST['csrf']??'')))throw new DomainException('La pantalla venció. Actualiza antes de continuar.');
        $accion=(string)($_POST['accion']??'');
        if($accion==='solicitar')$texto=$mod->solicitar($usr,$_POST);
        elseif($accion==='aplicar')$texto=$mod->aplicar($usr,$_POST);
        elseif($accion==='revision')$texto=$mod->revisar($usr,$_POST);
        else throw new DomainException('Acción inválida.');
        $_SESSION['erp_pos_dev_alm_ok']=$texto;
        $next=(int)($_POST['venta_id']??0);
        header('Location: erp_pos_devoluciones_almacen.php'.($next>0?'?venta='.$next:''),true,303);exit;
    }
    $lista=$mod->pendientes();
    if($ventaId>0){
        require_once __DIR__.'/../app/models/PosReversiones.php';
        $vmod=new PosReversiones();$venta=$vmod->venta($ventaId);
        $detalles=$mod->opciones($ventaId);
    }
}catch(DomainException $e){$error=$e->getMessage();}
catch(Throwable $e){error_log('Devoluciones almacen POS: '.$e->getMessage());$error='No se pudo completar la operación. Revisa la migración SQL y el registro de PHP.';}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ERP G&D | Devoluciones desde Almacén</title><style>
*{box-sizing:border-box}body{margin:0;background:#eef3fa;color:#193e63;font:15px system-ui,Segoe UI,Arial,sans-serif}
header{background:#15446f;color:white;padding:24px max(20px,calc((100% - 1120px)/2));border-bottom:4px solid #ca3855}header h1{font-size:24px;margin:0 0 7px}
main{max-width:1160px;margin:20px auto;padding:0 14px}.card{background:#fff;border-radius:14px;box-shadow:0 6px 22px #163c6511;padding:20px;margin-bottom:17px}
h2{font-size:19px;margin:0 0 12px}.muted{color:#617d98;font-size:13px;line-height:1.5}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(245px,1fr));gap:13px}.nav{display:flex;gap:8px;flex-wrap:wrap}
a.btn,button{border:0;display:inline-block;text-decoration:none;border-radius:9px;padding:10px 15px;background:#165eab;color:#fff;font:600 14px system-ui;cursor:pointer}a.btn.alt{background:#e5eef8;color:#15446f}button.red{background:#ac2f49}
label{display:block;font-weight:700;font-size:13px;margin:12px 0 5px}input,select,textarea{padding:11px;border-radius:8px;border:1px solid #bfcee0;width:100%;font:inherit}textarea{resize:vertical;min-height:75px}
input[type=checkbox]{width:auto}.checks{display:flex;gap:8px;align-items:start;margin:12px 0}.checks label{margin:0;font-weight:500}.alert{border-radius:9px;padding:13px;background:#fff3d9;border:1px solid #edd19b}.ok{background:#e4f5ec;border-color:#a4d6bb}.err{background:#fee8ed;border-color:#ed9ab0}.scroll{overflow:auto}table{width:100%;border-collapse:collapse;min-width:920px}td,th{padding:10px;text-align:left;border-bottom:1px solid #e0eaf3;font-size:13px;vertical-align:top}th{background:#1a4c7d;color:white}form{margin:0}.mini{font-size:12px}.tag{display:inline-block;background:#eaf2fa;border-radius:18px;padding:5px 9px;font-size:11px;font-weight:700}
</style></head><body>
<header><h1>ERP G&amp;D · Devoluciones desde Almacén</h1><div>Devolución física verificada · Reembolso documentado · Stock POS general y ubicación de Almacén</div></header>
<main>
<div class="nav" style="margin-bottom:16px"><a class="btn alt" href="erp_pos_reversiones.php<?= $ventaId>0?'?venta='.$ventaId:'' ?>">← Cancelaciones y devoluciones</a><a class="btn alt" href="erp_conciliacion_inventarios.php">Conciliación</a><a class="btn alt" href="dashboard.php">Inicio</a></div>
<section class="card alert"><strong>Reglas de seguridad:</strong> solo para ventas PAGADAS desde Almacén con ticket y salida física confirmados. La solicitud NO mueve stock. Al aplicar, Dirección debe haber verificado el retorno APTA para venta, la ubicación física y el reembolso YA realizado. Se registra una entrada de Almacén y se restaura el POS general una sola vez; NO se altera la salida original. Si hay daños, ticket parcial, origen histórico sin ticket o doble conteo, envía a revisión. No se ejecuta ninguna devolución bancaria desde esta pantalla.</section>
<?php if($ok):?><section class="card alert ok" role="status"><?=devh($ok)?></section><?php endif;?>
<?php if($error):?><section class="card alert err" role="alert"><?=devh($error)?></section><?php endif;?>
<section class="card"><h2>Solicitar devolución de una venta surtida por Almacén</h2>
<form method="get"><label>Identificador de venta (el número que aparece en la URL al abrir una venta)</label><div class="grid"><input name="venta" type="number" min="1" value="<?=$ventaId>0?$ventaId:''?>" placeholder="Ej. 36" required><button>Consultar venta</button></div></form>
<?php if($venta):?><p><strong><?=devh($venta['folio'])?></strong> · <?=devh($venta['sucursal'])?> · <?=devh($venta['estado'])?></p>
<div class="scroll"><table><thead><tr><th>Partida</th><th>Producto</th><th>Vendido</th><th>Origen</th><th>Pendiente disponible para devolver</th><th>Solicitar</th></tr></thead><tbody>
<?php foreach($detalles as $d):$pend=(int)$d['cantidad']-(int)$d['reservado']-(int)$d['devuelto'];?><tr>
<td>#<?=(int)$d['id']?></td><td><?=devh($d['descripcion_snapshot'])?></td><td><?=devh($d['cantidad'])?> <?=devh($d['presentacion'])?><div class="mini">Factor: <?=devh($d['piezas_por_presentacion'])?> piezas</div></td>
<td><?=devh($d['origen']??'SIN ORIGEN')?><?php if($d['almacen_ticket_id']):?><div class="mini">Ticket ID <?=devh($d['almacen_ticket_id'])?></div><?php endif;?></td>
<td><?=max(0,$pend)?> presentaciones</td>
<td><?php if($venta['estado']==='PAGADA'&&$d['origen']==='ALMACEN'&&$d['almacen_ticket_id']&&$pend>0):?>
<form method="post" onsubmit="return confirm('¿Crear solicitud de devolución para esta partida? Aún NO se mueve inventario.');">
<input type="hidden" name="csrf" value="<?=devh($csrf)?>"><input type="hidden" name="accion" value="solicitar"><input type="hidden" name="venta_id" value="<?=$ventaId?>"><input type="hidden" name="detalle_id" value="<?=(int)$d['id']?>"><input type="hidden" name="clave" value="<?=bin2hex(random_bytes(16))?>">
<label>Cantidad de presentaciones</label><input type="number" name="cantidad" min="1" max="<?=max(0,$pend)?>" required value="1">
<label>Motivo</label><textarea name="motivo" required minlength="8" maxlength="500" placeholder="Explica qué devolvió el cliente"></textarea>
<button>Solicitar devolución</button></form>
<?php else:?><span class="muted">No disponible. Revisar ticket u origen.</span><?php endif;?></td></tr><?php endforeach;?></tbody></table></div>
<?php elseif($ventaId>0):?><p class="alert">No se encontró esa venta. Verifica el número.</p><?php endif;?></section>
<section class="card"><h2>Solicitudes e historial</h2><p class="muted">Solo ADMINISTRADOR o DIRECCIÓN pueden confirmar y aplicar retornos a inventario. Un caso que requiere revisión no aumenta existencias.</p>
<?php if(!$lista):?><p>No existen solicitudes todavía.</p><?php else:?>
<?php foreach($lista as $x):?><div class="card" style="border:1px solid #e0e9f4;box-shadow:none;padding:15px">
<div class="grid"><div><strong>#<?=(int)$x['id']?> · <?=devh($x['venta_folio'])?></strong><div class="muted"><?=devh($x['descripcion'])?> · <?=devh($x['solicitante'])?></div></div><div><span class="tag"><?=devh($x['estado'])?></span><div class="muted"><?=devh($x['piezas'])?> piezas · $<?=devh($x['importe'])?></div></div><div class="muted">Solicitada: <?=devh($x['solicitado_en'])?><?php if($x['aprobado_en']):?><div>Atendida: <?=devh($x['aprobado_en'])?></div><?php endif;?></div></div>
<?php if($x['estado']==='SOLICITADA'):?>
<div class="grid" style="margin-top:12px">
<form method="post" onsubmit="return confirm('¿Confirmas que YA recibiste mercancía apta, YA realizaste el reembolso y no la has incorporado antes al inventario? Esta acción MODIFICA existencias.');">
<input type="hidden" name="csrf" value="<?=devh($csrf)?>"><input type="hidden" name="accion" value="aplicar"><input type="hidden" name="devolucion_id" value="<?=(int)$x['id']?>"><input type="hidden" name="venta_id" value="<?=(int)$x['venta_id']?>">
<label>Ubicación física donde reingresaron las piezas</label><select name="ubicacion_id" required><option value="">Selecciona una ubicación real</option>
<?php foreach(($mod?$mod->ubicaciones((int)$x['producto_id'],(int)$x['sucursal_id']):[]) as $loc):?><option value="<?=(int)$loc['id']?>"><?=devh($loc['sucursal'].' · '.$loc['ubicacion'].' · Actual: '.$loc['existencia'])?></option><?php endforeach;?></select>
<label>Nombre de quien recibió físicamente en Almacén</label><input name="recibido_por" required minlength="3" maxlength="150">
<label>Evidencia de recepción (folio, fecha, lote, condición)</label><textarea name="evidencia" required minlength="12" maxlength="500"></textarea>
<label>Medio del reembolso YA realizado</label><select name="medio" required><option value="">Selecciona...</option><option value="EFECTIVO">Efectivo</option><option value="TARJETA">Tarjeta</option><option value="TRANSFERENCIA">Transferencia</option><option value="OTRO">Otro</option></select>
<label>Referencia (obligatoria excepto efectivo)</label><input name="referencia" maxlength="120" placeholder="Comprobante bancario o referencia">
<div class="checks"><input type="checkbox" name="mercancia_apta" value="1" id="apta-<?=$x['id']?>" required><label for="apta-<?=$x['id']?>">Mercancía revisada y APTA para volver a venderse.</label></div>
<div class="checks"><input type="checkbox" name="devolucion_fisica" value="1" id="fis-<?=$x['id']?>" required><label for="fis-<?=$x['id']?>">Confirmo que las piezas regresaron físicamente al Almacén indicado.</label></div>
<div class="checks"><input type="checkbox" name="no_contabilizada" value="1" id="cont-<?=$x['id']?>" required><label for="cont-<?=$x['id']?>">No fueron incorporadas previamente mediante conteo, entrada u otro ajuste.</label></div>
<div class="checks"><input type="checkbox" name="reembolso_realizado" value="1" id="rem-<?=$x['id']?>" required><label for="rem-<?=$x['id']?>">El reembolso YA se realizó y su referencia es comprobable.</label></div>
<button>Confirmar devolución y aplicar</button>
</form>
<form method="post" onsubmit="return confirm('¿Enviar esta solicitud a revisión sin alterar existencias ni caja?');">
<input type="hidden" name="csrf" value="<?=devh($csrf)?>"><input type="hidden" name="accion" value="revision"><input type="hidden" name="devolucion_id" value="<?=(int)$x['id']?>"><input type="hidden" name="venta_id" value="<?=(int)$x['venta_id']?>">
<h3>Mercancía dañada, origen incierto o discrepancia</h3><p class="muted">Bloquea la aplicación automática. No regresará mercancía al inventario vendible.</p>
<label>Motivo de revisión</label><textarea name="motivo_revision" required minlength="10" maxlength="500" placeholder="Indica el problema, comprobantes y próximos pasos"></textarea><button class="red">Marcar requiere revisión</button>
</form></div>
<?php else:?><p class="muted"><?php if($x['estado']==='APLICADA'):?>Movimiento entrada: <?=devh($x['movimiento_id'])?> · Recibió <?=devh($x['recibido_por'])?> · Reembolso <?=devh($x['medio_reembolso'])?> · Referencia <?=devh($x['referencia_reembolso'])?><?php else:?><?=devh($x['observaciones_revision'])?><?php endif;?></p><?php endif;?>
</div><?php endforeach;?><?php endif;?></section>
</main>
<script src="assets/js/gyd_excel_reportes.js" defer></script>

<?php if (!defined('GYD_HIDE_GLOBAL_NAV')): ?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=4">
<script>window.GYD_NAV_HOME='index.php';window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=4"></script>
<?php endif; ?>
</body></html>
