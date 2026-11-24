<?php
/** Comprobación opcional CLI de base activa; nunca ejecuta escrituras. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo CLI.'); }
$root = dirname(__DIR__);
require_once $root . '/app/config/database.php';
$esperada = 'prueba';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--expect=')) $esperada = substr($arg, 9);
}
if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', $esperada)) {
    fwrite(STDERR, "ERROR: --expect inválido.\n"); exit(2);
}
try {
    $pdo=(new Database())->connect();
    $activa=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $puerto=(int)$pdo->query('SELECT @@port')->fetchColumn();
    printf("Base activa del ERP: %s | Puerto: %d\n", $activa, $puerto);
    if (!hash_equals($esperada, $activa)) {
        fwrite(STDERR, "BLOQUEADO: la base activa NO coincide con --expect. No se modificó nada.\n");
        exit(1);
    }
    echo "OK: destino confirmado; no se ejecutaron cambios.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: no se pudo verificar la conexión. No se modificó nada.\n");
    exit(2);
}
