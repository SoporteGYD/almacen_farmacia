<?php
declare(strict_types=1);
/** Upgrade incremental del control de retiros: sin umbral obligatorio, protege el fondo inicial. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Sólo terminal PHP.');}
$base=dirname(__DIR__);$src=__DIR__.'/retiros_mayoreo_v2_payload';
$apply=in_array('--apply',$argv,true);$check=in_array('--check',$argv,true);
if($apply===$check){fwrite(STDERR,"Uso: php tools/instalar_retiros_mayoreo_v2.php --check | --apply\n");exit(2);}
$manifest=json_decode((string)file_get_contents($src.'/manifest_v1.json'),true,512,JSON_THROW_ON_ERROR);
$errors=[];$changed=[];$already=0;
foreach($manifest as $rel=>$oldHash){
 $file=$base.'/'.$rel;$newFile=$src.'/'.$rel;
 if(!is_file($file)){$errors[]="Falta el módulo anterior: $rel. Primero instala Arqueos/Retiros V1.";continue;}
 if(!is_file($newFile)){$errors[]="Falta el archivo en ZIP V2: $rel";continue;}
 $cur=hash_file('sha256',$file);$up=hash_file('sha256',$newFile);
 if($cur===$up){$already++;continue;}
 if(!hash_equals($oldHash,$cur)){$errors[]="INCOMPATIBLE: $rel contiene cambios posteriores. Se conservará sin sobrescribir.";continue;}
 $changed[$rel]=(string)file_get_contents($newFile);
}
// Dos cambios precisos en el POS y en los movimientos gerenciales; todo lo demás se conserva.
$patches=[
 'public/pos.php'=>[
   'Aviso desde $3,000; conservar $1,000' => 'Aviso informativo desde $3,000; retiros libres conservando fondo inicial',
 ],
 'public/erp_control_cajas.php'=>[
   "if(\$tipo==='RETIRO' && \$tot['esperado']<300000)throw new DomainException('Los retiros solo proceden al alcanzar \$3,000 en caja.');\n                if(\$tipo==='RETIRO' && \$centavos>\$tot['esperado']-100000)throw new DomainException('Un retiro debe dejar al menos \$1,000 en la caja.');" =>
   "if(\$tipo==='RETIRO'){\n                    // El supervisor puede hacer varios retiros parciales sin cerrar turno.\n                    \$fondoCaja=cg_cents((string)cg_q(\$db,'SELECT fondo_inicial FROM pos_turnos WHERE id=?',[\$turnoId])->fetchColumn());\n                    if(\$centavos>max(0,\$tot['esperado']-\$fondoCaja))throw new DomainException('El retiro debe conservar íntegro el fondo inicial del turno.');\n                    if(cg_q(\$db,\"SELECT 1 FROM erp_retiros_supervisados WHERE turno_id=? AND estado='SOLICITADO' LIMIT 1\",[\$turnoId])->fetchColumn())throw new DomainException('Hay una entrega de retiro pendiente de confirmar o rechazar; resuélvela primero.');\n                }",
 ],
];
foreach($patches as $rel=>$rules){
 $file=$base.'/'.$rel;
 if(!is_file($file)){$errors[]="Falta $rel";continue;}
 $current=(string)file_get_contents($file);$updated=$current;
 foreach($rules as $before=>$after){
  if(str_contains($updated,$after))continue;
  if(substr_count($updated,$before)!==1){$errors[]="INCOMPATIBLE: no se encontró el cambio previo en $rel (o está modificado).";continue;}
  $updated=str_replace($before,$after,$updated);
 }
 if($updated!==$current)$changed[$rel]=$updated;
 else $already++;
}
if($errors){foreach($errors as $e)fwrite(STDERR,$e.PHP_EOL);fwrite(STDERR,"No se modificó ningún archivo.\n");exit(1);}
if($check){echo count($changed)?'COMPATIBLE':'YA INSTALADO';echo '. Archivos a actualizar: '.count($changed).'; sin cambios: '.$already.".\n--check no modifica archivos ni base de datos.\n";exit(0);}
// Resguardo: sólo la base de laboratorio 'prueba', como en la versión anterior.
require_once $base.'/app/config/database.php';
try{
 $db=(new Database())->connect();$bd=(string)$db->query('SELECT DATABASE()')->fetchColumn();
 if($bd!=='prueba')throw new RuntimeException('Base conectada: '.$bd.'. Sólo se permite aplicar en prueba.');
 foreach(['erp_retiros_supervisados','pos_turnos','pos_movimientos_caja'] as $table){
  $stmt=$db->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$stmt->execute([$table]);
  if(!$stmt->fetchColumn())throw new RuntimeException('Falta tabla requerida: '.$table);
 }
 if(!$changed){echo "YA INSTALADO. No se efectuaron cambios.\n";exit(0);}
 $backup=$base.'/backups_gyd_retiros_mayoreo_v2_'.date('Ymd_His');
 if(!@mkdir($backup,0775,true) && !is_dir($backup))throw new RuntimeException('No se pudo crear carpeta de respaldo.');
 foreach($changed as $rel=>$value){
  $dst=$backup.'/'.$rel;
  if(!is_dir(dirname($dst)) && !@mkdir(dirname($dst),0775,true) && !is_dir(dirname($dst)))throw new RuntimeException('No se pudo crear carpeta de respaldo '.$rel);
  if(!copy($base.'/'.$rel,$dst))throw new RuntimeException('No se pudo respaldar '.$rel);
 }
 $written=[];
 try{
  foreach($changed as $rel=>$value){
   $path=$base.'/'.$rel;
   // Bloqueo y reemplazo del contenido sólo después de respaldar todos los archivos.
   if(file_put_contents($path,$value,LOCK_EX)===false)throw new RuntimeException('No se pudo actualizar '.$rel);
   $written[]=$rel;
  }
 }catch(Throwable $e){
  foreach($written as $rel){copy($backup.'/'.$rel,$base.'/'.$rel);}
  throw $e;
 }
 echo 'ACTUALIZACIÓN V2 COMPLETADA. Archivos modificados: '.count($changed).".\nRespaldo: $backup\n";
 echo "No se modificó la base de datos. Retiro libre con fondo inicial por turno, múltiples retiros, sin corte intermedio.\n";
}catch(Throwable $e){fwrite(STDERR,'NO INSTALADO: '.$e->getMessage().PHP_EOL);exit(1);}
