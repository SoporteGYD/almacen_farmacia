<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

class Auditoria
{
    private PDO $conn;

    public function __construct(?PDO $conn = null)
    {
        if ($conn instanceof PDO) {
            $this->conn = $conn;
            return;
        }

        $database = new Database();
        $this->conn = $database->connect();
    }

    public function tableExists(): bool
    {
        $stmt = $this->conn->query("SHOW TABLES LIKE 'auditoria_movimientos'");
        return (bool) $stmt->fetchColumn();
    }

    /**
     * La bitácora combinada muestra dos fuentes:
     * 1) auditoria_movimientos (eventos técnicos reales), y
     * 2) movimientos reales que no cuentan con un evento de creación histórico.
     *
     * Los segundos se marcan como MOVIMIENTO_RECONSTRUIDO y nunca inventan IP,
     * URL o navegador: únicamente reutilizan información que sí existe en
     * movimientos, movimiento_detalle, usuarios y almacenes.
     */
    private function combinedAuditBaseSql(): string
    {
        return "
            SELECT
                CONCAT('A-', am.id) AS fila_clave,
                am.id,
                am.usuario_id,
                am.usuario_nombre,
                am.usuario_login,
                am.usuario_rol,
                am.almacen_id,
                am.almacen_nombre,
                am.modulo,
                am.accion,
                am.entidad,
                am.registro_id,
                am.descripcion,
                am.datos_anteriores,
                am.datos_nuevos,
                am.metadata,
                am.direccion_ip,
                am.user_agent,
                am.metodo_http,
                am.url,
                am.creado_en,
                'AUDITORIA' AS fuente,
                CASE
                    WHEN am.entidad = 'movimiento'
                         AND am.registro_id REGEXP '^[0-9]+$'
                    THEN CAST(am.registro_id AS UNSIGNED)
                    ELSE NULL
                END AS movimiento_id,
                CONCAT_WS(' ',
                    COALESCE(am.descripcion, ''),
                    COALESCE(am.usuario_nombre, ''),
                    COALESCE(am.usuario_login, ''),
                    COALESCE(am.almacen_nombre, ''),
                    COALESCE(am.modulo, ''),
                    COALESCE(am.accion, ''),
                    COALESCE(am.entidad, ''),
                    COALESCE(am.registro_id, ''),
                    COALESCE(CAST(am.datos_anteriores AS CHAR), ''),
                    COALESCE(CAST(am.datos_nuevos AS CHAR), ''),
                    COALESCE(CAST(am.metadata AS CHAR), ''),
                    COALESCE((
                        SELECT GROUP_CONCAT(
                            CONCAT_WS(' ', px.codigo, px.codigo_barras, px.descripcion, mdx.ubicacion)
                            SEPARATOR ' '
                        )
                        FROM movimiento_detalle mdx
                        INNER JOIN productos px ON px.id = mdx.producto_id
                        WHERE am.entidad = 'movimiento'
                          AND am.registro_id REGEXP '^[0-9]+$'
                          AND mdx.movimiento_id = CAST(am.registro_id AS UNSIGNED)
                    ), '')
                ) AS texto_busqueda
            FROM auditoria_movimientos am

            UNION ALL

            SELECT
                CONCAT('M-', m.id) AS fila_clave,
                m.id,
                m.usuario_id,
                u.nombre AS usuario_nombre,
                u.usuario AS usuario_login,
                u.rol AS usuario_rol,
                m.almacen_id,
                a.nombre AS almacen_nombre,
                CASE m.tipo_movimiento
                    WHEN 'ENTRADA' THEN 'Entradas'
                    WHEN 'SALIDA' THEN 'Salidas'
                    WHEN 'DEVOLUCION' THEN 'Devoluciones'
                    ELSE 'Movimientos de inventario'
                END AS modulo,
                CONCAT('CREAR_', m.tipo_movimiento) AS accion,
                'movimiento' AS entidad,
                CAST(m.id AS CHAR) AS registro_id,
                CONCAT(
                    'Movimiento ', m.tipo_movimiento, ' ', m.folio,
                    ' registrado en inventario',
                    CASE WHEN m.referencia IS NOT NULL AND m.referencia <> '' THEN CONCAT('. ', m.referencia) ELSE '' END,
                    CASE WHEN m.observaciones IS NOT NULL AND m.observaciones <> '' THEN CONCAT('. ', m.observaciones) ELSE '' END
                ) AS descripcion,
                NULL AS datos_anteriores,
                NULL AS datos_nuevos,
                JSON_OBJECT(
                    'origen', 'movimientos',
                    'folio', m.folio,
                    'tipo_movimiento', m.tipo_movimiento,
                    'fecha_movimiento', m.fecha,
                    'cancelado', m.cancelado,
                    'nota', 'Registro reconstruido desde el movimiento real porque no existe su evento de creación en auditoría.'
                ) AS metadata,
                NULL AS direccion_ip,
                NULL AS user_agent,
                NULL AS metodo_http,
                NULL AS url,
                COALESCE(m.created_at, m.fecha) AS creado_en,
                'MOVIMIENTO_RECONSTRUIDO' AS fuente,
                m.id AS movimiento_id,
                CONCAT_WS(' ',
                    m.folio,
                    m.tipo_movimiento,
                    COALESCE(m.referencia, ''),
                    COALESCE(m.observaciones, ''),
                    COALESCE(u.nombre, ''),
                    COALESCE(u.usuario, ''),
                    COALESCE(a.nombre, ''),
                    COALESCE((
                        SELECT GROUP_CONCAT(
                            CONCAT_WS(' ', p2.codigo, p2.codigo_barras, p2.descripcion, md2.ubicacion)
                            SEPARATOR ' '
                        )
                        FROM movimiento_detalle md2
                        INNER JOIN productos p2 ON p2.id = md2.producto_id
                        WHERE md2.movimiento_id = m.id
                    ), '')
                ) AS texto_busqueda
            FROM movimientos m
            LEFT JOIN usuarios u ON u.id = m.usuario_id
            LEFT JOIN almacenes a ON a.id = m.almacen_id
            WHERE NOT EXISTS (
                SELECT 1
                FROM auditoria_movimientos ax
                WHERE ax.entidad = 'movimiento'
                  AND ax.registro_id = CAST(m.id AS CHAR)
                  AND (
                      ax.accion = CONCAT('CREAR_', m.tipo_movimiento)
                      OR ax.accion IN (
                          'CREAR_MOVIMIENTO',
                          'REGISTRAR_MOVIMIENTO',
                          'REGISTRAR_ENTRADA',
                          'REGISTRAR_SALIDA'
                      )
                  )
            )
        ";
    }

