<?php
declare(strict_types=1);
/** Instalador aditivo y conservador. Solo ejecutable por CLI. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo consola.');}
$mode=$argv[1]??'';
if(!in_array($mode,['--check','--apply'],true)){fwrite(STDERR,"Uso: php tools/instalar_pruebas_integrales_v201.php --check | --apply\n");exit(2);}
$root=realpath(dirname(__DIR__));$payload=__DIR__.'/gyd_pruebas_payload';
if(!$root){fwrite(STDERR,"No existe la raíz del proyecto.\n");exit(2);}
$files=['app/helpers/gyd_pruebas_integrales.php','public/erp_pruebas_integrales.php'];
$required=['app/config/database.php','app/helpers/auth.php','app/views/layouts/header.php','public/dashboard.php'];
$errors=[];$warnings=[];$new=[];$existing=[];
if(PHP_VERSION_ID<80100)$errors[]='Se requiere PHP 8.1 o superior.';
foreach($required as $f)if(!is_file($root.'/'.$f))$errors[]='Falta archivo original: '.$f;
foreach($files as $rel){
    $src=$payload.'/'.$rel;$dst=$root.'/'.$rel;
    if(!is_file($src)){$errors[]='No existe archivo del ZIP: '.$rel;continue;}
    $out=[];$res=1;exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($src).' 2>&1',$out,$res);
    if($res!==0)$errors[]='Error de sintaxis: '.$rel.' '.implode(' ',$out);
    if(is_file($dst)){
        if(hash_file('sha256',$src)!==hash_file('sha256',$dst))$errors[]='Existe un archivo distinto; no se reemplazará: '.$rel;
        else $existing[]=$rel;
    }elseif(file_exists($dst))$errors[]='La ruta está ocupada: '.$rel;
    else $new[]=$rel;
}
$schemaFile=$payload.'/instalar_tablas.sql';
$sqlStatements=[];
if(!is_file($schemaFile)){$errors[]='Falta instalar_tablas.sql';}
else{
    $schemaRaw=file_get_contents($schemaFile);
    if($schemaRaw===false){$errors[]='No se pudo leer instalar_tablas.sql';}
    else{
        // Se eliminan los comentarios SQL completos ANTES de separar sentencias.
        // El instalador anterior separaba un punto y coma dentro de un comentario.
        $schemaWithoutComments=preg_replace('/^[ \t]*--[^\r\n]*(?:\r?\n|$)/m','',$schemaRaw);
        if($schemaWithoutComments===null){$errors[]='No se pudo analizar el SQL';}
        else{
            $sqlStatements=array_values(array_filter(array_map('trim',explode(';',$schemaWithoutComments)),static fn(string $x):bool=>$x!==''));
            $allow=['gyd_pruebas_integrales_runs'=>false,'gyd_pruebas_integrales_manual'=>false];
            foreach($sqlStatements as $statement){
                if(!preg_match('/\ACREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?(gyd_pruebas_integrales_(?:runs|manual))`?\s*\(/i',$statement,$m) || !array_key_exists(strtolower($m[1]),$allow)){
                    $errors[]='SQL inesperado: solo se permiten las dos tablas de evidencias de pruebas.';
                    break;
                }
                $table=strtolower($m[1]);
                if($allow[$table]){$errors[]='SQL duplicado en instalar_tablas.sql: '.$table;break;}
                $allow[$table]=true;
            }
            if(count($sqlStatements)!==2 || in_array(false,$allow,true))$errors[]='El esquema debe contener exactamente las dos tablas de historial y evidencias.';
        }
    }
}

$header=$root.'/app/views/layouts/header.php';$before=is_file($header)?(string)file_get_contents($header):'';$after=null;
$link='$menuAgregar(\'⚙️ Administración\', \'Centro de Pruebas Integrales\', \'erp_pruebas_integrales.php\', $menuEsAdmin);';
if(str_contains($before,'erp_pruebas_integrales.php'))$warnings[]='El enlace al Centro de Pruebas ya existe en el menú.';
else {
  $anchors=[
    '$menuAgregar(\'⚙️ Administración\', \'Rendimiento y Optimización\', \'erp_rendimiento_sistema.php\', $menuEsAdmin);',
    '$menuAgregar(\'⚙️ Administración\', \'Centro de Diagnóstico\', \'erp_diagnostico.php\', $menuEsAdmin);',
    '$menuAgregar(\'⚙️ Administración\', \'Usuarios y Permisos\', \'erp_permisos_usuarios.php\', $menuEsAdmin);',
    '$menuAgregar(\'⚙️ Administración\', \'Respaldos\', \'respaldos.php\', $menuEsAdmin || $esEncargado);',
  ];
  foreach($anchors as $a)if(substr_count($before,$a)===1){$after=str_replace($a,$a."\n".$link,$before);break;}
  if($after===null)$warnings[]='No se encontró un lugar seguro en el menú. Se instalará el enlace directo sin cambiar el menú.';
}
if($after!==null){
    $tmp=tempnam(sys_get_temp_dir(),'gpi_menu_');file_put_contents($tmp,$after);$out=[];$code=1;
    exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1',$out,$code);@unlink($tmp);
    if($code!==0)$errors[]='El menú modificado no pasa sintaxis PHP.';
}
$db=null;
if(!$errors){
  try {
    require_once $root.'/app/config/database.php';$db=(new Database())->connect();
    if((string)$db->query('SELECT DATABASE()')->fetchColumn()!=='prueba')throw new RuntimeException('Se permite instalar únicamente en la base «prueba».');
    $expect=[
      'gyd_pruebas_integrales_runs'=>['id','usuario_id','informe_json','correctas','revision','fallidas','no_disponibles','creado_en'],
      'gyd_pruebas_integrales_manual'=>['id','usuario_id','escenario','resultado','observacion','creado_en'],
    ];
    foreach($expect as $table=>$requiredCols){
      $s=$db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->execute([$table]);
      $cols=$s->fetchAll(PDO::FETCH_COLUMN);
      if($cols && array_diff($requiredCols,$cols))throw new RuntimeException('La tabla existente '.$table.' tiene columnas incompatibles. No se sobrescribirá.');
    }
  }catch(Throwable $e){$errors[]='Verificación MySQL: '.$e->getMessage();}
}
foreach($warnings as $x)echo 'AVISO: '.$x.PHP_EOL;
if($errors){foreach($errors as $e)fwrite(STDERR,'INCOMPATIBLE: '.$e.PHP_EOL);echo "No se modificó ningún archivo ni base de datos.\n";exit(1);}
echo 'COMPATIBLE: archivos nuevos '.count($new).', ya instalados '.count($existing).', menú '.($after===null?'sin cambios':'por actualizar').'.'.PHP_EOL;
if($mode==='--check'){echo "No se modificó ningún archivo ni registro. Realiza respaldo antes de --apply.\n";exit(0);}
$created=[];$temp=[];$backup=null;$changed=false;$createdTables=[];
try{
    if($after!==null){
      $backupDir=$root.'/tools/backups_pruebas_integrales';
      if(!is_dir($backupDir)&&!mkdir($backupDir,0750,true)&&!is_dir($backupDir))throw new RuntimeException('No se pudo crear carpeta de respaldos.');
      $backup=$backupDir.'/header_'.date('Ymd_His').'_'.bin2hex(random_bytes(4)).'.php';
      if(!copy($header,$backup))throw new RuntimeException('Falló respaldo del menú.');
    }
    foreach($new as $rel){
      $dst=$root.'/'.$rel;$src=$payload.'/'.$rel;
      if(!is_dir(dirname($dst)))throw new RuntimeException('No existe directorio de destino '.$rel);
      if(file_exists($dst))throw new RuntimeException('El archivo apareció durante la instalación: '.$rel);
      $tmp=$dst.'.tmp_'.bin2hex(random_bytes(4));$temp[]=$tmp;
      if(!copy($src,$tmp)||!rename($tmp,$dst))throw new RuntimeException('Error al copiar '.$rel);
      $created[]=$dst;echo 'Creado: '.$rel.PHP_EOL;
    }
    if($after!==null){
      if((string)file_get_contents($header)!==$before)throw new RuntimeException('El menú cambió durante la instalación.');
      $tmp=$header.'.tmp_'.bin2hex(random_bytes(4));$temp[]=$tmp;
      if(file_put_contents($tmp,$after,LOCK_EX)!==strlen($after)||!rename($tmp,$header))throw new RuntimeException('No se pudo actualizar el menú.');
      $changed=true;echo 'Menú actualizado con respaldo: '.$backup.PHP_EOL;
    }
    foreach($sqlStatements as $sql){$db->exec($sql);}
    echo "INSTALADO: http://localhost/almacen-farmacia2/public/erp_pruebas_integrales.php\n";
    echo "Se crearon únicamente tablas de historial/evidencia; no se escribieron datos operativos.\n";
}catch(Throwable $e){
    foreach($temp as $x)@unlink($x);
    if($changed&&$backup)@copy($backup,$header);
    foreach($created as $x)@unlink($x);
    fwrite(STDERR,'ERROR: '.$e->getMessage().". Archivos revertidos; revisar si la creación de tablas quedó parcial.\n");exit(1);
}
