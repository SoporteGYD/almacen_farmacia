<?php
declare(strict_types=1);
/** ERP G&D: instalador incremental de validación de órdenes. Solo CLI. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Acceso restringido.'); }
$mode = $argv[1] ?? '';
if (!in_array($mode, ['--check','--apply'], true)) {
    fwrite(STDERR, "Uso: php tools/instalar_oc_validacion_inteligente.php --check|--apply\n");
    exit(2);
}
$root = dirname(__DIR__);
$planPath = __DIR__.'/oc_validacion_plan.json';
$jsSource = __DIR__.'/erp_oc_validacion.js';
$jsTarget = $root.'/public/assets/js/erp_oc_validacion.js';
$changes = [];
$backups = [];
$committed = [];
$createdDirs = [];
try {
    $rules = json_decode((string)file_get_contents($planPath), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($rules) || count($rules) !== 2) throw new RuntimeException('El plan de cambios no es válido.');
    foreach ($rules as $relative => $steps) {
        if (!in_array($relative, ['public/erp_oc_win.php','app/helpers/erp_oc_importar.php'], true)) {
            throw new RuntimeException('Ruta no autorizada: '.$relative);
        }
        $path = $root.'/'.$relative;
        if (!is_file($path)) throw new RuntimeException('INCOMPATIBLE: falta '.$relative);
        $before = file_get_contents($path);
        if (!is_string($before)) throw new RuntimeException('No se pudo leer '.$relative);
        $current = $before;
        foreach ($steps as $n => $step) {
            $from = $step['before'] ?? null;
            $to = $step['after'] ?? null;
            if (!is_string($from) || $from === '' || !is_string($to)) throw new RuntimeException('Regla incorrecta en '.$relative);
            $oldCount = substr_count($current, $from);
            $newCount = substr_count($current, $to);
            if ($newCount === 1) { /* Ya aplicada; algunas firmas nuevas incluyen la antigua. */ }
            elseif ($oldCount === 1) $current = str_replace($from, $to, $current);
            else throw new RuntimeException('INCOMPATIBLE en '.$relative.' (regla '.($n + 1).'). No se modificó nada. Adjunta el ZIP actualizado para adaptar el parche.');
        }
        $changes[$path] = ['old'=>$before, 'new'=>$current, 'relative'=>$relative];
    }
    if (!str_contains($changes[$root.'/public/erp_oc_win.php']['new'], 'window.gydOcRevisionEvaluar') ||
        !str_contains($changes[$root.'/public/erp_oc_win.php']['new'], 'id="ocImportFile"') ||
        !str_contains($changes[$root.'/app/helpers/erp_oc_importar.php']['new'], "'costo_catalogo'")) {
        throw new RuntimeException('La revisión de compatibilidad del proyecto falló.');
    }
    foreach ($changes as $change) {
        $tmp = tempnam(sys_get_temp_dir(), 'oclint_');
        if ($tmp === false) throw new RuntimeException('No se pudo preparar la validación PHP.');
        try {
            if (file_put_contents($tmp, $change['new']) === false) throw new RuntimeException('No se pudo preparar prueba de sintaxis.');
            exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1', $output, $rc);
            if ($rc !== 0) throw new RuntimeException('Error de sintaxis en '.$change['relative'].': '.implode(' ', $output));
        } finally { @unlink($tmp); }
    }
    $js = file_get_contents($jsSource);
    if (!is_string($js) || !str_contains($js, 'window.gydOcRevisionEvaluar')) throw new RuntimeException('Falta el JavaScript del parche.');
    $jsExists = is_file($jsTarget);
    if ($jsExists && file_get_contents($jsTarget) !== $js) {
        throw new RuntimeException('INCOMPATIBLE: ya existe un JavaScript de validación diferente en '.$jsTarget.'. No se sobrescribió.');
    }
    $pending = 0;
    foreach ($changes as $change) if ($change['old'] !== $change['new']) $pending++;
    if (!$jsExists) $pending++;
    echo $pending ? "CAMBIOS PENDIENTES: $pending archivo(s).\n" : "YA APLICADO: validación inteligente.\n";
    if ($mode === '--check') { echo "COMPROBADO; no se modificó nada.\n"; exit(0); }
    if (!$pending) { echo "No había cambios pendientes.\n"; exit(0); }
    // Primero se respaldan todos los archivos antes de modificar cualquiera.
    foreach ($changes as $path => $change) {
        if ($change['old'] === $change['new']) continue;
        $backup = $path.'.backup_validacion_oc_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
        if (!copy($path, $backup)) throw new RuntimeException('No se pudo crear respaldo de '.$change['relative']);
        $backups[$path] = $backup;
    }
    // Se instala primero el script: aunque haya cache, la página actual seguirá funcionando.
    if (!$jsExists) {
        $dir = dirname($jsTarget);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('No fue posible crear '.$dir);
        if (file_put_contents($jsTarget, $js, LOCK_EX) === false) throw new RuntimeException('No se pudo instalar JavaScript.');
        $committed[] = $jsTarget;
    }
    foreach ($changes as $path => $change) {
        if ($change['old'] === $change['new']) continue;
        if (file_put_contents($path, $change['new'], LOCK_EX) === false) throw new RuntimeException('No se pudo actualizar '.$change['relative']);
        $committed[] = $path;
    }
    echo "ACTUALIZADO: validación previa de OC.\n";
    foreach ($backups as $backup) echo 'Respaldo: '.$backup."\n";
    echo "Sin migraciones ni modificaciones de datos o existencias.\n";
} catch (Throwable $e) {
    foreach (array_reverse($committed) as $path) {
        if (isset($backups[$path])) @copy($backups[$path], $path);
        elseif ($path === $jsTarget && !$jsExists) @unlink($path);
    }
    fwrite(STDERR, 'DETENIDO: '.$e->getMessage()."\n");
    exit(1);
}
