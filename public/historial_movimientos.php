<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/helpers/utils.php';
require_once __DIR__ . '/../app/controllers/AuditoriaController.php';

requireLogin();

$controller = new AuditoriaController();
$controller->verificarAdministrador();

$vista = strtolower(trim((string) ($_GET['vista'] ?? 'inventario')));
if (!in_array($vista, ['inventario', 'auditoria'], true)) {
    $vista = 'inventario';
}

$filters = [
    'buscar' => trim((string) ($_GET['buscar'] ?? '')),
    'usuario_id' => (int) ($_GET['usuario_id'] ?? 0),
    'almacen_id' => (int) ($_GET['almacen_id'] ?? 0),
    'tipo_movimiento' => trim((string) ($_GET['tipo_movimiento'] ?? '')),
    'modulo' => trim((string) ($_GET['modulo'] ?? '')),
    'accion' => trim((string) ($_GET['accion'] ?? '')),
    'fecha_inicio' => trim((string) ($_GET['fecha_inicio'] ?? '')),
    'fecha_final' => trim((string) ($_GET['fecha_final'] ?? '')),
];

$page = max(1, (int) ($_GET['page'] ?? 1));
$error = '';
$result = [
    'registros' => [],
    'total' => 0,
    'pagina' => 1,
    'por_pagina' => 30,
    'total_paginas' => 1,
];
$options = [
    'usuarios' => [],
    'almacenes' => [],
    'modulos' => [],
    'acciones' => [],
];
$inventoryTypes = [];

try {
    if (!$controller->estaInstalado() && $vista === 'auditoria') {
        $error = 'La tabla de auditoría todavía no está instalada. Ejecute database/instalar_auditoria.sql en phpMyAdmin.';
    } else {
        $options = $controller->opciones();
        $inventoryTypes = $controller->tiposInventario();
        $result = $vista === 'inventario'
            ? $controller->consultarInventario($filters, $page)
            : $controller->consultar($filters, $page);
    }
} catch (Throwable $e) {
    error_log('Error al consultar historial de movimientos: ' . $e->getMessage());
    $error = 'No fue posible consultar el historial de movimientos.';
}

