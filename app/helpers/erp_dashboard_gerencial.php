<?php
declare(strict_types=1);
/** G&D ERP: consultas gerenciales de SOLO LECTURA. Moneda MXN. */
function gd_dash_tabla(PDO $db,string $tabla):bool {
    $q=$db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $q->execute([$tabla]);return (int)$q->fetchColumn()>0;
}
function gd_dash_fecha(string $s):bool {
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$s);
    return $d!==false && $d->format('Y-m-d')===$s;
}
function gd_dash_query(PDO $db,string $sql,array $args=[]):array{
    $s=$db->prepare($sql);$s->execute($args);return $s->fetchAll(PDO::FETCH_ASSOC);
}
function gd_dash_numerico(mixed $n):float {return round((float)($n??0),2);}
function gd_dash_informe(PDO $db,string $desde,string $hasta,int $sucursal=0,int $producto=0):array {
    foreach(['pos_sucursales','pos_ventas','pos_venta_detalle','usuarios'] as $t){if(!gd_dash_tabla($db,$t))throw new RuntimeException('Falta la tabla '.$t.' de Punto de Venta.');}
    $tieneReversiones=gd_dash_tabla($db,'pos_reversiones');
    $tieneCostos=gd_dash_tabla($db,'erp_pos_costos_venta');
    $tienePagos=gd_dash_tabla($db,'pos_pagos');
    $tiempoIni=$desde.' 00:00:00';$tiempoFin=(new DateTimeImmutable($hasta))->modify('+1 day')->format('Y-m-d').' 00:00:00';
    $sucursales=gd_dash_query($db,'SELECT id,nombre FROM pos_sucursales WHERE activo=1 ORDER BY id');
    if($sucursal>0&&!in_array($sucursal,array_map('intval',array_column($sucursales,'id')),true))throw new RuntimeException('Sucursal inválida.');
    $filas=[];$diario=[];$totales=['ventas'=>0.0,'devoluciones'=>0.0,'neto'=>0.0,'costo_vendido_conocido'=>0.0,'costo_devoluciones_conocido'=>0.0,'lineas'=>0,'lineas_con_costo'=>0,'partidas_devolucion'=>0,'partidas_devolucion_con_costo'=>0,'tickets'=>0,'piezas'=>0,'cancelaciones'=>0,'devoluciones_count'=>0];
    $avisos=[];
    if(!$tieneReversiones)$avisos[]='No se encontró pos_reversiones. Las devoluciones y cancelaciones históricas no se pueden descontar del reporte.';
    if(!$tieneCostos)$avisos[]='No se encontró el registro histórico de costos. Ejecuta la migración incluida: no se calcularán utilidades sin costos de venta.';
    $filtroProducto=$producto>0?' AND d.producto_id=?':'';
    $sistemaCancelacion=$tieneReversiones?"(v.estado='PAGADA' OR (v.estado='CANCELADA' AND EXISTS (SELECT 1 FROM pos_reversiones rc WHERE rc.venta_id=v.id AND rc.tipo='CANCELACION_TOTAL' AND rc.estado='APLICADA')))":"v.estado='PAGADA'";
    $costJoin=$tieneCostos?' LEFT JOIN erp_pos_costos_venta sc ON sc.detalle_id=d.id ':' ';
    $costSelect=$tieneCostos?'COALESCE(SUM(sc.costo_total),0)':'0';
    $covered=$tieneCostos?'SUM(CASE WHEN sc.detalle_id IS NOT NULL THEN 1 ELSE 0 END)':'0';
    $sucReporte=$sucursal>0?array_values(array_filter($sucursales,static fn($x)=>(int)$x['id']===$sucursal)):$sucursales;
    foreach($sucReporte as $s){
        $sid=(int)$s['id'];
        $args=[$sid,$tiempoIni,$tiempoFin];if($producto>0)$args[]=$producto;
        $ventas=gd_dash_query($db,"SELECT COALESCE(SUM(d.total_renglon),0) AS ventas, COUNT(DISTINCT v.id) AS tickets,COALESCE(SUM(COALESCE(d.unidades_descontadas,d.cantidad*COALESCE(d.piezas_por_presentacion,1))),0) AS piezas, COUNT(*) AS lineas, $covered AS cubiertas, $costSelect AS costo FROM pos_ventas v JOIN pos_venta_detalle d ON d.venta_id=v.id $costJoin WHERE v.sucursal_id=? AND v.pagada_en>=? AND v.pagada_en<? AND ($sistemaCancelacion) $filtroProducto",$args)[0];
        $vDev=0.0;$devCost=0.0;$devoluciones=0;$cancelaciones=0;$partidasDev=0;$partidasDevCubiertas=0;
        if($tieneReversiones){
            $refCost=$tieneCostos?'SUM(CASE WHEN d.id IS NOT NULL AND sc.detalle_id IS NOT NULL THEN CASE WHEN r.tipo=\'CANCELACION_TOTAL\' THEN sc.costo_total ELSE r.piezas * sc.costo_unitario END ELSE 0 END) AS costo_refund, SUM(CASE WHEN d.id IS NOT NULL AND sc.detalle_id IS NOT NULL THEN 1 ELSE 0 END) AS cubiertas':'0 AS costo_refund,0 AS cubiertas';
            $refJoin=$tieneCostos?' LEFT JOIN erp_pos_costos_venta sc ON sc.detalle_id=d.id ':' ';
            $pr=$producto>0?' AND d.producto_id=?':'';
            $argsR=[$sid,$tiempoIni,$tiempoFin];if($producto>0)$argsR[]=$producto;
            $reemb=gd_dash_query($db,"SELECT r.id,r.tipo,r.importe,v.total AS total_venta, r.creado_en, COALESCE(SUM(d.total_renglon),0) AS total_partidas, $refCost, COUNT(d.id) AS partidas FROM pos_reversiones r JOIN pos_ventas v ON v.id=r.venta_id LEFT JOIN pos_venta_detalle d ON ((r.tipo='CANCELACION_TOTAL' AND d.venta_id=r.venta_id) OR (r.tipo='DEVOLUCION_PARCIAL' AND d.id=r.detalle_id)) $refJoin WHERE r.estado='APLICADA' AND r.tipo IN ('CANCELACION_TOTAL','DEVOLUCION_PARCIAL') AND v.sucursal_id=? AND r.creado_en>=? AND r.creado_en<? $pr GROUP BY r.id,r.tipo,r.importe,r.creado_en,v.total",$argsR);
            foreach($reemb as $r){
                if((int)$r['partidas']===0)continue;
                $importe=(float)$r['importe'];
                if($producto>0&&$r['tipo']==='CANCELACION_TOTAL'){
                    $importe=(float)$r['total_venta']>0?round((float)$r['importe']*(float)$r['total_partidas']/(float)$r['total_venta'],2):0.0;
                }
                $vDev+=$importe;
                $devCost+=(float)$r['costo_refund'];
                $partidasDev+=(int)$r['partidas'];$partidasDevCubiertas+=(int)$r['cubiertas'];
                if($r['tipo']==='CANCELACION_TOTAL')$cancelaciones++;else $devoluciones++;
                $day=substr((string)$r['creado_en'],0,10);
                if(!isset($diario[$day]))$diario[$day]=['dia'=>$day,'ventas'=>0.,'devoluciones'=>0.];
                $diario[$day]['devoluciones']+=$importe;
            }
        }
        $faltantes=(int)$ventas['lineas']-(int)$ventas['cubiertas'];
        $faltantesDev=$partidasDev-$partidasDevCubiertas;
        $costoOK=($faltantes===0&&$faltantesDev===0&&((int)$ventas['lineas']+$partidasDev)>0);
        $bruto=gd_dash_numerico($ventas['ventas']);$vDev=round($vDev,2);
        $costoNeto=$costoOK?round((float)$ventas['costo']-$devCost,2):null;
        $renta=$costoOK?round($bruto-$vDev-$costoNeto,2):null;
        $f=['id'=>$sid,'nombre'=>(string)$s['nombre'],'ventas'=>$bruto,'devoluciones'=>$vDev,'neto'=>round($bruto-$vDev,2),'costo_vendido_conocido'=>round((float)$ventas['costo'],2),'costo_devoluciones_conocido'=>round($devCost,2),'costo_neto'=>$costoNeto,'utilidad'=>$renta,'margen'=>$renta!==null&&($bruto-$vDev)>0?round($renta/($bruto-$vDev)*100,2):null,'tickets'=>(int)$ventas['tickets'],'piezas'=>(int)$ventas['piezas'],'lineas'=>(int)$ventas['lineas'],'lineas_con_costo'=>(int)$ventas['cubiertas'],'partidas_devolucion'=>$partidasDev,'partidas_devolucion_con_costo'=>$partidasDevCubiertas,'cancelaciones'=>$cancelaciones,'devoluciones_count'=>$devoluciones];
        $filas[]=$f;
        foreach(['ventas','devoluciones','neto','costo_vendido_conocido','costo_devoluciones_conocido','tickets','piezas','lineas','lineas_con_costo','partidas_devolucion','partidas_devolucion_con_costo','cancelaciones','devoluciones_count'] as $field){$totales[$field]+=$f[$field];}
    }
    $tieneActividad=($totales['lineas']+$totales['partidas_devolucion'])>0;
    $completo=$tieneActividad && $totales['lineas']===$totales['lineas_con_costo'] && $totales['partidas_devolucion']===$totales['partidas_devolucion_con_costo'];
    $totales['costo_neto']=$completo?round($totales['costo_vendido_conocido']-$totales['costo_devoluciones_conocido'],2):null;
    $totales['utilidad']=$completo?round($totales['neto']-$totales['costo_neto'],2):null;
    $totales['margen']=$totales['utilidad']!==null&&$totales['neto']>0?round($totales['utilidad']/$totales['neto']*100,2):null;
    $totales['cobertura']=$tieneActividad?round(($totales['lineas_con_costo']+$totales['partidas_devolucion_con_costo'])/($totales['lineas']+$totales['partidas_devolucion'])*100,1):0.0;
    if($tieneActividad&&!$completo)$avisos[]='Utilidad no disponible: faltan costos históricos en '.($totales['lineas']-$totales['lineas_con_costo']+$totales['partidas_devolucion']-$totales['partidas_devolucion_con_costo']).' partidas de ventas o devoluciones. No se sustituyen por costos actuales.';
    // Día de la venta y día de la devolución son periodos diferentes.
    $dayWhere=$sucursal>0?' AND v.sucursal_id=?':'';
    $dayArgs=[$tiempoIni,$tiempoFin];if($sucursal>0)$dayArgs[]=$sucursal;if($producto>0)$dayArgs[]=$producto;
    foreach(gd_dash_query($db,"SELECT DATE(v.pagada_en) AS dia,SUM(d.total_renglon) AS ventas FROM pos_ventas v JOIN pos_venta_detalle d ON d.venta_id=v.id WHERE v.pagada_en>=? AND v.pagada_en<? $dayWhere AND ($sistemaCancelacion) $filtroProducto GROUP BY DATE(v.pagada_en) ORDER BY dia",$dayArgs) as $r){
        $day=(string)$r['dia'];if(!isset($diario[$day]))$diario[$day]=['dia'=>$day,'ventas'=>0.,'devoluciones'=>0.];$diario[$day]['ventas']+=(float)$r['ventas'];
    }
    ksort($diario);
    foreach($diario as &$d){$d['ventas']=round($d['ventas'],2);$d['devoluciones']=round($d['devoluciones'],2);$d['neto']=round($d['ventas']-$d['devoluciones'],2);}unset($d);
    $topArgs=[$tiempoIni,$tiempoFin];if($sucursal>0)$topArgs[]=$sucursal;if($producto>0)$topArgs[]=$producto;
    $sCond=$sucursal>0?' AND v.sucursal_id=?':'';
    $top=gd_dash_query($db,"SELECT d.producto_id,MAX(d.codigo_snapshot) codigo,MAX(d.descripcion_snapshot) descripcion,COALESCE(SUM(COALESCE(d.unidades_descontadas,d.cantidad*COALESCE(d.piezas_por_presentacion,1))),0) AS piezas,COALESCE(SUM(d.total_renglon),0) AS importe FROM pos_ventas v JOIN pos_venta_detalle d ON d.venta_id=v.id WHERE v.pagada_en>=? AND v.pagada_en<? $sCond AND ($sistemaCancelacion) $filtroProducto GROUP BY d.producto_id ORDER BY piezas DESC,importe DESC LIMIT 15",$topArgs);
    $cajeros=gd_dash_query($db,"SELECT u.nombre,COUNT(DISTINCT v.id) tickets,ROUND(SUM(d.total_renglon),2) AS bruto FROM pos_ventas v JOIN pos_venta_detalle d ON d.venta_id=v.id LEFT JOIN usuarios u ON u.id=v.usuario_id WHERE v.pagada_en>=? AND v.pagada_en<? $sCond AND ($sistemaCancelacion) $filtroProducto GROUP BY v.usuario_id,u.nombre ORDER BY bruto DESC LIMIT 20",$topArgs);
    $pagos=[];
    if($tienePagos&&$producto===0){
        $pArgs=[$tiempoIni,$tiempoFin];if($sucursal>0)$pArgs[]=$sucursal;
        $pagos=gd_dash_query($db,"SELECT pg.metodo,SUM(pg.monto) importe,COUNT(pg.id) operaciones FROM pos_pagos pg JOIN pos_ventas v ON v.id=pg.venta_id WHERE v.pagada_en>=? AND v.pagada_en<? ".($sucursal>0?' AND v.sucursal_id=?':'')." AND pg.estado='CONFIRMADO' AND ($sistemaCancelacion) GROUP BY pg.metodo ORDER BY importe DESC",$pArgs);
    }
    $compras=[];
    if(gd_dash_tabla($db,'erp_facturas') && gd_dash_tabla($db,'erp_facturas_detalle') && gd_dash_tabla($db,'proveedores')){
        $branchEnum=['1'=>'HIDALGO','2'=>'TUXTLA','3'=>'TAPACHULA','4'=>'TOSCANA'];
        $cArgs=[$desde,$hasta];$cBranch='';
        if($sucursal>0){$cBranch=' AND f.sucursal=?';$cArgs[]=$branchEnum[(string)$sucursal]??'SIN_SUCURSAL';}
        $cProd='';if($producto>0){$cProd=' AND d.producto_id=?';$cArgs[]=$producto;}
        $compras=gd_dash_query($db,"SELECT p.nombre AS proveedor,COUNT(DISTINCT f.id) AS facturas, SUM(d.cantidad_facturada*d.costo_unitario) AS subtotal_capturado FROM erp_facturas f JOIN erp_facturas_detalle d ON d.factura_id=f.id JOIN proveedores p ON p.id=f.proveedor_id WHERE f.fecha_factura>=? AND f.fecha_factura<=? $cBranch $cProd GROUP BY f.proveedor_id,p.nombre ORDER BY subtotal_capturado DESC LIMIT 20",$cArgs);
    }
    // Compras registradas por factura NO equivalen a costo de ventas del periodo.
    return compact('sucursales','filas','totales','diario','top','cajeros','pagos','compras','avisos','tieneCostos','tieneReversiones','tienePagos');
}
