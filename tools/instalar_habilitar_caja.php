<?php
declare(strict_types=1);
/** G&D / POS - instalación quirúrgica de asignación y habilitación por contraseña. */
if (PHP_SAPI!=='cli') {http_response_code(403);exit('Sólo por línea de comandos.');}
$mode=$argv[1]??'--check';
if(!in_array($mode,['--check','--apply','--verify'],true)){fwrite(STDERR,"Uso: php tools/instalar_habilitar_caja.php --check|--apply|--verify\n");exit(2);}
$root=dirname(__DIR__);$patchFile=__DIR__.'/patches_habilitar_caja.json';
try {
    $spec=json_decode((string)file_get_contents($patchFile),true,512,JSON_THROW_ON_ERROR);
    if(!is_array($spec)||count($spec)<4)throw new RuntimeException('Manifest de parches incompleto.');
    $helperRel='app/helpers/gyd_habilitar_caja.php';
    $helperData=(string)file_get_contents(__DIR__.'/payload/'.$helperRel);
    if($helperData==='')throw new RuntimeException('Falta el archivo de validación.');
    $updates=[];$already=0;
    foreach($spec as $rel=>$changes){
        if(str_contains($rel,'..')||str_starts_with($rel,'/'))throw new RuntimeException('Ruta no válida.');
        $path=$root.'/'.$rel;
        if(!is_file($path))throw new RuntimeException('No existe '.$rel.'; revisa la carpeta del proyecto.');
        $content=(string)file_get_contents($path);$altered=$content;
        foreach($changes as $patch){
            $old=(string)$patch['old'];$new=(string)$patch['new'];$required=(int)($patch['count']??1);
            $count=substr_count($altered,$old);
            if(substr_count($altered,$new)===$required){/* patch already applied */}
            elseif($count===$required){$altered=str_replace($old,$new,$altered);}
            else throw new RuntimeException('Archivo modificado o incompatible: '.$rel.'; no se sobrescribirá.');
        }
        if($altered===$content)$already++;
        else $updates[$rel]=$altered;
    }
    $helperPath=$root.'/'.$helperRel;
    if(is_file($helperPath)&&hash_file('sha256',$helperPath)!==hash('sha256',$helperData))
        throw new RuntimeException('Ya existe otro validador de habilitación. Resuelve el conflicto antes de instalar.');
    if(!is_file($helperPath))$updates[$helperRel]=$helperData;
    if($mode==='--check'){
        echo 'COMPATIBLE: '.count($updates).' archivos para instalar; '.$already." sin cambios.\n";
        echo "Requiere el ERP conectado a prueba:3307 para --apply. No modifica nada en este modo.\n";exit(0);
    }
    if($mode==='--verify'){
        if($updates)throw new RuntimeException('Quedan archivos sin aplicar: '.implode(', ',array_keys($updates)));
        require_once $root.'/app/config/database.php';
        $db=(new Database())->connect();
        $name=(string)$db->query('SELECT DATABASE()')->fetchColumn();
        $present=(bool)$db->query("SHOW TABLES LIKE 'gyd_apertura_intentos'")->fetchColumn();
        if($name!=='prueba'||!$present)throw new RuntimeException('No se encontró el registro de intentos en la base prueba.');
        echo "VERIFICADO: pantallas, permisos y validador de caja instalados.\n";exit(0);
    }
    require_once $root.'/app/config/database.php';
    $db=(new Database())->connect();
    $name=(string)$db->query('SELECT DATABASE()')->fetchColumn();
    $port=(int)$db->query('SELECT @@port')->fetchColumn();
    if($name!=='prueba'||$port!==3307)throw new RuntimeException('Instalación detenida: se requiere prueba en puerto 3307.');
    if(!$updates){echo "INSTALADO: no hay cambios pendientes.\n";exit(0);}
    $db->exec("CREATE TABLE IF NOT EXISTS gyd_apertura_intentos (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL,
        caja_id INT NOT NULL,
        exitoso TINYINT(1) NOT NULL,
        creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_gyd_apertura_fallos (usuario_id,exitoso,creado_en)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $backups=$root.'/backups_gyd_habilitar_caja_'.date('Ymd_His');
    if(!mkdir($backups,0750,true)&&!is_dir($backups))throw new RuntimeException('No se pudo crear el directorio de respaldo.');
    $written=[];
    try {
        foreach($updates as $rel=>$new){
            $path=$root.'/'.$rel;
            if(is_file($path)){
                $dest=$backups.'/'.$rel;
                if(!is_dir(dirname($dest))&&!mkdir(dirname($dest),0750,true)&&!is_dir(dirname($dest)))throw new RuntimeException('Error creando directorio de respaldo.');
                if(!copy($path,$dest))throw new RuntimeException('No se pudo respaldar '.$rel);
            }
        }
        foreach($updates as $rel=>$new){
            $path=$root.'/'.$rel;
            if(!is_dir(dirname($path)))throw new RuntimeException('Carpeta de aplicación no encontrada.');
            $temp=$path.'.gyd-tmp-'.bin2hex(random_bytes(4));
            if(file_put_contents($temp,$new,LOCK_EX)!==strlen($new))throw new RuntimeException('Escritura incompleta en '.$rel);
            if(is_file($path)){@chmod($temp,fileperms($path)&0777);}
            if(!rename($temp,$path)){@unlink($temp);throw new RuntimeException('No se pudo actualizar '.$rel);}
            $written[]=$rel;
        }
    }catch(Throwable $e){
        foreach(array_reverse($written) as $rel){$dest=$backups.'/'.$rel;$path=$root.'/'.$rel;if(is_file($dest))copy($dest,$path);else @unlink($path);}
        throw $e;
    }
    echo 'INSTALADO: '.count($written)." archivos actualizados. Respaldo: $backups\n";
    echo "Ejecuta --verify; reinicia Apache y vuelve a entrar al POS.\n";
}catch(Throwable $e){fwrite(STDERR,'ERROR: '.$e->getMessage()."\n");exit(1);}
