<?php

declare(strict_types=1);

require_once __DIR__ . '/../models/Auditoria.php';
require_once __DIR__ . '/../helpers/audit.php';

class AuditoriaController
{
    private Auditoria $model;

    public function __construct()
    {
        $this->model = new Auditoria();
    }

    public function verificarAdministrador(): void
    {
        $rol = strtoupper(
            trim((string) ($_SESSION['user']['rol'] ?? ''))
        );

        if ($rol !== 'ADMINISTRADOR') {
            auditLog([
                'modulo' => 'Historial de movimientos',
                'accion' => 'ACCESO_DENEGADO',
                'descripcion' => 'Intentó abrir el historial de movimientos sin permisos de administrador.',
            ]);

            http_response_code(403);
            exit('Acceso denegado. Este módulo es exclusivo del administrador.');
        }
    }

    public function estaInstalado(): bool
    {
        return $this->model->tableExists();
    }

    public function consultar(
        array $filters,
        int $page,
        int $perPage = 30
    ): array {
        return $this->model->paginated(
            $filters,
            $page,
            $perPage
        );
    }

    public function consultarInventario(
        array $filters,
        int $page,
        int $perPage = 30
    ): array {
        return $this->model->inventoryPaginated(
            $filters,
            $page,
            $perPage
        );
    }

    public function tiposInventario(): array
    {
        return $this->model->inventoryTypes();
    }

    public function opciones(): array
    {
        return $this->model->filterOptions();
    }
}
