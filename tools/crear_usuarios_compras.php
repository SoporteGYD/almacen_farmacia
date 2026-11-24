<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Uso exclusivo desde la terminal.'); }
require_once __DIR__ . '/../app/config/database.php';
$cmd = $argv[1] ?? '--ayuda';
if (!in_array($cmd,['--check','--crear-2'],true)) {
    echo "Uso:\n  php tools/crear_usuarios_compras.php --check\n  php tools/crear_usuarios_compras.php --crear-2\n";exit(1);
}
function preguntar(string $prompt, string $defecto=''): string {
    echo $prompt.($defecto!==''?' ['.$defecto.']':'').': ';
    $v=fgets(STDIN);
    if ($v===false) throw new RuntimeException('No se pudo leer la respuesta del operador.');
    $v=trim($v);
    return $v!==''?$v:$defecto;
}
try {
    $db=(new Database())->connect();
    $col=$db->query("SHOW COLUMNS FROM usuarios LIKE 'rol'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) throw new RuntimeException('No existe la columna rol en usuarios.');
    $type=strtoupper((string)$col['Type']);
    if(str_contains($type,'ENUM(') && !str_contains($type,"'COMPRAS'")) {
        throw new RuntimeException('La columna usuarios.rol no admite COMPRAS. Revisa sql/erp_correos_usuarios.sql y respalda la BD antes de migrar.');
    }
    $db->query('SELECT id,usuario,correo,estado FROM usuarios LIMIT 1');
    echo "OK: conexión y rol COMPRAS disponibles.\n";
    if($cmd==='--check') exit(0);
    echo "Se crearán dos usuarios ACTIVOS. Escribe nombre, usuario y correo REALES.\n";
    $cuentas=[];
    for($i=1;$i<=2;$i++) {
        echo "\nUsuario de Compras #{$i}\n";
        $nombre=preguntar('Nombre completo');
        $login=preguntar('Usuario para iniciar sesión','compras0'.$i);
        $email=preguntar('Correo del empleado');
        if(mb_strlen($nombre)<3 || mb_strlen($nombre)>150 || !preg_match('/^[a-zA-Z0-9._-]{4,60}$/D',$login)
           || !filter_var($email,FILTER_VALIDATE_EMAIL) || mb_strlen($email)>150) {
            throw new RuntimeException('Nombre, usuario o correo inválido. No se creó ninguna cuenta.');
        }
        $cuentas[]=['nombre'=>$nombre,'usuario'=>$login,'correo'=>$email];
    }
    if($cuentas[0]['usuario']===$cuentas[1]['usuario'] || strcasecmp($cuentas[0]['correo'],$cuentas[1]['correo'])===0)
        throw new RuntimeException('Los empleados deben usar credenciales y correos diferentes.');
    $stmt=$db->prepare('SELECT COUNT(*) FROM usuarios WHERE usuario=? OR correo=?');
    foreach($cuentas as $c){$stmt->execute([$c['usuario'],$c['correo']]);if((int)$stmt->fetchColumn()>0)
        throw new RuntimeException('Ya existe el usuario o correo '.$c['usuario'].'. No se creó ninguna cuenta.');}
    echo "\n¿Crear las dos cuentas activas? Escribe CREAR para continuar: ";
    if(trim((string)fgets(STDIN))!=='CREAR'){echo "Cancelado: sin cambios.\n";exit(0);}
    $alfabeto='ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#%';
    $password=function() use($alfabeto): string {$r='';for($i=0;$i<22;$i++)$r.=$alfabeto[random_int(0,strlen($alfabeto)-1)];return $r;};
    $db->beginTransaction();
    $insert=$db->prepare('INSERT INTO usuarios (nombre,usuario,correo,password,rol,estado,almacen_id) VALUES (?,?,?,?,\'COMPRAS\',1,NULL)');
    foreach($cuentas as &$c){$c['password']=$password();$insert->execute([$c['nombre'],$c['usuario'],$c['correo'],password_hash($c['password'],PASSWORD_DEFAULT)]);$c['id']=(int)$db->lastInsertId();}unset($c);
    $db->commit();
    echo "\nCUENTAS CREADAS CORRECTAMENTE. Contraseñas mostradas una sola vez (no se guardan en archivos).\n";
    foreach($cuentas as $c) echo "{$c['nombre']} | usuario: {$c['usuario']} | contraseña: {$c['password']}\n";
    echo "Entrega cada contraseña por separado al empleado, y pide cambiarla con el administrador al primer acceso.\n";
} catch(Throwable $e) {
    if(isset($db) && $db->inTransaction())$db->rollBack();
    fwrite(STDERR,"ERROR: ".$e->getMessage()."\n");exit(1);
}
