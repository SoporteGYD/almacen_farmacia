<?php
declare(strict_types=1);
/**
 * G&D: coloca navegación dentro de módulos y corrige los enlaces duplicados.
 * No altera SQL, permisos, operaciones, existencias o pagos.
 */
$root = dirname(__DIR__);
$public = $root . '/public';
$source = __DIR__ . '/nav_src';
$assets = [
    $source.'/gyd_nav_global.js' => $public.'/assets/js/gyd_nav_global.js',
    $source.'/gyd_nav_global.css' => $public.'/assets/css/gyd_nav_global.css',
];
$cmd = $argv[1] ?? '--check';
$stateFile = __DIR__.'/nav_integrada_ultimo_respaldo.json';
$exclusions = ['assets/','vendor/','/api_','session_keepalive.php','login.php','logout.php','exportar_','.bak','.backup','.respaldo','create_admin.php'];
$snippet = "\n<?php if (!defined('GYD_HIDE_GLOBAL_NAV')): ?>\n<link rel=\"stylesheet\" href=\"assets/css/gyd_nav_global.css?v=4\">\n<script>window.GYD_NAV_HOME='index.php';window.GYD_NAV_POS='pos.php';</script>\n<script src=\"assets/js/gyd_nav_global.js?v=4\"></script>\n<?php endif; ?>\n";
function candidates(string $folder, array $exclude): array {
    $out = [];
    if (!is_dir($folder)) return [];
    $itr = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS));
    foreach ($itr as $f) {
        if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') continue;
        $rel = str_replace('\\','/',substr($f->getPathname(), strlen($folder)+1));
        foreach ($exclude as $segment) if (strpos($rel,$segment) !== false) continue 2;
        $content = file_get_contents($f->getPathname());
        if ($content === false || stripos($content, '</body>') === false) continue;
        $out[$rel] = $f->getPathname();
    }
    ksort($out);
    return $out;
}
function revised(string $body, string $snippet): string {
    if (strpos($body,'gyd_nav_global.js') !== false) {
        $body = preg_replace('~assets/css/gyd_nav_global\.css(?:\?v=[^"\']*)?~', 'assets/css/gyd_nav_global.css?v=4', $body) ?? $body;
        $body = preg_replace('~assets/js/gyd_nav_global\.js(?:\?v=[^"\']*)?~', 'assets/js/gyd_nav_global.js?v=4', $body) ?? $body;
        return $body;
    }
    return preg_replace('~</body>~i', $snippet.'</body>', $body, 1) ?? $body;
}
function backupOne(string $path, string $backupDir, string $root): string {
    $rel = ltrim(str_replace('\\','/',substr($path,strlen($root))),'/');
    $dst = $backupDir . '/' . $rel;
    if (!is_dir(dirname($dst)) && !mkdir(dirname($dst),0770,true) && !is_dir(dirname($dst))) throw new RuntimeException('No se pudo preparar el respaldo');
    if (file_exists($path) && !copy($path,$dst)) throw new RuntimeException('No se pudo respaldar '.$rel);
    return $rel;
}
function writeFile(string $path, string $content): void {
    if (!is_dir(dirname($path)) && !mkdir(dirname($path),0770,true) && !is_dir(dirname($path))) throw new RuntimeException('No se pudo crear carpeta');
    $temp = $path.'.navtmp';
    if (file_put_contents($temp,$content,LOCK_EX) === false) throw new RuntimeException('No se pudo escribir '.$path);
    if (!rename($temp,$path)) { @unlink($temp); throw new RuntimeException('No se pudo instalar '.$path); }
}
$pages = candidates($public,$exclusions);
if ($cmd === '--check') {
    foreach ($assets as $src=>$dst) if (!is_file($src)) { fwrite(STDERR,"Falta recurso de actualización: $src\n"); exit(1); }
    if (!is_dir($public)) { fwrite(STDERR,"No se encuentra public/ del ERP\n"); exit(1); }
    $installed=0; $pending=0;
    foreach ($pages as $p) {
        $v=file_get_contents($p); if (strpos($v,'gyd_nav_global.js')!==false) $installed++; else $pending++;
    }
    echo "COMPATIBLE\nMódulos detectados: ".count($pages)."\nMódulos con script anterior: $installed\nMódulos aún no integrados: $pending\n";
    exit(0);
}
if ($cmd === '--apply') {
    if (!is_dir($public)) { fwrite(STDERR,"No se encuentra public/\n"); exit(1); }
    $changes = [];
    foreach ($assets as $src=>$dst) {
        if (!is_file($src)) { fwrite(STDERR,"Falta archivo del paquete\n"); exit(1); }
        $value=file_get_contents($src);
        if ($value === false) exit(1);
        if (!is_file($dst) || $value!==file_get_contents($dst)) $changes[$dst]=$value;
    }
    foreach ($pages as $path) {
        $original=file_get_contents($path);
        if ($original === false) continue;
        $target=revised($original,$snippet);
        if ($target!==$original) $changes[$path]=$target;
    }
    if (!$changes) { echo "SIN CAMBIOS: navegación integrada ya instalada.\n"; exit(0); }
    $backupDir = __DIR__ . '/respaldos_navegacion_integrada/' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
    if (!mkdir($backupDir,0770,true)) { fwrite(STDERR,"No se pudo crear respaldo\n"); exit(1); }
    $manifest=['backup'=>$backupDir,'files'=>[]];
    try {
        foreach ($changes as $path=>$content) {
            $rel=backupOne($path,$backupDir,$root);
            $manifest['files'][]=['path'=>$rel,'before_exists'=>is_file($path),'after_sha'=>hash('sha256',$content)];
        }
        foreach ($changes as $path=>$content) writeFile($path,$content);
        if (file_put_contents($stateFile,json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX)===false) throw new RuntimeException('No se pudo guardar estado');
        echo "APLICADO\nArchivos actualizados: ".count($changes)."\nRespaldo: ".$backupDir."\n";
    } catch(Throwable $e) { fwrite(STDERR,"ERROR: ".$e->getMessage().". Revise el respaldo en $backupDir\n"); exit(1); }
    exit(0);
}
if ($cmd === '--verify') {
    $good=true;
    foreach ($assets as $src=>$dst) if (!is_file($src)||!is_file($dst)||hash_file('sha256',$src)!==hash_file('sha256',$dst)) $good=false;
    $n=0;
    foreach ($pages as $path) { $v=file_get_contents($path); if ($v!==false && strpos($v,'gyd_nav_global.js?v=4')!==false) $n++; else $good=false; }
    if (!$good || !$n) { fwrite(STDERR,"ERROR: verificación incompleta. Revise rutas y recursos.\n"); exit(1); }
    echo "VERIFICADO: navegación integrada en $n páginas, sin barra flotante.\n"; exit(0);
}
if ($cmd === '--rollback') {
    if (!is_file($stateFile)) { fwrite(STDERR,"No se encontró respaldo anterior.\n"); exit(1); }
    $manifest=json_decode((string)file_get_contents($stateFile),true);
    if (!is_array($manifest) || !isset($manifest['backup'],$manifest['files'])) exit(1);
    $restored=0;$skipped=0;
    foreach ($manifest['files'] as $entry) {
        $path=$root.'/'.$entry['path']; $backup=$manifest['backup'].'/'.$entry['path'];
        if (is_file($path) && hash_file('sha256',$path)!==$entry['after_sha']) { $skipped++; continue; }
        if ($entry['before_exists']) { if(is_file($backup) && copy($backup,$path)) $restored++; else $skipped++; }
        else { if (!is_file($path)||unlink($path)) $restored++; else $skipped++; }
    }
    if ($skipped===0) @unlink($stateFile);
    echo "RESTAURACIÓN: $restored archivos restaurados, $skipped omitidos porque cambiaron desde la instalación.\n";
    exit($skipped?1:0);
}
fwrite(STDERR,"Uso: php instalar_navegacion_en_modulos.php --check|--apply|--verify|--rollback\n");
exit(1);
