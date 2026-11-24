<?php
declare(strict_types=1);
/** G&D V12.1 - Corrección aditiva, sin tocar la BD. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo consola.');}
$accion=$argv[1]??'';
if(!in_array($accion,['--check','--apply'],true)){
    fwrite(STDERR,"Uso: php tools/instalar_auditoria_cajas_v121.php --check | --apply\n");exit(2);
}
$root=realpath(__DIR__.'/..');
if(!$root){fwrite(STDERR,"ERROR: raíz del ERP no encontrada.\n");exit(2);}
$dest=$root.'/app/helpers/gyd_auditoria_cajas_v12.php';
$page=$root.'/public/erp_auditoria_cajas_v12.php';
$old="\$selectDen=\$hasDen?'den.id comprobante_id':'NULL comprobante_id';";
$new="\$selectDen=\$hasDen?'den.turno_id comprobante_id':'NULL comprobante_id';";
if(!is_file($dest)||!is_file($page)){fwrite(STDERR,"INCOMPATIBLE: la V12 debe estar instalada primero. No se cambió nada.\n");exit(1);}
$src=file_get_contents($dest);
if(!is_string($src)){fwrite(STDERR,"INCOMPATIBLE: no se pudo leer el módulo V12.\n");exit(1);}
$cuentaVieja=substr_count($src,$old);
$cuentaNueva=substr_count($src,$new);
if($cuentaVieja===0 && $cuentaNueva===1){echo "YA CORREGIDO: V12.1 aplicada con anterioridad. Sin cambios.\n";exit(0);}
if($cuentaVieja!==1 || $cuentaNueva!==0){fwrite(STDERR,"INCOMPATIBLE: la consulta de denominaciones cambió; no se sobrescribe código desconocido. Viejas=$cuentaVieja nuevas=$cuentaNueva\n");exit(1);}
$updated=str_replace($old,$new,$src);
if(substr_count($updated,$new)!==1){fwrite(STDERR,"ERROR: el cambio no es único.\n");exit(1);}
// Comprobar con el motor PHP antes de escribir.
$tmp=tempnam(sys_get_temp_dir(),'gyd_v121_');
if(!$tmp || file_put_contents($tmp,$updated,LOCK_EX)===false){fwrite(STDERR,"ERROR: no se pudo preparar la validación.\n");exit(2);}
$cmd=escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1';
exec($cmd,$out,$rc);@unlink($tmp);
if($rc!==0){fwrite(STDERR,"ERROR: código PHP no válido: ".implode(' ',$out)."\n");exit(1);}
if($accion==='--check'){
    echo "COMPATIBLE: se corregirá den.id por den.turno_id solo en el reporte V12.\n";
    echo "No cambia la base de datos, el POS, cortes, movimientos ni permisos.\n";
    exit(0);
}
$dir=$root.'/backups_gyd_auditoria_v121_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
if(!mkdir($dir,0700,true)){fwrite(STDERR,"ERROR: no se pudo crear respaldo.\n");exit(2);}
$backup=$dir.'/gyd_auditoria_cajas_v12.php';
if(!copy($dest,$backup)){fwrite(STDERR,"ERROR: no se pudo respaldar el módulo; no se cambió nada.\n");exit(2);}
$temporary=$dest.'.tmp_v121_'.bin2hex(random_bytes(3));
if(file_put_contents($temporary,$updated,LOCK_EX)===false){@unlink($temporary);fwrite(STDERR,"ERROR: no se pudo guardar el archivo temporal.\n");exit(2);}
if(!rename($temporary,$dest)){
    @unlink($temporary);fwrite(STDERR,"ERROR: no se pudo instalar; el original sigue intacto.\n");exit(2);
}
clearstatcache(true,$dest);
if(file_get_contents($dest)!==$updated){
    copy($backup,$dest);fwrite(STDERR,"ERROR: verificación de escritura falló; se restauró el respaldo.\n");exit(2);
}
echo "INSTALADO: se corrigió la columna del comprobante en Auditoría V12.1.\n";
echo "RESPALDO: $backup\n";
echo "Prueba: http://localhost/almacen-farmacia2/public/erp_auditoria_cajas_v12.php\n";
?>
