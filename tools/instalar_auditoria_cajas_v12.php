<?php
declare(strict_types=1);
/** Instalador aditivo V12, consola. No ejecuta SQL de escritura. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo CLI.');}
if(!in_array($argv[1]??'', ['--check','--apply'],true)){
    fwrite(STDERR,"Uso: php tools/instalar_auditoria_cajas_v12.php --check | --apply\n");exit(2);
}
$apply=$argv[1]==='--apply';
$root=realpath(__DIR__.'/..');$src=__DIR__.'/gyd_auditoria_v12_payload';
if($root===false){fwrite(STDERR,"ERROR: raíz del ERP no encontrada.\n");exit(2);}
$targets=[
  'app/helpers/gyd_auditoria_cajas_v12.php',
  'public/erp_auditoria_cajas_v12.php',
];
$errors=[];$warnings=[];
if(PHP_VERSION_ID<80100)$errors[]='Requiere PHP >=8.1.';
foreach(['app/config/database.php','app/helpers/auth.php','app/views/layouts/header.php','public/pos.php'] as $f){
    if(!is_file($root.'/'.$f))$errors[]='Falta archivo del sistema: '.$f;
}
$cfg=is_file($root.'/app/config/database.php')?(string)file_get_contents($root.'/app/config/database.php'):'';
if(!preg_match('/\$dbName\s*=\s*[\'\"]prueba[\'\"]/m',$cfg))$errors[]='Por seguridad V12 solo se instala en un proyecto configurado para la BD prueba.';
foreach($targets as $rel){
    $source=$src.'/'.$rel;$dest=$root.'/'.$rel;
    if(!is_file($source))$errors[]='Falta archivo de la V12: '.$rel;
    elseif(is_file($dest)&&hash_file('sha256',$source)!==hash_file('sha256',$dest))$errors[]='Archivo V12 distinto en destino (no se sobrescribe): '.$rel;
    elseif(is_file($dest))$warnings[]='Ya instalado sin cambios: '.$rel;
    if(is_file($source)){
        $output=[];$exit=1;
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($source).' 2>&1',$output,$exit);
        if($exit!==0)$errors[]='Error PHP: '.$rel.' '.implode(' ',$output);
    }
}
$menu=$root.'/app/views/layouts/header.php';
$anchor="\$menuAgregar('🛍️ Ventas y gerencia', 'Control de Cajas y Arqueos', 'erp_control_cajas.php', \$menuEsAdmin);";
$line="\$menuAgregar('🛍️ Ventas y gerencia', 'Auditoría y Control de Cajas · V12', 'erp_auditoria_cajas_v12.php', \$menuEsAdmin || \$esGerente || in_array(\$rolUsuario,['DUENO','DUEÑO','PROPIETARIO'],true));";
$header=is_file($menu)?file_get_contents($menu):false;
if(!is_string($header))$errors[]='No se pudo leer el menú.';
elseif(!str_contains($header,$line) && substr_count($header,$anchor)!==1)$warnings[]='No coincide el punto de menú; instala la página y agrega el enlace manualmente.';
elseif(str_contains($header,$line))$warnings[]='El enlace V12 ya está en el menú, no se duplicará.';
if(!is_file($root.'/app/helpers/gyd_acceso_previo.php'))$warnings[]='No se encontró V11; el panel de auditoría puede instalarse, pero confirma que el flujo de autorización esté instalado.';
if(!extension_loaded('pdo_mysql'))$warnings[]='PHP CLI sin pdo_mysql: no se puede comprobar MySQL desde este terminal.';
else {
    try {
        // Esta conexión se usa únicamente para SELECT; jamás ejecutar DDL ni DML.
        require_once $root.'/app/config/database.php';
        $db=(new Database())->connect();
        if((string)$db->query('SELECT DATABASE()')->fetchColumn()!=='prueba')$errors[]='La conexión MySQL no apunta a prueba.';
        $st=$db->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');
        foreach(['pos_turnos','pos_cajas','pos_sucursales','usuarios','pos_movimientos_caja','pos_auditoria'] as $name){
            $st->execute([$name]);if(!$st->fetchColumn())$errors[]='Falta tabla principal: '.$name;
        }
        foreach(['gyd_cortes_autorizaciones','gyd_retiros_boveda','gyd_cortes_intentos','gyd_retiros_intentos_clave'] as $name){
            $st->execute([$name]);if(!$st->fetchColumn())$warnings[]='Tabla opcional no disponible: '.$name;
        }
    }catch(Throwable $e){$warnings[]='No se comprobó MySQL: '.$e->getMessage();}
}
foreach($warnings as $w)echo 'AVISO: '.$w.PHP_EOL;
if($errors){foreach($errors as $e)fwrite(STDERR,'INCOMPATIBLE: '.$e.PHP_EOL);echo "No se modificó ningún archivo.\n";exit(1);}
if(!$apply){echo "COMPATIBLE. Revisa los avisos, respalda tu proyecto y ejecuta --apply.\n";exit(0);}
$created=[];$backupMenu=null;$menuTouched=false;
try {
    foreach($targets as $rel){
        $dest=$root.'/'.$rel;$source=$src.'/'.$rel;
        if(is_file($dest)){echo 'Ya presente: '.$rel.PHP_EOL;continue;}
        if(!is_dir(dirname($dest)))throw new RuntimeException('Directorio no disponible: '.dirname($dest));
        $tmp=$dest.'.tmpv12_'.bin2hex(random_bytes(4));
        if(file_put_contents($tmp,file_get_contents($source),LOCK_EX)===false||!rename($tmp,$dest))throw new RuntimeException('No se pudo instalar: '.$rel);
        $created[]=$dest;echo 'Creado: '.$rel.PHP_EOL;
    }
    if(is_string($header) && !str_contains($header,$line) && substr_count($header,$anchor)===1){
        $backupMenu=$menu.'.backup_v12_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
        if(!copy($menu,$backupMenu))throw new RuntimeException('No se pudo respaldar el menú.');
        $updated=str_replace($anchor,$anchor.PHP_EOL.$line,$header);
        $tmp=$menu.'.tmpv12_'.bin2hex(random_bytes(4));
        if(file_put_contents($tmp,$updated,LOCK_EX)===false||!rename($tmp,$menu))throw new RuntimeException('No se pudo actualizar el menú.');
        $menuTouched=true;
        echo 'Enlace agregado al menú; respaldo: '.basename($backupMenu).PHP_EOL;
    }
}catch(Throwable $e){
    if($menuTouched && $backupMenu!==null)copy($backupMenu,$menu);
    foreach($created as $c)@unlink($c);
    fwrite(STDERR,'ERROR: '.$e->getMessage().'. Se revirtieron los cambios realizados.\n');exit(1);
}
echo "V12 INSTALADA. Abre http://localhost/almacen-farmacia2/public/erp_auditoria_cajas_v12.php\n";
