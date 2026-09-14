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
        $stmt = $this->conn->query(
            "SHOW TABLES LIKE 'auditoria_movimientos'"
        );

        return (bool) $stmt->fetchColumn();
    }

    private function buildWhere(
        array $filters,
        array &$params
    ): string {
        $where = [];

        $search = trim((string) ($filters['buscar'] ?? ''));

        if ($search !== '') {
            $where[] = "(
                am.descripcion LIKE :buscar_descripcion
                OR am.usuario_nombre LIKE :buscar_usuario
                OR am.usuario_login LIKE :buscar_login
                OR am.entidad LIKE :buscar_entidad
                OR am.registro_id LIKE :buscar_registro
            )";

            $term = '%' . $search . '%';
            $params[':buscar_descripcion'] = $term;
            $params[':buscar_usuario'] = $term;
            $params[':buscar_login'] = $term;
            $params[':buscar_entidad'] = $term;
            $params[':buscar_registro'] = $term;
        }

        $usuarioId = (int) ($filters['usuario_id'] ?? 0);

        if ($usuarioId > 0) {
            $where[] = 'am.usuario_id = :usuario_id';
            $params[':usuario_id'] = $usuarioId;
        }

        $almacenId = (int) ($filters['almacen_id'] ?? 0);

        if ($almacenId > 0) {
            $where[] = 'am.almacen_id = :almacen_id';
            $params[':almacen_id'] = $almacenId;
        }

        $modulo = trim((string) ($filters['modulo'] ?? ''));

        if ($modulo !== '') {
            $where[] = 'am.modulo = :modulo';
            $params[':modulo'] = $modulo;
        }

        $accion = trim((string) ($filters['accion'] ?? ''));

        if ($accion !== '') {
            $where[] = 'am.accion = :accion';
            $params[':accion'] = $accion;
        }

        $fechaInicio = trim(
            (string) ($filters['fecha_inicio'] ?? '')
        );

        if ($fechaInicio !== '') {
            $where[] = 'am.creado_en >= :fecha_inicio';
            $params[':fecha_inicio'] = $fechaInicio . ' 00:00:00';
        }

        $fechaFinal = trim(
            (string) ($filters['fecha_final'] ?? '')
        );

        if ($fechaFinal !== '') {
            $where[] = 'am.creado_en < DATE_ADD(:fecha_final, INTERVAL 1 DAY)';
            $params[':fecha_final'] = $fechaFinal . ' 00:00:00';
        }

        return $where === []
            ? ''
            : ' WHERE ' . implode(' AND ', $where);
    }

    public function paginated(
        array $filters,
        int $page = 1,
        int $perPage = 30
    ): array {
        $page = max(1, $page);
        $perPage = min(100, max(10, $perPage));

        $params = [];
        $where = $this->buildWhere($filters, $params);

        $countStmt = $this->conn->prepare(
            'SELECT COUNT(*) FROM auditoria_movimientos am'
            . $where
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $totalPages = max(1, (int) ceil($total / $perPage));

        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $offset = ($page - 1) * $perPage;

        $sql = "
            SELECT
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
                am.creado_en
            FROM auditoria_movimientos am
            {$where}
            ORDER BY am.creado_en DESC, am.id DESC
            LIMIT :limite OFFSET :desplazamiento
        ";

        $stmt = $this->conn->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $stmt->bindValue(':limite', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(
            ':desplazamiento',
            $offset,
            PDO::PARAM_INT
        );
        $stmt->execute();

        return [
            'registros' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'pagina' => $page,
            'por_pagina' => $perPage,
            'total_paginas' => $totalPages,
        ];
    }

    public function filterOptions(): array
    {
        $usuarios = $this->conn->query("
            SELECT
                id,
                nombre,
                usuario
            FROM usuarios
            ORDER BY nombre ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $almacenes = $this->conn->query("
            SELECT
                id,
                nombre
            FROM almacenes
            WHERE estado = 1
            ORDER BY nombre ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $modulos = $this->conn->query("
            SELECT DISTINCT modulo
            FROM auditoria_movimientos
            WHERE modulo <> ''
            ORDER BY modulo ASC
        ")->fetchAll(PDO::FETCH_COLUMN);

        $acciones = $this->conn->query("
            SELECT DISTINCT accion
            FROM auditoria_movimientos
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
