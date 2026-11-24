<?php
declare(strict_types=1);
/** Instalador incremental del POS de pruebas G&D. Sólo interfaz de ventas/cobro. */
if (PHP_SAPI !== 'cli') {http_response_code(403);exit('Uso exclusivo desde consola.');}
$accion=$argv[1]??'';
if(!in_array($accion,['--check','--apply','--verify','--rollback'],true)){
    fwrite(STDERR,"Uso: php instalar_pos_ventas_cobro.php --check|--apply|--verify|--rollback\n");exit(2);
}
$base=dirname(__DIR__);
$source=__DIR__.'/payload_pos_ventas_cobro';
$manifest=json_decode((string)file_get_contents(__DIR__.'/manifest_pos_ventas_cobro.json'),true,512,JSON_THROW_ON_ERROR);
$expected=$manifest['baseline_sha256'];$targetHashes=$manifest['payload_sha256'];
$files=array_keys($targetHashes);
$backupPointer=__DIR__.'/.gyd_pos_cobro_ultimo_respaldo.json';
function fail(string $msg):never{fwrite(STDERR,"ERROR: $msg\n");exit(1);}
function hashes(string $base,array $files):array{
    $out=[];foreach($files as $rel){$path=$base.'/'.$rel;$out[$rel]=is_file($path)?hash_file('sha256',$path):null;}return $out;
}
function allExpected(array $got,array $expect):bool{
    foreach($expect as $rel=>$hash){if(($got[$rel]??null)!==$hash)return false;}return true;
}
try {
    require_once $base.'/app/config/database.php';
    $db=(new Database())->connect();
    $env=$db->query('SELECT DATABASE() AS nombre, @@port AS puerto')->fetch(PDO::FETCH_ASSOC);
    if(($env['nombre']??'')!=='prueba'||(int)($env['puerto']??0)!==3307) fail('Instalación bloqueada: sólo prueba:3307. No se modificó nada.');
    echo "Entorno verificado: prueba:3307\n";
} catch(Throwable $e){fail('No se pudo verificar la base prueba:3307; no se modificó nada.');}
foreach($files as $rel){
    $payload=$source.'/'.$rel;
    if(!is_file($payload)||hash_file('sha256',$payload)!==$targetHashes[$rel]) fail('Paquete alterado o incompleto: '.$rel);
}
$current=hashes($base,$files);
$installed=allExpected($current,$targetHashes);
if($accion==='--verify'){
    if(!$installed)fail('Los archivos instalados no corresponden al paquete. Ejecuta --check.');
    echo "VERIFICADO · Ventas, carrito y cobros. Sin cambios de base de datos.\n";exit(0);
}
if($accion==='--rollback'){
    if(!is_file($backupPointer))fail('No existe un respaldo registrado por este instalador.');
    $p=json_decode((string)file_get_contents($backupPointer),true,512,JSON_THROW_ON_ERROR);
    $folder=(string)($p['directory']??'');
    if(!str_starts_with($folder,$base.'/backups/pos_ventas_cobro_')||!is_dir($folder))fail('Respaldo inválido.');
    if(!$installed)fail('Se detectaron cambios posteriores; no se permite restaurar automáticamente.');
    foreach(array_keys($expected) as $rel){$from=$folder.'/'.$rel;if(!is_file($from)||hash_file('sha256',$from)!==$expected[$rel]) fail('Respaldo no coincide: '.$rel);}
    foreach(array_keys($expected) as $rel){if(!copy($folder.'/'.$rel,$base.'/'.$rel)) fail('No se pudo restaurar '.$rel);}
    if(!empty($p['css_created'])&&is_file($base.'/public/assets/css/pos_checkout_mejoras.css')) unlink($base.'/public/assets/css/pos_checkout_mejoras.css');
    echo "RESTAURADO · Archivos anteriores recuperados. La base no fue modificada.\n";exit(0);
}
if($installed){echo "INSTALADO · No es necesario aplicar nuevamente.\n";exit(0);}
foreach($expected as $rel=>$hash){
    if(($current[$rel]??null)!==$hash)fail('Archivo distinto al ERP proporcionado: '.$rel.'. No sobrescribiré tus cambios.');
}
if($current['public/assets/css/pos_checkout_mejoras.css']!==null)
    fail('Ya existe una hoja de estilos con ese nombre; no se sobrescribirá.');
if($accion==='--check'){echo "COMPATIBLE · ERP recibido + base prueba:3307. Listo para --apply.\n";exit(0);}
// El respaldo es obligatorio antes de reemplazar archivos. Apache debe estar detenido.
$stamp=date('Ymd_His').'_'.bin2hex(random_bytes(3));
$backup=$base.'/backups/pos_ventas_cobro_'.$stamp;
foreach(array_keys($expected) as $rel){
    if(!is_dir(dirname($backup.'/'.$rel))&&!mkdir(dirname($backup.'/'.$rel),0775,true)&&!is_dir(dirname($backup.'/'.$rel)))fail('No se pudo preparar respaldo.');
    if(!copy($base.'/'.$rel,$backup.'/'.$rel))fail('No se pudo respaldar '.$rel);
}
$changed=[];
try {
    foreach($files as $rel){
        $dest=$base.'/'.$rel;
        if(!is_dir(dirname($dest))&&!mkdir(dirname($dest),0775,true)&&!is_dir(dirname($dest)))throw new RuntimeException('No se pudo crear el directorio de destino.');
        if(!copy($source.'/'.$rel,$dest))throw new RuntimeException('No se pudo copiar '.$rel);
        $changed[]=$rel;
        if(hash_file('sha256',$dest)!==$targetHashes[$rel])throw new RuntimeException('Hash incorrecto: '.$rel);
    }
    file_put_contents($backupPointer,json_encode(['directory'=>$backup,'css_created'=>true],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);
} catch(Throwable $e){
    foreach(array_reverse($changed) as $rel){
        if(isset($expected[$rel]))copy($backup.'/'.$rel,$base.'/'.$rel);
        else @unlink($base.'/'.$rel);
    }
    fail($e->getMessage().'. Se intentó restaurar el estado anterior.');
}
echo "APLICADO · Mejora de ventas y cobro instalada. Respaldo: $backup\n";
echo "Ejecuta --verify y después inicia Apache. Usa Ctrl+F5 para actualizar el POS.\n";