function auditDecodeForView(?string $json): array
{
    if ($json === null || trim($json) === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function auditValueForView(mixed $value): string
{
    if ($value === null) return 'Vacío';
    if (is_bool($value)) return $value ? 'Sí' : 'No';
    if (is_array($value)) {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        return $json === false ? '' : $json;
    }
    $text = trim((string) $value);
    return $text === '' ? 'Vacío' : $text;
}

function auditFieldLabel(string $field): string
{
    return ucfirst(str_replace('_', ' ', $field));
}

function auditActionClass(string $action): string
{
    if (str_contains($action, 'ELIMIN') || str_contains($action, 'CANCEL') || str_contains($action, 'FALLIDO') || str_contains($action, 'DENEGADO')) return 'audit-badge-danger';
    if (str_contains($action, 'CRE') || str_contains($action, 'GUARD') || str_contains($action, 'INICIO_SESION') || $action === 'ENTRADA') return 'audit-badge-success';
    if (str_contains($action, 'ACTUAL') || str_contains($action, 'EDIT') || str_contains($action, 'CAMBIO') || $action === 'AJUSTE') return 'audit-badge-warning';
    return 'audit-badge-info';
}

function movementTypeLabel(string $type): string
{
    return match (strtoupper($type)) {
        'ENTRADA' => 'Entrada',
        'SALIDA' => 'Salida',
        'AJUSTE' => 'Ajuste',
        'TRASPASO' => 'Traspaso',
        'MERMA' => 'Merma',
        'DEVOLUCION' => 'Devolución',
        default => ucfirst(strtolower($type)),
    };
}

$queryWithoutPage = $_GET;
unset($queryWithoutPage['page']);

$moduleCss = 'historial_movimientos';
include __DIR__ . '/../app/views/layouts/header.php';
?>

<section class="audit-page">
    <div class="audit-heading">
        <div>
            <h2>Historial de movimientos</h2>
            <p>
                <?= $vista === 'inventario'
                    ? 'Movimientos reales registrados en inventario: entradas, salidas y demás operaciones.'
                    : 'Bitácora consolidada: auditoría técnica más entradas, salidas y movimientos reales del inventario.' ?>
            </p>
        </div>
        <div class="audit-total">
            <strong><?= number_format((int) $result['total']) ?></strong>
            <span><?= $vista === 'inventario' ? 'movimientos reales' : 'eventos / movimientos' ?></span>
        </div>
    </div>

    <div class="audit-view-tabs" role="navigation" aria-label="Tipo de historial">
        <a href="historial_movimientos.php?vista=inventario" class="<?= $vista === 'inventario' ? 'active' : '' ?>">
            📦 Movimientos de inventario
        </a>
        <a href="historial_movimientos.php?vista=auditoria" class="<?= $vista === 'auditoria' ? 'active' : '' ?>">
            🧾 Bitácora del sistema
        </a>
    </div>

    <?php if ($vista === 'inventario'): ?>
        <div class="audit-alert audit-alert-info">
            Esta vista lee directamente la tabla <strong>movimientos</strong>. Por eso incluye movimientos históricos aunque no tengan un evento equivalente en la auditoría.
        </div>
    <?php else: ?>
        <div class="audit-alert audit-alert-info">
            Esta vista combina la <strong>auditoría técnica</strong> con los <strong>movimientos reales de inventario</strong>. Si un movimiento antiguo no generó auditoría, se muestra como <strong>reconstruido desde inventario</strong>; no se inventan IP, navegador ni URL.
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="audit-alert audit-alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="GET" action="historial_movimientos.php" class="audit-filters">
        <input type="hidden" name="vista" value="<?= e($vista) ?>">
        <div class="audit-field audit-field-wide">
            <label for="buscar">Buscar</label>
            <input type="search" id="buscar" name="buscar" value="<?= e($filters['buscar']) ?>"
                   placeholder="<?= $vista === 'inventario' ? 'Folio, producto, código, referencia, usuario...' : 'Usuario, descripción, producto, folio o ID' ?>">
        </div>

        <div class="audit-field">
            <label for="usuario_id">Usuario</label>
            <select id="usuario_id" name="usuario_id">
                <option value="0">Todos</option>
                <?php foreach ($options['usuarios'] as $option): ?>
                    <option value="<?= (int) $option['id'] ?>" <?= (int) $filters['usuario_id'] === (int) $option['id'] ? 'selected' : '' ?>>
                        <?= e($option['nombre']) ?> (<?= e($option['usuario']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="audit-field">
            <label for="almacen_id">Almacén</label>
            <select id="almacen_id" name="almacen_id">
                <option value="0">Todos</option>
                <?php foreach ($options['almacenes'] as $option): ?>
                    <option value="<?= (int) $option['id'] ?>" <?= (int) $filters['almacen_id'] === (int) $option['id'] ? 'selected' : '' ?>>
                        <?= e($option['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if ($vista === 'inventario'): ?>
            <div class="audit-field">
                <label for="tipo_movimiento">Tipo de movimiento</label>
                <select id="tipo_movimiento" name="tipo_movimiento">
                    <option value="">Todos</option>
                    <?php foreach ($inventoryTypes as $type): ?>
                        <option value="<?= e((string) $type) ?>" <?= $filters['tipo_movimiento'] === $type ? 'selected' : '' ?>>
                            <?= e(movementTypeLabel((string) $type)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php else: ?>
            <div class="audit-field">
                <label for="modulo">Módulo</label>
                <select id="modulo" name="modulo">
                    <option value="">Todos</option>
                    <?php foreach ($options['modulos'] as $option): ?>
                        <option value="<?= e((string) $option) ?>" <?= $filters['modulo'] === $option ? 'selected' : '' ?>><?= e((string) $option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="audit-field">
                <label for="accion">Acción</label>
                <select id="accion" name="accion">
                    <option value="">Todas</option>
                    <?php foreach ($options['acciones'] as $option): ?>
                        <option value="<?= e((string) $option) ?>" <?= $filters['accion'] === $option ? 'selected' : '' ?>><?= e(str_replace('_', ' ', (string) $option)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <div class="audit-field">
            <label for="fecha_inicio">Desde</label>
            <input type="date" id="fecha_inicio" name="fecha_inicio" value="<?= e($filters['fecha_inicio']) ?>">
        </div>
        <div class="audit-field">
            <label for="fecha_final">Hasta</label>
            <input type="date" id="fecha_final" name="fecha_final" value="<?= e($filters['fecha_final']) ?>">
        </div>
        <div class="audit-filter-actions">
            <button type="submit" class="audit-btn audit-btn-primary">Filtrar</button>
            <a href="historial_movimientos.php?vista=<?= e($vista) ?>" class="audit-btn audit-btn-secondary">Limpiar</a>
        </div>
    </form>

    <div class="audit-table-card">
        <div class="audit-table-scroll">
            <?php if ($vista === 'inventario'): ?>
                <table class="audit-table inventory-history-table">
                    <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Folio</th>
                        <th>Tipo</th>
                        <th>Almacén</th>
                        <th>Productos / Cantidad</th>
                        <th>Usuario</th>
                        <th>Estado</th>
                        <th>Detalle</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($result['registros'] === []): ?>
                        <tr><td colspan="8" class="audit-empty">No hay movimientos que coincidan con los filtros.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($result['registros'] as $row): ?>
                        <tr>
                            <td class="audit-date">
                                <strong><?= e(date('d/m/Y H:i', strtotime((string) $row['fecha']))) ?></strong>
                                <?php if (!empty($row['created_at'])): ?>
                                    <small>Registrado: <?= e(date('d/m/Y H:i:s', strtotime((string) $row['created_at']))) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><strong><?= e((string) $row['folio']) ?></strong><small>#<?= (int) $row['id'] ?></small></td>
                            <td><span class="audit-badge <?= e(auditActionClass((string) $row['tipo_movimiento'])) ?>"><?= e(movementTypeLabel((string) $row['tipo_movimiento'])) ?></span></td>
                            <td><?= e((string) ($row['almacen_nombre'] ?: 'Sin almacén')) ?></td>
                            <td><strong><?= number_format((int) $row['total_productos']) ?> producto(s)</strong><small><?= number_format((int) $row['total_cantidad']) ?> pieza(s) registradas</small></td>
                            <td><strong><?= e((string) ($row['usuario_nombre'] ?: 'Usuario no identificado')) ?></strong><small><?= e((string) ($row['usuario_login'] ?? '')) ?><?= !empty($row['usuario_rol']) ? ' · ' . e((string) $row['usuario_rol']) : '' ?></small></td>
                            <td>
                                <?php if ((int) $row['cancelado'] === 1): ?>
                                    <span class="audit-badge audit-badge-danger">CANCELADO</span>
                                <?php else: ?>
                                    <span class="audit-badge audit-badge-success">APLICADO</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <details class="audit-details inventory-details">
                                    <summary>Ver movimiento</summary>
                                    <div class="movement-summary-grid">
                                        <div><span>Referencia</span><strong><?= e((string) ($row['referencia'] ?: 'Sin referencia')) ?></strong></div>
                                        <div><span>Operación</span><strong><?= e((string) ($row['tipo_operacion'] ?: 'No especificada')) ?></strong></div>
                                        <div class="movement-summary-wide"><span>Observaciones</span><strong><?= nl2br(e((string) ($row['observaciones'] ?: 'Sin observaciones'))) ?></strong></div>
                                        <?php if ((int) $row['cancelado'] === 1): ?>
                                            <div><span>Fecha cancelación</span><strong><?= e((string) ($row['fecha_cancelacion'] ?: 'No registrada')) ?></strong></div>
                                            <div><span>Motivo</span><strong><?= e((string) ($row['motivo_cancelacion'] ?: 'Sin motivo')) ?></strong></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="audit-change-title">Productos del movimiento</div>
                                    <div class="audit-changes-scroll">
                                        <table class="audit-changes movement-products">
                                            <thead><tr><th>Código</th><th>Descripción</th><th>Cantidad</th><th>Ubicación</th><th>Costo</th></tr></thead>
                                            <tbody>
                                            <?php foreach (($row['detalles'] ?? []) as $detail): ?>
                                                <tr>
                                                    <td><?= e((string) ($detail['codigo_barras'] ?: $detail['codigo'])) ?></td>
                                                    <td><?= e((string) $detail['descripcion']) ?></td>
                                                    <td><?= number_format((int) $detail['cantidad']) ?></td>
                                                    <td><?= e((string) ($detail['ubicacion'] ?: 'Sin ubicación')) ?></td>
                                                    <td>$<?= number_format((float) $detail['costo_unitario'], 2) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <?php if (($row['detalles'] ?? []) === []): ?>
                                                <tr><td colspan="5">Sin detalle de productos.</td></tr>
                                            <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <table class="audit-table audit-system-table">
                    <thead><tr><th>Fecha y hora</th><th>Usuario</th><th>Módulo</th><th>Acción</th><th>Origen</th><th>Descripción</th><th>Detalles</th></tr></thead>
                    <tbody>
                    <?php if ($result['registros'] === []): ?>
                        <tr><td colspan="7" class="audit-empty">No hay eventos ni movimientos que coincidan con los filtros.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($result['registros'] as $row): ?>
                        <?php
                        $before = auditDecodeForView($row['datos_anteriores']);
                        $after = auditDecodeForView($row['datos_nuevos']);
                        $metadata = auditDecodeForView($row['metadata']);
                        $fields = array_unique(array_merge(array_keys($before), array_keys($after)));
                        $isReconstructed = (string) ($row['fuente'] ?? '') === 'MOVIMIENTO_RECONSTRUIDO';
                        $movement = is_array($row['movimiento'] ?? null) ? $row['movimiento'] : null;
                        $movementDetails = is_array($row['movimiento_detalles'] ?? null) ? $row['movimiento_detalles'] : [];
                        ?>
                        <tr class="<?= $isReconstructed ? 'audit-row-reconstructed' : '' ?>">
                            <td class="audit-date"><?= e(date('d/m/Y H:i:s', strtotime((string) $row['creado_en']))) ?></td>
                            <td>
                                <strong><?= e((string) ($row['usuario_nombre'] ?: 'Usuario no identificado')) ?></strong>
                                <small><?= e((string) ($row['usuario_login'] ?? '')) ?><?= !empty($row['usuario_rol']) ? ' · ' . e((string) $row['usuario_rol']) : '' ?></small>
                                <?php if (!empty($row['almacen_nombre'])): ?><small><?= e((string) $row['almacen_nombre']) ?></small><?php endif; ?>
                            </td>
                            <td><?= e((string) $row['modulo']) ?></td>
                            <td><span class="audit-badge <?= e(auditActionClass((string) $row['accion'])) ?>"><?= e(str_replace('_', ' ', (string) $row['accion'])) ?></span></td>
                            <td>
                                <?php if ($isReconstructed): ?>
                                    <span class="audit-source audit-source-reconstructed">INVENTARIO</span>
                                    <small>Reconstruido</small>
                                <?php elseif ((int) ($row['movimiento_id'] ?? 0) > 0): ?>
                                    <span class="audit-source audit-source-linked">AUDITORÍA</span>
                                    <small>Vinculado a movimiento</small>
                                <?php else: ?>
                                    <span class="audit-source">AUDITORÍA</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= e((string) $row['descripcion']) ?>
                                <?php if (!empty($row['entidad'])): ?><small><?= e((string) $row['entidad']) ?><?= !empty($row['registro_id']) ? ' #' . e((string) $row['registro_id']) : '' ?></small><?php endif; ?>
                                <?php if ($movement !== null): ?>
                                    <small><strong>Folio:</strong> <?= e((string) ($movement['folio'] ?? '')) ?> · <strong>Tipo:</strong> <?= e(movementTypeLabel((string) ($movement['tipo_movimiento'] ?? ''))) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <details class="audit-details <?= $movement !== null ? 'inventory-details' : '' ?>">
                                    <summary>Ver detalle</summary>

                                    <?php if ($movement !== null): ?>
                                        <div class="audit-change-title">Movimiento de inventario relacionado</div>
                                        <div class="movement-summary-grid">
                                            <div><span>Folio</span><strong><?= e((string) ($movement['folio'] ?? '')) ?></strong></div>
                                            <div><span>Tipo</span><strong><?= e(movementTypeLabel((string) ($movement['tipo_movimiento'] ?? ''))) ?></strong></div>
                                            <div><span>Fecha movimiento</span><strong><?= !empty($movement['fecha']) ? e(date('d/m/Y H:i', strtotime((string) $movement['fecha']))) : 'No disponible' ?></strong></div>
                                            <div><span>Almacén</span><strong><?= e((string) ($movement['almacen_nombre'] ?? 'Sin almacén')) ?></strong></div>
                                            <div><span>Referencia</span><strong><?= e((string) (($movement['referencia'] ?? '') ?: 'Sin referencia')) ?></strong></div>
                                            <div><span>Operación</span><strong><?= e((string) (($movement['tipo_operacion'] ?? '') ?: 'No especificada')) ?></strong></div>
                                            <div class="movement-summary-wide"><span>Observaciones</span><strong><?= nl2br(e((string) (($movement['observaciones'] ?? '') ?: 'Sin observaciones'))) ?></strong></div>
                                            <div><span>Estado</span><strong><?= (int) ($movement['cancelado'] ?? 0) === 1 ? 'CANCELADO' : 'APLICADO' ?></strong></div>
                                            <?php if ((int) ($movement['cancelado'] ?? 0) === 1): ?>
                                                <div><span>Motivo cancelación</span><strong><?= e((string) (($movement['motivo_cancelacion'] ?? '') ?: 'Sin motivo')) ?></strong></div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="audit-change-title">Productos del movimiento</div>
                                        <div class="audit-changes-scroll">
                                            <table class="audit-changes movement-products">
                                                <thead><tr><th>Código</th><th>Descripción</th><th>Cantidad</th><th>Ubicación</th><th>Costo</th></tr></thead>
                                                <tbody>
                                                <?php foreach ($movementDetails as $detail): ?>
                                                    <tr>
                                                        <td><?= e((string) (($detail['codigo_barras'] ?? '') ?: ($detail['codigo'] ?? ''))) ?></td>
                                                        <td><?= e((string) ($detail['descripcion'] ?? '')) ?></td>
                                                        <td><?= number_format((int) ($detail['cantidad'] ?? 0)) ?></td>
                                                        <td><?= e((string) (($detail['ubicacion'] ?? '') ?: 'Sin ubicación')) ?></td>
                                                        <td>$<?= number_format((float) ($detail['costo_unitario'] ?? 0), 2) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <?php if ($movementDetails === []): ?><tr><td colspan="5">Sin detalle de productos.</td></tr><?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($fields !== []): ?>
                                        <div class="audit-change-title">Comparación de cambios</div>
                                        <div class="audit-changes-scroll"><table class="audit-changes"><thead><tr><th>Campo</th><th>Antes</th><th>Después</th></tr></thead><tbody>
                                        <?php foreach ($fields as $field): ?><tr><td><?= e(auditFieldLabel((string) $field)) ?></td><td><pre><?= e(auditValueForView($before[$field] ?? null)) ?></pre></td><td><pre><?= e(auditValueForView($after[$field] ?? null)) ?></pre></td></tr><?php endforeach; ?>
                                        </tbody></table></div>
                                    <?php endif; ?>
                                    <?php if ($metadata !== []): ?><div class="audit-change-title">Información adicional</div><pre class="audit-json"><?= e(auditValueForView($metadata)) ?></pre><?php endif; ?>

                                    <?php if ($isReconstructed): ?>
                                        <div class="audit-reconstructed-note">Este evento se obtuvo del movimiento real de inventario. Los datos técnicos de navegación no existen para ese registro histórico.</div>
                                    <?php endif; ?>
                                    <dl class="audit-technical">
                                        <div><dt>IP</dt><dd><?= e((string) ($row['direccion_ip'] ?: ($isReconstructed ? 'No disponible (histórico)' : 'No disponible'))) ?></dd></div>
                                        <div><dt>Método</dt><dd><?= e((string) ($row['metodo_http'] ?: ($isReconstructed ? 'No disponible (histórico)' : 'No disponible'))) ?></dd></div>
                                        <div><dt>URL</dt><dd><?= e((string) ($row['url'] ?: ($isReconstructed ? 'No disponible (histórico)' : 'No disponible'))) ?></dd></div>
                                        <div><dt>Navegador</dt><dd><?= e((string) ($row['user_agent'] ?: ($isReconstructed ? 'No disponible (histórico)' : 'No disponible'))) ?></dd></div>
                                    </dl>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <?php if ((int) $result['total_paginas'] > 1): ?>
        <nav class="audit-pagination" aria-label="Paginación">
            <?php
            $pageNumbers = [1, (int) $result['total_paginas']];
            for ($nearby = max(1, (int) $result['pagina'] - 2); $nearby <= min((int) $result['total_paginas'], (int) $result['pagina'] + 2); $nearby++) {
                $pageNumbers[] = $nearby;
            }
            $pageNumbers = array_values(array_unique($pageNumbers));
            sort($pageNumbers);
            ?>
            <?php foreach ($pageNumbers as $number): ?>
                <?php $pageQuery = $queryWithoutPage; $pageQuery['page'] = $number; ?>
                <a href="?<?= e(http_build_query($pageQuery)) ?>" class="<?= $number === (int) $result['pagina'] ? 'active' : '' ?>"><?= $number ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/../app/views/layouts/footer.php'; ?>
