<?php
declare(strict_types=1);
/** ERP G&D · Instalador incremental / sin sobreescritura ciega. PHP CLI. */
if (PHP_SAPI !== 'cli') {http_response_code(403);exit('Solo terminal.');}
$modo = $argv[1] ?? '--check';
if (!in_array($modo,['--check','--apply'],true)) {fwrite(STDERR,"Uso: php tools/instalar_corte_caja_denominaciones.php --check|--apply\n");exit(2);}
$base=dirname(__DIR__);
$payload=__DIR__.DIRECTORY_SEPARATOR.'corte_payload';
$manifest=json_decode((string)file_get_contents($payload.'/patches.json'),true,512,JSON_THROW_ON_ERROR);
if (!is_array($manifest)) throw new RuntimeException('Manifiesto inválido.');
function ruta_corte(string $base,string $rel):string {return $base.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$rel);}
function repl_corte(string $code,array $ops,string $rel):array {
    $cambiado=false;
    foreach($ops as $op){
        $old=(string)$op['old'];$new=(string)$op['new'];
        if (str_contains($code,$new)) continue;
        $n=substr_count($code,$old);
        if($n!==1)throw new RuntimeException("INCOMPATIBLE: {$rel}. Marcador esperado {$n} veces. No se sobrescribirá.");
        // Sustitución literal: el marcador ya se comprobó exactamente una vez.
        // preg_replace() no admite closures como argumento de reemplazo.
        $code=str_replace($old,$new,$code);
        $cambiado=true;
    }
    return [$code,$cambiado];
}
$plans=[];$nuevos=[];
try {
    foreach($manifest as $rel=>$ops) {
        $target=ruta_corte($base,$rel);
        if(!is_file($target))throw new RuntimeException('INCOMPATIBLE: falta '.$rel);
        $orig=file_get_contents($target);
        if($orig===false)throw new RuntimeException('No puedo leer '.$rel);
        [$modified,$changed]=repl_corte($orig,$ops,$rel);
        if($changed) $plans[$rel]=['old'=>$orig,'new'=>$modified];
    }
    $archivos=[
        'app/helpers/pos_corte_denominaciones.php',
        'public/assets/js/pos_corte_denominaciones.js',
        'public/assets/css/pos_corte_denominaciones.css',
        'public/erp_caja_desglose_csv.php',
        'tests/probar_corte_denominaciones.php',
        'sql/pos_cortes_denominaciones.sql'
    ];
    foreach ($archivos as $rel) {
        $source=ruta_corte($payload,$rel);$target=ruta_corte($base,$rel);
        if(!is_file($source))throw new RuntimeException('ZIP incompleto: '.$rel);
        if(is_file($target)) {
            if(hash_file('sha256',$target)!==hash_file('sha256',$source))throw new RuntimeException('INCOMPATIBLE: '.$rel.' ya existe modificado; no se sobrescribirá.');
        } else $nuevos[$rel]=$source;
    }
    // Validamos la BD objetivo para no mezclar pruebas y operación real.
    require_once $base.'/app/config/database.php';
    $db=(new Database())->connect();
    $actual=(string)$db->query('SELECT DATABASE()')->fetchColumn();
    if($actual!=='prueba')throw new RuntimeException('INCOMPATIBLE: la base conectada es '.($actual?:'[sin seleccionar]').'. Solo se admite prueba.');
    foreach(['pos_turnos','pos_cajas','pos_pagos','pos_ventas','pos_movimientos_caja','erp_caja_arqueos','pos_auditoria'] as $tbl){
        $st=$db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$st->execute([$tbl]);
        if((int)$st->fetchColumn()!==1)throw new RuntimeException('INCOMPATIBLE: falta tabla POS '.$tbl);
    }
    $tabla='pos_cortes_denominaciones';
    $stmt=$db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$stmt->execute([$tabla]);
    if((int)$stmt->fetchColumn()>0){
        $col=$db->query("SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pos_cortes_denominaciones'")->fetchAll(PDO::FETCH_COLUMN);
        foreach(['turno_id','usuario_id','desglose_json','total_contado','creado_en'] as $needed)if(!in_array($needed,$col,true))throw new RuntimeException('INCOMPATIBLE: la tabla de cortes existente tiene otra estructura.');
    }
    echo "G&D | Conteo por denominaciones en Cierre POS\n";
    echo "Base conectada: {$actual}\n";
    echo "Archivos a actualizar: ".count($plans)." · Archivos nuevos: ".count($nuevos)."\n";
    if ($modo==='--check') {echo "COMPATIBLE · Comprobación únicamente, sin cambios.\n";exit(0);}
    $backup=ruta_corte($base,'backups/instaladores/corte_caja_'.date('Ymd_His').'_'.bin2hex(random_bytes(3)));
    if(!is_dir($backup)&&!mkdir($backup,0775,true)&&!is_dir($backup))throw new RuntimeException('No pude crear carpeta de respaldos.');
    foreach($plans as $rel=>$dato){
        $dest=ruta_corte($backup,$rel);if(!is_dir(dirname($dest))&&!mkdir(dirname($dest),0775,true)&&!is_dir(dirname($dest)))throw new RuntimeException('No pude crear subcarpeta de respaldo.');
        if(file_put_contents($dest,$dato['old'],LOCK_EX)===false)throw new RuntimeException('No pude crear respaldo de '.$rel);
    }
    // La tabla se crea antes de activar el código. No se modifica la estructura del POS actual.
    $sql=(string)file_get_contents($payload.'/sql/pos_cortes_denominaciones.sql');
    $sql=preg_replace('/^\s*--[^\r\n]*/m','',$sql);
    $db->exec(trim($sql));
    $escritos=[];
    try{
      foreach($nuevos as $rel=>$source){
        $to=ruta_corte($base,$rel);if(!is_dir(dirname($to))&&!mkdir(dirname($to),0775,true)&&!is_dir(dirname($to)))throw new RuntimeException('No pude crear carpeta para '.$rel);
        $escritos[]=['new',$rel];
        if(!copy($source,$to))throw new RuntimeException('No pude copiar '.$rel);
      }
      foreach($plans as $rel=>$dato){
        $to=ruta_corte($base,$rel);
        $escritos[]=['mod',$rel];
        if(file_put_contents($to,$dato['new'],LOCK_EX)===false)throw new RuntimeException('No pude actualizar '.$rel);
      }
    }catch(Throwable $e){
      foreach(array_reverse($escritos) as [$kind,$rel]){
        $target=ruta_corte($base,$rel);
        if($kind==='new')@unlink($target);
        elseif(isset($plans[$rel]))@file_put_contents($target,$plans[$rel]['old']);
      }
      throw $e;
    }
    echo "INSTALADO · Respaldo de archivos: {$backup}\n";
    echo "Ejecutar pruebas: php tests/probar_corte_denominaciones.php\n";
    echo "El cierre ahora requiere contar billetes y monedas en el POS.\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
