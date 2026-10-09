<?php

require_once __DIR__ . '/../models/Producto.php';
require_once __DIR__ . '/../helpers/audit.php';

class ProductoController
{
    private Producto $productoModel;

    public function __construct()
    {
        $this->productoModel = new Producto();
    }

    private function productoAuditData(?array $producto): array
    {
        if (!$producto) {
            return [];
        }

        $fields = [
            'id',
            'codigo',
            'codigo_barras',
            'descripcion',
            'categoria_id',
            'proveedor_id',
            'laboratorio',
            'unidad_medida',
            'precio_compra',
            'costo_ultimo',
            'costo_promedio',
            'precio_venta',
            'ubicacion',
            'estado',
        ];

        return array_intersect_key(
            $producto,
            array_flip($fields)
        );
    }

    private function esAdministrador(): bool
    {
        $user = $_SESSION['user'] ?? null;

        return isset($user['rol'])
            && in_array(strtoupper(trim($user['rol'])), ['ADMINISTRADOR', 'ADMIN'], true);
    }

    private function sucursalUsuario(): string
    {
        $user = $_SESSION['user'] ?? null;

        if (!$user) {
            return '';
        }

        $almacenNombre = strtoupper(trim($user['almacen_nombre'] ?? ''));

        if (str_contains($almacenNombre, 'HIDALGO')) {
            return 'CIUDAD HIDALGO';
        }

        if (str_contains($almacenNombre, 'TUXTLA')) {
            return 'TUXTLA';
        }

        $almacenId = (int)($user['almacen_id'] ?? 0);

        if ($almacenId === 1) {
            return 'CIUDAD HIDALGO';
        }

        if ($almacenId === 2 || $almacenId === 3) {
            return 'TUXTLA';
        }

        return '';
    }

    public function index(
        string $search = '',
        string $sucursal = '',
        string $categoriaId = '',
        string $proveedor = '',
        string $ubicacion = '',
        string $estadoStock = ''
    ): array {
        $isAdmin = $this->esAdministrador();

        if (!$isAdmin) {
            $sucursal = $this->sucursalUsuario();
        }

        return $this->productoModel->getAll(
            trim($search),
            trim($sucursal),
            $isAdmin,
            trim($categoriaId),
            '',
            strtoupper(trim($ubicacion)),
            trim($estadoStock)
        );
    }

    public function count(string $search = '', string $sucursal = ''): int
    {
        return $this->productoModel->countAll(trim($search));
    }

    public function categorias(): array
    {
        return $this->productoModel->getCategorias();
    }

    public function proveedores(): array
    {
        return $this->productoModel->getProveedores();
    }

    public function almacenes(): array
    {
        return $this->productoModel->getAlmacenes();
    }

    public function find(int $id): ?array
    {
        return $this->productoModel->findById($id);
    }

    private function sanitizeData(array $data): array
    {
        return [
            'codigo' => trim($data['codigo'] ?? ''),
            'codigo_barras' => trim($data['codigo_barras'] ?? ''),
            'descripcion' => trim($data['descripcion'] ?? ''),
            'categoria_id' => trim($data['categoria_id'] ?? ''),
            'proveedor_nombre' => trim($data['proveedor_nombre'] ?? ''),
            'laboratorio' => trim($data['laboratorio'] ?? ''),
            'unidad_medida' => trim($data['unidad_medida'] ?? ''),
            'precio_compra' => trim($data['precio_compra'] ?? '0'),
            'precio_venta' => trim($data['precio_venta'] ?? '0'),
            'stock_minimo' => 0,
            'stock_maximo' => 0,
            'ubicacion' => strtoupper(trim($data['ubicacion'] ?? '')),
        ];
    }

