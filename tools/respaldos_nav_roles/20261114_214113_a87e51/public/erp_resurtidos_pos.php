<?php
declare(strict_types=1);
/** ERP G&D · Recepción de salidas de resurtido en Farmacia.
 * La salida ya descontó el inventario de Almacén; aquí SOLO se acreditan
 * en erp_pos_farmacia_control la existencia FÍSICA de Farmacia, sin sumar al POS general.
 */
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/pos_inteligente.php';
requireLogin();
$usuario=currentUser()?:[];
$rol=strtoupper(trim((string)($usuario['rol_original']??$usuario['rol']??'')));
$puedenEntrar=['ADMINISTRADOR','ADMIN','DIRECCION','GERENTE','ENCARGADO','JEFE_ALMACEN'];
if(!in_array($rol,$puedenEntrar,true)){http_response_code(403);exit('Sin permisos para consultar resurtidos al POS.');}
$uid=(int)($usuario['id']??0);
$esGlobal=in_array($rol,['ADMINISTRADOR','ADMIN','DIRECCION'],true);
$puedeConfirmar=in_array($rol,['ADMINISTRADOR','ADMIN','DIRECCION','GERENTE'],true);
$almacenSesion=(int)($usuario['almacen_id']??0);
if(!$esGlobal && !in_array($almacenSesion,[1,3],true)){http_response_code(403);exit('Necesitas un almacén habilitado.');}
if($uid<1){http_response_code(403);exit('Sesión inválida.');}
function rp_e(mixed $s):string{return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function rp_hora(mixed $s):string{return rp_e((string)$s);}
/** Determina el resurtido sin consultar por coincidencias parciales de folio. */
function rp_resurtido(PDO $db,array $m):?array {
    if(($m['tipo_movimiento']??'')!=='SALIDA'||strtoupper((string)($m['tipo_operacion']??''))!=='RESURTIDO'||(int)($m['cancelado']??0)!==0)return null;
    $alm=(int)($m['almacen_id']??0);
    if(!in_array($alm,[1,3],true))return null;
    // Solo salidas destinadas a la farmacia de la misma sucursal.
    $ref=trim((string)($m['referencia']??''));
    if(!str_starts_with($ref,$alm===1?'S0004':'S0007'))return null;
    $txt=(string)($m['observaciones']??'');
    $res=null;
    if(preg_match('/^\s*Folio\s+resurtido\s*:\s*([^\s|]+)/iu',$txt,$mm)){
        $st=$db->prepare("SELECT id,folio,almacen_id,tipo_solicitud,estado FROM resurtidos WHERE folio=? AND almacen_id=? AND tipo_solicitud='RESURTIDO' LIMIT 1");
        $st->execute([$mm[1],$alm]);$res=$st->fetch(PDO::FETCH_ASSOC)?:null;
    }
    if(!$res){
        $st=$db->prepare("SELECT id,folio,almacen_id,tipo_solicitud,estado FROM resurtidos WHERE salida_id=? AND almacen_id=? AND tipo_solicitud='RESURTIDO' LIMIT 1");
        $st->execute([(int)$m['id'],$alm]);$res=$st->fetch(PDO::FETCH_ASSOC)?:null;
    }
    // Un pedido directo del POS es una venta desde bodega, NO un resurtido interno de farmacia.
    if($res){
        $st=$db->prepare('SELECT 1 FROM erp_pos_tickets_almacen WHERE resurtido_id=? LIMIT 1');
        $st->execute([(int)$res['id']]);
        if($st->fetchColumn())return null;
    }
    return $res;
}
function rp_detalles(PDO $db,int $salidaId):array {
    $st=$db->prepare('SELECT d.producto_id,p.codigo,p.descripcion,SUM(d.cantidad) cantidad FROM movimiento_detalle d JOIN productos p ON p.id=d.producto_id WHERE d.movimiento_id=? GROUP BY d.producto_id,p.codigo,p.descripcion ORDER BY p.descripcion');
    $st->execute([$salidaId]);return $st->fetchAll(PDO::FETCH_ASSOC);
}
function rp_pos(PDO $db,int $alm):int{
    $nombre=$alm===1?'CIUDAD HIDALGO':'TUXTLA GUTIERREZ';
    $st=$db->prepare('SELECT id FROM pos_sucursales WHERE UPPER(TRIM(nombre))=? AND activo=1 LIMIT 1');
    $st->execute([$nombre]);$id=(int)$st->fetchColumn();
    if($id<1)throw new RuntimeException('No está habilitada la sucursal POS '.$nombre.'.');
    return $id;
}
if(empty($_SESSION['erp_rpos_csrf']))$_SESSION['erp_rpos_csrf']=bin2hex(random_bytes(32));
$csrf=(string)$_SESSION['erp_rpos_csrf'];
if(empty($_SESSION['erp_rpos_form_token']))$_SESSION['erp_rpos_form_token']=bin2hex(random_bytes(16));
$token=(string)$_SESSION['erp_rpos_form_token'];
$ver=(int)($_GET['ver']??$_POST['movimiento_id']??0);
$error='';$exito=(string)($_SESSION['erp_rpos_flash']??'');unset($_SESSION['erp_rpos_flash']);
$lista=[];$movimiento=null;$rel=null;$items=[];$historial=[];$inicio='';
try{
    $db=(new Database())->connect();$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $inicio=(string)$db->query('SELECT habilitado_desde FROM erp_resurtidos_pos_config WHERE id=1')->fetchColumn();
    if($inicio==='')throw new RuntimeException('Falta ejecutar la migración sql/erp_resurtidos_pos.sql.');
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!$puedeConfirmar)throw new RuntimeException('Solo Gerencia y Dirección pueden confirmar entregas a Farmacia.');
        if(!hash_equals($csrf,(string)($_POST['csrf']??'')))throw new RuntimeException('Sesión de formulario vencida. Actualiza la página.');
        $recToken=(string)($_POST['token']??'');
        if(!preg_match('/^[a-f0-9]{32}$/D',$recToken)||!hash_equals($token,$recToken))throw new RuntimeException('La confirmación ya fue enviada o el formulario expiró.');
        if(($_POST['confirmar']??'')!=='1')throw new RuntimeException('Debes confirmar que recibiste físicamente la mercancía.');
        if($ver<1)throw new RuntimeException('Selecciona una salida válida.');
        $cantidades=$_POST['recibida']??null;
        if(!is_array($cantidades)||count($cantidades)>250)throw new RuntimeException('Cantidades no válidas.');
        $obs=trim((string)($_POST['observaciones']??''));
        if(mb_strlen($obs)>750)throw new RuntimeException('Las observaciones exceden el máximo permitido.');
        $db->beginTransaction();
        // Primero bloquear la salida: serializa a usuarios confirmando el mismo envío.
        $st=$db->prepare('SELECT * FROM movimientos WHERE id=? FOR UPDATE');$st->execute([$ver]);$m=$st->fetch(PDO::FETCH_ASSOC);
        if(!$m || (string)$m['created_at']<$inicio)throw new RuntimeException('Esta salida es anterior a la instalación o ya no existe.');
        if(!$esGlobal && (int)$m['almacen_id']!==$almacenSesion)throw new RuntimeException('No corresponde al almacén de tu usuario.');
        $r=rp_resurtido($db,$m);
        if(!$r)throw new RuntimeException('Esta salida no corresponde a un resurtido válido destinado a Farmacia.');
        $posId=rp_pos($db,(int)$m['almacen_id']);
        $lineas=rp_detalles($db,$ver);
        if(!$lineas||count($lineas)>250)throw new RuntimeException('No hay partidas válidas para confirmar.');
        $st=$db->prepare('SELECT * FROM erp_resurtidos_pos_recepciones WHERE movimiento_id=? FOR UPDATE');$st->execute([$ver]);$record=$st->fetch(PDO::FETCH_ASSOC);
        if(!$record){
            $db->prepare('INSERT INTO erp_resurtidos_pos_recepciones(movimiento_id,resurtido_id,almacen_id,pos_sucursal_id) VALUES (?,?,?,?)')->execute([$ver,(int)$r['id'],(int)$m['almacen_id'],$posId]);
            $newItem=$db->prepare('INSERT INTO erp_resurtidos_pos_detalle(movimiento_id,producto_id,cantidad_enviada,cantidad_recibida) VALUES (?,?,?,0)');
            foreach($lineas as $d){$q=(int)$d['cantidad'];if($q<1)throw new RuntimeException('La salida contiene cantidades inválidas.');$newItem->execute([$ver,(int)$d['producto_id'],$q]);}
        }else if((int)$record['resurtido_id']!==(int)$r['id']||(int)$record['pos_sucursal_id']!==$posId){throw new RuntimeException('La vinculación de la salida cambió.');}
        $st=$db->prepare('SELECT * FROM erp_resurtidos_pos_detalle WHERE movimiento_id=? ORDER BY producto_id FOR UPDATE');$st->execute([$ver]);$registros=$st->fetchAll(PDO::FETCH_ASSOC);
        $map=[];foreach($registros as $z)$map[(int)$z['producto_id']]=$z;
        if(count($map)!==count($lineas))throw new RuntimeException('Las partidas de la salida cambiaron. Solicita revisión administrativa.');
        $aConfirmar=[];$pendientesPrevios=0;$totalFaltante=0;
        foreach($lineas as $d){
            $pid=(int)$d['producto_id'];$q=(int)$d['cantidad'];
            if(!isset($map[$pid])||(int)$map[$pid]['cantidad_enviada']!==$q)throw new RuntimeException('Detectada alteración de cantidades del envío.');
            $pendiente=$q-(int)$map[$pid]['cantidad_recibida'];
            if($pendiente<0)throw new RuntimeException('Cantidad recibida mayor que la salida; requiere auditoría.');
            $pendientesPrevios+=$pendiente;
            $valor=$cantidades[$pid]??'0';
            if(!is_string($valor)&&!is_int($valor))throw new RuntimeException('Una cantidad tiene formato inválido.');
            if(!preg_match('/^\d{1,10}$/D',(string)$valor))throw new RuntimeException('Solo se aceptan cantidades enteras no negativas.');
            $num=(int)$valor;
            if($num>$pendiente)throw new RuntimeException('La cantidad recibida supera lo pendiente de '.(string)$d['descripcion']);
            if($num>0)$aConfirmar[$pid]=$num;
            $totalFaltante+=$pendiente-$num;
        }
        if(!$aConfirmar)throw new RuntimeException('Debes confirmar al menos una pieza. Para entregas sin piezas, registra una incidencia y no afectes stock.');
        if($totalFaltante>0 && mb_strlen($obs)<5)throw new RuntimeException('Para una recepción parcial, explica en Observaciones qué quedó pendiente (mínimo 5 caracteres).');
        if($pendientesPrevios<1)throw new RuntimeException('Esta salida ya está recibida por completo.');
        // El token único protege también contra reenvíos desde otra sesión del navegador.
        $db->prepare('INSERT INTO erp_resurtidos_pos_confirmaciones(movimiento_id,token,usuario_id,observaciones) VALUES (?,?,?,?)')->execute([$ver,$recToken,$uid,$obs?:null]);
        $confirmId=(int)$db->lastInsertId();
        $stDet=$db->prepare('INSERT INTO erp_resurtidos_pos_confirmaciones_detalle(confirmacion_id,producto_id,cantidad) VALUES (?,?,?)');
        $stSum=$db->prepare('UPDATE erp_resurtidos_pos_detalle SET cantidad_recibida=cantidad_recibida+? WHERE movimiento_id=? AND producto_id=?');
        $stPos=$db->prepare('UPDATE erp_pos_farmacia_control SET existencia=existencia+? WHERE sucursal_id=? AND producto_id=?');
        $totalConfirmado=0;
        foreach($aConfirmar as $pid=>$q){$stDet->execute([$confirmId,$pid,$q]);$stSum->execute([$q,$ver,$pid]);$stPos->execute([$q,$posId,$pid]);if($stPos->rowCount()!==1)throw new RuntimeException('Configura el conteo físico inicial de Farmacia antes de acreditar el resurtido.');$stock=pi_ubicacion($db,$posId,$pid);pi_alerta($db,$posId,$pid,$stock['farmacia'],$stock['minimo']);$totalConfirmado+=$q;}
        $db->prepare("UPDATE erp_resurtidos_pos_recepciones SET estado=? WHERE movimiento_id=?")->execute([$totalFaltante===0?'COMPLETA':'PARCIAL',$ver]);
        $db->commit();$_SESSION['erp_rpos_form_token']=bin2hex(random_bytes(16));
        $_SESSION['erp_rpos_flash']='Recepción confirmada: '.$totalConfirmado.' piezas acreditadas al conteo físico de Farmacia. El POS general NO cambió. '.($totalFaltante>0?('Quedan '.$totalFaltante.' piezas en tránsito / por aclarar.'): 'Resurtido recibido por completo.');
        header('Location: erp_resurtidos_pos.php?ver='.$ver,true,303);exit;
    }
    $filtrar=$esGlobal?'':' AND m.almacen_id='.(int)$almacenSesion;
    $rows=$db->query("SELECT m.id,m.folio,m.almacen_id,m.fecha,m.created_at,m.referencia,m.tipo_operacion,m.tipo_movimiento,m.observaciones,m.cancelado,
        rcv.estado,COALESCE((SELECT SUM(cantidad_enviada-cantidad_recibida) FROM erp_resurtidos_pos_detalle WHERE movimiento_id=m.id),0) pendiente,
        COALESCE((SELECT SUM(cantidad_recibida) FROM erp_resurtidos_pos_detalle WHERE movimiento_id=m.id),0) recibidas
        FROM movimientos m LEFT JOIN erp_resurtidos_pos_recepciones rcv ON rcv.movimiento_id=m.id
        WHERE m.tipo_movimiento='SALIDA' AND m.tipo_operacion='RESURTIDO' AND m.cancelado=0
          AND m.almacen_id IN (1,3) AND m.created_at >= ".$db->quote($inicio).$filtrar."
        ORDER BY m.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $m){$r=rp_resurtido($db,$m);if($r){$m['resurtido_folio']=$r['folio'];$lista[]=$m;}}
    if($ver>0){
        $st=$db->prepare('SELECT m.* FROM movimientos m WHERE m.id=? AND m.created_at>=?');$st->execute([$ver,$inicio]);$movimiento=$st->fetch(PDO::FETCH_ASSOC)?:null;
        if(!$movimiento||(!$esGlobal && (int)$movimiento['almacen_id']!==$almacenSesion))throw new RuntimeException('Salida fuera de tu almacén o anterior a la integración.');
        $rel=rp_resurtido($db,$movimiento);if(!$rel)throw new RuntimeException('La salida seleccionada no corresponde a un resurtido válido no cancelado.');
        $items=rp_detalles($db,$ver);
        $st=$db->prepare('SELECT producto_id,cantidad_enviada,cantidad_recibida FROM erp_resurtidos_pos_detalle WHERE movimiento_id=?');$st->execute([$ver]);$rMap=[];
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row)$rMap[(int)$row['producto_id']]=$row;
        foreach($items as &$d){$v=$rMap[(int)$d['producto_id']]??null;$d['recibido']=(int)($v['cantidad_recibida']??0);$d['pendiente']=max(0,(int)$d['cantidad']-$d['recibido']);if($v && (int)$v['cantidad_enviada']!==(int)$d['cantidad'])throw new RuntimeException('Los productos de esta salida se modificaron y requieren auditoría.');}unset($d);
        $st=$db->prepare('SELECT c.creado_en,u.nombre,c.observaciones,cd.producto_id,cd.cantidad,p.descripcion FROM erp_resurtidos_pos_confirmaciones c JOIN usuarios u ON u.id=c.usuario_id JOIN erp_resurtidos_pos_confirmaciones_detalle cd ON cd.confirmacion_id=c.id JOIN productos p ON p.id=cd.producto_id WHERE c.movimiento_id=? ORDER BY c.id DESC');$st->execute([$ver]);$historial=$st->fetchAll(PDO::FETCH_ASSOC);
    }
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();error_log('ERP resurtidos POS: '.$e->getMessage());$error=$e instanceof RuntimeException?$e->getMessage():'No se pudo procesar el resurtido. Comprueba la migración y registros de PHP.';}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ERP G&amp;D · Resurtidos a Farmacia</title>
<style>*{box-sizing:border-box}body{margin:0;background:#eef3f8;color:#173a5d;font:15px system-ui,Segoe UI,sans-serif}header{background:#103f72;color:#fff;padding:23px max(16px,calc((100% - 1100px)/2));border-bottom:4px solid #d5344b}h1{margin:0 0 4px;font-size:25px}main{max-width:1130px;margin:20px auto;padding:0 14px}.card{background:#fff;border-radius:13px;padding:20px;margin-bottom:16px;box-shadow:0 5px 22px #16345a10}.info{background:#e6f1fd;border-radius:10px;padding:13px;margin:14px 0}.danger{background:#fff0e8;border-left:4px solid #c66d24}.message{background:#e3f6e9;border-radius:10px;padding:12px;margin:12px 0}.error{background:#ffe5e7}.muted{color:#5b748c;font-size:13px}.btn,button{background:#1766b1;color:#fff;border:0;border-radius:9px;padding:9px 13px;font:600 14px system-ui;cursor:pointer;text-decoration:none;display:inline-block}.btn.alt{background:#e8f0f9;color:#144c81}table{border-collapse:collapse;width:100%;min-width:680px}th,td{text-align:left;padding:11px;border-bottom:1px solid #dce6f0;font-size:13px}th{background:#14578e;color:#fff}.table{overflow:auto}input[type=number],textarea{padding:10px;border:1px solid #b6cce0;border-radius:8px;font:inherit;max-width:125px}textarea{max-width:none;width:100%;min-height:74px}label{font-weight:600}input[type=checkbox]{width:18px;height:18px;vertical-align:middle}.state{font-size:12px;font-weight:700;padding:5px 9px;border-radius:12px;background:#edf3f9;display:inline-block}nav.links{display:flex;gap:10px;flex-wrap:wrap} .toprow{display:flex;justify-content:space-between;gap:15px;align-items:center;flex-wrap:wrap}@media(max-width:700px){header h1{font-size:20px}.card{padding:13px}}</style></head><body>
<?php if(is_file(__DIR__.'/../app/views/layouts/erp_barra_sesion.php'))require __DIR__.'/../app/views/layouts/erp_barra_sesion.php'; ?>
<header><h1>ERP G&amp;D · Resurtidos a Farmacia</h1><div>Recepción en POS de salidas de Almacén · Ciudad Hidalgo y Tuxtla Gutiérrez</div></header><main>
<section class="card"><nav class="links"><a class="btn alt" href="resurtidos.php">← Resurtidos</a><a class="btn alt" href="erp_ordenes_compra.php">Órdenes de compra</a></nav>
<div class="info"><b>Cómo funciona:</b> Almacén ya descontó las piezas al registrar su SALIDA. Cuando Farmacia confirma lo que recibió, este módulo suma <b>solo esas piezas al CONTEO FÍSICO de Farmacia</b>, sin modificar el inventario general POS. Si llegaron menos, las restantes quedan pendientes de aclaración o recepción complementaria.</div>
<div class="info danger"><b>Seguridad:</b> solo aparecen salidas tipo RESURTIDO posteriores a la instalación, vinculadas a solicitudes reales. <b>Los tickets de venta NO se suman al POS.</b> Primero registra un conteo físico inicial de Farmacia. Revisa las confirmaciones de resurtidos anteriores: podían haber sumado indebidamente al POS general.</div>
<p class="muted">Inicio de integración: <?=rp_e($inicio?:'No configurado')?> · Rol: <?=rp_e($rol)?> · <?= $puedeConfirmar?'Puedes confirmar recepciones.':'Puedes consultar; Gerencia o Dirección debe confirmar.' ?></p>
</section>
<?php if($error):?><div class="message error" role="alert"><?=rp_e($error)?></div><?php endif;?><?php if($exito):?><div class="message" role="status"><?=rp_e($exito)?></div><?php endif;?>
<section class="card"><h2>Salidas de resurtido pendientes / recientes</h2><div class="table"><table><thead><tr><th>Salida</th><th>Resurtido</th><th>Fecha</th><th>Almacén</th><th>Farmacia</th><th>Estado</th><th>Acción</th></tr></thead><tbody>
<?php foreach($lista as $l):?><tr><td><b><?=rp_e($l['folio'])?></b></td><td><?=rp_e($l['resurtido_folio'])?></td><td><?=rp_hora($l['fecha'])?></td><td><?=((int)$l['almacen_id']===1?'Ciudad Hidalgo':'Tuxtla')?></td><td><?=((int)$l['almacen_id']===1?'Ciudad Hidalgo':'Tuxtla')?></td><td><span class="state"><?=rp_e($l['estado']??'PENDIENTE')?><?php if($l['estado']):?> · Pendientes: <?=(int)$l['pendiente']?><?php endif;?></span></td><td><a class="btn" href="?ver=<?=(int)$l['id']?>">Ver / recibir</a></td></tr><?php endforeach;if(!$lista):?><tr><td colspan="7">No hay resurtidos elegibles desde el inicio de esta integración. Genera una salida nueva de RESURTIDO desde el módulo actual.</td></tr><?php endif;?></tbody></table></div></section>
<?php if($movimiento&&$rel):?>
<section class="card"><div class="toprow"><h2>Recibir salida <?=rp_e($movimiento['folio'])?></h2><span class="state">Solicitud <?=rp_e($rel['folio'])?></span></div>
<p>Almacén: <b><?=((int)$movimiento['almacen_id']===1?'Ciudad Hidalgo':'Tuxtla Gutiérrez')?></b> · Fecha: <?=rp_e($movimiento['fecha'])?> · Destino: Farmacia de la misma sucursal</p>
<form method="post" action="erp_resurtidos_pos.php?ver=<?=(int)$movimiento['id']?>" onsubmit="return confirm('¿Confirmas las piezas recibidas? Solo se sumarán al conteo físico de Farmacia. El POS GENERAL no cambia.');">
<input type="hidden" name="csrf" value="<?=rp_e($csrf)?>"><input type="hidden" name="token" value="<?=rp_e($token)?>"><input type="hidden" name="movimiento_id" value="<?=(int)$movimiento['id']?>">
<div class="table"><table><thead><tr><th>Código / Producto</th><th>Enviado en salida</th><th>Ya recibido</th><th>Pendiente</th><th>Recibir ahora</th></tr></thead><tbody>
<?php $hayPendientes=false;foreach($items as $i):$p=(int)$i['pendiente'];if($p>0)$hayPendientes=true;?><tr><td><?=rp_e($i['codigo'])?><br><b><?=rp_e($i['descripcion'])?></b></td><td><?=(int)$i['cantidad']?></td><td><?=(int)$i['recibido']?></td><td><?=$p?></td><td><?php if($puedeConfirmar&&$p>0):?><input type="number" name="recibida[<?=(int)$i['producto_id']?>]" value="<?=$p?>" min="0" max="<?=$p?>" step="1" required><?php else:?>—<?php endif;?></td></tr><?php endforeach;?></tbody></table></div>
<?php if($puedeConfirmar&&$hayPendientes):?><label for="observaciones">Observaciones de entrega (obligatorias si es parcial)</label><textarea id="observaciones" name="observaciones" maxlength="750" placeholder="Ej. Se recibieron 30 de 40 piezas; las 10 restantes están pendientes de entregar."></textarea>
<p><label><input type="checkbox" name="confirmar" value="1" required> He contado las piezas y confirmo que no fueron ingresadas ya al POS.</label></p><button type="submit">Confirmar recepción física en Farmacia</button><?php else:?><p class="muted"><?=!$hayPendientes?'Esta salida ya fue recibida totalmente.':'Tu rol solo permite consultar.'?></p><?php endif;?></form></section>
<section class="card"><h2>Historial de confirmaciones</h2><div class="table"><table><thead><tr><th>Fecha</th><th>Confirmó</th><th>Producto</th><th>Piezas</th><th>Observaciones</th></tr></thead><tbody><?php foreach($historial as $h):?><tr><td><?=rp_e($h['creado_en'])?></td><td><?=rp_e($h['nombre'])?></td><td><?=rp_e($h['descripcion'])?></td><td><?=(int)$h['cantidad']?></td><td><?=rp_e($h['observaciones']??'')?></td></tr><?php endforeach;if(!$historial):?><tr><td colspan="5">Todavía no hay recepciones confirmadas.</td></tr><?php endif;?></tbody></table></div></section>
<?php endif;?></main>
<script src="assets/js/gyd_excel_reportes.js" defer></script>

<?php if (!defined('GYD_HIDE_GLOBAL_NAV')): ?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=4">
<script>window.GYD_NAV_HOME='index.php';window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=4"></script>
<?php endif; ?>
</body></html>
