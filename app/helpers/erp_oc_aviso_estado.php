<?php
declare(strict_types=1);
/** Bandeja de Compras: estados derivados, sólo lectura. No escribe existencias. */

/**
 * Asigna cantidades de documentos y movimientos al recibo más antiguo por
 * código dentro de cada OC. Esto permite visualizar los complementos sin
 * adjudicar arbitrariamente al más reciente una aplicación anterior.
 * NO es un vínculo documental directo entre factura y recepción.
 */
function oc_status_batch(PDO $pdo, array $avisos): array {
    $res=[];$ocs=[];
    foreach($avisos as $a){
        $id=(int)($a['id']??0);$oid=(int)($a['orden_id']??0);
        if($id>0 && $oid>0){$ocs[$oid]=$oid; $res[$id]=[
            'recibidas'=>0,'facturadas'=>0,'aplicadas'=>0,
            'lectura'=>($a['estado']??'PENDIENTE')==='REVISADO',
            'entrada_almacen'=>false,'lineas'=>[],'factura_id'=>null
        ];}
    }
    if(!$ocs)return $res;
    $orders=array_values($ocs);
    $qs=implode(',',array_fill(0,count($orders),'?'));
    $st=$pdo->prepare("SELECT r.id AS recepcion_id,r.orden_id,r.destino,rd.producto_id,
      d.codigo_snapshot AS codigo, d.descripcion_snapshot AS descripcion,
      SUM(rd.cantidad_piezas) AS piezas,
      MAX(CASE WHEN v.movimiento_id IS NOT NULL THEN 1 ELSE 0 END) AS entrada_almacen
      FROM erp_oc_recepciones r
      JOIN erp_oc_recepciones_detalle rd ON rd.recepcion_id=r.id
      JOIN erp_ordenes_compra_detalle d ON d.id=rd.orden_detalle_id
      LEFT JOIN erp_oc_entrada_vinculos v ON v.recepcion_id=r.id
      WHERE r.estado='REGISTRADA' AND r.orden_id IN ($qs)
      GROUP BY r.id,r.orden_id,r.destino,rd.producto_id,d.codigo_snapshot,d.descripcion_snapshot
      ORDER BY r.orden_id,r.id,rd.producto_id");
    $st->execute($orders);$recs=$st->fetchAll(PDO::FETCH_ASSOC);
    $st=$pdo->prepare("SELECT f.orden_id,fd.producto_id,SUM(fd.cantidad_facturada) AS piezas,MAX(f.id) AS ultimo
      FROM erp_facturas f JOIN erp_facturas_detalle fd ON fd.factura_id=f.id
      WHERE f.orden_id IN ($qs) GROUP BY f.orden_id,fd.producto_id");
    $st->execute($orders);$facturas=[];$latest=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $l){
        $o=(int)$l['orden_id'];$pid=(int)$l['producto_id'];
        $facturas[$o][$pid]=max(0,(int)$l['piezas']);
        $latest[$o]=max($latest[$o]??0,(int)$l['ultimo']);
    }
    $st=$pdo->prepare("SELECT f.orden_id,a.producto_id,a.destino,SUM(a.cantidad) AS piezas
      FROM erp_facturas_stock_aplicaciones a
      JOIN erp_facturas f ON f.id=a.factura_id
      WHERE f.orden_id IN ($qs) GROUP BY f.orden_id,a.producto_id,a.destino");
    $st->execute($orders);$aplicadas=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $l){
        $aplicadas[(int)$l['orden_id']][(int)$l['producto_id']][(string)$l['destino']]=max(0,(int)$l['piezas']);
    }
    // Crear índice por recibo; el resultado incluye también avisos de recibos posteriores
    // aunque ya se hayan facturado productos de recibos anteriores.
    $byReceipt=[];
    foreach($avisos as $a){$rid=(int)($a['recepcion_id']??0);if($rid>0){$byReceipt[$rid][]=(int)$a['id'];}}
    foreach($recs as $l){
        $oid=(int)$l['orden_id'];$pid=(int)$l['producto_id'];$rid=(int)$l['recepcion_id'];
        $qty=max(0,(int)$l['piezas']);
        $fact=min($qty,$facturas[$oid][$pid]??0);
        $facturas[$oid][$pid]=max(0,($facturas[$oid][$pid]??0)-$fact);
        $destino=(string)$l['destino'];
        $tipo= str_ends_with($destino,'_BODEGA')?'ALMACEN':'FARMACIA';
        $vinculo = $tipo==='ALMACEN' && ((int)$l['entrada_almacen']===1);
        // El movimiento de almacén vinculado al folio REC es fuente directa.
        // No consumir aplicaciones de facturas para el mismo recibo.
        $ap=$vinculo?$qty:min($qty,$aplicadas[$oid][$pid][$tipo]??0);
        if(!$vinculo)$aplicadas[$oid][$pid][$tipo]=max(0,($aplicadas[$oid][$pid][$tipo]??0)-$ap);
        foreach($byReceipt[$rid]??[] as $aid){
            $res[$aid]['recibidas']+=$qty;
            $res[$aid]['facturadas']+=$fact;
            $res[$aid]['aplicadas']+=$ap;
            $res[$aid]['entrada_almacen']=$res[$aid]['entrada_almacen']||$vinculo;
            $res[$aid]['factura_id']=$latest[$oid]??null;
            $res[$aid]['lineas'][]=[
                'codigo'=>(string)$l['codigo'],'descripcion'=>(string)$l['descripcion'],
                'recibidas'=>$qty,'facturadas'=>$fact,'aplicadas'=>$ap,
                'tipo'=>$tipo,'directa'=>$vinculo
            ];
        }
    }
    foreach($res as &$s){
        $s['lectura']=$s['lectura'] || ($s['recibidas']>0 && $s['facturadas'] >= $s['recibidas']);
        $s['factura_estado']=$s['facturadas']<=0?'PENDIENTE':($s['facturadas']<$s['recibidas']?'PARCIAL':'REGISTRADA');
        $s['inventario_estado']=$s['aplicadas']<=0?'PENDIENTE':($s['aplicadas']<$s['recibidas']?'PARCIAL':'APLICADO');
    }
    unset($s);
    return $res;
}