    private function validate(array $data, ?int $id = null): array
    {
        if ($data['codigo'] === '') {
            return [
                'success' => false,
                'message' => 'El código es obligatorio.'
            ];
        }

        if ($data['descripcion'] === '') {
            return [
                'success' => false,
                'message' => 'La descripción es obligatoria.'
            ];
        }

        // El proveedor es opcional, pero solo admite proveedores comerciales.
        // Los traspasos se documentan en Entradas y sus observaciones.
        $nombreProveedor = trim((string)($data['proveedor_nombre'] ?? ''));
        $longitudProveedor = function_exists('mb_strlen')
            ? mb_strlen($nombreProveedor, 'UTF-8')
            : strlen($nombreProveedor);
        if ($longitudProveedor > 150) {
            return [
                'success' => false,
                'message' => 'El nombre del proveedor no puede superar 150 caracteres.'
            ];
        }
        if ($nombreProveedor !== '' && proveedorEsConceptoInterno($nombreProveedor)) {
            return [
                'success' => false,
                'message' => 'TRASPASO o ENTRADA no son proveedores comerciales. Deja el proveedor vacío; registra el traspaso en Entradas.'
            ];
        }

        if (!is_numeric($data['precio_compra']) || (float)$data['precio_compra'] < 0) {
            return [
                'success' => false,
                'message' => 'El costo último no es válido.'
            ];
        }

        if (!is_numeric($data['precio_venta']) || (float)$data['precio_venta'] < 0) {
            return [
                'success' => false,
                'message' => 'El precio de venta no es válido.'
            ];
        }

        if ($this->productoModel->existsByCodigo($data['codigo'], $id)) {
            return [
                'success' => false,
                'message' => 'Ya existe un producto con ese código en el catálogo.'
            ];
        }

        return [
            'success' => true
        ];
    }

    public function store(array $postData): array
    {
        $data = $this->sanitizeData($postData);

        $productoInactivo =
            $this->productoModel->findByCodigoExacto(
                $data['codigo']
            );

        if (
            $productoInactivo
            && (int) ($productoInactivo['estado'] ?? 1) === 0
        ) {
            $productoId = (int) $productoInactivo['id'];
            $validation = $this->validate(
                $data,
                $productoId
            );

            if (!$validation['success']) {
                return $validation;
            }

            $ok = $this->productoModel->reactivate(
                $productoId,
                $data
            );

            if (!$ok) {
                return [
                    'success' => false,
                    'message' =>
                        'No se pudo reactivar el producto.'
                ];
            }

            $productoReactivado =
                $this->productoModel->findById(
                    $productoId
                );

            auditLog([
                'modulo' => 'Productos',
                'accion' => 'REACTIVAR_PRODUCTO',
                'entidad' => 'producto',
                'registro_id' => $productoId,
                'descripcion' =>
                    'Reactivó el producto '
                    . (
                        $productoReactivado['descripcion']
                        ?? $data['descripcion']
                    )
                    . ' con código '
                    . $data['codigo']
                    . '.',
                'anteriores' =>
                    $this->productoAuditData(
                        $productoInactivo
                    ),
                'nuevos' =>
                    $this->productoAuditData(
                        $productoReactivado
                    )
            ]);

            return [
                'success' => true,
                'message' =>
                    'El producto estaba eliminado y fue reactivado correctamente. Conserva su historial y sus ubicaciones anteriores.',
                'producto_id' => $productoId,
                'reactivado' => true
            ];
        }

        $validation = $this->validate($data);

        if (!$validation['success']) {
            return $validation;
        }

        $ok = $this->productoModel->create($data);

        if (!$ok) {
            return [
                'success' => false,
                'message' => 'No se pudo registrar el producto.'
            ];
        }

        $productoCreado = $this->productoModel->findByCodigo(
            $data['codigo']
        );

        auditLog([
            'modulo' => 'Productos',
            'accion' => 'CREAR_PRODUCTO',
            'entidad' => 'producto',
            'registro_id' => $productoCreado['id'] ?? $data['codigo'],
            'descripcion' => 'Creó el producto '
                . ($productoCreado['descripcion'] ?? $data['descripcion'])
                . ' con código ' . $data['codigo'] . '.',
            'nuevos' => $this->productoAuditData($productoCreado)
                ?: $data,
        ]);

        return [
            'success' => true,
            'message' => 'Producto registrado correctamente.',
            'producto_id' => isset($productoCreado['id'])
                ? (int) $productoCreado['id']
                : null,
        ];
    }

