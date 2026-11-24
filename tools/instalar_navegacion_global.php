<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$public = $root . '/public';
$assetsJs = $public . '/assets/js/gyd_nav_global.js';
$assetsCss = $public . '/assets/css/gyd_nav_global.css';
$backupDir = $root . '/tools/backups_nav_global';
$marker = 'gyd_nav_global.js';
$snippet = "\n<?php if (!defined('GYD_HIDE_GLOBAL_NAV')): ?>\n<link rel=\"stylesheet\" href=\"assets/css/gyd_nav_global.css?v=1\">\n<script>window.GYD_NAV_HOME='index.php';window.GYD_NAV_POS='pos.php';</script>\n<script src=\"assets/js/gyd_nav_global.js?v=1\"></script>\n<?php endif; ?>\n";
$exclude = [
  'assets/', 'vendor/', '/api_', 'session_keepalive.php', 'login.php', 'logout.php', 'exportar_', '.bak', '.backup', '.respaldo', 'create_admin.php'
];
$cmd = $argv[1] ?? '--check';
function collectFiles(string $public, array $exclude): array {
  $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($public, FilesystemIterator::SKIP_DOTS));
  $files = [];
  foreach ($rii as $file) {
    if (!$file->isFile()) continue;
    $path = str_replace('\\','/',$file->getPathname());
    if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php') continue;
    $rel = ltrim(str_replace(str_replace('\\','/',$public), '', $path), '/');
    $skip = false;
    foreach ($exclude as $ex) { if (strpos($rel, $ex) !== false) { $skip = true; break; } }
    if ($skip) continue;
    $contents = file_get_contents($path);
    if ($contents === false) continue;
    if (stripos($contents, '</body>') === false) continue;
    $files[] = [$path, $rel];
  }
  sort($files);
  return $files;
}
$files = is_dir($public) ? collectFiles($public, $exclude) : [];
if ($cmd === '--check') {
  if (!is_dir($public)) { fwrite(STDERR, "ERROR: no se encontró la carpeta public\n"); exit(1); }
  echo "COMPATIBLE\n";
  echo "PHP con </body> detectados: ".count($files)."\n";
  exit(0);
}
if ($cmd === '--apply') {
  if (!is_dir($public)) { fwrite(STDERR, "ERROR: no se encontró la carpeta public\n"); exit(1); }
  @mkdir(dirname($assetsJs), 0777, true);
  @mkdir(dirname($assetsCss), 0777, true);
  @mkdir($backupDir, 0777, true);
  copy(__DIR__ . '/../public/assets/js/gyd_nav_global.js', $assetsJs);
  copy(__DIR__ . '/../public/assets/css/gyd_nav_global.css', $assetsCss);
  $patched = 0; $already = 0;
  foreach ($files as [$path, $rel]) {
    $contents = file_get_contents($path);
    if ($contents === false) continue;
    if (strpos($contents, $marker) !== false) { $already++; continue; }
    $backup = $backupDir . '/' . str_replace('/','__',$rel) . '.bak';
    if (!is_file($backup)) copy($path, $backup);
    $new = preg_replace('~</body>~i', $snippet . '</body>', $contents, 1, $count);
    if ($count > 0 && $new !== null) {
      file_put_contents($path, $new);
      $patched++;
    }
  }
  echo "APLICADO\n";
  echo "Archivos parchados: {$patched}\n";
  echo "Archivos ya parchados: {$already}\n";
  exit(0);
}
if ($cmd === '--verify') {
  $ok = is_file($assetsJs) && is_file($assetsCss);
  $patched = 0;
  foreach ($files as [$path, $rel]) {
    $contents = file_get_contents($path);
    if ($contents !== false && strpos($contents, $marker) !== false) $patched++;
  }
  if (!$ok || $patched === 0) { fwrite(STDERR, "ERROR: la verificación no pasó\n"); exit(1); }
  echo "VERIFICADO\n";
  echo "Archivos con navegación global: {$patched}\n";
  exit(0);
}
fwrite(STDERR, "Uso: php instalar_navegacion_global.php [--check|--apply|--verify]\n"); exit(1);
