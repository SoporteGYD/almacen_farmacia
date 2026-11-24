<?php
declare(strict_types=1);

// Ajuste limitado al modal de edición. No modifica CSRF, roles ni MySQL.
$root = realpath(__DIR__ . '/..');
if ($root === false) { fwrite(STDERR, "ERROR: raiz no encontrada.\n"); exit(2); }
$target = $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'usuario.php';
$mode = $argv[1] ?? '';
if (!in_array($mode, ['--check', '--apply'], true) || count($argv) !== 2) {
    fwrite(STDERR, "Uso: php tools/instalar_f1_usuarios_modal_seguro.php --check|--apply\n"); exit(2);
}
if (!is_file($target) || !is_readable($target)) {
    fwrite(STDERR, "INCOMPATIBLE: public/usuario.php no existe o no se puede leer.\n"); exit(1);
}
$source = file_get_contents($target);
if ($source === false) { fwrite(STDERR, "INCOMPATIBLE: no se pudo leer usuario.php.\n"); exit(1); }
$eol = str_contains($source, "\r\n") ? "\r\n" : "\n";
$normalized = str_replace("\r\n", "\n", $source);

// Exigir el contrato de seguridad recibido del usuario antes de cambiar algo.
$checks = [
    'requireLogin()' => str_contains($normalized, 'requireLogin();'),
    'verificarAdmin()' => str_contains($normalized, '$controller->verificarAdmin();'),
    'token de sesion' => str_contains($normalized, "\$_SESSION['gyd_usuarios_csrf']"),
    'verificacion CSRF en POST' => str_contains($normalized, 'hash_equals($csrfUsuarios'),
    'cuatro campos CSRF' => substr_count($normalized, 'name="csrf"') === 4,
    'control de crear/editar/password/estado' => array_reduce(['crear','editar','password','estado'], static fn($a,$v) => $a && str_contains($normalized, "\$accion === '".$v."'"), true),
];
foreach ($checks as $label => $ok) {
    if (!$ok) { fwrite(STDERR, "INCOMPATIBLE: validacion de seguridad ausente o modificada: $label. No se cambio nada.\n"); exit(1); }
}
if (str_contains($normalized, 'class="btn-usuario btn-edit js-editar-usuario"') && str_contains($normalized, "document.querySelectorAll('.js-editar-usuario')")) {
    echo "YA INSTALADO: el modal usa atributos seguros; CSRF original conservado.\n";
    exit(0);
}
$old = <<<'OLD'
                            <button 
                                type="button"
                                class="btn-usuario btn-edit"
                                onclick="abrirModalEditar(
                                    '<?= (int)$usuario['id'] ?>',
                                    '<?= e($usuario['nombre']) ?>',
                                    '<?= e($usuario['usuario']) ?>',
                                    '<?= e($usuario['correo']) ?>',
                                    '<?= e($usuario['rol']) ?>',
                                    '<?= (int)($usuario['almacen_id'] ?? 0) ?>', '<?= (int)($usuario['pos_sucursal_id']??0) ?>', '<?= (int)($usuario['pos_caja_id']??0) ?>'
                                )"
                            >
