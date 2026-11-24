<?php
declare(strict_types=1);
/** Rendimiento de Cajeros | Solo lectura, datos reales del POS y cronometro opcional. */
function grd_h(mixed $x): string { return htmlspecialchars((string)($x??''), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function grd_fecha(string $x): bool { $d=DateTimeImmutable::createFromFormat('!Y-m-d',$x);return $d!==false && $d->format('Y-m-d')===$x; }
function grd_periodo(string $desde,string $hasta): array {
    if(!grd_fecha($desde)||!grd_fecha($hasta)||$desde>$hasta)throw new DomainException('Selecciona fechas válidas.');
    $ini=new DateTimeImmutable($desde);$fin=new DateTimeImmutable($hasta);
    if($ini->diff($fin)->days>92)throw new DomainException('Consulta un máximo de 93 días cada vez.');
    return [$desde.' 00:00:00', $fin->modify('+1 day')->format('Y-m-d').' 00:00:00'];
}
function grd_query(PDO $db,string $sql,array $params=[]): array {$st=$db->prepare($sql);$st->execute($params);return $st->fetchAll(PDO::FETCH_ASSOC);}
function grd_tabla(PDO $db,string $name):bool {return (bool)grd_query($db,'SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1',[$name]);}
function grd_columnas(PDO $db,string $table):array {return array_column(grd_query($db,'SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=?',[$table]),'column_name');}
function grd_centavos(mixed $n):int {
 $str=trim((string)($n??'0'));
 if(!preg_match('/^(-?)(\d{1,12})(?:\.(\d{1,2}))?$/D',$str,$m))throw new DomainException('Importe no válido en la base de datos.');
 $v=((int)$m[2]*100+(int)str_pad($m[3]??'',2,'0',STR_PAD_RIGHT));
 return ($m[1]==='-'?-$v:$v);
}
function grd_dinero(int $c):string{return ($c<0?'-':'').'$'.number_format(abs($c)/100,2,'.',',');}
function grd_tiempo(?float $sec):string {
 if($sec===null)return 'Sin medición';
 $sec=max(0,(int)round($sec));return $sec>=3600?sprintf('%d h %02d min',intdiv($sec,3600),intdiv($sec%3600,60)):sprintf('%d:%02d',intdiv($sec,60),$sec%60);
}
function grd_csv_text(mixed $v): string {
 $t=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u',' ',(string)($v??''))??'';
 return preg_match('/^\s*[=+@\-]/u',$t)?"'".$t:$t;
}
function grd_permitido(array $u):bool {
 $role=strtoupper(trim((string)($u['rol_original']??$u['rol']??'')));
 return (int)($u['id']??0)>0 && in_array($role,['ADMINISTRADOR','ADMIN','DIRECCION'],true);
}
/** Cada fila representa un cajero + sucursal. Ventas por fecha pagada_en.
 *  Monto = ventas originales brutas, no utilidad ni venta neta tras devoluciones.
 *  Piezas = unidades de partidas originales. La medición es aproximada y opcional.
 */
function grd_reporte(PDO $db,string $desde,string $hasta,int $sucursal=0,int $cajero=0):array {
 [$ini,$fin]=grd_periodo($desde,$hasta);
 foreach(['usuarios','pos_ventas','pos_sucursales','pos_venta_detalle'] as $t){if(!grd_tabla($db,$t))throw new RuntimeException('Falta tabla del POS: '.$t);}
 $branches=grd_query($db,'SELECT id,nombre FROM pos_sucursales ORDER BY nombre');
 $cashiers=grd_query($db,"SELECT DISTINCT u.id,u.nombre FROM usuarios u JOIN pos_ventas v ON v.usuario_id=u.id WHERE v.pagada_en IS NOT NULL ORDER BY u.nombre,u.id");
 if($sucursal>0&&!in_array($sucursal,array_map(static fn($r)=>(int)$r['id'],$branches),true))throw new DomainException('Sucursal no válida.');
 if($cajero>0&&!in_array($cajero,array_map(static fn($r)=>(int)$r['id'],$cashiers),true))throw new DomainException('Cajero no válido.');
 $where="v.pagada_en>=? AND v.pagada_en<? AND v.estado IN ('PAGADA','CANCELADA')";
 $args=[$ini,$fin];
 if($sucursal>0){$where.=' AND v.sucursal_id=?';$args[]=$sucursal;}
 if($cajero>0){$where.=' AND v.usuario_id=?';$args[]=$cajero;}
 $base=grd_query($db,"SELECT v.usuario_id,v.sucursal_id,u.nombre cajero,s.nombre sucursal,COUNT(*) tickets,
   SUM(CASE WHEN v.estado='CANCELADA' THEN 1 ELSE 0 END) canceladas,
   COALESCE(SUM(v.total),0) importe
   FROM pos_ventas v JOIN usuarios u ON u.id=v.usuario_id JOIN pos_sucursales s ON s.id=v.sucursal_id
   WHERE $where GROUP BY v.usuario_id,v.sucursal_id,u.nombre,s.nombre ORDER BY u.nombre,s.nombre",$args);
 $partCols=grd_columnas($db,'pos_venta_detalle');
 $units=in_array('unidades_descontadas',$partCols,true)?'d.unidades_descontadas':'NULL';
 $fallback=in_array('piezas_por_presentacion',$partCols,true)?'d.cantidad*COALESCE(d.piezas_por_presentacion,1)':'d.cantidad';
 // Si falta equivalencia y hay presentaciones CAJA, no afirmar piezas precisas.
 $piezasExactas=in_array('unidades_descontadas',$partCols,true)||in_array('piezas_por_presentacion',$partCols,true);
 $pieces=[];
 foreach(grd_query($db,"SELECT v.usuario_id,v.sucursal_id,COUNT(*) lineas,COALESCE(SUM(COALESCE($units,$fallback)),0) piezas
    FROM pos_venta_detalle d JOIN pos_ventas v ON v.id=d.venta_id WHERE $where GROUP BY v.usuario_id,v.sucursal_id",$args) as $r){$pieces[(int)$r['usuario_id'].':'.(int)$r['sucursal_id']]=$r;}
 $avisos=[];
 if(!$piezasExactas)$avisos[]='El detalle no incluye equivalencias de presentaciones. Las unidades se mostrarán sin afirmar que todas son piezas físicas.';
 $hasTiming=grd_tabla($db,'erp_pos_eficiencia_cajeros');$times=[];
 if($hasTiming){
   $cols=grd_columnas($db,'erp_pos_eficiencia_cajeros');
   $core=['venta_id','estado','total_seg','captura_seg','pago_seg','cobro_red_seg','activo_seg','inactivo_seg','oculto_seg','errores_cobro','escaneos','busquedas','solicitudes_almacen'];
   $missing=array_diff($core,$cols);
   if($missing){$hasTiming=false;$avisos[]='El cronómetro instalado no tiene todas sus columnas; el reporte omitirá los tiempos. Consulta Diagnóstico de Tiempos.';}
   else {
    foreach(grd_query($db,"SELECT v.usuario_id,v.sucursal_id,COUNT(*) medidos,
      AVG(e.total_seg) prom_total,AVG(e.captura_seg) prom_captura,AVG(e.pago_seg) prom_pago,
      AVG(e.cobro_red_seg) prom_proceso,AVG(COALESCE(e.inactivo_seg,0)+COALESCE(e.oculto_seg,0)) prom_pausas,
      SUM(COALESCE(e.errores_cobro,0)) errores, SUM(COALESCE(e.escaneos,0)) escaneos,
      SUM(COALESCE(e.busquedas,0)) busquedas,SUM(COALESCE(e.solicitudes_almacen,0)) almacen
      FROM erp_pos_eficiencia_cajeros e JOIN pos_ventas v ON v.id=e.venta_id
      WHERE e.estado='COMPLETADA' AND e.total_seg IS NOT NULL AND $where
      AND e.usuario_id=v.usuario_id AND e.sucursal_id=v.sucursal_id
      GROUP BY v.usuario_id,v.sucursal_id",$args) as $t){$times[(int)$t['usuario_id'].':'.(int)$t['sucursal_id']]=$t;}
   }
 } else $avisos[]='No está instalada la medición erp_pos_eficiencia_cajeros: los tiempos se mostrarán como «Sin medición». No se crean datos artificiales.';
 $rows=[];$totals=['tickets'=>0,'importe'=>0,'canceladas'=>0,'piezas'=>0,'lineas'=>0,'medidos'=>0];
 foreach($base as $b){
  $id=(int)$b['usuario_id'].':'.(int)$b['sucursal_id'];$p=$pieces[$id]??[];$t=$times[$id]??[];
  $num=(int)$b['tickets'];$importe=grd_centavos($b['importe']);$medidos=(int)($t['medidos']??0);
  $row=['usuario_id'=>(int)$b['usuario_id'],'sucursal_id'=>(int)$b['sucursal_id'],'cajero'=>$b['cajero'],'sucursal'=>$b['sucursal'],
    'tickets'=>$num,'canceladas'=>(int)$b['canceladas'],'importe_c'=>$importe,'ticket_medio_c'=>$num>0?(int)round($importe/$num):null,
    'piezas'=>(int)($p['piezas']??0),'lineas'=>(int)($p['lineas']??0),'medidos'=>$medidos,
    'cobertura'=>$num>0?100*$medidos/$num:0,
    'prom_total'=>isset($t['prom_total'])?(float)$t['prom_total']:null,
    'prom_captura'=>isset($t['prom_captura'])?(float)$t['prom_captura']:null,
    'prom_pago'=>isset($t['prom_pago'])?(float)$t['prom_pago']:null,
    'prom_proceso'=>isset($t['prom_proceso'])?(float)$t['prom_proceso']:null,
    'prom_pausas'=>isset($t['prom_pausas'])?(float)$t['prom_pausas']:null,
    'errores'=>$hasTiming?(int)($t['errores']??0):null,
    'escaneos'=>$hasTiming?(int)($t['escaneos']??0):null,
    'busquedas'=>$hasTiming?(int)($t['busquedas']??0):null,
    'almacen'=>$hasTiming?(int)($t['almacen']??0):null];
  foreach(['tickets','canceladas','piezas','lineas','medidos'] as $key)$totals[$key]+=$row[$key];
  $totals['importe']+=$importe;$rows[]=$row;
 }
 $maxTickets=max(1,...array_map(static fn($r)=>(int)$r['tickets'],$rows));
 return ['sucursales'=>$branches,'cajeros'=>$cashiers,'rows'=>$rows,'totales'=>$totals,'avisos'=>$avisos,
   'tiene_medicion'=>$hasTiming,'piezas_exactas'=>$piezasExactas,'max_tickets'=>$maxTickets];
}
