<?php
declare(strict_types=1);
/** G&D. Compacta diseño de OC sin alterar lógica. Sólo CLI. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Sólo uso por consola.');}
$mode=$argv[1]??'';
if(!in_array($mode,['--check','--apply'],true)){
 fwrite(STDERR,"Uso: php tools/instalar_oc_diseno_compacto.php --check|--apply\n");exit(2);
}
$file=dirname(__DIR__).'/public/erp_oc_win.php';
$planFile=__DIR__.'/oc_compacto_plan.json';
try{
 if(!is_file($file))throw new RuntimeException('No existe public/erp_oc_win.php');
 $plan=json_decode((string)@file_get_contents($planFile),true,512,JSON_THROW_ON_ERROR);
 $rules=$plan['public/erp_oc_win.php']??null;
 if(!is_array($rules)||count($rules)!==4)throw new RuntimeException('Plan de cambios incompleto');
 $original=file_get_contents($file);
 if($original===false)throw new RuntimeException('No es posible leer archivo');
 $result=$original;
 $changes=0;
 foreach($rules as $num=>$r){
  $old=$r['before']??null;$new=$r['after']??null;
  if(!is_string($old)||!is_string($new)||$old==='')throw new RuntimeException('Regla inválida '.($num+1));
  $oldCount=substr_count($result,$old);
  $newCount=$new===''?0:substr_count($result,$new);
  if($oldCount===1){$result=str_replace($old,$new,$result);$changes++;continue;}
  // Se considera aplicada si la firma nueva es inequívoca; para el aviso eliminado,
  // se verifica contra el resto de firmas en la etapa final.
  if($new!==''&&$newCount===1&&$oldCount===0)continue;
  if($new===''&&$oldCount===0)continue;
  throw new RuntimeException('INCOMPATIBLE: Regla '.($num+1).'. No se modificó nada. Adjunta el archivo public/erp_oc_win.php para adaptar el parche.');
 }
 if(!str_contains($result,'id="ocForm"')||!str_contains($result,'id="ocImportFile"')||!str_contains($result,'id="historial"'))throw new RuntimeException('Incompatibilidad en los módulos necesarios; sin cambios.');
 if(str_contains($result,'<div class="page-intro">')||str_contains($result,'<div class="help-note">')||str_contains($result,'aria-label="Etapas de la orden"'))throw new RuntimeException('Algunas secciones de presentación permanecen. Sin cambios.');
 echo $changes?"CAMBIOS PENDIENTES: $changes\n":"YA APLICADO: diseño compacto\n";
 if($mode==='--check'){echo "COMPROBADO SIN MODIFICACIONES.\n";exit(0);}
 if($changes===0){echo "Nada que actualizar.\n";exit(0);}
 $tmp=tempnam(dirname($file),'.compact_oc_');
 if($tmp===false)throw new RuntimeException('No se pudo crear temporal.');
 try{
  if(file_put_contents($tmp,$result)===false)throw new RuntimeException('No se pudo escribir temporal.');
  $output=[];$rc=0;
  exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1',$output,$rc);
  if($rc!==0)throw new RuntimeException('Sintaxis no válida. '.implode(' | ',$output));
  $backup=$file.'.backup_diseno_compacto_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
  if(!copy($file,$backup))throw new RuntimeException('No fue posible crear respaldo.');
  if(file_put_contents($file,$result,LOCK_EX)===false){copy($backup,$file);throw new RuntimeException('No se pudo guardar. Se restauró el respaldo.');}
  echo "DISEÑO COMPACTADO. Respaldo: $backup\n";
  echo "Órdenes, importación, IVA, BD y datos no fueron cambiados.\n";
 }finally{@unlink($tmp);}
}catch(Throwable $e){fwrite(STDERR,"DETENIDO: ".$e->getMessage()."\n");exit(1);}
