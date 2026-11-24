<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/config/database.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

requireLogin();

$db = new Database();
$conn = $db->connect();

$mensaje = "";
$errores = 0;

function limpiarTexto($valor): string
{
    return trim((string)($valor ?? ''));
}

function limpiarCodigo($valor): string
{
    $valor = trim((string)($valor ?? ''));
    $valor = str_replace(["\n", "\r", "\t", " "], '', $valor);

    if (str_contains($valor, '.')) {
        $valor = rtrim(rtrim($valor, '0'), '.');
    }

    return $valor;
}

function codigoValido(string $codigo): bool
{
    return $codigo !== ''
        && preg_match('/[1-9]/', $codigo)
        && strlen($codigo) >= 3;
}

function normalizarEncabezado(string $texto): string
{
    $texto = strtolower(trim($texto));

    $texto = str_replace(
        [' ', '_', '-', '.', '/', '\\'],
        '',
        $texto
    );

    $texto = str_replace(
        ['á', 'é', 'í', 'ó', 'ú', 'ñ'],
        ['a', 'e', 'i', 'o', 'u', 'n'],
        $texto
    );

    return $texto;
}

function valorFila(array $fila, ?string $columna): string
{
    return $columna ? limpiarTexto($fila[$columna] ?? '') : '';
}

function codigoFila(array $fila, ?string $columna): string
{
    return $columna ? limpiarCodigo($fila[$columna] ?? '') : '';
}

function campoVacio($valor): bool
{
    $valor = trim((string)($valor ?? ''));
    return $valor === '' || $valor === '-';
}

