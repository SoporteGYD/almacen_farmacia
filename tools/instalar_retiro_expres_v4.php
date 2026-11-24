<?php
declare(strict_types=1);
/** Actualiza retiro V3 a captura de importe único. Sin permisos nuevos ni cambio de ventas. */
if (PHP_SAPI !== 'cli') {http_response_code(403); exit('Solo CLI.');}
$check=in_array('--check',$argv,true);
$apply=in_array('--apply',$argv,true);
if ($check===$apply){fwrite(STDERR,"Uso: php tools/instalar_retiro_expres_v4.php --check | --apply\n");exit(2);}
$project=dirname(__DIR__);
$payload=__DIR__.'/retiro_expres_v4_payload';
$approved=json_decode((string)file_get_contents($payload.'/v3_sha256.json'),true,512,JSON_THROW_ON_ERROR);
$changes=[]; $errors=[]; $already=0;
foreach($approved as $rel=>$v3Hash){
 $file=$project.'/'.$rel;
 $source=$payload.'/'.$rel;
 if(!is_file($file)||!is_file($source)){$errors[]='FALTA: '.$rel;continue;}
 $currentHash=hash_file('sha256',$file);
 $targetHash=hash_file('sha256',$source);
 if($currentHash===$targetHash){$already++;continue;}
 if($currentHash!==$v3Hash){$errors[]='INCOMPATIBLE: '.$rel.' no coincide con V3 o V4. No se sobrescribirá.';continue;}
 $changes[$rel]=(string)file_get_contents($source);
}
foreach(['app/helpers/gyd_retiros.php','app/config/database.php','app/helpers/pos_corte_denominaciones.php','public/pos_corte_retiros.php','public/pos_arqueos.php'] as $rel){
 if(!is_file($project.'/'.$rel))$errors[]='Falta módulo requerido: '.$rel;
}
$testTarget=$project.'/tests/probar_retiro_expres.php';
$testSource=$payload.'/tests/probar_retiro_expres.php';
$testNew=false;
if(!is_file($testTarget))$testNew=true;
elseif(hash_file('sha256',$testTarget)!==hash_file('sha256',$testSource))$errors[]='INCOMPATIBLE: test previo con cambios manuales; no se reemplaza.';
if($errors){foreach($errors as $e)fwrite(STDERR,$e."\n");fwrite(STDERR,"NO SE MODIFICÓ NINGÚN ARCHIVO.\n");exit(1);}
if($check){echo (count($changes)||$testNew?'COMPATIBLE':'YA INSTALADO').'. PHP por actualizar: '.count($changes).'; test nuevo: '.($testNew?'sí':'no').".\n";echo "El registro de retiros requiere permitir NULL en erp_retiros_supervisados.contado_declarado para no simular un arqueo. Esta migración ocurre solo en --apply y solo en prueba.\n";exit(0);}
try{
 require_once $project.'/app/config/database.php';
 $db=(new Database())->connect();
 $dbName=(string)$db->query('SELECT DATABASE()')->fetchColumn();
 if($dbName!=='prueba')throw new RuntimeException('BD conectada: '.$dbName.'. Solo se permite prueba.');
 $sql="SELECT DATA_TYPE, IS_NULLABLE FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='erp_retiros_supervisados' AND column_name='contado_declarado'";
 $column=$db->query($sql)->fetch(PDO::FETCH_ASSOC);
 if(!$column || !in_array(strtolower((string)$column['DATA_TYPE']),['decimal','numeric'],true))throw new RuntimeException('No se encontró una columna monetaria contado_declarado compatible.');
 foreach(['gyd_retiros_boveda','pos_movimientos_caja','pos_turnos','pos_ventas','pos_pagos'] as $name){
   $stmt=$db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$stmt->execute([$name]);
   if((int)$stmt->fetchColumn()!==1)throw new RuntimeException('Falta tabla '.$name);
 }
 if(!$changes && !$testNew && $column['IS_NULLABLE']==='YES'){echo "YA INSTALADO, sin modificaciones.\n";exit(0);}
 $backup=$project.'/backups_gyd_retiro_expres_v4_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
 if(!mkdir($backup,0775,true)&&!is_dir($backup))throw new RuntimeException('No se pudo crear el respaldo.');
 foreach($changes as $rel=>$source){$out=$backup.'/'.$rel;@mkdir(dirname($out),0775,true);if(!copy($project.'/'.$rel,$out))throw new RuntimeException('Fallo respaldando '.$rel);}
 // La migración es compatible con operaciones antiguas: no cambia ningún dato existente.
 if($column['IS_NULLABLE']!=='YES') $db->exec('ALTER TABLE erp_retiros_supervisados MODIFY COLUMN contado_declarado DECIMAL(14,2) NULL DEFAULT NULL');
 $written=[];
 try{
   foreach($changes as $rel=>$code){$dest=$project.'/'.$rel;if(file_put_contents($dest,$code,LOCK_EX)===false)throw new RuntimeException('No se pudo actualizar '.$rel);$written[]=$rel;}
   if($testNew){$dst=$testTarget;if(!is_dir(dirname($dst)))mkdir(dirname($dst),0775,true);if(file_put_contents($dst,file_get_contents($testSource),LOCK_EX)===false)throw new RuntimeException('No se pudo instalar el test');$written[]='tests/probar_retiro_expres.php';}
 }catch(Throwable $e){foreach(array_reverse($written) as $rel){if($rel==='tests/probar_retiro_expres.php')@unlink($testTarget);else @copy($backup.'/'.$rel,$project.'/'.$rel);}throw $e;}
 echo "INSTALADO: retiro exprés con un solo campo y ticket automático.\n";
 echo 'Archivos actualizados: '.count($changes).'; test nuevo: '.($testNew?'sí':'no').".\nRespaldo: $backup\n";
 echo "No se modificaron fondos, ventas, movimientos previos ni existencias.\n";
}catch(Throwable $e){fwrite(STDERR,'INSTALACIÓN DETENIDA: '.$e->getMessage()."\n");exit(1);}
