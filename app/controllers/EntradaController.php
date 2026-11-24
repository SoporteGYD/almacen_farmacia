<?php

require_once __DIR__ . '/../models/Movimiento.php';
require_once __DIR__ . '/../models/EntradaBorrador.php';
require_once __DIR__ . '/../helpers/audit.php';

class EntradaController
{
    private Movimiento $movimientoModel;
    private EntradaBorrador $borradorModel;

    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $this->movimientoModel = new Movimiento();
        $this->borradorModel = new EntradaBorrador();
    }

    private function obtenerAlmacenSesion(): int
    {
        $usuario = $_SESSION['user'] ?? [];
        return (int)($usuario['almacen_id'] ?? 0);
    }

    private function limpiarUbicacion(?string $ubicacion): string
    {
        $ubicacion = strtoupper(trim((string)$ubicacion));
        $ubicacion = str_replace('SIN UBICACIÓN', 'SIN UBICACION', $ubicacion);

        return $ubicacion !== '' ? $ubicacion : 'SIN UBICACION';
    }

    public function almacenes(): array
    {
        return $this->movimientoModel->getAlmacenes();
    }

    public function proveedores(): array
    {
        return $this->movimientoModel->getProveedores();
    }

    public function productos(): array
    {
        return $this->movimientoModel->getProductosCatalogo();
    }

    public function generarFolio(?int $almacenId = null): string
    {
        $almacenId = $almacenId !== null
            ? (int)$almacenId
            : $this->obtenerAlmacenSesion();

        return $this->movimientoModel->generarFolioEntrada($almacenId);
    }

    public function ultimoFolioEntrada(?int $almacenId = null): string
    {
        $almacenId = $almacenId !== null
            ? (int)$almacenId
            : $this->obtenerAlmacenSesion();

        return $this->movimientoModel->ultimoFolioEntrada($almacenId);
    }

    public function obtenerEntrada(int $id): ?array
    {
        return $this->movimientoModel->obtenerEntradaPorId($id);
    }

    public function tiposEntrada(): array
    {
        return [
            ['clave' => 'E0001', 'descripcion' => 'Inventario Inicial'],
            ['clave' => 'E0002', 'descripcion' => 'Entrada de Producto'],
            ['clave' => 'E0003', 'descripcion' => 'Ajuste de Entrada de Inventario'],
        ];
    }

    /**
     * ACTUALIZAR ENTRADA EXISTENTE
     */
    public function actualizar(int $movimientoId, array $postData, int $usuarioId): array
{
    // Validar que la entrada existe y no está cancelada
    $entradaExistente = $this->movimientoModel->obtenerEntradaPorId($movimientoId);
    if (!$entradaExistente) {
        return [
            'success' => false,
            'message' => 'La entrada no existe.'
        ];
    }

    if ((int)($entradaExistente['cancelado'] ?? 0) === 1) {
        return [
            'success' => false,
            'message' => 'No puedes editar una entrada cancelada.'
        ];
    }

    $fecha = trim($postData['fecha'] ?? '');
    $tipoEntrada = trim($postData['tipo_entrada'] ?? '');
    $proveedorNombre = trim($postData['proveedor_nombre'] ?? '');
    $referencia = trim($postData['referencia'] ?? '');
    $observaciones = trim($postData['observaciones'] ?? '');

    $usuario = $_SESSION['user'] ?? [];
    $rol = strtoupper(trim($usuario['rol'] ?? ''));
    $almacenSesionId = (int)($usuario['almacen_id'] ?? 0);

    $almacenId = $rol === 'ADMINISTRADOR'
        ? (int)($postData['almacen_id'] ?? 0)
        : $almacenSesionId;

    if ($almacenId <= 0) {
        return [
            'success' => false,
            'message' => 'No tienes un almacén asignado.'
        ];
    }

    $folio = trim($postData['folio'] ?? '');

    if ($folio === '') {
        $folio = $this->movimientoModel->generarFolioEntrada($almacenId);
    }

    $productoIds = $postData['producto_id'] ?? [];
    $cantidades = $postData['cantidad'] ?? [];
    $costos = $postData['costo_unitario'] ?? [];
    $lotes = $postData['numero_lote'] ?? [];
    $caducidades = $postData['fecha_caducidad'] ?? [];
    $ubicaciones = $postData['ubicacion'] ?? [];

    if ($fecha === '') {
        return [
            'success' => false,
            'message' => 'La fecha es obligatoria.'
        ];
    }

    if ($tipoEntrada === '') {
        return [
            'success' => false,
            'message' => 'Debes seleccionar el tipo de entrada.'
        ];
    }

    if (empty($productoIds)) {
        return [
            'success' => false,
            'message' => 'Debes agregar al menos un producto.'
        ];
    }

    $detalle = [];

    foreach ($productoIds as $i => $productoId) {
        $productoId = (int)$productoId;
        $cantidad = isset($cantidades[$i]) ? (int)$cantidades[$i] : 0;
        $costo = isset($costos[$i]) ? (float)$costos[$i] : 0;
        $lote = trim($lotes[$i] ?? '');
        $caducidad = trim($caducidades[$i] ?? '');
        $ubicacion = $this->limpiarUbicacion($ubicaciones[$i] ?? '');

        if ($productoId <= 0) {
            continue;
        }

        if ($cantidad <= 0) {
            return [
                'success' => false,
                'message' => 'La cantidad debe ser mayor a 0 en todos los productos.'
            ];
        }

        if ($costo < 0) {
            return [
                'success' => false,
                'message' => 'El costo unitario no puede ser negativo.'
            ];
        }

        if ($ubicacion === 'SIN UBICACION') {
            return [
                'success' => false,
                'message' => 'Debes escribir o seleccionar una ubicación válida para cada producto.'
            ];
        }

        $detalle[] = [
            'producto_id' => $productoId,
            'cantidad' => $cantidad,
            'costo_unitario' => $costo,
            'precio_unitario' => 0,
            'numero_lote' => $lote,
            'fecha_caducidad' => $caducidad,
            'ubicacion' => $ubicacion,
            'almacen_id' => $almacenId,
        ];
    }

    if (count($detalle) === 0) {
        return [
            'success' => false,
            'message' => 'No hay productos válidos para guardar.'
        ];
    }

    // Construir referencia
    $referenciaFinal = $tipoEntrada;

    if ($proveedorNombre !== '') {
        $referenciaFinal .= ' | Proveedor: ' . $proveedorNombre;
    }

    if ($referencia !== '') {
        $referenciaFinal .= ' | Ref: ' . $referencia;
    }

    $productIdsAudit = auditExtractProductIds(
        $entradaExistente['detalle'] ?? [],
        $detalle
    );
    $inventoryBefore = auditInventorySnapshot(
        $productIdsAudit
    );

    // Actualizar movimiento y sus detalles
    $resultado = $this->movimientoModel->actualizarMovimiento($movimientoId, [
        'folio' => $folio,
        'fecha' => $fecha,
        'almacen_id' => $almacenId,
        'proveedor_nombre' => $proveedorNombre,
        'proveedor_id' => !empty($postData['proveedor_id']) ? (int)$postData['proveedor_id'] : null,
        'referencia' => $referenciaFinal,
        'observaciones' => $observaciones,
        'usuario_id' => $usuarioId,
    ], $detalle);

    if (!empty($resultado['success'])) {
        $entradaActualizada =
            $this->movimientoModel->obtenerEntradaPorId(
                $movimientoId
            );
        $inventoryAfter = auditInventorySnapshot(
            $productIdsAudit
        );

        auditLog([
            'modulo' => 'Entradas',
            'accion' => 'ACTUALIZAR_ENTRADA',
            'entidad' => 'movimiento',
            'registro_id' => $movimientoId,
            'descripcion' => 'Actualizó la entrada '
                . ($entradaActualizada['folio']
                    ?? $entradaExistente['folio']
                    ?? ('#' . $movimientoId))
                . ', incluyendo sus productos, cantidades, lotes o ubicaciones.',
            'anteriores' => [
                'movimiento' => $entradaExistente,
                'existencias' => $inventoryBefore,
            ],
            'nuevos' => [
                'movimiento' => $entradaActualizada,
                'existencias' => $inventoryAfter,
            ],
        ]);
    }

    return $resultado;
}
    public function guardar(array $postData, int $usuarioId): array
    {
        $fecha = trim($postData['fecha'] ?? '');
        $folioAnterior = trim($postData['folio_anterior'] ?? '');
        $tipoEntrada = trim($postData['tipo_entrada'] ?? '');

        $proveedorId = trim($postData['proveedor_id'] ?? '');
        $proveedorNombre = trim($postData['proveedor_nombre'] ?? '');

        $referencia = trim($postData['referencia'] ?? '');
        $observaciones = trim($postData['observaciones'] ?? '');

        $usuario = $_SESSION['user'] ?? [];
        $rol = strtoupper(trim($usuario['rol'] ?? ''));
        $almacenSesionId = (int)($usuario['almacen_id'] ?? 0);

        $almacenId = $rol === 'ADMINISTRADOR'
            ? (int)($postData['almacen_id'] ?? 0)
            : $almacenSesionId;

        if ($almacenId <= 0) {
            return [
                'success' => false,
                'message' => 'No tienes un almacén asignado.'
            ];
        }

        $folio = trim($postData['folio'] ?? '');

        if ($folio === '') {
            $folio = $this->movimientoModel->generarFolioEntrada($almacenId);
        }

        $productoIds = $postData['producto_id'] ?? [];
        $cantidades = $postData['cantidad'] ?? [];
        $costos = $postData['costo_unitario'] ?? [];
        $lotes = $postData['numero_lote'] ?? [];
        $caducidades = $postData['fecha_caducidad'] ?? [];
        $ubicaciones = $postData['ubicacion'] ?? [];

        if ($fecha === '') {
            return [
                'success' => false,
                'message' => 'La fecha es obligatoria.'
            ];
        }

        if ($tipoEntrada === '') {
            return [
                'success' => false,
                'message' => 'Debes seleccionar el tipo de entrada.'
            ];
        }

        if (empty($productoIds)) {
            return [
                'success' => false,
                'message' => 'Debes agregar al menos un producto.'
            ];
        }

        $detalle = [];

        foreach ($productoIds as $i => $productoId) {
            $productoId = (int)$productoId;
            $cantidad = isset($cantidades[$i]) ? (int)$cantidades[$i] : 0;
            $costo = isset($costos[$i]) ? (float)$costos[$i] : 0;
            $lote = trim($lotes[$i] ?? '');
            $caducidad = trim($caducidades[$i] ?? '');
            $ubicacion = $this->limpiarUbicacion($ubicaciones[$i] ?? '');

            if ($productoId <= 0) {
                continue;
            }

            if ($cantidad <= 0) {
                return [
                    'success' => false,
                    'message' => 'La cantidad debe ser mayor a 0 en todos los productos.'
                ];
            }

            if ($costo < 0) {
                return [
                    'success' => false,
                    'message' => 'El costo unitario no puede ser negativo.'
                ];
            }

            if ($ubicacion === 'SIN UBICACION') {
                return [
                    'success' => false,
                    'message' => 'Debes escribir o seleccionar una ubicación válida para cada producto.'
                ];
            }

            $detalle[] = [
                'producto_id' => $productoId,
                'cantidad' => $cantidad,
                'costo_unitario' => $costo,
                'precio_unitario' => 0,
                'numero_lote' => $lote,
                'fecha_caducidad' => $caducidad,
                'ubicacion' => $ubicacion,
                'almacen_id' => $almacenId,
            ];
        }

        if (count($detalle) === 0) {
            return [
                'success' => false,
                'message' => 'No hay productos válidos para guardar.'
            ];
        }

        $referenciaFinal = $tipoEntrada;

        if ($folioAnterior !== '') {
            $referenciaFinal .= ' | Folio anterior: ' . $folioAnterior;
        }

        if ($proveedorNombre !== '') {
            $referenciaFinal .= ' | Proveedor: ' . $proveedorNombre;
        }

        if ($referencia !== '') {
            $referenciaFinal .= ' | Ref: ' . $referencia;
        }

        $productIdsAudit = auditExtractProductIds($detalle);
        $inventoryBefore = auditInventorySnapshot(
            $productIdsAudit
        );

        $resultado = $this->movimientoModel->crearMovimiento([
            'folio' => $folio,
            'tipo_movimiento' => 'ENTRADA',
            'fecha' => $fecha,
            'almacen_id' => $almacenId,
            'proveedor_id' => $proveedorId !== '' ? (int)$proveedorId : null,
            'proveedor_nombre' => $proveedorNombre,
            'referencia' => $referenciaFinal,
            'observaciones' => $observaciones,
            'usuario_id' => $usuarioId,
        ], $detalle);

        if (!empty($resultado['success'])) {
            $movimientoId = (int) (
                $resultado['movimiento_id'] ?? 0
            );
            $entradaCreada = $movimientoId > 0
                ? $this->movimientoModel->obtenerEntradaPorId(
                    $movimientoId
                )
                : null;
            $inventoryAfter = auditInventorySnapshot(
                $productIdsAudit
            );

            auditLog([
                'modulo' => 'Entradas',
                'accion' => 'CREAR_ENTRADA',
                'entidad' => 'movimiento',
                'registro_id' => $movimientoId ?: null,
                'descripcion' => 'Registró la entrada '
                    . ($resultado['folio'] ?? $folio)
                    . ' con ' . count($detalle) . ' producto(s).',
                'anteriores' => [
                    'existencias' => $inventoryBefore,
                ],
                'nuevos' => [
                    'movimiento' => $entradaCreada ?? [
                        'folio' => $resultado['folio'] ?? $folio,
                        'almacen_id' => $almacenId,
                        'detalle' => $detalle,
                    ],
                    'existencias' => $inventoryAfter,
                ],
            ]);
        }

        return $resultado;
    }

    public function cancelarEntrada(int $movimientoId, int $usuarioId, string $motivo = ''): array
    {
        $entradaAnterior =
            $this->movimientoModel->obtenerEntradaPorId(
                $movimientoId
            );
        $productIdsAudit = auditExtractProductIds(
            $entradaAnterior['detalle'] ?? []
        );
        $inventoryBefore = auditInventorySnapshot(
            $productIdsAudit
        );
        $resultado = $this->movimientoModel->cancelarEntrada(
            $movimientoId,
            $usuarioId,
            $motivo
        );

        if (!empty($resultado['success'])) {
            $entradaNueva =
                $this->movimientoModel->obtenerEntradaPorId(
                    $movimientoId
                );
            $inventoryAfter = auditInventorySnapshot(
                $productIdsAudit
            );

            auditLog([
                'modulo' => 'Entradas',
                'accion' => 'CANCELAR_ENTRADA',
                'entidad' => 'movimiento',
                'registro_id' => $movimientoId,
                'descripcion' => 'Canceló la entrada '
                    . ($entradaAnterior['folio']
                        ?? ('#' . $movimientoId))
                    . '. Motivo: '
                    . ($motivo !== '' ? $motivo : 'Sin motivo capturado')
                    . '.',
                'anteriores' => [
                    'movimiento' => $entradaAnterior,
                    'existencias' => $inventoryBefore,
                ],
                'nuevos' => [
                    'movimiento' => $entradaNueva,
                    'existencias' => $inventoryAfter,
                ],
                'metadata' => [
                    'motivo_cancelacion' => $motivo,
                ],
            ]);
        }

    return $resultado;
}

    public function guardarBorrador(
        array $postData,
        int $usuarioId
    ): array {
        $usuario = $_SESSION['user'] ?? [];
        $rol = strtoupper(
            trim((string) ($usuario['rol'] ?? ''))
        );
        $almacenSesionId = (int) (
            $usuario['almacen_id'] ?? 0
        );
        $almacenId = $rol === 'ADMINISTRADOR'
            ? (int) ($postData['almacen_id'] ?? 0)
            : $almacenSesionId;
        $borradorId = (int) (
            $postData['borrador_id'] ?? 0
        );
        $datos = $postData['datos'] ?? [];

        if (!is_array($datos)) {
            throw new InvalidArgumentException(
                'Los datos del borrador no son válidos.'
            );
        }

        $resultado = $this->borradorModel->guardar(
            $usuarioId,
            $almacenId,
            (string) ($postData['nombre'] ?? ''),
            $datos,
            $borradorId > 0 ? $borradorId : null
        );

        auditLog([
            'modulo' => 'Entradas',
            'accion' => !empty($resultado['actualizado'])
                ? 'ACTUALIZAR_BORRADOR_ENTRADA'
                : 'CREAR_BORRADOR_ENTRADA',
            'entidad' => 'entrada_borrador',
            'registro_id' => $resultado['id'] ?? null,
            'descripcion' => (
                !empty($resultado['actualizado'])
                    ? 'Actualizó'
                    : 'Guardó'
            ) . ' el borrador de entrada "'
                . ($resultado['nombre'] ?? 'Sin nombre')
                . '".',
            'nuevos' => [
                'nombre' => $resultado['nombre'] ?? '',
                'almacen_id' => $almacenId,
                'total_productos' => (
                    $resultado['total_productos'] ?? 0
                ),
            ],
        ]);

        return $resultado;
    }

    public function listarBorradores(
        int $usuarioId
    ): array {
        return $this->borradorModel->listarPorUsuario(
            $usuarioId
        );
    }

    public function obtenerBorrador(
        int $borradorId,
        int $usuarioId
    ): ?array {
        $borrador = $this->borradorModel->obtener(
            $borradorId,
            $usuarioId
        );

        if ($borrador) {
            auditLog([
                'modulo' => 'Entradas',
                'accion' => 'CONTINUAR_BORRADOR_ENTRADA',
                'entidad' => 'entrada_borrador',
                'registro_id' => $borradorId,
                'descripcion' => 'Abrió el borrador de entrada "'
                    . ($borrador['nombre'] ?? 'Sin nombre')
                    . '" para continuar su captura.',
            ]);
        }

        return $borrador;
    }

    public function eliminarBorrador(
        int $borradorId,
        int $usuarioId,
        string $motivo = 'ELIMINADO'
    ): bool {
        $borrador = $this->borradorModel->obtener(
            $borradorId,
            $usuarioId
        );

        if (!$borrador) {
            return false;
        }

        $eliminado = $this->borradorModel->eliminar(
            $borradorId,
            $usuarioId
        );

        if ($eliminado) {
            $finalizado = strtoupper($motivo) === 'FINALIZADO';

            auditLog([
                'modulo' => 'Entradas',
                'accion' => $finalizado
                    ? 'FINALIZAR_BORRADOR_ENTRADA'
                    : 'ELIMINAR_BORRADOR_ENTRADA',
                'entidad' => 'entrada_borrador',
                'registro_id' => $borradorId,
                'descripcion' => $finalizado
                    ? 'Convirtió el borrador "'
                        . ($borrador['nombre'] ?? 'Sin nombre')
                        . '" en una entrada definitiva.'
                    : 'Eliminó el borrador de entrada "'
                        . ($borrador['nombre'] ?? 'Sin nombre')
                        . '".',
            ]);
        }

        return $eliminado;
    }

    /**
     * Obtiene todas las ubicaciones únicas del sistema
     * @param int|null $almacenId ID del almacén (opcional)
     * @return array Lista de ubicaciones únicas
     */
    public function ubicacionesTodas(?int $almacenId = null): array
    {
        return $this->movimientoModel->getTodasUbicacionesPorSucursal($almacenId);
    }
/**
 * Obtiene el historial de entradas con filtros
 */
public function historialEntradas(
    string $buscar = '',
    int $almacenId = 0,
    string $fechaInicio = '',
    string $fechaFinal = ''
): array {
    return $this->movimientoModel->historialEntradas($buscar, $almacenId, $fechaInicio, $fechaFinal);
}

/**
 * Calcula el resumen de las entradas
 */
public function resumen(array $entradas): array
{
    $totalEntradas = count($entradas);
    $totalProductos = 0;
    $totalUnidades = 0;
    $totalImporte = 0.0;

    foreach ($entradas as $entrada) {
        $totalProductos += (int)($entrada['total_productos'] ?? 0);
        $totalUnidades += (int)($entrada['total_unidades'] ?? 0);
        $totalImporte += (float)($entrada['total'] ?? 0);
    }

    return [
        'total_entradas' => $totalEntradas,
        'total_productos' => $totalProductos,
        'total_unidades' => $totalUnidades,
        'total_importe' => $totalImporte,
    ];
}
}
