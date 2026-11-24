<?php
declare(strict_types=1);
/** Farmacia G&D — Comparativo General de Sucursales; todas las operaciones son SELECT. */
function gcs_h(mixed $s): string { return htmlspecialchars((string)($s??''), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function gcs_date(string $s): bool { $d=DateTimeImmutable::createFromFormat('!Y-m-d',$s); return $d!==false && $d->format('Y-m-d')===$s; }
function gcs_range(string $from,string $to):array {
    if(!gcs_date($from)||!gcs_date($to)||$from>$to)throw new DomainException('Selecciona un periodo válido.');
    $a=new DateTimeImmutable($from); $b=new DateTimeImmutable($to);
    if($a->diff($b)->days>92)throw new DomainException('El periodo máximo es de 93 días.');
    return [$from.' 00:00:00',$b->modify('+1 day')->format('Y-m-d').' 00:00:00'];
}
function gcs_money_c(mixed $x):int {
    $s=trim((string)($x??'0'));
    if(!preg_match('/^(-?)(\d{1,13})(?:\.(\d{1,4}))?$/D',$s,$m))throw new RuntimeException('Se detectó un importe inválido en los registros del POS.');
    $fraction=substr(str_pad($m[3]??'',5,'0',STR_PAD_RIGHT),0,5);
    $c=(int)$m[2]*100+(int)substr($fraction,0,2);
    // HALF_UP centavos para los costos DECIMAL(18,4)
    if((int)$fraction[2]>=5)$c++;
    return ($m[1]==='-'?-$c:$c);
}
function gcs_money(int $c):string{return ($c<0?'-':'').'$'.number_format(abs($c)/100,2,'.',',');}
function gcs_num(int $n):string{return number_format($n,0,'.',',');}
function gcs_sql(PDO $db,string $sql,array $params=[]):array{$p=$db->prepare($sql);$p->execute($params);return $p->fetchAll(PDO::FETCH_ASSOC);}
function gcs_table(PDO $db,string $table):bool{return (bool)gcs_sql($db,'SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',[$table]);}
function gcs_cols(PDO $db,string $table):array{return array_column(gcs_sql($db,'SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=?',[$table]),'column_name');}
function gcs_csv(mixed $value):string { $s=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u',' ',(string)($value??''))??'';if(preg_match('/^-?\d+(?:\.\d+)?$/D',$s))return $s;return preg_match('/^\s*[=+@\-]/u',$s)?"'".$s:$s; }
function gcs_role(array $u):bool{
    $r=strtoupper(trim((string)($u['rol_original']??$u['rol']??'')));
    return (int)($u['id']??0)>0 && in_array($r,['ADMIN','ADMINISTRADOR','DIRECCION','DUEÑO','DUENO','PROPIETARIO'],true);
}
function gcs_default():array{return ['ventas_c'=>0,'tickets'=>0,'reversiones_c'=>null,'cancelaciones'=>0,'devoluciones'=>0,'neto_c'=>null,'piezas'=>null,'lineas'=>null,'medidas'=>null,'tiempo_seg'=>null,'inventario_skus'=>null,'agotados'=>null,'piezas_stock'=>null,'turnos_cerrados'=>0,'turnos_abiertos'=>0,'faltante_c'=>0,'sobrante_c'=>0,'retiros_c'=>null,'costos_c'=>null,'utilidad_c'=>null,'margen_pct'=>null,'cobertura_costos'=>null];}
function gcs_report(PDO $db,string $from,string $to,int $branch=0):array {
    if((string)$db->query('SELECT DATABASE()')->fetchColumn()!=='prueba')throw new DomainException('El comparativo solo puede utilizarse con la base de pruebas «prueba».');
    [$start,$end]=gcs_range($from,$to);
    $required=['pos_sucursales','pos_cajas','pos_turnos','pos_ventas'];
    foreach($required as $t)if(!gcs_table($db,$t))throw new RuntimeException('Falta la tabla necesaria: '.$t);
    $branches=gcs_sql($db,'SELECT id,nombre FROM pos_sucursales WHERE activo=1 ORDER BY id');
    $out=[];foreach($branches as $b){$id=(int)$b['id'];if($branch && $branch!==$id)continue;$out[$id]=['id'=>$id,'nombre'=>(string)$b['nombre']]+gcs_default();}
    if($branch && !$out)throw new DomainException('La sucursal no existe o no está activa.');
    $notices=[];
    if(!$out)return ['rows'=>[],'branches'=>$branches,'avisos'=>['No hay sucursales POS activas.'],'desde'=>$from,'hasta'=>$to,'meta'=>[]];
    $in=static fn(string $alias):string=>$branch?" AND {$alias}.sucursal_id=?":'';
    $args=static fn(array $a):array=>$branch?array_merge($a,[$branch]):$a;
    $hasRev=gcs_table($db,'pos_reversiones');
    $val=$hasRev?"(v.estado='PAGADA' OR (v.estado='CANCELADA' AND EXISTS (SELECT 1 FROM pos_reversiones r0 WHERE r0.venta_id=v.id AND r0.estado='APLICADA' AND r0.tipo='CANCELACION_TOTAL')))":"v.estado='PAGADA'";
    if(!$hasRev)$notices[]='Sin pos_reversiones: no se puede calcular venta neta. Se muestran ventas originales, pero no se consideran como netas.';
    foreach(gcs_sql($db,"SELECT v.sucursal_id,COUNT(*) tickets,COALESCE(SUM(v.total),0) ventas FROM pos_ventas v WHERE v.pagada_en>=? AND v.pagada_en<? AND $val".$in('v')." GROUP BY v.sucursal_id",$args([$start,$end])) as $r){
        $id=(int)$r['sucursal_id'];if(!isset($out[$id]))continue;$out[$id]['tickets']=(int)$r['tickets'];$out[$id]['ventas_c']=gcs_money_c($r['ventas']);
    }
    if($hasRev){
        foreach($out as &$r){$r['reversiones_c']=0;$r['neto_c']=$r['ventas_c'];}unset($r);
        foreach(gcs_sql($db,"SELECT v.sucursal_id,r.tipo,COUNT(*) n,COALESCE(SUM(r.importe),0) total FROM pos_reversiones r JOIN pos_ventas v ON v.id=r.venta_id WHERE r.estado='APLICADA' AND r.tipo IN ('CANCELACION_TOTAL','DEVOLUCION_PARCIAL') AND r.creado_en>=? AND r.creado_en<?".$in('v')." GROUP BY v.sucursal_id,r.tipo",$args([$start,$end])) as $r){
            $id=(int)$r['sucursal_id'];if(!isset($out[$id]))continue;$out[$id]['reversiones_c']+=gcs_money_c($r['total']);$key=$r['tipo']==='CANCELACION_TOTAL'?'cancelaciones':'devoluciones';$out[$id][$key]+=(int)$r['n'];
        }
        foreach($out as &$r){$r['neto_c']=$r['ventas_c']-$r['reversiones_c'];}unset($r);
        $notices[]='Ventas originales por fecha de pago; devoluciones y cancelaciones por fecha de aplicación. El neto del periodo puede incluir reembolsos de ventas anteriores.';
    }
    $hasDetails=gcs_table($db,'pos_venta_detalle');
    if($hasDetails){
        $cols=gcs_cols($db,'pos_venta_detalle');
        if(in_array('unidades_descontadas',$cols,true))$piece='d.unidades_descontadas';
        elseif(in_array('piezas_por_presentacion',$cols,true))$piece='d.cantidad*COALESCE(NULLIF(d.piezas_por_presentacion,0),1)';
        else {$piece='NULL';$notices[]='No es posible calcular piezas vendidas: faltan las equivalencias pieza/caja en el detalle.';}
        $detailSql="SELECT v.sucursal_id,COUNT(*) lineas".($piece==='NULL'?'':",COALESCE(SUM($piece),0) piezas")." FROM pos_venta_detalle d JOIN pos_ventas v ON v.id=d.venta_id WHERE v.pagada_en>=? AND v.pagada_en<? AND $val".$in('v')." GROUP BY v.sucursal_id";
        foreach(gcs_sql($db,$detailSql,$args([$start,$end])) as $r){$id=(int)$r['sucursal_id'];if(!isset($out[$id]))continue;$out[$id]['lineas']=(int)$r['lineas'];$out[$id]['piezas']=$piece==='NULL'?null:(int)$r['piezas'];}
        foreach($out as &$r){$r['lineas']??=0;if($piece!=='NULL')$r['piezas']??=0;}unset($r);
        // Costos históricos de las líneas originales (no ajustados por devoluciones); solo si cobertura 100%.
        if(gcs_table($db,'erp_pos_costos_venta')){
            $costCols=gcs_cols($db,'erp_pos_costos_venta');
            if(!array_diff(['detalle_id','costo_total'], $costCols)){
                foreach(gcs_sql($db,"SELECT v.sucursal_id,COUNT(*) lineas,COUNT(c.detalle_id) con_costo,COALESCE(SUM(c.costo_total),0) costo FROM pos_venta_detalle d JOIN pos_ventas v ON v.id=d.venta_id LEFT JOIN erp_pos_costos_venta c ON c.detalle_id=d.id WHERE v.pagada_en>=? AND v.pagada_en<? AND $val".$in('v')." GROUP BY v.sucursal_id",$args([$start,$end])) as $r){
                    $id=(int)$r['sucursal_id'];if(!isset($out[$id]))continue;$lines=(int)$r['lineas'];$covered=(int)$r['con_costo'];$out[$id]['cobertura_costos']=$lines>0?round($covered*100/$lines,1):null;
                    if($lines>0 && $covered===$lines){$out[$id]['costos_c']=gcs_money_c($r['costo']);$out[$id]['utilidad_c']=$out[$id]['ventas_c']-$out[$id]['costos_c'];$out[$id]['margen_pct']=$out[$id]['ventas_c']>0?round(100*$out[$id]['utilidad_c']/$out[$id]['ventas_c'],1):null;}
                }
                $notices[]='Utilidad bruta original = ventas originales − costo histórico de todas sus líneas, sin ajustes por devoluciones, impuestos ni otros gastos. Solo aparece con cobertura de costos del 100%.';
            }else $notices[]='No se muestran utilidades: el snapshot de costos no tiene las columnas necesarias.';
        }else $notices[]='Utilidad no disponible: falta el snapshot histórico erp_pos_costos_venta; no se estimarán costos actuales para ventas antiguas.';
    }else $notices[]='No está disponible pos_venta_detalle: no se muestran piezas ni margen.';
    $top=[];
    if($hasDetails && $piece!=='NULL'){
        foreach(gcs_sql($db,"SELECT d.producto_id,MAX(d.descripcion_snapshot) nombre,COALESCE(SUM($piece),0) piezas FROM pos_venta_detalle d JOIN pos_ventas v ON v.id=d.venta_id WHERE v.pagada_en>=? AND v.pagada_en<? AND $val".$in('v')." GROUP BY d.producto_id ORDER BY piezas DESC,d.producto_id ASC LIMIT 10",$args([$start,$end])) as $p){$top[]=['nombre'=>(string)$p['nombre'],'piezas'=>(int)$p['piezas']];}
    }
    // Medición de productividad: promedio ponderado por tickets realmente medidos.
    if($hasDetails && gcs_table($db,'erp_pos_eficiencia_cajeros')){
        $cols=gcs_cols($db,'erp_pos_eficiencia_cajeros');
        if(!array_diff(['venta_id','estado','total_seg','usuario_id','sucursal_id'],$cols)){
            foreach(gcs_sql($db,"SELECT v.sucursal_id,COUNT(*) medidos,AVG(e.total_seg) promedio FROM erp_pos_eficiencia_cajeros e JOIN pos_ventas v ON v.id=e.venta_id WHERE e.estado='COMPLETADA' AND e.total_seg IS NOT NULL AND e.usuario_id=v.usuario_id AND e.sucursal_id=v.sucursal_id AND v.pagada_en>=? AND v.pagada_en<? AND $val".$in('v')." GROUP BY v.sucursal_id",$args([$start,$end])) as $r){$id=(int)$r['sucursal_id'];if(!isset($out[$id]))continue;$out[$id]['medidas']=(int)$r['medidos'];$out[$id]['tiempo_seg']=round((float)$r['promedio'],1);}
            foreach($out as &$r){$r['medidas']??=0;}unset($r);
            $notices[]='Tiempo promedio medido únicamente en tickets con registro COMPLETADA. Se indica la cobertura para evitar comparaciones injustas.';
        }else $notices[]='No hay columnas compatibles de eficiencia; no se mostrará el promedio de tiempos.';
    }else $notices[]='El cronómetro no está disponible: tiempo de atención sin medición.';
    if(gcs_table($db,'pos_existencias_farmacia')){
        foreach(gcs_sql($db,"SELECT st.sucursal_id,COUNT(*) skus,COALESCE(SUM(CASE WHEN st.existencia<=0 THEN 1 ELSE 0 END),0) agotados,COALESCE(SUM(st.existencia),0) piezas_stock FROM pos_existencias_farmacia st WHERE 1=1".$in('st')." GROUP BY st.sucursal_id",$args([])) as $r){$id=(int)$r['sucursal_id'];if(!isset($out[$id]))continue;$out[$id]['inventario_skus']=(int)$r['skus'];$out[$id]['agotados']=(int)$r['agotados'];$out[$id]['piezas_stock']=(int)$r['piezas_stock'];}
        foreach($out as &$r){$r['inventario_skus']??=0;$r['agotados']??=0;$r['piezas_stock']??=0;}unset($r);
        $notices[]='Inventario es una fotografía actual de existencias POS, NO histórica del periodo. Tapachula y Toscana pueden compartir inventario físico: no sumar existencias como stock único de la empresa.';
    }else $notices[]='Sin existencias POS: inventario no disponible.';
    foreach(gcs_sql($db,"SELECT c.sucursal_id,t.estado,COUNT(*) n,COALESCE(SUM(CASE WHEN t.diferencia_efectivo<0 THEN -t.diferencia_efectivo ELSE 0 END),0) faltante,COALESCE(SUM(CASE WHEN t.diferencia_efectivo>0 THEN t.diferencia_efectivo ELSE 0 END),0) sobrante FROM pos_turnos t JOIN pos_cajas c ON c.id=t.caja_id WHERE ((t.estado='CERRADO' AND t.cerrado_en>=? AND t.cerrado_en<?) OR (t.estado='ABIERTO' AND t.abierto_en>=? AND t.abierto_en<?))".$in('c')." GROUP BY c.sucursal_id,t.estado",$args([$start,$end,$start,$end])) as $r){
        $id=(int)$r['sucursal_id'];if(!isset($out[$id]))continue;
        if($r['estado']==='ABIERTO')$out[$id]['turnos_abiertos']+=(int)$r['n'];
        if($r['estado']==='CERRADO'){$out[$id]['turnos_cerrados']+=(int)$r['n'];$out[$id]['faltante_c']+=gcs_money_c($r['faltante']);$out[$id]['sobrante_c']+=gcs_money_c($r['sobrante']);}
    }
    // Retiro incluye los reembolsos en efectivo de caja: no se etiqueta como bóveda.
    if(gcs_table($db,'pos_movimientos_caja')){
        foreach($out as &$r){$r['retiros_c']=0;}unset($r);
        foreach(gcs_sql($db,"SELECT c.sucursal_id,COALESCE(SUM(m.monto),0) total FROM pos_movimientos_caja m JOIN pos_turnos t ON t.id=m.turno_id JOIN pos_cajas c ON c.id=t.caja_id WHERE m.tipo='RETIRO' AND m.creado_en>=? AND m.creado_en<?".$in('c')." GROUP BY c.sucursal_id",$args([$start,$end])) as $r){$id=(int)$r['sucursal_id'];if(isset($out[$id]))$out[$id]['retiros_c']=gcs_money_c($r['total']);}
        $notices[]='Retiros POS incluyen movimientos de devolución de efectivo; no representan exclusivamente entregas a bóveda.';
    }else $notices[]='Sin pos_movimientos_caja: retiros no disponibles.';
    $notices[]='Cajas por fecha de cierre (o fecha de apertura en turnos abiertos); retiros por su fecha de movimiento. Estas métricas no deben sumarse a las ventas.';
    return ['rows'=>array_values($out),'branches'=>$branches,'avisos'=>array_values(array_unique($notices)),'desde'=>$from,'hasta'=>$to,'top_productos'=>$top,'meta'=>['tiene_reversiones'=>$hasRev]];
}
