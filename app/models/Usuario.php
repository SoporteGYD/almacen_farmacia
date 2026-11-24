<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

class Usuario
{
    private PDO $conn;
    private string $table = 'usuarios';

    public function __construct(?PDO $conn = null)
    {
        if ($conn instanceof PDO) {
            $this->conn = $conn;
            return;
        }

        $database = new Database();
        $this->conn = $database->connect();
    }

    // ==================================================
    // BUSCAR USUARIO PARA INICIAR SESIÓN
    // ==================================================

    public function findByUsuarioOrCorreo(
        string $login
    ): ?array {
        $login = trim($login);

        if ($login === '') {
            return null;
        }

        /*
         * Se utilizan dos parámetros diferentes:
         * :usuario y :correo.
         *
         * No se debe reutilizar :login porque PDO tiene
         * las consultas preparadas emuladas desactivadas.
         */
        $sql = "
            SELECT
                u.*,
                a.nombre AS almacen_nombre,

                UPPER(
                    REPLACE(
                        REPLACE(
                            REPLACE(
                                REPLACE(
                                    REPLACE(
                                        REPLACE(
                                            a.nombre,
                                            ' ',
                                            '_'
                                        ),
                                        'Á',
                                        'A'
                                    ),
                                    'É',
                                    'E'
                                ),
                                'Í',
                                'I'
                            ),
                            'Ó',
                            'O'
                        ),
                        'Ú',
                        'U'
                    )
                ) AS almacen_codigo

            FROM {$this->table} AS u

            LEFT JOIN almacenes AS a
                ON u.almacen_id = a.id

            WHERE (
                u.usuario = :usuario
                OR u.correo = :correo
            )
            AND u.estado = 1

            LIMIT 1
        ";

        $stmt = $this->conn->prepare($sql);

        $stmt->execute([
            ':usuario' => $login,
            ':correo' => $login
        ]);

        $user = $stmt->fetch(
            PDO::FETCH_ASSOC
        );

        return $user ?: null;
    }

    // ==================================================
    // BUSCAR USUARIO POR ID
    // ==================================================

    public function findById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $sql = "
            SELECT
                u.id,
                u.nombre,
                u.usuario,
                u.correo,
                u.rol,
                u.estado,
                u.almacen_id,
                u.created_at,
                u.updated_at,

                a.nombre AS almacen_nombre,

                UPPER(
                    REPLACE(
                        REPLACE(
                            REPLACE(
                                REPLACE(
                                    REPLACE(
                                        REPLACE(
                                            a.nombre,
                                            ' ',
                                            '_'
                                        ),
                                        'Á',
                                        'A'
                                    ),
                                    'É',
                                    'E'
                                ),
                                'Í',
                                'I'
                            ),
                            'Ó',
                            'O'
                        ),
                        'Ú',
                        'U'
                    )
                ) AS almacen_codigo

            FROM {$this->table} AS u

            LEFT JOIN almacenes AS a
                ON u.almacen_id = a.id

            WHERE u.id = :id

            LIMIT 1
        ";

        $stmt = $this->conn->prepare($sql);

        $stmt->execute([
            ':id' => $id
        ]);

        $user = $stmt->fetch(
            PDO::FETCH_ASSOC
        );

