<?php
declare(strict_types=1);
/** Instalador G&D permisos centrales. Nunca modifica permisos existentes al instalar. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo consola.');}
$mode=$argv[1]??'';
if(!in_array($mode,['--check','--apply'],true)){fwrite(STDERR,"Uso: php tools/instalar_permisos_centrales_v17.php --check | --apply\n");exit(2);}
$root=realpath(dirname(__DIR__));$payload=__DIR__.'/gyd_acl_payload';
if(!$root){fwrite(STDERR,"No se identificó la raíz del ERP.\n");exit(2);}
$required=['app/config/database.php','app/helpers/auth.php','app/views/layouts/header.php','public/usuario.php','public/dashboard.php'];
$added=['app/helpers/gyd_acl.php','public/erp_permisos_usuarios.php'];
$errors=[];$warnings=[];$fresh=[];$installed=[];
if(PHP_VERSION_ID<80100)$errors[]='Se requiere PHP 8.1 o superior.';
foreach($required as $rel)if(!is_file($root.'/'.$rel))$errors[]='Falta archivo requerido: '.$rel;
foreach($added as $rel){
 $src=$payload.'/'.$rel;$dst=$root.'/'.$rel;
 if(!is_file($src)){$errors[]='Falta contenido del ZIP: '.$rel;continue;}
 $out=[];$code=1;exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($src).' 2>&1',$out,$code);
 if($code!==0)$errors[]='Sintaxis inválida: '.$rel;
 if(is_file($dst)){
  if(hash_file('sha256',$src)!==hash_file('sha256',$dst))$errors[]='Existe versión diferente del archivo '.$rel.'; no se sobrescribirá.';
  else $installed[]=$rel;
 }elseif(file_exists($dst))$errors[]='Ruta ocupada: '.$rel;
 else $fresh[]=$rel;
}
$auth=$root.'/app/helpers/auth.php';$header=$root.'/app/views/layouts/header.php';
$authText=is_file($auth)?(string)file_get_contents($auth):'';
$headerText=is_file($header)?(string)file_get_contents($header):'';
$hook='    // GYD_ACL_CONTROL_CENTRAL_RUTAS\n    require_once __DIR__.\'/gyd_acl.php\';\n    gyd_acl_exigir_ruta(currentUser() ?? [], basename((string)($_SERVER[\'SCRIPT_NAME\'] ?? \'\')));\n';
$hook=str_replace('\\n',"\n",$hook);
$authNew=null;
if(str_contains($authText,'GYD_ACL_CONTROL_CENTRAL_RUTAS')){
 if(!str_contains($authText,'gyd_acl_exigir_ruta('))$errors[]='Control central de rutas incompleto en auth.php.';
}else{
 $anchor='    auditTrackCurrentRequest();';
 if(substr_count($authText,$anchor)!==1)$errors[]='No se reconoció el punto seguro de autorización en auth.php.';
 else $authNew=str_replace($anchor,$hook.$anchor,$authText);
}
$menuAnchor="$"."menuAgregar('⚙️ Administración', 'Usuarios', 'usuario.php', " . '$menuEsAdmin' . ");";
$menuNewLine="$"."menuAgregar('⚙️ Administración', 'Usuarios y Permisos', 'erp_permisos_usuarios.php', " . '$menuEsAdmin' . ");";
$menuHook=<<<'ADD'
// GYD_ACL_CONTROL_CENTRAL_MENU
require_once __DIR__.'/../../helpers/gyd_acl.php';
foreach($menuSecciones as $grupoAcl => $enlacesAcl){
    $menuSecciones[$grupoAcl]=array_values(array_filter($enlacesAcl,static function(array $enlaceAcl)use($user):bool{
        $moduloAcl=gyd_acl_modulo_ruta((string)$enlaceAcl['ruta']);
        if($moduloAcl===null)return true;
        try{return gyd_acl_puede($user??[],$moduloAcl);}catch(Throwable $e){error_log('ACL menu: '.$e->getMessage());return false;}
    }));
    if(!$menuSecciones[$grupoAcl])unset($menuSecciones[$grupoAcl]);
}
ADD;
$menuNew=null;
if(str_contains($headerText,'GYD_ACL_CONTROL_CENTRAL_MENU')){
 if(!str_contains($headerText,'erp_permisos_usuarios.php'))$errors[]='El menú central existente está incompleto.';
}else{
 if(substr_count($headerText,$menuAnchor)!==1)$errors[]='No se encontró un anclaje único para el menú Usuarios.';
 if(substr_count($headerText,'$menuNumeroEnlaces = array_sum(array_map(\'count\', $menuSecciones));')!==1)$errors[]='No se encontró el anclaje final del menú.';
 if(!$errors){
  $menuNew=str_replace($menuAnchor,$menuAnchor."\n".$menuNewLine,$headerText);
  $menuNew=str_replace('$menuNumeroEnlaces = array_sum(array_map(\'count\', $menuSecciones));',$menuHook."\n".'$menuNumeroEnlaces = array_sum(array_map(\'count\', $menuSecciones));',$menuNew);
 }
}
// API de alertas V15 usa isLoggedIn() sin requireLogin: no dejar la API como ruta alternativa sin control.
$alertPath=$root.'/public/erp_alertas_gerenciales_api.php';$alertBefore=is_file($alertPath)?(string)file_get_contents($alertPath):null;$alertNew=null;
if($alertBefore!==null){
 if(str_contains($alertBefore,'GYD_ACL_CONTROL_CENTRAL_ALERTAS')){ /* ya aplicado */ }
 elseif(substr_count($alertBefore,"require_once __DIR__.'/../app/helpers/auth.php';")===1){
    $anchor="require_once __DIR__.'/../app/helpers/auth.php';";
    $addition="\n// GYD_ACL_CONTROL_CENTRAL_ALERTAS\nrequireLogin();";
    $alertNew=str_replace($anchor,$anchor.$addition,$alertBefore);
 }else $errors[]='La API de alertas usa una estructura distinta; no se puede proteger automáticamente.';
}
// Notificaciones antiguas: consultan isLoggedIn() y necesitan el mismo control de ruta.
$notifyPath=$root.'/public/api_notificaciones_pendientes.php';
$notifyBefore=is_file($notifyPath)?(string)file_get_contents($notifyPath):null;$notifyNew=null;
if($notifyBefore!==null){
 if(str_contains($notifyBefore,'GYD_ACL_CONTROL_CENTRAL_NOTIFICACIONES')){ /* ya protegido */ }
 elseif(substr_count($notifyBefore,"require_once __DIR__ . '/../app/helpers/auth.php';")===1){
    $anchor="require_once __DIR__ . '/../app/helpers/auth.php';";
    $notifyNew=str_replace($anchor,$anchor."\n// GYD_ACL_CONTROL_CENTRAL_NOTIFICACIONES\nrequireLogin();",$notifyBefore);
 }else $errors[]='La API de notificaciones usa una estructura distinta; no se modificará.';
}
$updated=[];
foreach([[$auth,$authNew],[$header,$menuNew],[$alertPath,$alertNew],[$notifyPath,$notifyNew]] as [$file,$content]){
 if($content!==null){$temp=tempnam(sys_get_temp_dir(),'gyd_acl_chk_');file_put_contents($temp,$content);
  $out=[];$code=1;exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($temp).' 2>&1',$out,$code);unlink($temp);
  if($code!==0)$errors[]='Sintaxis inválida al actualizar '.basename($file).': '.implode(' ',$out);
  $updated[$file]=$content;
 }
}
if($mode==='--apply' && !$errors){
 try{
  require_once $root.'/app/config/database.php';$db=(new Database())->connect();
  if((string)$db->query('SELECT DATABASE()')->fetchColumn()!=='prueba')throw new RuntimeException('Solo se permite instalar en la base de pruebas «prueba».');
  $t=$db->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('usuarios','pos_usuario_asignaciones','pos_sucursales','pos_cajas')")->fetchAll(PDO::FETCH_COLUMN);
  foreach(['usuarios','pos_usuario_asignaciones','pos_sucursales','pos_cajas'] as $requiredTable)if(!in_array($requiredTable,$t,true))throw new RuntimeException('Falta tabla base '.$requiredTable);
 }catch(Throwable $e){$errors[]='Prevalidación MySQL: '.$e->getMessage();}
}
if($errors){foreach($errors as $msg)fwrite(STDERR,'INCOMPATIBLE: '.$msg.PHP_EOL);echo "No se modificó ningún archivo.\n";exit(1);}
echo 'COMPATIBLE: archivos nuevos '.count($fresh).', ya instalados '.count($installed).', actualizaciones '.count($updated).'.'.PHP_EOL;
if($mode==='--check'){echo "No se cambió ningún archivo ni la base de datos. Respalda antes de --apply.\n";exit(0);}
$backs=[];$created=[];$tmp=[];
try{
 // DDL aditivo. No reemplaza usuarios ni sus permisos actuales.
 $statements=preg_split('/;\s*\n/',(string)file_get_contents($payload.'/instalar_tablas.sql'));
 foreach($statements as $sql){$sql=preg_replace('/^--[^\n]*\n/m','',$sql);if(trim((string)$sql)!=='')$db->exec($sql);}
 $backupDir=$root.'/tools/backups_permisos_centrales';
 if(!is_dir($backupDir)&&!mkdir($backupDir,0750,true)&&!is_dir($backupDir))throw new RuntimeException('No se pudo crear la carpeta de respaldos.');
 foreach(array_keys($updated) as $path){
  if(!is_file($path))throw new RuntimeException('Archivo actualizado ya no existe: '.$path);
  $backup=$backupDir.'/'.basename($path).'_'.date('Ymd_His').'_'.bin2hex(random_bytes(3)).'.bak';
  if(!copy($path,$backup))throw new RuntimeException('No se pudo respaldar '.$path);
  $backs[$path]=$backup;
 }
 foreach($fresh as $rel){
  $src=$payload.'/'.$rel;$dst=$root.'/'.$rel;
  if(file_exists($dst))throw new RuntimeException('El archivo apareció durante la instalación: '.$rel);
  $temp=$dst.'.tmp_'.bin2hex(random_bytes(4));$tmp[]=$temp;
  if(!copy($src,$temp)||!rename($temp,$dst))throw new RuntimeException('No se pudo crear '.$rel);
  $created[]=$dst;echo 'Creado: '.$rel.PHP_EOL;
 }
 foreach($updated as $path=>$content){
  $old=$path===$auth?$authText:($path===$header?$headerText:($path===$alertPath?$alertBefore:$notifyBefore));
  if((string)file_get_contents($path)!==$old)throw new RuntimeException('El archivo cambió mientras se instalaba: '.$path);
  $temp=$path.'.tmp_'.bin2hex(random_bytes(4));$tmp[]=$temp;
  if(file_put_contents($temp,$content,LOCK_EX)!==strlen($content)||!rename($temp,$path))throw new RuntimeException('Error al actualizar '.$path);
  echo 'Actualizado con respaldo: '.basename($path).PHP_EOL;
 }
 echo "INSTALADO: http://localhost/almacen-farmacia2/public/erp_permisos_usuarios.php\n";
 echo "No se modificaron roles actuales, contraseñas, ventas, retiros ni inventarios.\n";
}catch(Throwable $e){
 foreach($tmp as $file)@unlink($file);
 foreach($backs as $path=>$backup)@copy($backup,$path);
 foreach($created as $file)@unlink($file);
 fwrite(STDERR,'ERROR: '.$e->getMessage().'. Archivos restaurados; si se crearon tablas nuevas quedaron vacías y sin restricciones.'.PHP_EOL);exit(1);
}
