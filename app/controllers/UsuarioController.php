<?php

require_once __DIR__ . '/../models/Usuario.php';
require_once __DIR__ . '/../models/Movimiento.php';
require_once __DIR__ . '/../helpers/audit.php';

class UsuarioController
{
    private Usuario $usuarioModel;
    private Movimiento $movimientoModel;

    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $this->usuarioModel = new Usuario();
        $this->movimientoModel = new Movimiento();
    }

    private function esAdmin(): bool
    {
        return ($_SESSION['user']['rol'] ?? '') === 'ADMINISTRADOR';
    }

    public function verificarAdmin(): void
    {
        if (!$this->esAdmin()) {
            header('Location: dashboard.php');
            exit;
        }
    }

    public function usuarios(): array
    {
        return $this->usuarioModel->getAll();
    }

    public function almacenes(): array
    {
        return $this->movimientoModel->getAlmacenes();
    }

    public function guardar(array $data): array
    {
        if (!$this->esAdmin()) {
            return [
                'success' => false,
                'message' => 'No tienes permiso para crear usuarios.'
            ];
        }

        $nombre = trim($data['nombre'] ?? '');
        $usuario = trim($data['usuario'] ?? '');
        $correo = trim($data['correo'] ?? '');
        $password = trim($data['password'] ?? '');
        $rol = strtoupper(trim($data['rol'] ?? 'CONSULTA'));
        $almacenId = $data['almacen_id'] ?? null;

        $rolesPermitidos = [
            'ADMINISTRADOR',
            'ENCARGADO',
            'CONSULTA',
            'GERENTE',
            'JEFE_ALMACEN',
        ];

        if ($nombre === '' || $usuario === '' || $correo === '' || $password === '') {
            return [
                'success' => false,
                'message' => 'Nombre, usuario, correo y contraseña son obligatorios.'
            ];
        }

        if (!in_array($rol, $rolesPermitidos, true)) {
            return [
                'success' => false,
                'message' => 'El rol seleccionado no es válido.'
            ];
        }

        if ($rol !== 'ADMINISTRADOR' && empty($almacenId)) {
            return [
                'success' => false,
                'message' => 'Debes asignar un almacén al usuario.'
            ];
        }

        if ($rol === 'ADMINISTRADOR') {
            $almacenId = null;
        }

        $ok = $this->usuarioModel->create([
            'nombre' => $nombre,
            'usuario' => $usuario,
            'correo' => $correo,
            'password' => $password,
            'rol' => $rol,
            'almacen_id' => $almacenId,
            'estado' => 1,
        ]);

        if ($ok) {
            $usuarioCreado =
                $this->usuarioModel->findByUsuarioOrCorreo(
                    $usuario
                );

            auditLog([
                'modulo' => 'Usuarios',
                'accion' => 'CREAR_USUARIO',
                'entidad' => 'usuario',
                'registro_id' => $usuarioCreado['id'] ?? null,
                'descripcion' => 'Creó al usuario '
                    . $nombre . ' con rol ' . $rol . '.',
                'nuevos' => [
                    'id' => $usuarioCreado['id'] ?? null,
                    'nombre' => $nombre,
                    'usuario' => $usuario,
                    'correo' => $correo,
                    'rol' => $rol,
                    'almacen_id' => $almacenId,
                    'estado' => 1,
                ],
            ]);
        }

        return [
            'success' => $ok,
            'message' => $ok ? 'Usuario creado correctamente.' : 'No se pudo crear el usuario.'
        ];
    }

    public function cambiarPassword(int $id, string $password): array
    {
        if (!$this->esAdmin()) {
            return [
                'success' => false,
                'message' => 'No tienes permiso para cambiar contraseñas.'
            ];
        }

        $password = trim($password);

        if ($id <= 0 || $password === '') {
            return [
                'success' => false,
                'message' => 'Usuario o contraseña inválida.'
            ];
        }

        $usuarioObjetivo = $this->usuarioModel->findById($id);

        $ok = $this->usuarioModel->updatePassword($id, $password);

        if ($ok) {
            auditLog([
                'modulo' => 'Usuarios',
                'accion' => 'CAMBIAR_CONTRASENA',
                'entidad' => 'usuario',
                'registro_id' => $id,
                'descripcion' => 'Cambió la contraseña del usuario '
                    . ($usuarioObjetivo['nombre'] ?? ('#' . $id))
                    . '. Por seguridad, la contraseña no se almacenó en la bitácora.',
                'metadata' => [
                    'usuario_afectado' => $usuarioObjetivo['usuario'] ?? null,
                    'correo' => $usuarioObjetivo['correo'] ?? null,
                ],
            ]);
        }

        return [
            'success' => $ok,
            'message' => $ok ? 'Contraseña actualizada correctamente.' : 'No se pudo actualizar la contraseña.'
        ];
    }

    public function cambiarEstado(int $id, int $estado): array
    {
        if (!$this->esAdmin()) {
            return [
                'success' => false,
                'message' => 'No tienes permiso para modificar usuarios.'
            ];
        }

        $usuarioAnterior = $this->usuarioModel->findById($id);
        $ok = $this->usuarioModel->updateEstado($id, $estado);

        if ($ok) {
            $usuarioNuevo = $this->usuarioModel->findById($id);

            auditLog([
                'modulo' => 'Usuarios',
                'accion' => $estado === 1
                    ? 'ACTIVAR_USUARIO'
                    : 'DESACTIVAR_USUARIO',
                'entidad' => 'usuario',
                'registro_id' => $id,
                'descripcion' => (
                    $estado === 1
                        ? 'Activó'
                        : 'Desactivó'
                )
                    . ' al usuario '
                    . ($usuarioAnterior['nombre'] ?? ('#' . $id))
                    . '.',
                'anteriores' => [
                    'estado' => $usuarioAnterior['estado'] ?? null,
                ],
                'nuevos' => [
                    'estado' => $usuarioNuevo['estado'] ?? $estado,
                ],
            ]);
        }

        return [
            'success' => $ok,
            'message' => $ok ? 'Estado actualizado correctamente.' : 'No se pudo actualizar el estado.'
        ];
    }
    public function actualizar(array $data): array
{
    if (!$this->esAdmin()) {
        return [
            'success' => false,
            'message' => 'No tienes permiso para editar usuarios.'
        ];
    }

    $id = (int)($data['usuario_id'] ?? 0);
    $nombre = trim($data['nombre'] ?? '');
    $usuario = trim($data['usuario'] ?? '');
    $correo = trim($data['correo'] ?? '');
    $rol = strtoupper(trim($data['rol'] ?? 'CONSULTA'));
    $almacenId = $data['almacen_id'] ?? null;

    $rolesPermitidos = [
        'ADMINISTRADOR',
        'ENCARGADO',
        'CONSULTA',
        'GERENTE',
        'JEFE_ALMACEN',
    ];

    if ($id <= 0 || $nombre === '' || $usuario === '' || $correo === '') {
        return [
            'success' => false,
            'message' => 'Datos incompletos para editar el usuario.'
        ];
    }

    if (!in_array($rol, $rolesPermitidos, true)) {
        return [
            'success' => false,
            'message' => 'El rol seleccionado no es válido.'
        ];
    }

    if ($rol !== 'ADMINISTRADOR' && empty($almacenId)) {
        return [
            'success' => false,
            'message' => 'Debes asignar un almacén al usuario.'
        ];
    }

    if ($rol === 'ADMINISTRADOR') {
        $almacenId = null;
    }

    $usuarioAnterior = $this->usuarioModel->findById($id);

    $ok = $this->usuarioModel->update($id, [
        'nombre' => $nombre,
        'usuario' => $usuario,
        'correo' => $correo,
        'rol' => $rol,
        'almacen_id' => $almacenId,
    ]);

    if ($ok) {
        $usuarioNuevo = $this->usuarioModel->findById($id);
        $changes = auditChangedValues(
            $usuarioAnterior ?? [],
            $usuarioNuevo ?? []
        );

        auditLog([
            'modulo' => 'Usuarios',
            'accion' => 'ACTUALIZAR_USUARIO',
            'entidad' => 'usuario',
            'registro_id' => $id,
            'descripcion' => 'Actualizó los datos del usuario '
                . ($usuarioNuevo['nombre']
                    ?? $usuarioAnterior['nombre']
                    ?? ('#' . $id))
                . '.',
            'anteriores' => $changes['anteriores'],
            'nuevos' => $changes['nuevos'],
        ]);
    }

    return [
        'success' => $ok,
        'message' => $ok ? 'Usuario actualizado correctamente.' : 'No se pudo actualizar el usuario.'
    ];
}
}
