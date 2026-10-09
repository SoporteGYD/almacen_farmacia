<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Proveedor.php';

class Producto
{
    private PDO $conn;
    private string $table = "productos";
    private int $limiteBajoStock = 120;

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->connect();
    }

    private function limpiarUbicacion(?string $ubicacion): string
    {
        $ubicacion = strtoupper(trim((string)$ubicacion));
        $ubicacion = str_replace('SIN UBICACIÓN', 'SIN UBICACION', $ubicacion);

        return $ubicacion !== '' ? $ubicacion : 'SIN UBICACION';
    }

    private function limpiarSucursal(?string $sucursal): string
    {
        return strtoupper(trim((string)$sucursal));
    }

    public function getCategorias(): array
    {
        $sql = "SELECT id, nombre 
                FROM categorias 
                WHERE estado = 1 
                ORDER BY nombre ASC";

        return $this->conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getProveedores(): array
    {
        $sql = "SELECT id, nombre 
                FROM proveedores 
                WHERE estado = 1 
                ORDER BY nombre ASC";

        return $this->conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAlmacenes(): array
    {
        $sql = "SELECT id, nombre 
                FROM almacenes 
                WHERE estado = 1 
                ORDER BY nombre ASC";

        return $this->conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Resolver el proveedor del catálogo sin crear proveedores falsos para
     * TRASPASO, ENTRADA u otros movimientos internos.
     * Se comparte la misma lógica validada que utiliza Entradas.
     */
    private function obtenerOCrearProveedor(string $nombre): ?int
    {
        return Proveedor::resolverId($this->conn, $nombre);
    }

    private function calcularEstadoStock(int $existencia): string
    {
        if ($existencia <= 0) {
            return 'agotado';
        }

        if ($existencia <= $this->limiteBajoStock) {
            return 'bajo';
        }

        return 'normal';
    }

    public function getAll(
        string $search = '',
        string $sucursal = '',
        bool $isAdmin = false,
        string $categoriaId = '',
        string $proveedor = '',
        string $ubicacion = '',
        string $estadoStock = ''
    ): array {
        $params = [];

        $ubicacion = strtoupper(trim($ubicacion));

        if ($isAdmin) {
            $sql = "SELECT 
                        p.id,
                        p.codigo,
                        p.codigo_barras,
                        p.descripcion,
                        p.laboratorio,
                        p.unidad_medida,
                        p.precio_compra,
                        COALESCE(p.costo_ultimo, p.precio_compra, 0) AS costo_ultimo,
                        COALESCE(p.costo_promedio, p.costo_ultimo, p.precio_compra, 0) AS costo_promedio,
                        p.precio_venta,
                        p.stock_minimo,
                        p.stock_maximo,
                        p.ubicacion,
                        p.estado,
                        c.nombre AS categoria,
                        pr.nombre AS proveedor,

                        COALESCE(SUM(
                            CASE 
                                WHEN UPPER(COALESCE(pe.sucursal, '')) COLLATE utf8mb4_general_ci IN ('CIUDAD HIDALGO', 'CD HIDALGO')
                                AND COALESCE(pe.existencia, 0) > 0
                                AND pe.ubicacion IS NOT NULL
                                AND TRIM(pe.ubicacion) != ''
                                AND UPPER(TRIM(pe.ubicacion)) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION', 'SIN UBICACIÓN')
                                THEN pe.existencia 
                                ELSE 0 
                            END
                        ), 0) AS existencia_hidalgo,

                        COALESCE(SUM(
                            CASE 
                                WHEN UPPER(COALESCE(pe.sucursal, '')) COLLATE utf8mb4_general_ci IN ('TUXTLA', 'TUXTLA GUTIERREZ', 'TUXTLA GUTIÉRREZ')
                                AND COALESCE(pe.existencia, 0) > 0
                                AND pe.ubicacion IS NOT NULL
                                AND TRIM(pe.ubicacion) != ''
                                AND UPPER(TRIM(pe.ubicacion)) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION', 'SIN UBICACIÓN')
                                THEN pe.existencia 
                                ELSE 0 
                            END
                        ), 0) AS existencia_tuxtla,

                        COALESCE(SUM(
                            CASE
                                WHEN pe.sucursal IS NOT NULL
                                AND TRIM(pe.sucursal) != ''
                                AND COALESCE(pe.existencia, 0) > 0
                                AND pe.ubicacion IS NOT NULL
                                AND TRIM(pe.ubicacion) != ''
                                AND UPPER(TRIM(pe.ubicacion)) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION', 'SIN UBICACIÓN')
                                THEN pe.existencia
                                ELSE 0
                            END
                        ), 0) AS existencia_total

                    FROM productos p
                    LEFT JOIN categorias c ON p.categoria_id = c.id
                    -- Mismo criterio que Existencias: proveedor de la última entrada
                    -- comercial no cancelada; si no existe, proveedor del alta.
                    LEFT JOIN proveedores pr ON pr.id = COALESCE(
                        (
                            SELECT me.proveedor_id
                            FROM movimiento_detalle de
                            INNER JOIN movimientos me ON me.id = de.movimiento_id
                            WHERE de.producto_id = p.id
                              AND me.tipo_movimiento = 'ENTRADA'
                              AND COALESCE(me.cancelado, 0) = 0
                              AND me.proveedor_id IS NOT NULL
                            ORDER BY me.fecha DESC, me.id DESC
                            LIMIT 1
                        ),
                        p.proveedor_id
                    )
                    LEFT JOIN producto_existencias pe ON pe.producto_id = p.id
                    WHERE p.estado = 1";
        } else {
            $sql = "SELECT 
                        p.id,
                        p.codigo,
                        p.codigo_barras,
                        p.descripcion,
                        p.laboratorio,
                        p.unidad_medida,
                        p.precio_compra,
                        COALESCE(p.costo_ultimo, p.precio_compra, 0) AS costo_ultimo,
                        COALESCE(p.costo_promedio, p.costo_ultimo, p.precio_compra, 0) AS costo_promedio,
                        p.precio_venta,
                        p.stock_minimo,
                        p.stock_maximo,
                        p.ubicacion,
                        p.estado,
                        c.nombre AS categoria,
                        pr.nombre AS proveedor,

                        COALESCE(SUM(
                            CASE
                                WHEN UPPER(COALESCE(pe.sucursal, '')) COLLATE utf8mb4_general_ci = UPPER(:sucursal)
                                AND COALESCE(pe.existencia, 0) > 0
                                AND pe.ubicacion IS NOT NULL
                                AND TRIM(pe.ubicacion) != ''
                                AND UPPER(TRIM(pe.ubicacion)) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION', 'SIN UBICACIÓN')
                                THEN pe.existencia
                                ELSE 0
                            END
                        ), 0) AS existencia,

                        COALESCE(SUM(
                            CASE
                                WHEN UPPER(COALESCE(pe.sucursal, '')) COLLATE utf8mb4_general_ci = UPPER(:sucursal_total)
                                AND COALESCE(pe.existencia, 0) > 0
                                AND pe.ubicacion IS NOT NULL
                                AND TRIM(pe.ubicacion) != ''
                                AND UPPER(TRIM(pe.ubicacion)) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION', 'SIN UBICACIÓN')
                                THEN pe.existencia
                                ELSE 0
                            END
                        ), 0) AS existencia_total

                    FROM productos p
                    LEFT JOIN categorias c ON p.categoria_id = c.id
                    -- Mismo criterio que Existencias: proveedor de la última entrada
                    -- comercial no cancelada; si no existe, proveedor del alta.
                    LEFT JOIN proveedores pr ON pr.id = COALESCE(
                        (
                            SELECT me.proveedor_id
                            FROM movimiento_detalle de
                            INNER JOIN movimientos me ON me.id = de.movimiento_id
                            WHERE de.producto_id = p.id
                              AND me.tipo_movimiento = 'ENTRADA'
                              AND COALESCE(me.cancelado, 0) = 0
                              AND me.proveedor_id IS NOT NULL
                            ORDER BY me.fecha DESC, me.id DESC
                            LIMIT 1
                        ),
                        p.proveedor_id
                    )
                    LEFT JOIN producto_existencias pe ON pe.producto_id = p.id
                    WHERE p.estado = 1";

            $params[':sucursal'] = $sucursal;
            $params[':sucursal_total'] = $sucursal;
        }

        if ($search !== '') {
            $sql .= " AND (
                        p.codigo LIKE :search_codigo
                        OR p.codigo_barras LIKE :search_barras
                        OR p.descripcion LIKE :search_descripcion
                        OR pr.nombre LIKE :search_proveedor
                        OR p.laboratorio LIKE :search_laboratorio
                        OR p.ubicacion LIKE :search_ubicacion_producto
                        OR pe.ubicacion LIKE :search_ubicacion_existencia
                    )";

            $valorSearch = "%{$search}%";

            $params[':search_codigo'] = $valorSearch;
            $params[':search_barras'] = $valorSearch;
            $params[':search_descripcion'] = $valorSearch;
            $params[':search_proveedor'] = $valorSearch;
            $params[':search_laboratorio'] = $valorSearch;
            $params[':search_ubicacion_producto'] = $valorSearch;
            $params[':search_ubicacion_existencia'] = $valorSearch;
        }

        if ($categoriaId !== '') {
            $sql .= " AND p.categoria_id = :categoria_id";
            $params[':categoria_id'] = (int)$categoriaId;
        }

        if ($ubicacion !== '') {
            if (preg_match('/^R[1-9]$/', $ubicacion)) {
                $sql .= " AND (
                            UPPER(COALESCE(p.ubicacion, '')) LIKE :rack_producto
                            OR UPPER(COALESCE(pe.ubicacion, '')) LIKE :rack_existencia
                        )";

                $valorRack = $ubicacion . 'N%';

                $params[':rack_producto'] = $valorRack;
                $params[':rack_existencia'] = $valorRack;
            } else {
                $sql .= " AND (
                            UPPER(COALESCE(p.ubicacion, '')) LIKE :ubicacion_producto
                            OR UPPER(COALESCE(pe.ubicacion, '')) LIKE :ubicacion_existencia
                        )";

                $valorUbicacion = "%{$ubicacion}%";

                $params[':ubicacion_producto'] = $valorUbicacion;
                $params[':ubicacion_existencia'] = $valorUbicacion;
            }
        }

        /*
         * El filtro "Agotados" necesita consultar también existencias en
         * cero. Antes esta parte exigía existencia > 0 y el HAVING también,
         * por lo que ese filtro nunca podía devolver resultados.
         */
        $esFiltroAgotado = $estadoStock === 'agotado';

        if ($isAdmin) {
            $sql .= " AND EXISTS (
                        SELECT 1
                        FROM producto_existencias pe2
                        WHERE pe2.producto_id = p.id
                        AND pe2.sucursal IS NOT NULL
                        AND TRIM(pe2.sucursal) != ''";

            if (!$esFiltroAgotado) {
                $sql .= " AND COALESCE(pe2.existencia, 0) > 0
                          AND pe2.ubicacion IS NOT NULL
                          AND TRIM(pe2.ubicacion) != ''
                          AND UPPER(TRIM(pe2.ubicacion)) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION', 'SIN UBICACIÓN')";
            }

            $sql .= ")";
        } else {
            $sql .= " AND EXISTS (
                        SELECT 1
                        FROM producto_existencias pe2
                        WHERE pe2.producto_id = p.id
                        AND UPPER(COALESCE(pe2.sucursal, '')) COLLATE utf8mb4_general_ci = UPPER(:sucursal_existencia)";

            if (!$esFiltroAgotado) {
                $sql .= " AND COALESCE(pe2.existencia, 0) > 0
                          AND pe2.ubicacion IS NOT NULL
                          AND TRIM(pe2.ubicacion) != ''
                          AND UPPER(TRIM(pe2.ubicacion)) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION', 'SIN UBICACIÓN')";
            }

            $sql .= ")";
            $params[':sucursal_existencia'] = $sucursal;
        }

        $sql .= " GROUP BY 
                    p.id,
                    p.codigo,
                    p.codigo_barras,
                    p.descripcion,
                    p.laboratorio,
                    p.unidad_medida,
                    p.precio_compra,
                    p.costo_ultimo,
                    p.costo_promedio,
                    p.precio_venta,
                    p.stock_minimo,
                    p.stock_maximo,
                    p.ubicacion,
                    p.estado,
                    c.nombre,
                    pr.nombre
                  HAVING existencia_total " . ($esFiltroAgotado ? '<= 0' : '> 0') . "
                  ORDER BY p.descripcion ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($productos as &$producto) {
            $productoId = (int)$producto['id'];

            if ($isAdmin) {
                $sqlUbi = "SELECT 
                sucursal,
                COALESCE(ubicacion, 'SIN UBICACION') AS ubicacion,
                existencia AS existencia_actual
           FROM producto_existencias
           WHERE producto_id = :producto_id
           AND sucursal IS NOT NULL
           AND TRIM(sucursal) != ''
           AND COALESCE(existencia, 0) >= 0  -- Traer TODAS (incluyo 0)
           AND ubicacion IS NOT NULL
           AND TRIM(ubicacion) != ''
           AND UPPER(TRIM(ubicacion)) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION', 'SIN UBICACIÓN')
           ORDER BY ubicacion ASC  -- <-- SOLO ORDEN FÍSICO, NO POR EXISTENCIA
           ";

                $stmtUbi = $this->conn->prepare($sqlUbi);
                $stmtUbi->execute([
                    ':producto_id' => $productoId
                ]);
            } else {
                $sqlUbi = "SELECT 
                sucursal,
                COALESCE(ubicacion, 'SIN UBICACION') AS ubicacion,
                existencia AS existencia_actual
           FROM producto_existencias
           WHERE producto_id = :producto_id
           AND UPPER(COALESCE(sucursal, '')) COLLATE utf8mb4_general_ci = UPPER(:sucursal)
           AND COALESCE(existencia, 0) >= 0  -- Traer TODAS (incluyo 0)
           AND ubicacion IS NOT NULL
           AND TRIM(ubicacion) != ''
           AND UPPER(TRIM(ubicacion)) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION', 'SIN UBICACIÓN')
           ORDER BY ubicacion ASC  -- <-- SOLO ORDEN FÍSICO
           ";

                $stmtUbi = $this->conn->prepare($sqlUbi);
                $stmtUbi->execute([
                    ':producto_id' => $productoId,
                    ':sucursal' => $sucursal
                ]);
            }

            $producto['ubicaciones'] = $stmtUbi->fetchAll(PDO::FETCH_ASSOC);

            $existencia = $isAdmin
                ? (int)($producto['existencia_total'] ?? 0)
                : (int)($producto['existencia'] ?? 0);

            $producto['estado_stock'] = $this->calcularEstadoStock($existencia);
            $producto['estado_stock_texto'] = match ($producto['estado_stock']) {
                'agotado' => 'AGOTADO',
                'bajo' => 'BAJO STOCK',
                default => 'NORMAL',
            };
        }

        unset($producto);

        if ($estadoStock !== '') {
            $productos = array_values(array_filter($productos, function ($producto) use ($estadoStock) {
                return ($producto['estado_stock'] ?? '') === $estadoStock;
            }));
        }

        return $productos;
    }

    public function countAll(string $search = ''): int
    {
        $sql = "SELECT COUNT(*) AS total
                FROM (
                    SELECT p.id
                    FROM productos p
                    INNER JOIN producto_existencias pe
                        ON pe.producto_id = p.id
                    WHERE p.estado = 1
                    AND pe.sucursal IS NOT NULL
                    AND TRIM(pe.sucursal) != ''
                    AND COALESCE(pe.existencia, 0) > 0
                    AND pe.ubicacion IS NOT NULL
                    AND TRIM(pe.ubicacion) != ''
                    AND UPPER(TRIM(pe.ubicacion)) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION', 'SIN UBICACIÓN')";

        $params = [];

        if ($search !== '') {
            $sql .= " AND (
                        p.codigo LIKE :count_codigo
                        OR p.codigo_barras LIKE :count_barras
                        OR p.descripcion LIKE :count_descripcion
                    )";

            $valorSearch = "%{$search}%";

            $params[':count_codigo'] = $valorSearch;
            $params[':count_barras'] = $valorSearch;
            $params[':count_descripcion'] = $valorSearch;
        }

        $sql .= " GROUP BY p.id
                ) AS t";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }

    public function findById(int $id): ?array
    {
        $sql = "SELECT 
                    p.*,
                    pr.nombre AS proveedor_nombre
                FROM productos p
                LEFT JOIN proveedores pr
                    ON p.proveedor_id = pr.id
                WHERE p.id = :id
                LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':id' => $id
        ]);

        $producto = $stmt->fetch(PDO::FETCH_ASSOC);

        return $producto ?: null;
    }

    public function findByCodigo(string $codigo): ?array
    {
        $sql = "SELECT *
                FROM productos
                WHERE codigo = :codigo_producto
                   OR codigo_barras = :codigo_barras
                LIMIT 1";

        $stmt = $this->conn->prepare($sql);

        $stmt->execute([
            ':codigo_producto' => $codigo,
            ':codigo_barras' => $codigo
        ]);

        $producto = $stmt->fetch(PDO::FETCH_ASSOC);

        return $producto ?: null;
    }

    /**
     * Buscar únicamente por el código principal.
     *
     * Incluye productos inactivos para poder reactivar el mismo registro
     * sin duplicar su código ni perder la relación con sus movimientos.
     */
    public function findByCodigoExacto(string $codigo): ?array
    {
        $sql = "SELECT *
                FROM productos
                WHERE codigo = :codigo
                ORDER BY estado DESC, id ASC
                LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':codigo' => trim($codigo)
        ]);

        $producto = $stmt->fetch(PDO::FETCH_ASSOC);

        return $producto ?: null;
    }

    public function existsByCodigo(string $codigo, ?int $excludeId = null): bool
    {
        $sql = "SELECT id
                FROM productos
                WHERE codigo = :codigo";

        $params = [
            ':codigo' => $codigo
        ];

        if ($excludeId !== null) {
            $sql .= " AND id != :id";
            $params[':id'] = $excludeId;
        }

        $sql .= " LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }


    private function existenciaTotalProducto(int $productoId): int
    {
        $sql = "SELECT COALESCE(SUM(COALESCE(existencia, 0)), 0)
                FROM producto_existencias
                WHERE producto_id = :producto_id";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':producto_id' => $productoId
        ]);

        return max(0, (int)$stmt->fetchColumn());
    }

    public function create(array $data): bool
    {
        $proveedorId = $this->obtenerOCrearProveedor($data['proveedor_nombre'] ?? '');
        $ubicacion = $this->limpiarUbicacion($data['ubicacion'] ?? '');

        if ($ubicacion === 'SIN UBICACION') {
            $ubicacion = null;
        }

        $sql = "INSERT INTO productos (
                    codigo,
                    codigo_barras,
                    descripcion,
                    categoria_id,
                    proveedor_id,
                    laboratorio,
                    unidad_medida,
                    precio_compra,
                    costo_ultimo,
                    costo_promedio,
                    precio_venta,
                    stock_minimo,
                    stock_maximo,
                    ubicacion,
                    existencia_bodega,
                    sucursal,
                    estado
                ) VALUES (
                    :codigo,
                    :codigo_barras,
                    :descripcion,
                    :categoria_id,
                    :proveedor_id,
                    :laboratorio,
                    :unidad_medida,
                    :precio_compra,
                    :costo_ultimo,
                    :costo_promedio,
                    :precio_venta,
                    0,
                    0,
                    :ubicacion,
                    0,
                    NULL,
                    1
                )";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':codigo' => $data['codigo'],
            ':codigo_barras' => $data['codigo_barras'] ?: $data['codigo'],
            ':descripcion' => $data['descripcion'],
            ':categoria_id' => $data['categoria_id'] ?: null,
            ':proveedor_id' => $proveedorId,
            ':laboratorio' => $data['laboratorio'] ?: null,
            ':unidad_medida' => $data['unidad_medida'] ?: null,
            ':precio_compra' => $data['precio_compra'] ?? 0,
            ':costo_ultimo' => $data['precio_compra'] ?? 0,
            ':costo_promedio' => $data['precio_compra'] ?? 0,
            ':precio_venta' => $data['precio_venta'] ?? 0,
            ':ubicacion' => $ubicacion
        ]);
    }

    public function update(int $id, array $data): bool
    {
        $proveedorId = $this->obtenerOCrearProveedor($data['proveedor_nombre'] ?? '');
        $ubicacion = $this->limpiarUbicacion($data['ubicacion'] ?? '');

        if ($ubicacion === 'SIN UBICACION') {
            $ubicacion = null;
        }

        $productoActual = $this->findById($id);
        $costoUltimo = (float)($data['precio_compra'] ?? 0);
        $costoPromedio = $this->existenciaTotalProducto($id) > 0
            ? (float)($productoActual['costo_promedio']
                ?? $productoActual['costo_ultimo']
                ?? $productoActual['precio_compra']
                ?? $costoUltimo)
            : $costoUltimo;

        $sql = "UPDATE productos SET
                    codigo = :codigo,
                    codigo_barras = :codigo_barras,
                    descripcion = :descripcion,
                    categoria_id = :categoria_id,
                    proveedor_id = :proveedor_id,
                    laboratorio = :laboratorio,
                    unidad_medida = :unidad_medida,
                    precio_compra = :precio_compra,
                    costo_ultimo = :costo_ultimo,
                    costo_promedio = :costo_promedio,
                    precio_venta = :precio_venta,
                    stock_minimo = 0,
                    stock_maximo = 0,
                    ubicacion = :ubicacion
                WHERE id = :id";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':codigo' => $data['codigo'],
            ':codigo_barras' => $data['codigo_barras'] ?: $data['codigo'],
            ':descripcion' => $data['descripcion'],
            ':categoria_id' => $data['categoria_id'] ?: null,
            ':proveedor_id' => $proveedorId,
            ':laboratorio' => $data['laboratorio'] ?: null,
            ':unidad_medida' => $data['unidad_medida'] ?: null,
            ':precio_compra' => $costoUltimo,
            ':costo_ultimo' => $costoUltimo,
            ':costo_promedio' => $costoPromedio,
            ':precio_venta' => $data['precio_venta'] ?? 0,
            ':ubicacion' => $ubicacion,
            ':id' => $id
        ]);
    }

    /**
     * Reactivar un producto dado de baja y actualizar sus datos de catálogo.
     *
     * Se conserva el mismo ID para no romper entradas, salidas, devoluciones
     * ni cualquier otro movimiento histórico vinculado con el producto.
     */
    public function reactivate(int $id, array $data): bool
    {
        $proveedorId = $this->obtenerOCrearProveedor(
            $data['proveedor_nombre'] ?? ''
        );
        $ubicacion = $this->limpiarUbicacion(
            $data['ubicacion'] ?? ''
        );

        if ($ubicacion === 'SIN UBICACION') {
            $ubicacion = null;
        }

        $productoActual = $this->findById($id);
        $costoUltimo = (float)($data['precio_compra'] ?? 0);
        $costoPromedio = $this->existenciaTotalProducto($id) > 0
            ? (float)($productoActual['costo_promedio']
                ?? $productoActual['costo_ultimo']
                ?? $productoActual['precio_compra']
                ?? $costoUltimo)
            : $costoUltimo;

        $sql = "UPDATE productos SET
                    codigo = :codigo,
                    codigo_barras = :codigo_barras,
                    descripcion = :descripcion,
                    categoria_id = :categoria_id,
                    proveedor_id = :proveedor_id,
                    laboratorio = :laboratorio,
                    unidad_medida = :unidad_medida,
                    precio_compra = :precio_compra,
                    costo_ultimo = :costo_ultimo,
                    costo_promedio = :costo_promedio,
                    precio_venta = :precio_venta,
                    stock_minimo = 0,
                    stock_maximo = 0,
                    ubicacion = :ubicacion,
                    estado = 1
                WHERE id = :id
                  AND estado = 0";

        $stmt = $this->conn->prepare($sql);

        $ok = $stmt->execute([
            ':codigo' => $data['codigo'],
            ':codigo_barras' =>
                $data['codigo_barras'] ?: $data['codigo'],
            ':descripcion' => $data['descripcion'],
            ':categoria_id' =>
                $data['categoria_id'] ?: null,
            ':proveedor_id' => $proveedorId,
            ':laboratorio' =>
                $data['laboratorio'] ?: null,
            ':unidad_medida' =>
                $data['unidad_medida'] ?: null,
            ':precio_compra' => $costoUltimo,
            ':costo_ultimo' => $costoUltimo,
            ':costo_promedio' => $costoPromedio,
            ':precio_venta' =>
                $data['precio_venta'] ?? 0,
            ':ubicacion' => $ubicacion,
            ':id' => $id
        ]);

        return $ok && $stmt->rowCount() > 0;
    }

    public function crearOActualizarCatalogo(array $data): bool
    {
        $codigo = trim($data['codigo']);

        $producto = $this->findByCodigo($codigo);

        $payload = [
            'codigo' => $codigo,
            'codigo_barras' => $data['codigo_barras'] ?: $codigo,
            'descripcion' => $data['descripcion'],
            'categoria_id' => $data['categoria_id'] ?? null,
            'proveedor_nombre' => $data['proveedor_nombre'] ?? '',
            'laboratorio' => $data['laboratorio'] ?? null,
            'unidad_medida' => $data['unidad_medida'] ?? null,
            'precio_compra' => $data['precio_compra'] ?? 0,
            'precio_venta' => $data['precio_venta'] ?? 0,
            'stock_minimo' => 0,
            'stock_maximo' => 0,
            'ubicacion' => $data['ubicacion'] ?? null
        ];

        if (
            $producto
            && (int) ($producto['estado'] ?? 1) === 0
        ) {
            return $this->reactivate(
                (int) $producto['id'],
                $payload
            );
        }

        if ($producto) {
            return $this->update((int)$producto['id'], $payload);
        }

        return $this->create($payload);
    }

    /**
     * OBTENER una ubicación específica
     */
    public function getUbicacionExistencia(
        int $productoId,
        string $sucursal,
        string $ubicacion
    ): ?array {
        $sucursal = $this->limpiarSucursal($sucursal);
        $ubicacion = $this->limpiarUbicacion($ubicacion);

        $sql = "SELECT id, producto_id, sucursal, ubicacion, existencia
                FROM producto_existencias
                WHERE producto_id = :producto_id
                AND UPPER(COALESCE(sucursal, '')) COLLATE utf8mb4_general_ci = UPPER(:sucursal)
                AND UPPER(COALESCE(ubicacion, '')) COLLATE utf8mb4_general_ci = UPPER(:ubicacion)";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':producto_id' => $productoId,
            ':sucursal' => $sucursal,
            ':ubicacion' => $ubicacion
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function crearUbicacionExistencia(
        int $productoId,
        string $sucursal,
        string $ubicacion,
        int $existencia
    ): bool {
        return $this->actualizarUbicacionExistencia(
            $productoId,
            $sucursal,
            $ubicacion,
            $ubicacion,
            max(0, $existencia)
        );
    }

    /**
     * ACTUALIZAR existencia de una ubicación (permite poner en 0 sin eliminar)
     */
    public function actualizarUbicacionExistencia(
        int $productoId,
        string $sucursal,
        string $ubicacionAnterior,
        string $ubicacionNueva,
        int $existencia
    ): bool {
        $sucursal = $this->limpiarSucursal($sucursal);
        $ubicacionAnterior = $this->limpiarUbicacion($ubicacionAnterior);
        $ubicacionNueva = $this->limpiarUbicacion($ubicacionNueva);

        if ($productoId <= 0 || $sucursal === '') {
            return false;
        }

        try {
            $this->conn->beginTransaction();

            // Verificar si ya existe la ubicación nueva (diferente a la anterior)
            if ($ubicacionAnterior !== $ubicacionNueva) {
                $sqlExiste = "SELECT id
                              FROM producto_existencias
                              WHERE producto_id = :producto_id
                              AND UPPER(COALESCE(sucursal, '')) COLLATE utf8mb4_general_ci = UPPER(:sucursal)
                              AND UPPER(COALESCE(ubicacion, '')) COLLATE utf8mb4_general_ci = UPPER(:ubicacion)
                              LIMIT 1";

                $stmtExiste = $this->conn->prepare($sqlExiste);
                $stmtExiste->execute([
                    ':producto_id' => $productoId,
                    ':sucursal' => $sucursal,
                    ':ubicacion' => $ubicacionNueva
                ]);

                $existeNueva = $stmtExiste->fetch(PDO::FETCH_ASSOC);

                if ($existeNueva) {
                    // Si ya existe la nueva ubicación, sumar existencias
                    $sqlSumar = "UPDATE producto_existencias
                                 SET existencia = existencia + :existencia,
                                     updated_at = CURRENT_TIMESTAMP
                                 WHERE id = :id";

                    $stmtSumar = $this->conn->prepare($sqlSumar);
                    $stmtSumar->execute([
                        ':existencia' => $existencia,
                        ':id' => $existeNueva['id']
                    ]);

                    // Eliminar o vaciar la ubicación anterior
                    $this->marcarSinExistencia($productoId, $sucursal, $ubicacionAnterior);
                } else {
                    // Actualizar la ubicación existente (cambiar nombre)
                    $sqlUpdate = "UPDATE producto_existencias
                                  SET ubicacion = :ubicacion_nueva,
                                      existencia = :existencia,
                                      updated_at = CURRENT_TIMESTAMP
                                  WHERE producto_id = :producto_id
                                  AND UPPER(COALESCE(sucursal, '')) COLLATE utf8mb4_general_ci = UPPER(:sucursal)
                                  AND UPPER(COALESCE(ubicacion, '')) COLLATE utf8mb4_general_ci = UPPER(:ubicacion_anterior)";

                    $stmtUpdate = $this->conn->prepare($sqlUpdate);
                    $stmtUpdate->execute([
                        ':ubicacion_nueva' => $ubicacionNueva,
                        ':existencia' => $existencia,
                        ':producto_id' => $productoId,
                        ':sucursal' => $sucursal,
                        ':ubicacion_anterior' => $ubicacionAnterior
                    ]);

                    if ($stmtUpdate->rowCount() === 0) {
                        // No existía, crear nueva
                        $sqlInsert = "INSERT INTO producto_existencias (
                                        producto_id,
                                        sucursal,
                                        ubicacion,
                                        existencia
                                    ) VALUES (
                                        :producto_id,
                                        :sucursal,
                                        :ubicacion,
                                        :existencia
                                    )";

                        $stmtInsert = $this->conn->prepare($sqlInsert);
                        $stmtInsert->execute([
                            ':producto_id' => $productoId,
                            ':sucursal' => $sucursal,
                            ':ubicacion' => $ubicacionNueva,
                            ':existencia' => $existencia
                        ]);
                    }
                }
            } else {
                // Misma ubicación, solo actualizar existencia
                $sqlUpdate = "UPDATE producto_existencias
                              SET existencia = :existencia,
                                  updated_at = CURRENT_TIMESTAMP
                              WHERE producto_id = :producto_id
                              AND UPPER(COALESCE(sucursal, '')) COLLATE utf8mb4_general_ci = UPPER(:sucursal)
                              AND UPPER(COALESCE(ubicacion, '')) COLLATE utf8mb4_general_ci = UPPER(:ubicacion)";

                $stmtUpdate = $this->conn->prepare($sqlUpdate);
                $stmtUpdate->execute([
                    ':existencia' => $existencia,
                    ':producto_id' => $productoId,
                    ':sucursal' => $sucursal,
                    ':ubicacion' => $ubicacionNueva
                ]);

                if ($stmtUpdate->rowCount() === 0 && $existencia > 0) {
                    // No existía, crear nueva
                    $sqlInsert = "INSERT INTO producto_existencias (
                                    producto_id,
                                    sucursal,
                                    ubicacion,
                                    existencia
                                ) VALUES (
                                    :producto_id,
                                    :sucursal,
                                    :ubicacion,
                                    :existencia
                                )";

                    $stmtInsert = $this->conn->prepare($sqlInsert);
                    $stmtInsert->execute([
                        ':producto_id' => $productoId,
                        ':sucursal' => $sucursal,
                        ':ubicacion' => $ubicacionNueva,
                        ':existencia' => $existencia
                    ]);
                }
            }

            // Actualizar ubicación principal del producto si es la primera
            $this->actualizarUbicacionPrincipal($productoId, $sucursal);

            $this->conn->commit();
            return true;

        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("Error en actualizarUbicacionExistencia: " . $e->getMessage());
            return false;
        }
    }

    /**
     * MARCAR una ubicación SIN EXISTENCIA (poner en 0, no eliminar)
     */
    private function marcarSinExistencia(int $productoId, string $sucursal, string $ubicacion): bool
    {
        $sql = "UPDATE producto_existencias
                SET existencia = 0,
                    updated_at = CURRENT_TIMESTAMP
                WHERE producto_id = :producto_id
                AND UPPER(COALESCE(sucursal, '')) COLLATE utf8mb4_general_ci = UPPER(:sucursal)
                AND UPPER(COALESCE(ubicacion, '')) COLLATE utf8mb4_general_ci = UPPER(:ubicacion)";

        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([
            ':producto_id' => $productoId,
            ':sucursal' => $sucursal,
            ':ubicacion' => $ubicacion
        ]);
    }

    /**
     * ACTUALIZAR ubicación principal del producto (la que tiene más stock)
     */
    private function actualizarUbicacionPrincipal(int $productoId, string $sucursal): void
    {
        $sql = "SELECT ubicacion
                FROM producto_existencias
                WHERE producto_id = :producto_id
                AND UPPER(COALESCE(sucursal, '')) COLLATE utf8mb4_general_ci = UPPER(:sucursal)
                AND existencia > 0
                ORDER BY existencia DESC, ubicacion ASC
                LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':producto_id' => $productoId,
            ':sucursal' => $sucursal
        ]);

        $ubicacion = $stmt->fetchColumn();

        $sqlUpdate = "UPDATE productos SET ubicacion = :ubicacion WHERE id = :id";
        $stmtUpdate = $this->conn->prepare($sqlUpdate);
        $stmtUpdate->execute([
            ':ubicacion' => $ubicacion ?: null,
            ':id' => $productoId
        ]);
    }

    /**
     * ACTUALIZAR existencia por código (para importaciones)
     */
    public function actualizarExistenciaPorCodigo(
        string $codigo,
        string $sucursal,
        int $existencia,
        string $ubicacion = 'SIN UBICACION'
    ): bool {
        $producto = $this->findByCodigo($codigo);

        if (!$producto) {
            return false;
        }

        $productoId = (int)$producto['id'];
        $sucursal = $this->limpiarSucursal($sucursal);
        $ubicacion = $this->limpiarUbicacion($ubicacion);

        return $this->actualizarUbicacionExistencia(
            $productoId,
            $sucursal,
            $ubicacion,
            $ubicacion,
            $existencia
        );
    }

    /**
     * ELIMINAR realmente una ubicación (solo si existencia = 0)
     */
    public function eliminarUbicacionExistenciaReal(
        int $productoId,
        string $sucursal,
        string $ubicacion
    ): bool {
        $sucursal = $this->limpiarSucursal($sucursal);
        $ubicacion = $this->limpiarUbicacion($ubicacion);

        if ($productoId <= 0 || $sucursal === '') {
            return false;
        }

        try {
            // Verificar que la existencia sea 0
            $sqlVerificar = "SELECT existencia
                            FROM producto_existencias
                            WHERE producto_id = :producto_id
                            AND UPPER(COALESCE(sucursal, '')) COLLATE utf8mb4_general_ci = UPPER(:sucursal)
                            AND UPPER(COALESCE(ubicacion, '')) COLLATE utf8mb4_general_ci = UPPER(:ubicacion)";

            $stmtVerificar = $this->conn->prepare($sqlVerificar);
            $stmtVerificar->execute([
                ':producto_id' => $productoId,
                ':sucursal' => $sucursal,
                ':ubicacion' => $ubicacion
            ]);

            $existencia = $stmtVerificar->fetchColumn();

            if ($existencia === false) {
                // No existe, no hay nada que eliminar
                return true;
            }

            if ((int)$existencia > 0) {
                // No se puede eliminar si tiene stock
                return false;
            }

            // Eliminar el registro
            $sqlDelete = "DELETE FROM producto_existencias
                          WHERE producto_id = :producto_id
                          AND UPPER(COALESCE(sucursal, '')) COLLATE utf8mb4_general_ci = UPPER(:sucursal)
                          AND UPPER(COALESCE(ubicacion, '')) COLLATE utf8mb4_general_ci = UPPER(:ubicacion)";

            $stmtDelete = $this->conn->prepare($sqlDelete);
            $stmtDelete->execute([
                ':producto_id' => $productoId,
                ':sucursal' => $sucursal,
                ':ubicacion' => $ubicacion
            ]);

            // Actualizar ubicación principal
            $this->actualizarUbicacionPrincipal($productoId, $sucursal);

            return true;

        } catch (Throwable $e) {
            error_log("Error al eliminar ubicación: " . $e->getMessage());
            return false;
        }
    }

    /**
     * MARCAR sin existencia (versión simplificada para compatibilidad)
     */
    private function marcarSinExistenciaConSucursal(int $productoId, string $sucursal): bool
    {
        $sucursal = $this->limpiarSucursal($sucursal);

        $sql = "UPDATE producto_existencias
                SET existencia = 0,
                    updated_at = CURRENT_TIMESTAMP
                WHERE producto_id = :producto_id
                AND UPPER(COALESCE(sucursal, '')) COLLATE utf8mb4_general_ci = UPPER(:sucursal)";

        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([
            ':producto_id' => $productoId,
            ':sucursal' => $sucursal
        ]);
    }

    public function deleteLogical(int $id): bool
    {
        $sql = "UPDATE productos 
                SET estado = 0 
                WHERE id = :id";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':id' => $id
        ]);
    }

    /**
     * ELIMINAR ubicación (alias para mantener compatibilidad)
     */
    public function eliminarUbicacionExistencia(
        int $productoId,
        string $sucursal,
        string $ubicacion
    ): bool {
        return $this->eliminarUbicacionExistenciaReal($productoId, $sucursal, $ubicacion);
    }

    public function getExistencias(
        string $buscar = '',
        int $almacenId = 0,
        string $estadoStock = '',
        string $sucursal = '',
        bool $isAdmin = false
    ): array {
        return $this->getAll(
            $buscar,
            $sucursal,
            $isAdmin,
            '',
            '',
            '',
            $estadoStock
        );
    }
}
