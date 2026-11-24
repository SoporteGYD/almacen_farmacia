<?php
declare(strict_types=1);
/**
 * G&D: navigation by signed-in role; no floating bars.
 * Leaves cash, sales, inventory, pricing, user permissions unchanged.
 * Upgrades only PHP pages already injected by installer 'en_modulos'.
 */
$root = dirname(__DIR__);
$public = $root . '/public';
$cmd = $argv[1] ?? '--check';
$stateFile = __DIR__ . '/navegacion_roles_ultimo_respaldo.json';
$assetTargets = [
    __DIR__.'/nav_roles_src/gyd_nav_global.js' => $public.'/assets/js/gyd_nav_global.js',
    __DIR__.'/nav_roles_src/gyd_nav_global.css' => $public.'/assets/css/gyd_nav_global.css',
];
const GYD_NAV_ROLE_START = '/* GYD_NAV_ROLE_V1 */';

// Never obtain role from URL, cookies set by JS or localStorage. Session-only.
$snippet = <<<'SNIPPET'
<?php /* GYD_NAV_ROLE_V1 */
$__gydNavigationAccount = function_exists('isLoggedIn') && isLoggedIn() && function_exists('currentUser')
    ? (currentUser() ?: []) : [];
$__gydNavigationRole = strtoupper(trim((string)($__gydNavigationAccount['rol_original'] ?? $__gydNavigationAccount['rol'] ?? '')));
$__gydNavigationAllowed = in_array($__gydNavigationRole, ['DIRECCION','ADMINISTRADOR','ADMIN','COMPRAS'], true);
if (!defined('GYD_HIDE_GLOBAL_NAV') && $__gydNavigationAllowed):
$__gydNavigationHome = $__gydNavigationRole === 'COMPRAS' ? 'erp_compras_inicio.php' : 'dashboard.php';
?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=5">
<script>window.GYD_NAV_ENABLED=true;window.GYD_NAV_HOME=<?=json_encode($__gydNavigationHome, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=5"></script>
<?php endif; unset($__gydNavigationAccount,$__gydNavigationRole,$__gydNavigationAllowed,$__gydNavigationHome); ?>
SNIPPET;

/** Match the previous installer block, NEVER a site's pre-existing scripts. */
$oldPattern = '~<\?php\s+if\s*\(\s*!defined\s*\(\s*[\'\"]GYD_HIDE_GLOBAL_NAV[\'\"]\s*\)\s*\)\s*:\s*\?>\s*<link\b[^>]*gyd_nav_global\.css[^>]*>\s*<script(?:\s[^>]*)?>[\s\S]*?<\/script>\s*<script\b[^>]*gyd_nav_global\.js[^>]*><\/script>\s*<\?php\s+endif\s*;\s*\?>~i';
$newPattern = '~<\?php\s*/\*\s*GYD_NAV_ROLE_V1\s*\*/[\s\S]*?unset\(\$__gydNavigationAccount,\$__gydNavigationRole,\$__gydNavigationAllowed,\$__gydNavigationHome\);\s*\?>~i';

function navFiles(string $public): array {
    if (!is_dir($public)) return [];
    $itr = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($public, FilesystemIterator::SKIP_DOTS));
    $files = [];
    foreach ($itr as $f) {
        if (!$f->isFile() || strtolower($f->getExtension())!=='php') continue;
        $path=$f->getPathname();
        $rel=str_replace('\\','/',substr($path,strlen($public)+1));
        if (str_contains($rel,'/assets/') || str_contains($rel,'/vendor/') || str_contains($rel,'.bak') || str_contains($rel,'.backup') || str_contains($rel,'.respaldo')) continue;
        $source = @file_get_contents($path);
        if ($source !== false && str_contains($source,'gyd_nav_global.js')) $files[$rel]=$path;
    }
    ksort($files);
    return $files;
}
function transform(string $text, string $newSnippet, string $oldPattern, string $newPattern): ?string {
    $n=0;
    if (str_contains($text, 'GYD_NAV_ROLE_V1')) {
        $out=preg_replace($newPattern,$newSnippet,$text,1,$n);
    } else {
        $out=preg_replace($oldPattern,$newSnippet,$text,1,$n);
    }
    return $out!==null && $n===1 ? $out : null;
}
function putSafely(string $target, string $data): void {
    if (!is_dir(dirname($target)) && !mkdir(dirname($target),0770,true) && !is_dir(dirname($target))) throw new RuntimeException('No se pudo crear '.dirname($target));
    $tmp=$target.'.gyd_navtmp';
    if (file_put_contents($tmp,$data,LOCK_EX)===false || !rename($tmp,$target)) { @unlink($tmp); throw new RuntimeException('Error escribiendo '.$target); }
}
function planned(array $pages, array $assets, string $snippet, string $oldPattern, string $newPattern): array {
    $changes=[];$invalid=[];
    foreach ($assets as $src=>$dst) {
        if (!is_file($src)) { $invalid[]='Recurso faltante: '.$src;continue; }
        $data=file_get_contents($src);
        if($data===false){$invalid[]='No legible: '.$src;continue;}
        if (!is_file($dst) || @file_get_contents($dst)!==$data) $changes[$dst]=$data;
    }
    foreach ($pages as $rel=>$path) {
        $orig=@file_get_contents($path);
        if ($orig===false) {$invalid[]='No legible: '.$rel;continue;}
        $next=transform($orig,$snippet,$oldPattern,$newPattern);
        if ($next===null) {$invalid[]='Navegación no reconocida: '.$rel;continue;}
        if ($next!==$orig) $changes[$path]=$next;
    }
    return [$changes,$invalid];
}

