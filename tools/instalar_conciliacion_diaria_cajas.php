<?php
declare(strict_types=1);
/** Instalador incremental. Crea pantalla de solo lectura; no ejecuta DDL ni modifica datos de negocio. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo consola.');}
$check=in_array('--check',$argv,true);$apply=in_array('--apply',$argv,true);
if($check===$apply){fwrite(STDERR,"Uso: php tools/instalar_conciliacion_diaria_cajas.php --check | --apply\n");exit(2);}
$root=dirname(__DIR__);$payload=__DIR__.'/concilia_diaria_payload';$errors=[];$new=[];$changed=[];$skipped=[];$already=0;
foreach(['app/helpers/gyd_conciliacion_diaria.php','public/erp_conciliacion_diaria_cajas.php','tests/probar_conciliacion_diaria.php'] as $rel){
 $source=$payload.'/'.$rel;$target=$root.'/'.$rel;
 if(!is_file($source)){$errors[]='Falta archivo del paquete: '.$rel;continue;}
 $bytes=file_get_contents($source);if($bytes===false){$errors[]='No puede leerse '.$source;continue;}
 if(!file_exists($target)){$new[$rel]=$bytes;continue;}
 if(hash_file('sha256',$source)===hash_file('sha256',$target)){$already++;continue;}
 $errors[]='INCOMPATIBLE: '.$rel.' existe con otro contenido; no se sobrescribirá.';
}
// Inserta exclusivamente un enlace en un punto estable, sin sobrescribir la pantalla existente.
$rel='public/erp_control_cajas.php';$path=$root.'/'.$rel;
if(is_file($path)){
 $orig=(string)file_get_contents($path);
 if(strpos($orig,'erp_conciliacion_diaria_cajas.php')===false){
  $tag="\n".'<a href="erp_conciliacion_diaria_cajas.php" style="display:inline-block;margin:6px 0;color:#fff;font-weight:700;text-decoration:underline">Conciliación diaria de cajas</a>'."\n";
  if(substr_count($orig,'</header>')===1)$changed[$rel]=str_replace('</header>',$tag.'</header>',$orig);
  else $skipped[]='Menú del Control de Cajas: enlace no insertado (marca de navegación no reconocida)';
 }
}else $skipped[]='Control de Cajas no encontrado; abrir nueva pantalla por URL directa';
foreach(['app/helpers/auth.php','app/config/database.php'] as $need)if(!is_file($root.'/'.$need))$errors[]='Falta dependencia del ERP: '.$need;
if($errors){foreach($errors as $err)fwrite(STDERR,$err."\n");fwrite(STDERR,"NO SE MODIFICÓ NINGÚN ARCHIVO.\n");exit(1);}
if($check){echo (count($new)+count($changed)?'COMPATIBLE':'YA INSTALADO').'. Nuevos '.count($new).', cambios '.count($changed).', existentes '.($already).', avisos '.count($skipped).".\n";
 foreach($skipped as $s)echo 'AVISO: '.$s."\n";
 echo "Centro de solo lectura. Sin migraciones ni movimientos monetarios.\n";exit(0);}
if(!$new&&!$changed){echo "YA INSTALADO.\n";exit(0);}
try {
 require_once $root.'/app/config/database.php';$db=(new Database())->connect();$name=(string)$db->query('SELECT DATABASE()')->fetchColumn();
 if($name!=='prueba')throw new RuntimeException('Sólo se aplica sobre proyecto conectado a base prueba. Base actual: '.$name);
 $bk=$root.'/backups_gyd_conciliacion_diaria_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
 if(!mkdir($bk,0775,true)&&!is_dir($bk))throw new RuntimeException('No pudo crearse respaldo.');
 foreach($changed as $rel=>$bytes){$dest=$bk.'/'.$rel;@mkdir(dirname($dest),0775,true);if(!copy($root.'/'.$rel,$dest))throw new RuntimeException('No pudo respaldarse '.$rel);}
 $done=[];
 try{
  foreach(array_merge($new,$changed) as $rel=>$bytes){$target=$root.'/'.$rel;@mkdir(dirname($target),0775,true);
   $tmp=$target.'.gydtmp.'.bin2hex(random_bytes(3));if(file_put_contents($tmp,$bytes,LOCK_EX)===false)throw new RuntimeException('No pudo escribirse temporal '.$rel);
   if(!rename($tmp,$target)){@unlink($tmp);throw new RuntimeException('No pudo aplicarse '.$rel);} $done[]=$rel;
  }
 }catch(Throwable $e){foreach(array_reverse($done) as $rel){if(isset($new[$rel]))@unlink($root.'/'.$rel);elseif(isset($changed[$rel]))@copy($bk.'/'.$rel,$root.'/'.$rel);}throw $e;}
 echo 'INSTALADO CORRECTAMENTE. Archivos nuevos: '.count($new).'; modificados: '.count($changed).".\nRespaldo: $bk\n";
 foreach($skipped as $s)echo 'AVISO: '.$s."\n";
 echo "Abra: http://localhost/almacen-farmacia2/public/erp_conciliacion_diaria_cajas.php\n";
 echo "No se modificaron tablas, ventas, retiros, cajas ni inventario.\n";
}catch(Throwable $e){fwrite(STDERR,"INSTALACIÓN DETENIDA: ".$e->getMessage()."\n");exit(1);}
