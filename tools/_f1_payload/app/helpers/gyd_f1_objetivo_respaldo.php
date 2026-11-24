<?php
/**
 * G&D: impide respaldar una base distinta de la utilizada por la conexión PDO.
 * No contiene contraseñas, no altera tablas ni cambia la base configurada.
 */
declare(strict_types=1);

function gyd_f1_comparar_destino_respaldo(string $activa, int $puertoActivo, string $solicitada, int $puertoSolicitado): ?string
{
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', $solicitada)) {
        return 'Respaldo bloqueado: nombre de base no válido.';
    }
    if ($activa === '' || !hash_equals($activa, $solicitada)) {
        return 'Respaldo bloqueado: la base seleccionada no coincide con la base utilizada por el ERP. Revisa GD_DB_NAME y app/config/database.php.';
    }
    if ($puertoActivo <= 0 || $puertoSolicitado <= 0 || $puertoActivo !== $puertoSolicitado) {
        return 'Respaldo bloqueado: el puerto de MySQL del respaldo no coincide con la conexión real del ERP.';
    }
    return null;
}

function gyd_f1_validar_destino_respaldo(string $solicitada, string $puertoSolicitado): ?string
{
    try {
        require_once __DIR__ . '/../config/database.php';
        $pdo = (new Database())->connect();
        $activa = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $puerto = (int) $pdo->query('SELECT @@port')->fetchColumn();
        return gyd_f1_comparar_destino_respaldo($activa, $puerto, $solicitada, (int)$puertoSolicitado);
    } catch (Throwable $e) {
        error_log('G&D verificación destino de respaldo: ' . $e->getMessage());
        return 'Respaldo bloqueado: no se pudo confirmar la base y el puerto activos del ERP.';
    }
}