$pages=navFiles($public);
if(!is_dir($public) || !count($pages)){fwrite(STDERR,"ERROR: falta proyecto ERP o la navegación por módulos anterior no está instalada.\n");exit(1);}
[$changes,$invalid]=planned($pages,$assetTargets,$snippet,$oldPattern,$newPattern);
if ($cmd==='--check') {
    if($invalid){fwrite(STDERR,"ERROR: detectar diferencias antes de aplicar:\n".implode("\n",$invalid)."\n");exit(1);}
    echo "COMPATIBLE\nMódulos ya conectados: ".count($pages)."\nArchivos que se actualizarán: ".count($changes)."\nRoles visibles: DIRECCION, ADMINISTRADOR, ADMIN y COMPRAS\nInicio COMPRAS: erp_compras_inicio.php\nInicio DIRECCION/ADMIN: dashboard.php\n";exit(0);
}
if ($cmd==='--apply') {
    if($invalid){fwrite(STDERR,"ERROR: archivos incompatibles. No se aplicó ningún cambio:\n".implode("\n",$invalid)."\n");exit(1);}
    if(!$changes){echo "SIN CAMBIOS: navegación por roles ya instalada.\n";exit(0);}
    $backupDir=__DIR__.'/respaldos_nav_roles/'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
    if(!mkdir($backupDir,0770,true)) {fwrite(STDERR,"No se pudo crear respaldo.\n");exit(1);}
    $manifest=['backup'=>$backupDir,'files'=>[]];
    try {
        foreach($changes as $path=>$newText) {
            $rel=ltrim(str_replace('\\','/',substr($path,strlen($root))),'/');
            $hadFile=is_file($path);
            if ($hadFile){
                if (!is_dir(dirname($backupDir.'/'.$rel)) && !mkdir(dirname($backupDir.'/'.$rel),0770,true) && !is_dir(dirname($backupDir.'/'.$rel))) throw new RuntimeException('No se pudo crear carpeta respaldo');
                if (!copy($path,$backupDir.'/'.$rel)) throw new RuntimeException('No se pudo respaldar '.$rel);
            }
            $manifest['files'][]=['path'=>$rel,'before_exists'=>$hadFile,'after_sha'=>hash('sha256',$newText)];
        }
        foreach($changes as $path=>$newText)putSafely($path,$newText);
        if(file_put_contents($stateFile,json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX)===false)throw new RuntimeException('No se pudo guardar estado');
        echo "APLICADO\nPáginas con rol protegido: ".count($pages)."\nArchivos modificados: ".count($changes)."\nRespaldo: $backupDir\n";
    }catch(Throwable $e){fwrite(STDERR,"ERROR: ".$e->getMessage().". Revisa y restaura el respaldo $backupDir\n");exit(1);}
    exit(0);
}
if ($cmd==='--verify') {
    if($invalid || $changes){fwrite(STDERR,"ERROR: instalación incompleta o archivos cambiados.\n");exit(1);}
    foreach($pages as $rel=>$path){
        $content=file_get_contents($path);
        if (!str_contains($content,'GYD_NAV_ROLE_V1') || !str_contains($content,'GYD_NAV_ENABLED=true') || !str_contains($content,"'COMPRAS' ? 'erp_compras_inicio.php' : 'dashboard.php'")) {
            fwrite(STDERR,"ERROR: protección faltante en $rel\n");exit(1);
        }
    }
    echo "VERIFICADO: ".count($pages)." páginas protegidas por rol y con inicio correcto.\n";exit(0);
}
if ($cmd==='--rollback') {
    if(!is_file($stateFile)){fwrite(STDERR,"ERROR: no existe manifest de restauración.\n");exit(1);}
    $manifest=json_decode((string)file_get_contents($stateFile),true);
    if(!is_array($manifest)||!isset($manifest['backup'],$manifest['files']))exit(1);
    $good=0;$skipped=0;
    foreach($manifest['files'] as $entry){
        $path=$root.'/'.$entry['path'];$backup=$manifest['backup'].'/'.$entry['path'];
        if(is_file($path) && hash_file('sha256',$path)!==$entry['after_sha']){$skipped++;continue;}
        if($entry['before_exists']){if(is_file($backup)&&copy($backup,$path))$good++;else $skipped++;}
        else {if(!is_file($path)||unlink($path))$good++;else $skipped++;}
    }
    if($skipped===0)@unlink($stateFile);
    echo "RESTAURACIÓN: $good archivos, omitidos $skipped.\n";exit($skipped?1:0);
}
fwrite(STDERR,"Uso: php instalar_navegacion_por_roles.php --check|--apply|--verify|--rollback\n");exit(1);