    public function update(int $id, array $postData): array
    {
        $productoActual = $this->productoModel->findById($id);

        if (!$productoActual) {
            return [
                'success' => false,
                'message' => 'El producto no existe.'
            ];
        }

        $data = $this->sanitizeData($postData);

        $validation = $this->validate($data, $id);

        if (!$validation['success']) {
            return $validation;
        }

        $ok = $this->productoModel->update($id, $data);

        if (!$ok) {
            return [
                'success' => false,
                'message' => 'No se pudo actualizar el producto.'
            ];
        }

        $productoActualizado = $this->productoModel->findById($id);
        $changes = auditChangedValues(
            $this->productoAuditData($productoActual),
            $this->productoAuditData($productoActualizado)
        );

        auditLog([
            'modulo' => 'Productos',
            'accion' => 'ACTUALIZAR_PRODUCTO',
            'entidad' => 'producto',
            'registro_id' => $id,
            'descripcion' => 'Actualizó el producto '
                . ($productoActualizado['descripcion']
                    ?? $productoActual['descripcion']
                    ?? ('#' . $id))
                . '.',
            'anteriores' => $changes['anteriores'],
            'nuevos' => $changes['nuevos'],
        ]);

        return [
            'success' => true,
            'message' => 'Producto actualizado correctamente.'
        ];
    }

    public function importarInformacion(array $data): array
    {
        $codigo = trim($data['codigo'] ?? '');

        if ($codigo === '') {
            return [
                'success' => false,
                'message' => 'El código de barras es obligatorio.'
            ];
        }

        $ok = $this->productoModel->crearOActualizarCatalogo([
            'codigo' => $codigo,
            'codigo_barras' => $data['codigo_barras'] ?? $codigo,
            'descripcion' => $data['descripcion'] ?? '',
            'laboratorio' => $data['marca'] ?? '',
            'unidad_medida' => '',
            'categoria_id' => null,
            'proveedor_nombre' => '',
            'precio_compra' => 0,
            'precio_venta' => 0,
            'stock_minimo' => 0,
            'stock_maximo' => 0,
            'ubicacion' => '',
        ]);

        return [
            'success' => $ok,
            'message' => $ok
                ? 'Producto importado correctamente.'
                : 'No se pudo importar el producto.'
        ];
    }

    public function importarExistencia(
        string $codigo,
        string $sucursal,
        int $existencia
    ): array {
        $codigo = trim($codigo);
        $sucursal = strtoupper(trim($sucursal));

        if ($codigo === '') {
            return [
                'success' => false,
                'message' => 'El código de barras es obligatorio.'
            ];
        }

        if ($sucursal === '') {
            return [
                'success' => false,
                'message' => 'La sucursal es obligatoria.'
            ];
        }

        if ($existencia < 0) {
            $existencia = 0;
        }

        $producto = $this->productoModel->findByCodigo($codigo);
        $ubicacionAnterior = $producto
            ? $this->productoModel->getUbicacionExistencia(
                (int) $producto['id'],
                $sucursal,
                $ubicacion
            )
            : null;

        $ok = $this->productoModel->actualizarExistenciaPorCodigo(
            $codigo,
            $sucursal,
            $existencia
        );

        return [
            'success' => $ok,
            'message' => $ok
                ? 'Existencia actualizada correctamente.'
                : 'No se encontró el producto en el catálogo.'
        ];
    }

