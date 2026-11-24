<?php
declare(strict_types=1);
/**
 * ERP G&D — Ajuste visual para el panel de revisión de Órdenes de compra.
 * Actualiza SOLO public/assets/js/erp_oc_validacion.js y preserva su función revisar().
 * No se expone a través del navegador: se ejecuta únicamente mediante PHP CLI.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Acceso restringido.'); }
$mode = $argv[1] ?? '';
if (!in_array($mode, ['--check', '--apply'], true)) {
    fwrite(STDERR, "Uso: php tools/instalar_oc_validaciones_compactas.php --check|--apply\n");
    exit(2);
}
$root = dirname(__DIR__);
$page = $root.'/public/erp_oc_win.php';
$target = $root.'/public/assets/js/erp_oc_validacion.js';
$oldFragPath = __DIR__.'/oc_revision_presentacion_anterior.txt';
$newFragPath = __DIR__.'/oc_revision_presentacion_compacta.txt';
try {
    if (!is_file($page) || !is_file($target)) {
        throw new RuntimeException('INCOMPATIBLE: falta el módulo de OC o su validador JavaScript. Instala primero la validación inteligente.');
    }
    $php = file_get_contents($page);
    $source = file_get_contents($target);
    $old = file_get_contents($oldFragPath);
    $new = file_get_contents($newFragPath);
    if (!is_string($php) || !is_string($source) || !is_string($old) || !is_string($new)) {
        throw new RuntimeException('No se pudieron leer los archivos del módulo o del parche.');
    }
    if (!str_contains($php, 'id="ocRevisionPanel"') ||
        !str_contains($php, 'id="ocRevisionTitulo"') ||
        !str_contains($php, 'id="ocRevisionLista"') ||
        !str_contains($php, 'assets/js/erp_oc_validacion.js')) {
        throw new RuntimeException('INCOMPATIBLE: no existe la estructura de revisión esperada en erp_oc_win.php.');
    }
    if (!str_contains($source, 'window.gydOcRevisionEvaluar = revisar;') ||
        !str_contains($source, 'window.gydOcRevisionMostrar = mostrar;') ||
        !str_contains($source, 'function revisar()')) {
        throw new RuntimeException('INCOMPATIBLE: no se reconoce el validador. No se modificó nada.');
    }
    if (!str_contains($new, 'GYD_OC_VALIDACION_COMPACTA_V1') ||
        !str_contains($old, 'function mostrar(resultado) {')) {
        throw new RuntimeException('El paquete está incompleto o incorrecto.');
    }
    $already = substr_count($source, 'GYD_OC_VALIDACION_COMPACTA_V1') === 1;
    if ($already) {
        if (substr_count($source, $new) !== 1 || substr_count($source, $old) !== 0) {
            throw new RuntimeException('INCOMPATIBLE: se detectaron cambios adicionales en el panel compacto.');
        }
        echo "YA APLICADO: validaciones compactas. No hay archivos pendientes.\n";
        exit(0);
    }
    if (substr_count($source, $old) !== 1) {
        throw new RuntimeException('INCOMPATIBLE: esta versión del panel no coincide con la versión validada. No se reemplazó nada.');
    }
    $patched = str_replace($old, $new, $source);
    if ($patched === $source ||
        substr_count($patched, 'GYD_OC_VALIDACION_COMPACTA_V1') !== 1 ||
        !str_contains($patched, 'function revisar()') ||
        !str_contains($patched, "form.addEventListener('input'")) {
        throw new RuntimeException('Error preparando el parche; no se modificó nada.');
    }
    echo "COMPATIBLE: 1 archivo JavaScript pendiente, solo cambia la presentación de las revisiones.\n";
    if ($mode === '--check') {
        echo "COMPROBADO: no se modificó ningún archivo.\n";
        exit(0);
    }
    $backup = $target.'.backup_oc_validaciones_compactas_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
    if (!copy($target, $backup)) throw new RuntimeException('No se pudo crear respaldo del JavaScript.');
    $written = @file_put_contents($target, $patched, LOCK_EX);
    if ($written === false || $written !== strlen($patched)) {
        @copy($backup, $target);
        throw new RuntimeException('Fallo de escritura. Se intentó restaurar el respaldo.');
    }
    if (hash_file('sha256', $target) !== hash('sha256', $patched)) {
        @copy($backup, $target);
        throw new RuntimeException('No coincide la verificación final. Se intentó restaurar el respaldo.');
    }
    echo "ACTUALIZADO: panel compacto de validaciones.\n";
    echo "RESPALDO: ".$backup."\n";
    echo "SIN CAMBIOS: IVA, TXT/CSV/Excel/PDF, cálculos, registro, base de datos e inventario.\n";
    echo "Abre el formulario y actualiza con Ctrl+F5.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "DETENIDO: ".$ex->getMessage()."\n");
    exit(1);
}
