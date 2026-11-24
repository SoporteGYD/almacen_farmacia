<?php
declare(strict_types=1);
/** Instalador incremental: conciliación detallada de turnos G&D, solo laboratorio prueba:3307. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo terminal.');}
$mode=$argv[1]??'--check';
if(!in_array($mode,['--check','--check-files','--apply','--verify','--rollback'],true)) {fwrite(STDERR,"Usa --check | --check-files | --apply | --verify | --rollback\n");exit(2);}
$root=dirname(__DIR__);$here=__DIR__;
const FILES_NEW=[
    'app/helpers/gyd_pos_conciliacion_detalle.php',
    'public/pos_conciliacion_turno.php',
    'public/assets/css/gyd_conciliacion_turno.css',
];
const PATCHED=['public/pos.php','public/pos_corte_retiros.php'];
function gc_err(string $msg):never {throw new RuntimeException($msg);}
function gc_file(string $path):string {
    if(!is_file($path))gc_err('Falta archivo: '.$path);
    $v=file_get_contents($path);if($v===false)gc_err('Sin lectura de: '.$path);return $v;
}
function gc_patches():array {
    $posOld='<div class="headbuttons"><button id="abrir"';
    $posNew='<div class="headbuttons"><?php if(in_array(strtoupper((string)($u[\'rol_original\']??$u[\'rol\']??\'\')), [\'ADMINISTRADOR\',\'DIRECCION\'],true)):?><a href="pos_conciliacion_turno.php" onclick="this.href=\'pos_conciliacion_turno.php?caja_id=\'+encodeURIComponent(document.getElementById(\'caja\').value)" target="_blank" rel="noopener" title="Resumen detallado de conciliación" style="display:inline-flex;align-items:center;padding:8px 12px;border-radius:7px;text-decoration:none;background:#e6eff9;color:#174875;font-weight:700;font-size:13px">Resumen de turno</a><?php endif;?><button id="abrir"';
    $reportOld='<a href="pos.php">POS</a>';
    $reportNew='<a href="pos.php">POS</a><a href="pos_conciliacion_turno.php?turno_id=<?=(int)$turnoId?>">Conciliación detallada del turno</a>';
    return [
        'public/pos.php'=>['old'=>$posOld,'new'=>$posNew],
        'public/pos_corte_retiros.php'=>['old'=>$reportOld,'new'=>$reportNew]
    ];
}
function gc_plan(string $root,string $here):array {
    $plan=[];
    foreach(FILES_NEW as $rel){
        $file=$root.'/'.$rel;$new=gc_file($here.'/payload/'.$rel);
        $old=is_file($file)?gc_file($file):null;
        if($old!==null && $old!==$new)gc_err('Ya existe un archivo diferente: '.$rel.'. No se sobrescribió.');
        $plan[$rel]=['old'=>$old,'new'=>$new];
    }
    foreach(gc_patches() as $rel=>$patch){
        $old=gc_file($root.'/'.$rel);$new=$old;
        $a=substr_count($old,$patch['old']);$b=substr_count($old,$patch['new']);
        if($b===1){} // Instalación previa.
        elseif($a===1 && $b===0)$new=str_replace($patch['old'],$patch['new'],$old);
        else gc_err('El archivo '.$rel.' no coincide con el POS esperado. Encontrados antes='.$a.', aplicado='.$b.'. No se reemplazó.');
        $plan[$rel]=['old'=>$old,'new'=>$new];
    }
    // Confirmar presencia de la autorización y del módulo de denominaciones del corte anterior.
    foreach(['app/helpers/gyd_cortes_autorizados.php','app/helpers/pos_corte_denominaciones.php','public/gyd_cortes_caja.php'] as $rel){
        if(!is_file($root.'/'.$rel))gc_err('Primero instala la actualización de cortes autorizados: falta '.$rel);
    }
    return $plan;
}
function gc_db(string $root):void {
    require_once $root.'/app/config/database.php';
    $db=(new Database())->connect();$name=(string)$db->query('SELECT DATABASE()')->fetchColumn();$port=(int)$db->query('SELECT @@port')->fetchColumn();
    if($name!=='prueba'||$port!==3307)gc_err('Por seguridad solo se permite la base prueba:3307. Se detectó '.$name.':'.$port);
    $required=['pos_turnos','pos_cajas','pos_sucursales','usuarios','pos_ventas','pos_pagos','pos_movimientos_caja','pos_reversiones','gyd_retiros_boveda','pos_cortes_denominaciones','gyd_cortes_autorizaciones'];
    $sql="SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()";
    $existing=array_map('strtolower',$db->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    foreach($required as $table)if(!in_array(strtolower($table),$existing,true))gc_err('Falta tabla de cortes: '.$table);
}
function gc_safe_path(string $path,string $root):bool {
    return str_starts_with($path,$root.'/backups/gyd_conciliacion_') && is_dir($path);
}
try {
    if($mode==='--rollback'){
        $ptr=$here.'/.ultimo_respaldo_conciliacion.json';if(!is_file($ptr))gc_err('Sin respaldo de esta instalación.');
        $b=json_decode(gc_file($ptr),true,512,JSON_THROW_ON_ERROR);$folder=(string)($b['folder']??'');
        if(!gc_safe_path($folder,$root))gc_err('Ruta de respaldo inválida.');
        $allowed=array_merge(FILES_NEW,PATCHED);
        foreach($b['files'] as $rel=>$item){
            if(!in_array($rel,$allowed,true))gc_err('Ruta de archivo no autorizada.');
            if(!is_file($root.'/'.$rel)||hash_file('sha256',$root.'/'.$rel)!==$item['after'])gc_err('El archivo cambió desde la instalación; restauración manual requerida: '.$rel);
            if($item['before']!==null && hash_file('sha256',$folder.'/'.$rel)!==$item['before'])gc_err('Respaldo no coincide: '.$rel);
        }
        foreach($b['files'] as $rel=>$item){
            if($item['before']===null){if(!unlink($root.'/'.$rel))gc_err('No se pudo retirar '.$rel);}
            else {if(!copy($folder.'/'.$rel,$root.'/'.$rel))gc_err('No se pudo restaurar '.$rel);}
        }
        unlink($ptr);echo "RESTAURADO. La conciliación detallada anterior quedó como estaba.\n";exit(0);
    }
    $plan=gc_plan($root,$here);$pending=array_filter($plan,static fn($i)=>$i['old']!==$i['new']);
    if($mode==='--check-files'){echo 'ARCHIVOS COMPATIBLES. Pendientes: '.count($pending)."\n";exit(0);}
    gc_db($root);
    if($mode==='--check'){echo 'COMPATIBLE: prueba:3307; pendientes '.count($pending)." archivo(s). Sin cambios.\n";exit(0);}
    if($mode==='--verify'){
        if($pending)gc_err('Aún quedan '.count($pending).' archivos pendientes.');
        echo "VERIFICADO: reporte de conciliación, pagos separados, denominaciones y comprobante.\n";exit(0);
    }
    if(!$pending){echo "YA ESTÁ APLICADO. Sin cambios.\n";exit(0);}
    $folder=$root.'/backups/gyd_conciliacion_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
    $checks=[];
    foreach($pending as $rel=>$item){
        if($item['old']!==null){
            $dest=$folder.'/'.$rel;if(!is_dir(dirname($dest))&&!mkdir(dirname($dest),0775,true))gc_err('No se pudo crear respaldo.');
            if(file_put_contents($dest,$item['old'],LOCK_EX)===false)gc_err('Error al respaldar: '.$rel);
        }
        $checks[$rel]=['before'=>$item['old']===null?null:hash('sha256',$item['old']), 'after'=>hash('sha256',$item['new'])];
    }
    $changed=[];
    try{
        foreach($pending as $rel=>$item){
            $dest=$root.'/'.$rel;
            if(!is_dir(dirname($dest))&&!mkdir(dirname($dest),0775,true))gc_err('No se pudo crear directorio: '.$rel);
            $tmp=tempnam(dirname($dest),'.gcd_');
            if($tmp===false||file_put_contents($tmp,$item['new'],LOCK_EX)===false)gc_err('Error preparando '.$rel);
            if(!rename($tmp,$dest))gc_err('Error escribiendo '.$rel);
            $changed[]=$rel;
            if(hash_file('sha256',$dest)!==$checks[$rel]['after'])gc_err('Hash no coincide: '.$rel);
        }
        $ptr=$here.'/.ultimo_respaldo_conciliacion.json';
        if(file_put_contents($ptr,json_encode(['folder'=>$folder,'files'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX)===false)gc_err('No se pudo escribir referencia de respaldo.');
    } catch(Throwable $ex){
        foreach(array_reverse($changed) as $rel){$orig=$pending[$rel]['old'];if($orig===null)@unlink($root.'/'.$rel);else @copy($folder.'/'.$rel,$root.'/'.$rel);}
        throw $ex;
    }
    echo 'APLICADO. Archivos instalados/actualizados: '.count($changed)."\nRespaldo: ".$folder."\nEjecuta --verify y actualiza el POS con Ctrl+F5.\n";
} catch(Throwable $e){fwrite(STDERR,'ERROR: '.$e->getMessage()."\n");exit(1);}
