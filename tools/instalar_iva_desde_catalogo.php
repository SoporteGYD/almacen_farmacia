<?php
declare(strict_types=1);
/** Parche incremental para IVA de compra desde Catálogo Maestro.
 * Sólo modifica tres archivos si sus fragmentos existentes son compatibles.
 * --check: sin cambios. --apply: backup, PHP -l y reemplazo controlado.
 */
if (PHP_SAPI !== 'cli') {http_response_code(403); exit('Sólo terminal CLI.');}
$option=$argv[1]??'';
if(!in_array($option,['--check','--apply'],true)){fwrite(STDERR,"Uso: php tools/instalar_iva_desde_catalogo.php --check|--apply\n");exit(2);}
$root=dirname(__DIR__);
$planFile=__DIR__.'/iva_catalogo_plan.json';
$helper=$root.'/app/helpers/erp_catalogo_iva.php';
if(!is_file($helper)){fwrite(STDERR,"FALTA: app/helpers/erp_catalogo_iva.php. Copia las carpetas del ZIP.\n");exit(2);}
$plan=json_decode((string)file_get_contents($planFile),true);
if(!is_array($plan)){fwrite(STDERR,"No se puede abrir el plan del parche.\n");exit(2);}
$planned=[];
try {
 foreach($plan as $relative=>$changes){
  if(!preg_match('~^(?:public|app/helpers)/[a-zA-Z0-9_./-]+[.]php$~D',$relative)||str_contains($relative,'..'))throw new RuntimeException('Ruta insegura en el plan.');
  $path=$root.'/'.$relative;
  if(!is_file($path))throw new RuntimeException('No existe '.$relative);
  $old=file_get_contents($path);if($old===false)throw new RuntimeException('No se puede leer '.$relative);
  $new=$old;$need=0;
  foreach($changes as $c){
   $before=(string)($c['before']??'');$after=(string)($c['after']??'');
   if($before===''||$after==='')throw new RuntimeException('Regla de reemplazo vacía.');
   $countBefore=substr_count($new,$before);$countAfter=substr_count($new,$after);
   if($countAfter===1 && $countBefore<=1){/* regla ya aplicada; after puede contener before */}
   elseif($countBefore===1 && $countAfter===0){$new=str_replace($before,$after,$new);$need++;}
   else {throw new RuntimeException("INCOMPATIBLE: $relative. No modificar: una firma cambió o está duplicada. Adjunta ese archivo para integrarlo sin sobrescribir otras personalizaciones.");}
  }
  $planned[]=[$path,$relative,$old,$new,$need];
 }
 foreach($planned as [$path,$relative,$old,$new,$need]){
  echo ($need>0?'PENDIENTE':'OK YA INSTALADO').": $relative ($need ajustes)\n";
 }
 if($option==='--check') {echo "COMPROBACIÓN TERMINADA SIN CAMBIOS.\n";exit(0);}
 // Preparar y validar todos los archivos antes de tocar el proyecto real.
 $temps=[];
 foreach($planned as [$path,$relative,$old,$new,$need]){
  if($need===0)continue;
  $tmp=tempnam(dirname($path),'.iva_');if($tmp===false)throw new RuntimeException('No se pudo crear temporal para '.$relative);
  $temps[]=$tmp;
  if(file_put_contents($tmp,$new)===false)throw new RuntimeException('No se pudo escribir temporal.');
  $cmd=escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1';$out=[];$rc=0;exec($cmd,$out,$rc);
  if($rc!==0)throw new RuntimeException('Error de sintaxis en '.$relative.': '.implode(' ',$out));
 }
 $copies=[];$applied=[];
 try {
  foreach($planned as [$path,$relative,$old,$new,$need]){
   if($need===0)continue;
   $backup=$path.'.backup_iva_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
   if(!copy($path,$backup))throw new RuntimeException('No se pudo respaldar '.$relative);
   $copies[]=['path'=>$path,'backup'=>$backup];
   if(file_put_contents($path,$new,LOCK_EX)===false)throw new RuntimeException('No se pudo guardar '.$relative);
   $applied[]=$path;
   echo "ACTUALIZADO: $relative | respaldo: $backup\n";
  }
  echo "LISTO. No se ejecutaron cambios de base de datos, stock, precios ni órdenes históricas.\n";
 }catch(Throwable $e){foreach(array_reverse($copies) as $b){if(in_array($b['path'],$applied,true))copy($b['backup'],$b['path']);}throw $e;}
} catch(Throwable $e) {fwrite(STDERR,"DETENIDO: ".$e->getMessage()."\nNo se aplicaron cambios nuevos, o se restauraron los respaldos ante un fallo.\n");exit(1);} finally {if(isset($temps))foreach($temps as $tmp)@unlink($tmp);}
