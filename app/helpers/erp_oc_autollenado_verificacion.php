<?php
declare(strict_types=1);
/**
 * ERP G&D – Precarga no destructiva de verificación desde recepciones OC.
 * Sólo lectura: NUNCA inserta aceptadas ni modifica existencias.
 * El usuario de Almacén confirma el resultado con el formulario habitual.
 */
function gyd_oc_disponibles_para_factura(array $detalle, array $recibidos, array $aceptadosNetos): array {
    $resultado = ['partidas' => [], 'piezas' => 0, 'productos' => 0, 'multi_lote' => 0, 'por_revisar' => 0, 'recibidas' => 0];
    foreach ($recibidos as $v) $resultado['recibidas'] += max(0,(int)$v['cantidad']);
    $usado = [];
    foreach ($detalle as $d) {
        $id = (int)$d['id']; $pid = (int)$d['producto_id'];
        $pendiente = max(0, (int)$d['cantidad_facturada'] - (int)$d['aceptadas'] - (int)$d['incidencias_pendientes']);
        $re = $recibidos[$pid] ?? null;
        if ($pendiente === 0 || !$re) continue;
        $libres = max(0, (int)$re['cantidad'] - (int)($aceptadosNetos[$pid] ?? 0) - (int)($usado[$pid] ?? 0));
        $qty = min($pendiente, $libres);
        if ($qty === 0) continue;
        // No declarar mercancía 'buena' automáticamente si sus lotes difieren
        // o hay caducidad vencida: necesita inspección manual por lote.
        $unico = (int)$re['lotes_distintos'] === 1;
        $caducidad = (string)($re['caducidad'] ?? '');
        if (!$unico || ($caducidad !== '' && $caducidad < date('Y-m-d'))) {
            $resultado['por_revisar']++;
            if (!$unico) $resultado['multi_lote']++;
            continue;
        }
        $usado[$pid] = ($usado[$pid] ?? 0) + $qty;
        $resultado['partidas'][$id] = [
            'buenas' => $qty,
            'lote' => $unico ? (string)($re['lote'] ?? '') : '',
            'caducidad' => $unico ? (string)($re['caducidad'] ?? '') : '',
            'multi_lote' => !$unico,
        ];
        $resultado['piezas'] += $qty;
        $resultado['productos']++;
    }
    return $resultado;
}
function gyd_oc_precargar_verificacion(PDO $db, int $ordenId, array $detalle): array {
    $vacio = ['partidas'=>[], 'piezas'=>0, 'productos'=>0, 'multi_lote'=>0, 'por_revisar'=>0, 'recibidas'=>0];
    if ($ordenId < 1 || !$detalle) return $vacio;

    $q = $db->prepare("SELECT rd.producto_id, SUM(rd.cantidad_piezas) cantidad,
                COUNT(DISTINCT CONCAT(COALESCE(rd.lote,''),'|',COALESCE(CAST(rd.caducidad AS CHAR),''))) lotes_distintos,
                MAX(rd.lote) lote, MAX(rd.caducidad) caducidad
        FROM erp_oc_recepciones r
        JOIN erp_oc_recepciones_detalle rd ON rd.recepcion_id=r.id
        JOIN erp_ordenes_compra_detalle od ON od.id=rd.orden_detalle_id
             AND od.orden_id=r.orden_id AND od.producto_id=rd.producto_id
        WHERE r.orden_id=? AND r.estado='REGISTRADA'
        GROUP BY rd.producto_id");
    $q->execute([$ordenId]);
    $rec=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r) $rec[(int)$r['producto_id']]=$r;
    if (!$rec) return $vacio;

    $q = $db->prepare("SELECT fd.producto_id, COALESCE(SUM(v.cantidad),0) historicas
        FROM erp_facturas f
        JOIN erp_facturas_detalle fd ON fd.factura_id=f.id
        JOIN erp_facturas_revision_detalle v ON v.factura_detalle_id=fd.id AND v.tipo='ACEPTADA'
        WHERE f.orden_id=? GROUP BY fd.producto_id");
    $q->execute([$ordenId]);
    $brutas=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r) $brutas[(int)$r['producto_id']]=(int)$r['historicas'];

    // Las conciliaciones auditadas revierten o restituyen aceptaciones previas.
    require_once __DIR__.'/erp_oc_seguimiento.php';
    $ajustes=erp_oc_ajustes_por_orden($db,$ordenId);
    $netas=[];
    foreach($rec as $pid=>$r){
        [$descontado,$repuesto]=$ajustes[$pid]??[0,0];
        $netas[$pid]=erp_oc_neto_validado($brutas[$pid]??0,$descontado,$repuesto);
    }
    return gyd_oc_disponibles_para_factura($detalle,$rec,$netas);
}
