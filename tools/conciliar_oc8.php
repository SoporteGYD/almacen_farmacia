<?php
declare(strict_types=1);
/** Conciliación APARTE del stock de la factura original. Sólo OC 8 / aplicación 3.
 * Comandos: --check | --ajustar ID_ADMIN | --reponer ID_ADMIN
 * No se ejecuta desde el navegador. Sólo XAMPP / base de pruebas.
 */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Sólo CLI');}
$modo=$argv[1]??'--check';
if(!in_array($modo,['--check','--ajustar','--reponer'],true)){fwrite(STDERR,"Uso: php tools/conciliar_oc8.php --check | --ajustar ID_ADMIN | --reponer ID_ADMIN\n");exit(2);}
require_once __DIR__.'/../app/config/database.php';
const AP_ID=3;
const OC_ID=8;
const PID=1394;
const ALM=1;
const UBICACION='R1N1Z01';
const RECIBIDO_INICIAL=80;
const FACTURADO=100;
const DIFERENCIA=20;
function pedirConfirmacion(string $modo):void {
    echo "\nATENCIÓN: este paso registra un movimiento REAL de inventario en la BD PRUEBA.\n";
    echo "Escribe exactamente: CONFIRMO ".($modo==='--ajustar'?'AJUSTE 20':'REPOSICION 20')."\n> ";
    $respuesta=trim((string)fgets(STDIN));
    if($respuesta!==($modo==='--ajustar'?'CONFIRMO AJUSTE 20':'CONFIRMO REPOSICION 20')){
      throw new RuntimeException('Confirmación cancelada. No se efectuaron cambios.');
    }
}
try{
 $db=(new Database())->connect();$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
 if((string)$db->query('SELECT DATABASE()')->fetchColumn()!=='prueba')
    throw new RuntimeException('Protección: este archivo sólo opera sobre la base local llamada prueba.');
 $db->beginTransaction();
 $operadorId=0;
 if($modo!=='--check'){
    $operadorId=(int)($argv[2]??0);
    if($operadorId<1)throw new RuntimeException('Especifica el ID del administrador: --ajustar ID / --reponer ID.');
    $uq=$db->prepare('SELECT nombre,rol,estado FROM usuarios WHERE id=?');
    $uq->execute([$operadorId]);$actor=$uq->fetch(PDO::FETCH_ASSOC);
    if(!$actor || (int)$actor['estado']!==1 || !in_array(strtoupper((string)$actor['rol']),['ADMINISTRADOR','ADMIN','DIRECCION'],true))
       throw new RuntimeException('La cuenta indicada no está activa con rol ADMINISTRADOR o DIRECCION.');
    echo 'Responsable ERP: '.(string)$actor['nombre'].' (ID '.$operadorId.")\n";
 }
 $q=$db->prepare('SELECT a.*,f.orden_id,f.folio_interno, f.numero_factura FROM erp_facturas_stock_aplicaciones a JOIN erp_facturas f ON f.id=a.factura_id WHERE a.id=? FOR UPDATE');
 $q->execute([AP_ID]);$a=$q->fetch(PDO::FETCH_ASSOC);
 if(!$a || (int)$a['orden_id']!==OC_ID || (int)$a['producto_id']!==PID || (int)$a['cantidad']!==FACTURADO || $a['destino']!=='ALMACEN'
    || (int)$a['almacen_id']!==ALM || $a['ubicacion']!==UBICACION || (int)$a['movimiento_id']!==1590){
    throw new RuntimeException('Los datos ya no coinciden con el diagnóstico original. Se detuvo por seguridad.');
 }
 $q=$db->prepare('SELECT m.id,m.tipo_movimiento,m.cancelado,md.cantidad,md.lote_id FROM movimientos m JOIN movimiento_detalle md ON md.movimiento_id=m.id WHERE m.id=? AND m.almacen_id=? AND md.producto_id=? AND md.ubicacion=? FOR UPDATE');
 $q->execute([1590,ALM,PID,UBICACION]);$original=$q->fetchAll(PDO::FETCH_ASSOC);
 if(count($original)!==1 || $original[0]['tipo_movimiento']!=='ENTRADA' || (int)$original[0]['cancelado']!==0 || (int)$original[0]['cantidad']!==FACTURADO || $original[0]['lote_id']!==null)
    throw new RuntimeException('La entrada original cambió o tiene lote asociado: requiere conciliación manual de lotes.');
 $q=$db->prepare("SELECT COALESCE(SUM(rd.cantidad_piezas),0) FROM erp_oc_recepciones r JOIN erp_oc_recepciones_detalle rd ON rd.recepcion_id=r.id JOIN erp_ordenes_compra_detalle od ON od.id=rd.orden_detalle_id AND od.orden_id=r.orden_id AND od.producto_id=rd.producto_id WHERE r.orden_id=? AND r.estado='REGISTRADA' AND rd.producto_id=?");
 $q->execute([OC_ID,PID]);$recibidas=(int)$q->fetchColumn();
 $q=$db->prepare('SELECT existencia FROM producto_existencias WHERE producto_id=? AND sucursal=? AND ubicacion=? FOR UPDATE');
 $q->execute([PID,'CIUDAD HIDALGO',UBICACION]);$rows=$q->fetchAll(PDO::FETCH_COLUMN);
 if(count($rows)!==1)throw new RuntimeException('No hay una única fila de existencia para la ubicación esperada. Se detuvo.');
 $stock=(int)$rows[0];
 $q=$db->prepare('SELECT tipo,cantidad,movimiento_id,stock_antes,stock_despues FROM erp_oc_conciliacion_eventos WHERE aplicacion_id=? ORDER BY id FOR UPDATE');
 $q->execute([AP_ID]);$eventos=$q->fetchAll(PDO::FETCH_ASSOC);$tipos=[];
 foreach($eventos as $e){if(isset($tipos[$e['tipo']]))throw new RuntimeException('Eventos duplicados detectados.');$tipos[$e['tipo']]=$e;}
 if(isset($tipos['REPOSICION'])&&!isset($tipos['AJUSTE_FALTANTE']))throw new RuntimeException('Historial de reposición incompleto.');
 $neto=FACTURADO-(isset($tipos['AJUSTE_FALTANTE'])?DIFERENCIA:0)+(isset($tipos['REPOSICION'])?DIFERENCIA:0);
 echo "OC-00000008 / FAC-000002 / NEUROBION producto 1394\n";
 echo "Factura: ".FACTURADO.". Recepciones registradas: $recibidas. Aplicación original: 100 (mov. 1590).\n";
 echo "Existencia ACTUAL R1N1Z01: $stock. Aplicado NETO tras conciliaciones: $neto.\n";
 echo "Corrección de faltante: ".(isset($tipos['AJUSTE_FALTANTE'])?'YA REGISTRADA':'PENDIENTE').". Reposición: ".(isset($tipos['REPOSICION'])?'YA REGISTRADA':'PENDIENTE').".\n";
 if($modo==='--check'){ $db->rollBack(); echo "CONSULTA terminada. Sin modificaciones.\n";exit(0); }
 if($modo==='--ajustar'){
    if(isset($tipos['AJUSTE_FALTANTE']))throw new RuntimeException('Ajuste ya aplicado; no se puede repetir.');
    if($recibidas!==RECIBIDO_INICIAL || $stock!==FACTURADO)throw new RuntimeException('No coinciden recepción 80 / stock 100. Revisar manualmente.');
    $q=$db->prepare('SELECT COUNT(*) FROM movimientos m JOIN movimiento_detalle d ON d.movimiento_id=m.id WHERE d.producto_id=? AND m.almacen_id=? AND m.id>? AND m.cancelado=0');
    $q->execute([PID,ALM,1590]);if((int)$q->fetchColumn()>0)throw new RuntimeException('Hay movimientos posteriores. No se puede ajustar automáticamente.');
    $tipo='AJUSTE_FALTANTE';$movTipo='SALIDA';$cantidadAntes=FACTURADO;$despues=RECIBIDO_INICIAL;$folio='AJ-ERP-OC8-20';
    $nota='Conciliacion auditada OC-00000008 FAC-000002: 100 aplicadas, 80 recibidas; 20 pendientes de recepcion.';
 }else{
    if(!isset($tipos['AJUSTE_FALTANTE']) || isset($tipos['REPOSICION']))throw new RuntimeException('Debe existir sólo el ajuste previo, sin reposición anterior.');
    if($recibidas!==FACTURADO || $stock!==RECIBIDO_INICIAL)throw new RuntimeException('Para reponer deben constar 100 recibidas y 80 de stock en R1N1Z01. Revisa si hubo movimientos.');
    $q=$db->prepare('SELECT COUNT(*) FROM movimientos m JOIN movimiento_detalle d ON d.movimiento_id=m.id WHERE d.producto_id=? AND m.almacen_id=? AND m.id>? AND m.id<>? AND m.cancelado=0');
    $q->execute([PID,ALM,(int)$tipos['AJUSTE_FALTANTE']['movimiento_id'],(int)$tipos['AJUSTE_FALTANTE']['movimiento_id']]);
    if((int)$q->fetchColumn()>0)throw new RuntimeException('Hay movimientos posteriores al ajuste; concilia antes de reponer.');
    $tipo='REPOSICION';$movTipo='ENTRADA';$cantidadAntes=RECIBIDO_INICIAL;$despues=FACTURADO;$folio='REP-ERP-OC8-20';
    $nota='Reposicion de 20 faltantes OC-00000008 FAC-000002; ya recibidas fisicamente. Sin factura nueva.';
 }
 // La confirmación es deliberadamente posterior a las comprobaciones, pero anterior a las escrituras.
 pedirConfirmacion($modo);
 $q=$db->prepare('SELECT COUNT(*) FROM movimientos WHERE folio=?');$q->execute([$folio]);
 if((int)$q->fetchColumn()>0)throw new RuntimeException('Folio reservado ya existe. No se registra nada.');
 $u=getenv('USERNAME')?:getenv('USER')?:'operador_CLI';
 $q=$db->prepare('INSERT INTO movimientos(folio,tipo_movimiento,fecha,almacen_id,usuario_id,proveedor_id,referencia,tipo_operacion,observaciones) VALUES (?,?,NOW(),?,?,?,?,?,?)');
 // Conserva responsable ERP histórico de la aplicación. El usuario del equipo se registra por separado.
 $q->execute([$folio,$movTipo,ALM,$operadorId,null,'OC-00000008 / FAC-000002','AJUSTE',$nota]);
 $movId=(int)$db->lastInsertId();
 $q=$db->prepare('INSERT INTO movimiento_detalle(movimiento_id,producto_id,lote_id,cantidad,costo_unitario,precio_unitario,ubicacion) VALUES (?,?,NULL,?,?,0,?)');
 // Movimiento contable inverso al ERP: SALIDA de 20 / ENTRADA de 20.
 $q->execute([$movId,PID,DIFERENCIA,'0.00',UBICACION]);
 $q=$db->prepare('UPDATE producto_existencias SET existencia=?,updated_at=NOW() WHERE producto_id=? AND sucursal=? AND ubicacion=? AND existencia=?');
 $q->execute([$despues,PID,'CIUDAD HIDALGO',UBICACION,$cantidadAntes]);
 if($q->rowCount()!==1)throw new RuntimeException('El stock cambió concurrentemente. Se revierte todo.');
 $q=$db->prepare('INSERT INTO erp_oc_conciliacion_eventos(aplicacion_id,orden_id,factura_id,producto_id,almacen_id,ubicacion,tipo,cantidad,movimiento_id,stock_antes,stock_despues,evidencia,operado_por) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
 $q->execute([AP_ID,OC_ID,(int)$a['factura_id'],PID,ALM,UBICACION,$tipo,DIFERENCIA,$movId,$cantidadAntes,$despues,$nota,substr((string)$u.'; ERP user id '.$operadorId,0,120)]);
 $db->commit();
 echo "Movimiento registrado #$movId ($folio). Stock: $cantidadAntes -> $despues. Auditoría creada.\n";
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();fwrite(STDERR,"DETENIDO: ".$e->getMessage()."\n");exit(1);}
