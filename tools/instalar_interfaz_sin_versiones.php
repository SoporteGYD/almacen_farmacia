<?php
declare(strict_types=1);
/**
 * G&D | Limpieza de etiquetas de versión visibles.
 * Solo cambia textos literales; NO cambia rutas, funciones, bases de datos ni identificadores.
 * Ejecutar con CLI: --check / --apply / --verify.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo se ejecuta desde consola.'); }
$modo = $argv[1] ?? '--check';
if (!in_array($modo, ['--check', '--apply', '--verify'], true)) {
    fwrite(STDERR, "Uso: php tools/instalar_interfaz_sin_versiones.php --check|--apply|--verify\n");
    exit(2);
}
$raiz = realpath(__DIR__ . '/..');
if (!$raiz || !is_file($raiz . '/public/pos.php') || !is_dir($raiz . '/app')) {
    fwrite(STDERR, "ERROR: coloca la carpeta tools dentro del proyecto almacen-farmacia2.\n");
    exit(2);
}
$archivo = __DIR__ . '/reemplazos_interfaz.json';
$json = @file_get_contents($archivo);
$cfg = $json === false ? null : json_decode($json, true);
if (!is_array($cfg) || !isset($cfg['reemplazos']) || !is_array($cfg['reemplazos'])) {
    fwrite(STDERR, "ERROR: falta el mapa de cambios del paquete.\n");
    exit(2);
}
$criticos = ['public/gyd_canjes.php', 'public/gyd_clientes.php', 'public/gyd_recompensas.php', 'public/gyd_lab_v40.php', 'public/gyd_lab_v41.php'];
foreach ($criticos as $ruta) {
    if (!is_file($raiz . '/' . $ruta)) {
        fwrite(STDERR, "ERROR: falta archivo requerido: $ruta. No se realizaron cambios.\n");
        exit(2);
    }
}
$cambios = [];
$ausentes = [];
$restantes = [];
foreach ($cfg['reemplazos'] as $ruta => $mapa) {
    if (!preg_match('~^(?:public|app)/[A-Za-z0-9_./-]+\\.(?:php|js)$~', $ruta) || str_contains($ruta, '..')) {
        fwrite(STDERR, "ERROR: ruta no permitida en el paquete.\n"); exit(2);
    }
    $destino = $raiz . '/' . $ruta;
    if (!is_file($destino)) { $ausentes[] = $ruta; continue; }
    $actual = file_get_contents($destino);
    if ($actual === false) { fwrite(STDERR, "ERROR: no se pudo leer $ruta.\n"); exit(2); }
    $nuevo = $actual;
    $reemplazos = 0;
    foreach ($mapa as $buscar => $poner) {
        if (!is_string($buscar) || !is_string($poner) || $buscar === '') continue;
        $veces = substr_count($nuevo, $buscar);
        if ($veces > 0) { $nuevo = str_replace($buscar, $poner, $nuevo); $reemplazos += $veces; }
    }
    if ($nuevo !== $actual) $cambios[$ruta] = ['original'=>$actual, 'nuevo'=>$nuevo, 'count'=>$reemplazos];
    // En --verify la ausencia de textos originales es el criterio de conformidad.
    // Identificadores técnicos (p.ej. window.GYD_LAB_V41) quedan fuera del mapa.
    foreach ($mapa as $buscar => $poner) {
        if (str_contains($nuevo, $buscar) && $buscar !== $poner) $restantes[] = $ruta . ' :: ' . $buscar;
    }
}
if ($modo === '--check') {
    echo "REVISIÓN DE ETIQUETAS VISIBLES · sin cambiar archivos\n";
    echo "Proyecto: $raiz\n";
    foreach ($cambios as $ruta=>$dato) echo "  CAMBIAR  $ruta (".$dato['count']." etiquetas)\n";
    foreach ($ausentes as $ruta) echo "  OMITIR   $ruta (módulo opcional no instalado)\n";
    echo "Archivos pendientes: ".count($cambios).". No se modifica la base de datos.\n";
    exit(0);
}
if ($modo === '--verify') {
    if ($cambios) {
        echo "PENDIENTE: quedan ".count($cambios)." archivos con textos visibles antiguos. Ejecuta --apply.\n";
        foreach (array_keys($cambios) as $ruta) echo " - $ruta\n";
        exit(1);
    }
    echo "VERIFICADO: no quedan las etiquetas visibles previstas en los archivos instalados.\n";
    echo "Identificadores internos, rutas y nombres de archivos no se cambiaron.\n";
    exit(0);
}
if (!$cambios) { echo "SIN CAMBIOS: la interfaz ya tiene los textos limpios.\n"; exit(0); }
$stamp = date('Ymd_His');
$backup = __DIR__ . '/respaldos_interfaz_' . $stamp;
if (file_exists($backup)) $backup .= '_' . getmypid();
if (!mkdir($backup, 0700, true)) { fwrite(STDERR, "ERROR: no se pudo crear respaldo.\n"); exit(2); }
$respaldados = [];
foreach ($cambios as $ruta=>$datos) {
    $destino = $backup . '/' . $ruta;
    $dir = dirname($destino);
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) { fwrite(STDERR,"ERROR respaldo $ruta\n");exit(2); }
    if (file_put_contents($destino, $datos['original']) === false) { fwrite(STDERR,"ERROR respaldo $ruta\n");exit(2); }
    $respaldados[$ruta] = hash('sha256', $datos['original']);
}
file_put_contents($backup . '/MANIFIESTO_RESPALDO.json', json_encode($respaldados, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
$aplicados = [];
foreach ($cambios as $ruta=>$datos) {
    $destino = $raiz . '/' . $ruta;
    $temporal = $destino . '.limpieza.' . getmypid() . '.tmp';
    if (file_put_contents($temporal, $datos['nuevo'], LOCK_EX) === false) {
        fwrite(STDERR, "ERROR al escribir $ruta. Restaurando...\n"); break;
    }
    @chmod($temporal, fileperms($destino) & 0777);
    if (!rename($temporal, $destino)) {
        @unlink($temporal);
        fwrite(STDERR, "ERROR al reemplazar $ruta. Restaurando...\n"); break;
    }
    $aplicados[] = $ruta;
    echo "ACTUALIZADO $ruta\n";
}
if (count($aplicados)!==count($cambios)) {
    foreach ($aplicados as $ruta) {
        copy($backup.'/'.$ruta, $raiz.'/'.$ruta);
    }
    fwrite(STDERR, "Se restauraron los archivos modificados. Revisa permisos.\n");
    exit(2);
}
echo "APLICADO: ".count($cambios)." archivos. Respaldo: $backup\n";
echo "Ahora ejecuta --verify y recarga la página con Ctrl+F5.\n";
