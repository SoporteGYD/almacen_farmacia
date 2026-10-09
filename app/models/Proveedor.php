<?php
require_once __DIR__ . '/../helpers/proveedor_historico.php';
/**
 * Catálogo de proveedores utilizado por Entradas y por la recuperación histórica.
 * El llamador debe abrir la transacción cuando necesite atomicidad con el movimiento.
 */
final class Proveedor
{
    public static function resolverId(PDO $conn, string $nombre, ?int $id = null): ?int
    {
        $nombre = trim($nombre);
        // Las entradas por traspaso o ajuste NO son proveedores comerciales.
        // Mantener proveedor_id en NULL; las observaciones permanecen intactas.
        if (proveedorEsConceptoInterno($nombre)) {
            return null;
        }
        if ($nombre !== '') {
            $nombre = preg_replace('/\s+/u', ' ', $nombre) ?: $nombre;
            $longitud = function_exists('mb_strlen')
                ? mb_strlen($nombre, 'UTF-8')
                : strlen($nombre);
            if ($longitud > 150) {
                throw new InvalidArgumentException('El nombre del proveedor no puede exceder 150 caracteres.');
            }

            // Reutiliza el proveedor existente aunque el nombre cambie de mayúsculas.
            $stmt = $conn->prepare(
                "SELECT id, estado FROM proveedores
                 WHERE TRIM(nombre) COLLATE utf8mb4_general_ci = :nombre
                 ORDER BY estado DESC, id ASC LIMIT 1"
            );
            $stmt->execute([':nombre' => $nombre]);
            $existente = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existente) {
                $proveedorId = (int) $existente['id'];
                if ((int)$existente['estado'] !== 1) {
                    $activar = $conn->prepare('UPDATE proveedores SET estado = 1 WHERE id = :id');
                    $activar->execute([':id' => $proveedorId]);
                }
                return $proveedorId;
            }

            $nuevo = $conn->prepare('INSERT INTO proveedores (nombre, estado) VALUES (:nombre, 1)');
            $nuevo->execute([':nombre' => $nombre]);
            return (int)$conn->lastInsertId();
        }

        if ($id !== null && $id > 0) {
            $stmt = $conn->prepare('SELECT id FROM proveedores WHERE id = :id AND estado = 1 LIMIT 1');
            $stmt->execute([':id' => $id]);
            if ($stmt->fetchColumn()) {
                return $id;
            }
            throw new InvalidArgumentException('El proveedor seleccionado no existe o está inactivo.');
        }

        return null; // Una entrada puede ser de inventario inicial o ajuste sin proveedor.
    }
}
