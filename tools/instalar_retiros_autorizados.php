<?php
declare(strict_types=1);
/** Instalador transaccional de archivos para autorización de retiros.
 * Ejecutar en CLI XAMPP. Se niega si detecta archivos distintos a los examinados.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Uso exclusivo en consola.'); }
$mode = (string)($argv[1] ?? '--check');
if (!in_array($mode, ['--check','--apply','--verify','--rollback'],true)) {
    fwrite(STDERR,"Uso: php instalar_retiros_autorizados.php --check|--apply|--verify|--rollback\n");exit(2);
}
$base = dirname(__DIR__);
$manifestPath=__DIR__.'/manifest_retiros_autorizados.json';
$pointer=__DIR__.'/ultima_instalacion_retiros_autorizados.json';
function abortar(string $m):never { fwrite(STDERR,"ERROR: $m\n");exit(1); }
function digest(string $s):string {return hash('sha256',$s);}
function currentBytes(string $f):?string {return is_file($f)?(string)file_get_contents($f):null;}
function writeAtomic(string $f,string $s):void {
    $dir=dirname($f);
    if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('No se pudo crear '. $dir);
    $tmp=tempnam($dir,'.gyd_retiro_');
    if($tmp===false || file_put_contents($tmp,$s,LOCK_EX)===false || !rename($tmp,$f)){
        if($tmp!==false)@unlink($tmp);
        throw new RuntimeException('No se pudo actualizar '.$f);
    }
}
function conexionPrueba(string $base):PDO {
    require_once $base.'/app/config/database.php';
    $pdo=(new Database())->connect();
    $dbname=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $port=(int)$pdo->query('SELECT @@port')->fetchColumn();
    if($dbname!=='prueba' || $port!==3307)throw new RuntimeException('Solo se permite usar MariaDB prueba, puerto 3307. Conexión recibida: '.$dbname.' / '.$port);
    return $pdo;
}
function schema(PDO $db,bool $write):void {
    $sqls=[
"CREATE TABLE IF NOT EXISTS gyd_retiros_autorizaciones (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 retiro_id BIGINT UNSIGNED NOT NULL,
 turno_id BIGINT UNSIGNED NOT NULL,
 caja_id BIGINT UNSIGNED NOT NULL,
 cajero_apertura_id INT NOT NULL,
 operador_id INT NOT NULL,
 autorizador_id INT NOT NULL,
 autorizador_rol VARCHAR(40) NOT NULL,
 modalidad VARCHAR(40) NOT NULL,
 registrado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_gd_retiro_autorizado (retiro_id),
 KEY idx_gd_autorizador (autorizador_id, registrado_en),
 KEY idx_gd_operador (operador_id, registrado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS gyd_retiros_intentos_autorizacion (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 operador_id INT NOT NULL,
 autorizador_id INT NULL,
 login_intentado VARCHAR(120) NOT NULL,
 exitoso TINYINT(1) NOT NULL,
 ip_hash CHAR(64) NOT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_gd_intentos_operador (operador_id,exitoso,creado_en),
 KEY idx_gd_intentos_autorizador (autorizador_id,exitoso,creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
    if($write){foreach($sqls as $q)$db->exec($q);}
    foreach(['gyd_retiros_autorizaciones'=>['retiro_id','operador_id','autorizador_id','autorizador_rol','modalidad'],
             'gyd_retiros_intentos_autorizacion'=>['operador_id','autorizador_id','login_intentado','exitoso']] as $table=>$columns){
        $st=$db->prepare('SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=?');
        $st->execute([$table]);$got=$st->fetchAll(PDO::FETCH_COLUMN);
        foreach($columns as $col) if(!in_array($col,$got,true))throw new RuntimeException("Esquema incompleto o no instalado: $table.$col");
    }
}
try {
    $manifest=json_decode((string)file_get_contents($manifestPath),true,512,JSON_THROW_ON_ERROR);
    $plan=[];$unchanged=true;
    // El acceso operativo del cajero debe estar autorizado por la actualización previa.
    $auth=currentBytes($base.'/app/helpers/auth.php');
    if(!is_string($auth)||!str_contains($auth,"'pos_retiros.php'"))
        throw new RuntimeException('Primero aplica la actualización de perfil CAJERO; faltan las rutas autorizadas de retiro.');
    foreach($manifest['files'] as $relative=>$item){
        $path=$base.'/'.$relative;$now=currentBytes($path);$hash=$now===null?'':digest($now);
        if($now!==null && $hash===$item['after']){$plan[$relative]=['path'=>$path,'before'=>$now,'new'=>$now,'change'=>false,'already'=>true];continue;}
        if($relative==='app/helpers/gyd_retiros_autorizaciones.php'){
            if($now!==null) throw new RuntimeException('El helper de autorización ya existe pero no coincide. No sobrescribiré '.$relative);
            $variant='default';
        }else {
            $variant=array_search($hash,$item['before'],true);
            if($variant===false)throw new RuntimeException('Archivo modificado o versión incompatible: '.$relative.'. No se reemplazará. Envía ZIP actualizado si es necesario.');
        }
        $src=__DIR__.'/payload/'.$relative.($variant==='default'?'':'.'.$variant);
        $new=(string)file_get_contents($src);
        if(digest($new)!==$item['after'])throw new RuntimeException('Archivo de actualización corrupto: '.$relative);
        $plan[$relative]=['path'=>$path,'before'=>$now,'new'=>$new,'change'=>true,'already'=>false];
        $unchanged=false;
    }
    if($mode==='--check'){
        $db=conexionPrueba($base);
        echo "COMPATIBLE · Base prueba:3307. Sin cambios realizados.\n";
        foreach($plan as $rel=>$p)echo ($p['change']?'LISTO: ':'YA INSTALADO: ').$rel."\n";
        exit(0);
    }
    if($mode==='--verify'){
        if(!$unchanged)throw new RuntimeException('Todavía faltan archivos por instalar.');
        schema(conexionPrueba($base),false);
        echo "VERIFICADO · Autorización de retiros y registro de autorizadores operativos.\n";exit(0);
    }
    if($mode==='--rollback'){
        if(!is_file($pointer)) throw new RuntimeException('No existe respaldo para revertir.');
        $data=json_decode((string)file_get_contents($pointer),true,512,JSON_THROW_ON_ERROR);
        $folder=(string)($data['folder']??'');
        if(!str_starts_with($folder,$base.'/backups/autorizacion_retiros_')||!is_dir($folder))throw new RuntimeException('Respaldo inválido.');
        foreach($data['files'] as $rel=>$record){
            $now=currentBytes($base.'/'.$rel);
            if($now===null||digest($now)!==($record['after']??''))throw new RuntimeException('Cambios posteriores en '.$rel.'. Detengo rollback.');
            if(!($record['new']??false) && (!is_file($folder.'/'.$rel) || hash_file('sha256',$folder.'/'.$rel)!==($record['before']??'')))throw new RuntimeException('Respaldo incompleto: '.$rel);
        }
        foreach($data['files'] as $rel=>$record){
            if($record['new']??false)unlink($base.'/'.$rel);
            else writeAtomic($base.'/'.$rel,(string)file_get_contents($folder.'/'.$rel));
        }
        echo "RESTAURADO · Código anterior recuperado. Las tablas de auditoría permanecen para conservar evidencias.\n";
        exit(0);
    }
    // --apply: verificar la conexión y crear las tablas antes de habilitar rutas nuevas.
    $db=conexionPrueba($base);
    schema($db,true);
    if($unchanged){echo "APLICADO · Todos los archivos ya están actualizados.\n";exit(0);}
    $stamp=date('Ymd_His').'_'.bin2hex(random_bytes(4));
    $backup=$base.'/backups/autorizacion_retiros_'.$stamp;
    $changes=[];$metadata=['folder'=>$backup,'files'=>[]];
    foreach($plan as $rel=>$p){
        if(!$p['change'])continue;
        if(!is_dir($backup.'/'.dirname($rel))&&!mkdir($backup.'/'.dirname($rel),0775,true))throw new RuntimeException('No se pudo crear carpeta de respaldo.');
        $metadata['files'][$rel]=['new'=>$p['before']===null,'before'=>$p['before']===null?'':digest($p['before']),'after'=>digest($p['new'])];
        if($p['before']!==null)file_put_contents($backup.'/'.$rel,$p['before'],LOCK_EX);
    }
    try {
        foreach($plan as $rel=>$p){
            if(!$p['change'])continue;
            writeAtomic($p['path'],$p['new']);$changes[]=$rel;
            if(hash_file('sha256',$p['path'])!==digest($p['new']))throw new RuntimeException('Verificación falló: '.$rel);
        }
        file_put_contents($pointer,json_encode($metadata,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX);
    }catch(Throwable $e){
        foreach(array_reverse($changes) as $rel){
            if($metadata['files'][$rel]['new'])@unlink($base.'/'.$rel);
            else @copy($backup.'/'.$rel,$base.'/'.$rel);
        }
        throw $e;
    }
    echo "APLICADO · Retiro por contraseña de cajero de apertura, gerente, dueño o administrador.\n";
    echo "Respaldo: $backup\nEjecuta --verify, inicia Apache y actualiza el navegador.\n";
}catch(Throwable $e){abortar($e->getMessage());}
