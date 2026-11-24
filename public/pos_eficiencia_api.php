<?php
declare(strict_types=1);
/** Telemetría auxiliar, nunca bloquea ni modifica una venta. */
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
$u=currentUser()?:[];
$rol=strtoupper(trim((string)($u['rol_original']??$u['rol']??'')));
if(!in_array($rol,['CAJERO','ADMINISTRADOR','ADMIN','DIRECCION'],true)) {http_response_code(403);echo json_encode(['error'=>'Sin permiso']);exit;}
function met_out(array $v,int $code=200):never {http_response_code($code);echo json_encode($v,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
try {
 if($_SERVER['REQUEST_METHOD']!=='POST')met_out(['error'=>'Método no permitido'],405);
 $raw=file_get_contents('php://input');if(strlen($raw)>9000)met_out(['error'=>'Tamaño inválido'],413);
 $p=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
 if(!is_array($p) || !hash_equals((string)($_SESSION['pos_csrf']??''),(string)($p['csrf']??'')))met_out(['error'=>'Sesión vencida'],403);
 $token=(string)($p['token']??'');
 if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i',$token))met_out(['error'=>'Identificador inválido'],422);
 $caja=(int)($p['caja_id']??0);$uid=(int)($u['id']??0);
 if($uid<1||$caja<1)met_out(['error'=>'Usuario o caja inválidos'],403);
 $db=(new Database())->connect();
 require_once __DIR__.'/../app/models/PuntoVenta.php';
 $pv=new PuntoVenta();$infoCaja=$pv->cajaAutorizada($u,$caja);
 $sucursal=(int)$infoCaja['id'];
 $action=(string)($p['action']??'');
 if($action==='inicio') {
    $turno=$pv->turno($caja);
    if(!$turno||($turno['estado']??'ABIERTO')!=='ABIERTO')met_out(['error'=>'No hay turno abierto'],422);
    $st=$db->prepare("INSERT INTO erp_pos_eficiencia_cajeros (token,usuario_id,sucursal_id,caja_id,turno_id) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE token=token");
    $st->execute([$token,$uid,$sucursal,$caja,(int)$turno['id']]);
    $st=$db->prepare('SELECT usuario_id,caja_id FROM erp_pos_eficiencia_cajeros WHERE token=?');$st->execute([$token]);$r=$st->fetch(PDO::FETCH_ASSOC);
    if(!$r||(int)$r['usuario_id']!==$uid||(int)$r['caja_id']!==$caja)met_out(['error'=>'Sesión de captura no coincide'],409);
    met_out(['ok'=>true]);
 }
 if($action!=='fin')met_out(['error'=>'Acción desconocida'],422);
 $ventaId=(int)($p['venta_id']??0);
 if($ventaId<1)met_out(['error'=>'Venta no encontrada'],422);
 $db->beginTransaction();
 $q=$db->prepare('SELECT * FROM erp_pos_eficiencia_cajeros WHERE token=? FOR UPDATE');$q->execute([$token]);$m=$q->fetch(PDO::FETCH_ASSOC);
 if(!$m||(int)$m['usuario_id']!==$uid||(int)$m['caja_id']!==$caja){$db->rollBack();met_out(['error'=>'Medición no iniciada'],409);}
 if($m['estado']==='COMPLETADA'){$db->commit();met_out(['ok'=>true,'repetida'=>true]);}
 $q=$db->prepare("SELECT id,sucursal_id,caja_id,turno_id,usuario_id,estado,pagada_en FROM pos_ventas WHERE id=? LIMIT 1");$q->execute([$ventaId]);$sale=$q->fetch(PDO::FETCH_ASSOC);
 if(!$sale||(int)$sale['usuario_id']!==$uid||(int)$sale['caja_id']!==$caja||(int)$sale['sucursal_id']!==$sucursal||(int)$sale['turno_id']!==(int)$m['turno_id']||$sale['estado']!=='PAGADA'){
    $db->rollBack();met_out(['error'=>'La venta no corresponde a la sesión'],409);
 }
 $dt=$db->prepare('SELECT TIMESTAMPDIFF(SECOND,inicio_en,NOW(6)) AS elapsed,TIMESTAMPDIFF(SECOND,inicio_en,?) AS hasta_venta FROM erp_pos_eficiencia_cajeros WHERE id=?');
 $dt->execute([(string)$sale['pagada_en'],(int)$m['id']]);$elapsed=$dt->fetch(PDO::FETCH_ASSOC);
 $max=max(1,min(21600,(int)$elapsed['elapsed']));
 if((int)$elapsed['hasta_venta']< -2 ||(int)$elapsed['hasta_venta']>21600){$db->rollBack();met_out(['error'=>'El inicio de captura no coincide con la venta'],409);}
 // Duraciones procedentes del navegador: aproximadas, validadas contra el tiempo de servidor.
 $secs=static function(string $field,int $cap)use($p,$max):int{return max(0,min($cap,$max+3,(int)($p[$field]??0)));};
 $counts=static function(string $field,int $cap=10000)use($p):int{return max(0,min($cap,(int)($p[$field]??0)));};
 $total=$secs('total_seg',21600);$capture=$secs('captura_seg',21600);$payment=$secs('pago_seg',21600);
 $red=$secs('cobro_red_seg',21600);$idle=$secs('inactivo_seg',21600);$hidden=$secs('oculto_seg',21600);$wait=$secs('gestion_almacen_seg',21600);
 $total=min($total,$max+3);$capture=min($capture,$total);$payment=min($payment,$total);$red=min($red,$total);
 $inactive=min($total,$idle+$hidden);$active=max(0,$total-$inactive);
 // Compatibilidad: instalaciones antiguas no tienen todas las columnas de presentaciones.
 // No debe perderse el cronómetro por faltar una columna opcional del detalle POS.
 $detailCols=$db->query('SHOW COLUMNS FROM pos_venta_detalle')->fetchAll(PDO::FETCH_COLUMN,0);
 $hasPieces=in_array('unidades_descontadas',$detailCols,true);
 $hasFactor=in_array('piezas_por_presentacion',$detailCols,true);
 if($hasPieces && $hasFactor){
   $piecesExpr='COALESCE(SUM(COALESCE(unidades_descontadas,cantidad*COALESCE(piezas_por_presentacion,1))),0)';
 }elseif($hasPieces){
   $piecesExpr='COALESCE(SUM(COALESCE(unidades_descontadas,cantidad)),0)';
 }elseif($hasFactor){
   $piecesExpr='COALESCE(SUM(cantidad*COALESCE(piezas_por_presentacion,1)),0)';
 }else{
   // Sin información histórica de presentaciones, usar únicamente las piezas
   // enviadas por el POS al momento del cobro, sin adivinar el factor de caja.
   $piecesExpr='NULL';
 }
 $qDet=$db->prepare('SELECT COUNT(*) AS lineas, '.$piecesExpr.' AS piezas FROM pos_venta_detalle WHERE venta_id=?');
 $qDet->execute([$ventaId]);$detail=$qDet->fetch(PDO::FETCH_ASSOC)?:[];
 $realPieces=$detail['piezas']===null ? $counts('piezas',10000000) : (int)$detail['piezas'];
 $q=$db->prepare("UPDATE erp_pos_eficiencia_cajeros SET estado='COMPLETADA',venta_id=?,final_en=NOW(6),total_seg=?,captura_seg=?,pago_seg=?,cobro_red_seg=?,inactivo_seg=?,oculto_seg=?,activo_seg=?,gestion_almacen_seg=?,lineas=?,piezas=?,busquedas=?,seleccion_catalogo=?,escaneos=?,selecciones_buscador=?,cambios_cantidad=?,productos_quitados=?,intentos_cobro=?,errores_cobro=?,solicitudes_almacen=?,metodo_pago=? WHERE id=? AND estado='INICIADA'");
 $q->execute([$ventaId,$total,$capture,$payment,$red,$idle,$hidden,$active,$wait,(int)($detail['lineas']??0),$realPieces,$counts('busquedas'),$counts('seleccion_catalogo'),$counts('escaneos'),$counts('selecciones_buscador'),$counts('cambios_cantidad'),$counts('productos_quitados'),$counts('intentos_cobro'),$counts('errores_cobro'),$counts('solicitudes_almacen'),substr((string)($p['metodo_pago']??''),0,20),(int)$m['id']]);
 $db->commit();met_out(['ok'=>true]);
} catch(Throwable $e) {
 if(isset($db)&&$db instanceof PDO&&$db->inTransaction())$db->rollBack();
 error_log('POS eficiencia [registro]: '.get_class($e).' '.$e->getMessage());met_out(['error'=>'No se pudo guardar el tiempo; revisa error.log de PHP (POS eficiencia).'],500);
}
