<?php
declare(strict_types=1);
/** G&D 2026-10-26 · Centro de conciliación integral V1 · SOLO LECTURA. */
function ci_html($value):string { return htmlspecialchars((string)$value, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function ci_select(PDO $db, string $sql, array $params=[]):array {
    $st=$db->prepare($sql);$st->execute($params);return $st->fetchAll(PDO::FETCH_ASSOC);
}
function ci_estructura(PDO $db):array {
    $obligatorias=['erp_ordenes_compra','erp_ordenes_compra_detalle','erp_oc_recepciones','erp_oc_recepciones_detalle','erp_facturas','erp_facturas_detalle','erp_facturas_revisiones','erp_facturas_revision_detalle','erp_facturas_stock_aplicaciones'];
    $st=$db->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('.implode(',',array_fill(0,count($obligatorias)+1,'?')).')');
    $st->execute(array_merge($obligatorias,['erp_oc_conciliacion_eventos']));
    $hay=[];foreach($st->fetchAll(PDO::FETCH_COLUMN) as $name)$hay[strtolower((string)$name)]=true;
    $faltan=array_values(array_filter($obligatorias,static fn($t)=>!isset($hay[$t])));
    return ['faltan'=>$faltan,'ajustes'=>isset($hay['erp_oc_conciliacion_eventos'])];
}
function ci_mapas(PDO $db,array $ids,bool $ajustes):array {
    $idMarks=implode(',',array_fill(0,count($ids),'?'));
    $secciones=['pedido'=>[], 'recepcion'=>[], 'factura'=>[], 'revision'=>[], 'aplicacion'=>[], 'ajuste'=>[], 'recepciones_documentadas'=>[]];
    if(!$ids)return $secciones;
    $fuentes=[
     'pedido'=>"SELECT d.orden_id orden_id,d.producto_id,MIN(d.codigo_snapshot) codigo,MIN(d.descripcion_snapshot) descripcion,SUM(d.cantidad_piezas) solicitado FROM erp_ordenes_compra_detalle d WHERE d.orden_id IN ($idMarks) GROUP BY d.orden_id,d.producto_id",
     'recepcion'=>"SELECT r.orden_id,rd.producto_id,SUM(rd.cantidad_piezas) recibido FROM erp_oc_recepciones r JOIN erp_oc_recepciones_detalle rd ON rd.recepcion_id=r.id JOIN erp_ordenes_compra_detalle d ON d.id=rd.orden_detalle_id AND d.orden_id=r.orden_id AND d.producto_id=rd.producto_id WHERE r.estado='REGISTRADA' AND r.orden_id IN ($idMarks) GROUP BY r.orden_id,rd.producto_id",
     'factura'=>"SELECT f.orden_id,fd.producto_id,MIN(fd.codigo_snapshot) codigo,MIN(fd.descripcion_snapshot) descripcion,SUM(fd.cantidad_facturada) facturado FROM erp_facturas f JOIN erp_facturas_detalle fd ON fd.factura_id=f.id WHERE f.orden_id IN ($idMarks) GROUP BY f.orden_id,fd.producto_id",
     'revision'=>"SELECT f.orden_id,fd.producto_id,SUM(CASE WHEN rd.tipo='ACEPTADA' THEN rd.cantidad ELSE 0 END) aceptado, SUM(CASE WHEN rd.tipo<>'ACEPTADA' AND (rd.resolucion IS NULL OR rd.resolucion<>'REPOSICION_RECIBIDA') THEN rd.cantidad ELSE 0 END) incidencia FROM erp_facturas f JOIN erp_facturas_detalle fd ON fd.factura_id=f.id JOIN erp_facturas_revision_detalle rd ON rd.factura_detalle_id=fd.id JOIN erp_facturas_revisiones rv ON rv.id=rd.revision_id AND rv.factura_id=f.id WHERE f.orden_id IN ($idMarks) GROUP BY f.orden_id,fd.producto_id",
     'aplicacion'=>"SELECT f.orden_id,a.producto_id,SUM(a.cantidad) aplicado FROM erp_facturas f JOIN erp_facturas_stock_aplicaciones a ON a.factura_id=f.id WHERE f.orden_id IN ($idMarks) GROUP BY f.orden_id,a.producto_id",
     'recepciones_documentadas'=>"SELECT r.orden_id, COUNT(*) recepciones FROM erp_oc_recepciones r WHERE r.estado='REGISTRADA' AND r.orden_id IN ($idMarks) GROUP BY r.orden_id"
    ];
    if($ajustes)$fuentes['ajuste']="SELECT e.orden_id,e.producto_id,SUM(CASE WHEN e.tipo='AJUSTE_FALTANTE' THEN e.cantidad ELSE 0 END) descontado,SUM(CASE WHEN e.tipo='REPOSICION' THEN e.cantidad ELSE 0 END) repuesto FROM erp_oc_conciliacion_eventos e WHERE e.orden_id IN ($idMarks) GROUP BY e.orden_id,e.producto_id";
    foreach($fuentes as $clave=>$sql){
       foreach(ci_select($db,$sql,$ids) as $r){$oid=(int)$r['orden_id'];$pid=(int)($r['producto_id']??0);$secciones[$clave][$oid][$pid]=$r;}
    }
    return $secciones;
}
/** Devuelve el estado de UNA partida, no sustituye conciliación fiscal ni contable. */
function ci_clasificar(array $r,bool $hayRecibos,bool $ajustesDisponibles):array {
    $r['observaciones']=[];
    $s=$r['solicitado'];$rec=$r['recibido'];$f=$r['facturado'];$a=$r['aceptado'];$ap=$r['aplicado'];$i=$r['incidencia'];
    $r['por_recibir']=$hayRecibos?max(0,$s-$rec):null;
    $r['por_facturar']=max(0,$s-$f);
    $r['por_verificar']=max(0,$f-$a-$i);
    $r['por_aplicar']=max(0,$a-$ap);
    if($s<1)$r['observaciones'][]='Partida sin cantidad solicitada en la OC';
    if($f>$s)$r['observaciones'][]='Facturado supera lo solicitado';
    if($hayRecibos && $rec>$s)$r['observaciones'][]='Recibido supera lo solicitado';
    if($a>$f)$r['observaciones'][]='Aceptado supera lo facturado';
    if($a+$i>$f)$r['observaciones'][]='Aceptado e incidencias superan lo facturado';
    if($ap>$a)$r['observaciones'][]='Aplicado supera lo aceptado';
    if($hayRecibos && $a>$rec)$r['observaciones'][]='Aceptado supera lo recibido';
    if($hayRecibos && $ap>$rec)$r['observaciones'][]='Aplicado supera lo recibido';
    if($r['ajuste_invalido'])$r['observaciones'][]='Ajustes de conciliación inconsistentes';
    if($r['observaciones'])$r['estado']='REVISAR';
    elseif(!$ajustesDisponibles)$r['estado']='SIN AUDITORÍA';
    elseif(!$hayRecibos)$r['estado']='SIN EVIDENCIA';
    elseif($r['por_recibir']>0 || $r['por_facturar']>0 || $r['por_verificar']>0 || $r['por_aplicar']>0 || $i>0)$r['estado']='PENDIENTE';
    else $r['estado']='CONCILIADO';
    return $r;
}
function ci_construir(array $orden, array $m,bool $ajustesDisponibles):array {
    $oid=(int)$orden['id'];
    $docRecepciones=isset($m['recepciones_documentadas'][$oid][0]);
    $ids=[];
    foreach(['pedido','recepcion','factura','revision','aplicacion','ajuste'] as $clave){foreach(array_keys($m[$clave][$oid]??[]) as $pid)$ids[$pid]=true;}
    $tot=['solicitado'=>0,'recibido'=>0,'facturado'=>0,'aceptado'=>0,'incidencia'=>0,'aplicado'=>0,'por_recibir'=>0,'por_facturar'=>0,'por_verificar'=>0,'por_aplicar'=>0];
    $partidas=[];$cuentas=['REVISAR'=>0,'PENDIENTE'=>0,'SIN EVIDENCIA'=>0,'SIN AUDITORÍA'=>0,'CONCILIADO'=>0];
    foreach(array_keys($ids) as $pid){
       $p=$m['pedido'][$oid][$pid]??[];$f=$m['factura'][$oid][$pid]??[];$v=$m['revision'][$oid][$pid]??[];
       $brutoA=(int)($v['aceptado']??0);$brutoAp=(int)($m['aplicacion'][$oid][$pid]['aplicado']??0);
       $ev=$m['ajuste'][$oid][$pid]??[];$des=(int)($ev['descontado']??0);$rep=(int)($ev['repuesto']??0);
       $invalido=$des<0 || $rep<0 || $rep>$des || $des>$brutoA || $des>$brutoAp;
       $netA=$brutoA-$des+$rep;$netAp=$brutoAp-$des+$rep;
       $r=['producto_id'=>(int)$pid,'codigo'=>(string)($p['codigo']??$f['codigo']??(string)$pid),
          'descripcion'=>(string)($p['descripcion']??$f['descripcion']??'Producto sin descripción'),
          'solicitado'=>(int)($p['solicitado']??0),'recibido'=>(int)($m['recepcion'][$oid][$pid]['recibido']??0),
          'facturado'=>(int)($f['facturado']??0),'aceptado'=>$netA,
          'incidencia'=>(int)($v['incidencia']??0),'aplicado'=>$netAp,
          'ajuste_invalido'=>$invalido];
       $r=ci_clasificar($r,$docRecepciones,$ajustesDisponibles);
       foreach($tot as $k=>$_){$tot[$k]+=(int)($r[$k]??0);}
       $cuentas[$r['estado']]++;$partidas[]=$r;
    }
    usort($partidas,static fn($a,$b)=>strcmp($a['codigo'],$b['codigo']));
    if(!$partidas)$estado='SIN PARTIDAS';
    elseif($cuentas['REVISAR'])$estado='REVISAR';
    elseif(!in_array((string)$orden['estado'],['EMITIDA','PARCIAL','COMPLETA'],true))$estado='NO EMITIDA';
    elseif($cuentas['SIN AUDITORÍA'])$estado='SIN AUDITORÍA';
    elseif($cuentas['SIN EVIDENCIA'])$estado='SIN EVIDENCIA';
    elseif($cuentas['PENDIENTE'])$estado='PENDIENTE';
    else $estado='CONCILIADO';
    return ['orden'=>$orden,'estado'=>$estado,'totales'=>$tot,'partidas'=>$partidas,'cuentas'=>$cuentas,'tiene_recepciones'=>$docRecepciones];
}
/** Máximo 500 órdenes recientes por consulta para limitar recursos. Exportación usa el mismo conjunto filtrado. */
function ci_listado(PDO $db,string $busqueda='',string $destino='',string $ocEstado='',string $concEstado=''):array {
    $where=[];$params=[];
    if($busqueda!==''){$where[]='(o.folio LIKE ? OR p.nombre LIKE ?)';$params[]='%'.$busqueda.'%';$params[]='%'.$busqueda.'%';}
    if($destino!==''){$where[]='o.destino=?';$params[]=$destino;}
    if($ocEstado!==''){$where[]='o.estado=?';$params[]=$ocEstado;}
    $sql='SELECT o.id,o.folio,o.fecha,o.destino,o.estado,p.nombre proveedor FROM erp_ordenes_compra o JOIN proveedores p ON p.id=o.proveedor_id';
    if($where)$sql.=' WHERE '.implode(' AND ',$where);
    $sql.=' ORDER BY o.id DESC LIMIT 500';
    $ordenes=ci_select($db,$sql,$params);
    if(!$ordenes)return [];
    $ids=array_map(static fn($o)=>(int)$o['id'],$ordenes);
    $e=ci_estructura($db);
    if($e['faltan'])throw new RuntimeException('Faltan tablas: '.implode(', ',$e['faltan']));
    $map=ci_mapas($db,$ids,$e['ajustes']);$salida=[];
    foreach($ordenes as $o){$row=ci_construir($o,$map,$e['ajustes']);if($concEstado===''||$concEstado===$row['estado'])$salida[]=$row;}
    return $salida;
}
function ci_detalle(PDO $db,int $id):?array {
    if($id<1)return null;
    $orden=ci_select($db,'SELECT o.id,o.folio,o.fecha,o.destino,o.estado,p.nombre proveedor FROM erp_ordenes_compra o JOIN proveedores p ON p.id=o.proveedor_id WHERE o.id=?',[$id])[0]??null;
    if(!$orden)return null;
    $e=ci_estructura($db);if($e['faltan'])throw new RuntimeException('Faltan tablas: '.implode(', ',$e['faltan']));
    $m=ci_mapas($db,[$id],$e['ajustes']);
    $r=ci_construir($orden,$m,$e['ajustes']);
    $r['facturas']=ci_select($db,'SELECT id,folio_interno,numero_factura,sucursal,estado,fecha_factura FROM erp_facturas WHERE orden_id=? ORDER BY id',[$id]);
    $r['recepciones']=ci_select($db,'SELECT id,folio,estado,fecha_recepcion FROM erp_oc_recepciones WHERE orden_id=? ORDER BY id',[$id]);
    return $r;
}
function ci_csv_seguro(string $s):string {return preg_match('/^\s*[=+@\-]/u',$s)?"'".$s:$s;}
