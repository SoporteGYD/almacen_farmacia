<?php
declare(strict_types=1);
/** Instalador incremental y reversible de reportes G&D, sin DDL ni DML. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Acceso solo terminal.');}
$modo=$argv[1]??'--check';
if(!in_array($modo,['--check','--apply','--verify','--rollback','--check-files'],true)){
    fwrite(STDERR,"Usa --check | --apply | --verify | --rollback | --check-files\n");exit(2);
}
$root=dirname(__DIR__);$here=__DIR__;
const GR_NEW=[
    'app/helpers/gyd_reportes_gerenciales.php',
    'public/gyd_reportes_gerenciales.php',
    'public/assets/css/gyd_reportes_gerenciales.css',
];
const GR_PANEL='public/gyd_panel_cajas.php';
const GR_ANCHOR='<a href="pos.php">Punto de venta</a>';
const GR_MARKER='<!-- GYD_ENLACE_REPORTES_GERENCIA -->';
const GR_LINK=GR_MARKER.'<a href="gyd_reportes_gerenciales.php">Reportes gerenciales</a>';
function gr_inst_error(string $msg):never{throw new RuntimeException($msg);}
function gr_inst_read(string $path):string{
    if(!is_file($path))gr_inst_error('Archivo requerido no encontrado: '.$path);
    $v=file_get_contents($path);if($v===false)gr_inst_error('No se puede leer: '.$path);return $v;
}
function gr_inst_plan(string $root,string $here):array{
    if(!is_file($root.'/app/helpers/gyd_panel_cajas.php')||!is_file($root.'/public/pos_conciliacion_turno.php'))
        gr_inst_error('Primero instala el Panel de Control y Auditoría y la Conciliación por Turno.');
    $plan=[];
    foreach(GR_NEW as $rel){
        $actual=is_file($root.'/'.$rel)?gr_inst_read($root.'/'.$rel):null;
        $nuevo=gr_inst_read($here.'/payload/'.$rel);
        if($actual!==null&&$actual!==$nuevo)gr_inst_error('Existe una versión personalizada de '.$rel.'. No se sobrescribirá.');
        $plan[$rel]=['previo'=>$actual,'nuevo'=>$nuevo];
    }
    $original=gr_inst_read($root.'/'.GR_PANEL);
    if(str_contains($original,GR_MARKER)){
        if(substr_count($original,GR_MARKER)!==1||!str_contains($original,GR_LINK))
            gr_inst_error('El acceso a reportes está modificado. No se sobrescribirá.');
        $nuevo=$original;
    }else{
        if(substr_count($original,GR_ANCHOR)!==1)
            gr_inst_error('No se encontró el punto exacto del menú de Control de Cajas.');
        $nuevo=str_replace(GR_ANCHOR,GR_ANCHOR.GR_LINK,$original);
    }
    $plan[GR_PANEL]=['previo'=>$original,'nuevo'=>$nuevo];
    return $plan;
}
function gr_inst_db(string $root):void{
    require_once $root.'/app/config/database.php';
    $db=(new Database())->connect();
    $nombre=(string)$db->query('SELECT DATABASE()')->fetchColumn();
    $puerto=(int)$db->query('SELECT @@port')->fetchColumn();
    if($nombre!=='prueba'||$puerto!==3307)gr_inst_error('Seguridad: se requiere prueba:3307. Recibida '.$nombre.':'.$puerto);
    $necesarias=['pos_sucursales','pos_cajas','pos_turnos','pos_ventas','pos_pagos',
      'pos_movimientos_caja','pos_reversiones','gyd_retiros_boveda',
      'gyd_cortes_autorizaciones','pos_cortes_denominaciones','usuarios'];
    $tablas=array_map('strtolower',$db->query('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchAll(PDO::FETCH_COLUMN));
    foreach($necesarias as $tabla)if(!in_array($tabla,$tablas,true))gr_inst_error('Falta la tabla '.$tabla);
}
function gr_inst_atomic(string $path,string $contents):void{
    $dir=dirname($path);
    if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))gr_inst_error('No se pudo crear '.$dir);
    $tmp=tempnam($dir,'.gyd-');if($tmp===false)gr_inst_error('No se pudo generar archivo temporal.');
    try{
        if(file_put_contents($tmp,$contents,LOCK_EX)===false)gr_inst_error('Error al escribir el archivo temporal.');
        if(is_file($path))@chmod($tmp,fileperms($path)&0777);
        if(!rename($tmp,$path))gr_inst_error('No se pudo actualizar '.$path);
    }finally{if(is_file($tmp))@unlink($tmp);}
}
function gr_inst_backup(string $root,string $here,array $plan):string{
    $folder=$root.'/tools/backups_reportes_gerenciales_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
    if(!mkdir($folder,0770,true))gr_inst_error('No se pudo crear respaldo.');
    $manifest=[];
    foreach($plan as $rel=>$p){
        $manifest[$rel]=['existia'=>$p['previo']!==null,'sha256'=>hash('sha256',$p['nuevo'])];
        if($p['previo']!==null){
            $target=$folder.'/'.$rel;
            if(!is_dir(dirname($target))&&!mkdir(dirname($target),0770,true)&&!is_dir(dirname($target)))gr_inst_error('No se pudo crear carpeta de respaldo.');
            if(file_put_contents($target,$p['previo'])===false)gr_inst_error('No se pudo respaldar '.$rel);
        }
    }
    gr_inst_atomic($folder.'/manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    gr_inst_atomic($here.'/.ultimo_respaldo_reportes.txt',$folder);
    return $folder;
}
function gr_inst_rollback(string $root,string $here):void{
    $ptr=$here.'/.ultimo_respaldo_reportes.txt';
    $folder=trim(gr_inst_read($ptr));$real=realpath($folder);$tools=realpath($root.'/tools');
    if(!$real||!$tools||!str_starts_with($real,$tools.'/backups_reportes_gerenciales_'))gr_inst_error('Respaldo no válido.');
    $manifest=json_decode(gr_inst_read($real.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
    $permitidas=array_merge(GR_NEW,[GR_PANEL]);
    foreach($manifest as $rel=>$x){
        if(!in_array($rel,$permitidas,true))gr_inst_error('Elemento de respaldo no permitido.');
        $path=$root.'/'.$rel;
        if(!is_file($path)||hash_file('sha256',$path)!==$x['sha256'])gr_inst_error('Archivo modificado tras instalar: '.$rel.'. No se restaurará automáticamente.');
    }
    foreach($manifest as $rel=>$x){
        $path=$root.'/'.$rel;
        if($x['existia'])gr_inst_atomic($path,gr_inst_read($real.'/'.$rel));
        else if(!unlink($path))gr_inst_error('No se pudo retirar '.$rel);
    }
    @unlink($ptr);echo "Restauración terminada.\n";
}
try{
    if($modo==='--rollback'){gr_inst_rollback($root,$here);exit;}
    if($modo!=='--check-files')gr_inst_db($root);
    $plan=gr_inst_plan($root,$here);
    if($modo==='--check'||$modo==='--check-files'){
        echo "COMPATIBLE: Reportes gerenciales (solo lectura)\n";
        foreach($plan as $rel=>$p)echo ($p['previo']===$p['nuevo']?'[SIN CAMBIOS] ':'[LISTO] ').$rel."\n";
        exit;
    }
    if($modo==='--verify'){
        foreach($plan as $rel=>$p)if($p['previo']!==$p['nuevo'])gr_inst_error('No está instalado: '.$rel);
        echo "VERIFICADO: Reportes y acceso instalados; base intacta.\n";exit;
    }
    if(!array_filter($plan,static fn($p)=>$p['previo']!==$p['nuevo'])){echo "YA INSTALADO: no se realizó ningún cambio.\n";exit;}
    $backup=gr_inst_backup($root,$here,$plan);
    foreach($plan as $rel=>$p)if($p['previo']!==$p['nuevo'])gr_inst_atomic($root.'/'.$rel,$p['nuevo']);
    echo "INSTALADO. Respaldo: ".$backup."\nEjecuta --verify.\n";
}catch(Throwable $e){fwrite(STDERR,'ERROR: '.$e->getMessage()."\n");exit(1);}
