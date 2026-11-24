<?php
declare(strict_types=1);
/**
 * ERP G&D - Unificación reversible de recepción original y recepción con incidencias.
 * No crea ni modifica tablas, movimientos, facturas, costos, precios o existencias.
 * --check: inspección sin cambios; --apply: actualizar enlaces y delegar el formulario anterior.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Ejecuta desde la consola.'); }
$accion = $argv[1] ?? '--check';
if (!in_array($accion, ['--check', '--apply'], true)) {
    fwrite(STDERR, "Uso: php instalar_recepcion_unificada.php --check | --apply\n"); exit(2);
}
$root = dirname(__DIR__);
$targets = [
    'public/erp_recepciones_pendientes.php',
    'public/erp_recepcion_registrar.php',
    'public/erp_recepcion_calidad.php',
    'public/erp_recepciones_calidad_historial.php',
];
foreach ($targets as $relative) {
    if (!is_file($root . '/' . $relative)) {
        fwrite(STDERR, "INCOMPATIBLE: falta $relative. Instala antes Recepciones Parciales con Calidad.\n"); exit(1);
    }
}
$original = [];
foreach ($targets as $relative) $original[$relative] = (string)file_get_contents($root . '/' . $relative);

// Verificar que el formulario enriquecido guarda en la fuente canónica, no en otro inventario.
$enhanced = $original['public/erp_recepcion_calidad.php'];
foreach (['rq_validar_partida', 'erp_oc_recepciones_detalle', 'erp_oc_recepcion_incidencias', 'oc_calidad_csrf', 'solicitud_token', 'erpr_puede_registrar_destino'] as $needle) {
    if (!str_contains($enhanced, $needle)) {
        fwrite(STDERR, "INCOMPATIBLE: la recepción detallada no tiene la función requerida: $needle.\n"); exit(1);
    }
}
$pending = $original['public/erp_recepciones_pendientes.php'];
if (!str_contains($pending,'erp_recepcion_registrar.php?') && !str_contains($pending,'erp_recepcion_calidad.php?')) {
    fwrite(STDERR, "INCOMPATIBLE: no se encuentra acceso de recepción en bandeja. No se alteró el menú.\n"); exit(1);
}
$mod = $original;
// Un único botón, incluso si la instalación previa insertó la segunda opción con cambios de formato.
$legacyHref = 'erp_recepcion_registrar.php?id=<?=(int)$r[\'id\']?>';
$newHref = 'erp_recepcion_calidad.php?id=<?=(int)$r[\'id\']?>';
$pattern = '~<a\s+class="btn secondary"\s+href="erp_recepcion_calidad\.php\?id=<\?=\(int\)\$r\[\'id\'\]\?>"\s*>Registrar buenas y diferencias</a>~';
$count = 0;
$mod['public/erp_recepciones_pendientes.php'] = preg_replace($pattern, '', $pending, -1, $count) ?? $pending;
if ($count > 1) { fwrite(STDERR, "INCOMPATIBLE: se encontraron accesos duplicados inesperados.\n");exit(1); }
// Si solo estaba el botón nuevo, convertirlo a acceso único.
if (!str_contains($mod['public/erp_recepciones_pendientes.php'], $legacyHref)) {
    if (!str_contains($mod['public/erp_recepciones_pendientes.php'], $newHref)) {
        fwrite(STDERR, "INCOMPATIBLE: no se reconoció el enlace de recepción.\n");exit(1);
    }
    $mod['public/erp_recepciones_pendientes.php'] = str_replace(
        '<a class="btn secondary" href="'.$newHref.'">Registrar buenas y diferencias</a>',
        '<a class="btn" href="'.$legacyHref.'">Registrar recepción</a>',
        $mod['public/erp_recepciones_pendientes.php']
    );
}
// El formulario anterior ya no procesa datos: delega a la recepción con control de calidad.
// Se inserta antes de cualquier require/login para que no haya doble escritura.
$old = $original['public/erp_recepcion_registrar.php'];
$marker = 'ERP_GYD_RECEPCION_UNIFICADA_V1';
if (!str_contains($old, $marker)) {
    if (!preg_match('/^<\?php\s*declare\s*\(strict_types\s*=\s*1\);/D', substr($old, 0, 200))) {
        fwrite(STDERR, "INCOMPATIBLE: formato de recepción original desconocido. No se modificó.\n"); exit(1);
    }
    if (!str_contains($old, 'erp_oc_recepciones') || !str_contains($old, 'erpr_detalles')) {
        fwrite(STDERR, "INCOMPATIBLE: recepción original no reconocida.\n");exit(1);
    }
    $prepend = "\n// $marker: URL tradicional, formulario único de recepción con incidencias.\n".
        "require __DIR__ . '/erp_recepcion_calidad.php';\nexit;\n";
    $updated = preg_replace('/^(<\?php\s*declare\s*\(strict_types\s*=\s*1\);)/', '$1' . $prepend, $old, 1, $replacements);
    if ($replacements !== 1 || $updated === null) {fwrite(STDERR, "INCOMPATIBLE: no se pudo crear delegación segura.\n");exit(1);}
    $mod['public/erp_recepcion_registrar.php'] = $updated;
}
// La vista original de incidencias se conserva por compatibilidad con enlaces anteriores.
// Darle el mismo título público que al acceso único.
$mod['public/erp_recepcion_calidad.php'] = str_replace(
    ['<title>G&D · Recepción con incidencias</title>', 'G&D · Recepción física por entregas'],
    ['<title>G&D · Registrar recepción</title>', 'G&D · Registrar recepción'],
    $enhanced
);
// Al abrir el historial detallado, la siguiente entrega vuelve al acceso oficial único.
$mod['public/erp_recepciones_calidad_historial.php'] = str_replace(
    'href="erp_recepcion_calidad.php?id=<?=$ocId?>"',
    'href="erp_recepcion_registrar.php?id=<?=$ocId?>"',
    $original['public/erp_recepciones_calidad_historial.php']
);
// Validar que el resultado contiene únicamente una acción visible por OC.
$resultPending = $mod['public/erp_recepciones_pendientes.php'];
if (str_contains($resultPending,'Registrar buenas y diferencias') || substr_count($resultPending, $legacyHref) !== 2) {
    // Normalmente hay dos referencias: una para Registrar, otra para Consultar en el mismo ternario.
    // Si la estructura fue personalizada, detenerse en lugar de sobreescribir a ciegas.
    fwrite(STDERR, "INCOMPATIBLE: estructura de botones distinta a la versión G&D esperada.\n");exit(1);
}
if (str_contains($resultPending, 'href="'.$newHref.'"')) {
    fwrite(STDERR, "INCOMPATIBLE: queda un segundo acceso directo a recepción.\n");exit(1);
}
$changes = [];
foreach($targets as $rel) if($mod[$rel] !== $original[$rel]) $changes[]=$rel;

// No se pide BD para --check porque no se modifican tablas; se recomienda validar entorno al aplicar.
if ($accion === '--check') {
    echo "COMPATIBLE (sin escrituras).\n";
    echo "Archivos por actualizar: ".count($changes)."\n";
    foreach($changes as $rel) echo "  - $rel\n";
    echo "Único botón: Registrar recepción. La misma pantalla acepta buenas, incidencias y complementos.\n";
    exit(0);
}
// Restringir el parche al entorno de prueba existente, sin credenciales ni datos operativos.
require_once $root.'/app/config/database.php';
try {
    $pdo = (new Database())->connect();
    $dbname = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if (!preg_match('/prueba|_lab|laboratorio|test/i', $dbname)) throw new RuntimeException("La base seleccionada ($dbname) no parece de prueba.");
    $q=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='erp_oc_recepcion_incidencias'");
    if ((int)$q->fetchColumn() < 1) throw new RuntimeException('Falta la tabla de incidencias: instala antes recepciones con calidad.');
} catch (Throwable $e) {
    fwrite(STDERR, "INCOMPATIBLE con la base de pruebas: ".$e->getMessage()."\n");exit(1);
}
if (!$changes) {echo "YA INSTALADO. Ningún archivo necesita cambios.\n";exit(0);}
$dir = __DIR__.'/respaldos_recepcion_unificada_'.date('Ymd_His');
if (!mkdir($dir,0775,true)) {fwrite(STDERR,"ERROR: no se pudo crear respaldo.\n");exit(1);}
$backups=[];$temps=[];
try {
    foreach ($changes as $rel) {
        $src=$root.'/'.$rel;
        $b=$dir.'/'.str_replace('/','__',$rel);
        if (!copy($src,$b)) throw new RuntimeException("No se pudo respaldar $rel");
        $backups[$src]=$b;
    }
    foreach($changes as $rel) {
        $src=$root.'/'.$rel;
        $tmp=$src.'.fusiontmp.'.getmypid();
        if (file_put_contents($tmp, $mod[$rel], LOCK_EX) === false) throw new RuntimeException("No se pudo escribir temporal $rel");
        $temps[]=$tmp;
        if (!rename($tmp,$src)) throw new RuntimeException("No se pudo actualizar $rel");
    }
    echo "INSTALADO. Un solo acceso y un solo motor de registro.\n";
    echo "Respaldos: $dir\n";
    echo "Abrir: http://localhost/almacen-farmacia2/public/erp_recepciones_pendientes.php\n";
} catch (Throwable $e) {
    foreach ($backups as $src=>$b) copy($b,$src);
    foreach($temps as $tmp) if (is_file($tmp)) unlink($tmp);
    fwrite(STDERR, 'ERROR: '.$e->getMessage().". Archivos restaurados.\n");exit(1);
}
