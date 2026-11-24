<?php
declare(strict_types=1);
/** Instalador F2: sin cambios SQL; modificacion de dos archivos verificados. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo CLI.'); }
$cmd = in_array('--apply', $argv ?? [], true) ? '--apply' : '--check';
$root = realpath(__DIR__.'/..');
if ($root === false) { fwrite(STDERR,"No se encontro la carpeta del proyecto.\n"); exit(2); }
$manifest=[
    'public/productos.php' => ['original' => 'b24347f60012acdcd4604e5b1590be61e65dae56c62605b8c68b88c8e65e18a2', 'updated' => '3caf0c9665de9d1579500755a50cc82d857df78e429b5fbacd877c6d672587f7'],
    'app/controllers/ProductoController.php' => ['original' => 'e786b20277aaa811305911353aa3c4aea77e6cf8db4e6f7dd9b049506cd10be8', 'updated' => 'e09221eec24f0b1ac8fa8da066c49151cb1ccfc927039d84b65655536d344f42'],
];
$payload=__DIR__.'/f2_catalogo_v30_payload/';
$pending=[];
foreach ($manifest as $path=>$checks) {
    $dest=$root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$path);
    $source=$payload.$path;
    if(!is_file($source)||hash_file('sha256',$source)!==$checks['updated']){
        fwrite(STDERR,"INCOMPATIBLE: archivo del parche alterado: $path\n");exit(2);
    }
    if(!is_file($dest)) { fwrite(STDERR,"INCOMPATIBLE: falta $path\n");exit(2); }
    $hash=hash_file('sha256',$dest);
    if($hash===$checks['updated'])continue;
    if($hash!==$checks['original']){
        fwrite(STDERR,"INCOMPATIBLE: $path difiere de la version analizada; no se sobrescribio.\n");exit(2);
    }
    $pending[$path]=['dest'=>$dest,'src'=>$source];
}
if(!$pending){ echo "YA INSTALADO: los archivos ya coinciden con esta correccion.\n";exit(0); }
if($cmd==='--check') {
    echo "COMPATIBLE: archivos reconocidos; no se modifico nada.\n";
    foreach($pending as $path=>$_)echo "  - $path\n";
    echo "Recomendacion: respalda proyecto y DB prueba antes de --apply.\n";exit(0);
}
$backupRoot=$root.DIRECTORY_SEPARATOR.'tools'.DIRECTORY_SEPARATOR.'backups_f2_catalogo_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
if(!mkdir($backupRoot,0700,true) && !is_dir($backupRoot)){fwrite(STDERR,"No se pudo crear respaldo.\n");exit(3);}
$copies=[];
foreach($pending as $path=>$op){
    $backup=$backupRoot.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$path);
    if(!is_dir(dirname($backup)) && !mkdir(dirname($backup),0700,true)){fwrite(STDERR,"No se pudo preparar respaldo.\n");exit(3);}
    if(!copy($op['dest'],$backup) || hash_file('sha256',$backup)!==$manifest[$path]['original']){
        fwrite(STDERR,"No se pudo respaldar $path. Ningun archivo aplicado.\n");exit(3);
    }
    $copies[$path]=$backup;
}
$applied=[];
foreach($pending as $path=>$op){
    if(!copy($op['src'],$op['dest']) || hash_file('sha256',$op['dest'])!==$manifest[$path]['updated']){
        foreach($applied as $old)copy($copies[$old],$pending[$old]['dest']);
        // Recuperar tambien el archivo actual si se copio de manera incompleta.
        copy($copies[$path],$op['dest']);
        fwrite(STDERR,"FALLO al instalar: se intento restaurar el respaldo en $backupRoot.\n");exit(4);
    }
    $applied[]=$path;
}
echo "INSTALACION CORRECTA. Respaldo en: $backupRoot\n";
echo "La base de datos no fue modificada.\n";
