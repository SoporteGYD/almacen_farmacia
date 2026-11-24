<?php
declare(strict_types=1);

/** Validación servidor de habilitación. No guarda contraseñas ni modifica el inventario. */
function gyd_numero_caja(string $codigo): ?string {
    if (!preg_match('/(?:^|[^0-9])(\d{1,3})$/', trim($codigo), $m)) return null;
    $n=(int)$m[1];
    return $n>=1 && $n<=999 ? str_pad((string)$n,3,'0',STR_PAD_LEFT) : null;
}
function gyd_habilitar_caja_autorizar(PDO $db, array $sesion, int $cajaId, string $numero, string $clave): string {
    if ((string)$db->query('SELECT DATABASE()')->fetchColumn()!=='prueba') {
        throw new DomainException('La habilitación sólo está disponible en la base de pruebas.');
    }
    $id=(int)($sesion['id']??0);
    if ($id<=0 || $cajaId<=0) throw new DomainException('Sesión o caja inválida.');
    $numero=trim($numero);
    if (!preg_match('/^[0-9]{1,3}$/D',$numero) || (int)$numero<1 || $clave==='' || strlen($clave)>512) {
        throw new DomainException('Escribe el número de caja y tu contraseña de inicio de sesión.');
    }
    $esperado=str_pad((string)(int)$numero,3,'0',STR_PAD_LEFT);
    $q=$db->prepare("SELECT COUNT(*) FROM gyd_apertura_intentos WHERE usuario_id=? AND exitoso=0 AND creado_en >= DATE_SUB(NOW(),INTERVAL 15 MINUTE)");
    $q->execute([$id]);
    if ((int)$q->fetchColumn()>=5) throw new DomainException('Demasiados intentos fallidos; vuelve a intentar dentro de 15 minutos.');
    $q=$db->prepare('SELECT u.id,u.estado,u.rol,u.password FROM usuarios u WHERE u.id=? LIMIT 1');
    $q->execute([$id]);
    $cuenta=$q->fetch(PDO::FETCH_ASSOC);
    $q=$db->prepare('SELECT c.id,c.codigo,c.sucursal_id,c.activa,s.activo AS sucursal_activa FROM pos_cajas c JOIN pos_sucursales s ON s.id=c.sucursal_id WHERE c.id=? LIMIT 1');
    $q->execute([$cajaId]);
    $caja=$q->fetch(PDO::FETCH_ASSOC);
    $rol=strtoupper((string)($cuenta['rol']??''));
    $rolSesion=strtoupper((string)($sesion['rol_original']??$sesion['rol']??''));
    $asignada=true;
    if ($rol==='CAJERO') {
        $q=$db->prepare('SELECT 1 FROM pos_usuario_asignaciones WHERE usuario_id=? AND sucursal_id=? AND caja_id=? LIMIT 1');
        $q->execute([$id,(int)($caja['sucursal_id']??0),$cajaId]);
        $asignada=(bool)$q->fetchColumn();
    }
    $valido=is_array($cuenta) && (int)($cuenta['estado']??0)===1
        && in_array($rol,['CAJERO','ADMINISTRADOR','DIRECCION'],true)
        && $rol===$rolSesion && is_array($caja)
        && (int)$caja['activa']===1 && (int)$caja['sucursal_activa']===1
        && $asignada && gyd_numero_caja((string)$caja['codigo'])===$esperado
        && password_verify($clave,(string)($cuenta['password']??''));
    $q=$db->prepare('INSERT INTO gyd_apertura_intentos(usuario_id,caja_id,exitoso) VALUES(?,?,?)');
    $q->execute([$id,$cajaId,$valido?1:0]);
    if (!$valido) throw new DomainException('Número de caja o contraseña incorrectos. Revisa la asignación con Administración.');
    return $esperado;
}
