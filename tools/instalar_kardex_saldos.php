<?php
declare(strict_types=1);
/** Instala exclusivamente la vista de saldos del Kardex; sin cambios de base de datos. */
$root=dirname(__DIR__);
$page=$root.'/public/erp_kardex_general.php';
$helper=$root.'/app/helpers/erp_kardex_saldos.php';
$payload=__DIR__.'/payload_kardex/erp_kardex_saldos.php';
$backupRoot=$root.'/tools/backups_kardex_saldos';
$flag="erp_kardex_saldos.php';";
$operation=$argv[1]??'--check';
$replacements=[
  [base64_decode('cmVxdWlyZV9vbmNlIF9fRElSX18uJy8uLi9hcHAvaGVscGVycy9lcnBfa2FyZGV4X3VuaWZpY2Fkby5waHAnOw=='),base64_decode('cmVxdWlyZV9vbmNlIF9fRElSX18uJy8uLi9hcHAvaGVscGVycy9lcnBfa2FyZGV4X3VuaWZpY2Fkby5waHAnOwpyZXF1aXJlX29uY2UgX19ESVJfXy4nLy4uL2FwcC9oZWxwZXJzL2VycF9rYXJkZXhfc2FsZG9zLnBocCc7')],
  [base64_decode('W3BpX3R0X3N0b2NrX3NpZCgkZGIsJHNpZCksJHBpZF0='),base64_decode('WyRzaWQsJHBpZF0=')],
  [base64_decode('JGxpbWl0PTcwOyRjb3VudD1jb3VudCgkZXZlbnRzKTs='),base64_decode('Ly8gUmVjb25zdHJ1aXIgc2FsZG9zIHPDs2xvIGNvbW8gcmVmZXJlbmNpYTsgbG9zIGFqdXN0ZXMgYXBsaWNhZG9zIHRyYWVuIHZhbG9yZXMgZ3VhcmRhZG9zLgovLyBObyBjb25mdW5kaXIgdW5hIHJlY29uc3RydWNjacOzbiBkZXNkZSBlbCBzYWxkbyBhY3R1YWwgY29uIHVuIG1vdmltaWVudG8gaGlzdMOzcmljbyBjb25maXJtYWRvLgppZiAoISRlcnJvciAmJiAkcHJvZHVjdG8pIHsKICAgICR3YXJuaW5nc1NhbGRvcz1bXTsKICAgICR0b2Rvcz1rZ19tb3ZpbWllbnRvcygkZGIsJHNpZCwkcGlkLCRkZXNkZSwkaG95LCdUT0RPUycsMCwkd2FybmluZ3NTYWxkb3MpOwogICAgJGNoZWNrcG9pbnRzPWtnX3NhX2NoZWNrcG9pbnRzKCRkYiwkdG9kb3MsJHdhcm5pbmdzU2FsZG9zKTsKICAgICRpbmNvbXBsZXRvPWNvdW50KCR3YXJuaW5nc1NhbGRvcyk+MDsKICAgICRldmVudHM9a2dfc2FfY2FsY3VsYXIoJGV2ZW50cywkdG9kb3MsJHNuYXBzaG90LCRjaGVja3BvaW50cywkaW5jb21wbGV0byk7CiAgICBpZigkaW5jb21wbGV0bykkd2FybmluZ3NbXT0nU2FsZG9zIGhpc3TDs3JpY29zIG5vIHJlY29uc3RydWlkb3M6IGV4aXN0ZW4gZnVlbnRlcyBpbmNvbXBsZXRhcyBvIGNvbiBsw61taXRlIGRlIHJlZ2lzdHJvcy4gQWNvcnRhIGxhcyBmZWNoYXMgcGFyYSBjb25zdWx0YXIgeSByZXZpc2EgZWwgcmVnaXN0cm8gZGVsIHNlcnZpZG9yLic7Cn0KJGxpbWl0PTcwOyRjb3VudD1jb3VudCgkZXZlbnRzKTs=')],
  [base64_decode('ZnB1dGNzdigkb3V0LFsnRmVjaGEnLCdUaXBvJywnTW92aW1pZW50bycsJ0ZvbGlvJywnRGVzY3JpcGNpw7NuJywnVXN1YXJpbycsJ86UIFBPUyBnZW5lcmFsJywnzpQgRmFybWFjaWEgZsOtc2ljYScsJ86UIEFsbWFjw6luIGbDrXNpY28nLCdFc3RhZG8nLCdUYWJsYScsJ0lEIGRlIHJlZ2lzdHJvJ10sJzsnKTs='),base64_decode('ZnB1dGNzdigkb3V0LFsnRmVjaGEnLCdUaXBvJywnTW92aW1pZW50bycsJ0ZvbGlvJywnRGVzY3JpcGNpw7NuJywnVXN1YXJpbycsJ0dlbmVyYWwgYW50ZXMnLCdHZW5lcmFsIGRlc3B1w6lzJywnQ2FtYmlvIEdlbmVyYWwnLCdWYWxpZGFjacOzbiBHZW5lcmFsJywnRmFybWFjaWEgYW50ZXMnLCdGYXJtYWNpYSBkZXNwdcOpcycsJ0NhbWJpbyBGYXJtYWNpYScsJ1ZhbGlkYWNpw7NuIEZhcm1hY2lhJywnQWxtYWPDqW4gYW50ZXMnLCdBbG1hY8OpbiBkZXNwdcOpcycsJ0NhbWJpbyBBbG1hY8OpbicsJ1ZhbGlkYWNpw7NuIEFsbWFjw6luJywnRXN0YWRvJywnVGFibGEnLCdJRCBkZSByZWdpc3RybyddLCc7Jyk7')],
  [base64_decode('JHJbJ3BvcyddPz8nTk8gREVURVJNSU5BRE8nLCRyWydmYXJtYWNpYSddPz8nTk8gREVURVJNSU5BRE8nLCRyWydhbG1hY2VuJ10/PydOTyBERVRFUk1JTkFETyc='),base64_decode('Li4ua2dfc2FfZXhwb3J0KCRyLCdwb3MnKSwuLi5rZ19zYV9leHBvcnQoJHIsJ2Zhcm1hY2lhJyksLi4ua2dfc2FfZXhwb3J0KCRyLCdhbG1hY2VuJyk=')],
  [base64_decode('LnVua25vd257Y29sb3I6IzlkNjQxZH0='),base64_decode('LnVua25vd257Y29sb3I6IzlkNjQxZH0ua2ctc2FsZG97ZGlzcGxheTpmbGV4O2ZsZXgtd3JhcDp3cmFwO2dhcDozcHggNnB4O2FsaWduLWl0ZW1zOmNlbnRlcjttaW4td2lkdGg6MjAwcHg7Zm9udC12YXJpYW50LW51bWVyaWM6dGFidWxhci1udW1zO2ZvbnQtc2l6ZToxMnB4fS5rZy1zYWxkbyAua2ctYmVmb3JlLC5rZy1zYWxkbyAua2ctYWZ0ZXJ7d2hpdGUtc3BhY2U6bm93cmFwfS5rZy1zYWxkbyBie2ZvbnQtc2l6ZToxNHB4O2NvbG9yOiMxNDJmNGJ9LmtnLXNhbGRvIC5rZy1hcnJvd3tjb2xvcjojNzg5MGE5fS5rZy1zYWxkbyAua2ctZGVsdGF7Y29sb3I6IzM1NGU2YjtmbGV4LWJhc2lzOjEwMCU7Zm9udC13ZWlnaHQ6NzAwfS5rZy1zYWxkbyAua2ctY2VydHtmb250LXNpemU6MTBweDtib3JkZXItcmFkaXVzOjZweDtwYWRkaW5nOjRweCA2cHg7YmFja2dyb3VuZDojZWVmMWY3O2ZvbnQtd2VpZ2h0Ojc1MH0ua2ctc2FsZG8ua2ctZ29vZCAua2ctY2VydHtiYWNrZ3JvdW5kOiNkY2ZjZTc7Y29sb3I6IzE2NjUzNH0ua2ctc2FsZG8ua2ctZXN0aW1hdGUgLmtnLWNlcnR7YmFja2dyb3VuZDojZmVmM2M3O2NvbG9yOiM5MjQwMGV9LmtnLXNhbGRvLmtnLWJhZCAua2ctY2VydHtiYWNrZ3JvdW5kOiNmZWUyZTI7Y29sb3I6I2I5MWMxY30ua2ctc2FsZG8ua2ctdW5rbm93biAua2ctY2VydHtiYWNrZ3JvdW5kOiNlNWU3ZWI7Y29sb3I6IzRiNTU2M30ua2ctZGV0YWlse2ZvbnQtc2l6ZToxMXB4O2NvbG9yOiNhMTJjMzZ9dGFibGV7bWluLXdpZHRoOjE2NTBweH0=')],
  [base64_decode('PGI+Tm8gc3VtZXMgZXN0b3MgcmVuZ2xvbmVzIHBhcmEgcmVjb25zdHJ1aXIgZWwgc2FsZG86PC9iPiBwdWVkZW4gZmFsdGFyIG1vdmltaWVudG9zIGFudGVyaW9yZXMsIGluaWNpYWxlcywgY29ycmVjY2lvbmVzIGhpc3TDs3JpY2FzIG8gaW50ZWdyYWNpb25lcyBkZSBvdHJhcyB2ZXJzaW9uZXMu'),base64_decode('PGI+QW50ZXMgeSBkZXNwdcOpczo8L2I+IMKrUmVnaXN0cmFkb8K7IHByb2NlZGUgZGVsIGFqdXN0ZSBhcGxpY2FkbyBxdWUgZ3VhcmTDsyBhbWJvcyB2YWxvcmVzOyDCq1JlY29uc3RydWlkb8K7IHNlIGNhbGN1bGEgZGVzZGUgbGEgZXhpc3RlbmNpYSBhY3R1YWwgeSBOTyBkZW11ZXN0cmEgcG9yIHPDrSBzb2xvIHF1ZSBlbCBpbnZlbnRhcmlvIGhheWEgY2FtYmlhZG8gZW4gZXNlIGluc3RhbnRlLiDCq05vIHZlcmlmaWNhYmxlwrsgc2lnbmlmaWNhIHF1ZSBmYWx0YW4gZGF0b3MgbyBleGlzdGUgZWZlY3RvIGRlc2Nvbm9jaWRvLiBObyBzdW1lcyBsb3MgcmVuZ2xvbmVzIHBhcmEgcmVjb25zdHJ1aXIgZWwgc2FsZG86IHB1ZWRlbiBmYWx0YXIgbW92aW1pZW50b3MgaGlzdMOzcmljb3MsIGNvcnJlY2Npb25lcyBlIGludGVncmFjaW9uZXMgYW50ZXJpb3Jlcy4=')],
  [base64_decode('PHRoPs6UIFBPUyBnZW5lcmFsPC90aD48dGg+zpQgRmFybWFjaWE8L3RoPjx0aD7OlCBBbG1hY8OpbjwvdGg+'),base64_decode('PHRoPlBPUyBnZW5lcmFsOiBhbnRlcyAvIGRlc3B1w6lzPC90aD48dGg+RmFybWFjaWE6IGFudGVzIC8gZGVzcHXDqXM8L3RoPjx0aD5BbG1hY8OpbjogYW50ZXMgLyBkZXNwdcOpczwvdGg+')],
  [base64_decode('PD9waHAgZm9yZWFjaChbJ3BvcycsJ2Zhcm1hY2lhJywnYWxtYWNlbiddIGFzICRrKTokZGVsdGE9JHJbJGtdOz8+PHRkIGNsYXNzPSJudW0gPD89KCRkZWx0YT09PW51bGw/J3Vua25vd24nOigkZGVsdGE8MD8nbmVnJzooJGRlbHRhPjA/J3Bvcyc6JycpKSk/PiI+PD89a2dfbnVtYmVyKCRkZWx0YSk/PjwvdGQ+PD9waHAgZW5kZm9yZWFjaDs/Pg=='),base64_decode('PD9waHAgZm9yZWFjaChbJ3BvcycsJ2Zhcm1hY2lhJywnYWxtYWNlbiddIGFzICRrKTo/Pjx0ZD48Pz1rZ19zYV9jZWxkYSgkciwkayk/PjwvdGQ+PD9waHAgZW5kZm9yZWFjaDs/Pg==')],
];
function inspect(string $page,array $replacements,string $flag):array {
 if(!is_file($page))throw new RuntimeException('Falta public/erp_kardex_general.php');
 $content=file_get_contents($page);if($content===false)throw new RuntimeException('No es posible leer el Kardex');
 if(str_contains($content,$flag))return [true,$content];
 foreach($replacements as $i=>[$old,$new]) {
    // La lectura de Toscana independiente puede haberse corregido en un parche anterior.
    if($i===1 && !str_contains($content,$old))continue;
    if(substr_count($content,$old)!==1)throw new RuntimeException('El Kardex difiere del esperado en el bloque '.($i+1).'. No se sobrescribió. Comparte tu archivo actual para adaptar el cambio.');
 }
 return [false,$content];
}
try {
 if(!in_array($operation,['--check','--apply','--verify'],true))throw new RuntimeException('Uso: php instalar_kardex_saldos.php --check | --apply | --verify');
 if($operation==='--verify'){
   if(!is_file($page)||!is_file($helper)||!str_contains((string)file_get_contents($page),$flag))throw new RuntimeException('No se encontraron los cambios instalados');
   echo "VERIFICADO: Kardex con saldos y exportación ampliada.\n";exit(0);
 }
 [$installed,$source]=inspect($page,$replacements,$flag);
 if($operation==='--check'){echo $installed?"YA INSTALADO\n":"COMPATIBLE: actualización de consulta y CSV; no modifica existencias.\n";exit(0);}
 if($installed){echo "YA INSTALADO; no se repiten cambios.\n";exit(0);}
 if(!is_file($payload))throw new RuntimeException('Falta el helper de la actualización');
 // Preparar texto completo antes de modificar cualquier archivo.
 $target=$source;
 foreach($replacements as [$old,$new]){$target=str_replace($old,$new,$target);}
 if(!str_contains($target,$flag))throw new RuntimeException('No se pudo ensamblar el parche');
 // Crear respaldo del archivo vigente, incluso si proviene de una actualización posterior.
 $folder=$backupRoot.'/'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
 if(!is_dir($folder) && !mkdir($folder,0770,true))throw new RuntimeException('No se pudo crear respaldo');
 if(!copy($page,$folder.'/erp_kardex_general.php'))throw new RuntimeException('No fue posible respaldar el Kardex');
 if(is_file($helper))copy($helper,$folder.'/erp_kardex_saldos.php');
 if(!is_dir(dirname($helper)))mkdir(dirname($helper),0770,true);
 // Escritura atómica de ambos archivos; restaurar página si falla helper.
 if(!copy($payload,$helper))throw new RuntimeException('No se pudo copiar el helper');
 if(file_put_contents($page,$target,LOCK_EX)===false){copy($folder.'/erp_kardex_general.php',$page);throw new RuntimeException('No se pudo actualizar la página');}
 echo "APLICADO. Respaldo: $folder\n";
 echo "Ejecuta --verify y reinicia Apache.\n";
} catch(Throwable $e){fwrite(STDERR,'ERROR: '.$e->getMessage()."\n");exit(1);}