    private function buildCombinedWhere(array $filters, array &$params): string
    {
        $where = [];

        $search = trim((string) ($filters['buscar'] ?? ''));
        if ($search !== '') {
            $where[] = 'h.texto_busqueda LIKE :buscar';
            $params[':buscar'] = '%' . $search . '%';
        }

        $usuarioId = (int) ($filters['usuario_id'] ?? 0);
        if ($usuarioId > 0) {
            $where[] = 'h.usuario_id = :usuario_id';
            $params[':usuario_id'] = $usuarioId;
        }

        $almacenId = (int) ($filters['almacen_id'] ?? 0);
        if ($almacenId > 0) {
            $where[] = 'h.almacen_id = :almacen_id';
            $params[':almacen_id'] = $almacenId;
        }

        $modulo = trim((string) ($filters['modulo'] ?? ''));
        if ($modulo !== '') {
            $where[] = 'h.modulo = :modulo';
            $params[':modulo'] = $modulo;
        }

        $accion = trim((string) ($filters['accion'] ?? ''));
        if ($accion !== '') {
            $where[] = 'h.accion = :accion';
            $params[':accion'] = $accion;
        }

        $fechaInicio = trim((string) ($filters['fecha_inicio'] ?? ''));
        if ($fechaInicio !== '') {
            $where[] = 'h.creado_en >= :fecha_inicio';
            $params[':fecha_inicio'] = $fechaInicio . ' 00:00:00';
        }

        $fechaFinal = trim((string) ($filters['fecha_final'] ?? ''));
        if ($fechaFinal !== '') {
            $where[] = 'h.creado_en < DATE_ADD(:fecha_final, INTERVAL 1 DAY)';
            $params[':fecha_final'] = $fechaFinal . ' 00:00:00';
        }

        return $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
    }

