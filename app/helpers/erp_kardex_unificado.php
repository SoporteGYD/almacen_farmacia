<?php
declare(strict_types=1);
/** ERP G&D: fuentes documentales del Kardex. SOLO CONSULTA; no mueve inventario. */
function kg_h(mixed $x): string {return htmlspecialchars((string)$x, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');}
function kg_table(PDO $db, string $table): bool {
    static $cache=[];
    if (!preg_match('/^[a-z0-9_]+$/D',$table)) return false;
    if (!array_key_exists($table,$cache)) {
        $s=$db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $s->execute([$table]); $cache[$table]=(bool)$s->fetchColumn();
    }
    return $cache[$table];
}
function kg_column(PDO $db,string $table,string $column): bool {
    static $cache=[];
    $key=$table.'.'.$column;
    if(!preg_match('/^[a-z0-9_]+$/D',$table)||!preg_match('/^[a-z0-9_]+$/D',$column)||!kg_table($db,$table))return false;
    if(!array_key_exists($key,$cache)){
        $s=$db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $s->execute([$table,$column]);$cache[$key]=(bool)$s->fetchColumn();
    }
    return $cache[$key];
}
function kg_source(PDO $db,string $label,string $sql,array $params,array &$warnings,int $limit=240):array {
    try {
        $s=$db->prepare($sql.' LIMIT '.$limit);
        $s->execute($params);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)===$limit)$warnings[]="Fuente $label: se alcanzó el límite de $limit registros. Acorta las fechas para ver más.";
        return $rows;
    }catch(Throwable $e){error_log('Kardex '.$label.': '.$e->getMessage());$warnings[]="No se pudo consultar $label; revisa la instalación y el registro de PHP.";return [];}
}
function kg_event(string $date,string $group,string $event,string $ref,string $detail,string $who,?int $general,?int $pharmacy,?int $warehouse,string $status,string $source,int $id=0):array{
    return ['fecha'=>$date,'grupo'=>$group,'tipo'=>$event,'folio'=>$ref,'detalle'=>$detail,'usuario'=>$who,'pos'=>$general,'farmacia'=>$pharmacy,'almacen'=>$warehouse,'estado'=>$status,'origen'=>$source,'id'=>$id];
}
/** Devuelve eventos en orden cronológico inverso; null = efecto desconocido o no rastreable.
 * IMPORTANTE: los deltas históricos NO reconstruyen necesariamente el saldo inicial ni todos los movimientos.
 */
