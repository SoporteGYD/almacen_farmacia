<?php
declare(strict_types=1);
/** Installer seguro, sólo CLI: validación previa, copia, SQL CREATE TABLE y rollback de archivos. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Sólo terminal.');}
$mode=$argv[1]??'';
if(!in_array($mode,['--check','--apply'],true)) {fwrite(STDERR,"Uso: php tools/instalar_flujo_oc_avisos.php --check | --apply\n");exit(2);}
$root=realpath(__DIR__.'/..');
if(!$root || !is_file($root.'/app/helpers/auth.php')){fwrite(STDERR,"Estructura del ERP inválida.\n");exit(2);}
$rules=json_decode((string)file_get_contents(__DIR__.'/ocav_patches.json'),true,512,JSON_THROW_ON_ERROR);
$updated=[];$skipped=[];$errors=[];
foreach($rules as $r){
  $rel=$r['file']??'';
  if(!is_string($rel) || str_contains($rel,'..') || !preg_match('~^(app|public)/[A-Za-z0-9_/.-]+\.php$~D',$rel)){$errors[]='Ruta inválida en manifest';continue;}
  $path=$root.'/'.$rel;
  if(!is_file($path)){if(!empty($r['optional'])){$skipped[]=$rel;continue;}$errors[]="No existe $rel";continue;}
  $txt=(string)file_get_contents($path);
  if(str_contains($txt,$r['new'])){$skipped[]=$rel;continue;}
  if(substr_count($txt,$r['old'])!==1){$errors[]="INCOMPATIBLE: $rel. Código diferente del esperado; no sobrescribir.";continue;}
  $updated[]=$rel;
}
foreach($errors as $e)fwrite(STDERR,"- $e\n");
if($errors){fwrite(STDERR,"INCOMPATIBLE: no se modificó ningún archivo.\n");exit(3);}

// Verificación de esquema compatible con MariaDB y PDO con emulación desactivada.
// SHOW TABLES LIKE ? NO admite marcadores con prepared statements nativos.
// La consulta a INFORMATION_SCHEMA sí admite parámetros enlazados.
function ocav_verificar_esquema(PDO $db): void {
  $required=['erp_ordenes_compra','erp_oc_recepciones','erp_oc_recepciones_detalle','erp_facturas_stock_aplicaciones','movimientos'];
  $st=$db->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND TABLE_TYPE = 'BASE TABLE'");
  if(!$st)throw new RuntimeException('No se pudo preparar la comprobación de tablas.');
  foreach($required as $table){
    $st->execute([$table]);
    if((int)$st->fetchColumn()<1)throw new RuntimeException('Falta la tabla requerida: '.$table);
    $st->closeCursor();
  }
  // Prevenir un fallo en el CREATE TABLE por claves foráneas de tipo diferente.
  $q=$db->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
  if(!$q)throw new RuntimeException('No se pudo consultar la estructura de IDs.');
  foreach(['erp_ordenes_compra','erp_oc_recepciones'] as $table){
    $q->execute([$table,'id']);
    $tipo=(string)$q->fetchColumn();
    $q->closeCursor();
    if(!preg_match('/^bigint(?:\(\d+\))? unsigned$/i',trim($tipo))){
      throw new RuntimeException('La columna '.$table.'.id debe ser BIGINT UNSIGNED para crear los vínculos; tipo actual: '.($tipo?:'no encontrado'));
    }
  }
}

try{
  require_once $root.'/app/config/database.php';
  $db=(new Database())->connect();
  ocav_verificar_esquema($db);
}catch(Throwable $e){
  fwrite(STDERR,"INCOMPATIBLE (base de datos): ".$e->getMessage()."\nNo se modificaron archivos, tablas ni existencias.\n");
  exit(4);
}

fwrite(STDOUT,"COMPATIBLE: ".count(array_unique($updated))." archivos por modificar; ".count($skipped)." reglas ya instaladas. Base de datos comprobada.\n");
if($mode==='--check'){
  fwrite(STDOUT,"Sólo comprobación: se consultó el esquema; la base de datos y el stock no se modificaron.\n");
  exit(0);
}
$backups=[];
try{
  $sql=(string)file_get_contents($root.'/sql/01_avisos_y_entradas_oc.sql');
  foreach(preg_split('/;\s*(?:\R|\z)/',$sql) as $statement){
    if(trim($statement)!=='')$db->exec($statement);
  }
  // Aplicar sólo después de validar TODOS los archivos, con respaldo por archivo.
  $cambios=[];
  foreach($rules as $r){
    $path=$root.'/'.$r['file'];
    if(!is_file($path) && !empty($r['optional']))continue;
    $s=$cambios[$path]??(string)file_get_contents($path);
    if(str_contains($s,$r['new']))continue;
    if(substr_count($s,$r['old'])!==1)throw new RuntimeException('Cambió el archivo durante instalación: '.$r['file']);
    $cambios[$path]=str_replace($r['old'],$r['new'],$s);
  }
  $stamp=date('Ymd_His');
  foreach($cambios as $path=>$contenido){
    $bak=$path.'.respaldo_ocav_'.$stamp;
    if(!copy($path,$bak))throw new RuntimeException('No se pudo respaldar: '.$path);
    $backups[$path]=$bak;
    if(file_put_contents($path,$contenido,LOCK_EX)===false)throw new RuntimeException('No se pudo escribir: '.$path);
    $salida=[];$status=0;
    exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($path).' 2>&1',$salida,$status);
    if($status!==0)throw new RuntimeException('Error de sintaxis en '.$path.' → '.implode(' | ',$salida));
  }
  fwrite(STDOUT,"INSTALADO: ".count($cambios)." archivos modificados. Se crearon respaldos y tablas de avisos/vínculos.\n");
  fwrite(STDOUT,"Recarga el navegador con Ctrl+F5. No se modificó ninguna existencia.\n");
}catch(Throwable $e){
  foreach($backups as $target=>$bak){if(is_file($bak))copy($bak,$target);}
  fwrite(STDERR,"ABORTADO: ".$e->getMessage()."\nSe restauraron los archivos respaldados. Si se crearon tablas nuevas, permanecen vacías.\n");exit(4);
}