    public function guardarUbicacionExistencia(array $postData): array
    {
        $codigo = trim($postData['codigo'] ?? '');
        $sucursal = strtoupper(trim($postData['sucursal'] ?? ''));
        $ubicacion = strtoupper(trim($postData['ubicacion'] ?? ''));
        $existencia = (int)($postData['existencia'] ?? 0);

        if ($codigo === '') {
            return [
                'success' => false,
                'message' => 'Código vacío.'
            ];
        }

        if ($sucursal === '') {
            return [
                'success' => false,
                'message' => 'Sucursal vacía.'
            ];
        }

        if ($ubicacion === '') {
            $ubicacion = 'SIN UBICACION';
        }

        if ($existencia < 0) {
            $existencia = 0;
        }

        $ok = $this->productoModel->actualizarExistenciaPorCodigo(
            $codigo,
            $sucursal,
            $existencia,
            $ubicacion
        );

        if ($ok && $producto) {
            $ubicacionNueva = $this->productoModel->getUbicacionExistencia(
                (int) $producto['id'],
                $sucursal,
                $ubicacion
            );

            auditLog([
                'modulo' => 'Productos',
                'accion' => $ubicacionAnterior
                    ? 'ACTUALIZAR_EXISTENCIA_UBICACION'
                    : 'CREAR_UBICACION',
                'entidad' => 'producto_existencia',
                'registro_id' => $ubicacionNueva['id']
                    ?? $ubicacionAnterior['id']
                    ?? null,
                'descripcion' => (
                    $ubicacionAnterior
                        ? 'Actualizó'
                        : 'Creó'
                )
                    . ' la ubicación ' . $ubicacion
                    . ' del producto '
                    . ($producto['descripcion'] ?? $codigo)
                    . ' en ' . $sucursal . '; existencia de '
                    . (int) ($ubicacionAnterior['existencia'] ?? 0)
                    . ' a '
                    . (int) ($ubicacionNueva['existencia'] ?? $existencia)
                    . '.',
                'anteriores' => $ubicacionAnterior,
                'nuevos' => $ubicacionNueva,
                'metadata' => [
                    'producto_id' => (int) $producto['id'],
                    'codigo' => $codigo,
                    'descripcion' => $producto['descripcion'] ?? null,
                ],
            ]);
        }

        return [
            'success' => $ok,
            'message' => $ok
                ? 'Ubicación guardada correctamente.'
                : 'No se pudo guardar la ubicación. Verifica que el producto exista.'
        ];
    }

