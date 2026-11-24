<?php
/** Instalador G&D: Centro de Alertas de Inventario; no escribe datos SQL. */
declare(strict_types=1);
$root=dirname(__DIR__);
$source=__DIR__.'/payload';
$helper=$root.'/app/helpers/gyd_alertas_stock.php';
$page=$root.'/public/erp_alertas_inventario.php';
$kardex=$root.'/public/erp_kardex_general.php';
$dbfile=$root.'/app/config/database.php';
$backup=$root.'/tools/respaldos_centro_alertas_inventario';
$manifest=$backup.'/manifest.json';
$op=$argv[1]??'--check';
$tag='GYD_LINK_CENTRO_ALERTAS_INV_2026';
function install_read(string $path):string{
    $r=@file_get_contents($path);
    if($r===false)throw new RuntimeException('Falta archivo: '.$path);
    return $r;
}
function install_patch_kardex(string $code):string{
    if(str_contains($code,'GYD_LINK_CENTRO_ALERTAS_INV_2026'))return $code;
    // Inserción de enlace dentro de los controles nativos del Kardex, no navbar flotante.
    $link='<?php /* GYD_LINK_CENTRO_ALERTAS_INV_2026 */ ?><a class="btn light" href="erp_alertas_inventario.php">Alertas de inventario</a>';
    if(substr_count($code,'<div class="links">')>=1){
        return preg_replace('~<div class="links">~','<div class="links">'.$link,$code,1);
    }
    throw new RuntimeException('No se encontró área de navegación nativa en Kardex; se detuvo para evitar duplicar controles.');
}
function install_connect(string $dbfile):PDO {
    require_once $dbfile;
    $db=(new Database())->connect();
    $row=$db->query('SELECT DATABASE() AS db,@@port AS port')->fetch(PDO::FETCH_ASSOC);
    if(($row['db']??'')!=='prueba'||(int)($row['port']??0)!==3307)throw new RuntimeException('Instalación permitida sólo en prueba:3307.');
    foreach(['productos','pos_existencias_farmacia','erp_pos_farmacia_control','producto_existencias','pos_sucursales'] as $table){
        $st=$db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $st->execute([$table]);
        if((int)$st->fetchColumn()!==1)throw new RuntimeException('Falta tabla requerida: '.$table);
    }
    $ids=$db->query('SELECT id FROM pos_sucursales WHERE id IN (1,2,3,4)')->fetchAll(PDO::FETCH_COLUMN);
    foreach([1,2,3,4] as $sid)if(!in_array($sid,array_map('intval',$ids),true))throw new RuntimeException('No se encontró sucursal POS '.$sid);
    return $db;
}
function install_backup(string $target,string $backup):void{
    if(!is_file($target))return;
    $dir=dirname($backup);if(!is_dir($dir)&&!mkdir($dir,0770,true))throw new RuntimeException('No pudo crear respaldo.');
    if(!is_file($backup)&&!copy($target,$backup))throw new RuntimeException('No pudo respaldar '.$target);
}
function install_write(string $file,string $content):void{
    if(file_put_contents($file,$content,LOCK_EX)===false)throw new RuntimeException('No se pudo escribir '.$file);
}
try{
    if(!in_array($op,['--check','--apply','--verify','--rollback'],true))throw new RuntimeException('Opciones: --check, --apply, --verify, --rollback');
    $db=install_connect($dbfile);
    $helperContent=install_read($source.'/gyd_alertas_stock.php');
    $pageContent=install_read($source.'/erp_alertas_inventario.php');
    $oldK=install_read($kardex);
    // Omitir anclas flotantes, no alterar el perfil de navegación existente.
    $newK=$op==='--rollback'?$oldK:install_patch_kardex($oldK);
    foreach([[$helper,'G&D - Análisis de inventarios'],[$page,'Centro de Alertas de Inventario, sólo lectura']] as [$target,$marker]){
        if(is_file($target) && !str_contains(install_read($target),$marker))throw new RuntimeException('Existe un archivo personalizado que no debe sobrescribirse: '.basename($target));
    }
    if($op==='--check'){
        echo "COMPATIBLE: prueba:3307, cuatro sucursales, fuentes de stock disponibles; sin alterar datos.\n";
        echo "El escaneo distinguirá registros ausentes de existencia cero y no cambiará inventarios.\n";exit(0);
    }
    if($op==='--verify'){
        if(!is_file($helper)||!is_file($page)||!str_contains(install_read($kardex),$tag))throw new RuntimeException('No se detectaron todos los componentes del centro de alertas.');
        echo "VERIFICADO: módulo, acceso desde Kardex y lectura exclusivamente en prueba:3307.\n";exit(0);
    }
    if($op==='--rollback'){
        if(!is_file($manifest))throw new RuntimeException('No existe manifiesto de instalación para restaurar.');
        $m=json_decode(install_read($manifest),true,512,JSON_THROW_ON_ERROR);
        $skipped=[];$done=[];
        foreach(['helper'=>$helper,'page'=>$page,'kardex'=>$kardex] as $key=>$file){
            $entry=$m[$key]??null;
            if(!$entry)continue;
            if(!is_file($file)||hash_file('sha256',$file)!==($entry['sha_instalado']??'')){$skipped[]=$key;continue;}
            if(!empty($entry['creado'])){
                unlink($file);$done[]=$key;
            }else{
                $bak=$backup.'/'.$key.'.bak';if(is_file($bak)&&copy($bak,$file))$done[]=$key;
            }
        }
        if(!$skipped)@unlink($manifest);
        echo 'RESTAURADO: '.implode(', ',$done)."\n";
        if($skipped)echo 'Con cambios posteriores, no restaurados: '.implode(', ',$skipped)."\n";
        exit(0);
    }
    // --apply: no sobrescribir el manifiesto original en una segunda ejecución.
    if(is_file($manifest)){
        $mOld=json_decode(install_read($manifest),true,512,JSON_THROW_ON_ERROR);
        $installed=['helper'=>$helper,'page'=>$page,'kardex'=>$kardex];
        foreach($installed as $key=>$f){
            if(!is_file($f) || hash_file('sha256',$f)!==($mOld[$key]['sha_instalado']??''))
                throw new RuntimeException('La instalación anterior cambió; no se reescriben archivos ni respaldos. Revisa los cambios antes de reinstalar.');
        }
        echo "YA INSTALADO: se mantienen intactos archivos y respaldos.\n";exit(0);
    }
    if(!is_dir($backup)&&!mkdir($backup,0770,true))throw new RuntimeException('No se pudo crear carpeta de respaldos.');
    $targets=['helper'=>[$helper,$helperContent],'page'=>[$page,$pageContent],'kardex'=>[$kardex,$newK]];
    $m=[];$written=[];
    foreach($targets as $key=>[$f,$content]){
        $existing=is_file($f);install_backup($f,$backup.'/'.$key.'.bak');
        $m[$key]=['creado'=>!$existing,'hash_original'=>$existing?hash_file('sha256',$f):null,'sha_instalado'=>hash('sha256',$content)];
    }
    try{
        foreach($targets as $key=>[$f,$content]){
            if(is_file($f)&&install_read($f)===$content)continue;
            install_write($f,$content);$written[]=$key;
        }
        install_write($manifest,json_encode($m,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    }catch(Throwable $e){
        foreach($written as $key){$f=$targets[$key][0];$bak=$backup.'/'.$key.'.bak';if(is_file($bak))@copy($bak,$f);elseif(is_file($f))@unlink($f);}
        throw $e;
    }
    echo "APLICADO: Centro de Alertas disponible en public/erp_alertas_inventario.php.\n";
    echo "No se modificaron tablas, triggers, ventas, existencias ni movimientos.\n";
}catch(Throwable $e){fwrite(STDERR,'ERROR: '.$e->getMessage()."\n");exit(1);}
