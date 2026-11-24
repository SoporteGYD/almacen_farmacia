<?php
declare(strict_types=1);
/** G&D V10 - sobre V9.2 aplicada y retiro automático V6. Solo DB prueba. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit("Ejecuta este instalador desde PowerShell.\n");}
$mode=$argv[1]??'';
if(!in_array($mode,['--check','--apply'],true)){
    fwrite(STDERR,"Uso: php tools/instalar_pos_limite_retiro_corte_v10.php --check|--apply\n");exit(2);
}
$root=dirname(__DIR__);$payload=__DIR__.'/gyd_pos_v10_payload';
$patches=json_decode((string)@file_get_contents($payload.'/replacements.json'),true,512,JSON_THROW_ON_ERROR);
if(!is_array($patches)){fwrite(STDERR,"Error de payload.\n");exit(2);}
$errors=[];$changes=[];$already=0;
foreach($patches as $rel=>$operations){
    if(!preg_match('~^(?:app|public)/[A-Za-z0-9_/.-]+\.(?:php|js|css)$~',$rel) || str_contains($rel,'..')){$errors[]="Ruta de cambio inválida: {$rel}";continue;}
    $file=$root.'/'.$rel;
    if(!is_file($file)||!is_readable($file)){$errors[]="INCOMPATIBLE: no existe {$rel}.";continue;}
    $s=(string)file_get_contents($file);
    foreach($operations as $n=>$op){
        $old=(string)($op['old']??'');$new=(string)($op['new']??'');
        if($old===''||$new===''){$errors[]="Cambio inválido en {$rel} #{$n}.";continue;}
        // Comprobar el texto NUEVO antes que el viejo: algunos cambios conservan el marcador antiguo como prefijo.
        if(str_contains($s,$new)){$already++;continue;}
        $count=substr_count($s,$old);
        if($count!==1){$errors[]="INCOMPATIBLE {$rel}: marcador ".($n+1)." no único o cambiado ({$count} coincidencias): ".str_replace("\n",' ',substr($old,0,140));continue;}
        $s=str_replace($old,$new,$s);
    }
    if($s!==file_get_contents($file))$changes[$rel]=$s;
}
// No adaptar silenciosamente archivos de versiones anteriores: son operaciones de caja sensibles.
foreach([
 'app/models/PuntoVenta.php'=>['gyd_corte_autorizar(',"pos_turnos SET estado='CERRADO'",'gd_cash_totals('],
 'public/pos_retiros.php'=>['GYD_RETIRO_AUTO_V6','id="importe"'],
 'app/helpers/gyd_retiros_directos.php'=>['GYD_RETIRO_AUTO_V6','gd_guardar_retiro('],
 'app/helpers/gyd_cortes_autorizados.php'=>['gyd_corte_permitido(','gyd_cortes_intentos']
] as $rel=>$musts){
  $s=$changes[$rel]??(is_file($root.'/'.$rel)?file_get_contents($root.'/'.$rel):'');
  foreach($musts as $needle)if(!str_contains($s,$needle))$errors[]="INCOMPATIBLE {$rel}: falta dependencia {$needle}.";
}
if($errors){foreach($errors as $err)fwrite(STDERR,$err.PHP_EOL);fwrite(STDERR,"No se modificó ningún archivo. No ejecutes --apply.\n");exit(1);}
// Verificar sintaxis PHP de candidatos sin sobrescribir los originales.
foreach($changes as $rel=>$source){
    if(!str_ends_with($rel,'.php'))continue;
    $tmp=tempnam(sys_get_temp_dir(),'gyd_v10_');if(!$tmp)exit(2);
    try{
        file_put_contents($tmp,$source);
        $lines=[];$code=0;exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1',$lines,$code);
        if($code!==0){fwrite(STDERR,"Sintaxis PHP inválida {$rel}: ".implode(' ',$lines).PHP_EOL);exit(1);}
    }finally{@unlink($tmp);}
}
if($mode==='--check'){
    echo "COMPATIBLE: ".count($changes)." archivos para actualizar; {$already} marcadores ya instalados.\n";
    echo "PHP candidato: sintaxis válida. Requiere V9.2 y Retiro V6 instalados.\n";
    echo "El corte pedirá contraseña siempre, el cajero retiro con contraseña de su sesión y límite estricto de $3,000.00.\n";
    echo "No se modificó ningún archivo ni base de datos.\n";exit(0);
}
try {
    require_once $root.'/app/config/database.php';
    $db=(new Database())->connect();
    $currentDb=(string)$db->query('SELECT DATABASE()')->fetchColumn();
    if($currentDb!=='prueba')throw new RuntimeException('La base activa no es prueba: '.$currentDb.'. No se instalará.');
    foreach(['usuarios','pos_turnos','pos_ventas','pos_movimientos_caja','gyd_cortes_intentos','gyd_cortes_autorizaciones','gyd_retiros_boveda'] as $table){
      $q=$db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
      $q->execute([$table]);if(!(int)$q->fetchColumn())throw new RuntimeException('Falta tabla necesaria: '.$table);
    }
    if(!$changes){echo "V10 ya estaba instalado. Ningún archivo modificado.\n";exit(0);}
    $backup=$root.'/backups_gyd_v10_limite_caja_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
    if(!mkdir($backup,0775,true))throw new RuntimeException('No se pudo crear la carpeta de respaldo.');
    $hashes=[];
    foreach($changes as $rel=>$source){
       $hashes[$rel]=hash_file('sha256',$root.'/'.$rel);
       $dest=$backup.'/'.$rel;
       if(!is_dir(dirname($dest))&&!mkdir(dirname($dest),0775,true))throw new RuntimeException('No se pudo preparar carpeta de respaldo.');
       if(!copy($root.'/'.$rel,$dest))throw new RuntimeException('No se pudo respaldar: '.$rel);
    }
    foreach($hashes as $rel=>$hash)if(hash_file('sha256',$root.'/'.$rel)!==$hash)throw new RuntimeException('El archivo cambió durante la verificación: '.$rel);
    $ddl=(string)file_get_contents($payload.'/instalar_tablas.sql');
    $db->exec($ddl); // Sólo tabla de intentos de contraseña, no altera saldos.
    $written=[];
    try{
       foreach($changes as $rel=>$source){
          $dest=$root.'/'.$rel;$tmp=$dest.'.v10tmp'.bin2hex(random_bytes(3));
          if(file_put_contents($tmp,$source,LOCK_EX)!==strlen($source))throw new RuntimeException('No se pudo escribir: '.$rel);
          if(!rename($tmp,$dest)){@unlink($tmp);throw new RuntimeException('No se pudo reemplazar: '.$rel);}
          $written[]=$rel;
       }
    }catch(Throwable $e){
       foreach($written as $rel)@copy($backup.'/'.$rel,$root.'/'.$rel);
       throw $e;
    }
    echo "V10 INSTALADO CORRECTAMENTE.\n";
    echo "Archivos modificados: ".count($changes).". Respaldo: {$backup}\n";
    echo "No se modificaron movimientos, ventas ni cortes históricos.\n";
    echo "Actualiza el navegador con Ctrl+F5 y prueba en la base prueba.\n";
}catch(Throwable $e){fwrite(STDERR,'INSTALACIÓN DETENIDA: '.$e->getMessage().PHP_EOL);exit(1);}
