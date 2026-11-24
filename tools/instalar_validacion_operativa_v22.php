<?php
declare(strict_types=1);
/** Instalador G&D. No aplica SQL ni modifica operaciones. --check o --apply */
if(PHP_SAPI!=='cli'){http_response_code(403);exit("Solo se permite desde terminal.\n");}
$mode=$argv[1]??'';
if(!in_array($mode,['--check','--apply'],true)){fwrite(STDERR,"Uso: php tools/instalar_validacion_operativa_v22.php --check|--apply\n");exit(2);}
$root=dirname(__DIR__);$payload=__DIR__.'/gyd_validacion_payload';
$files=[
    $payload.'/app/helpers/gyd_validacion_operativa.php'=>$root.'/app/helpers/gyd_validacion_operativa.php',
    $payload.'/public/erp_validacion_operativa.php'=>$root.'/public/erp_validacion_operativa.php',
];
try{
    $dbFile=$root.'/app/config/database.php';
    if(!is_file($dbFile)||!is_file($root.'/app/helpers/auth.php'))throw new RuntimeException('No se encontró la estructura del proyecto ERP.');
    require_once $dbFile;
    $db=(new Database())->connect();
    $dbName=(string)$db->query('SELECT DATABASE()')->fetchColumn();
    if($dbName!=='prueba')throw new RuntimeException('Instalación detenida: base activa no es «prueba» ('.($dbName?:'ninguna').').');
    $new=0;$same=0;
    foreach($files as $src=>$dst){
        if(!is_file($src))throw new RuntimeException('Falta el archivo del paquete '.basename($src));
        if(is_file($dst)){
            if(hash_file('sha256',$src)!==hash_file('sha256',$dst))throw new RuntimeException('Existe un archivo diferente que no se sobrescribirá: '.$dst);
            $same++;
        }else{
            if(!is_dir(dirname($dst)))throw new RuntimeException('Falta carpeta destino: '.dirname($dst));
            $new++;
        }
    }
    $menu=$root.'/app/views/layouts/header.php';$menuNext=null;$insert=false;
    if(is_file($menu)){
        $text=(string)file_get_contents($menu);
        if(!str_contains($text,"'erp_validacion_operativa.php'")){
            $lines=preg_split('/(?<=\n)/',$text);$pos=null;
            foreach(['Centro de Pruebas Integrales','Respaldos'] as $needle){
                foreach($lines as $index=>$line){if(str_contains($line,"\$menuAgregar(")&&str_contains($line,"'⚙️ Administración'")&&str_contains($line,"'".$needle."'")){$pos=$index;break;}}
                if($pos!==null)break;
            }
            if($pos!==null){
                $line="\$menuAgregar('⚙️ Administración', 'Centro de Validación Operativa', 'erp_validacion_operativa.php', \$menuEsAdmin);\n";
                array_splice($lines,$pos+1,0,[$line]);$menuNext=implode('',$lines);$insert=true;
            }
        }
    }
    echo "COMPATIBLE: ".$new." archivos nuevos, ".$same." ya instalados. Base: prueba.\n";
    echo ($insert?'Se añadirá un enlace al menú con respaldo previo.':(is_file($menu)?'Menú ya integrado o estructura no reconocida; se conserva intacto.':'No se encontró menú; acceso por URL.'))."\n";
    if($mode==='--check'){echo "No se modificó ningún archivo.\n";exit(0);}
    $created=[];
    try{
        foreach($files as $src=>$dst){if(is_file($dst))continue;
            $temp=$dst.'.tmp.'.bin2hex(random_bytes(4));
            if(!copy($src,$temp))throw new RuntimeException('No se pudo copiar '.basename($dst));
            if(!rename($temp,$dst)){@unlink($temp);throw new RuntimeException('No se pudo finalizar '.basename($dst));}
            $created[]=$dst;echo 'Creado: '.substr($dst,strlen($root)+1)."\n";
        }
        if($insert&&$menuNext!==null){
            $backup=$menu.'.bak_validacion_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
            if(!copy($menu,$backup))throw new RuntimeException('No se pudo respaldar el menú.');
            $temp=$menu.'.tmp.'.bin2hex(random_bytes(4));
            if(file_put_contents($temp,$menuNext,LOCK_EX)===false){@unlink($temp);throw new RuntimeException('No se pudo preparar el menú.');}
            if(!rename($temp,$menu)){@unlink($temp);throw new RuntimeException('No se pudo actualizar el menú.');}
            echo "Menú actualizado. Respaldo: $backup\n";
        }
    }catch(Throwable $e){foreach($created as $file)@unlink($file);throw $e;}
    echo "INSTALADO. Abre public/erp_validacion_operativa.php. No se ejecutó SQL.\n";
}catch(Throwable $e){fwrite(STDERR,'ERROR: '.$e->getMessage()."\n");exit(1);}
