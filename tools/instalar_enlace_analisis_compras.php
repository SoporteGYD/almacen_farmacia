<?php
declare(strict_types=1);
/** Instalador aditivo e idempotente. No reemplaza el menú de usuario. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo terminal.');}
$mode=$argv[1]??'--check';
if(!in_array($mode,['--check','--apply'],true)){fwrite(STDERR,"Uso: php tools/instalar_enlace_analisis_compras.php --check|--apply\n");exit(2);}
$root=dirname(__DIR__);
if(!is_file($root.'/public/erp_analisis_compras.php')||!is_file($root.'/app/helpers/erp_analisis_compras.php')){fwrite(STDERR,"ERROR: copia antes los archivos app/ y public/.\n");exit(2);}
$targets=[
 ['file'=>$root.'/public/erp_oc_win.php', 'needle'=>'<details class="nav-more">', 'add'=>'<a class="nav-action" href="erp_analisis_compras.php">Análisis de compras</a>', 'label'=>'Órdenes de Compra'],
 ['file'=>$root.'/public/erp_historial_precios.php','needle'=>'<a class="btn" href="erp_cambio_precios.php">','add'=>'<a class="btn" href="erp_analisis_compras.php">Análisis de compras</a> ', 'label'=>'Historial de Precios'],
];
$pending=[];
foreach($targets as $t){
 if(!is_file($t['file'])){echo 'OMITIDO: '.$t['label']." no existe.\n";continue;}
 $content=file_get_contents($t['file']);if($content===false){fwrite(STDERR,'ERROR: no se pudo leer '.$t['file']."\n");exit(2);}
 if(str_contains($content,'href="erp_analisis_compras.php"')){echo 'OK: el enlace ya existe en '.$t['label']."\n";continue;}
 if(substr_count($content,$t['needle'])!==1){echo 'OMITIDO: la estructura de '.$t['label']." cambió. Accede directamente a /public/erp_analisis_compras.php.\n";continue;}
 $pending[]=['t'=>$t,'content'=>$content];
}
echo 'Enlaces pendientes: '.count($pending)."\n";
if($mode==='--check'){echo "Comprobación terminada. No se modificó ningún archivo.\n";exit(0);}
foreach($pending as $item){
 $t=$item['t'];$file=$t['file'];$source=$item['content'];
 if(!is_writable($file)){fwrite(STDERR,"ERROR: no se puede escribir en $file\n");exit(2);}
 $backup=$file.'.backup_analisis_compras_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
 if(!copy($file,$backup)){fwrite(STDERR,"ERROR: no se pudo respaldar $file\n");exit(2);}
 $modified=str_replace($t['needle'],$t['add'].$t['needle'],$source);
 $tmp=$file.'.new_'.bin2hex(random_bytes(5));
 if(file_put_contents($tmp,$modified,LOCK_EX)===false){@unlink($tmp);fwrite(STDERR,"ERROR al escribir. Respaldo $backup\n");exit(2);}
 exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1',$out,$code);
 if($code!==0){@unlink($tmp);fwrite(STDERR,'ERROR de sintaxis: '.implode(' | ',$out)."\n");exit(2);}
 if(!rename($tmp,$file)){@unlink($tmp);fwrite(STDERR,"ERROR al aplicar. Respaldo $backup\n");exit(2);}
 echo 'OK: acceso agregado en '.$t['label'].". Respaldo: $backup\n";
}
echo "Instalación de enlaces finalizada. Sin SQL ni cambios de inventario.\n";
