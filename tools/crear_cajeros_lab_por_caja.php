<?php
declare(strict_types=1);
/**
 * G&D — Cuentas de CAJERO exclusivamente para la base local "prueba".
 * Uso:
 *   php tools/crear_cajeros_lab_por_caja.php --check
 *   php tools/crear_cajeros_lab_por_caja.php --apply --confirm=PRUEBA --password=123456
 *   php tools/crear_cajeros_lab_por_caja.php --verify
 *
 * No modifica usuarios previos ni elimina información. Operación transaccional.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Disponible solo en terminal.\n");
}

$args = array_slice($argv, 1);
$mode = null;
$confirm = '';
$password = null;
foreach ($args as $arg) {
    if (in_array($arg, ['--check', '--apply', '--verify'], true)) {
        if ($mode !== null) { fwrite(STDERR, "Selecciona solamente un modo.\n"); exit(2); }
        $mode = substr($arg, 2);
    } elseif (str_starts_with($arg, '--confirm=')) {
        $confirm = substr($arg, strlen('--confirm='));
    } elseif (str_starts_with($arg, '--password=')) {
        $password = substr($arg, strlen('--password='));
    } else {
        fwrite(STDERR, "Argumento desconocido.\n");
        exit(2);
    }
}
if ($mode === null) {
    echo "G&D | Crear cajeros de laboratorio por cada caja ACTIVA.\n";
    echo "  --check\n  --apply --confirm=PRUEBA --password=123456\n  --verify\n";
    exit(2);
}
if ($mode !== 'apply' && ($confirm !== '' || $password !== null)) {
    fwrite(STDERR, "Los argumentos --confirm y --password solo se permiten en --apply.\n");
    exit(2);
}
if ($mode === 'apply' && ($confirm !== 'PRUEBA' || $password !== '123456')) {
    fwrite(STDERR, "Para esta prueba usa --apply --confirm=PRUEBA --password=123456.\n");
    exit(2);
}
require_once __DIR__ . '/../app/config/database.php';

function labFail(string $message): never {
    fwrite(STDERR, "DETENIDO: {$message}\nNo se han modificado usuarios por esta ejecucion.\n");
    exit(1);
}
function labQuery(PDO $db, string $sql, array $params = []): PDOStatement {
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement;
}
function labAccount(array $caja): array {
    $sid = (int) $caja['sucursal_id'];
    $cid = (int) $caja['caja_id'];
    return [
        'usuario' => sprintf('gydlab_s%02d_c%03d', $sid, $cid),
        'correo' => sprintf('gydlab+s%02dc%03d@example.invalid', $sid, $cid),
        'nombre' => 'Cajero LAB - ' . (string) $caja['sucursal'] . ' - ' . (string) $caja['caja'],
    ];
}
function labCheckSchemas(PDO $db): void {
    $expected = [
        'usuarios' => ['id','nombre','usuario','correo','password','rol','estado','almacen_id'],
        'pos_sucursales' => ['id','nombre','activo'],
        'pos_cajas' => ['id','sucursal_id','nombre','activa'],
        'pos_usuario_asignaciones' => ['usuario_id','sucursal_id','caja_id'],
    ];
    $statement = labQuery($db, "SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('usuarios','pos_sucursales','pos_cajas','pos_usuario_asignaciones')");
    $found = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $found[$r['TABLE_NAME']][$r['COLUMN_NAME']] = true;
    }
    foreach ($expected as $table => $columns) {
        foreach ($columns as $column) {
            if (empty($found[$table][$column])) labFail("Falta {$table}.{$column}. El esquema no es compatible.");
        }
    }
}
function labCajas(PDO $db, bool $lock): array {
    $sql = "SELECT c.id AS caja_id,c.sucursal_id,c.nombre AS caja,s.nombre AS sucursal 
              FROM pos_cajas c INNER JOIN pos_sucursales s ON s.id=c.sucursal_id
              WHERE c.activa=1 AND s.activo=1 ORDER BY s.id,c.id" . ($lock ? ' FOR UPDATE' : '');
    return labQuery($db, $sql)->fetchAll(PDO::FETCH_ASSOC);
}
function labEstadoCuenta(PDO $db, array $caja, bool $lock): array {
    $a = labAccount($caja);
    $sql = 'SELECT id,usuario,correo,rol,estado FROM usuarios WHERE usuario=? OR correo=?' . ($lock ? ' FOR UPDATE' : '');
    $users = labQuery($db, $sql, [$a['usuario'], $a['correo']])->fetchAll(PDO::FETCH_ASSOC);
    if (count($users) === 0) return ['estado' => 'CREAR', 'data' => null];
    if (count($users) !== 1) return ['estado' => 'CONFLICTO', 'data' => null];
    $user = $users[0];
    if ((string)$user['usuario'] !== $a['usuario'] ||
        (string)$user['correo'] !== $a['correo'] ||
        strtoupper((string)$user['rol']) !== 'CAJERO' ||
        (int)$user['estado'] !== 1) return ['estado' => 'CONFLICTO', 'data' => $user];
    $assignment = labQuery($db, 'SELECT sucursal_id,caja_id FROM pos_usuario_asignaciones WHERE usuario_id=?', [(int)$user['id']])->fetch(PDO::FETCH_ASSOC);
    if (!$assignment || (int)$assignment['sucursal_id'] !== (int)$caja['sucursal_id'] ||
        (int)$assignment['caja_id'] !== (int)$caja['caja_id']) return ['estado' => 'CONFLICTO', 'data' => $user];
    return ['estado' => 'EXISTE', 'data' => $user];
}

try {
    $db = (new Database())->connect();
    // Protección obligatoria: el script no acepta configurar otra base.
    $dbName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
    $port = (int) $db->query('SELECT @@port')->fetchColumn();
    if ($dbName !== 'prueba') labFail("Solo se permite la base de laboratorio 'prueba' (actual: '{$dbName}').");
    if ($port !== 3307) labFail("Puerto distinto al laboratorio confirmado (actual: {$port}, esperado: 3307).");
    labCheckSchemas($db);

    $locked = false;
    if ($mode === 'apply') {
        $locked = ((int)labQuery($db, "SELECT GET_LOCK('gyd_lab_cajeros_prueba', 8)")->fetchColumn() === 1);
        if (!$locked) labFail('Otro proceso está creando cajeros de prueba.');
        $db->beginTransaction();
    }

    $cajas = labCajas($db, $mode === 'apply');
    if (count($cajas) === 0) throw new RuntimeException('No hay cajas activas en sucursales activas.');
    $creados = 0;
    $existentes = 0;
    $conflictos = [];
    $lines = [];
    foreach ($cajas as $caja) {
        $a = labAccount($caja);
        $estado = labEstadoCuenta($db, $caja, $mode === 'apply');
        $nombreCaja = $caja['sucursal'] . ' / ' . $caja['caja'];
        if ($estado['estado'] === 'CONFLICTO') {
            $conflictos[] = $a['usuario'] . ' (' . $nombreCaja . ')';
            $lines[] = '[CONFLICTO] ' . $nombreCaja . ' | ' . $a['usuario'];
            continue;
        }
        if ($estado['estado'] === 'EXISTE') {
            $existentes++;
            $lines[] = '[EXISTE] ' . $nombreCaja . ' | ' . $a['usuario'];
            continue;
        }
        if ($mode === 'apply') {
            $hashed = password_hash((string)$password, PASSWORD_DEFAULT);
            if (!$hashed) throw new RuntimeException('No fue posible proteger la contraseña.');
            labQuery($db,
                'INSERT INTO usuarios(nombre,usuario,correo,password,rol,estado,almacen_id) VALUES(?,?,?,?,\'CAJERO\',1,NULL)',
                [$a['nombre'], $a['usuario'], $a['correo'], $hashed]
            );
            $userId = (int)$db->lastInsertId();
            labQuery($db,
                'INSERT INTO pos_usuario_asignaciones(usuario_id,sucursal_id,caja_id) VALUES(?,?,?)',
                [$userId, (int)$caja['sucursal_id'], (int)$caja['caja_id']]
            );
            // Verificar la asignación antes de consolidar la transacción.
            if (labEstadoCuenta($db, $caja, true)['estado'] !== 'EXISTE') throw new RuntimeException('Error al verificar la asignación del nuevo cajero.');
            $creados++;
            $lines[] = '[CREADO] ' . $nombreCaja . ' | ' . $a['usuario'];
        } else {
            $lines[] = '[CREAR] ' . $nombreCaja . ' | ' . $a['usuario'];
            $creados++;
        }
    }
    if (count($conflictos) > 0) throw new RuntimeException('Conflicto con cuentas existentes: ' . implode('; ', $conflictos) . '. No se modificarán cuentas anteriores.');
    if ($mode === 'apply') $db->commit();
    echo "G&D | CUENTAS CAJERO LAB | Base: {$dbName} | Puerto: {$port}\n";
    echo "Modo: " . strtoupper($mode) . " | Cajas activas: " . count($cajas) . "\n";
    foreach ($lines as $line) echo $line, "\n";
    echo "Resumen: " . ($mode === 'apply' ? 'creados' : 'por crear') . "={$creados}; existentes={$existentes}.\n";
    if ($mode === 'check') echo "No se modificó la base. Para crear: --apply --confirm=PRUEBA --password=123456\n";
    if ($mode === 'apply') echo "Transacción confirmada. Contraseña asignada: 123456 (SOLO LAB).\n";
    if ($mode === 'verify') echo "Solo se verificaron los registros de cuentas, no se probaron inicios de sesión.\n";
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    if (!empty($locked) && isset($db)) {
        try { labQuery($db, "SELECT RELEASE_LOCK('gyd_lab_cajeros_prueba')"); } catch (Throwable $ignored) { }
    }
}
