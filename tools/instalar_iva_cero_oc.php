<?php
declare(strict_types=1);
/** ERP G&D: IVA 0% provisional para OC si Catálogo Maestro no tiene tasa.
 * Parche incremental, idempotente. No accede a BD ni modifica inventario.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Sólo CLI.'); }
$mode=$argv[1]??'';
if(!in_array($mode,['--check','--apply'],true)){
 fwrite(STDERR,"Uso: php tools/instalar_iva_cero_oc.php --check|--apply\n");exit(2);
}
$root=dirname(__DIR__);
$plan=json_decode((string)@file_get_contents(__DIR__.'/iva_cero_oc_plan.json'),true);
if(!is_array($plan)||count($plan)!==3){fwrite(STDERR,"Falta tools/iva_cero_oc_plan.json o está incompleto.\n");exit(2);}
$planned=[];$temps=[];
try {
 foreach($plan as $relative=>$rules){
  if(!in_array($relative,['app/helpers/erp_catalogo_iva.php','app/helpers/erp_oc_importar.php','public/erp_oc_win.php'],true))throw new RuntimeException('Ruta desconocida en el plan.');
  $path=$root.'/'.$relative;
  if(!is_file($path))throw new RuntimeException('Archivo requerido no encontrado: '.$relative.'. Instala el parche de IVA desde Catálogo Maestro antes de este.');
  $old=(string)file_get_contents($path);$new=$old;$changes=0;
  foreach($rules as $idx=>$r){
   $before=$r['before']??null;$after=$r['after']??null;
   if(!is_string($before)||!is_string($after)||$before===''||$after==='')throw new RuntimeException('Regla no válida: '.$relative);
   $oldCount=substr_count($new,$before);$newCount=substr_count($new,$after);
   if($newCount===1 && $oldCount===0)continue; // ya aplicado
   if($oldCount===1 && $newCount===0){$new=str_replace($before,$after,$new);$changes++;continue;}
   throw new RuntimeException('INCOMPATIBLE: '.$relative.' ajuste '.($idx+1).'. El código de tu versión es diferente. No se modificó ningún archivo.');
  }
  $planned[]=['path'=>$path,'relative'=>$relative,'old'=>$old,'new'=>$new,'changes'=>$changes];
 }
 foreach($planned as $p)echo ($p['changes']>0?'PENDIENTE: ':'YA APLICADO: ').$p['relative'].' ('.$p['changes']." cambios)\n";
 if($mode==='--check'){echo "COMPROBACIÓN TERMINADA SIN CAMBIOS.\n";exit(0);}
 foreach($planned as $p){
  if(!$p['changes'])continue;
  $tmp=tempnam(dirname($p['path']),'.iva_cero_');
  if($tmp===false)throw new RuntimeException('No se puede crear temporal para '.$p['relative']);
  $temps[]=$tmp;
  if(file_put_contents($tmp,$p['new'])===false)throw new RuntimeException('Error al escribir archivo temporal.');
  $output=[];$rc=0;
  exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1',$output,$rc);
  if($rc!==0)throw new RuntimeException('Error de sintaxis en '.$p['relative'].': '.implode(' | ',$output));
 }
 $backups=[];
 try{
  foreach($planned as $p){
   if(!$p['changes'])continue;
   $backup=$p['path'].'.backup_iva_cero_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
   if(!copy($p['path'],$backup))throw new RuntimeException('No se pudo crear respaldo de '.$p['relative']);
   $backups[]=[$p['path'],$backup];
   if(file_put_contents($p['path'],$p['new'],LOCK_EX)===false)throw new RuntimeException('Error al escribir '.$p['relative']);
   echo 'ACTUALIZADO: '.$p['relative'].' | respaldo: '.$backup."\n";
  }
 }catch(Throwable $e){foreach(array_reverse($backups) as [$file,$backup])@copy($backup,$file);throw $e;}
 echo "LISTO: IVA pendiente se considera 0% provisional al crear órdenes o importar archivos.\n";
 echo "Catálogo Maestro, BD, pedidos previos, IEPS, stock y módulos de Facturas NO fueron modificados.\n";
}catch(Throwable $e){fwrite(STDERR,'DETENIDO: '.$e->getMessage()."\n");exit(1);}
finally{foreach($temps as $tmp)@unlink($tmp);}
