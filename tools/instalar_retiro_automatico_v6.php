<?php
declare(strict_types=1);
/** G&D | V6 | Retiro automático por excedente (fondo protegido).
 * Parche quirúrgico de la versión V5/V5.1. No sobrescribe cambios ajenos.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo se ejecuta por consola.'); }
$check=in_array('--check',$argv,true);$apply=in_array('--apply',$argv,true);
if ($check === $apply) {fwrite(STDERR,"Uso: php tools/instalar_retiro_automatico_v6.php --check | --apply\n");exit(2);}
$root=dirname(__DIR__);$payload=__DIR__.'/retiro_automatico_v6_payload';
try {$rules=json_decode((string)file_get_contents($payload.'/replacements.json'),true,512,JSON_THROW_ON_ERROR);}
catch(Throwable $e){fwrite(STDERR,"Paquete incompleto: ".$e->getMessage()."\n");exit(1);}
$changes=[];$errors=[];$already=0;
foreach($rules as $rel=>$steps){
 $path=$root.'/'.$rel;
 if(!is_file($path)){$errors[]='FALTA: '.$rel.'; requiere los retiros directos V5.1.';continue;}
 $current=(string)file_get_contents($path);$updated=$current;
 foreach($steps as $i=>$step){
  $before=$step['old'];$after=$step['new'];
  $oldCount=substr_count($updated,$before);$newCount=substr_count($updated,$after);
  if($newCount===1){/* Cambio instalado previamente. La plantilla nueva puede contener fragmentos de la anterior. */}
  elseif($oldCount===1){$updated=str_replace($before,$after,$updated);}
  else{$errors[]='INCOMPATIBLE: '.$rel.' (seccion '.($i+1).'). Archivo modificado o parche anterior incompleto; no se sobrescribe.';break;}
 }
 if($updated!==$current)$changes[$rel]=$updated;else $already++;
}
if(!is_file($root.'/app/helpers/gyd_retiros.php'))$errors[]='Falta el módulo base de retiros /app/helpers/gyd_retiros.php.';
$testRelative='tests/probar_retiro_automatico_v6.php';$testFrom=$payload.'/probar_retiro_automatico_v6.php';
if(!is_file($testFrom))$errors[]='No se encontró el archivo de pruebas en el ZIP.';
elseif(is_file($root.'/'.$testRelative)&&hash_file('sha256',$root.'/'.$testRelative)!==hash_file('sha256',$testFrom))$errors[]='INCOMPATIBLE: '.$testRelative.' ya contiene otro contenido.';
if($errors){foreach($errors as $err)fwrite(STDERR,$err."\n");fwrite(STDERR,"NO SE MODIFICÓ NINGÚN ARCHIVO.\n");exit(1);}
$needsTest=!is_file($root.'/'.$testRelative);
if($check){echo ($changes||$needsTest?'COMPATIBLE':'YA INSTALADO')." | PHP a actualizar: ".count($changes)." | test nuevo: ".($needsTest?'si':'no')."\n";
 echo "Monto automático = efectivo esperado - fondo inicial; recálculo dentro de la transacción. Sin cambiar ventas ni stock.\n";exit(0);}
if(!$changes&&!$needsTest){echo "YA INSTALADO. No se realizaron modificaciones.\n";exit(0);}
try{
 require_once $root.'/app/config/database.php';
 $db=(new Database())->connect();$base=(string)$db->query('SELECT DATABASE()')->fetchColumn();
 if($base!=='prueba')throw new RuntimeException('Se requiere BD prueba. Detectada: '.$base);
 $backup=$root.'/backups_gyd_retiro_auto_v6_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
 if(!mkdir($backup,0775,true)&&!is_dir($backup))throw new RuntimeException('No se pudo crear carpeta de respaldo.');
 foreach($changes as $rel=>$content){$dest=$backup.'/'.$rel;@mkdir(dirname($dest),0775,true);
  if(!copy($root.'/'.$rel,$dest))throw new RuntimeException('No pudo respaldarse '.$rel);
 }
 $done=[];$newTest=false;
 try{
  foreach($changes as $rel=>$content){$dest=$root.'/'.$rel;$temp=$dest.'.gydtemp.'.bin2hex(random_bytes(3));
   if(file_put_contents($temp,$content,LOCK_EX)===false||!rename($temp,$dest))throw new RuntimeException('No se pudo actualizar '.$rel);
   $done[]=$rel;
  }
  if($needsTest){@mkdir($root.'/tests',0775,true);if(!copy($testFrom,$root.'/'.$testRelative))throw new RuntimeException('No se pudo copiar test.');$newTest=true;}
 }catch(Throwable $e){foreach($done as $rel){@copy($backup.'/'.$rel,$root.'/'.$rel);}if($newTest)@unlink($root.'/'.$testRelative);throw $e;}
 echo "INSTALADO CORRECTAMENTE | ".count($changes)." archivos modificados.\n";
 echo "Respaldo: $backup\n";
 echo "La cajera ve el monto sugerido sin escribirlo; se revalida antes de guardar.\n";
}catch(Throwable $e){fwrite(STDERR,"INSTALACIÓN DETENIDA: ".$e->getMessage()."\n");exit(1);}
