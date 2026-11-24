<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo consola.');}
$action=$argv[1]??'';
if(!in_array($action,['--check','--apply'],true)){fwrite(STDERR,"Uso: php tools/instalar_simulador_operaciones_v21.php --check | --apply\n");exit(2);}
$root=realpath(dirname(__DIR__));$payload=__DIR__.'/gyd_simulador_payload';$errors=[];$warnings=[];$new=[];$existing=[];
if($root===false)throw new RuntimeException('No se encuentra el proyecto.');
if(PHP_VERSION_ID<80100)$errors[]='Requiere PHP 8.1 o superior.';
foreach(['app/helpers/auth.php','app/config/database.php','app/views/layouts/header.php','public/dashboard.php'] as $p)if(!is_file($root.'/'.$p))$errors[]='Falta un archivo del ERP: '.$p;
$paths=['app/helpers/gyd_simulador_lab.php','public/erp_simulador_operaciones.php','tools/gyd_simulador_operaciones_v21.php'];
foreach($paths as $rel){
    $source=$payload.'/'.$rel;$target=$root.'/'.$rel;
    if(!is_file($source)){$errors[]='Falta archivo del paquete: '.$rel;continue;}
    $out=[];$exit=1;exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($source).' 2>&1',$out,$exit);
    if($exit!==0)$errors[]='Sintaxis no válida en '.$rel.': '.implode(' ',$out);
    if(is_file($target)){if(hash_file('sha256',$source)!==hash_file('sha256',$target))$errors[]='Archivo ya modificado; no se sobrescribirá: '.$rel;else $existing[]=$rel;}
    elseif(file_exists($target))$errors[]='Ruta ocupada: '.$rel;
    else $new[]=$rel;
}
$menu=$root.'/app/views/layouts/header.php';$old=is_file($menu)?(string)file_get_contents($menu):'';$updated=null;
$link='$menuAgregar(\'⚙️ Administración\', \'Simulador de Operaciones\', \'erp_simulador_operaciones.php\', $menuEsAdmin);';
if(str_contains($old,'erp_simulador_operaciones.php'))$warnings[]='El enlace al simulador ya existe.';
else{
    $anchors=[
      '$menuAgregar(\'⚙️ Administración\', \'Centro de Pruebas Integrales\', \'erp_pruebas_integrales.php\', $menuEsAdmin);',
      '$menuAgregar(\'⚙️ Administración\', \'Rendimiento y Optimización\', \'erp_rendimiento_sistema.php\', $menuEsAdmin);',
      '$menuAgregar(\'⚙️ Administración\', \'Centro de Diagnóstico\', \'erp_diagnostico.php\', $menuEsAdmin);',
      '$menuAgregar(\'⚙️ Administración\', \'Respaldos\', \'respaldos.php\', $menuEsAdmin || $esEncargado);',
    ];
    foreach($anchors as $anchor)if(substr_count($old,$anchor)===1){$updated=str_replace($anchor,$anchor."\n".$link,$old);break;}
    if($updated===null)$warnings[]='No hay un marcador de menú seguro. Instalará la página sin tocar el menú.';
}
if($updated!==null){$temp=tempnam(sys_get_temp_dir(),'gyd_sim_menu_');file_put_contents($temp,$updated);$out=[];$exit=1;exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($temp).' 2>&1',$out,$exit);@unlink($temp);if($exit!==0)$errors[]='La modificación del menú no pasa la sintaxis.';}
foreach($warnings as $warning)echo 'AVISO: '.$warning.PHP_EOL;
if($errors){foreach($errors as $error)fwrite(STDERR,'INCOMPATIBLE: '.$error.PHP_EOL);echo "No se modificó ningún archivo.\n";exit(1);}
echo 'COMPATIBLE: nuevos '.count($new).', ya instalados '.count($existing).', menú '.($updated===null?'sin cambios':'para actualizar').".\n";
if($action==='--check'){echo "No se modificó ningún archivo ni base de datos.\n";exit;}
$copied=[];$tmpFiles=[];$menuChanged=false;$backup=null;
try {
    if($updated!==null){$backupDir=$root.'/tools/backups_simulador';if(!is_dir($backupDir)&&!mkdir($backupDir,0750,true)&&!is_dir($backupDir))throw new RuntimeException('No se pudo crear carpeta de respaldo.');$backup=$backupDir.'/header_'.date('Ymd_His').'_'.bin2hex(random_bytes(4)).'.php';if(!copy($menu,$backup))throw new RuntimeException('No se pudo respaldar el menú.');}
    foreach($new as $rel){
        $dest=$root.'/'.$rel;$source=$payload.'/'.$rel;
        if(!is_dir(dirname($dest)))throw new RuntimeException('No existe la carpeta de destino: '.$rel);
        if(file_exists($dest))throw new RuntimeException('El archivo apareció durante la instalación: '.$rel);
        $temp=$dest.'.temp_'.bin2hex(random_bytes(4));$tmpFiles[]=$temp;
        if(!copy($source,$temp)||!rename($temp,$dest))throw new RuntimeException('No se pudo copiar: '.$rel);
        $copied[]=$dest;echo 'Creado: '.$rel.PHP_EOL;
    }
    if($updated!==null){
        if((string)file_get_contents($menu)!==$old)throw new RuntimeException('El menú cambió durante la instalación.');
        $temp=$menu.'.temp_'.bin2hex(random_bytes(4));$tmpFiles[]=$temp;
        if(file_put_contents($temp,$updated,LOCK_EX)!==strlen($updated)||!rename($temp,$menu))throw new RuntimeException('No se pudo actualizar el menú.');
        $menuChanged=true;echo 'Menú actualizado; respaldo: '.$backup.PHP_EOL;
    }
    echo "INSTALADO: http://localhost/almacen-farmacia2/public/erp_simulador_operaciones.php\n";
    echo "No se modificó ninguna base de datos. Para crear las tablas aisladas utiliza --setup del simulador.\n";
}catch(Throwable $e){foreach($tmpFiles as $t)@unlink($t);if($menuChanged && $backup)@copy($backup,$menu);foreach($copied as $t)@unlink($t);fwrite(STDERR,'ERROR: '.$e->getMessage()." Se revirtieron archivos creados.\n");exit(1);}