OLD;
$new = <<<'NEW'
                            <button
                                type="button"
                                class="btn-usuario btn-edit js-editar-usuario"
                                data-id="<?= (int)$usuario['id'] ?>"
                                data-nombre="<?= htmlspecialchars((string)($usuario['nombre'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                data-usuario="<?= htmlspecialchars((string)($usuario['usuario'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                data-correo="<?= htmlspecialchars((string)($usuario['correo'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                data-rol="<?= htmlspecialchars((string)($usuario['rol'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                data-almacen-id="<?= (int)($usuario['almacen_id'] ?? 0) ?>"
                                data-pos-sucursal-id="<?= (int)($usuario['pos_sucursal_id'] ?? 0) ?>"
                                data-pos-caja-id="<?= (int)($usuario['pos_caja_id'] ?? 0) ?>"
                            >
NEW;
$handler = <<<'SCRIPT'
// Los datos de usuarios se leen como texto: nunca se interpretan como JavaScript.
document.querySelectorAll('.js-editar-usuario').forEach((boton) => {
    boton.addEventListener('click', () => {
        const d = boton.dataset;
        abrirModalEditar(
            d.id,
            d.nombre,
            d.usuario,
            d.correo,
            d.rol,
            Number(d.almacenId || 0),
            Number(d.posSucursalId || 0),
            Number(d.posCajaId || 0)
        );
    });
});
SCRIPT;

if (substr_count($normalized, $old) !== 1 || substr_count($normalized, 'function abrirModalEditar(') !== 1 || str_contains($normalized, 'js-editar-usuario')) {
    fwrite(STDERR, "INCOMPATIBLE: el boton Editar ha cambiado. No se sobrescribira una version diferente.\n"); exit(1);
}
$patched = str_replace($old, $new, $normalized);
$patched = str_replace('function abrirModalEditar(', $handler . "\n" . 'function abrirModalEditar(', $patched);
if ($patched === $normalized) { fwrite(STDERR, "ERROR: no hubo cambios.\n"); exit(1); }
$patched = ($eol === "\r\n") ? str_replace("\n", "\r\n", $patched) : $patched;

// Validar sintaxis del nuevo PHP antes de tocar la pantalla actual.
$tmp = tempnam(sys_get_temp_dir(), 'gyd_user_');
if ($tmp === false) { fwrite(STDERR, "ERROR: sin directorio temporal.\n"); exit(2); }
file_put_contents($tmp, $patched, LOCK_EX);
$cmd = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1';
$lint = [];
$code = 0;
exec($cmd, $lint, $code);
@unlink($tmp);
if ($code !== 0) { fwrite(STDERR, "INCOMPATIBLE: PHP lint fallo: " . implode(' | ', $lint) . "\n"); exit(1); }

if ($mode === '--check') {
    echo "COMPATIBLE: se cambiara SOLO la apertura del modal Editar en public/usuario.php.\n";
    echo "CSRF y permisos ya existentes: CONSERVADOS. Base de datos: SIN CAMBIOS.\n";
    echo "Sintaxis PHP resultante: OK. Ejecuta --apply tras hacer respaldo.\n";
    exit(0);
}

// Resguardo fuera de htdocs, nunca dentro de una URL publica.
$backupFolder = dirname(dirname($root)) . DIRECTORY_SEPARATOR . 'gyd_resguardos_codigo' . DIRECTORY_SEPARATOR . 'f1' . DIRECTORY_SEPARATOR . 'usuarios';
if (!is_dir($backupFolder) && !mkdir($backupFolder, 0700, true) && !is_dir($backupFolder)) {
    fwrite(STDERR, "ERROR: no fue posible crear el directorio de respaldos.\n"); exit(1);
}
$backup = $backupFolder . DIRECTORY_SEPARATOR . 'usuario.php.' . date('Ymd_His') . '.' . bin2hex(random_bytes(4)) . '.bak';
if (!copy($target, $backup)) { fwrite(STDERR, "ERROR: no se pudo respaldar usuario.php.\n"); exit(1); }
if (!is_writable($target)) { fwrite(STDERR, "ERROR: usuario.php no es escribible. Respaldo: $backup\n"); exit(1); }
$written = file_put_contents($target, $patched, LOCK_EX);
if ($written === false || $written !== strlen($patched)) {
    copy($backup, $target);
    fwrite(STDERR, "ERROR: escritura incompleta; se intento restaurar el archivo anterior. Respaldo: $backup\n"); exit(1);
}
echo "INSTALADO: modal Editar con datos seguros.\n";
echo "Respaldo: $backup\n";
echo "No se cambio CSRF, usuario/controller, permisos, roles ni MySQL.\n";