    /**
     * Editar ubicación y existencia
     * - Si la existencia es 0, permite actualizar a 0 o eliminar
     * - Si la ubicación cambia, se renombra
     */
    public function editarUbicacionExistencia(array $postData): array
    {
        $productoId = (int)($postData['producto_id'] ?? 0);
        $sucursal = strtoupper(trim($postData['sucursal'] ?? ''));
        $ubicacionAnterior = strtoupper(trim($postData['ubicacion_anterior'] ?? ''));
        $ubicacionNueva = strtoupper(trim($postData['ubicacion_nueva'] ?? ''));
        $existencia = (int)($postData['existencia'] ?? 0);

        // Validaciones
        if ($productoId <= 0) {
            return [
                'success' => false,
                'message' => 'ID de producto inválido.'
            ];
        }

        if ($sucursal === '') {
            return [
                'success' => false,
                'message' => 'Sucursal inválida.'
            ];
        }

        if ($ubicacionAnterior === '') {
            $ubicacionAnterior = 'SIN UBICACION';
        }

        if ($ubicacionNueva === '') {
            $ubicacionNueva = 'SIN UBICACION';
        }

        if ($existencia < 0) {
            $existencia = 0;
        }

        // Primero, verificar si la ubicación existe
        $ubicacionExistente = $this->productoModel->getUbicacionExistencia(
            $productoId,
            $sucursal,
            $ubicacionAnterior
        );

        $producto = $this->productoModel->findById($productoId);

        if (!$ubicacionExistente && $existencia > 0) {
            // Si no existe y queremos agregar existencia, crear nueva
            $ok = $this->productoModel->crearUbicacionExistencia(
                $productoId,
                $sucursal,
                $ubicacionNueva,
                $existencia
            );

            if ($ok) {
                $ubicacionDespues =
                    $this->productoModel->getUbicacionExistencia(
                        $productoId,
                        $sucursal,
                        $ubicacionNueva
                    );

                auditLog([
                    'modulo' => 'Productos',
                    'accion' => 'CREAR_UBICACION',
                    'entidad' => 'producto_existencia',
                    'registro_id' => $ubicacionDespues['id'] ?? null,
                    'descripcion' => 'Creó la ubicación '
                        . $ubicacionNueva . ' con '
                        . $existencia . ' unidades para '
                        . ($producto['descripcion'] ?? ('producto #' . $productoId))
                        . '.',
                    'nuevos' => $ubicacionDespues,
                    'metadata' => [
                        'producto_id' => $productoId,
                        'sucursal' => $sucursal,
                    ],
                ]);
            }

            return [
                'success' => $ok,
                'message' => $ok
                    ? 'Ubicación creada correctamente.'
                    : 'No se pudo crear la ubicación.'
            ];
        }

        // Si la existencia es 0, el usuario puede elegir eliminar o mantener en 0
        if ($existencia === 0) {
            // Por ahora, solo actualizamos a 0 (no eliminamos automáticamente)
            $ok = $this->productoModel->actualizarUbicacionExistencia(
                $productoId,
                $sucursal,
                $ubicacionAnterior,
                $ubicacionNueva,
                0
            );

            if ($ok) {
                $ubicacionDespues =
                    $this->productoModel->getUbicacionExistencia(
                        $productoId,
                        $sucursal,
                        $ubicacionNueva
                    );

                auditLog([
                    'modulo' => 'Productos',
                    'accion' => 'ACTUALIZAR_EXISTENCIA_UBICACION',
                    'entidad' => 'producto_existencia',
                    'registro_id' => $ubicacionDespues['id']
                        ?? $ubicacionExistente['id']
                        ?? null,
                    'descripcion' => 'Cambió a 0 la existencia de '
                        . ($producto['descripcion'] ?? ('producto #' . $productoId))
                        . ' en la ubicación ' . $ubicacionNueva . '.',
                    'anteriores' => $ubicacionExistente,
                    'nuevos' => $ubicacionDespues,
                    'metadata' => [
                        'producto_id' => $productoId,
                        'sucursal' => $sucursal,
                    ],
                ]);
            }

            return [
                'success' => $ok,
                'message' => $ok
                    ? 'Existencia actualizada a 0 unidades.'
                    : 'No se pudo actualizar la ubicación.'
            ];
        }

        // Actualizar ubicación y existencia (existencia > 0)
        $ok = $this->productoModel->actualizarUbicacionExistencia(
            $productoId,
            $sucursal,
            $ubicacionAnterior,
            $ubicacionNueva,
            $existencia
        );

        if ($ok) {
            $ubicacionDespues =
                $this->productoModel->getUbicacionExistencia(
                    $productoId,
                    $sucursal,
                    $ubicacionNueva
                );

            auditLog([
                'modulo' => 'Productos',
                'accion' => 'ACTUALIZAR_UBICACION_EXISTENCIA',
                'entidad' => 'producto_existencia',
                'registro_id' => $ubicacionDespues['id']
                    ?? $ubicacionExistente['id']
                    ?? null,
                'descripcion' => 'Actualizó la ubicación de '
                    . ($producto['descripcion'] ?? ('producto #' . $productoId))
                    . ' de ' . $ubicacionAnterior
                    . ' a ' . $ubicacionNueva
                    . ' y dejó la existencia en '
                    . $existencia . ' unidades.',
                'anteriores' => $ubicacionExistente,
                'nuevos' => $ubicacionDespues,
                'metadata' => [
                    'producto_id' => $productoId,
                    'sucursal' => $sucursal,
                ],
            ]);
        }

        return [
            'success' => $ok,
            'message' => $ok
                ? 'Ubicación actualizada correctamente.'
                : 'No se pudo actualizar la ubicación. Verifica que los datos sean correctos.'
        ];
    }