        return $user ?: null;
    }

    // ==================================================
    // OBTENER TODOS LOS USUARIOS
    // ==================================================

    public function getAll(): array
    {
        $sql = "
            SELECT
                u.id,
                u.nombre,
                u.usuario,
                u.correo,
                u.rol,
                u.estado,
                u.almacen_id,
                u.created_at,
                u.updated_at,

                a.nombre AS almacen_nombre

            FROM {$this->table} AS u

            LEFT JOIN almacenes AS a
                ON u.almacen_id = a.id

            ORDER BY
                u.nombre ASC
        ";

        $stmt = $this->conn->query($sql);

        return $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );
    }

    // ==================================================
    // CREAR USUARIO
    // ==================================================

    public function create(array $data): bool
    {
        $nombre = trim(
            (string) ($data['nombre'] ?? '')
        );

        $usuario = trim(
            (string) ($data['usuario'] ?? '')
        );

        $correo = trim(
            (string) ($data['correo'] ?? '')
        );

        $password = (string) (
            $data['password'] ?? ''
        );

        $rol = strtoupper(
            trim(
                (string) (
                    $data['rol']
                    ?? 'CONSULTA'
                )
            )
        );

        $estado = (int) (
            $data['estado'] ?? 1
        );

        $almacenId = isset($data['almacen_id'])
            && $data['almacen_id'] !== ''
                ? (int) $data['almacen_id']
                : null;

        if ($nombre === '') {
            throw new InvalidArgumentException(
                'El nombre del usuario es obligatorio.'
            );
        }

        if ($usuario === '') {
            throw new InvalidArgumentException(
                'El nombre de usuario es obligatorio.'
            );
        }

        if ($correo === '') {
            throw new InvalidArgumentException(
                'El correo electrónico es obligatorio.'
            );
        }

        if ($password === '') {
            throw new InvalidArgumentException(
                'La contraseña es obligatoria.'
            );
        }

        $sql = "
            INSERT INTO {$this->table} (
                nombre,
                usuario,
                correo,
                password,
                rol,
                estado,
                almacen_id
            ) VALUES (
                :nombre,
                :usuario,
                :correo,
                :password,
                :rol,
                :estado,
                :almacen_id
            )
        ";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':nombre' => $nombre,
            ':usuario' => $usuario,
            ':correo' => $correo,
            ':password' => password_hash(
                $password,
                PASSWORD_DEFAULT
            ),
            ':rol' => $rol,
            ':estado' => $estado,
            ':almacen_id' => $almacenId
        ]);
    }

    // ==================================================
    // ACTUALIZAR CONTRASEÑA
    // ==================================================

    public function updatePassword(
        int $id,
        string $password
    ): bool {
        if ($id <= 0) {
            throw new InvalidArgumentException(
                'El usuario indicado no es válido.'
            );
        }

        if ($password === '') {
            throw new InvalidArgumentException(
                'La contraseña no puede estar vacía.'
            );
        }

        $sql = "
            UPDATE {$this->table}
            SET
                password = :password,
                updated_at = NOW()
            WHERE id = :id
        ";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':password' => password_hash(
                $password,
                PASSWORD_DEFAULT
            ),
            ':id' => $id
        ]);
    }

    // ==================================================
    // ACTIVAR O DESACTIVAR USUARIO
    // ==================================================

    public function updateEstado(
        int $id,
        int $estado
    ): bool {
        if ($id <= 0) {
            throw new InvalidArgumentException(
                'El usuario indicado no es válido.'
            );
        }

        $estado = $estado === 1 ? 1 : 0;

        $sql = "
            UPDATE {$this->table}
            SET
                estado = :estado,
                updated_at = NOW()
            WHERE id = :id
        ";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':estado' => $estado,
            ':id' => $id
        ]);
    }

    // ==================================================
    // ACTUALIZAR DATOS DEL USUARIO
    // ==================================================

    public function update(
        int $id,
        array $data
    ): bool {
        if ($id <= 0) {
            throw new InvalidArgumentException(
                'El usuario indicado no es válido.'
            );
        }

        $nombre = trim(
            (string) ($data['nombre'] ?? '')
        );

        $usuario = trim(
            (string) ($data['usuario'] ?? '')
        );

        $correo = trim(
            (string) ($data['correo'] ?? '')
        );

        $rol = strtoupper(
            trim(
                (string) (
                    $data['rol']
                    ?? 'CONSULTA'
                )
            )
        );

        $almacenId = isset($data['almacen_id'])
            && $data['almacen_id'] !== ''
                ? (int) $data['almacen_id']
                : null;

        if ($nombre === '') {
            throw new InvalidArgumentException(
                'El nombre es obligatorio.'
            );
        }

        if ($usuario === '') {
            throw new InvalidArgumentException(
                'El nombre de usuario es obligatorio.'
            );
        }

        if ($correo === '') {
            throw new InvalidArgumentException(
                'El correo electrónico es obligatorio.'
            );
        }

        $sql = "
            UPDATE {$this->table}
            SET
                nombre = :nombre,
                usuario = :usuario,
                correo = :correo,
                rol = :rol,
                almacen_id = :almacen_id,
                updated_at = NOW()
            WHERE id = :id
        ";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':nombre' => $nombre,
            ':usuario' => $usuario,
            ':correo' => $correo,
            ':rol' => $rol,
            ':almacen_id' => $almacenId,
            ':id' => $id
        ]);
    }
}