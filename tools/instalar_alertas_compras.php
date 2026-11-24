<?php
declare(strict_types=1);
/** G&D - Instalador conservador de alertas, solo CLI. Sin cambios SQL. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo CLI.');}
$modo=$argv[1]??'';
if(!in_array($modo,['--check','--apply'],true)){fwrite(STDERR,"Uso: php tools/instalar_alertas_compras.php --check|--apply\n");exit(2);}
$root=dirname(__DIR__);
$source=__DIR__.'/alertas_compras_files';
$files=['app/helpers/erp_alertas_compras.php','public/erp_alertas_compras.php'];
$links=['public/erp_catalogo_comparar_proveedores.php','public/erp_catalogo_finanzas.php'];
$link='<a href="erp_alertas_compras.php">Alertas de compras</a>';
$plan=[];$errors=[];
foreach(['app/helpers/auth.php','app/config/database.php','app/helpers/erp_comparador_proveedores.php'] as $req){
  if(!is_file($root.'/'.$req))$errors[]='Falta dependencia '.$req;
}
foreach($files as $rel){
 $src=$source.'/'.$rel;$dst=$root.'/'.$rel;
 if(!is_file($src)){$errors[]='Falta el archivo fuente '.$rel;continue;}
 if(is_file($dst)){
   if(hash_file('sha256',$src)!==hash_file('sha256',$dst))$errors[]='El archivo '.$rel.' existe con modificaciones distintas. No se sobrescribe.';
   else echo "YA INSTALADO: $rel\n";
 }else{$plan[$rel]=file_get_contents($src);echo "CREAR: $rel\n";}
}
foreach($links as $rel){
 $dst=$root.'/'.$rel;
 if(!is_file($dst)){$errors[]='Falta el módulo previo '.$rel;continue;}
 $old=file_get_contents($dst);
 if($old===false){$errors[]='No se pudo leer '.$rel;continue;}
 if(str_contains($old,'href="erp_alertas_compras.php"')){echo "YA INSTALADO: enlace en $rel\n";continue;}
 if(substr_count($old,'</header>')!==1){$errors[]='No se encontró un encabezado único en '.$rel;continue;}
 $new=str_replace('</header>',' '.$link.'</header>',$old);
 $plan[$rel]=$new;
 echo "ENLACE: $rel\n";
}
foreach($plan as $rel=>$content){
 $tmp=tempnam(sys_get_temp_dir(),'gyd-ac-');
 if(!$tmp){$errors[]='No se pudo crear temporal para '.$rel;continue;}
 file_put_contents($tmp,$content);
 $result=[];$ret=1;
 exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1',$result,$ret);
 unlink($tmp);
 if($ret!==0)$errors[]='PHP no válido: '.$rel.' '.implode(' ',$result);
}
if($errors){foreach($errors as $err)fwrite(STDERR,"INCOMPATIBLE: $err\n");fwrite(STDERR,"INCOMPATIBLE: ningún archivo fue modificado.\n");exit(1);}
echo "COMPATIBLE: ".count($plan)." cambios. No altera MySQL.\n";
if($modo==='--check')exit(0);
if(!$plan){echo "INSTALADO: ya estaba actualizado.\n";exit(0);}
$dir=$root.'/backups_gyd_alertas_compras_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
if(!mkdir($dir,0775,true)){fwrite(STDERR,"No se pudo crear respaldo.\n");exit(2);}
$backed=[];$created=[];
try{
 foreach($plan as $rel=>$content){
  $dst=$root.'/'.$rel;$folder=dirname($dst);
  if(!is_dir($folder)&&!mkdir($folder,0775,true))throw new RuntimeException('No se pudo crear carpeta '.$folder);
  if(is_file($dst)){
    $backup=$dir.'/'.$rel;
    if(!is_dir(dirname($backup))&&!mkdir(dirname($backup),0775,true))throw new RuntimeException('Carpeta respaldo fallida.');
    if(!copy($dst,$backup))throw new RuntimeException('Copia respaldo fallida '.$rel);
    $backed[$dst]=$backup;
  }else{$created[]=$dst;}
  $temp=$dst.'.gydtmp_'.getmypid();
  if(file_put_contents($temp,$content,LOCK_EX)===false)throw new RuntimeException('No se pudo escribir '.$rel);
  if(!rename($temp,$dst)){@unlink($temp);throw new RuntimeException('No se pudo instalar '.$rel);}
 }
 echo "INSTALADO: panel en public/erp_alertas_compras.php\nRESPALDOS: $dir\n";
}catch(Throwable $e){
 foreach($backed as $dst=>$backup)if(is_file($backup))@copy($backup,$dst);
 foreach($created as $dst)if(is_file($dst))@unlink($dst);
 fwrite(STDERR,"ABORTADO: ".$e->getMessage().". Se intentó restaurar el estado anterior.\n");exit(1);
}