    public function paginated(array $filters, int $page = 1, int $perPage = 30): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(10, $perPage));

        $baseSql = $this->combinedAuditBaseSql();
        $params = [];
        $where = $this->buildCombinedWhere($filters, $params);

        $countSql = "SELECT COUNT(*) FROM ({$baseSql}) h {$where}";
        $countStmt = $this->conn->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        $sql = "
            SELECT h.*
            FROM ({$baseSql}) h
            {$where}
            ORDER BY h.creado_en DESC, h.fila_clave DESC
            LIMIT :limite OFFSET :desplazamiento
        ";
        $stmt = $this->conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limite', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':desplazamiento', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->attachMovementDetailsToAuditRows($rows);

        return [
            'registros' => $rows,
            'total' => $total,
            'pagina' => $page,
            'por_pagina' => $perPage,
            'total_paginas' => $totalPages,
        ];
    }

    private function attachMovementDetailsToAuditRows(array &$rows): void
    {
        if ($rows === []) {
            return;
        }

        $movementIds = [];
        foreach ($rows as $row) {
            $movementId = (int) ($row['movimiento_id'] ?? 0);
            if ($movementId > 0) {
                $movementIds[$movementId] = $movementId;
            }
        }

        if ($movementIds === []) {
            return;
        }

        $ids = array_values($movementIds);
        $marks = implode(',', array_fill(0, count($ids), '?'));

        $movementSql = "
            SELECT
                m.id,
                m.folio,
                m.tipo_movimiento,
                m.fecha,
                m.created_at,
                m.referencia,
                m.tipo_operacion,
                m.observaciones,
                m.cancelado,
                m.fecha_cancelacion,
                m.motivo_cancelacion,
                a.nombre AS almacen_nombre,
                u.nombre AS usuario_nombre
            FROM movimientos m
            LEFT JOIN almacenes a ON a.id = m.almacen_id
            LEFT JOIN usuarios u ON u.id = m.usuario_id
            WHERE m.id IN ({$marks})
        ";
        $movementStmt = $this->conn->prepare($movementSql);
        $movementStmt->execute($ids);
        $movementById = [];
        foreach ($movementStmt->fetchAll(PDO::FETCH_ASSOC) as $movement) {
            $movementById[(int) $movement['id']] = $movement;
        }

        $detailSql = "
            SELECT
                md.movimiento_id,
                md.id,
                p.codigo,
                p.codigo_barras,
                p.descripcion,
                md.cantidad,
                md.costo_unitario,
                md.precio_unitario,
                md.ubicacion
            FROM movimiento_detalle md
            INNER JOIN productos p ON p.id = md.producto_id
            WHERE md.movimiento_id IN ({$marks})
            ORDER BY md.movimiento_id DESC, md.id ASC
        ";
        $detailStmt = $this->conn->prepare($detailSql);
        $detailStmt->execute($ids);
        $detailsById = [];
        foreach ($detailStmt->fetchAll(PDO::FETCH_ASSOC) as $detail) {
            $detailsById[(int) $detail['movimiento_id']][] = $detail;
        }

        foreach ($rows as &$row) {
            $movementId = (int) ($row['movimiento_id'] ?? 0);
            if ($movementId <= 0) {
                $row['movimiento'] = null;
                $row['movimiento_detalles'] = [];
                continue;
            }
            $row['movimiento'] = $movementById[$movementId] ?? null;
            $row['movimiento_detalles'] = $detailsById[$movementId] ?? [];
        }
        unset($row);
    }

    public function filterOptions(): array
    {
        $usuarios = $this->conn->query("
            SELECT id, nombre, usuario
            FROM usuarios
            ORDER BY nombre ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $almacenes = $this->conn->query("
            SELECT id, nombre
            FROM almacenes
            WHERE estado = 1
            ORDER BY nombre ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $modulos = $this->conn->query("
            SELECT modulo FROM (
                SELECT DISTINCT modulo FROM auditoria_movimientos WHERE modulo <> ''
                UNION
                SELECT DISTINCT CASE tipo_movimiento
                    WHEN 'ENTRADA' THEN 'Entradas'
                    WHEN 'SALIDA' THEN 'Salidas'
                    WHEN 'DEVOLUCION' THEN 'Devoluciones'
                    ELSE 'Movimientos de inventario'
                END AS modulo
                FROM movimientos
            ) x
            WHERE modulo <> ''
            ORDER BY modulo ASC
        ")->fetchAll(PDO::FETCH_COLUMN);

        $acciones = $this->conn->query("
            SELECT accion FROM (
                SELECT DISTINCT accion FROM auditoria_movimientos WHERE accion <> ''
                UNION
                SELECT DISTINCT CONCAT('CREAR_', tipo_movimiento) AS accion
                FROM movimientos
                WHERE tipo_movimiento <> ''
            ) x
            WHERE accion <> ''
            ORDER BY accion ASC
        ")->fetchAll(PDO::FETCH_COLUMN);

        return [
            'usuarios' => $usuarios,
            'almacenes' => $almacenes,
            'modulos' => $modulos,
            'acciones' => $acciones,
        ];
    }

    private function buildInventoryWhere(array $filters, array &$params): string
    {
        $where = [];
        $search = trim((string) ($filters['buscar'] ?? ''));
        if ($search !== '') {
            $where[] = "(m.folio LIKE :inv_buscar OR m.referencia LIKE :inv_buscar2 OR m.observaciones LIKE :inv_buscar3 OR u.nombre LIKE :inv_buscar4 OR u.usuario LIKE :inv_buscar5 OR a.nombre LIKE :inv_buscar6 OR EXISTS (SELECT 1 FROM movimiento_detalle mdx INNER JOIN productos px ON px.id = mdx.producto_id WHERE mdx.movimiento_id = m.id AND (px.codigo LIKE :inv_buscar7 OR px.codigo_barras LIKE :inv_buscar8 OR px.descripcion LIKE :inv_buscar9)))";
            $term = '%' . $search . '%';
            for ($i = 1; $i <= 9; $i++) {
                $params[$i === 1 ? ':inv_buscar' : ':inv_buscar' . $i] = $term;
            }
        }

        $usuarioId = (int) ($filters['usuario_id'] ?? 0);
        if ($usuarioId > 0) {
            $where[] = 'm.usuario_id = :inv_usuario_id';
            $params[':inv_usuario_id'] = $usuarioId;
        }

        $almacenId = (int) ($filters['almacen_id'] ?? 0);
        if ($almacenId > 0) {
            $where[] = 'm.almacen_id = :inv_almacen_id';
            $params[':inv_almacen_id'] = $almacenId;
        }

        $tipo = strtoupper(trim((string) ($filters['tipo_movimiento'] ?? '')));
        if ($tipo !== '') {
            $where[] = 'm.tipo_movimiento = :inv_tipo';
            $params[':inv_tipo'] = $tipo;
        }

        $fechaInicio = trim((string) ($filters['fecha_inicio'] ?? ''));
        if ($fechaInicio !== '') {
            $where[] = 'm.fecha >= :inv_fecha_inicio';
            $params[':inv_fecha_inicio'] = $fechaInicio . ' 00:00:00';
        }

        $fechaFinal = trim((string) ($filters['fecha_final'] ?? ''));
        if ($fechaFinal !== '') {
            $where[] = 'm.fecha < DATE_ADD(:inv_fecha_final, INTERVAL 1 DAY)';
            $params[':inv_fecha_final'] = $fechaFinal . ' 00:00:00';
        }

        return $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
    }

    public function inventoryPaginated(array $filters, int $page = 1, int $perPage = 30): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(10, $perPage));
        $params = [];
        $where = $this->buildInventoryWhere($filters, $params);

        $countSql = "SELECT COUNT(*) FROM movimientos m LEFT JOIN usuarios u ON u.id = m.usuario_id LEFT JOIN almacenes a ON a.id = m.almacen_id {$where}";
        $countStmt = $this->conn->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        $sql = "
            SELECT
                m.id,
                m.folio,
                m.tipo_movimiento,
                m.fecha,
                m.created_at,
                m.almacen_id,
                a.nombre AS almacen_nombre,
                m.usuario_id,
                u.nombre AS usuario_nombre,
                u.usuario AS usuario_login,
                u.rol AS usuario_rol,
                m.referencia,
                m.tipo_operacion,
                m.observaciones,
                m.cancelado,
                m.fecha_cancelacion,
                m.motivo_cancelacion,
                (SELECT COUNT(DISTINCT md1.producto_id) FROM movimiento_detalle md1 WHERE md1.movimiento_id = m.id) AS total_productos,
                (SELECT COALESCE(SUM(md2.cantidad), 0) FROM movimiento_detalle md2 WHERE md2.movimiento_id = m.id) AS total_cantidad
            FROM movimientos m
            LEFT JOIN usuarios u ON u.id = m.usuario_id
            LEFT JOIN almacenes a ON a.id = m.almacen_id
            {$where}
            ORDER BY m.fecha DESC, m.id DESC
            LIMIT :inv_limite OFFSET :inv_offset
        ";
        $stmt = $this->conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':inv_limite', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':inv_offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($rows !== []) {
            $ids = array_map(static fn(array $row): int => (int) $row['id'], $rows);
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $detailSql = "
                SELECT md.movimiento_id, md.id, p.codigo, p.codigo_barras, p.descripcion,
                       md.cantidad, md.costo_unitario, md.precio_unitario, md.ubicacion
                FROM movimiento_detalle md
                INNER JOIN productos p ON p.id = md.producto_id
                WHERE md.movimiento_id IN ({$marks})
                ORDER BY md.movimiento_id DESC, md.id ASC
            ";
            $detailStmt = $this->conn->prepare($detailSql);
            $detailStmt->execute($ids);
            $grouped = [];
            foreach ($detailStmt->fetchAll(PDO::FETCH_ASSOC) as $detail) {
                $grouped[(int) $detail['movimiento_id']][] = $detail;
            }
            foreach ($rows as &$row) {
                $row['detalles'] = $grouped[(int) $row['id']] ?? [];
            }
            unset($row);
        }

        return [
            'registros' => $rows,
            'total' => $total,
            'pagina' => $page,
            'por_pagina' => $perPage,
            'total_paginas' => $totalPages,
        ];
    }

    public function inventoryTypes(): array
    {
        return $this->conn->query("SELECT DISTINCT tipo_movimiento FROM movimientos WHERE tipo_movimiento <> '' ORDER BY tipo_movimiento ASC")->fetchAll(PDO::FETCH_COLUMN);
    }
}
