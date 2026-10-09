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
 * Recupera exclusivamente nombres incluidos en la lista blanca de abajo.
 * Excluye conceptos internos como TRASPASO y ENTRADA.
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

// Lista blanca de proveedores comerciales identificados en la vista previa.
// COMA y FRANCO ESPAÑA GARCIA quedan pendientes de confirmar; no los
// autorices sin verificar que realmente sean proveedores.
$proveedoresAutorizados = [
    'NADRO',
    'WALMART',
    'FANASA',
    'SAHUAYO',
];
$clavesAutorizadas = [];
foreach ($proveedoresAutorizados as $proveedorAutorizado) {
    $clave = function_exists('mb_strtoupper')
        ? mb_strtoupper(trim($proveedorAutorizado), 'UTF-8')
        : strtoupper(trim($proveedorAutorizado));
    $clavesAutorizadas[$clave] = true;
}

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
    $conceptosInternos = [];
    $porRevisar = [];
    while ($fila = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $referencia = $fila['referencia'] ?? null;
        // Separa etiquetas internas para mostrarlas de forma transparente.
        $nombreBruto = null;
        foreach (explode('|', (string)$referencia) as $segmento) {
            if (preg_match('/^\s*Proveedor\s*:\s*(.+?)\s*$/iu', $segmento, $partes) === 1) {
                $nombreBruto = trim($partes[1]);
                break;
            }
        }
        if (proveedorEsConceptoInterno($nombreBruto)) {
            $claveInterna = function_exists('mb_strtoupper')
                ? mb_strtoupper($nombreBruto, 'UTF-8') : strtoupper($nombreBruto);
            $conceptosInternos[$claveInterna] = ($conceptosInternos[$claveInterna] ?? 0) + 1;
            continue;
        }
        $nombre = proveedorHistoricoDesdeReferencia($referencia);
        if ($nombre === null) {
            $descartados++;
            continue;
        }
        $clave = function_exists('mb_strtoupper')
            ? mb_strtoupper($nombre, 'UTF-8') : strtoupper($nombre);
        if (!isset($clavesAutorizadas[$clave])) {
            if (!isset($porRevisar[$clave])) {
                $porRevisar[$clave] = ['nombre' => $nombre, 'total' => 0];
            }
            $porRevisar[$clave]['total']++;
            continue;
        }
        $pendientes[] = [
            'id' => (int)$fila['id'],
            'folio' => (string)$fila['folio'],
            'nombre' => $nombre,
        ];
        if (!isset($porProveedor[$clave])) {
            $porProveedor[$clave] = ['nombre' => $nombre, 'total' => 0];
        }
        $porProveedor[$clave]['total']++;
    }
    $stmt->closeCursor();

    echo $aplicar ? "MODO APLICACIÓN\n" : "VISTA PREVIA (SIN CAMBIOS)\n";
    echo 'Entradas recuperables: ' . count($pendientes) . "\n";
    echo 'Referencias sin proveedor válido: ' . $descartados . "\n";
    echo 'Conceptos internos excluidos: ' . array_sum($conceptosInternos) . "\n";
    foreach ($conceptosInternos as $concepto => $cantidad) {
        echo "  - {$concepto} ({$cantidad} entradas): NO se creará proveedor\n";
    }
    echo 'Nombres pendientes de validar (NO se aplican): ' . array_sum(array_column($porRevisar, 'total')) . "\n";
    foreach ($porRevisar as $dato) {
        echo '  - ' . $dato['nombre'] . ' (' . $dato['total'] . " entradas)\n";
    }
    echo 'Proveedores autorizados distintos: ' . count($porProveedor) . "\n";
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
        echo "No hay proveedores autorizados pendientes.\n";
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
    echo "Los conceptos internos y nombres no autorizados quedaron sin vincular.\n";
} catch (Throwable $error) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    fwrite(STDERR, 'No se aplicaron cambios completos: ' . $error->getMessage() . "\n");
    exit(1);
}
