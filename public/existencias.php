<?php

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/helpers/utils.php';
require_once __DIR__ . '/../app/controllers/ExistenciaController.php';

requireLogin();

$controller = new ExistenciaController();
$user = currentUser();

$rolUsuario = strtoupper(trim($user['rol'] ?? ''));
$esAdmin = in_array($rolUsuario, ['ADMINISTRADOR', 'ADMIN'], true);

$filtros = [
    'buscar' => trim($_GET['buscar'] ?? ''),
    'almacen_id' => isset($_GET['almacen_id']) ? (int)$_GET['almacen_id'] : 0,
    'estado_stock' => trim($_GET['estado_stock'] ?? ''),
    'rack' => trim($_GET['rack'] ?? ''),
    'categoria_id' => trim($_GET['categoria_id'] ?? ''),
    'proveedor_id' => trim($_GET['proveedor_id'] ?? ''),
    'orden' => trim($_GET['orden'] ?? 'descripcion'),
];

$almacenes = $controller->almacenes();
$categorias = $controller->categorias();
$proveedores = $controller->proveedores();

$productos = $controller->index($filtros);
$resumen = $controller->resumen($productos);

// Paginación de 20 productos. Los indicadores siguen considerando TODOS
// los productos filtrados, no solamente los de la página visible.
$productosPorPagina = 20;
$totalProductosFiltrados = count($productos);
$totalPaginas = max(1, (int) ceil($totalProductosFiltrados / $productosPorPagina));
$paginaSolicitada = filter_var(
    $_GET['page'] ?? 1,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$paginaActual = min($totalPaginas, $paginaSolicitada ?: 1);
$desplazamiento = ($paginaActual - 1) * $productosPorPagina;
$productosPagina = array_slice($productos, $desplazamiento, $productosPorPagina);
$primerRegistro = $totalProductosFiltrados > 0 ? $desplazamiento + 1 : 0;
$ultimoRegistro = min($desplazamiento + $productosPorPagina, $totalProductosFiltrados);

// Conservar búsqueda, almacén, rack, filtros y orden al cambiar de página.
$urlPagina = static function (int $numero) use ($filtros): string {
    return 'existencias.php?' . http_build_query(
        array_merge($filtros, ['page' => $numero])
    ) . '#inventario-actual';
};

$valorInventario = $esAdmin
    ? $controller->valorInventario(
        (int) $filtros['almacen_id']
    )
    : null;

$moduleCss = 'existencias';

include __DIR__ . '/../app/views/layouts/header.php';

?>

<div class="existencias-page">

    <div class="module-header existencias-header">
        <div>
            <h2>Existencias</h2>
            <p>
                Consulta general de inventario por almacén, rack,
                categoría, proveedor y estado de stock.
            </p>
        </div>
    </div>

    <div class="existencias-resumen-grid">

        <div class="existencia-card total">
            <span>Total productos</span>
            <strong><?= number_format((int)$resumen['totalProductos']) ?></strong>
        </div>

        <div class="existencia-card unidades">
            <span>Total unidades</span>
            <strong><?= number_format((int)$resumen['totalUnidades']) ?></strong>
        </div>

        <div class="existencia-card normal">
            <span>Stock normal</span>
            <strong><?= number_format((int)$resumen['stockNormal']) ?></strong>
        </div>

        <div class="existencia-card warning">
            <span>Stock bajo</span>
            <strong><?= number_format((int)$resumen['stockBajo']) ?></strong>
        </div>

        <div class="existencia-card danger">
            <span>Sin existencia</span>
            <strong><?= number_format((int)$resumen['sinExistencia']) ?></strong>
        </div>

        <div class="existencia-card dark">
            <span>Sin almacén</span>
            <strong><?= number_format((int)$resumen['sinAlmacen']) ?></strong>
        </div>

        <?php if ($esAdmin): ?>

            <div class="existencia-card valor">
                <span>
                    <?= (int) $filtros['almacen_id'] > 0
                        ? 'Valor total del almacén'
                        : 'Valor total de todos los almacenes' ?>
                </span>
                <strong>
                    $<?= number_format((float) $valorInventario, 2) ?>
                </strong>
            </div>

        <?php endif; ?>

    </div>

    <div class="existencias-filter-card">

        <form method="GET" action="existencias.php" class="existencias-filter-form">

            <div class="existencia-field search-field">
                <label>Buscar general</label>

                <input
                    type="text"
                    name="buscar"
                    value="<?= e($filtros['buscar']) ?>"
                    placeholder="Código, barras, descripción, proveedor, categoría, laboratorio o ubicación..."
                >
            </div>

            <?php if ($esAdmin): ?>

                <div class="existencia-field">
                    <label>Almacén</label>

                    <select name="almacen_id">

                        <option value="0">Todos</option>

                        <?php foreach ($almacenes as $almacen): ?>

                            <option
                                value="<?= (int)$almacen['id'] ?>"
                                <?= (int)$filtros['almacen_id'] === (int)$almacen['id'] ? 'selected' : '' ?>
                            >
                                <?= e($almacen['nombre']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>
                </div>

            <?php endif; ?>

            <div class="existencia-field">
                <label>Rack</label>

                <select name="rack">

                    <option value="">Todos</option>

                    <?php for ($i = 1; $i <= 9; $i++): ?>

                        <?php $rack = 'R' . $i; ?>

                        <option
                            value="<?= $rack ?>"
                            <?= $filtros['rack'] === $rack ? 'selected' : '' ?>
                        >
                            <?= $rack ?>
                        </option>

                    <?php endfor; ?>

                </select>
            </div>

            <div class="existencia-field">
                <label>Estado</label>

                <select name="estado_stock">

                    <option value="">Todos</option>

                    <option
                        value="stock"
                        <?= $filtros['estado_stock'] === 'stock' ? 'selected' : '' ?>
                    >
                        Con stock
                    </option>

                    <option
                        value="normal"
                        <?= $filtros['estado_stock'] === 'normal' ? 'selected' : '' ?>
                    >
                        Stock normal
                    </option>

                    <option
                        value="bajo"
                        <?= $filtros['estado_stock'] === 'bajo' ? 'selected' : '' ?>
                    >
                        Stock bajo
                    </option>

                    <option
                        value="sin_existencia"
                        <?= $filtros['estado_stock'] === 'sin_existencia' ? 'selected' : '' ?>
                    >
                        Sin existencia
                    </option>

                    <option
                        value="sin_almacen"
                        <?= $filtros['estado_stock'] === 'sin_almacen' ? 'selected' : '' ?>
                    >
                        Sin almacén
                    </option>

                </select>
            </div>

            <div class="existencia-field">
                <label>Categoría</label>

                <select name="categoria_id">

                    <option value="">Todas</option>

                    <?php foreach ($categorias as $categoria): ?>

                        <option
                            value="<?= (int)$categoria['id'] ?>"
                            <?= (string)$filtros['categoria_id'] === (string)$categoria['id'] ? 'selected' : '' ?>
                        >
                            <?= e($categoria['nombre']) ?>
                        </option>

                    <?php endforeach; ?>

                </select>
            </div>

            <div class="existencia-field">
                <label>Proveedor</label>

                <select name="proveedor_id">

                    <option value="">Todos</option>

                    <?php foreach ($proveedores as $proveedor): ?>

                        <option
                            value="<?= (int)$proveedor['id'] ?>"
                            <?= (string)$filtros['proveedor_id'] === (string)$proveedor['id'] ? 'selected' : '' ?>
                        >
                            <?= e($proveedor['nombre']) ?>
                        </option>

                    <?php endforeach; ?>

                </select>
            </div>

            <div class="existencia-field">
                <label>Ordenar</label>

                <select name="orden">

                    <option
                        value="descripcion"
                        <?= $filtros['orden'] === 'descripcion' ? 'selected' : '' ?>
                    >
                        Descripción
                    </option>

                    <option
                        value="codigo"
                        <?= $filtros['orden'] === 'codigo' ? 'selected' : '' ?>
                    >
                        Código
                    </option>

                    <option
                        value="ubicacion"
                        <?= $filtros['orden'] === 'ubicacion' ? 'selected' : '' ?>
                    >
                        Ubicación
                    </option>

                    <option
                        value="existencia_mayor"
                        <?= $filtros['orden'] === 'existencia_mayor' ? 'selected' : '' ?>
                    >
                        Mayor existencia
                    </option>

                    <option
                        value="existencia_menor"
                        <?= $filtros['orden'] === 'existencia_menor' ? 'selected' : '' ?>
                    >
                        Menor existencia
                    </option>

                </select>
            </div>

            <div class="existencias-actions">
                <button type="submit" class="btn-primary-action">
                    Filtrar
                </button>

                <a href="existencias.php" class="btn-secondary-action">
                    Limpiar
                </a>
            </div>

        </form>

    </div>

    <div class="erp-table-card existencias-table-card" id="inventario-actual">

        <div class="table-topbar">
            <div>
                <h3>Inventario actual</h3>

                <p>
                    <?= number_format($totalProductosFiltrados) ?> productos encontrados
                    <?php if ($totalProductosFiltrados > 0): ?>
                        · Mostrando <?= number_format($primerRegistro) ?>–<?= number_format($ultimoRegistro) ?>
                    <?php endif; ?>
                </p>
            </div>
            <span class="existencias-page-indicator">
                20 por página · Página <?= $paginaActual ?> de <?= $totalPaginas ?>
            </span>
        </div>

        <div class="table-responsive" role="region" aria-label="Tabla de existencias, deslice horizontalmente si es necesario" tabindex="0">

            <table class="erp-table tabla-existencias <?= $esAdmin ? 'tabla-existencias--admin' : 'tabla-existencias--general' ?>">
                <colgroup>
                    <col class="col-codigo">
                    <col class="col-barras">
                    <col class="col-descripcion">
                    <col class="col-categoria">
                    <col class="col-proveedor">
                    <col class="col-unidad">
                    <col class="col-almacen">
                    <col class="col-ubicacion">
                    <col class="col-existencia">
                    <col class="col-estado">
                    <?php if ($esAdmin): ?>
                        <col class="col-costos">
                    <?php endif; ?>
                </colgroup>

                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Código barras</th>
                        <th>Descripción</th>
                        <th>Categoría</th>
                        <th>Proveedor</th>
                        <th>Unidad</th>
                        <th>Almacén</th>
                        <th>Ubicación</th>
                        <th>Existencia</th>
                        <th>Estado</th>

                        <?php if ($esAdmin): ?>
                            <th>Costos</th>
                        <?php endif; ?>
                    </tr>
                </thead>

                <tbody>

                    <?php if (!empty($productosPagina)): ?>

                        <?php foreach ($productosPagina as $producto): ?>

                            <?php

                                $existencia = (int)(
                                    $producto['existencia_con_ubicacion']
                                    ?? $producto['existencia']
                                    ?? 0
                                );

                                $costoUltimo = $esAdmin
                                    ? (float)($producto['costo_ultimo'] ?? 0)
                                    : 0.0;

                                $costoPromedio = $esAdmin
                                    ? (float)($producto['costo_promedio'] ?? $costoUltimo)
                                    : 0.0;

                                $valor = $esAdmin
                                    ? $existencia * $costoPromedio
                                    : 0.0;

                                $estadoTexto = strtoupper(
                                    trim((string)($producto['estado_stock'] ?? 'STOCK NORMAL'))
                                );

                                $estadoClase = 'estado-normal';

                                if ($estadoTexto === 'SIN EXISTENCIA') {
                                    $estadoClase = 'estado-sin';

                                } elseif ($estadoTexto === 'SIN ALMACEN') {
                                    $estadoClase = 'estado-dark';

                                } elseif ($estadoTexto === 'STOCK BAJO') {
                                    $estadoClase = 'estado-bajo';
                                }

                            ?>

                            <tr>

                                <td><?= e($producto['codigo'] ?? '') ?></td>

                                <td><?= e($producto['codigo_barras'] ?? '') ?></td>

                                <td class="descripcion-cell">
                                    <?= e($producto['descripcion'] ?? '') ?>
                                </td>

                                <td>
                                    <?= e($producto['categoria'] ?? 'Sin categoría') ?>
                                </td>

                                <td>
                                    <?= e($producto['proveedor'] ?? 'Sin proveedor') ?>
                                </td>

                                <td><?= e($producto['unidad_medida'] ?? '') ?></td>

                                <td>
                                    <?= e($producto['sucursal'] ?? 'SIN ALMACEN') ?>
                                </td>

                                <td>
                                    <?= e($producto['ubicacion'] ?? 'SIN UBICACION') ?>
                                </td>

                                <td class="text-right existencia-number">
                                    <?= number_format($existencia) ?>
                                </td>

                                <td>
                                    <span class="stock-badge <?= e($estadoClase) ?>">
                                        <?= e($estadoTexto) ?>
                                    </span>
                                </td>

                                <?php if ($esAdmin): ?>

                                    <td class="costos-cell">
                                        <button
                                            type="button"
                                            class="btn-vista-costos"
                                            data-costos-modal-open
                                            data-codigo="<?= e($producto['codigo'] ?? '') ?>"
                                            data-barras="<?= e($producto['codigo_barras'] ?? '') ?>"
                                            data-descripcion="<?= e($producto['descripcion'] ?? '') ?>"
                                            data-almacen="<?= e($producto['sucursal'] ?? 'SIN ALMACEN') ?>"
                                            data-ubicacion="<?= e($producto['ubicacion'] ?? 'SIN UBICACION') ?>"
                                            data-existencia="<?= number_format($existencia) ?>"
                                            data-costo-ultimo="$<?= number_format($costoUltimo, 2) ?>"
                                            data-costo-promedio="$<?= number_format($costoPromedio, 4) ?>"
                                            data-valor-promedio="$<?= number_format($valor, 2) ?>"
                                        >
                                            👁 Vista rápida
                                        </button>
                                    </td>

                                <?php endif; ?>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="<?= $esAdmin ? 11 : 10 ?>" class="empty-table">
                                No se encontraron productos con los filtros seleccionados.
                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

        <?php if ($totalPaginas > 1): ?>
            <nav class="existencias-pagination" aria-label="Páginas de existencias">
                <div class="existencias-pagination-info">
                    Mostrando <strong><?= number_format($primerRegistro) ?>–<?= number_format($ultimoRegistro) ?></strong>
                    de <strong><?= number_format($totalProductosFiltrados) ?></strong> productos
                </div>

                <div class="existencias-pagination-links">
                    <?php if ($paginaActual > 1): ?>
                        <a href="<?= e($urlPagina(1)) ?>" aria-label="Ir a la primera página">« Primera</a>
                        <a href="<?= e($urlPagina($paginaActual - 1)) ?>" aria-label="Ir a la página anterior">‹ Anterior</a>
                    <?php else: ?>
                        <span class="is-disabled" aria-disabled="true">« Primera</span>
                        <span class="is-disabled" aria-disabled="true">‹ Anterior</span>
                    <?php endif; ?>

                    <?php
                        $paginaInicial = max(1, $paginaActual - 2);
                        $paginaFinal = min($totalPaginas, $paginaActual + 2);
                    ?>
                    <?php if ($paginaInicial > 1): ?>
                        <span class="pagination-ellipsis" aria-hidden="true">…</span>
                    <?php endif; ?>
                    <?php for ($numero = $paginaInicial; $numero <= $paginaFinal; $numero++): ?>
                        <?php if ($numero === $paginaActual): ?>
                            <span class="is-current" aria-current="page"><?= $numero ?></span>
                        <?php else: ?>
                            <a href="<?= e($urlPagina($numero)) ?>" aria-label="Ir a la página <?= $numero ?>"><?= $numero ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($paginaFinal < $totalPaginas): ?>
                        <span class="pagination-ellipsis" aria-hidden="true">…</span>
                    <?php endif; ?>

                    <?php if ($paginaActual < $totalPaginas): ?>
                        <a href="<?= e($urlPagina($paginaActual + 1)) ?>" aria-label="Ir a la página siguiente">Siguiente ›</a>
                        <a href="<?= e($urlPagina($totalPaginas)) ?>" aria-label="Ir a la última página">Última »</a>
                    <?php else: ?>
                        <span class="is-disabled" aria-disabled="true">Siguiente ›</span>
                        <span class="is-disabled" aria-disabled="true">Última »</span>
                    <?php endif; ?>
                </div>
            </nav>
        <?php endif; ?>

    </div>

</div>

<?php if ($esAdmin): ?>
    <div class="costos-modal" id="costosModal" aria-hidden="true">
        <div class="costos-modal-backdrop" data-costos-modal-close></div>

        <div class="costos-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="costosModalTitulo">
            <div class="costos-modal-header">
                <div>
                    <span class="costos-modal-eyebrow">Vista rápida</span>
                    <h3 id="costosModalTitulo">Detalle de costos</h3>
                    <p id="costosModalDescripcion">Consulta del producto seleccionado.</p>
                </div>

                <button type="button" class="costos-modal-close" data-costos-modal-close aria-label="Cerrar">×</button>
            </div>

            <div class="costos-modal-body">
                <div class="costos-modal-section">
                    <h4>Producto</h4>
                    <div class="costos-form-grid">
                        <div class="costos-form-field">
                            <label>Código</label>
                            <input type="text" id="modalCostoCodigo" readonly>
                        </div>
                        <div class="costos-form-field">
                            <label>Código de barras</label>
                            <input type="text" id="modalCostoBarras" readonly>
                        </div>
                        <div class="costos-form-field costos-span-2">
                            <label>Descripción</label>
                            <textarea id="modalCostoDescripcion" rows="2" readonly></textarea>
                        </div>
                        <div class="costos-form-field">
                            <label>Almacén</label>
                            <input type="text" id="modalCostoAlmacen" readonly>
                        </div>
                        <div class="costos-form-field">
                            <label>Ubicación</label>
                            <input type="text" id="modalCostoUbicacion" readonly>
                        </div>
                        <div class="costos-form-field">
                            <label>Existencia</label>
                            <input type="text" id="modalCostoExistencia" readonly>
                        </div>
                    </div>
                </div>

                <div class="costos-modal-section costos-resumen-section">
                    <h4>Costos</h4>
                    <div class="costos-resumen-grid">
                        <div class="costo-resumen-card">
                            <span>Costo último</span>
                            <strong id="modalCostoUltimo">$0.00</strong>
                            <small>Último costo capturado en una entrada.</small>
                        </div>
                        <div class="costo-resumen-card">
                            <span>Costo promedio</span>
                            <strong id="modalCostoPromedio">$0.0000</strong>
                            <small>Promedio ponderado vigente del producto.</small>
                        </div>
                        <div class="costo-resumen-card destacado">
                            <span>Valor a costo promedio</span>
                            <strong id="modalValorPromedio">$0.00</strong>
                            <small>Existencia × costo promedio.</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="costos-modal-footer">
                <button type="button" class="btn-cerrar-costos" data-costos-modal-close>Cerrar</button>
            </div>
        </div>
    </div>

    <script>
    (() => {
        const modal = document.getElementById('costosModal');
        if (!modal) return;

        const campos = {
            codigo: document.getElementById('modalCostoCodigo'),
            barras: document.getElementById('modalCostoBarras'),
            descripcion: document.getElementById('modalCostoDescripcion'),
            almacen: document.getElementById('modalCostoAlmacen'),
            ubicacion: document.getElementById('modalCostoUbicacion'),
            existencia: document.getElementById('modalCostoExistencia'),
            costoUltimo: document.getElementById('modalCostoUltimo'),
            costoPromedio: document.getElementById('modalCostoPromedio'),
            valorPromedio: document.getElementById('modalValorPromedio')
        };

        let ultimoBoton = null;

        const abrirModal = (boton) => {
            ultimoBoton = boton;
            campos.codigo.value = boton.dataset.codigo || '';
            campos.barras.value = boton.dataset.barras || '';
            campos.descripcion.value = boton.dataset.descripcion || '';
            campos.almacen.value = boton.dataset.almacen || '';
            campos.ubicacion.value = boton.dataset.ubicacion || '';
            campos.existencia.value = boton.dataset.existencia || '0';
            campos.costoUltimo.textContent = boton.dataset.costoUltimo || '$0.00';
            campos.costoPromedio.textContent = boton.dataset.costoPromedio || '$0.0000';
            campos.valorPromedio.textContent = boton.dataset.valorPromedio || '$0.00';

            const descripcionTitulo = document.getElementById('costosModalDescripcion');
            descripcionTitulo.textContent = `${boton.dataset.codigo || ''} · ${boton.dataset.descripcion || 'Producto'}`;

            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('costos-modal-open');
            const closeButton = modal.querySelector('.costos-modal-close');
            if (closeButton) closeButton.focus();
        };

        const cerrarModal = () => {
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('costos-modal-open');
            if (ultimoBoton) ultimoBoton.focus();
        };

        document.querySelectorAll('[data-costos-modal-open]').forEach((boton) => {
            boton.addEventListener('click', () => abrirModal(boton));
        });

        modal.querySelectorAll('[data-costos-modal-close]').forEach((boton) => {
            boton.addEventListener('click', cerrarModal);
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && modal.classList.contains('is-open')) {
                cerrarModal();
            }
        });
    })();
    </script>
<?php endif; ?>

<?php include __DIR__ . '/../app/views/layouts/footer.php'; ?>
