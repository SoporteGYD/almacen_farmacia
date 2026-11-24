<?php
declare(strict_types=1);
/** Actualiza solo el modal y la autorización de retiros, preservando el POS existente. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo consola.');}
$op=$argv[1]??'--check';
if(!in_array($op,['--check','--apply','--verify','--rollback'],true)){
    fwrite(STDERR,"Uso: php instalar_retiro_caja_supervision.php --check|--apply|--verify|--rollback\n");exit(2);
}
$root=dirname(__DIR__);
$manifest=__DIR__.'/patches_retiro_caja.json';
$helperPath='app/helpers/gyd_retiro_caja_supervision.php';
$pointer=__DIR__.'/ultima_instalacion_retiro_caja.json';
function failR(string $m):never{fwrite(STDERR,"ERROR: $m\n");exit(1);}
function shaR(string $s):string{return hash('sha256',$s);}
function writeR(string $path,string $data):void{
    $dir=dirname($path);
    if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException("No se pudo crear $dir");
    $tmp=tempnam($dir,'.gyd_caja_');
    if($tmp===false || file_put_contents($tmp,$data,LOCK_EX)===false || !rename($tmp,$path)){
        if($tmp!==false)@unlink($tmp);
        throw new RuntimeException('Error al escribir: '.$path);
    }
}
function connR(string $root):PDO{
    require_once $root.'/app/config/database.php';
    $db=(new Database())->connect();
    $name=(string)$db->query('SELECT DATABASE()')->fetchColumn();
    $port=(int)$db->query('SELECT @@port')->fetchColumn();
    if($name!=='prueba'||$port!==3307)throw new RuntimeException('Solo base prueba en MariaDB 3307. Conectado: '.$name.' / '.$port);
    foreach(['gyd_retiros_intentos_autorizacion','gyd_retiros_autorizaciones','pos_turnos','pos_cajas','usuarios'] as $table){
        $q=$db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$table]);
        if(!(int)$q->fetchColumn())throw new RuntimeException('Falta la tabla '.$table.'. Instala primero la actualización de retiros autorizados.');
    }
    return $db;
}
try{
    $patches=json_decode((string)file_get_contents($manifest),true,512,JSON_THROW_ON_ERROR);
    $markers=['public/pos.php'=>'id="gyd-retiro-metodo"',
        'public/pos_api.php'=>'gd_retiro_credencial_por_caja(',
        'public/assets/js/gyd_acceso_previo.js'=>'function modoRetiro()',
        'app/helpers/gyd_retiros_autorizaciones.php'=>"'CAJERO_CAJA_Y_CLAVE','SUPERVISOR_USUARIO_Y_CLAVE'"];
    $plan=[];
    // Si un archivo tiene cambios desconocidos, cancelar TODO antes de escribir.
    foreach($patches as $rel=>$ops){
        if(!isset($markers[$rel]))throw new RuntimeException('Ruta inesperada en manifiesto: '.$rel);
        $path=$root.'/'.$rel;
        if(!is_file($path))throw new RuntimeException('No se encuentra '.$rel);
        $old=(string)file_get_contents($path);
        if(str_contains($old,$markers[$rel])){
            foreach($ops as $p){
                if(!str_contains($old,(string)$p['new']))throw new RuntimeException('Actualización parcial o incompatibilidad en '.$rel.'. No sobrescribiré el archivo.');
            }
            $plan[$rel]=['before'=>$old,'after'=>$old,'change'=>false];continue;
        }
        $new=$old;
        foreach($ops as $p){
            $needle=(string)$p['old'];$expected=(int)$p['count'];$count=substr_count($new,$needle);
            if($count!==$expected)throw new RuntimeException('El archivo '.$rel.' fue modificado o falta la actualización de retiros anterior. Coincidencias de parche: '.$count.'; esperadas: '.$expected.'. No se sobrescribirá.');
            $new=str_replace($needle,(string)$p['new'],$new);
        }
        if(!str_contains($new,$markers[$rel]))throw new RuntimeException('La preparación de '.$rel.' falló.');
        $plan[$rel]=['before'=>$old,'after'=>$new,'change'=>true];
    }
    $payload=__DIR__.'/payload/'.$helperPath;
    if(!is_file($payload))throw new RuntimeException('Falta helper en el paquete.');
    $newHelper=(string)file_get_contents($payload);
    $existing=is_file($root.'/'.$helperPath)?(string)file_get_contents($root.'/'.$helperPath):null;
    if($existing!==null && $existing!==$newHelper)throw new RuntimeException('El helper de validación existe con cambios distintos. No se sobrescribirá.');
    $plan[$helperPath]=['before'=>$existing,'after'=>$newHelper,'change'=>$existing===null];
    if($op==='--rollback'){
        if(!is_file($pointer))throw new RuntimeException('No existe registro de esta instalación para revertir.');
        $info=json_decode((string)file_get_contents($pointer),true,512,JSON_THROW_ON_ERROR);
        $dir=$info['backup']??'';
        if(!is_string($dir)||!str_starts_with($dir,$root.'/backups/retiro_caja_')||!is_dir($dir))throw new RuntimeException('Respaldo inválido.');
        foreach($info['files'] as $rel=>$entry){
            $target=$root.'/'.$rel;
            if(!is_file($target)||hash_file('sha256',$target)!==$entry['after'])throw new RuntimeException('El archivo fue modificado después de instalar: '.$rel);
            if(!$entry['new']&&(!is_file($dir.'/'.$rel)||hash_file('sha256',$dir.'/'.$rel)!==$entry['before']))throw new RuntimeException('Respaldo no íntegro: '.$rel);
        }
        foreach($info['files'] as $rel=>$entry){
            if($entry['new'])unlink($root.'/'.$rel);
            else writeR($root.'/'.$rel,(string)file_get_contents($dir.'/'.$rel));
        }
        unlink($pointer);
        echo "RESTAURADO. Las tablas históricas de auditoría no se borraron.\n";exit(0);
    }
    $pending=array_filter($plan,fn($r)=>$r['change']);
    if($op==='--check'){
        connR($root);
        echo "COMPATIBLE · Base prueba:3307. No se modificó ningún archivo.\n";
        foreach($plan as $rel=>$p)echo ($p['change']?'LISTO: ':'YA ESTÁ: ').$rel."\n";
        exit(0);
    }
    if($op==='--verify'){
        if($pending)throw new RuntimeException('Todavía faltan cambios por instalar. Ejecuta --apply.');
        connR($root);
        echo "VERIFICADO · Retiro: cajero (caja + contraseña) o supervisor (usuario + contraseña).\n";exit(0);
    }
    connR($root);
    if(!$pending){echo "APLICADO ANTERIORMENTE: no hay cambios pendientes.\n";exit(0);}
    if(is_file($pointer))throw new RuntimeException('Ya existe una instalación registrada. Revisa --verify o realiza --rollback antes de aplicar cambios distintos.');
    $stamp=date('Ymd_His').'_'.bin2hex(random_bytes(4));
    $back=$root.'/backups/retiro_caja_'.$stamp;
    $record=['backup'=>$back,'files'=>[]];
    foreach($pending as $rel=>$p){
        $record['files'][$rel]=['new'=>$p['before']===null,'before'=>$p['before']===null?'':shaR($p['before']),'after'=>shaR($p['after'])];
        if($p['before']!==null){
            $file=$back.'/'.$rel;
            if(!is_dir(dirname($file))&&!mkdir(dirname($file),0775,true)&&!is_dir(dirname($file)))throw new RuntimeException('No fue posible crear el respaldo.');
            if(file_put_contents($file,$p['before'],LOCK_EX)===false)throw new RuntimeException('No fue posible respaldar '.$rel);
        }
    }
    $written=[];
    try{
        foreach($pending as $rel=>$p){
            writeR($root.'/'.$rel,$p['after']);$written[]=$rel;
            if(hash_file('sha256',$root.'/'.$rel)!==shaR($p['after']))throw new RuntimeException('Error verificando '.$rel);
        }
        if(file_put_contents($pointer,json_encode($record,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('No se guardó el manifiesto de respaldo.');
    }catch(Throwable $e){
        foreach(array_reverse($written) as $rel){
            if($record['files'][$rel]['new'])@unlink($root.'/'.$rel);
            else @copy($back.'/'.$rel,$root.'/'.$rel);
        }
        throw $e;
    }
    echo "APLICADO · Retiro autorizado por cajero del turno o Gerencia / Dirección / Administración.\nRespaldo: $back\nEjecuta --verify y reinicia Apache.\n";
}catch(Throwable $e){failR($e->getMessage());}