    /**
     * NUEVO MÉTODO: Eliminar una ubicación específica
     */
    public function eliminarUbicacion(array $postData): array
    {
        $productoId = (int)($postData['producto_id'] ?? 0);
        $sucursal = strtoupper(trim($postData['sucursal'] ?? ''));
        $ubicacion = strtoupper(trim($postData['ubicacion'] ?? ''));

        if ($productoId <= 0) {
            return [
                'success' => false,
                'message' => 'ID de producto inválido.'
            ];
        }

        if ($sucursal === '') {
            return [
                'success' => false,
                'message' => 'Sucursal inválida.'
            ];
        }

        if ($ubicacion === '') {
            return [
                'success' => false,
                'message' => 'Ubicación inválida.'
            ];
        }

        // Verificar que la existencia sea 0 antes de eliminar
        $ubicacionData = $this->productoModel->getUbicacionExistencia(
            $productoId,
            $sucursal,
            $ubicacion
        );

        if (
            $ubicacionData
            && (int) (
                $ubicacionData['existencia_actual']
                ?? $ubicacionData['existencia']
                ?? 0
            ) > 0
        ) {
            return [
                'success' => false,
                'message' => 'No se puede eliminar una ubicación que tiene existencia. Primero pon la existencia en 0.'
            ];
        }

        $ok = $this->productoModel->eliminarUbicacionExistencia(
            $productoId,
            $sucursal,
            $ubicacion
        );

        if ($ok && $ubicacionData) {
            $producto = $this->productoModel->findById($productoId);

            auditLog([
                'modulo' => 'Productos',
                'accion' => 'ELIMINAR_UBICACION',
                'entidad' => 'producto_existencia',
                'registro_id' => $ubicacionData['id'] ?? null,
                'descripcion' => 'Eliminó la ubicación '
                    . $ubicacion . ' de '
                    . ($producto['descripcion'] ?? ('producto #' . $productoId))
                    . ' en ' . $sucursal . '.',
                'anteriores' => $ubicacionData,
                'nuevos' => [
                    'estado' => 'ELIMINADA'
                ],
                'metadata' => [
                    'producto_id' => $productoId,
                ],
            ]);
        }

        return [
            'success' => $ok,
            'message' => $ok
                ? 'Ubicación eliminada correctamente.'
                : 'No se pudo eliminar la ubicación. Es posible que no exista.'
        ];
    }

    public function destroy(int $id): array
    {
        $producto = $this->productoModel->findById($id);

        if (!$producto) {
            return [
                'success' => false,
                'message' => 'El producto no existe.'
            ];
        }

        $ok = $this->productoModel->deleteLogical($id);

        if (!$ok) {
            return [
                'success' => false,
                'message' => 'No se pudo eliminar el producto.'
            ];
        }

        auditLog([
            'modulo' => 'Productos',
            'accion' => 'DESACTIVAR_PRODUCTO',
            'entidad' => 'producto',
            'registro_id' => $id,
            'descripcion' => 'Desactivó el producto '
                . ($producto['descripcion'] ?? ('#' . $id))
                . '.',
            'anteriores' => $this->productoAuditData($producto),
            'nuevos' => array_merge(
                $this->productoAuditData($producto),
                ['estado' => 0]
            ),
        ]);

        return [
            'success' => true,
            'message' =>
                'Producto eliminado del catálogo visible. Podrás darlo de alta nuevamente con el mismo código.'
        ];
    }
}
