<?php
declare(strict_types=1);
/** php tools/instalar_erp_conciliacion.php --check|--apply */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Sólo por terminal.');}
$modo=$argv[1]??'--check';
if(!in_array($modo,['--check','--apply'],true)){fwrite(STDERR,"Uso: --check o --apply\n");exit(2);}
$root=dirname(__DIR__);
foreach(['public/erp_facturas_conciliacion.php','app/helpers/erp_conciliacion_automatica.php','public/erp_facturas_aplicar.php','app/helpers/erp_facturas.php','app/config/database.php'] as $file){
  if(!is_file($root.'/'.$file)){fwrite(STDERR,"INCOMPATIBLE: falta {$file}. Copia app, public y tools del ZIP.\n");exit(3);}
}
$target=$root.'/public/erp_facturas_aplicar.php';
$raw=file_get_contents($target);
if($raw===false){fwrite(STDERR,"No se pudo leer erp_facturas_aplicar.php.\n");exit(3);}
$marker='GYD_CONCILIACION_LINK_V1';
$needle='<a class="btn alt" href="erp_facturas_verificacion.php?id=<?=$fid?>">← Volver a Verificación</a>';
$compatible=substr_count($raw,$needle)===1;
$installed=str_contains($raw,$marker);
if(!$installed && !$compatible){
  echo "INCOMPATIBLE: no encuentro el lugar seguro para integrar el enlace sin sobrescribir otras modificaciones.\n";
  echo "El reporte independiente sí está disponible: public/erp_facturas_conciliacion.php?id=9\n";
  exit(3);
}
$php=PHP_BINARY;
foreach(['public/erp_facturas_conciliacion.php','app/helpers/erp_conciliacion_automatica.php'] as $f){
  $cmd=escapeshellarg($php).' -l '.escapeshellarg($root.'/'.$f);
  exec($cmd,$salida,$rc);
  if($rc!==0){fwrite(STDERR,"INCOMPATIBLE: error de sintaxis en {$f}.\n".implode("\n",$salida)."\n");exit(3);}
  $salida=[];
}
echo ($installed?'YA INSTALADO':'COMPATIBLE').": conciliación de sólo lectura, sin migración SQL ni modificación de stock.\n";
if($modo==='--check'||$installed)exit(0);
$new=$raw;
$new=str_replace($needle,$needle.' <a class="btn alt" href="erp_facturas_conciliacion.php?id=<?=$fid?>">Conciliar factura y OC</a> <!-- '.$marker.' -->',$new);
if(substr_count($new,$marker)!==1){fwrite(STDERR,"No pude preparar el enlace.\n");exit(4);}
$backup=$target.'.backup_conciliacion_'.date('Ymd_His');
if(!copy($target,$backup)){fwrite(STDERR,"No pude crear respaldo de {$target}.\n");exit(4);}
$temp=$target.'.tmp_concil_'.getmypid();
if(file_put_contents($temp,$new,LOCK_EX)===false || !rename($temp,$target)){@unlink($temp);fwrite(STDERR,"Falló la instalación. Respaldo: {$backup}\n");exit(4);}
exec(escapeshellarg($php).' -l '.escapeshellarg($target),$salida,$rc);
if($rc!==0){copy($backup,$target);fwrite(STDERR,"Se revirtió el archivo; sintaxis incorrecta.\n");exit(5);}
echo "INSTALADO. Respaldo: {$backup}\nAbre erp_facturas_aplicar.php?id=9 y pulsa Conciliar factura y OC.\n";
