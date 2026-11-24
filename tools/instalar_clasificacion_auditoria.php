<?php
declare(strict_types=1);
/**
 * Ajusta la clasificación del módulo de auditoría del ERP G&D.
 * No ejecuta SQL ni modifica productos, costos, inventario ni fichas.
 * Uso: php tools/instalar_clasificacion_auditoria.php --check | --apply
 */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo CLI');}
$modo=$argv[1]??'--check';
if(!in_array($modo,['--check','--apply'],true)){
    fwrite(STDERR,"Uso: php tools/instalar_clasificacion_auditoria.php --check | --apply\n");exit(2);
}
$raiz=dirname(__DIR__);
$plantillas=__DIR__.'/clasificacion_auditoria_files';
$paths=['app/helpers/erp_auditoria_catalogo.php','public/erp_catalogo_auditoria.php'];
$original=[
  'app/helpers/erp_auditoria_catalogo.php'=>'ad5e55ea3cc7b53fa562c75674af3f8911f23e5c5fd60a75c45cb4ce0970fcc0',
  'public/erp_catalogo_auditoria.php'=>'2fad9be71a8dca433beb37fccedfc1ab96a72129651ad9e8935477c5221af34d'
];
$pendientes=[];$errores=[];
foreach($paths as $ruta){
    $destino=$raiz.'/'.$ruta;$origen=$plantillas.'/'.$ruta;
    if(!is_file($destino)){$errores[]="Falta archivo original $ruta. Instala primero Auditoría del Catálogo.";continue;}
    if(!is_file($origen)){$errores[]="Falta plantilla $ruta.";continue;}
    $contenido=(string)file_get_contents($origen);
    $hashNuevo=hash('sha256',$contenido);$hashActual=(string)hash_file('sha256',$destino);
    if(hash_equals($hashActual,$hashNuevo)){echo "YA INSTALADO: $ruta\n";continue;}
    if(!hash_equals($hashActual,$original[$ruta])){
        $errores[]="INCOMPATIBLE: $ruta tiene otras modificaciones. No se sobrescribirá.";continue;
    }
    $pendientes[$ruta]=$contenido;echo "ACTUALIZAR: $ruta\n";
    $temporal=tempnam(sys_get_temp_dir(),'gydlint_');
    if($temporal===false){$errores[]="No se pudo crear archivo temporal para $ruta";continue;}
    try{
        file_put_contents($temporal,$contenido);
        $comando=escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($temporal).' 2>&1';
        $salida=[];$codigo=0;exec($comando,$salida,$codigo);
        if($codigo!==0)$errores[]="PHP inválido en $ruta: ".implode(' ',$salida);
    }finally{@unlink($temporal);}
}
if($errores){foreach($errores as $e)fwrite(STDERR,$e."\n");fwrite(STDERR,"NO SE MODIFICARON ARCHIVOS\n");exit(3);}
if($modo==='--check'){echo 'COMPATIBLE: '.count($pendientes)." archivo(s) por actualizar. BD intacta.\n";exit(0);}
if(!$pendientes){echo "YA INSTALADO: No hay cambios.\n";exit(0);}
$dirBackup=$raiz.'/tools/backups/clasificacion_auditoria_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
foreach($pendientes as $ruta=>$contenido){
    $dest=$dirBackup.'/'.$ruta;
    if(!is_dir(dirname($dest))&&!@mkdir(dirname($dest),0775,true)){fwrite(STDERR,"Error al crear directorio del respaldo\n");exit(4);}
    if(!@copy($raiz.'/'.$ruta,$dest)){fwrite(STDERR,"Error al respaldar $ruta\n");exit(4);}
}
$aplicados=[];
try{
    foreach($pendientes as $ruta=>$contenido){
        $dest=$raiz.'/'.$ruta;$temporal=$dest.'.gd_new_'.getmypid();
        if(file_put_contents($temporal,$contenido,LOCK_EX)===false)throw new RuntimeException("Error al escribir $ruta");
        if(!@rename($temporal,$dest)){@unlink($temporal);throw new RuntimeException("Error al sustituir $ruta");}
        $aplicados[]=$ruta;
    }
}catch(Throwable $e){
    foreach(array_reverse($aplicados) as $ruta)@copy($dirBackup.'/'.$ruta,$raiz.'/'.$ruta);
    fwrite(STDERR,'Error: '.$e->getMessage().". Se intentó restaurar los archivos.\n");exit(5);
}
echo 'INSTALADO: '.count($aplicados).' archivo(s). Respaldo: '.$dirBackup."\n";
echo "El catálogo no se modificó. Abre: http://localhost/almacen-farmacia2/public/erp_catalogo_auditoria.php\n";
