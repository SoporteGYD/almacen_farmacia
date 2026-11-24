<?php
/**
 * Instalador seguro de G&D: F1 proteccion CSRF de Usuarios.
 * --check: solo lectura. --apply: respaldo y parche verificando sintaxis.
 * Rechaza cualquier estructura desconocida, no modifica la base de datos.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo consola.'); }
$modo = $argv[1] ?? '';
if (!in_array($modo, ['--check', '--apply'], true) || count($argv) !== 2) {
    fwrite(STDERR, "Uso: php tools/instalar_f1_seguridad_usuarios.php --check | --apply\n");
    exit(2);
}
$raiz = realpath(dirname(__DIR__));
if (!$raiz) { fwrite(STDERR, "No se identifico la raiz del ERP.\n"); exit(2); }
$ruta = $raiz . '/public/usuario.php';
$rutaAuth = $raiz . '/app/helpers/auth.php';
$rutaHelper = $raiz . '/app/helpers/gyd_f1_usuarios_csrf.php';
$fuenteHelper = __DIR__ . '/_f1_usuarios_csrf_payload/app/helpers/gyd_f1_usuarios_csrf.php';
$marca = 'GYD_F1_SEGURIDAD_USUARIOS_CSRF';
$errores = [];
if (PHP_VERSION_ID < 80100) $errores[] = 'Se necesita PHP 8.1 o superior.';
foreach ([$ruta, $rutaAuth, $fuenteHelper] as $archivo) if (!is_file($archivo)) $errores[] = 'Falta archivo requerido: ' . $archivo;
$original = is_file($ruta) ? (string)file_get_contents($ruta) : '';
$actualizado = $original;
$instalado = str_contains($original, $marca);
if (!$instalado && $original !== '') {
    // No reemplazar una proteccion CSRF ajena sin verificar como funciona.
    if (preg_match('~name\s*=\s*["\'](?:csrf|csrf_token|_token|gyd_f1_csrf)["\']~i', $original)) {
        $errores[] = 'Usuarios ya contiene otro token CSRF. Se requiere revision manual.';
    }
    $ancla = '$controller->verificarAdmin();';
    $post = "if (\$_SERVER['REQUEST_METHOD'] === 'POST') {";
    foreach ([$ancla, $post] as $texto) {
        if (substr_count($original, $texto) !== 1) $errores[] = 'No se encontro punto unico de integracion: ' . $texto;
    }
    foreach (['crear', 'editar', 'password', 'estado'] as $accion) {
        if (!preg_match('~name=["\']accion["\']\s+value=["\']' . $accion . '["\']~i', $original)) {
            $errores[] = 'No se reconocio el formulario de la accion: ' . $accion;
        }
    }
    preg_match_all('~<form\b[^>]*>~i', $original, $forms);
    $cantidadPost = 0;
    foreach ($forms[0] as $form) {
        if (preg_match('~\bmethod\s*=\s*(?:["\']post["\']|post)(?=\s|>)~i', $form)) $cantidadPost++;
    }
    if ($cantidadPost < 4) $errores[] = 'Se esperaban al menos cuatro formularios POST de usuarios.';
    if (!$errores) {
        $inicio = "\n// " . $marca . "\nrequire_once __DIR__ . '/../app/helpers/gyd_f1_usuarios_csrf.php';\n\$gydF1TokenUsuarios = gyd_f1_usuarios_csrf_token();\n";
        $actualizado = str_replace($ancla, $ancla . $inicio, $actualizado);
        $guardia = "\n    // " . $marca . ": bloquear POST sin token, antes de modificar usuarios.\n    if (!gyd_f1_usuarios_csrf_valido(\$_POST['gyd_f1_csrf'] ?? null)) {\n        http_response_code(403);\n        header('Cache-Control: no-store');\n        exit('Solicitud no autorizada o formulario caducado. Vuelve a abrir Usuarios.');\n    }";
        $actualizado = str_replace($post, $post . $guardia, $actualizado);
        $agregados = 0;
        $actualizado = preg_replace_callback('~<form\b[^>]*>~i', function (array $m) use (&$agregados): string {
            if (!preg_match('~\bmethod\s*=\s*(?:["\']post["\']|post)(?=\s|>)~i', $m[0])) return $m[0];
            $agregados++;
            return $m[0] . "\n        <input type=\"hidden\" name=\"gyd_f1_csrf\" value=\"<?= htmlspecialchars(\$gydF1TokenUsuarios, ENT_QUOTES, 'UTF-8') ?>\">";
        }, $actualizado);
        if ($actualizado === null || $agregados !== $cantidadPost) $errores[] = 'Error al agregar tokens a todos los formularios POST.';
        if (substr_count($actualizado, 'gyd_f1_usuarios_csrf_valido(') !== 1) $errores[] = 'No se integro una unica validacion de seguridad.';
    }
} elseif ($instalado) {
    if (substr_count($original, 'gyd_f1_usuarios_csrf_valido(') !== 1 ||
        !str_contains($original, 'name="gyd_f1_csrf"') ||
        !str_contains($original, 'gyd_f1_usuarios_csrf_token()')) {
        $errores[] = 'Se detecto instalacion de seguridad incompleta. No se sobrescribira.';
    }
}
if ($instalado && !is_file($rutaHelper)) {
    $errores[] = 'Se encontro una instalacion parcial sin helper. No se alterara el modulo.';
}
if (is_file($rutaHelper) && is_file($fuenteHelper) && hash_file('sha256',$rutaHelper) !== hash_file('sha256',$fuenteHelper)) {
    $errores[] = 'El helper ya existe con otro contenido. No se reemplazara.';
}
function validarSintaxisGyd(string $php): ?string {
    $temp = tempnam(sys_get_temp_dir(), 'gyd_f1_chk_');
    if ($temp === false || file_put_contents($temp, $php) === false) return 'No fue posible crear archivo temporal de revision.';
    $salida = [];$codigo = 1;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($temp) . ' 2>&1', $salida, $codigo);
    @unlink($temp);
    return $codigo === 0 ? null : implode(' ', $salida);
}
if (!$errores && $actualizado !== '') {
    $err = validarSintaxisGyd($actualizado);
    if ($err !== null) $errores[] = 'Sintaxis de Usuarios: ' . $err;
    $err = validarSintaxisGyd((string)file_get_contents($fuenteHelper));
    if ($err !== null) $errores[] = 'Sintaxis del helper: ' . $err;
}
if ($errores) {
    foreach ($errores as $error) fwrite(STDERR, 'INCOMPATIBLE: ' . $error . PHP_EOL);
    echo "No se modifico ningun archivo ni la base de datos.\n";
    exit(1);
}
if ($instalado && is_file($rutaHelper)) {
    echo "COMPATIBLE: proteccion CSRF de Usuarios ya instalada y verificada.\n";
    exit(0);
}
echo 'COMPATIBLE: ' . $cantidadPost . " formularios POST protegidos; archivos SQL a modificar: 0.\n";
if ($modo === '--check') { echo "Solo lectura. Haz un respaldo antes de --apply.\n"; exit(0); }
$dir = $raiz . '/tools/backups_f1_seguridad_usuarios';
if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
    fwrite(STDERR, "ERROR: No se pudo crear carpeta de respaldo.\n"); exit(1);
}
$deny = $dir . '/.htaccess';
if (!is_file($deny)) file_put_contents($deny, "Require all denied\nDeny from all\n");
$backup = $dir . '/usuario_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.php.bak';
if (!copy($ruta, $backup)) { fwrite(STDERR, "ERROR: No fue posible respaldar usuario.php.\n"); exit(1); }
@chmod($backup, 0600);
$creado = false;
try {
    if (!is_file($rutaHelper)) {
        $tmpHelper = $rutaHelper . '.tmp_' . bin2hex(random_bytes(4));
        if (!copy($fuenteHelper, $tmpHelper) || !rename($tmpHelper, $rutaHelper)) throw new RuntimeException('Fallo al crear el helper.');
        $creado = true;
    }
    $temporal = $ruta . '.tmp_' . bin2hex(random_bytes(4));
    if (file_put_contents($temporal, $actualizado) === false || !rename($temporal, $ruta)) throw new RuntimeException('Fallo al reemplazar usuario.php.');
    echo "INSTALADO: Formularios de Usuarios con proteccion CSRF.\n";
    echo "Respaldo: " . $backup . "\n";
    echo "No se modificaron datos de la base ni roles.\n";
} catch (Throwable $ex) {
    copy($backup, $ruta);
    if ($creado) @unlink($rutaHelper);
    fwrite(STDERR, 'ERROR: ' . $ex->getMessage() . "; se intento revertir usuario.php.\n");
    exit(1);
}
