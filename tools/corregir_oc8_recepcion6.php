<?php
/**
 * Caso exclusivamente de PRUEBAS: OC-00000008 / FAC-000002.
 * Anula recepcion ID6 (20 piezas de prueba) y concilia -20 piezas en R1N1Z01,
 * EN UNA SOLA TRANSACCION con movimiento nuevo y doble auditoria.
 * No elimina registros, no edita movimientos historicos, no modifica precios.
 * Uso: php tools/corregir_oc8_recepcion6.php --check
 *      php tools/corregir_oc8_recepcion6.php --aplicar ID_ADMIN
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo CLI'); }
$modo = $argv[1] ?? '--check';
if (!in_array($modo, ['--check', '--aplicar'], true) || ($modo === '--aplicar' && count($argv) !== 3)) {
    fwrite(STDERR, "Uso: php tools/corregir_oc8_recepcion6.php --check | --aplicar ID_ADMIN\n"); exit(2);
}
require_once __DIR__.'/../app/config/database.php';
const OC8_ORDEN=8;
const OC8_FACTURA='FAC-000002';
const OC8_APLICACION=3;
const OC8_PRODUCTO=1394;
const OC8_ALMACEN=1;
const OC8_UBICACION='R1N1Z01';
const OC8_SUCURSAL='CIUDAD HIDALGO';
const OC8_MOV_ORIGINAL=1590;
const OC8_REC_VALIDA=5;
const OC8_REC_PRUEBA=6;
const OC8_DETALLE=10;
const OC8_REC_PIEZAS=80;
const OC8_ANULAR_PIEZAS=20;
const OC8_FACTURADAS=100;
const OC8_FOLIO_AJUSTE='AJ-ERP-OC8-PRUEBA-20';

function failOc8(string $reason): never { throw new RuntimeException($reason); }
function oneOc8(PDO $db, string $sql, array $param=[]): array {
    $s=$db->prepare($sql);$s->execute($param);$rs=$s->fetchAll(PDO::FETCH_ASSOC);
    if(count($rs)!==1)failOc8('Se esperaba exactamente una fila: '.preg_replace('/\s+/', ' ', substr($sql, 0, 100)));
    return $rs[0];
}
function assertOc8(bool $condition, string $message): void { if (!$condition) failOc8($message); }
function confirmOc8(): void {
    fwrite(STDOUT, "\nSe va a ANULAR la recepcion 6 (20 piezas de prueba) y registrar un ajuste de SALIDA de 20 en R1N1Z01.\n");
    fwrite(STDOUT, "Se conservara la factura FAC-000002 y el movimiento 1590.\n");
    fwrite(STDOUT, "Escribe exactamente: CONFIRMO OC8 PRUEBA 20\n> ");
    $line=trim((string)fgets(STDIN));
    assertOc8($line==='CONFIRMO OC8 PRUEBA 20', 'Confirmacion cancelada. Ningun cambio aplicado.');
}
try {
    $db=(new Database())->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
    assertOc8((string)$db->query('SELECT DATABASE()')->fetchColumn()==='prueba', 'SOLO se permite base de datos prueba.');
    $db->beginTransaction();
    // Bloquear orden y fila exacta de recibo para evitar escrituras concurrentes.
    $order=oneOc8($db,'SELECT id FROM erp_ordenes_compra WHERE id=? FOR UPDATE',[OC8_ORDEN]);
    $recVal=oneOc8($db,'SELECT id,orden_id,estado,destino FROM erp_oc_recepciones WHERE id=? FOR UPDATE',[OC8_REC_VALIDA]);
    $recPrueba=oneOc8($db,'SELECT id,orden_id,estado,destino FROM erp_oc_recepciones WHERE id=? FOR UPDATE',[OC8_REC_PRUEBA]);
    foreach([$recVal,$recPrueba] as $rec) assertOc8((int)$rec['orden_id']===OC8_ORDEN,'Recepcion pertenece a otra orden.');
    assertOc8((string)$recVal['estado']==='REGISTRADA','La recepcion ID5 debe estar REGISTRADA.');
    assertOc8((string)$recVal['destino']===(string)$recPrueba['destino'],'Las recepciones tienen distinto destino.');
    assertOc8(in_array((string)$recVal['destino'],['HIDALGO_BODEGA','HIDALGO_FARMACIA'],true),'El destino no pertenece a Ciudad Hidalgo. Verificar antes de modificar.');
    // Validar que solo existen 80 + 20 piezas de este producto en las dos recepciones conocidas.
    $st=$db->prepare('SELECT r.id AS rec_id,r.estado,rd.orden_detalle_id,rd.producto_id,rd.cantidad_piezas
     FROM erp_oc_recepciones r JOIN erp_oc_recepciones_detalle rd ON rd.recepcion_id=r.id
     WHERE r.orden_id=? AND rd.producto_id=? ORDER BY r.id,rd.id FOR UPDATE');
    $st->execute([OC8_ORDEN,OC8_PRODUCTO]);$receps=$st->fetchAll(PDO::FETCH_ASSOC);
    assertOc8(count($receps)===2 && (int)$receps[0]['rec_id']===OC8_REC_VALIDA && (int)$receps[1]['rec_id']===OC8_REC_PRUEBA,
      'Hay recepciones adicionales o faltantes. No se opera automaticamente.');
    assertOc8((int)$receps[0]['orden_detalle_id']===OC8_DETALLE && (int)$receps[1]['orden_detalle_id']===OC8_DETALLE,
      'El detalle de la OC no coincide con ID10.');
    assertOc8((int)$receps[0]['cantidad_piezas']===OC8_REC_PIEZAS && (int)$receps[1]['cantidad_piezas']===OC8_ANULAR_PIEZAS,
      'Las cantidades ya no son 80 y 20.');
    // No anular parcialmente una recepcion multiproducto.
    $st=$db->prepare('SELECT COUNT(*) FROM erp_oc_recepciones_detalle WHERE recepcion_id=?');
    $st->execute([OC8_REC_PRUEBA]);
    assertOc8((int)$st->fetchColumn()===1,'La recepcion de prueba incluye otros productos: detener.');
    $app=oneOc8($db,'SELECT a.id,a.factura_id,a.cantidad,a.producto_id,a.almacen_id,a.destino,a.ubicacion,a.movimiento_id,f.orden_id,f.folio_interno
      FROM erp_facturas_stock_aplicaciones a JOIN erp_facturas f ON f.id=a.factura_id WHERE a.id=? FOR UPDATE',[OC8_APLICACION]);
    // El folio FAC usa anchuras distintas en versiones anteriores (p. ej. FAC-0000002).
    // Comparar el consecutivo numerico exacto, manteniendo todos los controles
    // de IDs, orden, producto, cantidad, almacen, ubicacion y movimiento.
    $folioReal = strtoupper(trim((string)$app['folio_interno']));
    $folioCoincide = preg_match('/^FAC-0*2$/D', $folioReal) === 1;
    $campos = [
      'orden_id' => [(int)$app['orden_id'], OC8_ORDEN],
      'producto_id' => [(int)$app['producto_id'], OC8_PRODUCTO],
      'almacen_id' => [(int)$app['almacen_id'], OC8_ALMACEN],
      'cantidad' => [(int)$app['cantidad'], OC8_FACTURADAS],
      'destino' => [(string)$app['destino'], 'ALMACEN'],
      'ubicacion' => [(string)$app['ubicacion'], OC8_UBICACION],
      'movimiento_id' => [(int)$app['movimiento_id'], OC8_MOV_ORIGINAL],
    ];
    $diferencias = [];
    foreach ($campos as $campo => [$real, $esperado]) {
        if ($real !== $esperado) $diferencias[] = "$campo: real=" . var_export($real, true) . ", esperado=" . var_export($esperado, true);
    }
    if (!$folioCoincide) {
        $diferencias[] = "folio_interno: real=" . var_export($folioReal, true) . ", se esperaba FAC-<ceros>2";
    }
    assertOc8($diferencias === [], 'No coincide la aplicacion de factura original: ' . implode('; ', $diferencias));
    echo "OK: aplicacion ID ".OC8_APLICACION."; folio real {$folioReal}; documento FAC consecutivo 2.\n";
    $move=oneOc8($db,'SELECT m.id,m.cancelado,m.tipo_movimiento,md.cantidad,md.ubicacion,md.lote_id
     FROM movimientos m JOIN movimiento_detalle md ON md.movimiento_id=m.id
     WHERE m.id=? AND m.almacen_id=? AND md.producto_id=? AND md.ubicacion=? FOR UPDATE',
      [OC8_MOV_ORIGINAL,OC8_ALMACEN,OC8_PRODUCTO,OC8_UBICACION]);
    assertOc8((int)$move['cancelado']===0 && $move['tipo_movimiento']==='ENTRADA' && (int)$move['cantidad']===OC8_FACTURADAS && $move['lote_id']===null,
      'La entrada original fue cancelada, alterada o usa lote. Requiere revision manual.');
    $stock=oneOc8($db,'SELECT existencia FROM producto_existencias WHERE producto_id=? AND sucursal=? AND ubicacion=? FOR UPDATE',
      [OC8_PRODUCTO,OC8_SUCURSAL,OC8_UBICACION]);
    $stockNow=(int)$stock['existencia'];
    $st=$db->prepare('SELECT COUNT(*) FROM erp_oc_conciliacion_eventos WHERE aplicacion_id=?');
    $st->execute([OC8_APLICACION]);$nAjustes=(int)$st->fetchColumn();
    $st=$db->prepare('SELECT COUNT(*) FROM erp_oc_recepciones_anulaciones WHERE recepcion_id=?');
    $st->execute([OC8_REC_PRUEBA]);$nAnulaciones=(int)$st->fetchColumn();
    $st=$db->prepare('SELECT COUNT(*) FROM movimientos WHERE folio=?');
    $st->execute([OC8_FOLIO_AJUSTE]);$nFolio=(int)$st->fetchColumn();
    $st=$db->prepare('SELECT COUNT(*) FROM movimientos m JOIN movimiento_detalle d ON d.movimiento_id=m.id
      WHERE d.producto_id=? AND m.almacen_id=? AND m.id>? AND m.cancelado=0');
    $st->execute([OC8_PRODUCTO,OC8_ALMACEN,OC8_MOV_ORIGINAL]);$movDespues=(int)$st->fetchColumn();
    $recibidoVigente=($recPrueba['estado']==='REGISTRADA') ? 100 : 80;
    echo "CASO OC-00000008 / FAC-000002 / NEUROBION 1394\n";
    echo "Recepcion 5: {$recVal['estado']} (80). Recepcion 6: {$recPrueba['estado']} (20 de prueba).\n";
    echo "Registradas vigentes: $recibidoVigente. Stock R1N1Z01: $stockNow.\n";
    echo "Eventos ajuste: $nAjustes. Anulaciones auditadas: $nAnulaciones. Movimiento con folio reservado: $nFolio.\n";
    echo "Movimientos posteriores producto 1394/almacen 1: $movDespues.\n";
    $already=(string)$recPrueba['estado']==='ANULADA' && $stockNow===80 && $nAjustes===1 && $nAnulaciones===1 && $nFolio===1;
    if($already){
        $db->rollBack();echo "ESTADO: YA CORREGIDO. No repetir operacion.\n";exit(0);
    }
    $ready=(string)$recPrueba['estado']==='REGISTRADA' && $stockNow===100 && $nAjustes===0 && $nAnulaciones===0 && $nFolio===0 && $movDespues===0;
    echo $ready ? "ESTADO: APTO para conciliacion de prueba en una sola transaccion.\n" : "ESTADO: NO APTO; hay diferencias y requiere revision antes de cualquier escritura.\n";
    if($modo==='--check'){$db->rollBack();echo "COMPROBACION TERMINADA. Ninguna escritura.\n";exit($ready?0:1);}
    assertOc8($ready,'Proteccion: las condiciones ya no coinciden con el diagnostico.');
    $adminId=(int)($argv[2]??0);
    assertOc8($adminId>0,'Indica un ID de administrador real.');
    $admin=oneOc8($db,'SELECT id,nombre,rol,estado FROM usuarios WHERE id=? FOR UPDATE',[$adminId]);
    assertOc8((int)$admin['estado']===1 && in_array(strtoupper(trim((string)$admin['rol'])),['ADMINISTRADOR','ADMIN','DIRECCION'],true),
      'El usuario no esta activo como administrador o Direccion.');
    echo 'RESPONSABLE: '.$admin['nombre'].' (ID '.$adminId.")\n";
    confirmOc8();
    // Todas las escrituras se realizan en UNA transaccion; cualquier fallo revierte TODO.
    $st=$db->prepare("UPDATE erp_oc_recepciones SET estado='ANULADA' WHERE id=? AND orden_id=? AND estado='REGISTRADA'");
    $st->execute([OC8_REC_PRUEBA,OC8_ORDEN]);
    assertOc8($st->rowCount()===1,'No se pudo anular la recepcion de prueba.');
    $nota='Prueba local OC-00000008 FAC-000002: anular recepcion #6 de 20 no recibidas. Conciliar 100 aplicadas vs 80 recibidas.';
    $st=$db->prepare('INSERT INTO movimientos (folio,tipo_movimiento,fecha,almacen_id,usuario_id,proveedor_id,referencia,tipo_operacion,observaciones)
       VALUES (?, ?, NOW(), ?, ?, NULL, ?, ?, ?)');
    $st->execute([OC8_FOLIO_AJUSTE,'SALIDA',OC8_ALMACEN,$adminId,'OC-00000008 / FAC-000002','AJUSTE',$nota]);
    $movId=(int)$db->lastInsertId();
    assertOc8($movId>0,'No fue posible generar folio de movimiento.');
    $st=$db->prepare('INSERT INTO movimiento_detalle(movimiento_id,producto_id,lote_id,cantidad,costo_unitario,precio_unitario,ubicacion)
       VALUES (?,?,NULL,?,?,0,?)');
    $st->execute([$movId,OC8_PRODUCTO,OC8_ANULAR_PIEZAS,'0.00',OC8_UBICACION]);
    $st=$db->prepare('UPDATE producto_existencias SET existencia=?, updated_at=NOW() WHERE producto_id=? AND sucursal=? AND ubicacion=? AND existencia=?');
    $st->execute([80,OC8_PRODUCTO,OC8_SUCURSAL,OC8_UBICACION,100]);
    assertOc8($st->rowCount()===1,'La existencia cambio. Se revierte toda la transaccion.');
    $st=$db->prepare('INSERT INTO erp_oc_conciliacion_eventos
     (aplicacion_id,orden_id,factura_id,producto_id,almacen_id,ubicacion,tipo,cantidad,movimiento_id,stock_antes,stock_despues,evidencia,operado_por)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $st->execute([OC8_APLICACION,OC8_ORDEN,(int)$app['factura_id'],OC8_PRODUCTO,OC8_ALMACEN,OC8_UBICACION,
        'AJUSTE_FALTANTE',20,$movId,100,80,$nota,substr((string)$admin['nombre'].' (ID '.$adminId.')',0,120)]);
    $st=$db->prepare('INSERT INTO erp_oc_recepciones_anulaciones
     (recepcion_id,orden_id,producto_id,cantidad_piezas,estado_anterior,estado_nuevo,motivo,usuario_id,movimiento_ajuste_id)
     VALUES (?,?,?,?,?,?,?,?,?)');
    $st->execute([OC8_REC_PRUEBA,OC8_ORDEN,OC8_PRODUCTO,20,'REGISTRADA','ANULADA',$nota,$adminId,$movId]);
    $db->commit();
    echo "COMPLETADO: recepcion 6 anulada; movimiento nuevo #$movId; stock R1N1Z01: 100 -> 80.\n";
    echo "FACTURA FAC-000002 y movimiento 1590 conservados. NO repetir esta operacion.\n";
} catch(Throwable $e) {
    if(isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
    fwrite(STDERR,"DETENIDO: ".$e->getMessage()."\nNingun cambio comprometido en esta ejecucion si se informa un fallo.\n");
    exit(1);
}
