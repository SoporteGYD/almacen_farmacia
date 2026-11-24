<?php
declare(strict_types=1);
/**
 * ERP G&D - CONCILIACION_READONLY_V1
 * Sólo SELECT; no mueve existencias, no cambia cantidades ni estados.
 * Cruza los registros internos por producto_id (identificador del código único).
 * El stock vivo NO permite reconstruir entradas históricas tras ventas o traspasos.
 */
function gyd_concil_rows(PDO $db, string $query, array $params): array {
    $s=$db->prepare($query);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC);
}
function gyd_concil_map(array $rows): array {
    $out=[];foreach($rows as $row){$id=(int)$row['producto_id'];if($id>0)$out[$id]=$row;}return $out;
}
function gyd_concil_reporte(PDO $db,int $facturaId):array {
    if($facturaId<=0)throw new RuntimeException('Identificador de factura inválido.');
    $rows=gyd_concil_rows($db,'SELECT f.id,f.orden_id,f.folio_interno,f.numero_factura,f.sucursal,f.estado,f.fecha_factura, p.nombre AS proveedor, o.folio AS oc_folio FROM erp_facturas f JOIN proveedores p ON p.id=f.proveedor_id LEFT JOIN erp_ordenes_compra o ON o.id=f.orden_id WHERE f.id=?',[$facturaId]);
    $f=$rows[0]??null;
    if(!$f)throw new RuntimeException('No se encontró la factura solicitada.');
    $oc=(int)($f['orden_id']??0);
    $scope=$oc>0?'f.orden_id=?':'f.id=?';
    $scopeParams=[$oc>0?$oc:$facturaId];
    $hayRecepciones=false;
    if($oc>0){$res=gyd_concil_rows($db,"SELECT COUNT(*) AS n FROM erp_oc_recepciones WHERE orden_id=? AND estado='REGISTRADA'",[$oc]);$hayRecepciones=(int)($res[0]['n']??0)>0;}
    $pedido=$oc>0?gyd_concil_map(gyd_concil_rows($db,'SELECT d.producto_id,MIN(d.codigo_snapshot) AS codigo,MIN(d.descripcion_snapshot) AS descripcion,SUM(d.cantidad_piezas) AS solicitadas FROM erp_ordenes_compra_detalle d WHERE d.orden_id=? GROUP BY d.producto_id',[$oc])):[];
    $recepcion=$oc>0?gyd_concil_map(gyd_concil_rows($db,"SELECT rd.producto_id,SUM(rd.cantidad_piezas) AS recibidas FROM erp_oc_recepciones_detalle rd JOIN erp_oc_recepciones r ON r.id=rd.recepcion_id JOIN erp_ordenes_compra_detalle od ON od.id=rd.orden_detalle_id AND od.orden_id=r.orden_id AND od.producto_id=rd.producto_id WHERE r.orden_id=? AND r.estado='REGISTRADA' GROUP BY rd.producto_id",[$oc])):[];
    $documentos=gyd_concil_map(gyd_concil_rows($db,"SELECT fd.producto_id,MIN(fd.codigo_snapshot) AS codigo,MIN(fd.descripcion_snapshot) AS descripcion,SUM(fd.cantidad_facturada) AS facturadas,SUM(CASE WHEN fd.factura_id=? THEN fd.cantidad_facturada ELSE 0 END) AS facturada_actual FROM erp_facturas_detalle fd JOIN erp_facturas f ON f.id=fd.factura_id WHERE {$scope} GROUP BY fd.producto_id",array_merge([$facturaId],$scopeParams)));
    $revisiones=gyd_concil_map(gyd_concil_rows($db,"SELECT fd.producto_id,
      SUM(CASE WHEN rd.tipo='ACEPTADA' THEN rd.cantidad ELSE 0 END) AS aceptadas,
      SUM(CASE WHEN rd.tipo='ACEPTADA' AND f.id=? THEN rd.cantidad ELSE 0 END) AS aceptada_actual,
      SUM(CASE WHEN rd.tipo<>'ACEPTADA' AND (rd.resolucion IS NULL OR rd.resolucion<>'REPOSICION_RECIBIDA') THEN rd.cantidad ELSE 0 END) AS incidencias,
      SUM(CASE WHEN rd.tipo<>'ACEPTADA' AND f.id=? AND (rd.resolucion IS NULL OR rd.resolucion<>'REPOSICION_RECIBIDA') THEN rd.cantidad ELSE 0 END) AS incidencia_actual
      FROM erp_facturas_revision_detalle rd JOIN erp_facturas_revisiones rv ON rv.id=rd.revision_id JOIN erp_facturas_detalle fd ON fd.id=rd.factura_detalle_id JOIN erp_facturas f ON f.id=rv.factura_id AND fd.factura_id=f.id WHERE {$scope} GROUP BY fd.producto_id",array_merge([$facturaId,$facturaId],$scopeParams)));
    $aplicaciones=gyd_concil_map(gyd_concil_rows($db,"SELECT fd.producto_id,SUM(a.cantidad) AS aplicadas,
      SUM(CASE WHEN f.id=? THEN a.cantidad ELSE 0 END) AS aplicada_actual,
      SUM(CASE WHEN a.cantidad<>rd.cantidad OR a.producto_id<>fd.producto_id OR a.factura_id<>f.id OR rd.tipo<>'ACEPTADA' THEN 1 ELSE 0 END) AS audit_errores,
      SUM(CASE WHEN (f.sucursal IN ('TAPACHULA','TOSCANA') AND a.destino<>'FARMACIA') OR (f.sucursal IN ('HIDALGO','TUXTLA') AND a.destino<>'ALMACEN') THEN 1 ELSE 0 END) AS destino_errores
      FROM erp_facturas_stock_aplicaciones a JOIN erp_facturas_revision_detalle rd ON rd.id=a.revision_detalle_id JOIN erp_facturas_revisiones rv ON rv.id=rd.revision_id JOIN erp_facturas_detalle fd ON fd.id=rd.factura_detalle_id JOIN erp_facturas f ON f.id=rv.factura_id AND fd.factura_id=f.id WHERE {$scope} GROUP BY fd.producto_id",array_merge([$facturaId],$scopeParams)));
    $ids=array_unique(array_merge(array_keys($pedido),array_keys($recepcion),array_keys($documentos),array_keys($revisiones),array_keys($aplicaciones)));
    sort($ids,SORT_NUMERIC);
    $rows=[];
    foreach($ids as $id){
        $p=$pedido[$id]??[];$r=$recepcion[$id]??[];$d=$documentos[$id]??[];$v=$revisiones[$id]??[];$a=$aplicaciones[$id]??[];
        $row=['producto_id'=>$id,
          'codigo'=>(string)($p['codigo']??$d['codigo']??(string)$id),
          'descripcion'=>(string)($p['descripcion']??$d['descripcion']??'Sin descripción'),
          'solicitadas'=>(int)($p['solicitadas']??0),'recibidas'=>(int)($r['recibidas']??0),
          'facturadas'=>(int)($d['facturadas']??0),'aceptadas'=>(int)($v['aceptadas']??0),
          'incidencias'=>(int)($v['incidencias']??0),'aplicadas'=>(int)($a['aplicadas']??0),
          'facturada_actual'=>(int)($d['facturada_actual']??0),
          'aceptada_actual'=>(int)($v['aceptada_actual']??0),'incidencia_actual'=>(int)($v['incidencia_actual']??0),
          'aplicada_actual'=>(int)($a['aplicada_actual']??0),
          'audit_errores'=>(int)($a['audit_errores']??0),'destino_errores'=>(int)($a['destino_errores']??0)
        ];
        $rows[]=gyd_concil_clasificar($row,$oc>0,$hayRecepciones);
    }
    $cols=['solicitadas','recibidas','facturadas','aceptadas','incidencias','aplicadas','por_recibir','por_facturar','pendiente_aplicar','por_verificar','facturada_actual','aceptada_actual','aplicada_actual','pendiente_aplicar_actual'];
    $tot=array_fill_keys($cols,0);$bad=0;$pending=0;
    foreach($rows as $r){foreach($cols as $k)$tot[$k]+=$r[$k];if($r['estado']==='REVISAR')$bad++;elseif($r['estado']==='PENDIENTE')$pending++;}
    return ['factura'=>$f,'tiene_oc'=>$oc>0,'tiene_recepciones'=>$hayRecepciones,'partidas'=>$rows,'totales'=>$tot,'alertas'=>$bad,'pendientes'=>$pending,'estado'=>$bad>0?'REVISAR':($pending>0?'PENDIENTES':'CONCILIADO')];
}
/** Lógica sin acceso a DB: probada con casos de sumas, faltantes y errores. */
function gyd_concil_clasificar(array $r,bool $tieneOc,bool $hayRecepciones=true):array {
    $r['por_recibir']=$tieneOc&&$hayRecepciones?max(0,$r['solicitadas']-$r['recibidas']):0;
    $r['por_facturar']=$tieneOc?max(0,$r['solicitadas']-$r['facturadas']):0;
    $r['pendiente_aplicar']=max(0,$r['aceptadas']-$r['aplicadas']);
    $r['pendiente_aplicar_actual']=max(0,$r['aceptada_actual']-$r['aplicada_actual']);
    $r['por_verificar']=max(0,$r['facturadas']-$r['aceptadas']-$r['incidencias']);
    $r['observaciones']=[];
    if($r['audit_errores']>0 || $r['destino_errores']>0)$r['observaciones'][]='Aplicación con vínculo, cantidad o destino inconsistente';
    if($r['aceptadas']>$r['facturadas'])$r['observaciones'][]='Aceptado supera lo facturado';
    if($r['aplicadas']>$r['aceptadas'])$r['observaciones'][]='Aplicado supera lo aceptado';
    if($r['aceptadas']+$r['incidencias']>$r['facturadas'])$r['observaciones'][]='Verificación e incidencias superan lo facturado';
    if($tieneOc){
      if($r['solicitadas']===0)$r['observaciones'][]='Código sin partida en la OC';
      if($hayRecepciones && $r['recibidas']>$r['solicitadas'])$r['observaciones'][]='Recibido supera lo solicitado';
      if($r['facturadas']>$r['solicitadas'])$r['observaciones'][]='Facturado supera lo solicitado';
      if($hayRecepciones && $r['aceptadas']>$r['recibidas'])$r['observaciones'][]='Aceptado supera lo recibido en Bodega';
      if($hayRecepciones && $r['aplicadas']>$r['recibidas'])$r['observaciones'][]='Aplicado supera la recepción registrada';
    }
    if(count($r['observaciones']))$r['estado']='REVISAR';
    elseif($r['pendiente_aplicar']>0 || $r['por_verificar']>0 || $r['por_recibir']>0 || $r['por_facturar']>0)$r['estado']='PENDIENTE';
    else $r['estado']='CONCILIADO';
    return $r;
}

/** Protege las celdas CSV ante fórmulas interpretadas por Excel. */
function gyd_concil_csv_seguro(string $s):string {
    return preg_match('/^[\s]*[=+@\-]/u',$s) ? "'".$s : $s;
}
