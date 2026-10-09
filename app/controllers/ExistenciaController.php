<?php

require_once __DIR__ . '/../config/database.php';

class ExistenciaController
{
    private PDO $conn;
    private int $limiteBajoStock = 120;

    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $database = new Database();
        $this->conn = $database->connect();
    }

    private function esAdministrador(): bool
    {
        $rol = strtoupper(trim($_SESSION['user']['rol'] ?? ''));
        return in_array($rol, ['ADMINISTRADOR', 'ADMIN'], true);
    }

    private function almacenSesionId(): int
    {
        return (int)($_SESSION['user']['almacen_id'] ?? 0);
    }

    private function sucursalesPorAlmacenId(int $almacenId): array
    {
        if ($almacenId === 1) {
            return ['CIUDAD HIDALGO', 'CD HIDALGO'];
        }

        if ($almacenId === 2 || $almacenId === 3) {
            return ['TUXTLA', 'TUXTLA GUTIERREZ', 'TUXTLA GUTIÉRREZ'];
        }

        return [];
    }

    public function almacenes(): array
    {
        return $this->conn
            ->query("SELECT id, nombre FROM almacenes WHERE estado = 1 ORDER BY nombre ASC")
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    public function categorias(): array
    {
        return $this->conn
            ->query("SELECT id, nombre FROM categorias WHERE estado = 1 ORDER BY nombre ASC")
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    public function proveedores(): array
    {
        return $this->conn
            ->query("SELECT id, nombre FROM proveedores WHERE estado = 1 ORDER BY nombre ASC")
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    private function aplicarFiltroSucursal(string &$sql, array &$params, int $almacenId, string $alias = 'pe'): void
    {
        $sucursales = $this->sucursalesPorAlmacenId($almacenId);

        if (empty($sucursales)) {
            return;
        }

        $marks = [];

        foreach ($sucursales as $i => $sucursal) {
            $key = ":sucursal_{$alias}_{$i}";
            $marks[] = $key;
            $params[$key] = $sucursal;
        }

        $sql .= " AND UPPER(COALESCE({$alias}.sucursal, '')) COLLATE utf8mb4_general_ci IN (" . implode(',', $marks) . ") ";
    }

    public function index(array $filtros): array
    {
        $params = [];
        $esAdmin = $this->esAdministrador();

        $camposCostos = $esAdmin
            ? "COALESCE(p.costo_ultimo, p.precio_compra, 0) AS costo_ultimo,
               COALESCE(p.costo_promedio, p.costo_ultimo, p.precio_compra, 0) AS costo_promedio"
            : "NULL AS costo_ultimo,
               NULL AS costo_promedio";

        $almacenId = (int)($filtros['almacen_id'] ?? 0);

        if (!$esAdmin) {
            $almacenId = $this->almacenSesionId();
        }

        $subquery = "SELECT
                        pe.producto_id,

                        SUM(
                            CASE
                                WHEN pe.sucursal IS NOT NULL
                                AND TRIM(pe.sucursal) != ''
                                THEN COALESCE(pe.existencia, 0)
                                ELSE 0
                            END
                        ) AS existencia,

                        SUM(
                            CASE
                                WHEN pe.sucursal IS NOT NULL
                                AND TRIM(pe.sucursal) != ''
                                THEN 1
                                ELSE 0
                            END
                        ) AS filas_con_sucursal,

                        SUM(
                            CASE
                                WHEN pe.sucursal IS NULL
                                OR TRIM(pe.sucursal) = ''
                                THEN 1
                                ELSE 0
                            END
                        ) AS filas_sin_almacen,

                        SUM(
                            CASE
                                WHEN pe.sucursal IS NOT NULL
                                AND TRIM(pe.sucursal) != ''
                                AND COALESCE(pe.existencia, 0) > 0
                                AND pe.ubicacion IS NOT NULL
                                AND TRIM(pe.ubicacion) != ''
                                AND UPPER(TRIM(pe.ubicacion)) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION', 'SIN UBICACIÓN')
                                THEN COALESCE(pe.existencia, 0)
                                ELSE 0
                            END
                        ) AS existencia_con_ubicacion,

                        GROUP_CONCAT(
                            DISTINCT CASE
                                WHEN pe.sucursal IS NOT NULL
                                AND TRIM(pe.sucursal) != ''
                                AND COALESCE(pe.existencia, 0) > 0
                                AND pe.ubicacion IS NOT NULL
                                AND TRIM(pe.ubicacion) != ''
                                AND UPPER(TRIM(pe.ubicacion)) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION', 'SIN UBICACIÓN')
                                THEN pe.ubicacion
                                ELSE NULL
                            END
                            ORDER BY pe.ubicacion ASC
                            SEPARATOR ', '
                        ) AS ubicacion,

                        GROUP_CONCAT(
                            DISTINCT COALESCE(NULLIF(TRIM(pe.sucursal), ''), 'SIN ALMACEN')
                            ORDER BY pe.sucursal ASC
                            SEPARATOR ', '
                        ) AS sucursales,

                        MIN(NULLIF(TRIM(pe.sucursal), '')) AS sucursal

                    FROM producto_existencias pe
                    WHERE 1 = 1";

        if ($almacenId > 0) {
            $this->aplicarFiltroSucursal($subquery, $params, $almacenId, 'pe');
        }

        if (!empty($filtros['rack'])) {
            $subquery .= " AND UPPER(COALESCE(pe.ubicacion, '')) LIKE :rack";
            $params[':rack'] = strtoupper(trim($filtros['rack'])) . '%';
        }

        $subquery .= " GROUP BY pe.producto_id";

        $sql = "SELECT
                    p.id,
                    p.codigo,
                    p.codigo_barras,
                    p.descripcion,
                    c.nombre AS categoria,
                    pr.nombre AS proveedor,
                    p.laboratorio,
                    p.unidad_medida,
                    {$camposCostos},

                    COALESCE(stock.sucursal, 'SIN ALMACEN') AS sucursal,
                    COALESCE(stock.sucursales, 'SIN ALMACEN') AS sucursales,
                    COALESCE(stock.ubicacion, 'SIN UBICACION') AS ubicacion,

                    COALESCE(stock.existencia, 0) AS existencia,
                    COALESCE(stock.existencia_con_ubicacion, 0) AS existencia_con_ubicacion,
                    COALESCE(stock.filas_con_sucursal, 0) AS filas_con_sucursal,
                    COALESCE(stock.filas_sin_almacen, 0) AS filas_sin_almacen,

                    CASE
                        WHEN stock.producto_id IS NULL
                             OR COALESCE(stock.filas_con_sucursal, 0) = 0
                        THEN 'SIN ALMACEN'

                        WHEN COALESCE(stock.existencia, 0) <= 0
                        THEN 'SIN EXISTENCIA'

                        WHEN COALESCE(stock.existencia_con_ubicacion, 0) <= 0
                        THEN 'SIN UBICACION'

                        WHEN COALESCE(stock.existencia_con_ubicacion, 0) <= {$this->limiteBajoStock}
                        THEN 'STOCK BAJO'

                        ELSE 'STOCK NORMAL'
                    END AS estado_stock

                FROM productos p
                LEFT JOIN categorias c ON p.categoria_id = c.id
                -- Proveedor de la última entrada NO cancelada con proveedor para
                -- el producto, considerando todas las sucursales y la fecha real
                -- del movimiento. Si no hay entrada asociada, usar el catálogo.
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
                LEFT JOIN ({$subquery}) stock ON stock.producto_id = p.id
                WHERE p.estado = 1";

        if (!empty($filtros['rack'])) {
            $sql .= " AND stock.producto_id IS NOT NULL";
        }

        if (!empty($filtros['buscar'])) {
    $sql .= " AND (
        p.codigo LIKE :buscar_codigo
        OR p.codigo_barras LIKE :buscar_barras
        OR p.descripcion LIKE :buscar_descripcion
        OR c.nombre LIKE :buscar_categoria
        OR pr.nombre LIKE :buscar_proveedor
        OR p.laboratorio LIKE :buscar_laboratorio
        OR stock.ubicacion LIKE :buscar_ubicacion
        OR stock.sucursales LIKE :buscar_sucursal
    )";

    $valorBuscar = '%' . trim($filtros['buscar']) . '%';

    $params[':buscar_codigo'] = $valorBuscar;
    $params[':buscar_barras'] = $valorBuscar;
    $params[':buscar_descripcion'] = $valorBuscar;
    $params[':buscar_categoria'] = $valorBuscar;
    $params[':buscar_proveedor'] = $valorBuscar;
    $params[':buscar_laboratorio'] = $valorBuscar;
    $params[':buscar_ubicacion'] = $valorBuscar;
    $params[':buscar_sucursal'] = $valorBuscar;
}

        if (!empty($filtros['categoria_id'])) {
            $sql .= " AND p.categoria_id = :categoria_id";
            $params[':categoria_id'] = (int)$filtros['categoria_id'];
        }

        if (!empty($filtros['proveedor_id'])) {
            $sql .= " AND pr.id = :proveedor_id";
            $params[':proveedor_id'] = (int)$filtros['proveedor_id'];
        }

        if (!empty($filtros['estado_stock'])) {
            if ($filtros['estado_stock'] === 'sin_almacen') {
                $sql .= " AND (
                    stock.producto_id IS NULL
                    OR COALESCE(stock.filas_con_sucursal, 0) = 0
                )";
            } elseif ($filtros['estado_stock'] === 'sin_existencia') {
                $sql .= " AND stock.producto_id IS NOT NULL
                          AND COALESCE(stock.filas_con_sucursal, 0) > 0
                          AND COALESCE(stock.existencia, 0) <= 0";
            } elseif ($filtros['estado_stock'] === 'bajo') {
                $sql .= " AND COALESCE(stock.existencia_con_ubicacion, 0) > 0
                          AND COALESCE(stock.existencia_con_ubicacion, 0) <= {$this->limiteBajoStock}";
            } elseif ($filtros['estado_stock'] === 'normal') {
                $sql .= " AND COALESCE(stock.existencia_con_ubicacion, 0) > {$this->limiteBajoStock}";
            } elseif ($filtros['estado_stock'] === 'stock') {
                $sql .= " AND COALESCE(stock.existencia_con_ubicacion, 0) > 0";
            }
        }

        $orden = $filtros['orden'] ?? 'descripcion';

        $ordenes = [
            'codigo' => 'p.codigo ASC',
            'descripcion' => 'p.descripcion ASC',
            'existencia_mayor' => 'existencia_con_ubicacion DESC',
            'existencia_menor' => 'existencia_con_ubicacion ASC',
            'ubicacion' => 'ubicacion ASC',
        ];

        $sql .= " ORDER BY " . ($ordenes[$orden] ?? 'p.descripcion ASC');

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function valorInventario(int $almacenId = 0): float
    {
        if (!$this->esAdministrador()) {
            return 0.0;
        }

        $params = [];

        $sql = "SELECT
                    COALESCE(
                        SUM(
                            COALESCE(pe.existencia, 0)
                            * COALESCE(
                                p.costo_promedio,
                                p.costo_ultimo,
                                p.precio_compra,
                                0
                            )
                        ),
                        0
                    ) AS valor_inventario
                FROM producto_existencias AS pe
                INNER JOIN productos AS p
                    ON p.id = pe.producto_id
                WHERE p.estado = 1
                  AND pe.sucursal IS NOT NULL
                  AND TRIM(pe.sucursal) != ''";

        if ($almacenId > 0) {
            $this->aplicarFiltroSucursal(
                $sql,
                $params,
                $almacenId,
                'pe'
            );
        }

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        return (float) $stmt->fetchColumn();
    }

    public function resumen(array $productos): array
    {
        $totalProductos = count($productos);
        $totalUnidades = 0;
        $sinExistencia = 0;
        $sinAlmacen = 0;
        $stockBajo = 0;
        $stockNormal = 0;
        $stockCorrecto = 0;
        $valorInventario = 0;

        foreach ($productos as $p) {
            $existencia = (int)($p['existencia'] ?? 0);
            $existenciaConUbicacion = (int)($p['existencia_con_ubicacion'] ?? 0);
            $precio = (float)($p['costo_promedio']
                ?? $p['costo_ultimo']
                ?? 0);
            $estado = strtoupper(trim((string)($p['estado_stock'] ?? '')));

            $totalUnidades += $existenciaConUbicacion;
            $valorInventario += $existenciaConUbicacion * $precio;

            if ($estado === 'SIN ALMACEN') {
                $sinAlmacen++;
            } elseif ($estado === 'SIN EXISTENCIA') {
                $sinExistencia++;
            } elseif ($existenciaConUbicacion > 0 && $existenciaConUbicacion <= $this->limiteBajoStock) {
                $stockBajo++;
                $stockCorrecto++;
            } elseif ($existenciaConUbicacion > $this->limiteBajoStock) {
                $stockNormal++;
                $stockCorrecto++;
            }
        }

        return compact(
            'totalProductos',
            'totalUnidades',
            'sinExistencia',
            'sinAlmacen',
            'stockBajo',
            'stockNormal',
            'stockCorrecto',
            'valorInventario'
        );
    }
}
