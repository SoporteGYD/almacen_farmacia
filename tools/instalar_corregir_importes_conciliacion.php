<?php
declare(strict_types=1);
/** Corrección incremental y SOLO LECTURA del reporte de conciliación de G&D. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo disponible en terminal.');}
$accion=$argv[1]??'--check';
if(!in_array($accion,['--check','--check-files','--apply','--verify','--rollback'],true)) {
    fwrite(STDERR,"Uso: --check | --check-files | --apply | --verify | --rollback\n");exit(2);
}
$root=dirname(__DIR__);
$here=__DIR__;
$rel='app/helpers/gyd_pos_conciliacion_detalle.php';
$dest=$root.'/'.$rel;
$payload=$here.'/payload/'.$rel;
$ptr=$here.'/.respaldo_ultimo_fix_importes.json';
const HASH_ORIG='90c445aa3ec57f19e95a069d357e4f7f515e397ca5e96aba45bf6dbcc05f75ee';
const HASH_NUEVO='6b795d2a3731fd796a65d3e90330972be448bd155d0d87e0058c7bc2a1b0455f';
function fix_error(string $s):never {throw new RuntimeException($s);}
function fix_verificar_archivo(string $f):string {if(!is_file($f))fix_error('No existe: '.$f);return hash_file('sha256',$f);}
function fix_db_pruebas(string $root):void {
    require_once $root.'/app/config/database.php';
    $pdo=(new Database())->connect();
    $database=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $port=(int)$pdo->query('SELECT @@port')->fetchColumn();
    if($database!=='prueba'||$port!==3307)fix_error('Se requiere la base de laboratorio prueba:3307. Conexión encontrada: '.$database.':'.$port);
}
try {
    if(fix_verificar_archivo($payload)!==HASH_NUEVO)fix_error('El archivo de actualización no coincide con el paquete original.');
    $actual=fix_verificar_archivo($dest);
    if($accion==='--rollback'){
        if(!is_file($ptr))fix_error('No existe información de respaldo de esta corrección.');
        $meta=json_decode((string)file_get_contents($ptr),true,512,JSON_THROW_ON_ERROR);
        $ruta=(string)($meta['backup']??'');
        $base=$root.'/backups/gyd_fix_conciliacion_';
        if(!str_starts_with($ruta,$base)||!is_file($ruta))fix_error('Ruta de respaldo no autorizada.');
        if($actual!==HASH_NUEVO)fix_error('El archivo fue modificado después de aplicar; restaura manualmente para preservar tus cambios.');
        if(hash_file('sha256',$ruta)!==HASH_ORIG)fix_error('El respaldo es distinto al archivo original.');
        if(!copy($ruta,$dest))fix_error('No se pudo restaurar el respaldo.');
        unlink($ptr);
        echo "RESTAURADO: el reporte volvió al estado anterior.\n";exit(0);
    }
    if(!in_array($actual,[HASH_ORIG,HASH_NUEVO],true))fix_error('El reporte ya contiene cambios distintos. No se sobrescribió ningún archivo. Envía ese archivo para combinar las correcciones.');
    if($accion==='--check-files') {echo "ARCHIVOS COMPATIBLES. Estado: ".($actual===HASH_NUEVO?'corregido':'pendiente').".\n";exit(0);}
    fix_db_pruebas($root);
    if($accion==='--check'){echo 'COMPATIBLE: base prueba:3307; reporte '.($actual===HASH_NUEVO?'ya corregido':'pendiente de corregir').". No se modificó nada.\n";exit(0);}
    if($accion==='--verify'){
        if($actual!==HASH_NUEVO)fix_error('Todavía no se ha aplicado la corrección.');
        echo "VERIFICADO: el reporte acepta diferencias negativas de cortes y conserva la validación original de retiros.\n";exit(0);
    }
    if($actual===HASH_NUEVO){echo "YA CORREGIDO. Sin cambios.\n";exit(0);}
    // Respaldo antes de editar; no se toca SQL ni lógica de venta/retiros.
    $stamp=date('Ymd_His').'_'.bin2hex(random_bytes(4));
    $bdir=$root.'/backups/gyd_fix_conciliacion_'.$stamp;
    if(!mkdir($bdir,0775,true))fix_error('No fue posible crear la carpeta de respaldo.');
    $backup=$bdir.'/gyd_pos_conciliacion_detalle.php';
    if(!copy($dest,$backup)||hash_file('sha256',$backup)!==HASH_ORIG)fix_error('Falló el respaldo; no se cambió el reporte.');
    $tmp=tempnam(dirname($dest),'.gyd_fix_');
    if($tmp===false)fix_error('No se pudo generar archivo temporal.');
    try {
        if(file_put_contents($tmp,(string)file_get_contents($payload),LOCK_EX)===false)fix_error('No se pudo preparar la corrección.');
        if(hash_file('sha256',$tmp)!==HASH_NUEVO)fix_error('El archivo temporal tiene un hash distinto.');
        if(!rename($tmp,$dest))fix_error('No fue posible aplicar la corrección.');
        if(hash_file('sha256',$dest)!==HASH_NUEVO)fix_error('Hash final distinto; comprueba permisos del proyecto.');
        if(file_put_contents($ptr,json_encode(['backup'=>$backup,'antes'=>HASH_ORIG,'despues'=>HASH_NUEVO],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT),LOCK_EX)===false)fix_error('No se pudo registrar respaldo.');
        echo "CORRECCIÓN APLICADA. Archivo actualizado: {$rel}\nRespaldo: {$backup}\nEjecuta --verify y presiona Ctrl+F5 en el navegador.\n";
    } catch(Throwable $ex) {
        if(is_file($tmp))@unlink($tmp);
        if(is_file($dest)&&hash_file('sha256',$dest)===HASH_NUEVO)@copy($backup,$dest);
        throw $ex;
    }
} catch(Throwable $ex) {fwrite(STDERR,'ERROR: '.$ex->getMessage()."\n");exit(1);}
