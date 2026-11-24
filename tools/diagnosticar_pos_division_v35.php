<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo CLI');}
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/gyd_pos_saldo_dividido.php';
if(!in_array('--run',$argv,true)||!in_array('--expect=prueba',$argv,true)){
 fwrite(STDERR,"Uso: php tools/diagnosticar_pos_division_v35.php --run --expect=prueba --port=3307\n");exit(2);
}
try{
 $db=(new Database())->connect();$env=$db->query('SELECT DATABASE() db, @@port port')->fetch(PDO::FETCH_ASSOC);
 if(strtolower((string)($env['db']??''))!=='prueba'||(int)$env['port']!==3307)throw new RuntimeException('Se permite solo prueba:3307.');
 echo "AUDITORIA POS V35 - LECTURA SOLAMENTE\n";
 foreach([1=>'CIUDAD HIDALGO',2=>'TUXTLA GUTIERREZ'] as $sid=>$nombre){
  $nombres=$sid===1?['CIUDAD HIDALGO','CD HIDALGO']:['TUXTLA','TUXTLA GUTIERREZ','TUXTLA GUTIÉRREZ'];
  $in=implode(',',array_fill(0,count($nombres),'?'));
  $q=$db->prepare('SELECT DISTINCT producto_id FROM pos_existencias_farmacia WHERE sucursal_id=? UNION SELECT DISTINCT producto_id FROM erp_pos_farmacia_control WHERE sucursal_id=? UNION SELECT DISTINCT producto_id FROM producto_existencias WHERE UPPER(TRIM(sucursal)) COLLATE utf8mb4_general_ci IN ('.$in.')');
  $q->execute(array_merge([$sid,$sid],$nombres));
  $ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));$ok=0;$bad=0;$sin=0;$samples=[];
  foreach($ids as $pid){$v=gyd_pos_saldo_dividido($db,$sid,$pid);if($v['farmacia']===null)$sin++;
   if($v['conciliado']){$ok++;continue;}$bad++;if(count($samples)<15)$samples[]=['producto'=>$pid,'general'=>$v['general'],'farmacia'=>$v['farmacia'],'almacen'=>$v['almacen'],'diferencia'=>$v['general']===null||$v['farmacia']===null?null:$v['general']-$v['farmacia']-$v['almacen']];
  }
  echo "\n$nombre (id $sid): conciliados=$ok, sin_cuadre=$bad, sin_conteo_farmacia=$sin, productos=".count($ids)."\n";
  foreach($samples as $v)echo '  '.json_encode($v,JSON_UNESCAPED_UNICODE)."\n";
 }
 echo "\nNo se modificó ningún registro. Para corregir cantidades verifica el conteo físico desde Catálogo Maestro.\n";
}catch(Throwable $e){fwrite(STDERR,'ERROR: '.$e->getMessage()."\n");exit(2);}
