<?php
declare(strict_types=1);
/** Utilidad opcional: DDL exclusivamente en la base prueba y solo con confirmación expresa. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo consola.');}
require_once dirname(__DIR__).'/app/config/database.php';
require_once __DIR__.'/gyd_optimizacion_payload/app/helpers/gyd_optimizacion.php';
$mode=$argv[1]??'';
if(!in_array($mode,['--check','--apply'],true)){fwrite(STDERR,"Uso: php tools/indices_rendimiento_v19.php --check | --apply CONFIRMO_RESPALDO\n");exit(2);}
if($mode==='--apply' && ($argv[2]??'')!=='CONFIRMO_RESPALDO'){
 fwrite(STDERR,"No se crearán índices sin CONFIRMO_RESPALDO. Guarda antes un backup y elige una ventana de mantenimiento.\n");exit(2);
}
try{
 $db=(new Database())->connect();go_check_db($db);
 $review=go_review($db);
 $ready=array_values(array_filter($review,static fn($r)=>$r['status']==='recomendado'));
 foreach($review as $r){echo strtoupper($r['status']).': '.$r['table'].' ['.implode(',',$r['cols']).']'.($r['index_actual']?' ('.$r['index_actual'].')':'').PHP_EOL;}
 if(!$ready){echo "No hay índices recomendados sin cobertura; no se ejecutó DDL.\n";exit(0);}
 echo "IMPORTANTE: CREATE INDEX es DDL y puede bloquear operaciones o fallar; no lo ejecutes mientras hay cajeros vendiendo.\n";
 foreach($ready as $r){
   $sql='CREATE INDEX `'.$r['name'].'` ON `'.$r['table'].'` ('.implode(',',array_map(static fn($c)=>'`'.$c.'`',$r['cols'])).')';
   if($mode==='--check'){echo 'PLAN: '.$sql.';'.PHP_EOL;continue;}
   // Releer el esquema inmediatamente antes de cada operación reduce riesgo de crear índices redundantes.
   $current=go_review($db);$match=null;
   foreach($current as $row)if($row['table']===$r['table']){$match=$row;break;}
   if(!$match || $match['status']!=='recomendado'){echo 'OMITIDO por cambio de esquema: '.$r['table'].PHP_EOL;continue;}
   try{$db->exec($sql);echo 'CREADO: '.$r['name'].PHP_EOL;}
   catch(Throwable $e){error_log('Indice G&D: '.$e->getMessage());fwrite(STDERR,'ERROR creando '.$r['name'].'. Los índices creados previamente NO se revierten automáticamente. Revisa el log y repite --check.'.PHP_EOL);exit(1);}
 }
 if($mode==='--check')echo "No se modificó la base. Para aplicar se requiere un respaldo y confirmación expresa.\n";
 else echo "Proceso finalizado. Ejecuta --check de nuevo y compara EXPLAIN antes/después.\n";
}catch(Throwable $e){fwrite(STDERR,'FALLO: '.$e->getMessage().PHP_EOL);exit(1);}
