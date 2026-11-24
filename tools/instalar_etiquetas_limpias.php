<?php
/**
 * Farmacia G&D — Limpieza visual de las versiones de módulos.
 * Solo cambia etiquetas visibles. No toca rutas, estructuras, funciones, permisos ni SQL.
 * php tools/instalar_etiquetas_limpias.php --check | --apply
 */
declare(strict_types=1);

$mode = $argv[1] ?? '--check';
if (!in_array($mode, ['--check', '--apply'], true)) {
    fwrite(STDERR, "Uso: php tools/instalar_etiquetas_limpias.php --check | --apply\n");
    exit(2);
}

$root = dirname(__DIR__);
$rules = [
    'app/views/layouts/header.php' => [
        ['Auditoría y Control de Cajas · V12', 'Auditoría y Control de Cajas'],
    ],
    'public/erp_auditoria_cajas_v12.php' => [
        ['<title>G&D · Auditoría y Control de Cajas V12</title>', '<title>G&D · Auditoría y Control de Cajas</title>'],
        ['Farmacia G&D · V12 · Solo lectura · Base de pruebas', 'Farmacia G&D · Solo lectura · Base de pruebas'],
        ['Este módulo V12 únicamente funciona', 'Este módulo únicamente funciona'],
    ],
    'public/pos_turnos_v13.php' => [
        ['<title>G&D | Control de turnos V13</title>', '<title>G&D | Control de turnos</title>'],
        ['<h1>Control de Apertura y Turnos · V13</h1>', '<h1>Control de Apertura y Turnos</h1>'],
    ],
    'public/pos_turnos_v13_api.php' => [
        ['Revisa la instalación V13 y los registros de PHP.', 'Revisa la instalación y los registros de PHP.'],
    ],
];

// Si Control de Turnos todavía no está instalado, limpia Auditoría ahora.
// Vuelve a ejecutar este instalador después de instalar Control de Turnos.
$turnosPresentes = is_file($root.'/public/pos_turnos_v13.php') || is_file($root.'/public/pos_turnos_v13_api.php');
if (!$turnosPresentes) {
    unset($rules['public/pos_turnos_v13.php'], $rules['public/pos_turnos_v13_api.php']);
    echo "AVISO: Control de Turnos no está instalado; se omiten sus etiquetas por ahora.\n";
}
$updates = [];
$errors = [];
$already = 0;
$replacements = 0;
foreach ($rules as $relative => $pairs) {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        $errors[] = "No se encontró {$relative}. Confirma que Auditoría y Control de Turnos estén instalados.";
        continue;
    }
    $original = file_get_contents($path);
    if ($original === false) {
        $errors[] = "No se pudo leer {$relative}.";
        continue;
    }
    $updated = $original;
    foreach ($pairs as [$from, $to]) {
        $oldCount = substr_count($updated, $from);
        $newCount = substr_count(str_replace($from, '', $updated), $to);
        if ($oldCount === 1 && $newCount === 0) {
            $updated = str_replace($from, $to, $updated);
            $replacements++;
        } elseif ($oldCount === 0 && $newCount === 1) {
            $already++;
        } else {
            $errors[] = "Etiqueta desconocida o repetida en {$relative}: " . mb_safe($from) . " (anterior={$oldCount}, nueva={$newCount}).";
        }
    }
    if ($updated !== $original) $updates[$relative] = ['old'=>$original,'new'=>$updated];
}
function mb_safe(string $value): string { return strlen($value)>90 ? substr($value,0,90).'...' : $value; }

if ($errors) {
    foreach ($errors as $e) fwrite(STDERR, "INCOMPATIBLE: {$e}\n");
    fwrite(STDERR, "No se cambió ningún archivo.\n");
    exit(1);
}
if ($mode === '--check') {
    echo "COMPATIBLE: ".count($updates)." archivos por actualizar, {$replacements} etiquetas por limpiar, {$already} ya limpias.\n";
    echo "Solo modifica texto visible; conserva rutas con sufijos V12/V13 y todo el comportamiento del ERP.\n";
    exit(0);
}
if (!$updates) {
    echo "Las etiquetas ya están limpias. No se modificó ningún archivo.\n";
    exit(0);
}

$backupDir=$root.'/backups_gyd_etiquetas_limpias_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
if (!mkdir($backupDir, 0750, true)) {
    fwrite(STDERR, "No se pudo crear el directorio de respaldo. No se cambió nada.\n");
    exit(1);
}
$staged=[];
try {
    foreach ($updates as $rel=>$pair) {
        $dst=$root.'/'.$rel;
        $back=$backupDir.'/'.$rel;
        if (!is_dir(dirname($back)) && !mkdir(dirname($back),0750,true) && !is_dir(dirname($back))) throw new RuntimeException('Error creando respaldo de '.$rel);
        if (!copy($dst,$back)) throw new RuntimeException('Error guardando respaldo de '.$rel);
        $temp=$dst.'.labels_tmp_'.bin2hex(random_bytes(4));
        if (file_put_contents($temp,$pair['new'],LOCK_EX)===false) throw new RuntimeException('Error escribiendo archivo temporal de '.$rel);
        $permissions = fileperms($dst);
        if ($permissions!==false) chmod($temp,$permissions & 0777);
        $staged[$rel]=$temp;
    }
    $done=[];
    foreach ($staged as $rel=>$temp) {
        $dst=$root.'/'.$rel;
        if (!rename($temp,$dst)) throw new RuntimeException('No se pudo instalar '.$rel);
        $done[]=$rel;
    }
    echo "INSTALADO: ".count($done)." archivos actualizados; {$replacements} etiquetas sin números de versión.\n";
    echo "RESPALDO: {$backupDir}\n";
    echo "Actualiza tu navegador con Ctrl+F5. Sin cambios de MySQL.\n";
} catch (Throwable $e) {
    foreach ($updates as $rel=>$pair) {
        $backup=$backupDir.'/'.$rel;
        if (is_file($backup)) copy($backup,$root.'/'.$rel);
    }
    foreach ($staged as $temp) { if (is_file($temp)) unlink($temp); }
    fwrite(STDERR, "No se pudo instalar: ".$e->getMessage().". Se intentó restaurar los archivos desde el respaldo.\n");
    exit(1);
}
