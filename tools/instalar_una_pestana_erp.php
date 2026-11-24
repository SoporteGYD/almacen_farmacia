<?php
declare(strict_types=1);
/** G&D: restore single-tab navigation for internal ERP/POS workflows. */
$root = dirname(__DIR__);
$public = $root . '/public';
$installer = __DIR__;
$src = $installer . '/una_pestana_src/gyd_una_pestana.js';
$dst = $public . '/assets/js/gyd_una_pestana.js';
$manifestFile = $installer . '/una_pestana_ultimo_respaldo.json';
$command = $argv[1] ?? '--check';
$marker = '<!-- GYD_UNA_PESTANA_INTERNA -->';
$inject = $marker . "\n" . '<script src="assets/js/gyd_una_pestana.js?v=1"></script>' . "\n";

function eligible(string $public): array {
    $files=[];
    if (!is_dir($public)) return $files;
    foreach (new DirectoryIterator($public) as $node) {
        if (!$node->isFile() || strtolower($node->getExtension()) !== 'php') continue;
        $name=$node->getFilename();
        if (preg_match('/^(?:login|logout|index|session_keepalive|api_|exportar_|create_admin|erp_cxp_archivo)\b/i', $name)) continue;
        if (preg_match('/\.(bak|backup|respaldo)|\.backup_|\.respaldo_/i',$name)) continue;
        $source=@file_get_contents($node->getPathname());
        if (!is_string($source) || !preg_match('~</body\s*>~i',$source))continue;
        $files[$name]=$node->getPathname();
    }
    ksort($files);
    return $files;
}
function planned(array $files,string $src,string $dst,string $marker,string $inject):array{
    $issues=[];$writes=[];
    if (!is_file($src))$issues[]='No se encontró el recurso JS del paquete.';
    else {
        $js=@file_get_contents($src);
        if (!is_string($js)||strlen($js)<100)$issues[]='Recurso JS inválido.';
        elseif (!is_file($dst) || @file_get_contents($dst)!==$js)$writes[$dst]=$js;
    }
    foreach($files as $filename=>$path){
        $old=file_get_contents($path);
        if ($old===false){$issues[]='No legible: '.$filename;continue;}
        if (str_contains($old,$marker)){
            // Idempotent: do not insert a second helper.
            if(substr_count($old,$marker)!==1)$issues[]='Marcador repetido: '.$filename;
            continue;
        }
        $new=preg_replace('~</body\s*>~i',$inject.'</body>',$old,1,$count);
        if ($count!==1 || $new===null){$issues[]='No se pudo modificar: '.$filename;continue;}
        $writes[$path]=$new;
    }
    return [$writes,$issues];
}
function atomicPut(string $path,string $content):void{
    if(!is_dir(dirname($path)) && !mkdir(dirname($path),0770,true) && !is_dir(dirname($path)))throw new RuntimeException('No fue posible crear directorio');
    $tmp=$path.'.gyd_unatab_tmp';
    if(file_put_contents($tmp,$content,LOCK_EX)===false || !rename($tmp,$path)) { @unlink($tmp); throw new RuntimeException('No se pudo guardar '.basename($path)); }
}
if(!is_dir($public)){fwrite(STDERR,"ERROR: no se encontró el ERP.\n");exit(1);}
$files=eligible($public);
if(count($files)<2){fwrite(STDERR,"ERROR: no se detectaron páginas del ERP.\n");exit(1);}
[$writes,$issues]=planned($files,$src,$dst,$marker,$inject);
if($command==='--check'){
    if($issues){fwrite(STDERR,"NO COMPATIBLE: ".implode('; ',$issues)."\n");exit(1);}
    echo "COMPATIBLE\nPáginas HTML detectadas: ".count($files)."\nActualizaciones pendientes: ".count($writes)."\nNo modifica base de datos ni operaciones financieras.\n";exit;
}
if($command==='--apply'){
    if($issues){fwrite(STDERR,"NO COMPATIBLE: ".implode('; ',$issues)."\n");exit(1);}
    if(!$writes){echo "SIN CAMBIOS: la navegación interna ya está aplicada.\n";exit;}
    $dir=$installer.'/respaldos_una_pestana/'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
    if(!mkdir($dir,0770,true)){fwrite(STDERR,"ERROR: no se pudo crear respaldo.\n");exit(1);}
    $manifest=['backup'=>$dir,'files'=>[]];
    try{
        foreach($writes as $path=>$content){
            $rel=ltrim(str_replace('\\','/',substr($path,strlen($root))),'/');
            $exists=is_file($path);
            $backup=$dir.'/'.$rel;
            if($exists){
                if(!is_dir(dirname($backup)) && !mkdir(dirname($backup),0770,true) && !is_dir(dirname($backup)))throw new RuntimeException('No se pudo crear directorio de respaldo');
                if(!copy($path,$backup))throw new RuntimeException('No se pudo respaldar '.$rel);
            }
            $manifest['files'][]=['path'=>$rel,'existed'=>$exists,'new_sha256'=>hash('sha256',$content)];
        }
        foreach($writes as $path=>$content)atomicPut($path,$content);
        if(file_put_contents($manifestFile,json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX)===false)throw new RuntimeException('Error al registrar respaldo');
        echo "APLICADO\nArchivos actualizados: ".count($writes)."\nRespaldo: $dir\n";
    }catch(Throwable $e){fwrite(STDERR,"ERROR: ".$e->getMessage().". Revisa el respaldo de $dir\n");exit(1);}
    exit;
}
if($command==='--verify'){
    if($issues || !is_file($dst) || count($writes)){fwrite(STDERR,"ERROR: faltan archivos o fueron modificados.\n");exit(1);}
    foreach($files as $name=>$path){$s=file_get_contents($path);if(substr_count($s,$marker)!==1){fwrite(STDERR,"ERROR: falta script en $name\n");exit(1);}}
    echo "VERIFICADO: ".count($files)." páginas con navegación de una pestaña.\n";exit;
}
if($command==='--rollback'){
    if(!is_file($manifestFile)){fwrite(STDERR,"ERROR: no hay respaldo registrado.\n");exit(1);}
    $manifest=json_decode((string)file_get_contents($manifestFile),true);
    if(!is_array($manifest) || !isset($manifest['backup'],$manifest['files'])){fwrite(STDERR,"ERROR: respaldo inválido.\n");exit(1);}
    $restored=0;$skipped=0;
    foreach($manifest['files'] as $entry){
        $path=$root.'/'.$entry['path'];$bak=$manifest['backup'].'/'.$entry['path'];
        if(is_file($path) && hash_file('sha256',$path)!==$entry['new_sha256']){$skipped++;continue;}
        if($entry['existed']){
            if(is_file($bak)&&copy($bak,$path))$restored++;else $skipped++;
        }else{
            if(!is_file($path)||unlink($path))$restored++;else $skipped++;
        }
    }
    if(!$skipped)@unlink($manifestFile);
    echo "RESTAURADOS: $restored; omitidos por cambios posteriores: $skipped\n";
    exit($skipped?1:0);
}
fwrite(STDERR,"Uso: php instalar_una_pestana_erp.php --check|--apply|--verify|--rollback\n");exit(1);
