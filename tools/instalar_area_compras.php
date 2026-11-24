<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo CLI');}
$cmd=$argv[1]??'--check';
if(!in_array($cmd,['--check','--apply'],true)){fwrite(STDERR,"Usa --check o --apply\n");exit(2);}
$raiz=dirname(__DIR__);
$reglas=json_decode((string)file_get_contents(__DIR__.'/compras_patches.json'),true,512,JSON_THROW_ON_ERROR);
$copias=['app/helpers/erp_compras_modulos.php','public/erp_compras_inicio.php'];
$errores=[];$pendientes=[];$resumen=[];
// primero validar TODOS los cambios para evitar una instalación parcial
foreach($copias as $ruta){
  $src=__DIR__.'/../'.$ruta;
  $dest=$raiz.'/'.$ruta;
  if(!is_file($src))$errores[]="No existe nuevo módulo: $ruta";
  elseif(is_file($dest) && hash_file('sha256',$src)!==hash_file('sha256',$dest))$errores[]="Hay otra versión de $ruta; no se sobrescribe.";
  elseif(!is_file($dest))$pendientes[$ruta]=file_get_contents($src);
}
foreach($reglas as $r){
   $ruta=$r['file'];$abs=$raiz.'/'.$ruta;
   if(!is_file($abs)){$errores[]="Falta: $ruta";continue;}
   $current=$pendientes[$ruta]??file_get_contents($abs);
   $oldCount=substr_count($current,$r['find']);$newCount=substr_count($current,$r['replace']);
   // Comparar primero la versión nueva: varias reglas conservan el texto viejo como prefijo.
   if($newCount===1){$resumen[]="YA INSTALADO $ruta | ".$r['description'];}
   elseif($oldCount===1){$pendientes[$ruta]=str_replace($r['find'],$r['replace'],$current);$resumen[]="PENDIENTE $ruta | ".$r['description'];}
   else {$errores[]="INCOMPATIBLE $ruta | ".$r['description']." (anclas antiguo=$oldCount, nuevo=$newCount)";}
}
foreach($resumen as $ln)echo $ln."\n";
if($errores){foreach($errores as $ln)fwrite(STDERR,$ln."\n");fwrite(STDERR,"INCOMPATIBLE: no se cambió ningún archivo.\n");exit(3);}
// Validate PHP syntax without modifying project. Uses php -l on a temporary .php file.
foreach($pendientes as $ruta=>$body){
 $tmp=tempnam(sys_get_temp_dir(),'gyd_compras_');
 if($tmp===false){fwrite(STDERR,"No se pudo crear archivo temporal.\n");exit(4);}
 file_put_contents($tmp,$body);
 $output=[];$status=0;
 exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1',$output,$status);
 @unlink($tmp);
 if($status!==0){fwrite(STDERR,"PHP inválido en $ruta: ".implode("\n",$output)."\n");exit(5);}
}
echo "COMPATIBLE: ".count($pendientes)." archivos por crear o actualizar.\n";
if($cmd==='--check'){echo "Comprobación terminada. No se modificó el ERP.\n";exit(0);}
$stamp=date('Ymd_His');$backup=$raiz.'/tools/respaldos_compras_'.$stamp;
if(!is_dir($backup) && !mkdir($backup,0700,true)){fwrite(STDERR,"No se pudo crear el respaldo.\n");exit(6);}
// Respaldar antes de modificar. Sin respaldo completo no aplicar.
foreach($pendientes as $ruta=>$body){
 $dest=$raiz.'/'.$ruta;
 if(is_file($dest)){
  $saved=$backup.'/'.$ruta;
  if(!is_dir(dirname($saved)) && !mkdir(dirname($saved),0700,true)){fwrite(STDERR,"Error al preparar respaldo.\n");exit(6);}
  if(!copy($dest,$saved)){fwrite(STDERR,"No se pudo respaldar $ruta.\n");exit(6);}
 }
}
$escritos=[];
try{
 foreach($pendientes as $ruta=>$body){
  $dest=$raiz.'/'.$ruta;
  if(!is_dir(dirname($dest)) && !mkdir(dirname($dest),0775,true))throw new RuntimeException('No se pudo crear '.dirname($dest));
  if(file_put_contents($dest,$body,LOCK_EX)===false)throw new RuntimeException('No se pudo escribir '.$ruta);
  $escritos[]=$ruta;
 }
} catch(Throwable $e){
 foreach($escritos as $ruta){$bk=$backup.'/'.$ruta;$dest=$raiz.'/'.$ruta;if(is_file($bk))copy($bk,$dest);else @unlink($dest);}
 fwrite(STDERR,"ERROR, se intentó restaurar el respaldo: ".$e->getMessage()."\n");exit(7);
}
echo "INSTALADO: ".count($escritos)." archivos. Respaldo en $backup\n";
echo "Siguiente: php tools/crear_usuarios_compras.php --check\n";