function kg_movimientos(PDO $db,int $sid,int $pid,string $desde,string $hasta,string $tipo,int $uid,array &$warnings): array {
    $events=[];
    $add=static function(array $r)use(&$events):void{$events[]=$r;};
    $start=$desde.' 00:00:00';$end=(new DateTimeImmutable($hasta))->modify('+1 day')->format('Y-m-d').' 00:00:00';
    $sucursal=$sid===1?'HIDALGO':($sid===2?'TUXTLA':($sid===3?'TAPACHULA':'TOSCANA'));
    $wh=$sid===1?[1]:($sid===2?[2,3]:[]);
    $want=static fn(string $group):bool=>$tipo==='TODOS'||$tipo===$group;
    $table=static fn(string $name):bool=>kg_table($db,$name);
    $usr='COALESCE(u.nombre,\'Usuario no identificado\')';
    // Ventas POS. Cantidades históricas en caja solo seguras cuando existe unidades_descontadas.
    if($want('VENTA')&&$table('pos_ventas')&&$table('pos_venta_detalle')){
        $pieces=kg_column($db,'pos_venta_detalle','unidades_descontadas')?'COALESCE(d.unidades_descontadas,d.cantidad)':'d.cantidad';
        $join=$table('pos_venta_origenes')?" LEFT JOIN pos_venta_origenes o ON o.venta_id=v.id AND o.producto_id=d.producto_id":"";
        $orig=$table('pos_venta_origenes')?'o.origen':'NULL';
        $whereUid=$uid>0?' AND v.usuario_id=?':'';
        $args=[$pid,$sid,$start,$end];if($uid>0)$args[]=$uid;
        $rows=kg_source($db,'Ventas POS',"SELECT d.id,v.folio,v.pagada_en AS fecha,v.estado,v.usuario_id,$usr AS responsable,d.cantidad,$pieces AS piezas,$orig AS origen_fisico FROM pos_venta_detalle d JOIN pos_ventas v ON v.id=d.venta_id LEFT JOIN usuarios u ON u.id=v.usuario_id $join WHERE d.producto_id=? AND v.sucursal_id=? AND v.pagada_en>=? AND v.pagada_en<? AND v.estado IN ('PAGADA','CANCELADA') $whereUid ORDER BY v.pagada_en DESC,d.id DESC",$args,$warnings);
        foreach($rows as $r){$qty=(int)$r['piezas'];$origin=(string)($r['origen_fisico']??'');$physical=$origin==='FARMACIA'?-$qty:($origin==='ALMACEN'||$origin==='FARMACIA_GENERAL'?0:null);
            $cancelledSale=$r['estado']==='CANCELADA';
            $detail=(int)$r['cantidad'].' presentación(es), '.$qty.' piezas · origen: '.($origin?:'SIN REGISTRO');
            if($cancelledSale)$detail.=' · CANCELADA: comprobar la reversa y no sumar esta línea al saldo';
            if(!kg_column($db,'pos_venta_detalle','unidades_descontadas'))$detail.=' · equivalencia histórica no verificada';
            $add(kg_event((string)$r['fecha'],'VENTA','Venta POS', (string)$r['folio'],$detail,(string)$r['responsable'],$cancelledSale?null:-$qty,$cancelledSale?null:$physical,0,(string)$r['estado'],'pos_venta_detalle',(int)$r['id']));
        }
    }
    // Almacén: solo movimientos con dirección inequívoca; otros se marcan sin efecto inferido.
    if($want('ALMACEN')&&$wh&&$table('movimientos')&&$table('movimiento_detalle')){
        $ph=implode(',',array_fill(0,count($wh),'?'));
        $args=[$pid,...$wh,$start,$end];$who=$uid>0?' AND m.usuario_id=?':'';if($uid>0)$args[]=$uid;
        $rows=kg_source($db,'Movimientos Almacén',"SELECT d.id,m.folio,m.fecha,m.tipo_movimiento,m.tipo_operacion,m.cancelado,m.almacen_id,m.referencia,d.cantidad,d.ubicacion,$usr AS responsable FROM movimiento_detalle d JOIN movimientos m ON m.id=d.movimiento_id LEFT JOIN usuarios u ON u.id=m.usuario_id WHERE d.producto_id=? AND m.almacen_id IN ($ph) AND m.fecha>=? AND m.fecha<? $who ORDER BY m.fecha DESC,d.id DESC",$args,$warnings);
        foreach($rows as $r){$cancelled=(int)$r['cancelado']===1;$qty=(int)$r['cantidad'];$kind=(string)$r['tipo_movimiento'];$delta=$cancelled?null:match($kind){'ENTRADA'=>$qty,'SALIDA','MERMA'=>-$qty,default=>null};
            $note='Almacén #'.$r['almacen_id'].' · '.($r['ubicacion']?:'sin ubicación').' · '.$kind.(!empty($r['tipo_operacion'])?' / '.$r['tipo_operacion']:'');
            $add(kg_event((string)$r['fecha'],'ALMACEN',$kind.' de Almacén',(string)$r['folio'],$note,(string)$r['responsable'],0,0,$delta,$cancelled?'CANCELADO / requiere comprobar reversa':'REGISTRADO','movimiento_detalle',(int)$r['id']));
        }
    }
    // Factura aplicada: el movimiento de Almacén se muestra en la fuente movimiento_detalle; no duplicar.
    if($want('FACTURA')&&$table('erp_facturas_stock_aplicaciones')&&$table('erp_facturas')){
        $args=[$pid,$start,$end,$sucursal,$sid];$user=$uid>0?' AND a.usuario_id=?':'';if($uid>0)$args[]=$uid;
        $rows=kg_source($db,'Facturas aplicadas',"SELECT a.id,a.creado_en AS fecha,a.cantidad,a.destino,a.movimiento_id,f.folio_interno,f.numero_factura,$usr AS responsable FROM erp_facturas_stock_aplicaciones a JOIN erp_facturas f ON f.id=a.factura_id LEFT JOIN usuarios u ON u.id=a.usuario_id WHERE a.producto_id=? AND a.creado_en>=? AND a.creado_en<? AND (f.sucursal=? OR a.pos_sucursal_id=?) $user ORDER BY a.creado_en DESC,a.id DESC",$args,$warnings);
        foreach($rows as $r){$q=(int)$r['cantidad'];$direct=$r['destino']==='FARMACIA';$add(kg_event((string)$r['fecha'],'FACTURA','Factura aplicada', (string)($r['folio_interno']?:$r['numero_factura']),$r['destino'].' · '.$q.' piezas'.($direct?' · entrada a POS único':' · asiento físico en movimientos de Almacén'),(string)$r['responsable'],$direct?$q:0,$direct?null:0,$direct?0:null,'APLICADA','erp_facturas_stock_aplicaciones',(int)$r['id']));}
    }
    // Captura de factura: únicamente documental; la aplicación física y contable es otro evento.
    if($want('FACTURA')&&$table('erp_facturas')&&$table('erp_facturas_detalle')){
        $args=[$pid,$sucursal,$start,$end];$user=$uid>0?' AND f.usuario_id=?':'';if($uid>0)$args[]=$uid;
        $rows=kg_source($db,'Facturas capturadas',"SELECT f.id,f.creado_en AS fecha,f.folio_interno,f.numero_factura,f.estado,d.cantidad_facturada,$usr AS responsable FROM erp_facturas_detalle d JOIN erp_facturas f ON f.id=d.factura_id LEFT JOIN usuarios u ON u.id=f.usuario_id WHERE d.producto_id=? AND f.sucursal=? AND f.creado_en>=? AND f.creado_en<? $user ORDER BY f.creado_en DESC,f.id DESC",$args,$warnings);
        foreach($rows as $r){$add(kg_event((string)$r['fecha'],'FACTURA','Factura registrada',(string)($r['folio_interno']?:$r['numero_factura']),(int)$r['cantidad_facturada'].' piezas facturadas · registro documental; NO equivale a stock disponible',(string)$r['responsable'],0,0,0,(string)$r['estado'],'erp_facturas',(int)$r['id']));}
    }
    // Resurtidos: recepción física a Farmacia no vuelve a aumentar el POS general.
    if($want('RESURTIDO')&&$table('erp_resurtidos_pos_confirmaciones')&&$table('erp_resurtidos_pos_confirmaciones_detalle')&&$table('erp_resurtidos_pos_recepciones')){
        $args=[$pid,$sid,$start,$end];$user=$uid>0?' AND c.usuario_id=?':'';if($uid>0)$args[]=$uid;
        $rows=kg_source($db,'Resurtidos confirmados',"SELECT c.id,c.creado_en AS fecha,c.movimiento_id,d.cantidad,$usr AS responsable FROM erp_resurtidos_pos_confirmaciones_detalle d JOIN erp_resurtidos_pos_confirmaciones c ON c.id=d.confirmacion_id JOIN erp_resurtidos_pos_recepciones r ON r.movimiento_id=c.movimiento_id LEFT JOIN usuarios u ON u.id=c.usuario_id WHERE d.producto_id=? AND r.pos_sucursal_id=? AND c.creado_en>=? AND c.creado_en<? $user ORDER BY c.creado_en DESC,c.id DESC",$args,$warnings);
        foreach($rows as $r){$add(kg_event((string)$r['fecha'],'RESURTIDO','Recibido en Farmacia','Movimiento #'.$r['movimiento_id'],(int)$r['cantidad'].' piezas transferidas físicamente; la salida de Almacén es un movimiento distinto',(string)$r['responsable'],0,(int)$r['cantidad'],0,'CONFIRMADO','erp_resurtidos_pos_confirmaciones_detalle',(int)$r['id']));}
    }
    // Notas de tickets: no afectan existencias al solicitarse.
    if($want('TICKET')&&$table('erp_pos_tickets_almacen')&&$table('resurtidos')){
        $args=[$pid,$sid,$start,$end];$user=$uid>0?' AND t.usuario_id=?':'';if($uid>0)$args[]=$uid;
        $rows=kg_source($db,'Tickets POS',"SELECT t.id,t.creado_en AS fecha,t.cantidad,r.folio,r.estado,t.venta_id,$usr AS responsable FROM erp_pos_tickets_almacen t JOIN resurtidos r ON r.id=t.resurtido_id LEFT JOIN usuarios u ON u.id=t.usuario_id WHERE t.producto_id=? AND t.sucursal_id=? AND t.creado_en>=? AND t.creado_en<? $user ORDER BY t.creado_en DESC,t.id DESC",$args,$warnings);
        foreach($rows as $r){$add(kg_event((string)$r['fecha'],'TICKET','Solicitud a Almacén',(string)$r['folio'],(int)$r['cantidad'].' piezas; '.($r['venta_id']?'asociado a venta #'.$r['venta_id']:'sin cobro asociado'),(string)$r['responsable'],0,0,0,(string)$r['estado'],'erp_pos_tickets_almacen',(int)$r['id']));}
    }
    // Reversiones: fuente del reintegro POS; para las físicas revisar origen y Kardex Almacén.
    if($want('DEVOLUCION')&&$table('pos_reversiones')&&$table('pos_ventas')){
        $args=[$pid,$sid,$start,$end];$user=$uid>0?' AND r.usuario_id=?':'';if($uid>0)$args[]=$uid;
        $sql="SELECT r.id,r.creado_en AS fecha,r.tipo,r.estado,r.piezas,r.motivo,v.folio,$usr AS responsable".($table('pos_venta_origenes')?",o.origen AS origen_fisico":" ,NULL AS origen_fisico")." FROM pos_reversiones r JOIN pos_ventas v ON v.id=r.venta_id LEFT JOIN usuarios u ON u.id=r.usuario_id".($table('pos_venta_origenes')?' LEFT JOIN pos_venta_origenes o ON o.venta_id=r.venta_id AND o.producto_id=r.producto_id':'')." WHERE r.producto_id=? AND v.sucursal_id=? AND r.creado_en>=? AND r.creado_en<? $user ORDER BY r.creado_en DESC,r.id DESC";
        foreach(kg_source($db,'Devoluciones/Reversas',$sql,$args,$warnings) as $r){$q=(int)$r['piezas'];$applied=$r['estado']==='APLICADA';$orig=(string)($r['origen_fisico']??'');
            $add(kg_event((string)$r['fecha'],'DEVOLUCION',(string)$r['tipo'],(string)$r['folio'],$q.' piezas · '.$r['motivo'].' · origen: '.($orig?:'desconocido'),(string)$r['responsable'],$applied?$q:0,$applied&&$orig==='FARMACIA'?$q:($orig==='ALMACEN'?0:null),0,(string)$r['estado'],'pos_reversiones',(int)$r['id']));
        }
    }
    // Solicitudes de devolución de Almacén (sin efecto hasta confirmar): documental.
    if($want('DEVOLUCION')&&$table('pos_devoluciones_almacen')&&$table('pos_ventas')){
        $args=[$pid,$sid,$start,$end];$user=$uid>0?' AND x.solicitado_por=?':'';if($uid>0)$args[]=$uid;
        $rows=kg_source($db,'Solicitudes devolución Almacén',"SELECT x.id,x.solicitado_en AS fecha,x.piezas,x.estado,x.motivo,v.folio,$usr AS responsable FROM pos_devoluciones_almacen x JOIN pos_ventas v ON v.id=x.venta_id LEFT JOIN usuarios u ON u.id=x.solicitado_por WHERE x.producto_id=? AND v.sucursal_id=? AND x.solicitado_en>=? AND x.solicitado_en<? $user ORDER BY x.solicitado_en DESC,x.id DESC",$args,$warnings);
        foreach($rows as $r){$add(kg_event((string)$r['fecha'],'DEVOLUCION','Solicitud de devolución Almacén',(string)$r['folio'],(int)$r['piezas'].' piezas · '.$r['motivo'].' · la restitución final se rastrea en reversas POS y movimientos Almacén',(string)$r['responsable'],0,0,0,(string)$r['estado'],'pos_devoluciones_almacen',(int)$r['id']));}
    }
    if($want('AJUSTE')&&$table('erp_conciliacion_ajustes')){
        $args=[$pid,$sid,$start,$end];$user=$uid>0?' AND a.aplicado_por=?':'';if($uid>0)$args[]=$uid;
        $rows=kg_source($db,'Ajustes',"SELECT a.id,a.aplicado_en AS fecha,a.destino,a.estado,a.cantidad_objetivo,a.cantidad_anterior,a.motivo,$usr AS responsable FROM erp_conciliacion_ajustes a LEFT JOIN usuarios u ON u.id=a.aplicado_por WHERE a.producto_id=? AND a.sucursal_id=? AND a.aplicado_en>=? AND a.aplicado_en<? $user ORDER BY a.aplicado_en DESC,a.id DESC",$args,$warnings);
        foreach($rows as $r){$delta=$r['cantidad_anterior']===null?null:((int)$r['cantidad_objetivo']-(int)$r['cantidad_anterior']);$pos=$r['destino']==='POS_GENERAL'?$delta:0;$farm=$r['destino']==='FARMACIA'?$delta:0;
            $add(kg_event((string)$r['fecha'],'AJUSTE','Ajuste autorizado','Ajuste #'.$r['id'],$r['destino'].' · '.$r['motivo'],(string)$r['responsable'],$pos,$farm,0,(string)$r['estado'],'erp_conciliacion_ajustes',(int)$r['id']));}
    }
    if($want('CONTEO')&&$table('erp_conciliacion_conteos')){
        $args=[$pid,$sid,$start,$end];$user=$uid>0?' AND c.usuario_id=?':'';if($uid>0)$args[]=$uid;
        $rows=kg_source($db,'Conteos',"SELECT c.id,c.registrado_en AS fecha,c.ubicacion,c.cantidad_contada,c.cantidad_sistema,c.observaciones,$usr AS responsable FROM erp_conciliacion_conteos c LEFT JOIN usuarios u ON u.id=c.usuario_id WHERE c.producto_id=? AND c.sucursal_id=? AND c.registrado_en>=? AND c.registrado_en<? $user ORDER BY c.registrado_en DESC,c.id DESC",$args,$warnings);
        foreach($rows as $r){$add(kg_event((string)$r['fecha'],'CONTEO','Conteo físico','Conteo #'.$r['id'],$r['ubicacion'].': '.(int)$r['cantidad_contada'].' piezas contadas · sistema '.($r['cantidad_sistema']??'?').' · '.$r['observaciones'],(string)$r['responsable'],0,0,0,'DOCUMENTAL','erp_conciliacion_conteos',(int)$r['id']));}
    }
    usort($events,static fn(array $a,array $b):int=>strcmp($b['fecha'],$a['fecha'])?:strcmp($b['origen'],$a['origen'])?:(($b['id']??0)<=>($a['id']??0)));
    return $events;
}
