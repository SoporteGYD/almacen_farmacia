<?php
declare(strict_types=1);
/** Instalador no destructivo del Centro de Alertas Gerenciales; solo base prueba. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit("Solo CLI.\n");}
$mode=$argv[1]??'--check';if(!in_array($mode,['--check','--apply'],true)){fwrite(STDERR,"Uso: php tools/instalar_alertas_gerenciales_v15.php --check|--apply\n");exit(2);}
$root=dirname(__DIR__);$payload=__DIR__.'/gyd_alertas_payload';
$destinations=[
 'app/helpers/gyd_alertas_gerenciales.php',
 'public/erp_alertas_gerenciales.php',
 'public/erp_alertas_gerenciales_api.php',
 'public/assets/gyd_alertas_gerenciales.css',
 'public/assets/gyd_alertas_gerenciales.js',
];
$headerRel='app/views/layouts/header.php';$headerPath=$root.'/'.$headerRel;
$anchor='$menuAgregar(\'🛍️ Ventas y gerencia\', \'Control de Cajas y Arqueos\', \'erp_control_cajas.php\', $menuEsAdmin);';
$line='$menuAgregar(\'🛍️ Ventas y gerencia\', \'Centro de Alertas Gerenciales\', \'erp_alertas_gerenciales.php\', $menuEsAdmin || $esGerente);';
$issues=[];$newFiles=[];$kept=[];
foreach($destinations as $rel){
 $src=$payload.'/'.$rel;$dst=$root.'/'.$rel;
 if(!is_file($src))$issues[]='Falta archivo del paquete: '.$rel;
 elseif(is_file($dst)){
  if(hash_file('sha256',$dst)!==hash_file('sha256',$src))$issues[]='El archivo ya existe y es diferente; no se sobrescribirá: '.$rel;
  else $kept[]=$rel;
 }elseif(file_exists($dst))$issues[]='Hay una ruta no válida: '.$rel;
 else $newFiles[]=$rel;
}
$headerNew=null;
if(!is_file($headerPath))$issues[]='No existe '.$headerRel;
else {
 $current=file_get_contents($headerPath);$hits=substr_count($current,'erp_alertas_gerenciales.php');
 if($hits>0){echo "Menú de alertas ya presente: no se duplicará.\n";}
 elseif(substr_count($current,$anchor)===1){$headerNew=str_replace($anchor,$anchor."\n".$line,$current);}
 else {echo "AVISO: no se encontró el anclaje exacto del menú. La página quedará disponible por URL y el menú no se modificará.\n";}
}
if($issues){foreach($issues as $m)fwrite(STDERR,"INCOMPATIBLE: $m\n");exit(1);}
echo "COMPATIBLE\n";
echo 'Archivos nuevos: '.count($newFiles).', ya presentes e idénticos: '.count($kept).', añadir menú: '.($headerNew!==null?'sí':'no')."\n";
if($mode==='--check'){echo "Sin cambios. Para instalar usa --apply.\n";exit(0);}
// Antes de escribir nada: validar que la conexión sea únicamente a la base de prueba.
try{
 require_once $root.'/app/config/database.php';
 $db=(new Database())->connect();
 $actual=(string)$db->query('SELECT DATABASE()')->fetchColumn();
 if($actual!=='prueba')throw new RuntimeException('La base activa no es prueba. Se canceló toda la instalación.');
 foreach(['pos_sucursales','pos_cajas','pos_turnos','usuarios'] as $table){
  $s=$db->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');$s->execute([$table]);
  if(!$s->fetchColumn())throw new RuntimeException('Falta tabla del POS: '.$table);
 }
 // Tabla nueva: único DDL. Las alertas se generan desde consultas SELECT.
 $sql=file_get_contents($payload.'/instalar_tablas.sql');
 if(!is_string($sql)||trim($sql)==='')throw new RuntimeException('Falta SQL de instalación.');
 $db->exec($sql);
 $backups=$root.'/tools/backups_alertas_gerenciales';
 if($headerNew!==null){if(!is_dir($backups)&&!mkdir($backups,0750,true)&&!is_dir($backups))throw new RuntimeException('No se pudo preparar carpeta de respaldos.');
  $backup=$backups.'/header_'.date('Ymd_His').'_'.bin2hex(random_bytes(3)).'.php';
  if(!copy($headerPath,$backup))throw new RuntimeException('No se pudo respaldar el menú.');
  echo "Respaldo del menú: $backup\n";
 }
 $created=[];
 foreach($newFiles as $rel){$src=$payload.'/'.$rel;$dst=$root.'/'.$rel;$dir=dirname($dst);
  if(!is_dir($dir)&&!mkdir($dir,0755,true)&&!is_dir($dir))throw new RuntimeException('No se pudo crear carpeta para '.$rel);
  if(!copy($src,$dst)){foreach($created as $f)@unlink($f);throw new RuntimeException('No se pudo copiar '.$rel);}
  $created[]=$dst;
 }
 if($headerNew!==null){
  $tmp=$headerPath.'.alertas.tmp';
  if(file_put_contents($tmp,$headerNew,LOCK_EX)===false||!rename($tmp,$headerPath)){
   @unlink($tmp);foreach($created as $f)@unlink($f);throw new RuntimeException('No se pudo integrar enlace en el menú.');
  }
 }
 echo "INSTALADO. SQL de atención verificado. Archivos nuevos: ".count($created).".\n";
 echo "Abrir: http://localhost/almacen-farmacia2/public/erp_alertas_gerenciales.php\n";
}catch(Throwable $e){fwrite(STDERR,'ERROR: '.$e->getMessage()."\n");exit(1);}
