<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit('Solo terminal');
$apply=in_array('--apply',$argv,true);$check=in_array('--check',$argv,true);
if($apply===$check)exit("Usa --check o --apply\n");
$root=dirname(__DIR__);$plan=json_decode((string)file_get_contents(__DIR__.'/plan.json'),true,512,JSON_THROW_ON_ERROR);
$changes=[];$issues=[];
foreach($plan as $rel=>$spec){
 $path=$root.'/'.$rel;
 if(!is_file($path)){if(!$spec['optional'])$issues[]='Falta archivo requerido: '.$rel;else echo "OMITIR: $rel no existe\n";continue;}
 $before=(string)file_get_contents($path);$after=$before;
 foreach($spec['edits'] as $edit){
  if(str_contains($after,$edit['new']))continue;
  $options=array_merge([$edit['old']],$edit['alternatives']??[]);
  $found=array_values(array_filter($options,fn($x)=>str_contains($after,$x)));
  if(count($found)!==1 || substr_count($after,$found[0])>3){$issues[]='No reconocido: '.$rel;break;}
  $after=str_replace($found[0],$edit['new'],$after);
 }
 if($after!==$before){$changes[$rel]=[$before,$after];echo "MODIFICAR: $rel\n";}else echo "SIN CAMBIOS: $rel\n";
}
if($issues){foreach(array_unique($issues) as $msg)fwrite(STDERR,"INCOMPATIBLE: $msg\n");exit(3);}
foreach($changes as $rel=>[$old,$new]){
 $tmp=tempnam(sys_get_temp_dir(),'gydphp');if(!$tmp)exit(4);file_put_contents($tmp,$new);
 exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1',$out,$code);@unlink($tmp);
 if($code!==0){fwrite(STDERR,"ERROR sintaxis $rel: ".implode(' ',$out)."\n");exit(4);}unset($out);
}
if($check){echo 'COMPATIBLE: '.count($changes).' archivos pendientes. No se modificó nada.'.PHP_EOL;exit(0);}
if(!$changes){echo "YA INSTALADO\n";exit(0);}
$bk=dirname(dirname($root)).'/gyd_resguardos_codigo/f1_pool_tt/'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
if(!is_dir($bk)&&!mkdir($bk,0700,true))exit("No fue posible respaldar.\n");
$done=[];
try {
 foreach($changes as $rel=>[$old,$new]){if(file_put_contents($bk.'/'.str_replace('/','__',$rel).'.bak',$old,LOCK_EX)===false)throw new RuntimeException('Error respaldo '.$rel);}
 foreach($changes as $rel=>[$old,$new]){
  $target=$root.'/'.$rel;if((string)file_get_contents($target)!==$old)throw new RuntimeException('Cambio externo en '.$rel);
  $temp=$target.'.f1tmp';if(file_put_contents($temp,$new,LOCK_EX)===false||!rename($temp,$target))throw new RuntimeException('Error modificación '.$rel);
  $done[]=$rel;
 }
 echo "CÓDIGO INSTALADO. No se ha cambiado MySQL. Respaldos: $bk\n";
} catch(Throwable $e){foreach($done as $rel)file_put_contents($root.'/'.$rel,$changes[$rel][0],LOCK_EX);fwrite(STDERR,'ERROR: '.$e->getMessage().". Archivos restaurados.\n");exit(4);}
