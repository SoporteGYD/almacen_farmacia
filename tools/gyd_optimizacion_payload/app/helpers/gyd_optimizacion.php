<?php
declare(strict_types=1);
/** Farmacia G&D — revisión de rendimiento de solo lectura. */
function go_h(mixed $x):string{return htmlspecialchars((string)($x??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function go_role(array $u):bool {
    $role=strtoupper(trim((string)($u['rol_original']??$u['rol']??'')));
    return (int)($u['id']??0)>0 && in_array($role,['ADMIN','ADMINISTRADOR','DIRECCION','DUEÑO','DUENO','PROPIETARIO'],true);
}
function go_check_db(PDO $db):void {
    if((string)$db->query('SELECT DATABASE()')->fetchColumn()!=='prueba')throw new DomainException('El diagnóstico está limitado a la base de pruebas «prueba».');
}
function go_definitions():array {
 return [
  ['table'=>'pos_ventas','cols'=>['pagada_en','sucursal_id'],'name'=>'idx_gyd_opt_vtas_fecha_suc','desc'=>'Reportes de ventas por fecha de pago y sucursal.'],
  ['table'=>'pos_reversiones','cols'=>['creado_en','estado','tipo'],'name'=>'idx_gyd_opt_rev_fecha_estado','desc'=>'Devoluciones y cancelaciones por periodo.'],
  ['table'=>'pos_movimientos_caja','cols'=>['creado_en','tipo','turno_id'],'name'=>'idx_gyd_opt_mov_fecha_tipo','desc'=>'Retiros y movimientos de caja por fecha.'],
  ['table'=>'pos_turnos','cols'=>['estado','abierto_en'],'name'=>'idx_gyd_opt_turno_estado_fecha','desc'=>'Detección de turnos abiertos prolongados.'],
 ];
}
function go_tables(PDO $db):array {
 $s=$db->query('SELECT TABLE_NAME, ENGINE, COALESCE(TABLE_ROWS,0) as estimado, COALESCE(DATA_LENGTH,0) AS datos, COALESCE(INDEX_LENGTH,0) AS indices FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()');
 $out=[];foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r)$out[(string)$r['TABLE_NAME']]=$r;
 return $out;
}
function go_columns(PDO $db):array {
 $s=$db->query('SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()');$out=[];
 foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r)$out[(string)$r['TABLE_NAME']][(string)$r['COLUMN_NAME']]=true;
 return $out;
}
function go_indexes(PDO $db):array {
 $s=$db->query('SELECT TABLE_NAME,INDEX_NAME,COLUMN_NAME,SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX');$out=[];
 foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r)$out[(string)$r['TABLE_NAME']][(string)$r['INDEX_NAME']][]=(string)$r['COLUMN_NAME'];
 return $out;
}
function go_review(PDO $db):array {
 go_check_db($db);
 $tables=go_tables($db);$columns=go_columns($db);$indexes=go_indexes($db);$items=[];
 foreach(go_definitions() as $d){
   $table=$d['table'];$existing=$tables[$table]??null;$status='recomendado';$idx='';
   if(!$existing){$status='no disponible';}
   elseif(strtoupper((string)$existing['ENGINE'])!=='INNODB'){$status='revisar motor';}
   elseif(array_diff($d['cols'],array_keys($columns[$table]??[]))){$status='incompatible';}
   else{
     foreach(($indexes[$table]??[]) as $name=>$cols){if(array_slice($cols,0,count($d['cols']))===$d['cols']){$status='cubierto';$idx=$name;break;}}
     if($status==='recomendado' && array_key_exists($d['name'],$indexes[$table]??[])){$status='revisar nombre';$idx=$d['name'];}
   }
   $items[]=$d+['status'=>$status,'index_actual'=>$idx,'filas_estimadas'=>(int)($existing['estimado']??0),'datos_bytes'=>(int)($existing['datos']??0),'indice_bytes'=>(int)($existing['indices']??0)];
 }
 return $items;
}
/** EXPLAIN únicamente: no ejecuta el SELECT completo. */
function go_explains(PDO $db,array $tables):array {
 $queries=[
   ['Ventas por fecha','pos_ventas',"SELECT sucursal_id,COUNT(*) AS tickets,SUM(total) AS importe FROM pos_ventas WHERE pagada_en>=DATE_SUB(NOW(),INTERVAL 7 DAY) GROUP BY sucursal_id"],
   ['Movimientos de caja','pos_movimientos_caja',"SELECT turno_id,SUM(monto) AS total FROM pos_movimientos_caja WHERE creado_en>=DATE_SUB(NOW(),INTERVAL 7 DAY) AND tipo='RETIRO' GROUP BY turno_id"],
   ['Devoluciones por fecha','pos_reversiones',"SELECT tipo,SUM(importe) AS total FROM pos_reversiones WHERE creado_en>=DATE_SUB(NOW(),INTERVAL 7 DAY) AND estado='APLICADA' GROUP BY tipo"],
   ['Turnos prolongados','pos_turnos',"SELECT id FROM pos_turnos WHERE estado='ABIERTO' AND abierto_en<DATE_SUB(NOW(),INTERVAL 12 HOUR) LIMIT 30"],
 ];$out=[];
 foreach($queries as [$label,$table,$sql]){
    if(!isset($tables[$table])){$out[]=['label'=>$label,'status'=>'No disponible','detalle'=>'Tabla ausente'];continue;}
    try{
      $r=$db->query('EXPLAIN '.$sql)->fetchAll(PDO::FETCH_ASSOC);$keys=[];$rows=0;$scan=[];
      foreach($r as $p){$keys[]=(string)($p['key']??'');$rows+=(int)($p['rows']??0);$scan[]=(string)($p['type']??'?');}
      $out[]=['label'=>$label,'status'=>'Consultado','detalle'=>'Clave: '.implode(', ',array_filter($keys)?:['sin índice elegido']).' · Tipo: '.implode(', ',$scan).' · Filas estimadas del plan: '.$rows];
    }catch(Throwable $e){error_log('G&D EXPLAIN '.$label.': '.$e->getMessage());$out[]=['label'=>$label,'status'=>'No evaluado','detalle'=>'Revisar esquema de esta consulta.'];}
 }
 return $out;
}
/** Muestra de operaciones acotadas, no un benchmark representativo de producción. */
function go_samples(PDO $db,array $tables):array {
 $queries=[['Conexión','SELECT 1']];
 if(isset($tables['pos_turnos']))$queries[]=['Turnos','SELECT id FROM pos_turnos WHERE estado=\'ABIERTO\' LIMIT 30'];
 if(isset($tables['pos_ventas']))$queries[]=['Últimos tickets','SELECT id FROM pos_ventas ORDER BY id DESC LIMIT 30'];
 if(isset($tables['pos_existencias_farmacia']))$queries[]=['Existencias','SELECT producto_id FROM pos_existencias_farmacia ORDER BY id DESC LIMIT 30'];
 $out=[];foreach($queries as [$label,$sql]){
    try{$start=hrtime(true);$rows=$db->query($sql)->fetchAll(PDO::FETCH_NUM);$ms=(hrtime(true)-$start)/1000000;
       $out[]=['label'=>$label,'ms'=>round($ms,2),'filas'=>count($rows),'status'=>'Medido'];}
    catch(Throwable $e){error_log('G&D muestra '.$label.': '.$e->getMessage());$out[]=['label'=>$label,'ms'=>null,'filas'=>0,'status'=>'No disponible'];}
 }
 return $out;
}
function go_csv_cell(mixed $v):string {
 $s=(string)($v??'');if(preg_match('/^\s*[=+@\-]/u',$s))return "'".$s;return $s;
}
