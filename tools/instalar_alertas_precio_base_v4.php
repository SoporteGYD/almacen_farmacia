<?php
declare(strict_types=1);
/** G&D V4: corrige alertas financieras redundantes de PRECIO BASE para POS multi-sucursal.
 * Solo cambia dos bloques en un helper; sin DDL, cambios en precios, estados o inventario.
 */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Disponible solo en CLI');}
$mode=$argv[1]??'--check';
if(!in_array($mode,['--check','--apply'],true)){
    fwrite(STDERR,"Uso: php tools/instalar_alertas_precio_base_v4.php --check | --apply\n");exit(2);
}
$root=dirname(__DIR__);
$src=__DIR__.'/alertas_v4_fuentes';
$rel='app/helpers/erp_compras_alertas_seguimiento.php';
$file=$root.'/'.$rel;
$newRel='app/helpers/erp_alertas_precio_base_v4.php';
$newFile=$root.'/'.$newRel;
$testRel='tests/probar_alertas_precio_base_v4.php';
$testFile=$root.'/'.$testRel;
$errors=[];$changes=[];$notes=[];
if(!is_file($file))$errors[]="Falta $rel. Instala antes el Centro de Seguimiento de Alertas.";
$original=is_file($file)?file_get_contents($file):false;
if($original===false)$errors[]="No se pudo leer $rel";
$sourceNew=@file_get_contents($src.'/'.$newRel);
$sourceTest=@file_get_contents($src.'/'.$testRel);
if($sourceNew===false||$sourceTest===false)$errors[]='Faltan archivos fuente en el ZIP.';
if(!$errors){
    $required="require_once __DIR__.'/erp_alertas_compras.php';";
    $hook="         if(\$branch!==0&&in_array(\$event['tipo'],['SUBIDA','OPORTUNIDAD'],true))continue;";
    $mark1='GYD_V4_PRECIO_BASE_INCLUDE';
    $mark2='GYD_V4_PRECIO_BASE_FILTRO';
    $has1=str_contains($original,$mark1);$has2=str_contains($original,$mark2);
    if($has1 xor $has2){$errors[]='Parche V4 parcialmente instalado o archivo modificado. No se sobrescribirá.';}
    elseif($has1&&$has2){$notes[]='YA INSTALADO: filtro de precio base.';}
    elseif(substr_count($original,$required)!==1||substr_count($original,$hook)!==1){
        $errors[]='INCOMPATIBLE: el helper cambió; faltan puntos de inserción inequívocos. No se sobrescribirá.';
    }else{
        $insertReq=$required."\nrequire_once __DIR__.'/erp_alertas_precio_base_v4.php'; // GYD_V4_PRECIO_BASE_INCLUDE";
        $code=<<<'PHP'
         // GYD_V4_PRECIO_BASE_FILTRO: precio base no es quinta sucursal.
         // Con todas las sucursales POS configuradas, evaluar margen/precio por sucursal.
         // Si alguna sucursal carece de precio, NO se omite esta alerta adicional.
         if($branch===0 && $hasPos && in_array((string)$event['tipo'],['MARGEN_BAJO','PERDIDA','SIN_PRECIO'],true)
            && gdca_base_cubierta_por_sucursales($branches,$branchPrices,(int)$event['producto_id']))continue;
PHP;
        $patched=str_replace($required,$insertReq,$original);
        $patched=str_replace($hook,$code."\n".$hook,$patched);
        $changes[$rel]=$patched;$notes[]='ACTUALIZAR: '.$rel;
    }
    foreach([[$newRel,$newFile,$sourceNew],[$testRel,$testFile,$sourceTest]] as [$rel2,$target,$source]){
        if(is_file($target)){
            if(file_get_contents($target)!==$source)$errors[]="INCOMPATIBLE: $rel2 ya existe con otro contenido.";
            else $notes[]='YA INSTALADO: '.$rel2;
        }else{$changes[$rel2]=$source;$notes[]='CREAR: '.$rel2;}
    }
    // Si la inserción ya estaba, confirmar que el helper auxiliar está disponible.
    if($has1&&$has2 && !is_file($newFile) && !isset($changes[$newRel]))$errors[]='Falta helper V4.';
}
foreach($changes as $rel2=>$body){
    $tmp=tempnam(sys_get_temp_dir(),'gydv4_');
    if($tmp===false){$errors[]='Sin archivo temporal';continue;}
    file_put_contents($tmp,$body);
    $lines=[];$rc=1;exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1',$lines,$rc);
    unlink($tmp);if($rc!==0)$errors[]='Sintaxis PHP incompatible en '.$rel2.': '.implode(' ',$lines);
}
foreach($notes as $note)echo $note."\n";
if($errors){foreach($errors as $e)fwrite(STDERR,$e."\n");fwrite(STDERR,"INCOMPATIBLE: sin modificar archivos.\n");exit(3);}
echo 'COMPATIBLE: '.count($changes).' archivo(s) por crear o actualizar. Base de datos intacta.'."\n";
if($mode==='--check')exit(0);
if(!$changes){echo "YA INSTALADO.\n";exit(0);}
$back=$root.'/tools/backups_gyd_alertas_base_v4_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
if(!mkdir($back,0700,true))throw new RuntimeException('No se pudo crear respaldo.');
foreach($changes as $rel2=>$body){
    $path=$root.'/'.$rel2;
    if(!is_file($path))continue;
    $dest=$back.'/'.$rel2;
    if(!is_dir(dirname($dest))&&!mkdir(dirname($dest),0700,true))throw new RuntimeException('Respaldo: no se pudo crear carpeta.');
    if(!copy($path,$dest))throw new RuntimeException('No se pudo respaldar '.$rel2);
}
$written=[];
try {
    foreach($changes as $rel2=>$body){
        $path=$root.'/'.$rel2;
        if(!is_dir(dirname($path))&&!mkdir(dirname($path),0755,true))throw new RuntimeException('No se pudo crear carpeta.');
        $tmp=$path.'.gydv4_'.getmypid();
        if(file_put_contents($tmp,$body,LOCK_EX)===false||!rename($tmp,$path))throw new RuntimeException('No se pudo instalar '.$rel2);
        $written[]=$rel2;
    }
}catch(Throwable $e){
    foreach(array_reverse($written) as $rel2){$path=$root.'/'.$rel2;$bkp=$back.'/'.$rel2;if(is_file($bkp))copy($bkp,$path);else @unlink($path);}
    fwrite(STDERR,'Restauración aplicada: '.$e->getMessage()."\n");exit(4);
}
echo "INSTALADO. Respaldo: $back\n";
echo "Siguiente paso: ejecutar tests/probar_alertas_precio_base_v4.php y después forzar una sincronización desde tu herramienta V3.\n";
