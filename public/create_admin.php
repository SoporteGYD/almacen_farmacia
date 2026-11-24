<?php

require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/helpers/auth.php';

requireLogin();

$user = currentUser();
$rol = strtoupper(trim((string) ($user['rol'] ?? '')));

if ($rol !== 'ADMINISTRADOR') {
    auditLog([
        'modulo' => 'Usuarios',
        'accion' => 'ACCION_DENEGADA',
        'descripcion' => 'Intentó utilizar el restablecimiento administrativo sin permisos.',
    ]);

    http_response_code(403);
    exit('Acceso denegado.');
}

try {
    $database = new Database();
    $conn = $database->connect();

    $nuevoHash = password_hash('admin123', PASSWORD_DEFAULT);

    $sql = "UPDATE usuarios 
            SET password = :password 
            WHERE usuario = 'admin' OR correo = 'admin@farmacia.com'";

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':password' => $nuevoHash
    ]);

    if ($stmt->rowCount() > 0) {
        auditLog([
            'modulo' => 'Usuarios',
            'accion' => 'RESTABLECER_CONTRASENA_ADMIN',
            'entidad' => 'usuario',
            'registro_id' => 'admin',
            'descripcion' => 'Restableció la contraseña del usuario admin mediante la herramienta administrativa. La contraseña no se guardó en la bitácora.',
        ]);

        echo "Contraseña actualizada correctamente para el usuario admin.";
    } else {
        echo "No se encontró un usuario admin para actualizar.";
    }
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage();
}
