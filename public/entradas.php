<?php

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/helpers/utils.php';
require_once __DIR__ . '/../app/controllers/EntradaController.php';

requireLogin();

$user = currentUser();
$controller = new EntradaController();

$csrfEntrada = $_SESSION['csrf_entradas'] ?? '';
if (!is_string($csrfEntrada) || $csrfEntrada === '') {
    $csrfEntrada = bin2hex(random_bytes(32));
    $_SESSION['csrf_entradas'] = $csrfEntrada;
}

$accionEntrada = trim((string)($_GET['action'] ?? ''));

if ($accionEntrada !== '') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    try {
        $usuarioId = (int)($user['id'] ?? 0);

        if ($accionEntrada === 'listar_borradores') {
            echo json_encode([
                'success' => true,
                'borradores' => $controller->listarBorradores(
                    $usuarioId
                ),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($accionEntrada === 'obtener_borrador') {
            $borradorId = (int)($_GET['id'] ?? 0);
            $borrador = $controller->obtenerBorrador(
                $borradorId,
                $usuarioId
            );

            if (!$borrador) {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'message' => 'No se encontró el borrador.',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            echo json_encode([
                'success' => true,
                'borrador' => $borrador,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($accionEntrada === 'folio_almacen') {
            $almacenId = (int)($_GET['almacen_id'] ?? 0);

            if ($almacenId <= 0) {
                throw new RuntimeException('Selecciona un almacén válido.');
            }

            echo json_encode([
                'success' => true,
                'folio' => $controller->generarFolio($almacenId),
                'ultimo_folio' => $controller->ultimoFolioEntrada($almacenId),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new RuntimeException(
                'Método no permitido.'
            );
        }

        $datosJson = json_decode(
            file_get_contents('php://input') ?: '{}',
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (!is_array($datosJson)
            || !hash_equals(
                $csrfEntrada,
                (string)($datosJson['csrf'] ?? '')
            )
        ) {
            http_response_code(419);
            throw new RuntimeException(
                'La sesión de captura cambió. Recargue la página.'
            );
        }

        if ($accionEntrada === 'guardar_borrador') {
            $resultado = $controller->guardarBorrador(
                $datosJson,
                $usuarioId
            );

            echo json_encode([
                'success' => true,
                'borrador' => $resultado,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($accionEntrada === 'eliminar_borrador') {
            $eliminado = $controller->eliminarBorrador(
                (int)($datosJson['id'] ?? 0),
                $usuarioId
            );

            echo json_encode([
                'success' => $eliminado,
                'message' => $eliminado
                    ? 'Borrador eliminado.'
                    : 'No se encontró el borrador.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        http_response_code(404);
        throw new RuntimeException(
            'Acción no encontrada.'
        );
    } catch (Throwable $e) {
        if (http_response_code() < 400) {
            http_response_code(400);
        }

        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$message = '';
$messageType = 'danger';

// Variables para edición
$editarId = isset($_GET['editar']) ? (int)$_GET['editar'] : 0;
$modoEdicion = $editarId > 0;
$entradaEditar = null;
$observacionesEditar = '';
$referenciaEditar = '';
$tipoDoc = '';
$folioDoc = '';
$tipoEntradaSeleccionado = '';
$proveedorSeleccionado = '';

if ($modoEdicion) {
    $entradaEditar = $controller->obtenerEntrada($editarId);

    if (!$entradaEditar) {
        $message = 'La entrada que intentas editar no existe.';
        $messageType = 'danger';
        $modoEdicion = false;
    } elseif ((int)($entradaEditar['cancelado'] ?? 0) === 1) {
        $message = 'No puedes editar una entrada cancelada.';
        $messageType = 'danger';
        $modoEdicion = false;
    } else {
      $observacionesEditar = (string)($entradaEditar['observaciones'] ?? '');
$referenciaEditar = (string)($entradaEditar['referencia'] ?? '');

$tipoEntradaSeleccionado = '';
$proveedorSeleccionado = '';
$tipoDoc = '';
$folioDoc = '';

$partesReferencia = array_map('trim', explode('|', $referenciaEditar));

$tipoEntradaSeleccionado = $partesReferencia[0] ?? '';

foreach ($partesReferencia as $parte) {
    if (stripos($parte, 'Proveedor:') === 0) {
        $proveedorSeleccionado = trim(substr($parte, strlen('Proveedor:')));
    }

    if (stripos($parte, 'Ref:') === 0) {
        $documento = trim(substr($parte, strlen('Ref:')));

        if (strpos($documento, ':') !== false) {
            [$tipoDocTmp, $folioDocTmp] = explode(':', $documento, 2);

            $tipoDoc = strtoupper(trim($tipoDocTmp));
            $folioDoc = trim($folioDocTmp);
        } else {
            $folioDoc = $documento;
        }
    }
}
    }
}

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $editarPostId = (int)($_POST['editar_id'] ?? 0);
    $borradorPostId = (int)($_POST['borrador_id'] ?? 0);

    if ($editarPostId > 0) {
        $result = $controller->actualizar($editarPostId, $_POST, (int)$user['id']);
    } else {
        $result = $controller->guardar($_POST, (int)$user['id']);
    }

    if ($result['success']) {
        $movimientoId = (int)($result['movimiento_id'] ?? 0);

        if ($movimientoId > 0) {
            if ($borradorPostId > 0) {
                try {
                    $controller->eliminarBorrador(
                        $borradorPostId,
                        (int)$user['id'],
                        'FINALIZADO'
                    );
                } catch (Throwable $e) {
                    error_log(
                        'No se pudo cerrar el borrador de entrada: '
                        . $e->getMessage()
                    );
                }
            }

            header('Location: imprimir_entrada.php?id=' . $movimientoId . '&preview=1');
            exit;
        }

        $message = '✅ La entrada se guardó correctamente.';
        $messageType = 'success';
    } else {
        $message = '❌ ' . ($result['message'] ?? 'Error al guardar la entrada');
        $messageType = 'danger';
    }
}

// Datos necesarios para la vista
$almacenes = $controller->almacenes();
$productos = $controller->productos();
$tiposEntrada = $controller->tiposEntrada();

$almacenSesion = (int)($user['almacen_id'] ?? 0);
$rolUsuario = strtoupper(trim($user['rol'] ?? ''));

$puedeSeleccionarAlmacenInicial = in_array(
    $rolUsuario,
    ['ADMINISTRADOR', 'ENCARGADO'],
    true
);

$folio = $almacenSesion > 0
    ? $controller->generarFolio($almacenSesion)
    : '';

if ($modoEdicion && $entradaEditar) {
    $folio = $entradaEditar['folio'];
}

$folioAnterior = $almacenSesion > 0
    && method_exists($controller, 'ultimoFolioEntrada')
        ? $controller->ultimoFolioEntrada($almacenSesion)
        : '';

date_default_timezone_set('America/Mexico_City');

// ===== FECHA AUTOMÁTICA =====
$fechaActual = date('Y-m-d\TH:i');
if ($modoEdicion && $entradaEditar && !empty($entradaEditar['fecha'])) {
    $fechaActual = date('Y-m-d\TH:i', strtotime($entradaEditar['fecha']));
}

// ===== UBICACIONES GENERALES PARA ENTRADAS =====
$ubicacionesGenerales = [];

$ubicacionesTodas = method_exists($controller, 'ubicacionesTodas')
    ? $controller->ubicacionesTodas($almacenSesion)
    : [];

foreach ($ubicacionesTodas as $ubicacion) {
    $ubicacion = strtoupper(trim((string)$ubicacion));
    if ($ubicacion !== '' && $ubicacion !== 'SIN UBICACION') {
        $ubicacionesGenerales[$ubicacion] = $ubicacion;
    }
}

ksort($ubicacionesGenerales);

// ===== CONSTRUIR ARRAY DE PRODUCTOS PARA JS =====
$productosJson = [];
foreach ($productos as $p) {
    $ubicacionesProducto = [];
    if (!empty($p['ubicaciones']) && is_array($p['ubicaciones'])) {
        foreach ($p['ubicaciones'] as $ubi) {
            $ubicacionTmp = strtoupper(trim((string)($ubi['ubicacion'] ?? '')));
            if ($ubicacionTmp !== '' && $ubicacionTmp !== 'SIN UBICACION') {
                $ubicacionesProducto[] = [
                    'ubicacion' => $ubicacionTmp,
                    'existencia_actual' => (int)($ubi['existencia_actual'] ?? $ubi['existencia'] ?? 0)
                ];
            }
        }
    }
    if (empty($ubicacionesProducto)) {
        $ubicacionNormal = strtoupper(trim((string)($p['ubicacion'] ?? '')));
        if ($ubicacionNormal !== '' && $ubicacionNormal !== 'SIN UBICACION') {
            $ubicacionesProducto[] = [
                'ubicacion' => $ubicacionNormal,
                'existencia_actual' => (int)($p['existencia_actual'] ?? 0)
            ];
        }
    }
    
    usort($ubicacionesProducto, function($a, $b) {
        return $a['existencia_actual'] - $b['existencia_actual'];
    });
    
    $ubicacionSugerida = $ubicacionesProducto[0]['ubicacion'] ?? '';
    
    $productosJson[] = [
        'id' => (int)$p['id'],
        'codigo' => $p['codigo'],
        'descripcion' => $p['descripcion'],
        'precio_compra' => (float)($p['precio_compra'] ?? 0),
        'costo_ultimo' => (float)($p['costo_ultimo'] ?? $p['precio_compra'] ?? 0),
        'costo_promedio' => (float)($p['costo_promedio'] ?? $p['costo_ultimo'] ?? $p['precio_compra'] ?? 0),
        'existencia_total' => (int)($p['existencia_total'] ?? 0),
        'ubicacion_sugerida' => $ubicacionSugerida,
        'ubicaciones' => $ubicacionesProducto
    ];
}

$moduleCss = 'entradas';
include __DIR__ . '/../app/views/layouts/header.php';
?>

<link rel="stylesheet" href="assets/css/ubicaciones-rapidas.css?v=20260729">

<style>
/* =========================================================
   DISEÑO CLARO Y ESPACIOSO CON MODAL GRANDE ESTILO EXCEL
   ========================================================= */

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    background: #eef2f7;
    font-family: 'Segoe UI', 'Inter', system-ui, -apple-system, sans-serif;
    padding: 24px;
    min-height: 100vh;
}

/* ===== HEADER SIMPLE ===== */
.page-header {
    margin-bottom: 28px;
}

.page-header h1 {
    font-size: 26px;
    font-weight: 600;
    color: #1a2c3e;
}

/* ===== CONTENEDOR PRINCIPAL ===== */
.main-container {
    max-width: 1600px;
    margin: 0 auto;
}

/* ===== SECCIÓN DE DATOS DEL DOCUMENTO ===== */
.doc-section {
    background: white;
    border-radius: 20px;
    padding: 24px 28px;
    margin-bottom: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    border: 1px solid #e4e7eb;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px 24px;
}

.form-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.form-field label {
    font-size: 12px;
    font-weight: 600;
    color: #4a5b6e;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}

.form-field input,
.form-field select,
.form-field textarea {
    padding: 12px 14px;
    border: 1px solid #d0d5dd;
    border-radius: 12px;
    font-size: 14px;
    color: #1a2c3e;
    background: white;
    transition: all 0.2s;
}

.form-field input:focus,
.form-field select:focus,
.form-field textarea:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
}

.form-field input:read-only {
    background: #f8f9fa;
    color: #6c7a8a;
}

.form-field textarea {
    resize: vertical;
    min-height: 70px;
}

.folio-previous {
    background: #f8f9fa;
    border-radius: 12px;
    padding: 12px 14px;
    margin-top: 16px;
    border: 1px solid #e4e7eb;
}

.folio-previous span {
    font-size: 13px;
    color: #2c7a4d;
    font-weight: 500;
}

.draft-toolbar {
    display: grid;
    grid-template-columns: minmax(220px, 1fr) auto auto;
    gap: 10px;
    align-items: center;
    margin-top: 14px;
    padding: 14px;
    border: 1px solid #bfdbfe;
    border-radius: 14px;
    background: #eff6ff;
}

.draft-toolbar input {
    width: 100%;
    padding: 11px 13px;
    border: 1px solid #93c5fd;
    border-radius: 10px;
    background: white;
    color: #1e3a5f;
}

.draft-button {
    border: 0;
    border-radius: 10px;
    padding: 11px 16px;
    font-weight: 700;
    cursor: pointer;
    white-space: nowrap;
}

.draft-button.primary {
    color: white;
    background: #2563eb;
}

.draft-button.secondary {
    color: #1e40af;
    background: white;
    border: 1px solid #93c5fd;
}

.draft-current {
    grid-column: 1 / -1;
    font-size: 12px;
    color: #475569;
}

/* ===== SECCIÓN DE CAPTURA RÁPIDA ===== */
.capture-section {
    background: white;
    border-radius: 20px;
    padding: 24px 28px;
    margin-bottom: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    border: 1px solid #e4e7eb;
}

.capture-title {
    font-size: 18px;
    font-weight: 600;
    color: #1a2c3e;
    margin-bottom: 20px;
    padding-bottom: 12px;
    border-bottom: 2px solid #e4e7eb;
}

.capture-grid {
    display: grid;
    grid-template-columns: 1fr 150px 180px 160px 160px 140px;
    gap: 16px;
    align-items: end;
}

.capture-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.capture-field label {
    font-size: 12px;
    font-weight: 600;
    color: #4a5b6e;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}

.capture-field input {
    padding: 12px 14px;
    border: 1px solid #d0d5dd;
    border-radius: 12px;
    font-size: 14px;
    background: white;
}

.capture-field input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
}

/* Botones rápidos cantidad */
.qty-actions {
    display: flex;
    gap: 8px;
    margin-top: 8px;
}

.qty-btn {
    flex: 1;
    background: #f3f4f6;
    border: 1px solid #e5e7eb;
    padding: 6px 0;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.1s;
    color: #4a5b6e;
}

.qty-btn:hover {
    background: #3b82f6;
    color: white;
    border-color: #3b82f6;
}

.btn-add {
    background: #3b82f6;
    border: none;
    padding: 12px 20px;
    border-radius: 40px;
    color: white;
    font-weight: 700;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    white-space: nowrap;
}

.btn-add:hover {
    background: #2563eb;
    transform: scale(1.01);
}

/* Producto seleccionado */
.selected-info {
    background: #eff6ff;
    border-radius: 14px;
    padding: 16px;
    margin: 16px 0 0 0;
    border: 1px solid #bfdbfe;
}

.selected-info .row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
    font-size: 13px;
}

.selected-info .label {
    color: #4a5b6e;
}

.selected-info .value {
    color: #1a2c3e;
    font-weight: 600;
}

/* ===== MODAL GRANDE ESTILO EXCEL ===== */
.modal-excel {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.6);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 100000;
    backdrop-filter: blur(4px);
}

.modal-excel.active {
    display: flex;
}

.modal-excel-content {
    background: white;
    border-radius: 24px;
    width: 90%;
    max-width: 1100px;
    height: 80vh;
    max-height: 700px;
    display: flex;
    flex-direction: column;
    box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
    animation: modalZoom 0.2s ease;
}

@keyframes modalZoom {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
}

.modal-excel-header {
    padding: 20px 24px;
    border-bottom: 1px solid #e4e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #f8f9fa;
    border-radius: 24px 24px 0 0;
}

.modal-excel-header h3 {
    font-size: 20px;
    font-weight: 600;
    color: #1a2c3e;
    display: flex;
    align-items: center;
    gap: 10px;
}

.modal-excel-header .shortcut {
    font-size: 12px;
    color: #6c7a8a;
    background: #eef2f7;
    padding: 4px 10px;
    border-radius: 40px;
}

.modal-excel-close {
    background: none;
    border: none;
    font-size: 28px;
    cursor: pointer;
    color: #6c7a8a;
    transition: all 0.1s;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
}

.modal-excel-close:hover {
    background: #eef2f7;
    color: #1a2c3e;
}

.modal-excel-search {
    padding: 20px 24px;
    border-bottom: 1px solid #e4e7eb;
    background: white;
}

.modal-excel-search input {
    width: 100%;
    padding: 14px 18px;
    border: 2px solid #e4e7eb;
    border-radius: 14px;
    font-size: 16px;
    transition: all 0.2s;
}

.modal-excel-search input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
}

.modal-excel-table-container {
    flex: 1;
    overflow: auto;
    padding: 0 20px 20px 20px;
}

.modal-excel-table {
    width: 100%;
    border-collapse: collapse;
}

.modal-excel-table th {
    position: sticky;
    top: 0;
    background: #f8f9fa;
    padding: 14px 12px;
    text-align: left;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    color: #4a5b6e;
    border-bottom: 2px solid #e4e7eb;
    z-index: 10;
}

.modal-excel-table td {
    padding: 14px 12px;
    border-bottom: 1px solid #eef2f6;
    font-size: 14px;
    color: #1a2c3e;
}

.modal-excel-table tr {
    cursor: pointer;
    transition: background 0.1s;
}

.modal-excel-table tr:hover td {
    background: #f0f7ff;
}

.modal-excel-table tr.selected td {
    background: #1d4ed8;
    color: #ffffff;
    border-bottom-color: #3b82f6;
}

.modal-excel-table tr.selected .product-code,
.modal-excel-table tr.selected small {
    color: #ffffff;
}

.modal-excel-table tr.selected .copy-button {
    background: #ffffff;
    color: #1d4ed8;
    border-color: #ffffff;
}

.modal-excel-table .product-code {
    font-weight: 700;
    color: #2563eb;
}

.copyable-cell {
    cursor: copy;
    user-select: text;
}

.copyable-cell:hover {
    text-decoration: underline;
    text-decoration-style: dotted;
}

.copy-actions {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

.copy-button {
    border: 1px solid #bfdbfe;
    border-radius: 8px;
    padding: 6px 8px;
    background: #eff6ff;
    color: #1d4ed8;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
}

.copy-button:hover {
    background: #dbeafe;
}

.draft-modal-content {
    height: auto;
    max-height: 78vh;
    max-width: 850px;
}

.draft-list {
    overflow: auto;
    padding: 18px 22px;
}

.draft-empty {
    padding: 38px 18px;
    text-align: center;
    color: #64748b;
}

.draft-item {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 12px;
    align-items: center;
    padding: 14px 16px;
    margin-bottom: 10px;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    background: #f8fafc;
}

.draft-item h4 {
    margin: 0 0 5px;
    color: #1e293b;
    font-size: 15px;
}

.draft-item p {
    margin: 0;
    color: #64748b;
    font-size: 12px;
}

.draft-item-actions {
    display: flex;
    gap: 8px;
}

.draft-delete {
    color: #b91c1c;
    background: #fff1f2;
    border-color: #fecdd3;
}

.modal-excel-footer {
    padding: 16px 24px;
    border-top: 1px solid #e4e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #f8f9fa;
    border-radius: 0 0 24px 24px;
    font-size: 13px;
    color: #6c7a8a;
}

.modal-excel-footer kbd {
    background: white;
    border: 1px solid #d0d5dd;
    padding: 3px 8px;
    border-radius: 6px;
    font-family: monospace;
    margin: 0 4px;
}

/* ===== TABLA DE PRODUCTOS ===== */
.products-section {
    background: white;
    border-radius: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    border: 1px solid #e4e7eb;
    overflow: hidden;
}

.products-header {
    padding: 20px 24px;
    border-bottom: 1px solid #eef2f6;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}

.products-header h3 {
    font-size: 17px;
    font-weight: 600;
    color: #1a2c3e;
}

.product-badge {
    background: #eef2ff;
    padding: 5px 14px;
    border-radius: 40px;
    font-size: 13px;
    font-weight: 600;
    color: #3b82f6;
}

.table-responsive {
    overflow-x: auto;
    padding: 0 20px;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table th {
    text-align: left;
    padding: 14px 12px;
    color: #6c7a8a;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    border-bottom: 1px solid #eef2f6;
}

.data-table td {
    padding: 14px 12px;
    color: #1a2c3e;
    font-size: 14px;
    border-bottom: 1px solid #f0f2f5;
}

.data-table tr:hover td {
    background: #fafbfc;
}

.data-table tr.fila-capturada-activa td {
    background: #1d4ed8;
    color: #ffffff;
    border-bottom-color: #3b82f6;
}

.data-table tr.fila-capturada-activa td strong {
    color: #ffffff;
}

.data-table tr.fila-capturada-activa .qty-input,
.data-table tr.fila-capturada-activa .price-input {
    background: #ffffff;
    color: #111827;
    border-color: #93c5fd;
}

.data-table tr.fila-producto-capturado:focus {
    outline: 2px solid #f59e0b;
    outline-offset: -2px;
}

.qty-input, .price-input {
    width: 80px;
    padding: 8px;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    text-align: center;
    font-size: 13px;
}

.delete-btn {
    background: none;
    border: none;
    color: #ef4444;
    cursor: pointer;
    font-size: 18px;
    padding: 6px 10px;
    border-radius: 10px;
    transition: all 0.1s;
}

.delete-btn:hover {
    background: #fef2f2;
}

.table-footer {
    padding: 20px 24px;
    border-top: 1px solid #eef2f6;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
}

.total-amount {
    font-size: 28px;
    font-weight: 700;
    color: #059669;
}

.btn-save {
    background: #059669;
    border: none;
    padding: 12px 32px;
    border-radius: 40px;
    color: white;
    font-weight: 700;
    font-size: 14px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
}

.btn-save:hover {
    background: #047857;
}

.btn-clear {
    background: none;
    border: 1px solid #ef4444;
    padding: 12px 24px;
    border-radius: 40px;
    color: #ef4444;
    font-weight: 600;
    cursor: pointer;
}

/* Shortcuts bar */
.shortcuts-bar {
    margin-top: 20px;
    padding: 12px 20px;
    background: #f8f9fa;
    border-radius: 60px;
    text-align: center;
    font-size: 12px;
    color: #6c7a8a;
}

.shortcuts-bar kbd {
    background: white;
    border: 1px solid #d0d5dd;
    padding: 3px 8px;
    border-radius: 6px;
    font-family: monospace;
    margin: 0 4px;
    font-size: 11px;
}

/* Toast */
.toast-message {
    position: fixed;
    bottom: 30px;
    right: 30px;
    background: white;
    border-left: 4px solid #059669;
    padding: 14px 24px;
    border-radius: 12px;
    box-shadow: 0 8px 20px rgba(0,0,0,0.12);
    z-index: 100001;
    animation: slideIn 0.3s ease;
    font-size: 14px;
    color: #1a2c3e;
}

@keyframes slideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

/* Responsive */
@media (max-width: 1200px) {
    .capture-grid {
        grid-template-columns: 1fr 1fr;
    }
    
    .btn-add {
        grid-column: span 2;
    }
}

@media (max-width: 768px) {
    body {
        padding: 16px;
    }
    
    .doc-section, .capture-section {
        padding: 18px;
    }
    
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .capture-grid {
        grid-template-columns: 1fr;
    }
    
    .btn-add {
        grid-column: span 1;
    }
    
    .table-footer {
        flex-direction: column;
        align-items: stretch;
    }
    
    .btn-save, .btn-clear {
        width: 100%;
        justify-content: center;
    }
    
    .modal-excel-content {
        width: 95%;
        height: 85vh;
    }

    .draft-toolbar {
        grid-template-columns: 1fr;
    }

    .draft-current {
        grid-column: 1;
    }

    .draft-item {
        grid-template-columns: 1fr;
    }
}
</style>

<!-- HEADER -->
<div class="page-header">
    <h1><?= $modoEdicion ? '✏️ Editar Entrada' : '📥 Nueva Entrada de Almacén' ?></h1>
</div>

<div class="main-container">

<?php if ($message): ?>
    <div class="alert alert-<?= e($messageType) ?>" style="margin-bottom: 20px; padding: 14px 20px; border-radius: 12px; background: <?= $messageType === 'success' ? '#ecfdf5' : '#fef2f2' ?>; color: <?= $messageType === 'success' ? '#065f46' : '#991b1b' ?>; border-left: 4px solid <?= $messageType === 'success' ? '#059669' : '#ef4444' ?>;">
        <?= e($message) ?>
    </div>
<?php endif; ?>

<form method="POST" id="formEntrada">
    <?php if ($modoEdicion && $entradaEditar): ?>
        <input type="hidden" name="editar_id" value="<?= (int)$entradaEditar['id'] ?>">
    <?php else: ?>
        <input type="hidden" name="borrador_id" id="borradorIdInput" value="">
    <?php endif; ?>
    <input type="hidden" name="referencia" id="referencia_final" value="<?= e($referenciaEditar) ?>">

    <!-- SECCIÓN 1: DATOS DEL DOCUMENTO -->
    <div class="doc-section">
        <div class="form-grid">
            <div class="form-field">
                <label>📄 Folio</label>
                <input type="text" name="folio" id="folioEntrada" value="<?= e($folio) ?>" placeholder="Seleccione un almacén" data-modo-edicion="<?= $modoEdicion ? '1' : '0' ?>" readonly>
            </div>

            <div class="form-field">
                <label>📅 Fecha y hora</label>
                <input type="datetime-local" name="fecha" id="fechaInput" required value="<?= $fechaActual ?>">
            </div>

            <div class="form-field">
                <label>📋 Tipo de entrada *</label>
                <select name="tipo_entrada" required>
                    <option value="">Seleccione...</option>
                    <?php foreach ($tiposEntrada as $tipo): 
                        $tipoValue = $tipo['clave'] . ' - ' . $tipo['descripcion'];
                        $selected = ($modoEdicion && $tipoValue === $tipoEntradaSeleccionado) ? 'selected' : '';
                    ?>
                        <option value="<?= e($tipoValue) ?>" <?= $selected ?>>
                            <?= e($tipo['clave']) ?> - <?= e($tipo['descripcion']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-field">
    <label>🏪 Almacén *</label>

    <?php
    $almacenSeleccionado = $modoEdicion && $entradaEditar
        ? (int)($entradaEditar['almacen_id'] ?? $almacenSesion)
        : $almacenSesion;

    $puedeEditarAlmacen = in_array(
        strtoupper(trim($rolUsuario)),
        ['ADMINISTRADOR', 'ENCARGADO'],
        true
    );
    ?>

    <select
        name="almacen_id"
        id="almacenEntradaSelect"
        required
        <?= !$puedeEditarAlmacen ? 'disabled' : '' ?>
    >
        <option value="">Seleccione...</option>

        <?php foreach ($almacenes as $almacen): ?>
            <option
                value="<?= (int)$almacen['id'] ?>"
                <?= (int)$almacen['id'] === $almacenSeleccionado ? 'selected' : '' ?>
            >
                <?= e($almacen['nombre']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <?php if (!$puedeEditarAlmacen): ?>
        <input
            type="hidden"
            name="almacen_id"
            value="<?= (int)$almacenSeleccionado ?>"
        >
    <?php endif; ?>
</div>

            <div class="form-field">
                <label>🏢 Proveedor</label>
                <input type="text" name="proveedor_nombre" placeholder="Ingrese el nombre del proveedor" value="<?= $modoEdicion ? e($proveedorSeleccionado) : '' ?>">
            </div>

            <div class="form-field">
                <label>📑 Tipo de documento</label>
                <select name="tipo_documento" id="tipo_documento">
                    <option value="">Seleccione...</option>
                    <option value="FACTURA" <?= $modoEdicion && $tipoDoc === 'FACTURA' ? 'selected' : '' ?>>📄 Factura</option>
                    <option value="NOTA" <?= $modoEdicion && $tipoDoc === 'NOTA' ? 'selected' : '' ?>>📝 Nota</option>
                    <option value="REMISION" <?= $modoEdicion && $tipoDoc === 'REMISION' ? 'selected' : '' ?>>📋 Remisión</option>
                    <option value="TICKET" <?= $modoEdicion && $tipoDoc === 'TICKET' ? 'selected' : '' ?>>🎫 Ticket</option>
                    <option value="AJUSTE" <?= $modoEdicion && $tipoDoc === 'AJUSTE' ? 'selected' : '' ?>>⚙️ Ajuste</option>
                    <option value="TRASPASO" <?= $modoEdicion && $tipoDoc === 'TRASPASO' ? 'selected' : '' ?>>🚚 Traspaso</option>
                    <option value="OTRO" <?= $modoEdicion && $tipoDoc === 'OTRO' ? 'selected' : '' ?>>📎 Otro</option>
                </select>
            </div>

            <div class="form-field">
                <label>🔢 Folio del documento</label>
                <input type="text" name="folio_documento" id="folio_documento" placeholder="Ingrese folio" value="<?= $modoEdicion ? e($folioDoc) : '' ?>">
            </div>

            <div class="form-field">
                <label>📝 Observaciones</label>
                <textarea name="observaciones" placeholder="Información adicional..."><?= $modoEdicion ? e($observacionesEditar) : '' ?></textarea>
            </div>
        </div>
        
        <div class="folio-previous">
            <span id="folioAnteriorTexto">📋 Último folio registrado: <?= e($folioAnterior !== '' ? $folioAnterior : (($almacenSesion <= 0 && !$modoEdicion) ? 'Seleccione un almacén' : 'Sin entradas anteriores')) ?></span>
        </div>

        <?php if (!$modoEdicion): ?>
            <div class="draft-toolbar">
                <input
                    type="text"
                    id="nombreBorrador"
                    maxlength="150"
                    placeholder="Nombre del borrador (ej. Factura pendiente)"
                >
                <button
                    type="button"
                    class="draft-button primary"
                    id="guardarBorradorBtn"
                >
                    💾 Guardar borrador
                </button>
                <button
                    type="button"
                    class="draft-button secondary"
                    id="abrirBorradoresBtn"
                >
                    📂 Mis borradores
                    (<span id="borradoresCount">0</span>)
                </button>
                <div class="draft-current" id="estadoBorradorActual">
                    Puede guardar la captura aunque todavía esté incompleta.
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- SECCIÓN 2: CAPTURA RÁPIDA -->
    <div class="capture-section">
        <div class="capture-title">➕ Agregar productos</div>
        
        <div class="capture-grid">
            <div class="capture-field">
                <label>🔍 Producto</label>
                <div style="display: flex; gap: 8px;">
                    <input type="text" id="productoDisplayInput" placeholder="Presiona Ctrl+B para buscar" readonly style="background: #f8f9fa; cursor: pointer;">
                    <button type="button" id="openModalBtn" style="background: #3b82f6; border: none; padding: 0 20px; border-radius: 12px; color: white; font-weight: 600; cursor: pointer;">🔍 Buscar</button>
                </div>
            </div>

            <div class="capture-field">
                <label>🔢 Cantidad</label>
                <input type="number" id="cantidadInput" value="1" min="1" step="1">
                <div class="qty-actions">
                    <button type="button" class="qty-btn" onclick="cambiarCantidad(1)">+1</button>
                    <button type="button" class="qty-btn" onclick="cambiarCantidad(5)">+5</button>
                    <button type="button" class="qty-btn" onclick="cambiarCantidad(10)">+10</button>
                </div>
            </div>

            <div class="capture-field">
                <label>💰 Costo de entrada (nuevo costo último)</label>
                <input type="number" id="precioInput" step="0.01" value="0.00">
            </div>

            <div class="capture-field">
                <label>🔢 Número de lote</label>
                <input type="text" id="loteInput" placeholder="Opcional">
            </div>

            <div class="capture-field">
                <label>📍 Ubicación</label>
                <input
                    type="text"
                    id="ubicacionInput"
                    list="ubicacionesList"
                    data-ubicacion-rapida
                    data-ubicacion-placeholder="R_N_Z__"
                    placeholder="R_N_Z__"
                    autocomplete="off"
                >
                <datalist id="ubicacionesList">
                    <?php foreach ($ubicacionesGenerales as $ubicacion): ?>
                        <option value="<?= e($ubicacion) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <small style="font-size: 11px; color: #6c7a8a;">
                    <?= count($ubicacionesGenerales) ?> ubicación(es) disponible(s)
                </small>
                <small class="ubicacion-rapida-ayuda">
                    Escribe solo números: <strong>1 1 01</strong> se convierte en <strong>R1N1Z01</strong>. Usa ↑ ↓ y Enter.
                </small>
            </div>

            <button type="button" class="btn-add" id="agregarBtn">
                ➕ Agregar producto (Enter)
            </button>
        </div>

        <div id="selectedInfo" style="display: none;">
            <div class="selected-info">
                <div class="row"><span class="label">📦 Producto:</span><span class="value" id="infoCodigo">-</span></div>
                <div class="row"><span class="label">📝 Descripción:</span><span class="value" id="infoDescripcion">-</span></div>
                <div class="row"><span class="label">💵 Costo último actual:</span><span class="value" id="infoCostoUltimo">-</span></div>
                <div class="row"><span class="label">📊 Costo promedio actual:</span><span class="value" id="infoCostoPromedio">-</span></div>
                <div class="row"><span class="label">📦 Existencia total:</span><span class="value" id="infoExistenciaTotal">-</span></div>
                <div class="row"><span class="label">📍 Ubicación sugerida:</span><span class="value" id="infoUbicacion">-</span></div>
            </div>
        </div>

        <div class="shortcuts-bar">
            🎯 <kbd>Ctrl</kbd> + <kbd>B</kbd> Abrir buscador &nbsp;&nbsp;|&nbsp;&nbsp;
            <kbd>↑</kbd> <kbd>↓</kbd> Navegar resultados &nbsp;&nbsp;|&nbsp;&nbsp;
            <kbd>Enter</kbd> Seleccionar producto &nbsp;&nbsp;|&nbsp;&nbsp;
            <kbd>Enter</kbd> (en cantidad/precio/ubicación) Agregar &nbsp;&nbsp;|&nbsp;&nbsp;
            <kbd>↑</kbd> <kbd>↓</kbd> Revisar renglones capturados &nbsp;&nbsp;|&nbsp;&nbsp;
            <kbd>Ctrl</kbd> + <kbd>Enter</kbd> Guardar entrada
        </div>
    </div>

    <!-- SECCIÓN 3: TABLA DE PRODUCTOS -->
    <div class="products-section">
        <div class="products-header">
            <h3>📋 Productos agregados</h3>
            <span class="product-badge" id="productosCount">0 productos</span>
        </div>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Cantidad</th>
                        <th>Código</th>
                        <th>Descripción</th>
                        <th>Costo último</th>
                        <th>Costo promedio</th>
                        <th>Lote</th>
                        <th>Ubicación</th>
                        <th>Importe</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="detalleBody">
                    <?php if ($modoEdicion && !empty($entradaEditar['detalles'])): ?>
                        <?php foreach ($entradaEditar['detalles'] as $item): 
                            $cantidad = (int)($item['cantidad'] ?? 0);
                            $costoUnitario = (float)($item['costo_unitario'] ?? 0);
                            $precio = $costoUnitario;
                            $importe = $cantidad * $precio;
                            $productoId = (int)($item['producto_id'] ?? 0);
                            $costoPromedioActual = 0.0;
                            foreach ($productosJson as $productoCostoTmp) {
                                if ((int)$productoCostoTmp['id'] === $productoId) {
                                    $costoPromedioActual = (float)($productoCostoTmp['costo_promedio'] ?? 0);
                                    break;
                                }
                            }
                            $ubicacion = trim($item['ubicacion'] ?? '');
                            $codigo = e($item['codigo'] ?? '');
                            $descripcion = e(substr($item['descripcion'] ?? '', 0, 60));
                            $lote = e($item['numero_lote'] ?? '');
                        ?>
                            <tr
                                class="fila-producto-capturado"
                                tabindex="0"
                                data-producto-id="<?= $productoId ?>"
                            >
                                <td>
                                    <input type="number" class="qty-input" value="<?= $cantidad ?>" min="1" onchange="actualizarCantidadFila(this)" style="width:70px; padding:6px;">
                                    <input type="hidden" name="cantidad[]" value="<?= $cantidad ?>">
                                    <input type="hidden" name="producto_id[]" value="<?= $productoId ?>">
                                    <input type="hidden" name="costo_unitario[]" value="<?= $costoUnitario ?>">
                                    <input type="hidden" name="numero_lote[]" value="<?= e($lote) ?>">
                                    <input type="hidden" name="fecha_caducidad[]" value="">
                                    <input type="hidden" name="ubicacion[]" value="<?= e($ubicacion) ?>">
                                </td>
                                <td><strong><?= $codigo ?></strong></td>
                                <td><?= $descripcion ?></td>
                                <td>
                                    <input type="number" class="price-input" value="<?= number_format($precio, 2, '.', '') ?>" step="0.01" min="0" onchange="actualizarPrecioFila(this)" style="width:90px; padding:6px;">
                                </td>
                                <td class="costo-promedio-estimado" data-costo-promedio="<?= e((string)$costoPromedioActual) ?>">
                                    $<?= number_format($costoPromedioActual, 4) ?>
                                </td>
                                <td><?= e($lote) ?></td>
                                <td><?= e($ubicacion) ?></td>
                                <td class="importe-fila" data-importe="<?= $importe ?>"><strong>$<?= number_format($importe, 2) ?></strong></td>
                                <td><button type="button" class="delete-btn" onclick="eliminarFila(this)">🗑️</button></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr id="filaVacia">
                            <td colspan="9" style="text-align: center; padding: 50px; color: #9ca3af;">
                                📭 No hay productos. Presiona Ctrl+B para buscar
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="table-footer">
            <button type="button" class="btn-clear" onclick="limpiarTodo()">🗑️ Limpiar todo</button>
            <div>
                <span style="color: #6c7a8a;">Total general:</span>
                <span class="total-amount" id="totalEntrada">$0.00</span>
            </div>
            <button type="button" class="btn-save" id="guardarBtn">
                💾 Guardar entrada (Ctrl+Enter)
            </button>
        </div>
    </div>
</form>
</div>

<!-- MODAL GRANDE ESTILO EXCEL -->
<div class="modal-excel" id="modalExcel">
    <div class="modal-excel-content">
        <div class="modal-excel-header">
            <h3>🔍 Buscar producto <span class="shortcut">Ctrl+B para abrir</span></h3>
            <button class="modal-excel-close" id="closeModalBtn">✕</button>
        </div>
        <div class="modal-excel-search">
            <input type="text" id="modalSearchInput" placeholder="Escribe código o nombre del producto..." autocomplete="off">
        </div>
        <div class="modal-excel-table-container">
            <table class="modal-excel-table">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Descripción</th>
                        <th>Costo último</th>
                        <th>Costo promedio</th>
                        <th>Ubicación sugerida</th>
                        <th>Ubicaciones disponibles</th>
                        <th>Copiar</th>
                    </tr>
                </thead>
                <tbody id="modalTableBody"></tbody>
            </table>
        </div>
        <div class="modal-excel-footer">
            <span><kbd>↑</kbd> <kbd>↓</kbd> Navegar | <kbd>Enter</kbd> Seleccionar | <kbd>Esc</kbd> Cerrar</span>
            <span>Mostrando <span id="modalResultCount">0</span> productos</span>
        </div>
    </div>
</div>

<?php if (!$modoEdicion): ?>
<div class="modal-excel" id="modalBorradores">
    <div class="modal-excel-content draft-modal-content">
        <div class="modal-excel-header">
            <h3>📂 Borradores de entradas</h3>
            <button
                type="button"
                class="modal-excel-close"
                id="cerrarBorradoresBtn"
            >✕</button>
        </div>
        <div class="draft-list" id="listaBorradores">
            <div class="draft-empty">Cargando borradores...</div>
        </div>
        <div class="modal-excel-footer">
            <span>Los borradores solamente aparecen en su cuenta.</span>
            <span>Puede continuar o eliminar cada captura.</span>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="assets/js/ubicaciones-rapidas.js?v=20260729"></script>
<script>
// ===== VARIABLES =====
const productos = <?php echo json_encode($productosJson, JSON_UNESCAPED_UNICODE); ?>;
const csrfEntrada = <?php echo json_encode($csrfEntrada, JSON_UNESCAPED_UNICODE); ?>;
const modoEdicionEntrada = <?php echo $modoEdicion ? 'true' : 'false'; ?>;

console.log('Productos cargados:', productos.length);

let productoSeleccionado = null;
let modalProductosFiltrados = [];
let modalSelectedIndex = -1;
let borradorActualId = 0;

// Elementos DOM
const modal = document.getElementById('modalExcel');
const modalSearch = document.getElementById('modalSearchInput');
const modalTableBody = document.getElementById('modalTableBody');
const modalResultCount = document.getElementById('modalResultCount');
const productoDisplayInput = document.getElementById('productoDisplayInput');
const cantidadInput = document.getElementById('cantidadInput');
const precioInput = document.getElementById('precioInput');
const loteInput = document.getElementById('loteInput');
const ubicacionInput = document.getElementById('ubicacionInput');
const detalleBody = document.getElementById('detalleBody');
const modalBorradores = document.getElementById('modalBorradores');
const listaBorradores = document.getElementById('listaBorradores');
const nombreBorrador = document.getElementById('nombreBorrador');
const borradorIdInput = document.getElementById('borradorIdInput');

// ===== FECHA AUTOMÁTICA =====
function ponerFechaActual() {
    const fechaInput = document.getElementById('fechaInput');
    if (!fechaInput.value) {
        const ahora = new Date();
        const year = ahora.getFullYear();
        const month = String(ahora.getMonth() + 1).padStart(2, '0');
        const day = String(ahora.getDate()).padStart(2, '0');
        const hours = String(ahora.getHours()).padStart(2, '0');
        const minutes = String(ahora.getMinutes()).padStart(2, '0');
        fechaInput.value = `${year}-${month}-${day}T${hours}:${minutes}`;
    }
}

// ===== MODAL =====
function abrirModal() {
    console.log('Abriendo modal, productos:', productos.length);
    if (productos.length === 0) {
        mostrarToast('⚠️ No hay productos disponible', 'warning');
        return;
    }
    modal.classList.add('active');
    modalSearch.value = '';
    cargarProductosEnModal(productos);
    setTimeout(() => modalSearch.focus(), 100);
    modalSelectedIndex = -1;
}

function cerrarModal() {
    modal.classList.remove('active');
}

function cargarProductosEnModal(productosList) {
    modalProductosFiltrados = productosList;
    modalResultCount.textContent = productosList.length;
    
    if (productosList.length === 0) {
        modalTableBody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 40px;">No se encontraron productos</td></tr>';
        return;
    }
    
    modalTableBody.innerHTML = productosList.map((p, idx) => {
        const ubicacionesTexto = p.ubicaciones.map(u => `${u.ubicacion}(${u.existencia_actual})`).join(', ');
        return `
            <tr data-idx="${idx}" data-producto-id="${p.id}" style="cursor: pointer;">
                <td
                    class="product-code copyable-cell"
                    data-copy-type="codigo"
                    data-copy-idx="${idx}"
                    title="Clic para copiar el código"
                >${escapeHtml(p.codigo)}</td>
                <td
                    class="copyable-cell"
                    data-copy-type="descripcion"
                    data-copy-idx="${idx}"
                    title="Clic para copiar la descripción"
                >${escapeHtml(p.descripcion)}</td>
                <td>$${Number(p.costo_ultimo ?? p.precio_compra ?? 0).toFixed(2)}</td>
                <td>$${Number(p.costo_promedio ?? p.costo_ultimo ?? p.precio_compra ?? 0).toFixed(4)}</td>
                <td>${escapeHtml(p.ubicacion_sugerida || 'N/A')}</td>
                <td><small>${escapeHtml(ubicacionesTexto || 'Ninguna')}</small></td>
                <td>
                    <div class="copy-actions">
                        <button
                            type="button"
                            class="copy-button"
                            data-copy-type="codigo"
                            data-copy-idx="${idx}"
                        >Código</button>
                        <button
                            type="button"
                            class="copy-button"
                            data-copy-type="descripcion"
                            data-copy-idx="${idx}"
                        >Descripción</button>
                    </div>
                </td>
            </tr>
        `;
    }).join('');
    
    document.querySelectorAll('#modalTableBody tr').forEach(row => {
        row.addEventListener('click', () => {
            const idx = parseInt(row.dataset.idx);
            if (modalProductosFiltrados[idx]) {
                seleccionarProductoDelModal(modalProductosFiltrados[idx]);
            }
        });
    });

    document.querySelectorAll(
        '#modalTableBody .copy-button, '
        + '#modalTableBody .copyable-cell'
    ).forEach(control => {
        control.addEventListener('click', function(event) {
            event.preventDefault();
            event.stopPropagation();

            const idx = Number(control.dataset.copyIdx);
            const tipo = control.dataset.copyType;
            const producto = modalProductosFiltrados[idx];

            if (!producto) return;

            const texto = tipo === 'codigo'
                ? producto.codigo
                : producto.descripcion;
            const etiqueta = tipo === 'codigo'
                ? 'Código'
                : 'Descripción';

            copiarTexto(texto, etiqueta);
        });
    });
}

async function copiarTexto(texto, etiqueta) {
    const valor = String(texto ?? '');

    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(valor);
        } else {
            const auxiliar = document.createElement('textarea');
            auxiliar.value = valor;
            auxiliar.setAttribute('readonly', '');
            auxiliar.style.position = 'fixed';
            auxiliar.style.opacity = '0';
            document.body.appendChild(auxiliar);
            auxiliar.select();

            if (!document.execCommand('copy')) {
                throw new Error('El navegador rechazó la copia.');
            }

            auxiliar.remove();
        }

        mostrarToast(`📋 ${etiqueta} copiada`);
    } catch (error) {
        mostrarToast(
            `❌ No se pudo copiar ${etiqueta.toLowerCase()}`,
            'error'
        );
    }
}

function filtrarProductosModal() {
    const termino = modalSearch.value.toLowerCase().trim();
    if (!termino) {
        cargarProductosEnModal(productos);
        return;
    }
    const filtrados = productos.filter(p => 
        p.codigo.toLowerCase().includes(termino) || 
        p.descripcion.toLowerCase().includes(termino)
    );
    cargarProductosEnModal(filtrados);
    modalSelectedIndex = -1;
}

function seleccionarProductoDelModal(producto) {
    productoSeleccionado = producto;
    
    document.getElementById('selectedInfo').style.display = 'block';
    document.getElementById('infoCodigo').innerHTML = `<strong>${escapeHtml(producto.codigo)}</strong>`;
    document.getElementById('infoDescripcion').textContent = producto.descripcion;
    document.getElementById('infoCostoUltimo').textContent = `$${Number(producto.costo_ultimo ?? producto.precio_compra ?? 0).toFixed(2)}`;
    document.getElementById('infoCostoPromedio').textContent = `$${Number(producto.costo_promedio ?? producto.costo_ultimo ?? producto.precio_compra ?? 0).toFixed(4)}`;
    document.getElementById('infoExistenciaTotal').textContent = Number(producto.existencia_total ?? 0).toLocaleString();
    document.getElementById('infoUbicacion').textContent = producto.ubicacion_sugerida || 'No definida';
    
    productoDisplayInput.value = `${producto.codigo} - ${producto.descripcion.substring(0, 50)}`;
    precioInput.value = Number(producto.costo_ultimo ?? producto.precio_compra ?? 0).toFixed(2);
    if (producto.ubicacion_sugerida) {
        window.UbicacionesRapidas?.establecerValor(
            ubicacionInput,
            producto.ubicacion_sugerida
        );
    }
    
    cerrarModal();
    cantidadInput.focus();
    cantidadInput.select();
    
    mostrarToast(`✅ Producto seleccionado: ${producto.codigo}`);
}

function actualizarSeleccionModal() {
    const filas = document.querySelectorAll('#modalTableBody tr');
    filas.forEach((fila, idx) => {
        if (idx === modalSelectedIndex) {
            fila.classList.add('selected');
            fila.scrollIntoView({ block: 'nearest' });
        } else {
            fila.classList.remove('selected');
        }
    });
}

// ===== CANTIDAD =====
function cambiarCantidad(valor) {
    let nuevo = parseInt(cantidadInput.value) + valor;
    if (nuevo < 1) nuevo = 1;
    cantidadInput.value = nuevo;
}

// ===== AGREGAR PRODUCTO =====
function agregarProducto() {
    if (!productoSeleccionado) {
        mostrarToast('❌ Selecciona un producto (Ctrl+B para buscar)', 'error');
        return;
    }
    
    const cantidad = parseInt(cantidadInput.value);
    const precio = parseFloat(precioInput.value);
    const lote = loteInput.value.trim();
    const ubicacionNormalizada = window.UbicacionesRapidas
        ?.obtenerValor(ubicacionInput);

    if (ubicacionNormalizada === null) {
        mostrarToast('❌ Completa la ubicación. Ejemplo: 1 1 01', 'error');
        ubicacionInput.reportValidity();
        ubicacionInput.focus();
        return;
    }

    let ubicacion = (ubicacionNormalizada ?? ubicacionInput.value)
        .trim()
        .toUpperCase();
    
    if (!ubicacion) ubicacion = productoSeleccionado.ubicacion_sugerida || 'SIN UBICACION';
    
    if (cantidad <= 0 || isNaN(cantidad)) {
        mostrarToast('❌ Cantidad inválida', 'error');
        return;
    }
    
    if (precio < 0 || isNaN(precio)) {
        mostrarToast('❌ Precio inválido', 'error');
        return;
    }
    
    if (ubicacion === 'SIN UBICACION') {
        mostrarToast('❌ Selecciona una ubicación válida', 'error');
        ubicacionInput.focus();
        return;
    }
    
    const filaVacia = document.getElementById('filaVacia');
    if (filaVacia) filaVacia.remove();
    
    crearFilaProductoCapturado({
        producto_id: productoSeleccionado.id,
        codigo: productoSeleccionado.codigo,
        descripcion: productoSeleccionado.descripcion,
        cantidad,
        precio,
        lote,
        ubicacion
    });

    actualizarTotales();
    
    // Resetear
    productoSeleccionado = null;
    document.getElementById('selectedInfo').style.display = 'none';
    productoDisplayInput.value = '';
    cantidadInput.value = '1';
    precioInput.value = '0.00';
    loteInput.value = '';
    window.UbicacionesRapidas?.limpiar(ubicacionInput);
    
    mostrarToast(`✅ ${cantidad} x producto agregado`);
}

function crearFilaProductoCapturado(item) {
    const cantidad = Math.max(
        1,
        Number.parseInt(item.cantidad, 10) || 1
    );
    const precio = Math.max(
        0,
        Number.parseFloat(item.precio) || 0
    );
    const productoId = Number.parseInt(
        item.producto_id,
        10
    ) || 0;
    const codigo = String(item.codigo ?? '');
    const descripcion = String(item.descripcion ?? '');
    const lote = String(item.lote ?? '');
    const ubicacion = String(item.ubicacion ?? '')
        .trim()
        .toUpperCase();
    const importe = cantidad * precio;
    const tr = document.createElement('tr');

    tr.dataset.productoId = productoId;
    tr.className = 'fila-producto-capturado';
    tr.tabIndex = 0;
    tr.innerHTML = `
        <td>
            <input type="number" class="qty-input" value="${cantidad}" min="1" onchange="actualizarCantidadFila(this)" style="width:70px; padding:6px;">
            <input type="hidden" name="cantidad[]" value="${cantidad}">
            <input type="hidden" name="producto_id[]" value="${productoId}">
            <input type="hidden" name="costo_unitario[]" value="${precio.toFixed(2)}">
            <input type="hidden" name="numero_lote[]" value="${escapeHtml(lote)}">
            <input type="hidden" name="fecha_caducidad[]" value="">
            <input type="hidden" name="ubicacion[]" value="${escapeHtml(ubicacion)}">
        </td>
        <td><strong>${escapeHtml(codigo)}</strong></td>
        <td title="${escapeHtml(descripcion)}">${escapeHtml(descripcion.substring(0, 60))}</td>
        <td>
            <input type="number" class="price-input" value="${precio.toFixed(2)}" step="0.01" min="0" onchange="actualizarPrecioFila(this)" style="width:90px; padding:6px;">
        </td>
        <td class="costo-promedio-estimado">$0.0000</td>
        <td>${escapeHtml(lote)}</td>
        <td>${escapeHtml(ubicacion)}</td>
        <td class="importe-fila" data-importe="${importe}"><strong>$${importe.toFixed(2)}</strong></td>
        <td><button type="button" class="delete-btn" onclick="eliminarFila(this)">🗑️</button></td>
    `;
    
    detalleBody.appendChild(tr);
    prepararNavegacionFilas();
    activarFilaCapturada(tr);
    actualizarCostosPromedioEstimados();

    return tr;
}

function obtenerFilasCapturadas() {
    return Array.from(
        detalleBody.querySelectorAll(
            'tr.fila-producto-capturado'
        )
    );
}

function activarFilaCapturada(fila, enfocar = false, selector = '') {
    obtenerFilasCapturadas().forEach(item => {
        item.classList.toggle(
            'fila-capturada-activa',
            item === fila
        );
    });

    if (!enfocar || !fila) return;

    const destino = selector
        ? fila.querySelector(selector)
        : fila;

    destino?.focus();

    if (destino instanceof HTMLInputElement) {
        destino.select();
    }
}

function prepararNavegacionFilas() {
    obtenerFilasCapturadas().forEach(fila => {
        if (fila.dataset.navegacionLista === '1') {
            return;
        }

        fila.dataset.navegacionLista = '1';
        fila.addEventListener('click', event => {
            const esControl = event.target.closest(
                'input, button, select, textarea'
            );

            activarFilaCapturada(
                fila,
                !esControl
            );
        });
        fila.addEventListener('focusin', () => {
            activarFilaCapturada(fila);
        });
    });
}

function navegarFilasCapturadas(event) {
    if (event.key !== 'ArrowUp'
        && event.key !== 'ArrowDown'
    ) {
        return;
    }

    if (modal?.classList.contains('active')
        || modalBorradores?.classList.contains('active')
    ) {
        return;
    }

    let filaActual = event.target.closest?.(
        'tr.fila-producto-capturado'
    );

    if (!filaActual) {
        const esCampoEditable = event.target.matches?.(
            'input, select, textarea'
        );

        if (esCampoEditable) return;

        filaActual = detalleBody.querySelector(
            'tr.fila-capturada-activa'
        );
    }

    if (!filaActual) return;

    const filas = obtenerFilasCapturadas();
    const indiceActual = filas.indexOf(filaActual);
    const desplazamiento = event.key === 'ArrowDown'
        ? 1
        : -1;
    const indiceNuevo = Math.max(
        0,
        Math.min(
            filas.length - 1,
            indiceActual + desplazamiento
        )
    );
    const selector = event.target.classList?.contains('qty-input')
        ? '.qty-input'
        : (
            event.target.classList?.contains('price-input')
                ? '.price-input'
                : ''
        );

    event.preventDefault();
    activarFilaCapturada(
        filas[indiceNuevo],
        true,
        selector
    );
}


function actualizarCostosPromedioEstimados() {
    const estados = new Map();

    document.querySelectorAll('#detalleBody tr[data-producto-id]').forEach((tr) => {
        const productoId = Number.parseInt(tr.dataset.productoId || '0', 10);
        const producto = productos.find((p) => Number(p.id) === productoId);
        const celdaPromedio = tr.querySelector('.costo-promedio-estimado');

        if (!producto || !celdaPromedio) {
            return;
        }

        if (modoEdicionEntrada) {
            const promedioActual = Number(
                producto.costo_promedio
                ?? producto.costo_ultimo
                ?? producto.precio_compra
                ?? 0
            );
            celdaPromedio.textContent = `$${promedioActual.toFixed(4)}`;
            return;
        }

        if (!estados.has(productoId)) {
            estados.set(productoId, {
                existencia: Math.max(0, Number(producto.existencia_total ?? 0)),
                promedio: Math.max(
                    0,
                    Number(
                        producto.costo_promedio
                        ?? producto.costo_ultimo
                        ?? producto.precio_compra
                        ?? 0
                    )
                ),
            });
        }

        const estado = estados.get(productoId);
        const cantidad = Math.max(
            0,
            Number.parseInt(tr.querySelector('.qty-input')?.value || '0', 10) || 0
        );
        const costoNuevo = Math.max(
            0,
            Number.parseFloat(tr.querySelector('.price-input')?.value || '0') || 0
        );

        const existenciaNueva = estado.existencia + cantidad;
        const promedioNuevo = existenciaNueva > 0
            ? (
                (estado.existencia * estado.promedio)
                + (cantidad * costoNuevo)
            ) / existenciaNueva
            : costoNuevo;

        celdaPromedio.textContent = `$${promedioNuevo.toFixed(4)}`;
        celdaPromedio.dataset.costoPromedio = promedioNuevo.toFixed(4);

        estado.existencia = existenciaNueva;
        estado.promedio = promedioNuevo;
    });
}

function actualizarCantidadFila(input) {
    const tr = input.closest('tr');
    let cantidad = parseInt(input.value);
    if (isNaN(cantidad) || cantidad < 1) cantidad = 1;
    
    const precioInputFila = tr.querySelector('.price-input');
    const precio = parseFloat(precioInputFila?.value || '0');
    const nuevoImporte = cantidad * precio;
    
    const importeTd = tr.querySelector('.importe-fila');
    if (importeTd) {
        importeTd.dataset.importe = nuevoImporte;
        importeTd.innerHTML = `<strong>$${nuevoImporte.toFixed(2)}</strong>`;
    }
    
    const hiddenInput = tr.querySelector('input[name="cantidad[]"]');
    if (hiddenInput) hiddenInput.value = cantidad;
    
    actualizarTotales();
    actualizarCostosPromedioEstimados();
}

function actualizarPrecioFila(input) {
    const tr = input.closest('tr');
    let precio = parseFloat(input.value);
    if (isNaN(precio) || precio < 0) precio = 0;
    
    const cantidadInputFila = tr.querySelector('.qty-input');
    const cantidad = parseInt(cantidadInputFila?.value || '0');
    const nuevoImporte = cantidad * precio;
    
    const importeTd = tr.querySelector('.importe-fila');
    if (importeTd) {
        importeTd.dataset.importe = nuevoImporte;
        importeTd.innerHTML = `<strong>$${nuevoImporte.toFixed(2)}</strong>`;
    }
    
    const hiddenInput = tr.querySelector('input[name="costo_unitario[]"]');
    if (hiddenInput) hiddenInput.value = precio.toFixed(2);
    
    actualizarTotales();
    actualizarCostosPromedioEstimados();
}

function eliminarFila(btn) {
    btn.closest('tr').remove();
    if (detalleBody.children.length === 0) {
        detalleBody.innerHTML = '<tr id="filaVacia"><td colspan="9" style="text-align: center; padding: 50px; color: #9ca3af;">📭 No hay productos. Presiona Ctrl+B para buscar</td></tr>';
    }
    prepararNavegacionFilas();
    actualizarTotales();
    actualizarCostosPromedioEstimados();
    mostrarToast('🗑️ Producto eliminado', 'info');
}

function limpiarTodo() {
    if (confirm('¿Eliminar todos los productos?')) {
        detalleBody.innerHTML = '<tr id="filaVacia"><td colspan="9" style="text-align: center; padding: 50px; color: #9ca3af;">📭 No hay productos. Presiona Ctrl+B para buscar</td></tr>';
        prepararNavegacionFilas();
        actualizarTotales();
        mostrarToast('🧹 Lista limpiada', 'info');
    }
}

function actualizarTotales() {
    let total = 0;
    let count = 0;
    document.querySelectorAll('.importe-fila').forEach(td => {
        total += parseFloat(td.dataset.importe || '0');
        count++;
    });
    document.getElementById('totalEntrada').innerHTML = `$${total.toFixed(2)}`;
    document.getElementById('productosCount').textContent = `${count} producto${count !== 1 ? 's' : ''}`;
}

function mostrarToast(mensaje, tipo = 'success') {
    const toast = document.createElement('div');
    toast.className = 'toast-message';
    toast.style.borderLeftColor = tipo === 'success' ? '#059669' : (tipo === 'error' ? '#ef4444' : '#f59e0b');
    toast.innerHTML = mensaje;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 2500);
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        if (m === '"') return '&quot;';
        if (m === "'") return '&#039;';
        return m;
    });
}

function valorCampo(selector) {
    return document.querySelector(selector)?.value ?? '';
}

function obtenerAlmacenCaptura() {
    return valorCampo('select[name="almacen_id"]')
        || valorCampo('input[name="almacen_id"]');
}

function obtenerProductosBorrador() {
    return obtenerFilasCapturadas().map(fila => ({
        producto_id: Number(fila.dataset.productoId || 0),
        codigo: fila.cells[1]?.innerText.trim() || '',
        descripcion: fila.cells[2]?.title
            || fila.cells[2]?.innerText.trim()
            || '',
        cantidad: Number(
            fila.querySelector('.qty-input')?.value || 0
        ),
        precio: Number(
            fila.querySelector('.price-input')?.value || 0
        ),
        lote: fila.querySelector(
            'input[name="numero_lote[]"]'
        )?.value || '',
        ubicacion: fila.querySelector(
            'input[name="ubicacion[]"]'
        )?.value || ''
    }));
}

function obtenerDatosBorrador() {
    construirReferencia();

    return {
        folio: valorCampo('input[name="folio"]'),
        fecha: valorCampo('input[name="fecha"]'),
        tipo_entrada: valorCampo('select[name="tipo_entrada"]'),
        almacen_id: Number(obtenerAlmacenCaptura() || 0),
        proveedor_nombre: valorCampo(
            'input[name="proveedor_nombre"]'
        ),
        tipo_documento: tipoDocumento?.value || '',
        folio_documento: folioDocumento?.value || '',
        referencia: referenciaFinal?.value || '',
        observaciones: valorCampo(
            'textarea[name="observaciones"]'
        ),
        producto_pendiente: productoSeleccionado
            ? {
                id: productoSeleccionado.id,
                codigo: productoSeleccionado.codigo,
                descripcion: productoSeleccionado.descripcion,
                precio_compra: productoSeleccionado.precio_compra,
                ubicacion_sugerida:
                    productoSeleccionado.ubicacion_sugerida || '',
                ubicaciones: productoSeleccionado.ubicaciones || []
            }
            : null,
        captura_producto: {
            cantidad: cantidadInput?.value || '1',
            precio: precioInput?.value || '0.00',
            lote: loteInput?.value || '',
            ubicacion: ubicacionInput?.value || ''
        },
        productos: obtenerProductosBorrador()
    };
}

async function solicitarJson(url, opciones = {}) {
    const respuesta = await fetch(url, {
        credentials: 'same-origin',
        cache: 'no-store',
        ...opciones
    });
    const datos = await respuesta.json().catch(() => ({
        success: false,
        message: 'El servidor devolvió una respuesta inválida.'
    }));

    if (!respuesta.ok || !datos.success) {
        throw new Error(
            datos.message || 'No se pudo completar la operación.'
        );
    }

    return datos;
}

async function guardarBorradorEntrada() {
    if (modoEdicionEntrada) return;

    const boton = document.getElementById(
        'guardarBorradorBtn'
    );
    const datos = obtenerDatosBorrador();

    if (datos.almacen_id <= 0) {
        mostrarToast(
            '❌ Seleccione un almacén antes de guardar.',
            'error'
        );
        return;
    }

    boton.disabled = true;
    const textoOriginal = boton.textContent;
    boton.textContent = 'Guardando...';

    try {
        const respuesta = await solicitarJson(
            'entradas.php?action=guardar_borrador',
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    csrf: csrfEntrada,
                    borrador_id: borradorActualId,
                    nombre: nombreBorrador?.value || '',
                    almacen_id: datos.almacen_id,
                    datos
                })
            }
        );
        const borrador = respuesta.borrador;

        borradorActualId = Number(borrador.id || 0);

        if (borradorIdInput) {
            borradorIdInput.value = String(
                borradorActualId
            );
        }

        if (nombreBorrador) {
            nombreBorrador.value = borrador.nombre || '';
        }

        actualizarEstadoBorrador(
            `Borrador "${borrador.nombre}" guardado. `
            + `${borrador.total_productos} producto(s).`
        );
        mostrarToast('💾 Borrador guardado');
        await actualizarConteoBorradores();
    } catch (error) {
        mostrarToast(
            `❌ ${error.message}`,
            'error'
        );
    } finally {
        boton.disabled = false;
        boton.textContent = textoOriginal;
    }
}

function actualizarEstadoBorrador(texto) {
    const estado = document.getElementById(
        'estadoBorradorActual'
    );

    if (estado) {
        estado.textContent = texto;
    }
}

function formatearFechaBorrador(fecha) {
    if (!fecha) return 'Sin fecha';

    const valor = String(fecha).replace(' ', 'T');
    const fechaObjeto = new Date(valor);

    if (Number.isNaN(fechaObjeto.getTime())) {
        return String(fecha);
    }

    return fechaObjeto.toLocaleString('es-MX');
}

async function obtenerListaBorradores() {
    const respuesta = await solicitarJson(
        'entradas.php?action=listar_borradores'
    );

    return Array.isArray(respuesta.borradores)
        ? respuesta.borradores
        : [];
}

async function actualizarConteoBorradores() {
    if (modoEdicionEntrada) return;

    try {
        const borradores = await obtenerListaBorradores();
        const contador = document.getElementById(
            'borradoresCount'
        );

        if (contador) {
            contador.textContent = String(
                borradores.length
            );
        }
    } catch (error) {
        actualizarEstadoBorrador(
            'No fue posible consultar los borradores. '
            + 'Verifique que ejecutó el archivo SQL de instalación.'
        );
    }
}

async function abrirBorradores() {
    if (!modalBorradores || !listaBorradores) return;

    modalBorradores.classList.add('active');
    listaBorradores.innerHTML =
        '<div class="draft-empty">Cargando borradores...</div>';

    try {
        const borradores = await obtenerListaBorradores();
        const contador = document.getElementById(
            'borradoresCount'
        );

        if (contador) {
            contador.textContent = String(
                borradores.length
            );
        }

        if (borradores.length === 0) {
            listaBorradores.innerHTML =
                '<div class="draft-empty">'
                + 'Todavía no tiene borradores guardados.'
                + '</div>';
            return;
        }

        listaBorradores.innerHTML = borradores.map(
            borrador => `
                <article class="draft-item">
                    <div>
                        <h4>${escapeHtml(borrador.nombre)}</h4>
                        <p>
                            ${Number(borrador.total_productos || 0)}
                            producto(s) ·
                            ${escapeHtml(borrador.almacen_nombre || 'Almacén')}
                            · Actualizado:
                            ${escapeHtml(formatearFechaBorrador(borrador.actualizado_en))}
                        </p>
                    </div>
                    <div class="draft-item-actions">
                        <button
                            type="button"
                            class="draft-button primary"
                            data-continuar-borrador="${Number(borrador.id)}"
                        >Continuar</button>
                        <button
                            type="button"
                            class="draft-button secondary draft-delete"
                            data-eliminar-borrador="${Number(borrador.id)}"
                        >Eliminar</button>
                    </div>
                </article>
            `
        ).join('');

        listaBorradores.querySelectorAll(
            '[data-continuar-borrador]'
        ).forEach(boton => {
            boton.addEventListener('click', () => {
                continuarBorrador(
                    Number(boton.dataset.continuarBorrador)
                );
            });
        });

        listaBorradores.querySelectorAll(
            '[data-eliminar-borrador]'
        ).forEach(boton => {
            boton.addEventListener('click', () => {
                eliminarBorradorEntrada(
                    Number(boton.dataset.eliminarBorrador)
                );
            });
        });
    } catch (error) {
        listaBorradores.innerHTML = `
            <div class="draft-empty">
                ${escapeHtml(error.message)}
                <br>
                Ejecute database/instalar_borradores_entrada.sql.
            </div>
        `;
    }
}

function cerrarBorradores() {
    modalBorradores?.classList.remove('active');
}

function asignarValor(selector, valor) {
    const campo = document.querySelector(selector);

    if (!campo || valor === undefined || valor === null) {
        return;
    }

    campo.value = String(valor);
    campo.dispatchEvent(
        new Event('change', { bubbles: true })
    );
}

async function continuarBorrador(id) {
    const hayOtraCaptura = obtenerFilasCapturadas().length > 0
        && borradorActualId !== id;

    if (hayOtraCaptura && !confirm(
        'La captura actual será reemplazada por el borrador seleccionado. '
        + '¿Desea continuar?'
    )) {
        return;
    }

    try {
        const respuesta = await solicitarJson(
            `entradas.php?action=obtener_borrador&id=${encodeURIComponent(id)}`
        );
        const borrador = respuesta.borrador;
        const datos = borrador.datos || {};
        const productosBorrador = Array.isArray(
            datos.productos
        ) ? datos.productos : [];
        const capturaProducto = datos.captura_producto || {};

        asignarValor(
            'input[name="fecha"]',
            datos.fecha
        );
        asignarValor(
            'select[name="tipo_entrada"]',
            datos.tipo_entrada
        );
        asignarValor(
            'input[name="proveedor_nombre"]',
            datos.proveedor_nombre
        );
        asignarValor(
            'select[name="tipo_documento"]',
            datos.tipo_documento
        );
        asignarValor(
            'input[name="folio_documento"]',
            datos.folio_documento
        );
        asignarValor(
            'textarea[name="observaciones"]',
            datos.observaciones
        );

        const almacenSelect = document.querySelector(
            'select[name="almacen_id"]'
        );

        if (almacenSelect
            && !almacenSelect.disabled
            && datos.almacen_id
        ) {
            almacenSelect.value = String(
                datos.almacen_id
            );
            almacenSelect.dispatchEvent(
                new Event('change', { bubbles: true })
            );
        }

        cantidadInput.value = String(
            capturaProducto.cantidad ?? '1'
        );
        precioInput.value = String(
            capturaProducto.precio ?? '0.00'
        );
        loteInput.value = String(
            capturaProducto.lote ?? ''
        );

        window.UbicacionesRapidas?.establecerValor(
            ubicacionInput,
            String(capturaProducto.ubicacion ?? '')
        );

        if (datos.producto_pendiente) {
            const pendiente = datos.producto_pendiente;
            productoSeleccionado = productos.find(
                producto => Number(producto.id)
                    === Number(pendiente.id)
            ) || pendiente;

            document.getElementById(
                'selectedInfo'
            ).style.display = 'block';
            document.getElementById(
                'infoCodigo'
            ).innerHTML = `<strong>${escapeHtml(
                productoSeleccionado.codigo
            )}</strong>`;
            document.getElementById(
                'infoDescripcion'
            ).textContent = productoSeleccionado.descripcion || '';
            document.getElementById(
                'infoUbicacion'
            ).textContent =
                productoSeleccionado.ubicacion_sugerida
                || 'No definida';
            productoDisplayInput.value =
                `${productoSeleccionado.codigo} - `
                + String(
                    productoSeleccionado.descripcion || ''
                ).substring(0, 50);
        } else {
            productoSeleccionado = null;
            document.getElementById(
                'selectedInfo'
            ).style.display = 'none';
            productoDisplayInput.value = '';
        }

        detalleBody.innerHTML = '';
        productosBorrador.forEach(
            crearFilaProductoCapturado
        );

        if (productosBorrador.length === 0) {
            detalleBody.innerHTML =
                '<tr id="filaVacia"><td colspan="9" '
                + 'style="text-align:center;padding:50px;color:#9ca3af;">'
                + '📭 No hay productos. Presiona Ctrl+B para buscar'
                + '</td></tr>';
        }

        borradorActualId = Number(borrador.id || 0);

        if (borradorIdInput) {
            borradorIdInput.value = String(
                borradorActualId
            );
        }

        if (nombreBorrador) {
            nombreBorrador.value = borrador.nombre || '';
        }

        construirReferencia();
        prepararNavegacionFilas();
        actualizarTotales();
        actualizarEstadoBorrador(
            `Continuando "${borrador.nombre}". `
            + 'Al guardar nuevamente se actualizará este borrador.'
        );
        cerrarBorradores();
        mostrarToast('📂 Borrador recuperado');
    } catch (error) {
        mostrarToast(
            `❌ ${error.message}`,
            'error'
        );
    }
}

async function eliminarBorradorEntrada(id) {
    if (!confirm(
        '¿Eliminar este borrador? Esta acción no elimina ninguna entrada definitiva.'
    )) {
        return;
    }

    try {
        await solicitarJson(
            'entradas.php?action=eliminar_borrador',
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    csrf: csrfEntrada,
                    id
                })
            }
        );

        if (borradorActualId === id) {
            borradorActualId = 0;

            if (borradorIdInput) {
                borradorIdInput.value = '';
            }

            if (nombreBorrador) {
                nombreBorrador.value = '';
            }

            actualizarEstadoBorrador(
                'El borrador se eliminó; la captura abierta se conserva.'
            );
        }

        mostrarToast('🗑️ Borrador eliminado', 'info');
        await abrirBorradores();
    } catch (error) {
        mostrarToast(
            `❌ ${error.message}`,
            'error'
        );
    }
}

// ===== REFERENCIA DOCUMENTO =====
const tipoDocumento = document.getElementById('tipo_documento');
const folioDocumento = document.getElementById('folio_documento');
const folioEntradaInput = document.getElementById('folioEntrada');
const folioAnteriorTexto = document.getElementById('folioAnteriorTexto');
const almacenEntradaSelect = document.getElementById('almacenEntradaSelect');

async function actualizarFolioPorAlmacen() {
    if (!folioEntradaInput || !almacenEntradaSelect) {
        return;
    }

    // En edición se conserva el folio original del movimiento.
    if (folioEntradaInput.dataset.modoEdicion === '1') {
        return;
    }

    const almacenId = Number(almacenEntradaSelect.value || 0);

    if (almacenId <= 0) {
        folioEntradaInput.value = '';
        folioEntradaInput.placeholder = 'Seleccione un almacén';

        if (folioAnteriorTexto) {
            folioAnteriorTexto.textContent = '📋 Último folio registrado: Seleccione un almacén';
        }
        return;
    }

    const valorAnterior = folioEntradaInput.value;
    folioEntradaInput.value = 'Calculando...';

    try {
        const respuesta = await fetch(
            `entradas.php?action=folio_almacen&almacen_id=${encodeURIComponent(almacenId)}`,
            {
                method: 'GET',
                headers: { 'Accept': 'application/json' },
                cache: 'no-store'
            }
        );

        const datos = await respuesta.json();

        if (!respuesta.ok || !datos.success) {
            throw new Error(datos.message || 'No se pudo generar el folio.');
        }

        folioEntradaInput.value = datos.folio || '';

        if (folioAnteriorTexto) {
            folioAnteriorTexto.textContent = '📋 Último folio registrado: '
                + (datos.ultimo_folio || 'Sin entradas anteriores');
        }
    } catch (error) {
        console.error('Error al actualizar folio:', error);
        folioEntradaInput.value = valorAnterior || '';

        if (!folioEntradaInput.value) {
            folioEntradaInput.placeholder = 'Error al generar folio';
        }
    }
}

if (almacenEntradaSelect) {
    almacenEntradaSelect.addEventListener('change', actualizarFolioPorAlmacen);

    if (almacenEntradaSelect.value && folioEntradaInput?.dataset.modoEdicion !== '1') {
        actualizarFolioPorAlmacen();
    }
}
const referenciaFinal = document.getElementById('referencia_final');

function construirReferencia() {
    const tipo = tipoDocumento.value.trim();
    const folio = folioDocumento.value.trim();
    if (tipo && folio) {
        referenciaFinal.value = `${tipo}: ${folio}`;
    } else if (tipo) {
        referenciaFinal.value = tipo;
    } else if (folio) {
        referenciaFinal.value = folio;
    } else {
        referenciaFinal.value = '';
    }
}

// ===== GUARDAR =====
function guardarEntrada() {
    construirReferencia();
    
    const productosEnLista = document.querySelectorAll('#detalleBody tr:not(#filaVacia)');
    if (productosEnLista.length === 0) {
        mostrarToast('❌ Agrega al menos un producto', 'error');
        return;
    }
    
    let ubicacionInvalida = false;
    document.querySelectorAll('input[name="ubicacion[]"]').forEach(input => {
        const ubicacion = (input.value || '').trim().toUpperCase();
        if (!ubicacion || ubicacion === 'SIN UBICACION') {
            ubicacionInvalida = true;
        }
    });
    
    if (ubicacionInvalida) {
        mostrarToast('❌ Todas las partidas deben tener ubicación válida', 'error');
        return;
    }
    
    document.getElementById('formEntrada').submit();
}

// ===== GENERAR TODAS LAS UBICACIONES (como en productos.php) =====
function generarTodasLasUbicaciones() {
    const lista = document.getElementById('ubicacionesList');
    if (!lista) return;
    
    const ubicaciones = [];
    
    function add(rack, nivel, zona) {
        const z = String(zona).padStart(2, '0');
        ubicaciones.push(`R${rack}N${nivel}Z${z}`);
    }
    
    // Rack 1: niveles 1-3, zonas 1-22
    for (let n = 1; n <= 3; n++) {
        for (let z = 1; z <= 22; z++) {
            add(1, n, z);
        }
    }
    
    // Rack 2: niveles 1-3, zonas 1-20
    for (let n = 1; n <= 3; n++) {
        for (let z = 1; z <= 20; z++) {
            add(2, n, z);
        }
    }
    
    // Rack 3: niveles 1-3, zonas 1-20
    for (let n = 1; n <= 3; n++) {
        for (let z = 1; z <= 20; z++) {
            add(3, n, z);
        }
    }
    
    // Rack 4: niveles 1-2, zonas 1-16
    for (let n = 1; n <= 2; n++) {
        for (let z = 1; z <= 16; z++) {
            add(4, n, z);
        }
    }
    // Rack 4 nivel 3 zonas 10-16
    for (let z = 10; z <= 16; z++) {
        add(4, 3, z);
    }
    
    // Rack 5: niveles 1-2, zonas 1-15
    for (let n = 1; n <= 2; n++) {
        for (let z = 1; z <= 15; z++) {
            add(5, n, z);
        }
    }
    // Rack 5 nivel 3 zonas 10-15
    for (let z = 10; z <= 15; z++) {
        add(5, 3, z);
    }
    
    // Rack 6: niveles 1-3, zonas 1-22
    for (let n = 1; n <= 3; n++) {
        for (let z = 1; z <= 22; z++) {
            add(6, n, z);
        }
    }
    
    // Ubicaciones adicionales
    ubicaciones.push('R7N1Z01 - PASILLO 3');
    ubicaciones.push('R8N1Z01 - PASILLO 2');
    ubicaciones.push('R9N1Z01 - PASILLO 1');
    ubicaciones.push('BODEGA PEDYALITE');
   
    
    // Limpiar opciones existentes y agregar todas
    lista.innerHTML = '';
    
    ubicaciones.forEach(u => {
        const option = document.createElement('option');
        option.value = u;
        lista.appendChild(option);
    });
    
    console.log('Ubicaciones cargadas:', ubicaciones.length);
}

// ===== INICIALIZACIÓN =====
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM cargado - Entradas');
    
    // Generar todas las ubicaciones
    generarTodasLasUbicaciones();
    
    actualizarTotales();
    ponerFechaActual();
    prepararNavegacionFilas();
    const filasIniciales = obtenerFilasCapturadas();
    if (filasIniciales.length > 0) {
        activarFilaCapturada(filasIniciales[0]);
    }
    actualizarConteoBorradores();

    document.addEventListener(
        'keydown',
        navegarFilasCapturadas
    );
    
    // Ctrl+B para abrir modal
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && (e.key === 'b' || e.key === 'B')) {
            e.preventDefault();
            e.stopPropagation();
            console.log('Ctrl+B presionado - Abriendo modal');
            abrirModal();
            return false;
        }
    });
    
    // Enter en campos = agregar producto
    const camposEnter = ['cantidadInput', 'precioInput', 'ubicacionInput', 'loteInput'];
    camposEnter.forEach(id => {
        const campo = document.getElementById(id);
        if (campo) {
            campo.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.defaultPrevented) {
                    e.preventDefault();
                    agregarProducto();
                }
            });
        }
    });
    
    // Ctrl+Enter = guardar
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'Enter') {
            e.preventDefault();
            guardarEntrada();
        }
    });
    
    // Botones
    document.getElementById('openModalBtn')?.addEventListener('click', abrirModal);
    document.getElementById('closeModalBtn')?.addEventListener('click', cerrarModal);
    document.getElementById('agregarBtn')?.addEventListener('click', agregarProducto);
    document.getElementById('guardarBtn')?.addEventListener('click', guardarEntrada);
    document.getElementById('guardarBorradorBtn')
        ?.addEventListener(
            'click',
            guardarBorradorEntrada
        );
    document.getElementById('abrirBorradoresBtn')
        ?.addEventListener(
            'click',
            abrirBorradores
        );
    document.getElementById('cerrarBorradoresBtn')
        ?.addEventListener(
            'click',
            cerrarBorradores
        );
    
    // Modal teclado
    if (modalSearch) {
        modalSearch.addEventListener('keydown', function(e) {
            const filas = document.querySelectorAll('#modalTableBody tr');
            const totalFilas = filas.length;
            
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                modalSelectedIndex = Math.min(modalSelectedIndex + 1, totalFilas - 1);
                actualizarSeleccionModal();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                modalSelectedIndex = Math.max(modalSelectedIndex - 1, 0);
                actualizarSeleccionModal();
            } else if (e.key === 'Enter' && modalSelectedIndex >= 0 && modalProductosFiltrados[modalSelectedIndex]) {
                e.preventDefault();
                seleccionarProductoDelModal(modalProductosFiltrados[modalSelectedIndex]);
            } else if (e.key === 'Escape') {
                cerrarModal();
            }
        });
        
        modalSearch.addEventListener('input', filtrarProductosModal);
    }
    
    // Cerrar modal al hacer clic fuera
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) cerrarModal();
        });
    }

    modalBorradores?.addEventListener(
        'click',
        function(event) {
            if (event.target === modalBorradores) {
                cerrarBorradores();
            }
        }
    );
    
    // Escape global
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal && modal.classList.contains('active')) {
            cerrarModal();
        }

        if (e.key === 'Escape'
            && modalBorradores?.classList.contains('active')
        ) {
            cerrarBorradores();
        }
    });
    
    // Referencia documento
    tipoDocumento?.addEventListener('change', construirReferencia);
    folioDocumento?.addEventListener('input', construirReferencia);
    
    // Forzar construcción de referencia inicial (para edición)
    construirReferencia();
});
</script>

<?php
if (file_exists(__DIR__ . '/../app/views/layouts/footer.php')) {
    include __DIR__ . '/../app/views/layouts/footer.php';
}
?>