function buscarProductoPorCodigo(PDO $conn, string $codigo): ?array
{
    $stmt = $conn->prepare("
        SELECT *
        FROM productos
        WHERE codigo = ?
           OR codigo_barras = ?
        LIMIT 1
    ");

    $stmt->execute([$codigo, $codigo]);
    $producto = $stmt->fetch(PDO::FETCH_ASSOC);

    return $producto ?: null;
}

function normalizarSucursal(string $sucursal): string
{
    $sucursal = strtoupper(trim($sucursal));

    if ($sucursal === 'CD HIDALGO') {
        return 'CIUDAD HIDALGO';
    }

    if ($sucursal === 'CIUDAD_HIDALGO') {
        return 'CIUDAD HIDALGO';
    }

    if ($sucursal === 'TUXTLA GUTIERREZ' || $sucursal === 'TUXTLA_GUTIERREZ') {
        return 'TUXTLA';
    }

    return $sucursal;
}

/*
|--------------------------------------------------------------------------
| ACTUALIZAR ALMACÉN DEL PRODUCTO
|--------------------------------------------------------------------------
| Corregido:
| - Se eliminó almacen_id porque NO existe en tu tabla productos.
| - Solo se actualiza sucursal y updated_at.
*/
function actualizarAlmacenProducto(PDO $conn, int $productoId, string $sucursal): void
{
    $sucursal = normalizarSucursal($sucursal);

    $stmt = $conn->prepare("
        UPDATE productos
        SET sucursal = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");

    $stmt->execute([$sucursal, $productoId]);
}

/*
|--------------------------------------------------------------------------
| ACTUALIZAR UBICACIÓN Y EXISTENCIA
|--------------------------------------------------------------------------
| Corregido:
| - Se eliminó created_at porque NO existe en producto_existencias.
| - Tu tabla producto_existencias solo tiene updated_at.
*/
function actualizarUbicacionExistencia(
    PDO $conn,
    int $productoId,
    string $sucursal,
    string $ubicacion,
    int $existencia
): void {
    $sucursal = normalizarSucursal($sucursal);
    $ubicacion = strtoupper(trim($ubicacion));

    if (
        $ubicacion === '' ||
        $ubicacion === '-' ||
        $ubicacion === 'SIN UBICACION' ||
        $ubicacion === 'SINUBICACION'
    ) {
        $ubicacion = 'SIN UBICACION';
    }

    $stmtCheck = $conn->prepare("
        SELECT id, existencia
        FROM producto_existencias
        WHERE producto_id = ?
          AND UPPER(sucursal) = UPPER(?)
          AND UPPER(ubicacion) = UPPER(?)
        LIMIT 1
    ");

    $stmtCheck->execute([$productoId, $sucursal, $ubicacion]);
    $existe = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if ($existe) {
        $stmtUpdate = $conn->prepare("
            UPDATE producto_existencias
            SET existencia = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");

        $stmtUpdate->execute([$existencia, $existe['id']]);
    } else {
        $stmtInsert = $conn->prepare("
            INSERT INTO producto_existencias (
                producto_id,
                sucursal,
                ubicacion,
                existencia,
                updated_at
            ) VALUES (
                ?, ?, ?, ?,
                CURRENT_TIMESTAMP
            )
        ");

        $stmtInsert->execute([$productoId, $sucursal, $ubicacion, $existencia]);
    }
}

function actualizarUbicacionPrincipal(PDO $conn, int $productoId, string $sucursal): void
{
    $sucursal = normalizarSucursal($sucursal);

    $stmt = $conn->prepare("
        SELECT ubicacion
        FROM producto_existencias
        WHERE producto_id = ?
          AND UPPER(sucursal) = UPPER(?)
          AND existencia > 0
        ORDER BY existencia DESC, ubicacion ASC
        LIMIT 1
    ");

    $stmt->execute([$productoId, $sucursal]);
    $ubicacion = $stmt->fetchColumn();

    if ($ubicacion) {
        $stmtUpdate = $conn->prepare("
            UPDATE productos
            SET ubicacion = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");

        $stmtUpdate->execute([$ubicacion, $productoId]);
    } else {
        $stmtUpdate = $conn->prepare("
            UPDATE productos
            SET ubicacion = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");

        $stmtUpdate->execute([$productoId]);
    }
}

function actualizarExistenciaBodega(PDO $conn, int $productoId): void
{
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(existencia), 0)
        FROM producto_existencias
        WHERE producto_id = ?
    ");

    $stmt->execute([$productoId]);
    $total = (int)$stmt->fetchColumn();

    $stmtUpdate = $conn->prepare("
        UPDATE productos
        SET existencia_bodega = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");

    $stmtUpdate->execute([$total, $productoId]);
}

function reactivarProducto(PDO $conn, int $productoId): void
{
    $stmt = $conn->prepare("
        UPDATE productos
        SET estado = 1,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");

    $stmt->execute([$productoId]);
}

function snapshotProductoImportacion(
    PDO $conn,
    int $productoId
): array {
    $stmtProducto = $conn->prepare("
        SELECT
            id,
            codigo,
            codigo_barras,
            descripcion,
            sucursal,
            ubicacion,
            existencia_bodega,
            estado
        FROM productos
        WHERE id = ?
        LIMIT 1
    ");
    $stmtProducto->execute([$productoId]);
    $producto = $stmtProducto->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmtExistencias = $conn->prepare("
        SELECT
            id,
            sucursal,
            ubicacion,
            existencia
        FROM producto_existencias
        WHERE producto_id = ?
        ORDER BY sucursal ASC, ubicacion ASC, id ASC
    ");
    $stmtExistencias->execute([$productoId]);

    $producto['existencias'] = $stmtExistencias->fetchAll(
        PDO::FETCH_ASSOC
    );

    return $producto;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $tipoImportacion = limpiarTexto($_POST['tipo_importacion'] ?? '');
    $sucursal = limpiarTexto($_POST['sucursal'] ?? '');

    if ($tipoImportacion === '') {

        $mensaje = "Debes seleccionar el tipo de importación.";

    } elseif ($tipoImportacion === 'existencia' && $sucursal === '') {

        $mensaje = "Debes seleccionar una sucursal para importar existencias.";

    } elseif (empty($_FILES['excel']['tmp_name'])) {

        $mensaje = "Debes seleccionar un archivo Excel.";

    } else {

        try {

            $spreadsheet = IOFactory::load($_FILES['excel']['tmp_name']);
            $hoja = $spreadsheet->getActiveSheet();
            $filas = $hoja->toArray(null, true, true, true);

            if (count($filas) < 2) {
                throw new Exception("El Excel no contiene datos suficientes.");
            }

            $filaEncabezado = array_shift($filas);
            $encabezados = [];

            foreach ($filaEncabezado as $columna => $valor) {
                $encabezados[normalizarEncabezado((string)$valor)] = $columna;
            }

            $actualizados = 0;
            $omitidos = 0;
            $noEncontrados = 0;
            $conStock = 0;
            $sinStock = 0;
            $productosEnExcel = 0;
            $detalleErrores = [];

            if ($tipoImportacion === 'existencia') {

                $colCodigoBarras =
                    $encabezados['codigobarras'] ??
                    $encabezados['codigo'] ??
                    null;

                $colExistencia =
                    $encabezados['existencia'] ??
                    $encabezados['stock'] ??
                    null;

                $colUbicacion =
                    $encabezados['ubicacion'] ??
                    null;

                $colAlmacen =
                    $encabezados['almacen'] ??
                    $encabezados['sucursal'] ??
                    null;

                if (!$colCodigoBarras || !$colExistencia) {
                    throw new Exception("No se encontraron las columnas obligatorias: Código/Código barras y Existencia.");
                }

                foreach ($filas as $numeroFila => $fila) {

                    $codigo = codigoFila($fila, $colCodigoBarras);
                    $existenciaRaw = valorFila($fila, $colExistencia);
                    $ubicacion = $colUbicacion ? valorFila($fila, $colUbicacion) : '';

                    $almacenFila = $colAlmacen ? valorFila($fila, $colAlmacen) : '';
                    $almacenUsar = !campoVacio($almacenFila) ? $almacenFila : $sucursal;
                    $almacenUsar = normalizarSucursal($almacenUsar);

                    if (!codigoValido($codigo)) {
                        $omitidos++;
                        continue;
                    }

                    $existenciaRaw = str_replace([',', ' '], '', $existenciaRaw);
                    $existencia = is_numeric($existenciaRaw) ? (int)$existenciaRaw : 0;

                    if ($existencia < 0) {
                        $existencia = 0;
                    }

                    $productosEnExcel++;

                    if ($existencia > 0) {
                        $conStock++;
                    } else {
                        $sinStock++;
                    }

                    $ubicacion = strtoupper(trim($ubicacion));

                    if (
                        $ubicacion === '' ||
                        $ubicacion === '-' ||
                        $ubicacion === 'SIN UBICACION' ||
                        $ubicacion === 'SINUBICACION'
                    ) {
                        $ubicacion = 'SIN UBICACION';
                    }

                    try {

                        $producto = buscarProductoPorCodigo($conn, $codigo);

                        if (!$producto) {
                            $noEncontrados++;
                            continue;
                        }

                        $productoId = (int)$producto['id'];
                        $estadoAnterior = snapshotProductoImportacion(
                            $conn,
                            $productoId
                        );

                        $conn->beginTransaction();

                        actualizarAlmacenProducto($conn, $productoId, $almacenUsar);

                        reactivarProducto($conn, $productoId);

                        actualizarUbicacionExistencia(
                            $conn,
                            $productoId,
                            $almacenUsar,
                            $ubicacion,
                            $existencia
                        );

                        actualizarUbicacionPrincipal($conn, $productoId, $almacenUsar);

                        actualizarExistenciaBodega($conn, $productoId);

                        $conn->commit();

                        $actualizados++;

                        $estadoNuevo = snapshotProductoImportacion(
                            $conn,
                            $productoId
                        );

                        auditLog([
                            'modulo' => 'Importación de productos',
                            'accion' => 'ACTUALIZAR_EXISTENCIA_IMPORTACION',
                            'entidad' => 'producto',
                            'registro_id' => $productoId,
                            'descripcion' => 'La importación actualizó '
                                . ($producto['descripcion']
                                    ?? ('producto #' . $productoId))
                                . ' en ' . $almacenUsar
                                . ', ubicación ' . $ubicacion
                                . ', con existencia ' . $existencia
                                . '.',
                            'anteriores' => $estadoAnterior,
                            'nuevos' => $estadoNuevo,
                            'metadata' => [
                                'origen' => 'ARCHIVO_EXCEL',
                                'fila_excel' => $numeroFila,
                                'codigo' => $codigo,
                            ],
                        ]);

                    } catch (Exception $e) {

                        if ($conn->inTransaction()) {
                            $conn->rollBack();
                        }

                        $errores++;

                        if (count($detalleErrores) < 10) {
                            $detalleErrores[] = "Fila {$numeroFila} (Código: {$codigo}): " . $e->getMessage();
                        }
                    }
                }

                $mensaje = "
                    <strong>✅ IMPORTACIÓN COMPLETADA</strong><br><br>

                    <strong>📊 Resumen:</strong><br>
                    • Total de productos en Excel: <strong>{$productosEnExcel}</strong><br>
                    • Con stock positivo (>0): <strong>{$conStock}</strong><br>
                    • Con stock 0 (agotados): <strong>{$sinStock}</strong><br>
                    <br>
                    <strong>🔄 Procesados:</strong><br>
                    • Actualizados/Insertados: <strong>{$actualizados}</strong><br>
                    • Productos no encontrados: <strong>{$noEncontrados}</strong><br>
                    • Omitidos (código inválido): <strong>{$omitidos}</strong><br>
                    • Errores: <strong>{$errores}</strong><br>
                    <br>
                    <strong>🏪 Almacén asignado:</strong> <strong style='color:#059669;'>{$almacenUsar}</strong><br>
                    <br>
                    <strong>⚠️ Nota:</strong> Los productos con stock 0 ahora tienen asignado el almacén '{$almacenUsar}' y aparecerán en la sección <strong>AGOTADOS</strong>.
                ";

            } else {

                throw new Exception("Tipo de importación no válido. Selecciona 'Existencias por almacén'.");
            }

            if (!empty($detalleErrores)) {
                $mensaje .= "<br><br><strong>⚠️ Primeros errores:</strong><br>";
                $mensaje .= "<ul style='margin-left:20px;'>";
                foreach ($detalleErrores as $error) {
                    $mensaje .= "<li>" . htmlspecialchars($error) . "</li>";
                }
                $mensaje .= "</ul>";
            }

            auditLog([
                'modulo' => 'Importación de productos',
                'accion' => 'IMPORTAR_EXISTENCIAS',
                'entidad' => 'producto_existencia',
                'descripcion' => 'Importó existencias desde Excel para '
                    . $almacenUsar . ': '
                    . $actualizados . ' actualizado(s), '
                    . $noEncontrados . ' no encontrado(s), '
                    . $omitidos . ' omitido(s) y '
                    . $errores . ' error(es).',
                'metadata' => [
                    'archivo' => basename(
                        (string) (
                            $_FILES['excel']['name']
                            ?? 'archivo_sin_nombre'
                        )
                    ),
                    'tipo_importacion' => $tipoImportacion,
                    'sucursal' => $almacenUsar,
                    'productos_en_excel' => $productosEnExcel,
                    'con_stock' => $conStock,
                    'sin_stock' => $sinStock,
                    'actualizados' => $actualizados,
                    'no_encontrados' => $noEncontrados,
                    'omitidos' => $omitidos,
                    'errores' => $errores,
                    'primeros_errores' => $detalleErrores,
                ],
            ]);

        } catch (Exception $e) {

            $mensaje = "<strong>❌ Error:</strong> " . $e->getMessage();
            $errores++;

            auditLog([
                'modulo' => 'Importación de productos',
                'accion' => 'IMPORTACION_FALLIDA',
                'descripcion' => 'Falló la importación de existencias desde Excel.',
                'metadata' => [
                    'archivo' => basename(
                        (string) (
                            $_FILES['excel']['name']
                            ?? 'archivo_sin_nombre'
                        )
                    ),
                    'tipo_importacion' => $tipoImportacion,
                    'sucursal' => $sucursal,
                    'error' => $e->getMessage(),
                ],
            ]);
        }
    }
}

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Importar Productos</title>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #eef2f7;
            font-family: 'Segoe UI', 'Inter', system-ui, sans-serif;
            min-height: 100vh;
            padding: 40px 20px;
        }

        .import-container {
            max-width: 650px;
            margin: 0 auto;
        }

        .import-card {
            background: white;
            border-radius: 28px;
            padding: 32px;
            box-shadow: 0 20px 35px -10px rgba(0,0,0,0.1);
            border: 1px solid #e4e7eb;
        }

        .import-title {
            font-size: 28px;
            font-weight: 700;
            color: #1a2c3e;
            margin-bottom: 8px;
        }

        .import-subtitle {
            color: #64748b;
            margin-bottom: 28px;
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #334155;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1.5px solid #e2e8f0;
            border-radius: 14px;
            font-size: 14px;
            transition: all 0.2s;
        }

        .form-control:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
        }

        select.form-control {
            background: white;
            cursor: pointer;
        }

        .btn-importar {
            background: #3b82f6;
            color: white;
            padding: 14px 24px;
            border-radius: 40px;
            font-weight: 700;
            border: none;
            cursor: pointer;
            width: 100%;
            font-size: 16px;
            transition: all 0.2s;
        }

        .btn-importar:hover {
            background: #2563eb;
            transform: scale(1.01);
        }

        .btn-volver {
            display: inline-block;
            margin-top: 20px;
            color: #64748b;
            text-decoration: none;
            font-size: 14px;
        }

        .btn-volver:hover {
            color: #3b82f6;
        }

        small {
            color: #64748b;
            font-size: 12px;
            display: block;
            margin-top: 6px;
        }

        .alert-success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
            padding: 20px;
            border-radius: 16px;
            margin-top: 24px;
            font-size: 14px;
            line-height: 1.5;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
            padding: 16px;
            border-radius: 16px;
            margin-top: 24px;
            font-size: 14px;
            line-height: 1.5;
        }

        hr {
            margin: 20px 0;
            border: none;
            border-top: 1px solid #eef2f6;
        }

        .badge-info {
            background: #e0f2fe;
            color: #0369a1;
            padding: 8px 12px;
            border-radius: 40px;
            font-size: 12px;
            margin-top: 16px;
            text-align: center;
        }
    </style>
</head>

<body>

<div class="import-container">
    <div class="import-card">

        <h1 class="import-title">📥 Importar Productos</h1>

        <p class="import-subtitle">
            Restaura productos desde Excel, incluyendo productos con stock 0.
        </p>

        <form method="POST" enctype="multipart/form-data">

            <div class="form-group">
                <label>Tipo de importación</label>

                <select name="tipo_importacion" id="tipo_importacion" class="form-control" required>
                    <option value="">Seleccione una opción</option>
                    <option value="existencia" selected>📍 Existencias por almacén</option>
                </select>
            </div>

            <div class="form-group">
                <label>Sucursal / Almacén</label>

                <select name="sucursal" id="sucursal" class="form-control" required>
                    <option value="CIUDAD HIDALGO" selected>🏪 CIUDAD HIDALGO</option>
                    <option value="TUXTLA">🏪 TUXTLA</option>
                </select>

                <small id="ayudaSucursal">
                    Los productos se asociarán al almacén seleccionado.
                </small>
            </div>

            <div class="form-group">
                <label>Archivo Excel</label>

                <input
                    type="file"
                    name="excel"
                    class="form-control"
                    accept=".xlsx,.xls"
                    required
                >

                <small>
                    El Excel debe contener columnas: Código/Código barras, Existencia y Ubicación.
                </small>
            </div>

            <button type="submit" class="btn-importar">
                🚀 Importar Excel
            </button>

        </form>

        <?php if (!empty($mensaje)): ?>
            <div class="<?= $errores > 0 ? 'alert-error' : 'alert-success' ?>">
                <?= $mensaje ?>
            </div>
        <?php endif; ?>

        <hr>

        <div class="badge-info" id="badgeInfo">
            💡 Los productos con stock 0 conservarán su almacén y aparecerán como agotados.
        </div>

        <a href="productos.php" class="btn-volver">
            ← Volver a productos
        </a>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const sucursal = document.getElementById('sucursal');
    const ayuda = document.getElementById('ayudaSucursal');
    const badgeInfo = document.getElementById('badgeInfo');

    function actualizarTexto() {
        ayuda.textContent = '✅ Los productos se asociarán a ' + sucursal.value + '. Los agotados aparecerán en la sección correspondiente.';
        badgeInfo.textContent = '💡 Los productos con stock 0 tendrán asignado el almacén ' + sucursal.value + '.';
    }

    sucursal.addEventListener('change', actualizarTexto);
    actualizarTexto();
});
</script>

</body>
</html>
