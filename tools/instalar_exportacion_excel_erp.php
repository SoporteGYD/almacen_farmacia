<?php
declare(strict_types=1);
/**
 * Instalación reversible de botón Exportar Excel (.xlsx) para informes ERP G&D.
 * Uso: php tools/instalar_exportacion_excel_erp.php --check | --apply | --verify | --rollback
 */
if (PHP_SAPI!=='cli'){http_response_code(403);exit('Instalador solo disponible en CLI.');}
$mode=$argv[1]??'--check';
if(!in_array($mode,['--check','--apply','--verify','--rollback'],true)){fwrite(STDERR,"Uso: --check | --apply | --verify | --rollback\n");exit(2);}
$root=realpath(dirname(__DIR__));
if(!$root||!is_dir($root.'/public')||!is_file($root.'/app/helpers/auth.php')){fwrite(STDERR,"Error: no se detecta la raíz del ERP. Copia tools en almacen-farmacia2.\n");exit(2);}
$payload=__DIR__.'/payload';
$manifest=$root.'/tools/.manifest_excel_reportes_gyd.json';
$mark='gyd_excel_reportes.js';
$tag='<script src="assets/js/gyd_excel_reportes.js" defer></script>';
function gl_read(string $path):string{ $s=@file_get_contents($path);if(!is_string($s))throw new RuntimeException('No se pudo leer: '.$path);return $s;}
function gl_files(string $root):array {
    $files=[];
    $files['app/views/layouts/footer.php']='shared';
    $files['app/helpers/erp_facturas.php']='helper';
    $files['app/helpers/erp_cxp.php']='helper';
    foreach(glob($root.'/public/erp_*.php')?:[] as $p){
        $data=@file_get_contents($p);
        if(in_array(basename($p),['erp_permisos_usuarios.php','erp_notificaciones.php','erp_compras_alerta_resolver.php','erp_producto_alta.php'],true))continue;
        if(!is_string($data)||stripos($data,'</body>')===false)continue;
        // Incluye reportes, listados y vistas financieras, sin tocar APIs.
        if(preg_match('/<table\b|exportar|\bcsv\b|reporte|resumen|historial|kardex|conciliaci[oó]n|auditor[ií]a|dashboard|finanzas|rendimiento/i',$data.' '.basename($p))){
            $files['public/'.basename($p)]='direct';
        }
    }
    foreach(['gyd_panel_cajas.php','gyd_reportes_gerenciales.php','pos_conciliacion_turno.php'] as $p){
        if(is_file($root.'/public/'.$p))$files['public/'.$p]='direct';
    }
    ksort($files);return $files;
}
function gl_patch(string $text,string $kind,string $tag):?string {
    if(str_contains($text,'gyd_excel_reportes.js'))return null;
    if($kind==='helper'){
        $needle="'</main></body></html>'";
        $replace="'</main>".$tag."</body></html>'";
        return str_contains($text,$needle)?str_replace($needle,$replace,$text):null;
    }
    $pos=strripos($text,'</body>');
    if($pos===false)return null;
    return substr_replace($text,"\n".$tag."\n",$pos,0);
}
function gl_write(string $path,string $data):void {
    $dir=dirname($path);if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Sin permiso para crear '.$dir);
    if(file_put_contents($path,$data,LOCK_EX)===false)throw new RuntimeException('No se pudo escribir '.$path);
}
try{
    if($mode==='--rollback'){
        if(!is_file($manifest))throw new RuntimeException('No existe un respaldo de esta actualización.');
        $m=json_decode(gl_read($manifest),true,32,JSON_THROW_ON_ERROR);
        foreach(($m['modified']??[]) as $rel=>$info){
            $target=$root.'/'.$rel;
            if(!is_file($target)||hash_file('sha256',$target)!==$info['updated'])throw new RuntimeException('Se ha modificado '.$rel.' desde la instalación. No se sobrescribe; restaura manualmente.');
        }
        foreach(($m['created']??[]) as $rel=>$hash){
            $path=$root.'/'.$rel;
            if(is_file($path)&&hash_file('sha256',$path)!==$hash)throw new RuntimeException('Archivo modificado: '.$rel.'. No se elimina.');
        }
        foreach($m['modified'] as $rel=>$info){copy($root.'/'.$info['backup'],$root.'/'.$rel);echo "RESTAURADO {$rel}\n";}
        foreach($m['created'] as $rel=>$hash){if(is_file($root.'/'.$rel))unlink($root.'/'.$rel);echo "RETIRADO {$rel}\n";}
        unlink($manifest);echo "RESTABLECIDO. No se modificaron bases de datos.\n";exit(0);
    }
    $needed=[
        'public/erp_exportar_excel.php'=>__DIR__.'/payload/public/erp_exportar_excel.php',
        'public/assets/js/gyd_excel_reportes.js'=>__DIR__.'/payload/public/assets/js/gyd_excel_reportes.js',
    ];
    if($mode==='--verify'){
        $bad=[];
        foreach($needed as $rel=>$src)if(!is_file($root.'/'.$rel)||hash_file('sha256',$root.'/'.$rel)!==hash_file('sha256',$src))$bad[]=$rel;
        foreach(gl_files($root) as $rel=>$kind){
            $content=gl_read($root.'/'.$rel);
            if(!str_contains($content,$mark))$bad[]=$rel;
        }
        if($bad){echo "FALTAN O CAMBIARON ARCHIVOS:\n - ".implode("\n - ",$bad)."\n";exit(1);}
        echo 'VERIFICADO: módulo Excel presente, sin alterar consultas, inventarios ni caja.' . "\n";
        exit(0);
    }
    $files=gl_files($root);$changes=[];$already=[];$unavailable=[];
    foreach($files as $rel=>$kind){
        $old=gl_read($root.'/'.$rel);$new=gl_patch($old,$kind,$tag);
        if($new!==null&&$new!==$old)$changes[$rel]=['old'=>$old,'new'=>$new];
        elseif(str_contains($old,$mark))$already[]=$rel;
        else $unavailable[]=$rel;
    }
    echo 'PÁGINAS Y PLANTILLAS DETECTADAS: '.count($files)."\n";
    echo 'PENDIENTES DE INTEGRAR: '.count($changes)."\n";
    echo 'YA INTEGRADAS: '.count($already)."\n";
    if($unavailable)echo 'SIN PUNTO DE INSERCIÓN: '.implode(', ',$unavailable)."\n";
    foreach($needed as $rel=>$src){
        if(!is_file($src))throw new RuntimeException('Falta archivo en paquete: '.$rel);
        if(is_file($root.'/'.$rel)&&hash_file('sha256',$src)!==hash_file('sha256',$root.'/'.$rel))throw new RuntimeException('Existe una implementación distinta de '.$rel.'. No se sobrescribe.');
    }
    if(!is_file($root.'/vendor/autoload.php'))throw new RuntimeException('No se encontró vendor/autoload.php. Conserva la carpeta vendor del ERP.');
    if($mode==='--check'){
        echo "COMPATIBLE PARA INSTALAR.\n";
        echo 'Se añaden Excel (.xlsx) a reportes existentes; no hay cambios SQL.' . "\n";
        exit(0);
    }
    if(is_file($manifest))throw new RuntimeException('La actualización ya tiene manifiesto. Ejecuta --verify o --rollback.');
    $stamp=date('Ymd_His').'_'.bin2hex(random_bytes(3));
    $backup='tools/backups/excel_reportes_'.$stamp;
    if(!mkdir($root.'/'.$backup,0775,true)&&!is_dir($root.'/'.$backup))throw new RuntimeException('No fue posible crear carpeta de respaldo.');
    $m=['created'=>[],'modified'=>[],'date'=>date(DATE_ATOM)];
    // Seguridad para copias locales del proyecto si el servidor permite HTTP sobre /tools.
    if(!is_file($root.'/tools/backups/.htaccess'))gl_write($root.'/tools/backups/.htaccess',"Require all denied\nDeny from all\n");
    foreach($changes as $rel=>$item){
        $b=$backup.'/'.$rel;
        gl_write($root.'/'.$b,$item['old']);
        $m['modified'][$rel]=['backup'=>$b,'original'=>hash('sha256',$item['old']),'updated'=>hash('sha256',$item['new'])];
    }
    foreach($needed as $rel=>$src){
        if(!is_file($root.'/'.$rel))$m['created'][$rel]=hash_file('sha256',$src);
    }
    // Manifiesto antes de escribir, para restaurar después de una interrupción.
    gl_write($manifest,json_encode($m,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    foreach($changes as $rel=>$item){gl_write($root.'/'.$rel,$item['new']);echo "ACTUALIZADO {$rel}\n";}
    foreach($needed as $rel=>$src){if(!is_file($root.'/'.$rel)){gl_write($root.'/'.$rel,gl_read($src));echo "NUEVO {$rel}\n";}}
    echo "APLICADO. Reinicia Apache y actualiza el navegador con Ctrl+F5.\n";
}catch(Throwable $ex){fwrite(STDERR,'ERROR: '.$ex->getMessage()."\n");exit(1);}
