<?php
declare(strict_types=1);
/** Instalador estricto F1: solo archivos de la versión compartida por el usuario. */
if (PHP_SAPI!=='cli') {http_response_code(403);exit('Solo CLI');}
$root=dirname(__DIR__);
$manifest=json_decode((string)file_get_contents(__DIR__.'/f1_manifest.json'),true,512,JSON_THROW_ON_ERROR);
$check=in_array('--check',$argv,true);$apply=in_array('--apply',$argv,true);
if ($check===$apply) {fwrite(STDERR,"Uso: php instalar_f1_stock_compartido.php --check | --apply\n");exit(2);}
$estados=[];
foreach($manifest as $relative=>$hash){
    $target=$root.'/'.str_replace('/',DIRECTORY_SEPARATOR,$relative);
    $source=__DIR__.'/payload/'.str_replace('/',DIRECTORY_SEPARATOR,$relative);
    if(!is_file($target) || !is_readable($target) || !is_file($source)){
        fwrite(STDERR,"INCOMPATIBLE: falta el archivo $relative. No se cambio nada.\n");exit(3);
    }
    if(hash_file('sha256',$source)!==$hash['after']) {
        fwrite(STDERR,"ERROR: el paquete fue alterado ($relative).\n");exit(3);
    }
    $actual=hash_file('sha256',$target);
    $estado=$actual===$hash['before']?'ORIGINAL':($actual===$hash['after']?'INSTALADO':'DISTINTO');
    $estados[$relative]=$estado;
    echo $relative.' => '.$estado.PHP_EOL;
}
if(in_array('DISTINTO',$estados,true)) {
    fwrite(STDERR,"INCOMPATIBLE: uno o mas archivos difieren del contenido enviado. No se sobrescribiran.\n");exit(3);
}
$hasOriginal=in_array('ORIGINAL',$estados,true);
if(!$hasOriginal){echo "YA INSTALADO: no hay cambios pendientes.\n";exit(0);}
if($check){echo "COMPATIBLE: solo lectura; NO cambia saldos, tablas, pagos ni descuentos del POS.\n";exit(0);}
// Nunca usar tools/backups como destino final: esta dentro de htdocs.
$fueraWeb=dirname(dirname($root)).'/gyd_resguardos_codigo/f1_stock_compartido';
if(!is_dir($fueraWeb) && !mkdir($fueraWeb,0700,true) && !is_dir($fueraWeb)) {
    fwrite(STDERR,"No fue posible crear un directorio de respaldos FUERA del sitio web.\n");exit(4);
}
$aplicados=[];$backups=[];
try{
    $suffix=date('Ymd_His').'_'.bin2hex(random_bytes(4));
    foreach($estados as $relative=>$estado){
        if($estado!=='ORIGINAL')continue;
        $target=$root.'/'.str_replace('/',DIRECTORY_SEPARATOR,$relative);
        $source=__DIR__.'/payload/'.str_replace('/',DIRECTORY_SEPARATOR,$relative);
        $backup=$fueraWeb.'/'.str_replace('/','_',$relative).'.'.$suffix.'.bak';
        if(!copy($target,$backup) || hash_file('sha256',$backup)!==$manifest[$relative]['before'])throw new RuntimeException('Fallo el respaldo: '.$relative);
        @chmod($backup,0600);
        $backups[$relative]=$backup;
        $tmp=$target.'.f1tmp.'.bin2hex(random_bytes(4));
        if(!copy($source,$tmp)){@unlink($tmp);throw new RuntimeException('Fallo la copia temporal: '.$relative);}
        if(hash_file('sha256',$tmp)!==$manifest[$relative]['after']){@unlink($tmp);throw new RuntimeException('Hash de copia temporal incorrecto: '.$relative);}
        if(!rename($tmp,$target)){@unlink($tmp);throw new RuntimeException('Fallo reemplazar: '.$relative);}
        $aplicados[$relative]=$target;
        if(hash_file('sha256',$target)!==$manifest[$relative]['after'])throw new RuntimeException('Verificacion de archivo instalado fallo: '.$relative);
    }
    echo "INSTALADO: candado compartido y campo informativo de inventario; venta sin modificar.\n";
    echo "Respaldos fuera del sitio: ".$fueraWeb.PHP_EOL;
} catch(Throwable $e){
    foreach($aplicados as $rel=>$t){if(isset($backups[$rel]))@copy($backups[$rel],$t);}
    fwrite(STDERR,"ERROR: ".$e->getMessage().". Se intento restaurar los originales.\n");exit(5);
}
