<?php
declare(strict_types=1);
/** Actualización exclusivamente visual: wizard de petición de traspasos G&D. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo uso desde la terminal.'); }
$accion = $argv[1] ?? '';
if (!in_array($accion, ['--check', '--apply'], true)) { fwrite(STDERR, "Uso: php tools/instalar_peticiones_wizard_v27.php --check|--apply\n"); exit(2); }
$root = dirname(__DIR__);
$payload = __DIR__ . '/_peticiones_wizard_payload/public';
$destPhp = $root . '/public/peticiones_traspasos.php';
$assets = ['peticiones_wizard.css', 'peticiones_wizard.js'];
$baseScript = '<script src="peticiones_traspasos.js" defer></script>';
$markerCss = '<link rel="stylesheet" href="peticiones_wizard.css">';
$markerJs = '<script src="peticiones_wizard.js" defer></script>';
try {
    if (PHP_VERSION_ID < 80100) throw new RuntimeException('Requiere PHP 8.1 o superior.');
    if (!is_file($destPhp) || !is_file($root . '/public/peticiones_traspasos.js')) {
        throw new RuntimeException('Instala primero Peticiones de Traspaso V26.2; no se encontró la pantalla original.');
    }
    foreach ($assets as $asset) {
        if (!is_file($payload . '/' . $asset)) throw new RuntimeException('El ZIP está incompleto: ' . $asset);
        $dst = $root . '/public/' . $asset;
        if (is_file($dst) && hash_file('sha256', $dst) !== hash_file('sha256', $payload . '/' . $asset)) {
            throw new RuntimeException('Existe una versión diferente de public/' . $asset . '. No se sobrescribirá.');
        }
    }
    $actual = file_get_contents($destPhp);
    if ($actual === false) throw new RuntimeException('No se pudo leer Peticiones de Traspaso.');
    foreach (['id="crear"', 'id="origen"', 'id="destino"', 'id="destino_tipo"', 'id="producto_buscar"', 'id="tabla_items"', 'id="items_json"', 'id="abrir_catalogo"', 'id="abrir_sugerencias"'] as $anchor) {
        if (!str_contains($actual, $anchor)) throw new RuntimeException('La pantalla actual fue personalizada; falta ' . $anchor . '. No se aplicarán cambios.');
    }
    $hasCss = str_contains($actual, $markerCss);
    $hasJs = str_contains($actual, $markerJs);
    if ($hasCss !== $hasJs) throw new RuntimeException('Se detectó una instalación incompleta del asistente. Revisa public/peticiones_traspasos.php.');
    $nuevo = $actual;
    if (!$hasCss) {
        if (substr_count($actual, '</head>') !== 1 || substr_count($actual, $baseScript) !== 1) {
            throw new RuntimeException('No se reconocieron los puntos de integración en la pantalla. No se modificará.');
        }
        $nuevo = str_replace('</head>', $markerCss . '</head>', $nuevo);
        $nuevo = str_replace($baseScript, $baseScript . $markerJs, $nuevo);
    }
    // Comprobar sintaxis PHP sin modificar el código original.
    $temp = tempnam(sys_get_temp_dir(), 'gyd_wizard_');
    if ($temp === false) throw new RuntimeException('No se pudo crear el archivo temporal de validación.');
    try {
        if (file_put_contents($temp, $nuevo) === false) throw new RuntimeException('No se pudo escribir la prueba temporal.');
        $out = [];
        $code = 1;
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($temp) . ' 2>&1', $out, $code);
        if ($code !== 0) throw new RuntimeException('Error de sintaxis PHP: ' . implode(' ', $out));
    } finally {
        @unlink($temp);
    }
    echo "COMPATIBLE: Peticiones de Traspaso detectadas. Sin cambios de base de datos, permisos ni inventarios.\n";
    echo $hasCss ? "El asistente ya estaba enlazado (se verifican los archivos).\n" : "Se agregarán dos archivos estáticos y sus enlaces en la pantalla.\n";
    if ($accion === '--check') { echo "SOLO COMPROBACIÓN. No se modificó ningún archivo.\n"; exit(0); }
    if ($hasCss) { echo "LISTO: el asistente ya estaba instalado.\n"; exit(0); }
    $backup = $root . '/tools/backups_peticiones_wizard_' . date('Ymd_His');
    if (!is_dir($backup) && !mkdir($backup, 0775, true)) throw new RuntimeException('No se pudo crear la carpeta de respaldo.');
    if (!copy($destPhp, $backup . '/peticiones_traspasos.php')) throw new RuntimeException('No se pudo respaldar peticiones_traspasos.php.');
    $newFiles = [];
    try {
        foreach ($assets as $asset) {
            $dst = $root . '/public/' . $asset;
            if (is_file($dst)) continue;
            $tmpDst = $dst . '.tmp_' . bin2hex(random_bytes(4));
            if (!copy($payload . '/' . $asset, $tmpDst) || !rename($tmpDst, $dst)) {
                @unlink($tmpDst);
                throw new RuntimeException('No se pudo instalar public/' . $asset);
            }
            $newFiles[] = $dst;
        }
        $tempPhp = $destPhp . '.tmp_' . bin2hex(random_bytes(4));
        if (file_put_contents($tempPhp, $nuevo, LOCK_EX) === false || !rename($tempPhp, $destPhp)) {
            @unlink($tempPhp);
            throw new RuntimeException('No se pudo actualizar la pantalla de Peticiones.');
        }
    } catch (Throwable $e) {
        @copy($backup . '/peticiones_traspasos.php', $destPhp);
        foreach ($newFiles as $file) @unlink($file);
        throw $e;
    }
    echo "INSTALADO: asistente de tres pasos agregado a Peticiones de Traspaso.\n";
    echo "Respaldo: " . $backup . "\n";
    echo "Abre http://localhost/almacen-farmacia2/public/peticiones_traspasos.php y presiona Ctrl+F5.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'INCOMPATIBLE: ' . $e->getMessage() . "\n");
    exit(1);
}
