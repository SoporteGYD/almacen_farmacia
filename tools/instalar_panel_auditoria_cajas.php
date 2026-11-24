<?php
declare(strict_types=1);
/** Actualización G&D: panel administrativo de cajas (solo lectura). */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo terminal.');}
$modo=$argv[1]??'--check';
if(!in_array($modo,['--check','--apply','--verify','--rollback','--check-files'],true)){
    fwrite(STDERR,"Usa --check | --apply | --verify | --rollback | --check-files\n");exit(2);
}
$root=dirname(__DIR__);$here=__DIR__;
const NEWFILES=[
    'app/helpers/gyd_panel_cajas.php',
    'public/gyd_panel_cajas.php',
    'public/assets/css/gyd_panel_cajas.css',
];
const POSFILE='public/pos.php';
const LINK_MARKER='<!-- GYD_PANEL_ADMIN_CAJAS -->';
const LINK_HTML=LINK_MARKER.'<?php if(in_array(strtoupper(trim((string)($u[\'rol_original\']??$u[\'rol\']??\'\'))),[\'ADMINISTRADOR\',\'DIRECCION\'],true)):?><a class="nav-pos-btn nav-pos-warehouse" href="gyd_panel_cajas.php">Control de cajas</a><?php endif;?>';
const POS_ANCHOR='<a class="nav-pos-btn nav-pos-exit" href="logout.php">';
function gpa_error(string $msg):never{throw new RuntimeException($msg);}
function gpa_read(string $path):string{if(!is_file($path))gpa_error('Falta: '.$path);$v=file_get_contents($path);if($v===false)gpa_error('No se puede leer: '.$path);return $v;}
function gpa_plan(string $root,string $here):array{
    if(!is_file($root.'/app/helpers/gyd_pos_conciliacion_detalle.php') || !is_file($root.'/public/pos_conciliacion_turno.php'))
        gpa_error('Primero instala Conciliación y su corrección de importes.');
    $plan=[];
    foreach(NEWFILES as $rel){$new=gpa_read($here.'/payload/'.$rel);$prev=is_file($root.'/'.$rel)?gpa_read($root.'/'.$rel):null;
        if($prev!==null&&$prev!==$new)gpa_error('No se sobrescribirá el archivo personalizado '.$rel);
        $plan[$rel]=['anterior'=>$prev,'nuevo'=>$new];
    }
    $original=gpa_read($root.'/'.POSFILE);
    if(str_contains($original,LINK_MARKER)){
        if(substr_count($original,LINK_MARKER)!==1 || !str_contains($original,LINK_HTML))gpa_error('El enlace de cajas ya está modificado. No se sobrescribirá.');
        $nuevo=$original;
    }else{
        if(substr_count($original,POS_ANCHOR)!==1)gpa_error('El encabezado del POS es distinto al esperado: no se modificará.');
        $nuevo=str_replace(POS_ANCHOR,LINK_HTML.POS_ANCHOR,$original);
    }
    $plan[POSFILE]=['anterior'=>$original,'nuevo'=>$nuevo];
    return $plan;
}
function gpa_db(string $root):void{
    require_once $root.'/app/config/database.php';$db=(new Database())->connect();
    $nombre=(string)$db->query('SELECT DATABASE()')->fetchColumn();$port=(int)$db->query('SELECT @@port')->fetchColumn();
    if($nombre!=='prueba'||$port!==3307)gpa_error('Seguridad: se requiere la base prueba en puerto 3307. Actual: '.$nombre.':'.$port);
    $need=['pos_sucursales','pos_cajas','pos_turnos','pos_pagos','pos_ventas','pos_movimientos_caja','pos_reversiones','gyd_retiros_boveda','gyd_cortes_autorizaciones','pos_cortes_denominaciones','usuarios'];
    $got=array_map('strtolower',$db->query('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchAll(PDO::FETCH_COLUMN));
    foreach($need as $n)if(!in_array($n,$got,true))gpa_error('No está instalada la tabla necesaria: '.$n);
}
function gpa_atomic(string $path,string $contents):void{
    $folder=dirname($path);if(!is_dir($folder)&&!mkdir($folder,0775,true)&&!is_dir($folder))gpa_error('No se pudo crear '.$folder);
    $tmp=tempnam($folder,'.gyd-');if($tmp===false)gpa_error('No se pudo crear temporal.');
    try{if(file_put_contents($tmp,$contents,LOCK_EX)===false)gpa_error('No se pudo escribir temporal.');
        if(is_file($path)){@chmod($tmp,fileperms($path)&0777);}
        if(!rename($tmp,$path))gpa_error('No se pudo sustituir '.$path);
    }finally{if(is_file($tmp))@unlink($tmp);}
}
function gpa_backup(string $root,array $plan,string $here):string{
    $name='backups_panel_auditoria_cajas_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
    $dir=$root.'/tools/'.$name;if(!mkdir($dir,0770,true))gpa_error('No se pudo crear respaldo.');
    $manifest=[];
    foreach($plan as $rel=>$p){$was=$p['anterior'];$manifest[$rel]=['existia'=>$was!==null,'hash_nuevo'=>hash('sha256',$p['nuevo'])];
        if($was!==null){$target=$dir.'/'.$rel;@mkdir(dirname($target),0770,true);if(file_put_contents($target,$was)===false)gpa_error('Error guardando respaldo de '.$rel);}}
    if(file_put_contents($dir.'/manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))===false)gpa_error('No se pudo guardar manifest.');
    if(file_put_contents($here.'/.ultimo_respaldo_panel_cajas.txt',$dir)===false)gpa_error('No se pudo registrar respaldo.');
    return $dir;
}
function gpa_rollback(string $root,string $here):void{
    $ptr=$here.'/.ultimo_respaldo_panel_cajas.txt';$dir=trim(gpa_read($ptr));
    $prefix=realpath($root.'/tools');$where=realpath($dir);
    if(!$prefix||!$where||!str_starts_with($where,$prefix.'/backups_panel_auditoria_cajas_'))gpa_error('Ruta de respaldo inválida.');
    $manifest=json_decode(gpa_read($where.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
    foreach($manifest as $rel=>$row){$path=$root.'/'.$rel;
        if(!is_file($path)||hash_file('sha256',$path)!==$row['hash_nuevo'])gpa_error('Archivo modificado después de instalar: '.$rel.'. Restauración detenida.');}
    foreach($manifest as $rel=>$row){$path=$root.'/'.$rel;
        if($row['existia'])gpa_atomic($path,gpa_read($where.'/'.$rel));
        else if(!unlink($path))gpa_error('No se pudo retirar '.$rel);}
    @unlink($ptr);echo "Restaurado desde: $where\n";
}
try{
    if($modo==='--rollback'){gpa_rollback($root,$here);exit(0);}
    if($modo!=='--check-files')gpa_db($root);
    $plan=gpa_plan($root,$here);
    if($modo==='--check'||$modo==='--check-files'){
        echo "COMPATIBLE: panel de cajas · prueba:3307 (si se validó DB)\n";
        foreach($plan as $rel=>$p)echo ($p['anterior']===$p['nuevo']?'[SIN CAMBIOS] ':'[PREPARADO] ').$rel."\n";
        exit(0);
    }
    if($modo==='--verify'){
        foreach($plan as $rel=>$p)if($p['anterior']!==$p['nuevo'])gpa_error('Pendiente de aplicar: '.$rel);
        echo "VERIFICADO: interfaz y acceso del POS instalados. Sin alterar datos.\n";exit(0);
    }
    $respaldo=gpa_backup($root,$plan,$here);
    foreach($plan as $rel=>$p)if($p['anterior']!==$p['nuevo'])gpa_atomic($root.'/'.$rel,$p['nuevo']);
    echo "ACTUALIZACIÓN APLICADA. Respaldo: $respaldo\nEjecuta --verify e inicia Apache.\n";
}catch(Throwable $e){fwrite(STDERR,'ERROR: '.$e->getMessage()."\n");exit(1);}
