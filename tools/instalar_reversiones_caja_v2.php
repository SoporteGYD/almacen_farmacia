<?php
declare(strict_types=1);
/** G&D 2026-10-28. Parche quirúrgico de reversas POS vs. caja, sin migración SQL. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo consola.');}
$check=in_array('--check',$argv,true);$apply=in_array('--apply',$argv,true);
if($check===$apply){fwrite(STDERR,"Uso: php tools/instalar_reversiones_caja_v2.php --check | --apply\n");exit(2);}
$root=dirname(__DIR__);$payload=__DIR__.'/reversiones_v2_payload';
try {$rules=json_decode((string)file_get_contents($payload.'/replacements.json'),true,512,JSON_THROW_ON_ERROR);}
catch(Throwable $e){fwrite(STDERR,"No se encontró el paquete de reemplazos: ".$e->getMessage()."\n");exit(2);}
$changes=[];$errors=[];$already=0;
foreach($rules as $rel=>$steps){
 if(str_contains($rel,'..')||str_starts_with($rel,'/')||str_contains($rel,'\\')){$errors[]='Ruta inválida '.$rel;continue;}
 $path=$root.'/'.$rel;
 if(!is_file($path)){$errors[]='FALTA '.$rel.' (se necesita la versión con retiros V5.1 y conciliación diaria)';continue;}
 $current=(string)file_get_contents($path);$after=$current;
 foreach($steps as $index=>$step){
  $old=(string)$step['old'];$new=(string)$step['new'];
  if(substr_count($after,$new)===1)continue;
  if(substr_count($after,$old)!==1){$errors[]='INCOMPATIBLE '.$rel.' / reemplazo '.($index+1).' (archivo diferente al esperado)';break;}
  $after=str_replace($old,$new,$after);
 }
 if($after!==$current)$changes[$rel]=$after;else $already++;
}
$copyFiles=[
 'public/erp_pos_reversion_ticket.php'=>'public/erp_pos_reversion_ticket.php',
 'tests/probar_reversiones_caja_v2.php'=>'tests/probar_reversiones_caja_v2.php',
];
$newFiles=[];
foreach($copyFiles as $rel=>$from){
 $original=$payload.'/'.$from;
 if(!is_file($original)){$errors[]='Paquete incompleto: '.$from;continue;}
 if(is_file($root.'/'.$rel)){
  if(hash_file('sha256',$root.'/'.$rel)!==hash_file('sha256',$original))$errors[]='INCOMPATIBLE archivo existente '.$rel.' (no se sobrescribirá).';
 }else{$newFiles[$rel]=$original;}
}
if($errors){foreach($errors as $e)fwrite(STDERR,$e."\n");fwrite(STDERR,"DETENIDO. No se modificó ningún archivo.\n");exit(1);}
if($check){echo ($changes||$newFiles?'COMPATIBLE':'YA INSTALADO')." | existentes a parchear: ".count($changes)." | archivos nuevos: ".count($newFiles)."\n";
 echo "Sin SQL nuevo. Incluye recibo 80 mm, controles de efectivo y cálculo consistente entre POS, retiros y conciliación diaria.\n";exit(0);}
if(!$changes&&!$newFiles){echo "YA INSTALADO. Sin cambios.\n";exit(0);}
try{
 require_once $root.'/app/config/database.php';
 $db=(new Database())->connect();$dbname=(string)$db->query('SELECT DATABASE()')->fetchColumn();
 if($dbname!=='prueba')throw new RuntimeException('BASE INCORRECTA: '.$dbname.'. Este instalador requiere exclusivamente prueba.');
 foreach(['pos_reversiones','pos_ventas','pos_pagos','pos_turnos','pos_movimientos_caja','pos_venta_origenes'] as $table){
  $st=$db->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');$st->execute([$table]);
  if(!$st->fetchColumn())throw new RuntimeException('Falta tabla esperada: '.$table.'. Antes instala la migración de reversas POS.');
 }
 $st=$db->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pos_reversiones' AND column_name='turno_reembolso_id' LIMIT 1");$st->execute();
 if(!$st->fetchColumn())throw new RuntimeException('Falta columna turno_reembolso_id en pos_reversiones.');
 $dir=$root.'/backups_gyd_reversiones_v2_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
 if(!mkdir($dir,0775,true))throw new RuntimeException('No se pudo crear un respaldo local de los PHP originales.');
 foreach($changes as $rel=>$source){
  $backup=$dir.'/'.$rel;
  if(!is_dir(dirname($backup))&&!mkdir(dirname($backup),0775,true))throw new RuntimeException('No se pudo preparar respaldo '.$rel);
  if(!copy($root.'/'.$rel,$backup))throw new RuntimeException('No se pudo respaldar '.$rel);
 }
 $touched=[];$added=[];
 try {
  foreach($changes as $rel=>$source){
   $dest=$root.'/'.$rel;$tmp=$dest.'.tmp_gyd_'.bin2hex(random_bytes(3));
   if(file_put_contents($tmp,$source,LOCK_EX)===false)throw new RuntimeException('No pudo crear temporal '.$rel);
   if(!rename($tmp,$dest)){@unlink($tmp);throw new RuntimeException('No pudo reemplazar '.$rel);}
   $touched[]=$rel;
  }
  foreach($newFiles as $rel=>$source){
   $dest=$root.'/'.$rel;
   if(!is_dir(dirname($dest))&&!mkdir(dirname($dest),0775,true))throw new RuntimeException('No se pudo crear directorio '.$rel);
   if(!copy($source,$dest))throw new RuntimeException('No se pudo instalar '.$rel);
   $added[]=$rel;
  }
 }catch(Throwable $e){
  foreach($touched as $rel){@copy($dir.'/'.$rel,$root.'/'.$rel);}
  foreach($added as $rel){@unlink($root.'/'.$rel);}
  throw $e;
 }
 echo "INSTALACIÓN FINALIZADA en base prueba. Archivos ajustados: ".count($changes)."; nuevos: ".count($newFiles)."\n";
 echo "Respaldo automático: $dir\n";
 echo "Siguiente: php tests/probar_reversiones_caja_v2.php y pruebas manuales de caja real con importes de prueba.\n";
}catch(Throwable $e){fwrite(STDERR,"ERROR: ".$e->getMessage()."\nNo se efectuaron cambios a la base de datos. Si algo se escribió parcialmente, revisa el respaldo indicado.\n");exit(1);}
