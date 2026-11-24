<?php
declare(strict_types=1);
/** Ejecutar desde CLI local: php scripts/crear_cajero_prueba.php */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo CLI');}
require_once __DIR__.'/../app/config/database.php';
$db=(new Database())->connect();
$actual=$db->query('SELECT DATABASE()')->fetchColumn();
$host=(string)$db->query('SELECT @@hostname')->fetchColumn();
if($actual!=='prueba')exit("ABORTADO: Esta operación solamente permite la base prueba.\n");
$usuario='cajero_prueba';$correo='cajero.prueba@gyd.local';$nombre='Cajero de Pruebas G&D';
$ex=$db->prepare('SELECT id FROM usuarios WHERE usuario=?');$ex->execute([$usuario]);
if($ex->fetch())exit("La cuenta cajero_prueba ya existe. Puedes modificarla desde Usuarios (administrador).\n");
$clave=rtrim(strtr(base64_encode(random_bytes(18)),'+/','-_'),'=');
$hash=password_hash($clave,PASSWORD_DEFAULT);
$st=$db->prepare("INSERT INTO usuarios(nombre,usuario,correo,password,rol,estado,almacen_id) VALUES(?,?,?,?,'CAJERO',1,1)");
$st->execute([$nombre,$usuario,$correo,$hash]);
echo "CAJERO CREADO EN BD prueba (almacén CD Hidalgo, id 1).\n";
echo "Usuario: $usuario\nContraseña generada: $clave\n";
echo "GUÁRDALA AHORA en un lugar privado. No se puede volver a consultar.\n";
echo "Para Tuxtla o cambiar la contraseña, usa el módulo Usuarios con una cuenta administradora.\n";
