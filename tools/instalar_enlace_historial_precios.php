<?php
declare(strict_types=1);
/** Instalador optativo: añade un enlace al historial, sin sobrescribir el módulo. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Uso exclusivo desde terminal.');}
$op=$argv[1]??'--check';
if(!in_array($op,['--check','--apply'],true)){fwrite(STDERR,"Uso: php tools/instalar_enlace_historial_precios.php --check|--apply\n");exit(2);}
$raiz=dirname(__DIR__);$ruta=$raiz.'/public/erp_cambio_precios.php';
$nuevo=$raiz.'/public/erp_historial_precios.php';$helper=$raiz.'/app/helpers/erp_historial_precios.php';
if(!is_file($nuevo)||!is_file($helper)){fwrite(STDERR,"ERROR: faltan archivos del historial. Copia primero public/ y app/.\n");exit(2);}
if(!is_file($ruta)){fwrite(STDERR,"AVISO: no existe erp_cambio_precios.php; entra directamente a /public/erp_historial_precios.php.\n");exit(0);}
$source=file_get_contents($ruta);
if($source===false){fwrite(STDERR,"ERROR: no se pudo leer el módulo de precios.\n");exit(2);}
if(strpos($source,'href="erp_historial_precios.php"')!==false){echo "OK: el acceso al historial ya existe. Sin cambios.\n";exit(0);}
$marcador='<nav class="toolbar">';
if(substr_count($source,$marcador)!==1){fwrite(STDERR,"AVISO: el diseño de la barra cambió, no se modificó nada. Agrega manualmente un enlace a erp_historial_precios.php.\n");exit(0);}
if($op==='--check'){echo "OK: compatible. Hay 1 enlace pendiente, SIN modificar archivos.\n";exit(0);}
if(!is_writable($ruta)){fwrite(STDERR,"ERROR: el archivo de precios no permite escritura.\n");exit(2);}
$backup=$ruta.'.backup_historial_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
if(!copy($ruta,$backup)){fwrite(STDERR,"ERROR: no se pudo crear el respaldo. Sin cambios.\n");exit(2);}
$replacement=$marcador.'<a class="btn secondary" href="erp_historial_precios.php">Historial de precios</a>';
$modified=str_replace($marcador,$replacement,$source);
$tmp=$ruta.'.history_tmp_'.bin2hex(random_bytes(4));
if(file_put_contents($tmp,$modified,LOCK_EX)===false){@unlink($tmp);fwrite(STDERR,"ERROR: no se pudo preparar el archivo. Respaldo: $backup\n");exit(2);}
$lint=PHP_BINARY.' -l '.escapeshellarg($tmp).' 2>&1';$lines=[];$code=0;exec($lint,$lines,$code);
if($code!==0){@unlink($tmp);fwrite(STDERR,"ERROR: la verificación de sintaxis falló. No se modificó el original.\n".implode("\n",$lines)."\n");exit(2);}
if(!rename($tmp,$ruta)){@unlink($tmp);fwrite(STDERR,"ERROR: no se pudo aplicar. Original intacto, respaldo: $backup\n");exit(2);}
echo "OK: acceso al historial agregado.\nRespaldo: $backup\n";
