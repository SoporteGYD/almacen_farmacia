<?php
declare(strict_types=1);
/** Autorizacion de cortes: solo la sesion titular de Gerencia, Direccion, Contabilidad o Administracion. */
function gyd_corte_rol(mixed $rol): string { return strtoupper(trim((string)$rol)); }
function gyd_corte_permitido(mixed $rol): bool {
    return in_array(gyd_corte_rol($rol), ['GERENTE','DIRECCION','DUEÑO','DUENO','PROPIETARIO','CONTABILIDAD','ADMINISTRADOR','ADMIN'], true);
}
function gyd_corte_exigir(array $usuario): string {
    $rol = gyd_corte_rol($usuario['rol_original'] ?? $usuario['rol'] ?? '');
    if ((int)($usuario['id'] ?? 0) < 1 || !gyd_corte_permitido($rol)) {
        throw new DomainException('Solo Gerencia, Dirección, Contabilidad o Administración puede realizar cortes de caja.');
    }
    return $rol;
}
function gyd_corte_caja_permitida(PDO $db, array $usuario, int $caja): array {
    gyd_corte_exigir($usuario);
    if ($caja < 1) throw new DomainException('Caja inválida.');
    $st = $db->prepare('SELECT c.id,c.sucursal_id,s.nombre sucursal_nombre,c.nombre caja_nombre FROM pos_cajas c JOIN pos_sucursales s ON s.id=c.sucursal_id WHERE c.id=? AND c.activa=1 AND s.activo=1 LIMIT 1');
    $st->execute([$caja]);
    $fila = $st->fetch(PDO::FETCH_ASSOC);
    if (!$fila) throw new DomainException('La caja no está activa o no existe.');
    return $fila;
}
function gyd_corte_resultado(int $centavos): array {
    return ['tipo'=>$centavos>0?'SOBRANTE':($centavos<0?'FALTANTE':'EXACTO'),
            'importe'=>number_format(abs($centavos)/100,2,'.','')];
}
/** Una sola clave: la de la cuenta que inició sesión. Nunca aceptar la clave de un tercero desde la sesión del cajero. */
function gyd_corte_autorizar(PDO $db, array $usuario, int $caja, string $login, string $contrasena): array {
    if ((string)$db->query('SELECT DATABASE()')->fetchColumn() !== 'prueba')
        throw new DomainException('Los cortes de este módulo solo se habilitan en la base prueba.');
    $rol = gyd_corte_exigir($usuario);
    gyd_corte_caja_permitida($db, $usuario, $caja);
    $id = (int)$usuario['id'];
    if ($contrasena === '' || strlen($contrasena) > 512)
        throw new DomainException('Ingresa tu propia contraseña para realizar el corte.');
    if (trim($login) !== '') {
        // El campo usuario heredado no puede utilizarse para suplantar otra cuenta.
        $st = $db->prepare('SELECT usuario,correo FROM usuarios WHERE id=? LIMIT 1');
        $st->execute([$id]);
        $identidad = $st->fetch(PDO::FETCH_ASSOC);
        if (!$identidad || !in_array(trim($login),[(string)$identidad['usuario'],(string)$identidad['correo']],true))
            throw new DomainException('Para el corte utiliza únicamente tu cuenta iniciada.');
    }
    $st = $db->prepare('SELECT COUNT(*) FROM gyd_cortes_intentos WHERE solicitante_id=? AND exitoso=0 AND creado_en>DATE_SUB(NOW(),INTERVAL 15 MINUTE)');
    $st->execute([$id]);
    if ((int)$st->fetchColumn() >= 5) throw new DomainException('Demasiados intentos. Intenta nuevamente después de 15 minutos.');
    $st = $db->prepare('SELECT estado,rol,password FROM usuarios WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $cuenta = $st->fetch(PDO::FETCH_ASSOC);
    $rolDb = gyd_corte_rol($cuenta['rol'] ?? '');
    $valido = is_array($cuenta) && (int)($cuenta['estado'] ?? 0)===1
        && gyd_corte_permitido($rolDb) && ($rolDb === $rol)
        && password_verify($contrasena,(string)($cuenta['password'] ?? ''));
    $st = $db->prepare('INSERT INTO gyd_cortes_intentos(solicitante_id,usuario_intentado,exitoso,ip_hash) VALUES(?,?,?,?)');
    $st->execute([$id,'sesion:#'.$id,$valido?1:0,hash('sha256',(string)($_SERVER['REMOTE_ADDR']??''))]);
    if (!$valido) throw new DomainException('No se autorizó el corte. Verifica tu contraseña o tus permisos.');
    return ['id'=>$id,'rol'=>$rolDb,'modo'=>'CONTRASENA_SESION'];
}
