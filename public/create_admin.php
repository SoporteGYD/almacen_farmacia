<?php
/**
 * Endpoint legado inhabilitado deliberadamente.
 * La versión anterior reemplazaba contraseñas de administradores
 * por una clave fija; nunca debe estar accesible por HTTP.
 * Utiliza la administración autenticada de usuarios, con auditoría.
 */
declare(strict_types=1);
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
exit('Herramienta de restablecimiento insegura deshabilitada. Contacte a Sistemas.');
