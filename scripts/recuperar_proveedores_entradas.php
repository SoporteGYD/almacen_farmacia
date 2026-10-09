<?php
/**
 * Recuperación manual de proveedores históricos.
 *
 * Vista previa (NO cambia nada):
 *   php scripts/recuperar_proveedores_entradas.php
 * Aplicar después de realizar un respaldo comprobado:
 *   php scripts/recuperar_proveedores_entradas.php --aplicar --respaldo-confirmado
 *
 * Sólo escribe en proveedores y movimientos.proveedor_id cuando está vacío.
 * NO modifica productos, existencias, lotes, detalle, costos o movimientos cancelados.
 * El proveedor de Existencias se obtiene de la última entrada NO cancelada.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Sólo se puede ejecutar por línea de comandos.');
}

require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/models/Proveedor.php';
require_once __DIR__ . '/../app/helpers/proveedor_historico.php';

$argumentos = array_slice($argv, 1);
$validos = ['--aplicar', '--respaldo-confirmado', '--ayuda'];
foreach ($argumentos as $argumento) {
    if (!in_array($argumento, $validos, true)) {
        fwrite(STDERR, "Opción desconocida: {$argumento}\n");
        exit(2);
    }
}

if (in_array('--ayuda', $argumentos, true)) {
    echo "Vista previa: php scripts/recuperar_proveedores_entradas.php\n";
    echo "Aplicar: php scripts/recuperar_proveedores_entradas.php --aplicar --respaldo-confirmado\n";
    exit(0);
}

$aplicar = in_array('--aplicar', $argumentos, true);
if ($aplicar && !in_array('--respaldo-confirmado', $argumentos, true)) {
    fwrite(STDERR, "DETENIDO: primero realiza y verifica un respaldo de la base de datos.\n");
    fwrite(STDERR, "Después ejecuta con --aplicar --respaldo-confirmado.\n");
    exit(2);
}

try {
    $conn = (new Database())->connect();
    // Captura nombres sólo de entradas cuyo proveedor no se guardó como relación.
    $stmt = $conn->query(
        "SELECT id, folio, referencia
         FROM movimientos
         WHERE tipo_movimiento = 'ENTRADA'
           AND proveedor_id IS NULL
           AND referencia LIKE '%Proveedor:%'
         ORDER BY id ASC"
    );

    $pendientes = [];
    $porProveedor = [];
    $descartados = 0;
    while ($fila = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $nombre = proveedorHistoricoDesdeReferencia($fila['referencia'] ?? null);
        if ($nombre === null) {
            $descartados++;
            continue;
        }
        $pendientes[] = [
            'id' => (int)$fila['id'],
            'folio' => (string)$fila['folio'],
            'nombre' => $nombre,
        ];
        $clave = function_exists('mb_strtoupper')
            ? mb_strtoupper($nombre, 'UTF-8') : strtoupper($nombre);
        if (!isset($porProveedor[$clave])) {
            $porProveedor[$clave] = ['nombre' => $nombre, 'total' => 0];
        }
        $porProveedor[$clave]['total']++;
    }
    $stmt->closeCursor();

    echo $aplicar ? "MODO APLICACIÓN\n" : "VISTA PREVIA (SIN CAMBIOS)\n";
    echo 'Entradas recuperables: ' . count($pendientes) . "\n";
    echo 'Referencias sin proveedor válido: ' . $descartados . "\n";
    echo 'Proveedores diferentes (por nombre): ' . count($porProveedor) . "\n";
    foreach ($porProveedor as $info) {
        echo '  - ' . $info['nombre'] . ' (' . $info['total'] . " entradas)\n";
    }
    if ($pendientes) {
        echo "Ejemplos de folios a recuperar:\n";
        foreach (array_slice($pendientes, 0, 15) as $item) {
            echo '  ' . $item['folio'] . ' -> ' . $item['nombre'] . "\n";
        }
    }

    if (!$aplicar) {
        echo "\nNo se realizó ningún cambio. Revisa esta vista previa y realiza un respaldo.\n";
        exit(0);
    }
    if (!$pendientes) {
        echo "No hay registros pendientes.\n";
        exit(0);
    }

    $conn->beginTransaction();
    $actualizar = $conn->prepare(
        "UPDATE movimientos SET proveedor_id = :proveedor_id
         WHERE id = :id AND proveedor_id IS NULL AND tipo_movimiento = 'ENTRADA'"
    );
    $actualizados = 0;
    foreach ($pendientes as $item) {
        $proveedorId = Proveedor::resolverId($conn, $item['nombre']);
        $actualizar->execute([
            ':proveedor_id' => $proveedorId,
            ':id' => $item['id'],
        ]);
        $actualizados += $actualizar->rowCount();
    }
    $conn->commit();
    echo "\nRecuperación terminada: {$actualizados} entradas vinculadas.\n";
    echo "No se modificaron existencias, costos, productos, cantidades ni folios.\n";
} catch (Throwable $error) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    fwrite(STDERR, 'No se aplicaron cambios completos: ' . $error->getMessage() . "\n");
    exit(1);
}
