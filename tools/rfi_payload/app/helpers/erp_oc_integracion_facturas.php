<?php
declare(strict_types=1);
/** ERP G&D - Enlace SOLO LECTURA OC -> recepción buena -> factura -> verificación -> inventario. */
function gyd_rfi_neto(int $historico,int $descuento,int $reposicion):int {
    if ($historico<0 || $descuento<0 || $reposicion<0 || $reposicion>$descuento || $descuento>$historico) {
        throw new RuntimeException('Historial de ajustes inconsistente: requiere revisión manual.');
    }
    return $historico-$descuento+$reposicion;
}
function gyd_rfi_sugerir(array $detalle,array $recepciones,array $netos):array {
    $out=['partidas'=>[],'piezas'=>0,'revisar'=>0,'lotes_multiples'=>0];$usados=[];
    foreach ($detalle as $d) {
        $did=(int)$d['id'];$pid=(int)$d['producto_id'];
        $pendiente=max(0,(int)$d['cantidad_facturada']-(int)$d['aceptadas']-(int)$d['incidencias_pendientes']);
        $r=$recepciones[$pid]??null;
        if (!$r || $pendiente===0) continue;
        $saldo=max(0,(int)$r['cantidad']-(int)($netos[$pid]??0)-(int)($usados[$pid]??0));
        $qty=min($saldo,$pendiente);
        if ($qty<=0)continue;
        $cad=(string)($r['caducidad']??'');$lot=(string)($r['lote']??'');
        // La sugerencia no se autocompleta si existen lotes distintos, falta lote,
        // falta caducidad o el lote está vencido. Almacén captura el dato manualmente.
        if ((int)$r['lotes_distintos']!==1 || $lot==='' || $cad==='' || $cad<=date('Y-m-d')) {
            $out['revisar']++;
            if((int)$r['lotes_distintos']!==1)$out['lotes_multiples']++;
            continue;
        }
        $out['partidas'][$did]=['buenas'=>$qty,'lote'=>$lot,'caducidad'=>$cad];
        $usados[$pid]=($usados[$pid]??0)+$qty;
        $out['piezas']+=$qty;
    }
    return $out;
}
/** Crea sugerencias para una factura existente, nunca registra verificación ni entrada. */
function gyd_rfi_precarga(PDO $db,int $ordenId,array $detalles):array {
    $vacio=['partidas'=>[],'piezas'=>0,'revisar'=>0,'lotes_multiples'=>0];
    if($ordenId<1 || !$detalles)return $vacio;
    $q=$db->prepare("SELECT d.producto_id,SUM(d.cantidad_piezas) cantidad,
       COUNT(DISTINCT CONCAT(COALESCE(d.lote,''),'|',COALESCE(CAST(d.caducidad AS CHAR),''))) lotes_distintos,
       MAX(d.lote) lote,MAX(d.caducidad) caducidad
       FROM erp_oc_recepciones r JOIN erp_oc_recepciones_detalle d ON d.recepcion_id=r.id
       JOIN erp_ordenes_compra_detalle od ON od.id=d.orden_detalle_id AND od.orden_id=r.orden_id AND od.producto_id=d.producto_id
       WHERE r.orden_id=? AND r.estado='REGISTRADA' GROUP BY d.producto_id");
    $q->execute([$ordenId]);$rec=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$rec[(int)$r['producto_id']]=$r;
    if(!$rec)return $vacio;
    $q=$db->prepare("SELECT fd.producto_id, SUM(rd.cantidad) aceptadas
       FROM erp_facturas f JOIN erp_facturas_detalle fd ON fd.factura_id=f.id
       JOIN erp_facturas_revision_detalle rd ON rd.factura_detalle_id=fd.id AND rd.tipo='ACEPTADA'
       WHERE f.orden_id=? GROUP BY fd.producto_id");
    $q->execute([$ordenId]);$historicas=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$historicas[(int)$r['producto_id']]=(int)$r['aceptadas'];
    if(!is_file(__DIR__.'/erp_oc_seguimiento.php'))throw new RuntimeException('Falta módulo de conciliación neta de OC.');
    require_once __DIR__.'/erp_oc_seguimiento.php';
    $ajustes=erp_oc_ajustes_por_orden($db,$ordenId);$netos=[];
    foreach($rec as $pid=>$_){[$aj,$rep]=$ajustes[$pid]??[0,0];$netos[$pid]=gyd_rfi_neto($historicas[$pid]??0,$aj,$rep);}
    return gyd_rfi_sugerir($detalles,$rec,$netos);
}
function gyd_rfi_resumen(PDO $db,int $ordenId):array {
    $q=$db->prepare('SELECT o.id,o.folio,o.destino,o.estado,p.nombre proveedor FROM erp_ordenes_compra o JOIN proveedores p ON p.id=o.proveedor_id WHERE o.id=?');
    $q->execute([$ordenId]);$oc=$q->fetch(PDO::FETCH_ASSOC);
    if(!$oc)throw new RuntimeException('No existe la orden de compra.');
    $q=$db->prepare('SELECT producto_id,MIN(codigo_snapshot) codigo,MIN(descripcion_snapshot) descripcion, SUM(cantidad_piezas) cantidad FROM erp_ordenes_compra_detalle WHERE orden_id=? GROUP BY producto_id ORDER BY MIN(id)');
    $q->execute([$ordenId]);$pedido=$q->fetchAll(PDO::FETCH_ASSOC);
    if(!$pedido)throw new RuntimeException('OC sin partidas.');
    $q=$db->prepare("SELECT d.producto_id,SUM(d.cantidad_piezas) piezas,COUNT(DISTINCT r.id) entregas
      FROM erp_oc_recepciones r JOIN erp_oc_recepciones_detalle d ON d.recepcion_id=r.id
      JOIN erp_ordenes_compra_detalle od ON od.id=d.orden_detalle_id AND od.orden_id=r.orden_id AND od.producto_id=d.producto_id
      WHERE r.orden_id=? AND r.estado='REGISTRADA' GROUP BY d.producto_id");
    $q->execute([$ordenId]);$rec=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$rec[(int)$r['producto_id']]=$r;
    $q=$db->prepare('SELECT fd.producto_id,SUM(fd.cantidad_facturada) piezas FROM erp_facturas f JOIN erp_facturas_detalle fd ON fd.factura_id=f.id WHERE f.orden_id=? GROUP BY fd.producto_id');
    $q->execute([$ordenId]);$fact=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$fact[(int)$r['producto_id']]=(int)$r['piezas'];
    $q=$db->prepare("SELECT fd.producto_id,SUM(rd.cantidad) piezas FROM erp_facturas f
      JOIN erp_facturas_detalle fd ON fd.factura_id=f.id
      JOIN erp_facturas_revision_detalle rd ON rd.factura_detalle_id=fd.id AND rd.tipo='ACEPTADA'
      WHERE f.orden_id=? GROUP BY fd.producto_id");
    $q->execute([$ordenId]);$ver=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$ver[(int)$r['producto_id']]=(int)$r['piezas'];
    $q=$db->prepare('SELECT a.producto_id,SUM(a.cantidad) piezas FROM erp_facturas_stock_aplicaciones a JOIN erp_facturas f ON f.id=a.factura_id WHERE f.orden_id=? GROUP BY a.producto_id');
    $q->execute([$ordenId]);$apl=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$apl[(int)$r['producto_id']]=(int)$r['piezas'];
    require_once __DIR__.'/erp_oc_seguimiento.php';$ajustes=erp_oc_ajustes_por_orden($db,$ordenId);
    $q=$db->prepare('SELECT id,folio_interno,numero_factura,fecha_factura,estado,sucursal FROM erp_facturas WHERE orden_id=? ORDER BY id');
    $q->execute([$ordenId]);$facturas=$q->fetchAll(PDO::FETCH_ASSOC);
    $q=$db->prepare("SELECT r.folio,r.fecha_recepcion,r.referencia_factura,
       COALESCE(SUM(d.cantidad_piezas),0) buenas FROM erp_oc_recepciones r
       LEFT JOIN erp_oc_recepciones_detalle d ON d.recepcion_id=r.id
       WHERE r.orden_id=? AND r.estado='REGISTRADA' GROUP BY r.id ORDER BY r.id DESC");
    $q->execute([$ordenId]);$entregas=$q->fetchAll(PDO::FETCH_ASSOC);
    $filas=[];$tot=['solicitadas'=>0,'buenas'=>0,'facturadas'=>0,'verificadas'=>0,'aplicadas'=>0,'pendientes'=>0];
    foreach($pedido as $d){
        $pid=(int)$d['producto_id'];$s=(int)$d['cantidad'];$b=(int)($rec[$pid]['piezas']??0);$f=$fact[$pid]??0;
        [$aj,$rep]=$ajustes[$pid]??[0,0];$v=gyd_rfi_neto($ver[$pid]??0,$aj,$rep);$a=gyd_rfi_neto($apl[$pid]??0,$aj,$rep);
        $pend=max(0,$s-$b);$revision=($b>$s||$f>$s||$v>$b||$v>$f||$a>$v);
        $estado=$revision?'REVISAR':($pend===0 && $f===$s && $v===$s && $a===$s?'CONCILIADO':'PENDIENTE');
        $filas[]=['codigo'=>$d['codigo'],'descripcion'=>$d['descripcion'],'solicitadas'=>$s,'buenas'=>$b,'facturadas'=>$f,'verificadas'=>$v,'aplicadas'=>$a,'pendientes'=>$pend,'estado'=>$estado];
        foreach(['solicitadas'=>$s,'buenas'=>$b,'facturadas'=>$f,'verificadas'=>$v,'aplicadas'=>$a,'pendientes'=>$pend] as $k=>$n)$tot[$k]+=$n;
    }
    return compact('oc','filas','facturas','entregas','tot');
}
