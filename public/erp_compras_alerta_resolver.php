<?php
declare(strict_types=1);
/** Navegador de incidencias de Compras. Ninguna operación escribe precios, facturas o existencias. */
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_compras_alertas_seguimiento.php';
requireLogin();
$user=currentUser()?:[];
if(!gdca_authorized($user)){http_response_code(403);exit('No tienes permisos para gestionar alertas de Compras.');}
header('Cache-Control: private, no-store');
if(empty($_SESSION['gyd_alerta_resolver_csrf']))$_SESSION['gyd_alerta_resolver_csrf']=bin2hex(random_bytes(32));
$token=(string)$_SESSION['gyd_alerta_resolver_csrf'];
$id=filter_var($_GET['caso']??$_POST['caso']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
if(!$id){http_response_code(400);exit('Selecciona una alerta válida desde Notificaciones.');}
$error='';$flash='';$caso=null;$historial=[];$puedePrecio=false;$precioActual=null;$costoActual=null;
$admin=gdca_role($user)!=='COMPRAS';
try{
 $db=(new Database())->connect();
 if(!gdca_tables_ok($db))throw new RuntimeException('Instala primero el Centro de Seguimiento de Alertas de Compras.');
 // GYD_ALERTAS_FIX_V2_FORZAR_COMPROBACION: permite revisar inmediatamente sin esperar sincronización.
 if(isset($_GET['comprobar'])) gdca_sync($db,true);
 // Respetar asignación: Compras no entra por URL a casos asignados a otros usuarios.
 $sql='SELECT c.* FROM erp_compras_alerta_casos c WHERE c.id=?';
 if(!$admin)$sql.=' AND (c.asignado_a IS NULL OR c.asignado_a=?)';
 $st=$db->prepare($sql);$args=[$id];if(!$admin)$args[]=(int)$user['id'];$st->execute($args);
 $caso=$st->fetch(PDO::FETCH_ASSOC)?:null;
 if(!$caso){http_response_code(404);throw new InvalidArgumentException('Esta incidencia no existe o está asignada a otro usuario.');}
 if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    if(!hash_equals($token,(string)($_POST['csrf']??'')))throw new InvalidArgumentException('La sesión caducó. Actualiza la página.');
    $accion=(string)($_POST['accion']??'');
    $nuevo=match($accion){'descartar'=>'DESCARTADA','atendida'=>'ATENDIDA','revision'=>'EN_REVISION',default=>''};
    if($nuevo==='')throw new InvalidArgumentException('Acción no permitida.');
    $nota=trim((string)($_POST['motivo']??''));
    // GYD_ALERTAS_FIX_V2_NOTA_SERVIDOR: la explicación es opcional.
    if(strlen($nota)>1800)throw new InvalidArgumentException('La observación supera 1800 caracteres.');
    // GYD_AUTO_ALERTAS_ENVIAR_A_MODULO
    // En alertas medibles no se permite un cierre manual sin que la detección haya desaparecido.
    if($nuevo==='ATENDIDA' && gdca_auto_tipo_verificable((string)$caso['tipo'])){
        gdca_sync($db,true);
        $q=$db->prepare('SELECT estado FROM erp_compras_alerta_casos WHERE id=? LIMIT 1');$q->execute([$id]);
        if($q->fetchColumn()!=='ATENDIDA')throw new InvalidArgumentException('La condición todavía no está corregida. Usa En revisión para abrir el módulo correspondiente, o descártala si no procede.');
        header('Location: erp_compras_alerta_resolver.php?caso='.$id.'&guardado=1',true,303);exit;
    }
    // Reutilizar el sistema de estados y bitácora, preservando el responsable actual.
    // GYD_ALERTAS_FIX_V2_NO_DUPLICAR_REVISION: abrir otra vez sin crear un movimiento EN_REVISION -> EN_REVISION.
    if($nuevo==='EN_REVISION' && (string)$caso['estado']==='EN_REVISION' && $nota===''){
        header('Location: '.gdca_auto_ruta_caso($db,$caso),true,303);exit;
    }
    gdca_edit_case($db,$user,$id,$nuevo,(int)($caso['asignado_a']??0),$nota);
    // GYD_AUTO_ALERTAS_REVISION_RUTA
    if($nuevo==='EN_REVISION'){
        $destino=gdca_auto_ruta_caso($db,$caso);
        header('Location: '.$destino,true,303);exit;
    }
    header('Location: erp_compras_alerta_resolver.php?caso='.$id.'&guardado=1',true,303);exit;
 }
 if(isset($_GET['guardado']))$flash='Se guardó la decisión y quedó registrada en el historial. No se modificaron precios ni inventarios.';
 $prod=$db->prepare('SELECT costo_ultimo,costo_promedio,precio_venta FROM productos WHERE id=? LIMIT 1');
 $prod->execute([(int)$caso['producto_id']]);$producto=$prod->fetch(PDO::FETCH_ASSOC)?:[];
 $costoActual=isset($producto['costo_ultimo'])?(float)$producto['costo_ultimo']:null;
 $precioActual=isset($producto['precio_venta'])?(float)$producto['precio_venta']:null;
 if((int)$caso['sucursal_id']>0 && gd_cp_table($db,'pos_precios')){
     $pv=$db->prepare('SELECT precio_publico FROM pos_precios WHERE producto_id=? AND sucursal_id=? LIMIT 1');
     $pv->execute([(int)$caso['producto_id'],(int)$caso['sucursal_id']]);
     $v=$pv->fetchColumn();$precioActual=$v===false?null:(float)$v;
 }
 // El editor existente sólo permite modificar partidas efectivamente aceptadas en facturas.
 if((int)$caso['factura_id']>0 && gd_cp_table($db,'erp_facturas_revision_detalle')){
    $st=$db->prepare("SELECT COUNT(*) FROM erp_facturas_detalle d JOIN erp_facturas_revision_detalle r ON r.factura_detalle_id=d.id WHERE d.factura_id=? AND d.producto_id=? AND r.tipo='ACEPTADA' AND r.cantidad>0");
    $st->execute([(int)$caso['factura_id'],(int)$caso['producto_id']]);$puedePrecio=(int)$st->fetchColumn()>0;
 }
 // GYD_ALERTAS_FIX_V2_ESTADO: muestra margen comprobable, no el costo de catálogo usado como referencia histórica.
 $estadoActual=gdca_auto_tipo_verificable((string)$caso['tipo'])?gdca_auto_contexto($db,$caso):null;
 $st=$db->prepare('SELECT usuario_nombre,accion,estado_anterior,estado_nuevo,observacion,creado_en FROM erp_compras_alerta_historial WHERE caso_id=? ORDER BY id DESC LIMIT 15');
 $st->execute([$id]);$historial=$st->fetchAll(PDO::FETCH_ASSOC);
}catch(Throwable $e){error_log('G&D resolver alerta: '.$e->getMessage());$error=($e instanceof InvalidArgumentException||$e instanceof RuntimeException)?$e->getMessage():'No fue posible consultar la incidencia. Revisa la base de datos.';}
$h=static fn(mixed $x):string=>htmlspecialchars((string)($x??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$money=static fn(mixed $v):string=>$v===null?'Sin configurar':'$'.number_format((float)$v,2,'.',',');
$precioUrl='erp_cambio_precios.php?'.http_build_query(['factura_id'=>(int)($caso['factura_id']??0),'producto_id'=>(int)($caso['producto_id']??0)]);
$finUrl='erp_catalogo_finanzas.php?'.http_build_query(['producto_id'=>(int)($caso['producto_id']??0),'sucursal'=>(int)($caso['sucursal_id']??0)]);
$catUrl='erp_catalogo_maestro.php?'.http_build_query(['q'=>(string)($caso['codigo']??'')]);
$histUrl='erp_catalogo_historial_costos.php?producto_id='.(int)($caso['producto_id']??0);
$compUrl='erp_catalogo_comparar_proveedores.php?producto_id='.(int)($caso['producto_id']??0);
$base='erp_compras_alertas_seguimiento.php?caso='.$id;
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>G&D | Resolver alerta de Compras</title>
<style>
:root{font-family:system-ui,'Segoe UI',Arial,sans-serif;color:#173c5b;background:#eef3f9}*{box-sizing:border-box}body{margin:0}header{background:#153f6b;color:#fff;padding:20px max(20px,calc((100% - 1100px)/2))}header h1{margin:0 0 6px;font-size:23px}header a{color:white}main{max-width:1100px;margin:auto;padding:18px}.card{padding:19px;background:white;border:1px solid #dce6ef;border-radius:13px;margin-bottom:15px}h2{font-size:19px;margin:0 0 12px}.muted{color:#60788d;font-size:13px}.values{display:grid;grid-template-columns:repeat(auto-fit,minmax(165px,1fr));gap:10px}.value{background:#f1f5fa;padding:14px;border-radius:9px}.value strong{font-size:17px;display:block;margin-top:5px}.actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:13px}.btn,button{background:#195f9c;border:0;border-radius:8px;color:white;padding:11px 14px;text-decoration:none;font:600 13px system-ui;cursor:pointer}.secondary{background:#e8f0f9;color:#1b507c}.warning{background:#fff6df;border:1px solid #edcc76;color:#784f0a;padding:12px;border-radius:9px}.ok{background:#e5f7ed;color:#216444;padding:13px;border-radius:9px}.error{background:#ffe9e9;color:#842020;padding:13px;border-radius:9px}.decision{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px}.decision form{padding:12px;border:1px solid #dae5ee;border-radius:9px}.decision h3{font-size:16px;margin:0 0 5px}textarea{width:100%;min-height:90px;padding:11px;margin:10px 0;border:1px solid #b9cddf;border-radius:8px;font:inherit}.discard{background:#b15422}.hist{border-top:1px solid #e1eaf2;padding:10px 0;font-size:13px}a:focus-visible,button:focus-visible,textarea:focus-visible{outline:3px solid #e3bb35;outline-offset:2px}
</style></head><body>
<header><h1>Revisar, corregir o descartar alerta</h1><a href="erp_notificaciones.php">← Volver a notificaciones</a></header><main>
<?php if($error):?><div class="error"><?=$h($error)?></div><?php endif;?>
<?php if($flash):?><div class="ok"><?=$h($flash)?></div><?php endif;?>
<?php if($caso):?>
<section class="card"><h2><?=$h(gd_ac_type_name((string)$caso['tipo']))?> · <?=$h($caso['codigo'])?></h2><p><?=$h($caso['descripcion'])?></p>
<div class="values"><div class="value">Sucursal<strong><?=$h($caso['sucursal_nombre'])?></strong></div><div class="value">Proveedor<strong><?=$h($caso['proveedor'])?></strong></div><div class="value">Costo registrado al detectar<strong><?=$h($money($caso['costo']))?></strong></div><div class="value">Precio registrado al detectar<strong><?=$h($money($caso['precio']))?></strong></div><div class="value">Precio público actual<strong><?=$h($money($precioActual))?></strong></div><div class="value">Último costo actual<strong><?=$h($money($costoActual))?></strong></div><div class="value">Estado del caso<strong><?=$h(str_replace('_',' ',$caso['estado']))?></strong></div><div class="value">Factura<strong><?=$h($caso['factura_numero']?:'Sin factura')?></strong></div></div>
<p class="muted"><?=$h($caso['descripcion_alerta'])?></p>
<?php /* GYD_ALERTAS_FIX_V2_DIAGNOSTICO_VISUAL */ if(!empty($estadoActual) && $estadoActual['factura_id']>0): ?>
<p class="warning">Comprobación actual: costo de última factura reciente <strong><?=$h($money($estadoActual['costo']))?></strong>;
precio público de esta sucursal <strong><?=$h($money($estadoActual['precio']))?></strong>;
margen estimado <strong><?=$estadoActual['margen']===null?'Sin precio':$h(number_format($estadoActual['margen'],2,'.','').'%')?></strong>.
<?php if($caso['tipo']==='MARGEN_BAJO'): ?>La alerta seguirá pendiente mientras el margen sea inferior al 15%.<?php endif; ?>
<?php if($caso['tipo']==='PERDIDA'): ?>La alerta seguirá pendiente mientras el precio sea menor al costo facturado.<?php endif; ?></p>
<?php elseif(!empty($estadoActual)): ?><p class="warning"><?=$h($estadoActual['motivo']?:'No hay datos suficientes para verificar automáticamente esta alerta.')?></p><?php endif; ?>
<a class="btn secondary" href="erp_compras_alerta_resolver.php?caso=<?=$id?>&amp;comprobar=1">Comprobar de nuevo</a>
<?php /* GYD_MARGEN_V3_DETALLE */ if($caso['tipo']==='MARGEN_BAJO' && !empty($estadoActual) && $estadoActual['costo']!==null):
    $cm=(float)$estadoActual['costo'];$minp=ceil(($cm/.85)*100-1e-8)/100;
    for($j=0;$j<4 && $minp>0 && 100*($minp-$cm)/$minp<15-1e-7;$j++)$minp+=.01;
?>
<p class="warning"><strong>Para quitar el aviso de margen bajo en <?=$h($caso['sucursal_nombre']?:'precio base')?>:</strong>
con el último costo facturado elegible <?=$h($money($cm))?> se requiere un precio público de al menos <strong><?=$h($money($minp))?></strong>
(margen real del 15% sobre venta). El precio sugerido de «MARK UP» en Cambio de Precios es una GANANCIA SOBRE COSTO, no el margen real sobre venta.
<?php if($estadoActual['margen']!==null && $estadoActual['margen']>=15):?>
<strong>El margen actual ya cumple:</strong> pulsa «Comprobar de nuevo» para sincronizar el estado. Si continúa pendiente, ejecuta el diagnóstico por terminal.
<?php endif; ?></p>
<?php endif; ?>
<p class="warning">Cada sucursal tiene su propia alerta. Corregir un precio en Tapachula no descarta automáticamente la alerta de Toscana, Hidalgo o Tuxtla. Los importes históricos no son una cotización vigente.</p></section>
<section class="card"><h2>Ir al módulo adecuado</h2><p class="muted">Usa primero los análisis para confirmar si el precio o costo está mal. La corrección de precios siempre se confirma dentro del módulo autorizado; esta pantalla no cambia precios.</p>
<div class="actions">
<a class="btn" href="<?=$h($finUrl)?>">Revisar costo, precio y margen</a>
<a class="btn secondary" href="<?=$h($histUrl)?>">Historial de costos y facturas</a>
<a class="btn secondary" href="<?=$h($compUrl)?>">Comparar proveedores</a>
<a class="btn secondary" href="<?=$h($catUrl)?>">Ficha del Catálogo Maestro</a>
<?php if((int)$caso['factura_id']>0):?><a class="btn secondary" href="erp_facturas_verificacion.php?id=<?=(int)$caso['factura_id']?>">Revisar factura y verificación</a><?php endif;?>
<?php if($puedePrecio):?><a class="btn" href="<?=$h($precioUrl)?>">Modificar precio del producto (factura aceptada)</a><?php endif;?>
<a class="btn secondary" href="<?=$h($base)?>">Responsable e historial completo</a>
</div>
<?php if(!$puedePrecio):?><p class="warning" style="margin-top:15px">El cambio de precios ligado a factura no está disponible para este caso porque no se encontró una partida aceptada de este producto. Revisa su factura y verificación; no se permitirá saltar ese control. La edición descriptiva del Catálogo Maestro sigue reservada a Administración.</p><?php endif;?></section>
<section class="card"><h2>Decisión sobre esta notificación</h2><p class="muted">Descartar no elimina el producto ni modifica el inventario. Las alertas medibles se marcan Atendidas automáticamente cuando el cambio quede comprobado; las demás requieren una decisión documentada. Las observaciones son opcionales; los cambios de estado quedan en el historial de auditoría.</p>
<?php if(in_array((string)$caso['estado'],['ATENDIDA','DESCARTADA'],true)):?><div class="ok">Este caso ya está cerrado como <?=$h($caso['estado'])?>. Puedes consultar el historial o reabrirlo desde Seguimiento.</div><?php else:?>
<div class="decision">
<form method="post"><h3>En revisión</h3><p class="muted">Deja constancia de que se está investigando.</p><input type="hidden" name="csrf" value="<?=$h($token)?>"><input type="hidden" name="caso" value="<?=$id?>"><input type="hidden" name="accion" value="revision"><label for="nota_revision" class="muted">Observación</label><textarea id="nota_revision" name="motivo" maxlength="1800"></textarea><button type="submit" class="secondary">Guardar y abrir módulo correspondiente</button></form>
<form method="post"><h3>Atendida</h3><p class="muted">Ya se hizo el ajuste necesario o se verificó la situación.</p><input type="hidden" name="csrf" value="<?=$h($token)?>"><input type="hidden" name="caso" value="<?=$id?>"><input type="hidden" name="accion" value="atendida"><label for="nota_atendida" class="muted">Acción realizada</label><textarea id="nota_atendida" name="motivo" maxlength="1800"></textarea><button type="submit">Marcar atendida</button></form>
<form method="post"><h3>Descartar</h3><p class="muted">No requiere corrección: explica por qué.</p><input type="hidden" name="csrf" value="<?=$h($token)?>"><input type="hidden" name="caso" value="<?=$id?>"><input type="hidden" name="accion" value="descartar"><label for="nota_descartar" class="muted">Motivo de descarte</label><textarea id="nota_descartar" name="motivo" maxlength="1800"></textarea><button type="submit" class="discard" onclick="return confirm('¿Descartar únicamente esta alerta? El motivo quedará registrado.')">Descartar esta alerta</button></form>
</div><?php endif;?></section>
<section class="card"><h2>Últimos movimientos</h2><?php foreach($historial as $m):?><div class="hist"><strong><?=$h($m['accion'])?> · <?=$h($m['usuario_nombre'])?></strong><div class="muted"><?=$h($m['creado_en'])?> · <?=$h($m['estado_anterior'])?> → <?=$h($m['estado_nuevo'])?></div><p><?=$h($m['observacion'])?></p></div><?php endforeach;?><?php if(!$historial):?><p class="muted">Sin decisiones manuales registradas.</p><?php endif;?></section>
<?php endif;?></main>
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
<!-- GYD_UNA_PESTANA_INTERNA -->
<script src="assets/js/gyd_una_pestana.js?v=1"></script>
</body></html>
