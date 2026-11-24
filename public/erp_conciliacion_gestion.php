<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_conciliacion_operativa.php';
requireLogin();
$usuario=currentUser()?:[];$uid=(int)($usuario['id']??0);
$esAdmin=co_admin($usuario);$rol=co_role($usuario);
if(!$esAdmin && !in_array($rol,['ENCARGADO','JEFE_ALMACEN','GERENTE'],true)){http_response_code(403);exit('No autorizado.');}
if($uid<1){http_response_code(403);exit('La sesión no tiene usuario válido.');}
// Un responsable operativo solo puede consultar su propia ubicación/sucursal.
$predeterminado=in_array((int)($usuario['almacen_id']??0),[2,3],true)?2:1;
$seleccionSid=(int)($_POST['sucursal']??$_GET['sucursal']??$predeterminado);
if(!co_admin($usuario) && !co_can_count($usuario,$seleccionSid,$rol==='GERENTE'?'FARMACIA':'ALMACEN')) { http_response_code(403);exit('Solo puedes consultar la sucursal que tienes asignada.'); }
if(empty($_SESSION['co_csrf']))$_SESSION['co_csrf']=bin2hex(random_bytes(32));
$csrf=(string)$_SESSION['co_csrf'];
$sucursal=(int)($_POST['sucursal']??$_GET['sucursal']??$predeterminado);
$producto=(int)($_POST['producto']??$_GET['producto']??0);
if(!co_sid($sucursal))$sucursal=1;
$busca=mb_substr(trim((string)($_GET['q']??'')),0,100);
$error='';$mensaje=(string)($_SESSION['co_flash']??'');unset($_SESSION['co_flash']);
$sucursales=[1=>'Ciudad Hidalgo',2=>'Tuxtla Gutiérrez'];
try {
 $db=(new Database())->connect();
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($csrf,(string)($_POST['csrf']??'')))throw new DomainException('Formulario vencido. Actualiza la página.');
  if(!co_sid($sucursal)||$producto<1||!co_producto($db,$producto))throw new DomainException('Selecciona una sucursal y un producto válidos.');
  $accion=(string)($_POST['accion']??'');
  $nota=trim((string)($_POST['nota']??''));
  if($accion==='conteo'){
   $ubicacion=(string)($_POST['ubicacion']??'');
   $cantidad=co_int($_POST['cantidad']??null);
   if(!in_array($ubicacion,['FARMACIA','ALMACEN'],true)||!co_can_count($usuario,$sucursal,$ubicacion))throw new DomainException('Tu rol no puede capturar ese inventario.');
   if($cantidad===false || mb_strlen($nota)<8||mb_strlen($nota)>500)throw new DomainException('Captura una cantidad válida y una observación de 8 a 500 caracteres.');
   $est=pi_ubicacion($db,$sucursal,$producto);
   $pend=pi_pendientes_entrega($db,$sucursal,$producto);
   $sistema=$ubicacion==='FARMACIA'?$est['farmacia']:$est['almacen'];
   $st=$db->prepare('INSERT INTO erp_conciliacion_conteos(sucursal_id,producto_id,ubicacion,cantidad_contada,cantidad_sistema,pos_general_corte,cobrado_pendiente_corte,observaciones,usuario_id) VALUES (?,?,?,?,?,?,?,?,?)');
   $st->execute([$sucursal,$producto,$ubicacion,$cantidad,$sistema,$est['general'],$pend['vendido_pendiente'],$nota,$uid]);
   $_SESSION['co_flash']='Conteo documentado. No se ha modificado ninguna existencia.';
  }elseif($accion==='solicitar'){
   if(!$esAdmin && !co_can_count($usuario,$sucursal,(string)($_POST['ubicacion']??'')))throw new DomainException('No autorizado a solicitar este ajuste.');
   $tipo=(string)($_POST['destino']??'');$cid=(int)($_POST['conteo_id']??0);
   if(!in_array($tipo,['FARMACIA','ALMACEN','POS_GENERAL'],true)||$cid<1||mb_strlen($nota)<12||mb_strlen($nota)>500)throw new DomainException('Especifica destino y motivo (12 a 500 caracteres).');
   $db->beginTransaction();
   $st=$db->prepare('SELECT * FROM erp_conciliacion_conteos WHERE id=? AND sucursal_id=? AND producto_id=? FOR UPDATE');$st->execute([$cid,$sucursal,$producto]);$c=$st->fetch(PDO::FETCH_ASSOC);
   if(!$c)throw new DomainException('Conteo inexistente.');
   $actual=co_last($db,$sucursal,$producto,(string)$c['ubicacion']);
   if(!$actual||(int)$actual['id']!==$cid)throw new DomainException('Existe un conteo más reciente; abre el último para solicitar el ajuste.');
   $est=pi_ubicacion($db,$sucursal,$producto);$pend=pi_pendientes_entrega($db,$sucursal,$producto);
   if($tipo!=='POS_GENERAL' && $tipo!==$c['ubicacion'])throw new DomainException('El ajuste físico debe corresponder al lugar contado.');
   if($tipo==='POS_GENERAL'){
    if(!$esAdmin)throw new DomainException('Solo Dirección o Administración puede solicitar un ajuste del POS general.');
    $f=co_last($db,$sucursal,$producto,'FARMACIA');$a=co_last($db,$sucursal,$producto,'ALMACEN');
    if(!co_count_fresh($f)||!co_count_fresh($a))throw new DomainException('El POS general requiere conteos recientes de Farmacia y Almacén (últimas 24 horas).');
    if((int)$pend['vendido_pendiente']>0||(int)$pend['solicitado_sin_cobro']>0)throw new DomainException('Hay tickets POS pendientes: conclúyelos o concílialos antes de ajustar el total general.');
    if((int)$est['almacen']!==(int)$a['cantidad_contada'])throw new DomainException('Primero concilia el conteo de Almacén con sus ubicaciones; no se puede ajustar el POS con ese saldo sin resolver.');
    $objetivo=(int)$f['cantidad_contada']+(int)$a['cantidad_contada'];$antes=(int)$est['general'];
   }else{
    $objetivo=(int)$c['cantidad_contada'];$antes=$tipo==='FARMACIA'?$est['farmacia']:$est['almacen'];
   }
   $st=$db->prepare('INSERT INTO erp_conciliacion_ajustes(conteo_id,sucursal_id,producto_id,destino,cantidad_objetivo,cantidad_anterior,motivo,solicitado_por) VALUES(?,?,?,?,?,?,?,?)');
   $st->execute([$cid,$sucursal,$producto,$tipo,$objetivo,$antes,$nota,$uid]);
   $aid=(int)$db->lastInsertId();co_event($db,$aid,'SOLICITADO',$uid,$antes,$objetivo,$nota);
   $db->commit();$_SESSION['co_flash']='Solicitud registrada. Está pendiente de autorización; los saldos no cambiaron.';
  }elseif(in_array($accion,['autorizar','rechazar','aplicar','cerrar_manual'],true)){
   if(!$esAdmin)throw new DomainException('Solo ADMINISTRADOR o DIRECCIÓN puede gestionar autorizaciones.');
   $aid=(int)($_POST['ajuste_id']??0);
   if($aid<1)throw new DomainException('Selecciona un ajuste válido.');
   $db->beginTransaction();
   $st=$db->prepare('SELECT * FROM erp_conciliacion_ajustes WHERE id=? AND sucursal_id=? AND producto_id=? FOR UPDATE');$st->execute([$aid,$sucursal,$producto]);$aj=$st->fetch(PDO::FETCH_ASSOC);
   if(!$aj)throw new DomainException('Ajuste no encontrado.');
   $tipo=(string)$aj['destino'];$estado=(string)$aj['estado'];
   if($accion==='rechazar'){
    if($estado!=='SOLICITADO'||mb_strlen($nota)<8||mb_strlen($nota)>500)throw new DomainException('Solo se rechazan solicitudes pendientes; indica un motivo de al menos 8 caracteres.');
    $db->prepare("UPDATE erp_conciliacion_ajustes SET estado='RECHAZADO',autorizado_por=?,autorizado_en=NOW(),referencia=? WHERE id=?")->execute([$uid,$nota,$aid]);
    co_event($db,$aid,'RECHAZADO',$uid,$aj['cantidad_anterior'],(int)$aj['cantidad_objetivo'],$nota);
    $_SESSION['co_flash']='Solicitud rechazada con motivo registrado.';
   }elseif($accion==='autorizar'){
    if($estado!=='SOLICITADO')throw new DomainException('La solicitud ya se atendió.');
    if((int)$aj['solicitado_por']===$uid)throw new DomainException('Otra persona autorizada debe aprobar la solicitud. No puedes autorizar tu propio ajuste.');
    if($tipo==='ALMACEN'){
     $db->prepare("UPDATE erp_conciliacion_ajustes SET estado='PENDIENTE_OPERACION_ALMACEN',autorizado_por=?,autorizado_en=NOW() WHERE id=?")->execute([$uid,$aid]);
     co_event($db,$aid,'APROBADO_MANUAL',$uid,$aj['cantidad_anterior'],(int)$aj['cantidad_objetivo'],'Aplicar por ubicación en el módulo de Almacén; no se alteró stock.');
     $_SESSION['co_flash']='Autorizado. Pendiente de ajustar la ubicación exacta en el módulo de Almacén.';
    }else{
     $db->prepare("UPDATE erp_conciliacion_ajustes SET estado='AUTORIZADO',autorizado_por=?,autorizado_en=NOW() WHERE id=?")->execute([$uid,$aid]);
     co_event($db,$aid,'AUTORIZADO',$uid,$aj['cantidad_anterior'],(int)$aj['cantidad_objetivo'],'Autorización documental; aún no aplicado.');
     $_SESSION['co_flash']='Autorizado. Falta aplicar el ajuste; valida que no haya movimientos nuevos.';
    }
   }elseif($accion==='aplicar'){
    if($estado!=='AUTORIZADO'||!in_array($tipo,['FARMACIA','POS_GENERAL'],true))throw new DomainException('Ajuste no autorizado para aplicación automática.');
    $cst=$db->prepare('SELECT * FROM erp_conciliacion_conteos WHERE id=?');$cst->execute([$aj['conteo_id']]);$c=$cst->fetch(PDO::FETCH_ASSOC);
    $ultimo=co_last($db,$sucursal,$producto,(string)$c['ubicacion']);
    if(!$ultimo||(int)$ultimo['id']!==(int)$aj['conteo_id'])throw new DomainException('Existe un nuevo conteo. No se puede aplicar el ajuste antiguo.');
    if(!co_count_fresh($c))throw new DomainException('El conteo tiene más de 24 horas. Solicita un conteo actualizado.');
    $target=(int)$aj['cantidad_objetivo'];
    $est=pi_ubicacion($db,$sucursal,$producto);$pend=pi_pendientes_entrega($db,$sucursal,$producto);
    if($tipo==='FARMACIA'){
     $st=$db->prepare('SELECT existencia,minimo FROM erp_pos_farmacia_control WHERE sucursal_id=? AND producto_id=? FOR UPDATE');$st->execute([$sucursal,$producto]);$r=$st->fetch(PDO::FETCH_ASSOC)?:null;
     $antes=$r===null?null:(int)$r['existencia'];
     if($antes!==($aj['cantidad_anterior']===null?null:(int)$aj['cantidad_anterior']))throw new DomainException('El saldo de Farmacia cambió desde la solicitud. No se aplicó nada.');
     if((int)$c['cantidad_contada']!==$target)throw new DomainException('La cantidad aprobada difiere del conteo de Farmacia.');
     $minimo=$r===null?24:(int)$r['minimo'];
     $db->prepare('INSERT INTO erp_pos_farmacia_control(sucursal_id,producto_id,existencia,minimo,actualizado_por) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE existencia=VALUES(existencia),actualizado_por=VALUES(actualizado_por)')->execute([$sucursal,$producto,$target,$minimo,$uid]);
     $db->prepare('INSERT INTO erp_pos_conteos_historial(sucursal_id,producto_id,anterior,nueva,minimo_anterior,minimo_nuevo,usuario_id,motivo) VALUES(?,?,?,?,?,?,?,?)')->execute([$sucursal,$producto,$antes,$target,$r['minimo']??null,$minimo,$uid,'Ajuste autorizado conciliación #'.$aid]);
     pi_alerta($db,$sucursal,$producto,$target,$minimo);
    }else{
     $fa=co_last($db,$sucursal,$producto,'FARMACIA');$al=co_last($db,$sucursal,$producto,'ALMACEN');
     if(!co_count_fresh($fa)||!co_count_fresh($al))throw new DomainException('Caducaron los conteos de Farmacia o Almacén.');
     if((int)$pend['vendido_pendiente']!==0||(int)$pend['solicitado_sin_cobro']!==0)throw new DomainException('Hay tickets POS pendientes: no puede ajustarse el general.');
     if($est['farmacia']===null||(int)$est['farmacia']!==(int)$fa['cantidad_contada']||(int)$est['almacen']!==(int)$al['cantidad_contada'])throw new DomainException('El inventario físico no coincide con los conteos confirmados. Primero regulariza ubicaciones.');
     if($target!==(int)$fa['cantidad_contada']+(int)$al['cantidad_contada'])throw new DomainException('El objetivo ya no coincide con el inventario físico.');
     $antes=co_lock_pos($db,$sucursal,$producto);
     if($antes!==(int)$aj['cantidad_anterior'])throw new DomainException('Hubo ventas/entradas posteriores a la solicitud. No se aplicó nada.');
     // Un faltante de migración masiva no debe regularizarse mediante ajuste de caja.
     if(abs($target-$antes)>100)throw new DomainException('Diferencia mayor a 100 piezas: requiere migración/conciliación extraordinaria, no un ajuste ordinario del POS.');
     $db->prepare('UPDATE pos_existencias_farmacia SET existencia=? WHERE sucursal_id=? AND producto_id=?')->execute([$target,$sucursal,$producto]);
    }
    $db->prepare("UPDATE erp_conciliacion_ajustes SET estado='APLICADO',aplicado_por=?,aplicado_en=NOW(),referencia=? WHERE id=?")->execute([$uid,'Conciliación controlada #'.$aid,$aid]);
    co_event($db,$aid,'APLICADO',$uid,$antes,$target,'Cambio aplicado en inventario '.$tipo.'; auditar en módulo de conciliación.');
    $_SESSION['co_flash']='Ajuste aplicado y auditado: '.$tipo.'.';
   }elseif($accion==='cerrar_manual'){
    if($tipo!=='ALMACEN'||$estado!=='PENDIENTE_OPERACION_ALMACEN'||mb_strlen($nota)<8||mb_strlen($nota)>250)throw new DomainException('Registra la referencia del movimiento realizado en la ubicación exacta (8 a 250 caracteres).');
    $est=pi_ubicacion($db,$sucursal,$producto);
    if((int)$est['almacen']!==(int)$aj['cantidad_objetivo'])throw new DomainException('El saldo de Almacén aún no coincide con el objetivo aprobado. Corrige el rack en el módulo de Almacén.');
    $db->prepare("UPDATE erp_conciliacion_ajustes SET estado='CERRADO_MANUAL',aplicado_por=?,aplicado_en=NOW(),referencia=? WHERE id=?")->execute([$uid,$nota,$aid]);
    co_event($db,$aid,'CERRADO_MANUAL',$uid,$aj['cantidad_anterior'],(int)$aj['cantidad_objetivo'],$nota);
    $_SESSION['co_flash']='Se registró el cierre documental de Almacén. No se modificaron saldos desde esta pantalla.';
   }
   $db->commit();
  }
  header('Location: erp_conciliacion_gestion.php?sucursal='.$sucursal.'&producto='.$producto,true,303);exit;
 }
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();$error=$e instanceof DomainException?$e->getMessage():'No fue posible completar la operación. Revisa la migración y los errores PHP.';error_log('ERP conciliacion operativa: '.$e->getMessage());}
$prods=[];$actual=null;$stock=null;$pend=null;$ultimos=[];$ajustes=[];$eventos=[];
if(isset($db))try{
 if(mb_strlen($busca)>1){$st=$db->prepare('SELECT id,codigo,descripcion FROM productos WHERE estado=1 AND (codigo LIKE ? OR codigo_barras LIKE ? OR descripcion LIKE ?) ORDER BY descripcion LIMIT 30');$lk='%'.$busca.'%';$st->execute([$lk,$lk,$lk]);$prods=$st->fetchAll(PDO::FETCH_ASSOC);}
 if($producto>0 && co_sid($sucursal)){
  $actual=co_producto($db,$producto);
  if($actual){$stock=pi_ubicacion($db,$sucursal,$producto);$pend=pi_pendientes_entrega($db,$sucursal,$producto);
   foreach(['FARMACIA','ALMACEN'] as $where)$ultimos[$where]=co_last($db,$sucursal,$producto,$where);
   $st=$db->prepare('SELECT a.*,c.ubicacion,c.cantidad_contada,c.registrado_en,c.observaciones FROM erp_conciliacion_ajustes a JOIN erp_conciliacion_conteos c ON c.id=a.conteo_id WHERE a.sucursal_id=? AND a.producto_id=? ORDER BY a.id DESC LIMIT 50');$st->execute([$sucursal,$producto]);$ajustes=$st->fetchAll(PDO::FETCH_ASSOC);
   $st=$db->prepare('SELECT b.*,u.nombre FROM erp_conciliacion_bitacora b LEFT JOIN usuarios u ON u.id=b.usuario_id JOIN erp_conciliacion_ajustes a ON a.id=b.ajuste_id WHERE a.sucursal_id=? AND a.producto_id=? ORDER BY b.id DESC LIMIT 60');$st->execute([$sucursal,$producto]);$eventos=$st->fetchAll(PDO::FETCH_ASSOC);
  }
 }
}catch(Throwable $e){error_log('ERP conciliacion leer: '.$e->getMessage());$error='No fue posible cargar la conciliación. Confirma que ejecutaste el SQL.';}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ERP G&D | Conciliación operativa</title><style>
*{box-sizing:border-box}body{margin:0;font:15px system-ui,'Segoe UI',sans-serif;background:#edf3fa;color:#183759}header{background:linear-gradient(110deg,#113c6a,#226ac2);color:#fff;padding:24px max(16px,calc((100% - 1220px)/2))}header h1{margin:0;font-size:27px}header p{margin:7px 0 0;color:#dce8fa}main{max-width:1250px;margin:20px auto;padding:0 16px}nav{display:flex;flex-wrap:wrap;gap:9px;margin-bottom:16px}a.btn,button{background:#155aab;color:#fff;border:0;border-radius:9px;padding:10px 15px;text-decoration:none;font:600 14px system-ui;cursor:pointer}a.btn.secondary{background:#e2eafa;color:#174671}.card{background:white;padding:20px;border-radius:14px;margin-bottom:15px;box-shadow:0 5px 20px #1c436815}.cols{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:15px}.field{margin:9px 0}.field label{display:block;margin:5px 0;font-weight:700;font-size:13px}input,select,textarea{padding:10px;border:1px solid #b8cbe0;border-radius:9px;max-width:100%;font:inherit}input[type=number]{width:170px}textarea{width:100%;min-height:72px}.alert{padding:13px;background:#fff3de;border:1px solid #edd1a0;border-radius:10px;margin:10px 0}.error{background:#fee6e9;border-color:#eb99a5}.ok{background:#e4f4ec;border-color:#b6e4ce}.small{font-size:12px;color:#5b748c}.stats{display:flex;gap:10px;flex-wrap:wrap}.stat{min-width:140px;flex:1;background:#edf4fc;padding:14px;border-radius:10px}.stat strong{font-size:24px;display:block}h2{margin:0 0 12px;font-size:20px}h3{font-size:17px;margin:15px 0 9px}table{border-collapse:collapse;width:100%;min-width:900px}th,td{padding:10px;border-bottom:1px solid #e1ebf5;text-align:left;font-size:12px;vertical-align:top}th{background:#1a4c7f;color:#fff}td b{display:inline-block}form.inline{display:inline-flex;gap:5px;flex-wrap:wrap;align-items:center}form.inline input{width:260px}.scroll{overflow:auto}.tag{display:inline-block;border-radius:10px;padding:5px 8px;background:#e7f1fa;font-weight:700;font-size:11px}.danger{background:#b52d43!important}.muted{color:#617b94} @media(max-width:650px){header h1{font-size:21px}.card{padding:14px}}
</style></head><body>
<header><h1>Conciliación operativa · ERP G&amp;D</h1><p>Conteos físicos, investigación de diferencias y ajustes con autorización — Ciudad Hidalgo y Tuxtla</p></header>
<main><nav><?php if($esAdmin):?><a class="btn secondary" href="erp_conciliacion_inventarios.php?sucursal=<?= $sucursal ?>">← Conciliación</a><a class="btn secondary" href="erp_reservas_almacen.php?sucursal=<?= $sucursal ?>">Reservas</a><?php else:?><a class="btn secondary" href="dashboard.php">← Almacén / Inicio</a><?php endif;?><a class="btn secondary" href="logout.php">Cerrar sesión</a></nav>
<div class="alert"><b>Flujo seguro:</b> contar → documentar la diferencia → solicitar → autorizar (otra persona) → aplicar. Capturar un conteo <b>no</b> modifica stock. Los ajustes de Almacén se ejecutan en su módulo por <b>ubicación/rack</b>, no desde este formulario. El POS general solo puede ajustarse cuando los dos conteos están vigentes y no hay tickets pendientes.</div>
<?php if($error):?><div class="alert error" role="alert"><?=co_esc($error)?></div><?php endif;?><?php if($mensaje):?><div class="alert ok" role="status"><?=co_esc($mensaje)?></div><?php endif;?>
<section class="card"><form method="get"><div class="cols"><div class="field"><label for="suc">Sucursal</label><select id="suc" name="sucursal"><?php foreach($sucursales as $id=>$nombre):if(!$esAdmin && !co_can_count($usuario,$id,$rol==='GERENTE'?'FARMACIA':'ALMACEN'))continue;?><option value="<?= $id ?>" <?= $sucursal===$id?'selected':''?>><?=co_esc($nombre)?></option><?php endforeach;?></select></div><div class="field"><label for="q">Buscar código o producto</label><input id="q" name="q" value="<?=co_esc($busca)?>" placeholder="Código o descripción" minlength="2"></div></div><button type="submit">Buscar</button></form>
<?php if($prods):?><p class="small">Selecciona el producto:</p><div class="scroll"><table style="min-width:500px"><?php foreach($prods as $pr):?><tr><td><a href="?sucursal=<?=$sucursal?>&amp;producto=<?=(int)$pr['id']?>"><?=co_esc($pr['codigo'].' · '.$pr['descripcion'])?></a></td></tr><?php endforeach;?></table></div><?php endif;?></section>
<?php if($stock && $actual):?>
<section class="card"><h2><?=co_esc($actual['codigo'].' · '.$actual['descripcion'])?></h2><p class="small"><?=co_esc($sucursales[$sucursal])?> · Identificador <?= $producto ?></p><div class="stats"><div class="stat">POS general<strong><?= (int)$stock['general'] ?></strong></div><div class="stat">Farmacia registrada<strong><?= $stock['farmacia']===null?'Sin conteo':(int)$stock['farmacia'] ?></strong></div><div class="stat">Almacén por racks<strong><?= (int)$stock['almacen'] ?></strong></div><div class="stat">POS cobrado pendiente<strong><?= (int)$pend['vendido_pendiente'] ?></strong></div><div class="stat">Tickets sin cobrar<strong><?= (int)$pend['solicitado_sin_cobro'] ?></strong></div></div></section>
<section class="cols">
<?php foreach(['FARMACIA'=>'Farmacia','ALMACEN'=>'Almacén'] as $where=>$nombre):?>
<div class="card"><h2>Conteo físico · <?=co_esc($nombre)?></h2>
<?php if(isset($ultimos[$where]) && $ultimos[$where]):$cu=$ultimos[$where];?><p class="small">Último conteo: <b><?=(int)$cu['cantidad_contada']?></b> piezas · <?=co_esc($cu['registrado_en'])?>. Registrado en sistema al contar: <?=co_esc($cu['cantidad_sistema']??'SIN DATO')?>.</p><?php else:?><p class="small">No hay conteo documentado.</p><?php endif;?>
<?php if(co_can_count($usuario,$sucursal,$where)):?><form method="post"><input type="hidden" name="csrf" value="<?=co_esc($csrf)?>"><input type="hidden" name="accion" value="conteo"><input type="hidden" name="producto" value="<?=$producto?>"><input type="hidden" name="sucursal" value="<?=$sucursal?>"><input type="hidden" name="ubicacion" value="<?=co_esc($where)?>"><div class="field"><label>Cantidad física contada (piezas)</label><input type="number" name="cantidad" min="0" max="10000000" required></div><div class="field"><label>Referencia, evidencia y observaciones</label><textarea name="nota" minlength="8" maxlength="500" placeholder="Fecha, responsable, ubicación/reporte de conteo..." required></textarea></div><button>Guardar conteo, sin modificar stock</button></form><?php else:?><p class="small">Tu rol no tiene permiso para capturar este conteo.</p><?php endif;?>
<?php if($esAdmin && isset($ultimos[$where]) && $ultimos[$where]):$cu=$ultimos[$where];?><hr><h3>Solicitar corrección · <?=co_esc($where)?></h3><p class="small">Objetivo: <?= (int)$cu['cantidad_contada'] ?> piezas. Requiere revisión y autorización de otra persona.</p><form method="post"><input type="hidden" name="csrf" value="<?=co_esc($csrf)?>"><input type="hidden" name="accion" value="solicitar"><input type="hidden" name="sucursal" value="<?=$sucursal?>"><input type="hidden" name="producto" value="<?=$producto?>"><input type="hidden" name="ubicacion" value="<?=co_esc($where)?>"><input type="hidden" name="destino" value="<?=co_esc($where)?>"><input type="hidden" name="conteo_id" value="<?=(int)$cu['id']?>"><div class="field"><label>Motivo de ajuste</label><textarea name="nota" minlength="12" maxlength="500" required></textarea></div><button>Solicitar autorización</button></form><?php endif;?></div>
<?php endforeach;?></section>
<?php if($esAdmin && !empty($ultimos['FARMACIA']) && !empty($ultimos['ALMACEN'])):?>
<section class="card"><h2>Conciliación del POS general</h2><p>Objetivo según últimos conteos: <b><?= (int)$ultimos['FARMACIA']['cantidad_contada']+(int)$ultimos['ALMACEN']['cantidad_contada']-(int)$pend['vendido_pendiente'] ?> piezas</b>. Solo se tramita si están ambos conteos frescos, no hay tickets pendientes y Almacén está conciliado por racks. La validación se repite antes de aplicar.</p><form method="post"><input type="hidden" name="csrf" value="<?=co_esc($csrf)?>"><input type="hidden" name="accion" value="solicitar"><input type="hidden" name="sucursal" value="<?=$sucursal?>"><input type="hidden" name="producto" value="<?=$producto?>"><input type="hidden" name="ubicacion" value="FARMACIA"><input type="hidden" name="destino" value="POS_GENERAL"><input type="hidden" name="conteo_id" value="<?=(int)$ultimos['FARMACIA']['id']?>"><div class="field"><label>Justificación de ajuste POS general</label><textarea name="nota" minlength="12" maxlength="500" required></textarea></div><button>Solicitar revisión del POS general</button></form></section>
<?php endif;?>
<section class="card"><h2>Solicitudes y autorizaciones</h2><div class="scroll"><table><thead><tr><th>ID / Destino</th><th>Antes → objetivo</th><th>Estado</th><th>Fecha / motivo</th><th>Revisión y aplicación</th></tr></thead><tbody><?php foreach($ajustes as $a):?><tr><td>#<?=(int)$a['id']?> · <b><?=co_esc($a['destino'])?></b></td><td><?=co_esc($a['cantidad_anterior']??'Sin configurar')?> → <?= (int)$a['cantidad_objetivo'] ?></td><td><span class="tag"><?=co_esc($a['estado'])?></span></td><td><?=co_esc($a['solicitado_en'])?><br><?=co_esc($a['motivo'])?></td><td><?php if($esAdmin && $a['estado']==='SOLICITADO'):?>
<?php if((int)$a['solicitado_por']===$uid):?><span class="small">Debe revisar otro administrador o dueño.</span><?php else:?><form class="inline" method="post"><input type="hidden" name="csrf" value="<?=co_esc($csrf)?>"><input type="hidden" name="sucursal" value="<?=$sucursal?>"><input type="hidden" name="producto" value="<?=$producto?>"><input type="hidden" name="ajuste_id" value="<?=(int)$a['id']?>"><button name="accion" value="autorizar" onclick="return confirm('¿Autorizar este ajuste para su aplicación posterior?')">Autorizar</button><input name="nota" placeholder="Motivo al rechazar" maxlength="500"><button name="accion" value="rechazar" class="danger">Rechazar</button></form><?php endif;?>
<?php elseif($esAdmin && $a['estado']==='AUTORIZADO'):?><form method="post" onsubmit="return confirm('Esta operación MODIFICARÁ existencias. ¿Revisaste los saldos y el conteo físico?')"><input type="hidden" name="csrf" value="<?=co_esc($csrf)?>"><input type="hidden" name="sucursal" value="<?=$sucursal?>"><input type="hidden" name="producto" value="<?=$producto?>"><input type="hidden" name="ajuste_id" value="<?=(int)$a['id']?>"><button name="accion" value="aplicar">Aplicar ajuste autorizado</button></form>
<?php elseif($esAdmin && $a['estado']==='PENDIENTE_OPERACION_ALMACEN'):?><div class="small">Ajusta primero el rack/ubicación en Almacén.</div><form method="post"><input type="hidden" name="csrf" value="<?=co_esc($csrf)?>"><input type="hidden" name="sucursal" value="<?=$sucursal?>"><input type="hidden" name="producto" value="<?=$producto?>"><input type="hidden" name="ajuste_id" value="<?=(int)$a['id']?>"><input name="nota" minlength="8" maxlength="250" placeholder="Folio/movimiento de Almacén" required><button name="accion" value="cerrar_manual">Validar cierre</button></form>
<?php else:?><span class="small"><?=co_esc($a['referencia']??'—')?></span><?php endif;?></td></tr><?php endforeach; ?><?php if(!$ajustes):?><tr><td colspan="5">Todavía no hay ajustes solicitados.</td></tr><?php endif;?></tbody></table></div></section>
<section class="card"><h2>Bitácora de cambios</h2><div class="scroll"><table style="min-width:750px"><thead><tr><th>Fecha</th><th>Solicitud</th><th>Evento</th><th>Responsable</th><th>Detalle</th></tr></thead><tbody><?php foreach($eventos as $ev):?><tr><td><?=co_esc($ev['registrado_en'])?></td><td>#<?=(int)$ev['ajuste_id']?></td><td><?=co_esc($ev['evento'])?></td><td><?=co_esc($ev['nombre']??'Usuario '.(int)$ev['usuario_id'])?></td><td><?=co_esc($ev['observacion'])?></td></tr><?php endforeach;?><?php if(!$eventos):?><tr><td colspan="5">Sin movimientos de ajustes documentados.</td></tr><?php endif;?></tbody></table></div></section>
<?php else:?><section class="card"><p>Busca y selecciona un producto para ver sus conteos, ajustes e historial.</p></section><?php endif;?></main>
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
<!-- GYD_UNA_PESTANA_INTERNA -->
<script src="assets/js/gyd_una_pestana.js?v=1"></script>
</body></html>
