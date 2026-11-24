<?php
declare(strict_types=1);
/**
 * Parche G&D: retira topes arbitrarios de partidas, usa JSON compacto al guardar,
 * pagina formularios largos e importa por lotes.
 * Solo CLI: php tools/instalar_erp_sin_topes.php --check|--apply
 * No consulta ni modifica MySQL: copia de seguridad antes de aplicar.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Solo línea de comandos.\n"); }
$mode=$argv[1]??'--check';
if (!in_array($mode,['--check','--apply'],true)) {fwrite(STDERR,"Uso: --check o --apply\n");exit(2);}
$root=realpath(dirname(__DIR__));
$manifestPath=__DIR__.'/patches/erp_sin_limites_manifest.json';
if (!$root||!is_file($manifestPath)){fwrite(STDERR,"Faltan archivos del instalador.\n");exit(2);}
$manifest=json_decode((string)file_get_contents($manifestPath),true,512,JSON_THROW_ON_ERROR);
if (($manifest['version']??'')!=='GYD_SIN_LIMITES_PARTIDAS_V1') {fwrite(STDERR,"Versión de manifiesto desconocida.\n");exit(2);}
$prepared=[];$errors=[];
foreach ($manifest['files'] as $rel=>$operations) {
    if (str_contains($rel,'..')||str_starts_with($rel,'/')) {$errors[]="Ruta no permitida: $rel";continue;}
    $path=$root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$rel);
    if (!is_file($path)||!is_readable($path)||!is_writable($path)){$errors[]="No se puede leer/escribir: $rel";continue;}
    $original=file_get_contents($path);
    if (!is_string($original)) {$errors[]="Lectura fallida: $rel";continue;}
    $modified=$original;$changes=0;$already=0;
    foreach($operations as $i=>$operation) {
        $new=$operation['new'];
        // Identificar primero el parche ya instalado, incluso si contiene una parte del texto original.
        if ($new!=='' && substr_count($modified,$new)===1) {$already++;continue;}
        $variants=[];
        foreach($operation['old'] as $old) {
            if ($old!=='' && substr_count($modified,$old)===1) $variants[]=$old;
        }
        if (count($variants)!==1) {
            $errors[]="INCOMPATIBLE: $rel / regla ".($i+1).". Revisar manualmente; no se sobrescribirá.";
            continue;
        }
        $modified=str_replace($variants[0],$new,$modified);
        $changes++;
    }
    $prepared[$rel]=['path'=>$path,'old'=>$original,'new'=>$modified];
    echo "$rel: $changes por aplicar, $already ya instaladas\n";
}
if ($errors) {
  echo "INCOMPATIBLE; no se ha modificado ningún archivo.\n";
  foreach($errors as $e)echo " - $e\n";
  exit(1);
}
if ($mode==='--check'){echo "COMPATIBLE. Usa --apply para instalar (con respaldo automático).\n";exit(0);}
$changes=array_filter($prepared,static fn($a)=>$a['old']!==$a['new']);
if (!$changes) {echo "YA INSTALADO. No se modificaron archivos.\n";exit(0);}
$stamp=date('Ymd_His').'_'.bin2hex(random_bytes(4));
$backupDir=$root.'/tools/respaldos_erp_sin_topes/'.$stamp;
foreach($changes as $rel=>$item) {
  $target=$backupDir.'/'.$rel;
  if (!is_dir(dirname($target)) && !mkdir(dirname($target),0775,true) && !is_dir(dirname($target))) {
    fwrite(STDERR,"No se pudo preparar respaldo. Ningún archivo del ERP fue modificado.\n");exit(1);
  }
  if (file_put_contents($target,$item['old'],LOCK_EX)!==strlen($item['old'])) {
    fwrite(STDERR,"Error creando respaldo de $rel. ERP intacto.\n");exit(1);
  }
}
$applied=[];
try {
 foreach($changes as $rel=>$item) {
   $tmp=$item['path'].'.temp_'.$stamp;
   if(file_put_contents($tmp,$item['new'],LOCK_EX)!==strlen($item['new'])){ @unlink($tmp);throw new RuntimeException("No se pudo crear temporal: $rel"); }
   if(!rename($tmp,$item['path'])){@unlink($tmp);throw new RuntimeException("No se pudo actualizar: $rel");}
   $applied[$rel]=$item;
 }
} catch(Throwable $e) {
 foreach($applied as $rel=>$item) {
   $backup=$backupDir.'/'.$rel;
   if(!copy($backup,$item['path']))fwrite(STDERR,"RESTAURACIÓN MANUAL: copiar $backup a $rel\n");
 }
 fwrite(STDERR,"Error. Intenté restaurar lo aplicado: ".$e->getMessage()."\n");exit(1);
}
echo "INSTALACIÓN TERMINADA. Archivos modificados: ".count($applied)."\n";
echo "RESPALDO: $backupDir\n";
echo "Nota: PHP/MySQL mantienen límites físicos de memoria y tiempo; no hay tope fijo de renglones en estos flujos.\n";
