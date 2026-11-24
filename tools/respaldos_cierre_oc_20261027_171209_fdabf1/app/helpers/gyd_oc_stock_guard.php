<?php
declare(strict_types=1);
/**
 * ERP G&D — prevención de doble entrada para OC vinculadas.
 * Llamar dentro de la MISMA transacción que incrementa existencias.
 * Ambas rutas bloquean la fila de OC con FOR UPDATE antes de medir saldos.
 */
function gyd_oc_stock_cupo(int $buenas,int $entradas,int $facturas,int $nuevo): int {
    if (min($buenas,$entradas,$facturas,$nuevo)<0 || $nuevo<1) {
        throw new RuntimeException('Cantidades de conciliación inválidas.');
    }
    if ($entradas+$facturas > $buenas) {
        throw new RuntimeException('El historial ya contiene más piezas aplicadas que buenas recibidas. Requiere conciliación manual.');
    }
    if ($entradas+$facturas+$nuevo > $buenas) {
        throw new RuntimeException('Doble entrada bloqueada: ya se aplicaron '.($entradas+$facturas).' de '.$buenas.' piezas buenas. Solo quedan '.($buenas-$entradas-$facturas).'.');
    }
    return $buenas-$entradas-$facturas-$nuevo;
}
function gyd_oc_stock_lock(PDO $db,int $ocId): array {
    if($ocId<1) throw new RuntimeException('Identificador de OC inválido.');
    $q=$db->prepare('SELECT id,folio,destino,estado,proveedor_id FROM erp_ordenes_compra WHERE id=? FOR UPDATE');
    $q->execute([$ocId]);$oc=$q->fetch(PDO::FETCH_ASSOC);
    if(!$oc)throw new RuntimeException('La OC vinculada ya no existe.');
    return $oc;
}
function gyd_oc_stock_buenas(PDO $db,int $ocId,int $pid): int {
    $q=$db->prepare("SELECT COALESCE(SUM(rd.cantidad_piezas),0) FROM erp_oc_recepciones r
      JOIN erp_oc_recepciones_detalle rd ON rd.recepcion_id=r.id
      JOIN erp_ordenes_compra_detalle od ON od.id=rd.orden_detalle_id AND od.orden_id=r.orden_id AND od.producto_id=rd.producto_id
      WHERE r.orden_id=? AND r.estado='REGISTRADA' AND rd.producto_id=?");
    $q->execute([$ocId,$pid]);return (int)$q->fetchColumn();
}
function gyd_oc_stock_manual(PDO $db,int $ocId,int $pid,int $alm):int {
    $q=$db->prepare('SELECT COALESCE(SUM(cantidad),0) FROM erp_oc_entradas_vinculos WHERE orden_id=? AND producto_id=? AND almacen_id=?');
    $q->execute([$ocId,$pid,$alm]);return (int)$q->fetchColumn();
}
function gyd_oc_stock_facturas(PDO $db,int $ocId,int $pid,int $alm):int {
    $q=$db->prepare("SELECT COALESCE(SUM(a.cantidad),0) FROM erp_facturas_stock_aplicaciones a
      JOIN erp_facturas f ON f.id=a.factura_id
      WHERE f.orden_id=? AND a.producto_id=? AND a.destino='ALMACEN' AND a.almacen_id=?");
    $q->execute([$ocId,$pid,$alm]);return (int)$q->fetchColumn();
}
function gyd_oc_stock_validar_destino(string $destino,int $alm):void {
    $map=['HIDALGO_BODEGA'=>1,'TUXTLA_BODEGA'=>3];
    if(!isset($map[$destino]) || $map[$destino]!==$alm){
        throw new RuntimeException('Destino incompatible: una OC para otra farmacia/bodega no puede incrementar este almacén.');
    }
}
/** Ejecutado dentro de Movimiento::crearMovimiento después de insertar cabecera, ANTES del detalle y stock. */
function gyd_oc_stock_entrada(PDO $db,array $data,array $detalle,int $movimientoId):void {
    if(($data['tipo_movimiento']??'')!=='ENTRADA')return;
    $rdid=(int)($data['gyd_recepcion_detalle_id']??0);
    if($rdid===0){
        // Evita que la ruta clásica etiquetada OC/REC simule una entrada desvinculada.
        $ref=(string)($data['referencia']??'');
        if(preg_match('/(?:^|[^A-Z0-9])(?:OC-[0-9]{4,}|REC-[0-9]{4,}|ERP_OC_REC)(?:$|[^A-Z0-9])/i',$ref)){
            throw new RuntimeException('Entrada vinculada a OC/REC: utiliza «Entrada protegida desde recepción» para evitar doble stock.');
        }
        return; // Entradas ajenas a una OC conservan el flujo clásico.
    }
    if(count($detalle)!==1)throw new RuntimeException('Entrada protegida: registra una partida de recepción por movimiento.');
    $q=$db->prepare("SELECT rd.id,rd.recepcion_id,rd.orden_detalle_id,rd.producto_id,rd.cantidad_piezas,rd.lote,rd.caducidad,
      r.orden_id,r.destino,r.estado AS estado_rec,od.producto_id AS producto_oc
      FROM erp_oc_recepciones_detalle rd JOIN erp_oc_recepciones r ON r.id=rd.recepcion_id
      JOIN erp_ordenes_compra_detalle od ON od.id=rd.orden_detalle_id AND od.orden_id=r.orden_id
      WHERE rd.id=?");
    $q->execute([$rdid]);$r=$q->fetch(PDO::FETCH_ASSOC);
    if(!$r||$r['estado_rec']!=='REGISTRADA')throw new RuntimeException('Recepción inexistente o no registrada.');
    $ocId=(int)$r['orden_id'];$oc=gyd_oc_stock_lock($db,$ocId);
    if(!in_array((string)$oc['estado'],['EMITIDA','PARCIAL'],true))throw new RuntimeException('La OC no está abierta para nuevas entradas.');
    if((string)$r['destino']!==(string)$oc['destino'])throw new RuntimeException('La recepción tiene destino distinto al de la OC.');
    $alm=(int)($data['almacen_id']??0);gyd_oc_stock_validar_destino((string)$oc['destino'],$alm);
    if((int)$r['producto_id']!==(int)$r['producto_oc']||(int)$r['producto_id']!==(int)$detalle[0]['producto_id'])throw new RuntimeException('El producto no corresponde a la partida de recepción.');
    if((string)($r['lote']??'')!==trim((string)($detalle[0]['numero_lote']??'')) || (string)($r['caducidad']??'')!==trim((string)($detalle[0]['fecha_caducidad']??''))){
        throw new RuntimeException('Lote o caducidad no coinciden con las piezas buenas verificadas en REC.');
    }
    if(!empty($r['caducidad']) && (string)$r['caducidad']<=date('Y-m-d'))throw new RuntimeException('La recepción contiene un lote caducado.');
    $qty=(int)$detalle[0]['cantidad'];$pid=(int)$r['producto_id'];
    $token=(string)($data['gyd_solicitud_token']??'');
    if(!preg_match('/^[a-f0-9]{32}$/D',$token))throw new RuntimeException('Token de entrada OC inválido. Recarga el formulario.');
    $q=$db->prepare('SELECT COUNT(*) FROM erp_oc_entradas_vinculos WHERE solicitud_token=?');
    $q->execute([$token]);if((int)$q->fetchColumn()>0)throw new RuntimeException('Esta entrada ya fue registrada. Consulta su folio en historial.');
    $q=$db->prepare('SELECT COALESCE(SUM(cantidad),0) FROM erp_oc_entradas_vinculos WHERE recepcion_detalle_id=?');
    $q->execute([$rdid]);$usadas=(int)$q->fetchColumn();
    if($qty<1 || $usadas+$qty>(int)$r['cantidad_piezas'])throw new RuntimeException('La partida REC no dispone de tantas piezas buenas pendientes para entrada.');
    $buenas=gyd_oc_stock_buenas($db,$ocId,$pid);
    $manual=gyd_oc_stock_manual($db,$ocId,$pid,$alm);
    $facturas=gyd_oc_stock_facturas($db,$ocId,$pid,$alm);
    gyd_oc_stock_cupo($buenas,$manual,$facturas,$qty);
    $q=$db->prepare('INSERT INTO erp_oc_entradas_vinculos(orden_id,recepcion_id,recepcion_detalle_id,producto_id,movimiento_id,almacen_id,cantidad,usuario_id,solicitud_token) VALUES(?,?,?,?,?,?,?,?,?)');
    $q->execute([$ocId,(int)$r['recepcion_id'],$rdid,$pid,$movimientoId,$alm,$qty,(int)($data['usuario_id']??0),$token]);
}
/** En ERP facturas: invocar bajo bloqueo de OC y antes de sumar stock de bodega. */
function gyd_oc_stock_factura(PDO $db,int $ocId,int $pid,int $cantidad,int $alm):void {
    if($ocId<1 || $alm<1)return;
    $oc=gyd_oc_stock_lock($db,$ocId);
    gyd_oc_stock_validar_destino((string)$oc['destino'],$alm);
    $q=$db->prepare('SELECT COUNT(*) FROM erp_oc_recepciones WHERE orden_id=?');
    $q->execute([$ocId]);
    if(!(int)$q->fetchColumn())throw new RuntimeException('Sin recepción física para esta OC: registra y verifica primero las piezas buenas.');
    $buenas=gyd_oc_stock_buenas($db,$ocId,$pid);
    $manual=gyd_oc_stock_manual($db,$ocId,$pid,$alm);
    $facturas=gyd_oc_stock_facturas($db,$ocId,$pid,$alm);
    gyd_oc_stock_cupo($buenas,$manual,$facturas,$cantidad);
}

/** Una entrada vinculada y contabilizada no se edita o cancela desde Entradas clásicas. */
function gyd_oc_stock_bloquear_edicion(PDO $db,int $movimientoId):void {
    $q=$db->prepare('SELECT id FROM erp_oc_entradas_vinculos WHERE movimiento_id=? LIMIT 1');
    $q->execute([$movimientoId]);
    if($q->fetchColumn())throw new RuntimeException('Entrada OC vinculada: no se puede editar ni cancelar aquí. Requiere ajuste de inventario auditado y conciliación de recepción.');
}
