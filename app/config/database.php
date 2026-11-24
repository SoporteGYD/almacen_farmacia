<?php

declare(strict_types=1);

date_default_timezone_set('America/Mexico_City');

class Database
{
    private string $host = '127.0.0.1';
    private string $port = '3307';
    private string $dbName = 'almacen_farmacia';
    private string $username = 'root';
    private string $password = '';
    private string $charset = 'utf8mb4';

    private ?PDO $connection = null;

    /**
     * Crear y devolver la conexión PDO.
     */
    public function connect(): PDO
    {
        /*
         * Reutilizar la conexión si ya fue creada dentro
         * de la misma instancia de Database.
         */
        if ($this->connection instanceof PDO) {
            return $this->connection;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $this->host,
            $this->port,
            $this->dbName,
            $this->charset
        );

        try {
            $this->connection = new PDO(
                $dsn,
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE =>
                        PDO::ERRMODE_EXCEPTION,

                    PDO::ATTR_DEFAULT_FETCH_MODE =>
                        PDO::FETCH_ASSOC,

                    PDO::ATTR_EMULATE_PREPARES =>
                        false,

                    PDO::ATTR_STRINGIFY_FETCHES =>
                        false,

                    PDO::ATTR_TIMEOUT =>
                        10
                ]
            );

            /*
             * Configuración de zona horaria para MySQL.
             * America/Mexico_City normalmente corresponde
             * con UTC-06:00.
             */
            $this->connection->exec(
                "SET time_zone = '-06:00'"
            );

            /*
             * Asegurar el uso completo de UTF-8.
             */
            $this->connection->exec(
                "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            );

            return $this->connection;
        } catch (PDOException $e) {
            error_log(
                'Error de conexión con MySQL: '
                . $e->getMessage()
            );

            throw new RuntimeException(
                'No se pudo establecer conexión con la base de datos.',
                0,
                $e
            );
        }
    }

    /**
     * Comprobar si existe una conexión activa.
     */
    public function isConnected(): bool
    {
        if (!$this->connection instanceof PDO) {
            return false;
        }

        try {
            $this->connection->query('SELECT 1');

            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Cerrar la conexión PDO.
     */
    public function disconnect(): void
    {
        $this->connection = null;
    }
}