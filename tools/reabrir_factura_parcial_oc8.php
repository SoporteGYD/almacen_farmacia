<?php
declare(strict_types=1);
/** 
 * Recalcula exclusivamente el estado documental de FAC-<ceros>2/OC8, después
 * de la conciliación ya confirmada. Nunca modifica existencias o revisiones.
 */
if(PHP_SAPI!=='cli')exit(2);
$mode=$argv[1]??'';
if(!in_array($mode,['--check','--apply'],true)||($mode==='--apply'&&count($argv)!==3)){
  fwrite(STDERR,"Uso: php tools/reabrir_factura_parcial_oc8.php --check | --apply ID_ADMIN\n");exit(2);
}
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_facturas.php';
function requireState(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
try{
 $db=(new Database())->connect();$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
 requireState((string)$db->query('SELECT DATABASE()')->fetchColumn()==='prueba','Sólo se permite la base de pruebas llamada prueba.');
 $db->beginTransaction();
 $q=$db->prepare('SELECT id FROM erp_ordenes_compra WHERE id=? FOR UPDATE');$q->execute([8]);requireState((bool)$q->fetchColumn(),'No existe OC8.');
 $q=$db->prepare('SELECT id,orden_id,folio_interno,estado FROM erp_facturas WHERE orden_id=? FOR UPDATE');$q->execute([8]);$fac=$q->fetchAll(PDO::FETCH_ASSOC);
 requireState(count($fac)===1,'El caso OC8 tiene otra distribución de facturas: requiere revisión manual.');
 $f=$fac[0];$fid=(int)$f['id'];
 requireState(preg_match('/^FAC-0*2$/D',(string)$f['folio_interno'])===1,'Folio no es FAC consecutivo 2.');
 $q=$db->prepare("SELECT id,cantidad,movimiento_id FROM erp_oc_conciliacion_eventos WHERE factura_id=? AND orden_id=8 AND producto_id=1394 AND tipo='AJUSTE_FALTANTE'");
 $q->execute([$fid]);$e=$q->fetchAll(PDO::FETCH_ASSOC);
 requireState(count($e)===1&&(int)$e[0]['cantidad']===20,'Falta conciliación auditada de las 20 piezas.');
 $eid=(int)$e[0]['id'];
 $q=$db->query('SELECT estado FROM erp_oc_recepciones WHERE id=6 AND orden_id=8');
 requireState($q->fetchColumn()==='ANULADA','La recepción de prueba ID6 no está anulada.');
 $q=$db->prepare("SELECT COALESCE(SUM(rd.cantidad_piezas),0) FROM erp_oc_recepciones r JOIN erp_oc_recepciones_detalle rd ON rd.recepcion_id=r.id WHERE r.orden_id=8 AND r.estado='REGISTRADA' AND rd.producto_id=1394");
 $q->execute();requireState((int)$q->fetchColumn()===80,'Recepciones vigentes no suman 80.');
 $q=$db->prepare('SELECT existencia FROM producto_existencias WHERE producto_id=1394 AND sucursal=? AND ubicacion=? FOR UPDATE');
 $q->execute(['CIUDAD HIDALGO','R1N1Z01']);requireState((int)$q->fetchColumn()===80,'Stock actual ya no es 80. Revisar antes de cambiar estado.');
 $items=ef_resumen_detalles($db,$fid);
 requireState(count($items)===1 && (int)$items[0]['producto_id']===1394 && (int)$items[0]['cantidad_facturada']===100 && (int)$items[0]['aceptadas']===80,
   'El saldo neto no coincide con 100 facturadas / 80 aceptadas. Comprueba la instalación del helper.');
 $q=$db->prepare('SELECT COUNT(*) FROM erp_oc_facturas_reaperturas WHERE factura_id=? AND conciliacion_evento_id=?');
 $q->execute([$fid,$eid]);$audit=(int)$q->fetchColumn();
 echo "OC8 / {$f['folio_interno']} | Estado: {$f['estado']} | Facturadas: 100 | Aceptadas netas: 80 | Por verificar: 20 | Reaperturas: $audit\n";
 if($audit>0){$db->rollBack();echo "YA REABIERTA. No repetir.\n";exit(0);}
 requireState((string)$f['estado']==='VERIFICADA','La factura ya tiene un estado diferente a VERIFICADA. Detenido.');
 if($mode==='--check'){$db->rollBack();echo "APTO para reapertura DOCUMENTAL, sin cambiar stock. Comprobación sin escritura.\n";exit(0);}
 $adminId=filter_var($argv[2]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
 requireState($adminId!==false,'Indica un ID de administrador numérico real.');
 $q=$db->prepare('SELECT rol,estado FROM usuarios WHERE id=? FOR UPDATE');$q->execute([$adminId]);$admin=$q->fetch(PDO::FETCH_ASSOC);
 requireState($admin && (int)$admin['estado']===1 && in_array(strtoupper(trim((string)$admin['rol'])),['ADMINISTRADOR','ADMIN','DIRECCION'],true),
   'Administrador inactivo o no autorizado.');
 echo "Se cambiará SOLO estado de la factura a EN_REVISION. Escribe: CONFIRMO REABRIR FAC2\n> ";
 requireState(trim((string)fgets(STDIN))==='CONFIRMO REABRIR FAC2','Confirmación cancelada.');
 ef_actualizar_estado($db,$fid);
 $q=$db->prepare('SELECT estado FROM erp_facturas WHERE id=?');$q->execute([$fid]);$nuevo=(string)$q->fetchColumn();
 requireState($nuevo==='EN_REVISION','No se obtuvo EN_REVISION; operación revertida.');
 $motivo='Reapertura documental luego de conciliación auditada de 20 piezas faltantes en OC8; conserva revisión histórica.';
 $q=$db->prepare('INSERT INTO erp_oc_facturas_reaperturas(factura_id,orden_id,conciliacion_evento_id,estado_anterior,estado_nuevo,motivo,usuario_id) VALUES (?,?,?,?,?,?,?)');
 $q->execute([$fid,8,$eid,(string)$f['estado'],$nuevo,$motivo,$adminId]);
 $db->commit();echo "COMPLETADO: estado documental EN_REVISION; 20 pendientes de verificar. Stock sin cambios.\n";
}catch(Throwable $e){if(isset($db)&&$db instanceof PDO&&$db->inTransaction())$db->rollBack();fwrite(STDERR,"DETENIDO: ".$e->getMessage()."\nSin modificaciones confirmadas si ocurrió un error.\n");exit(3);}
