<?php
declare(strict_types=1);
/** Cortes G&D: cualquier cajero asignado solicita, solo un rol autorizado firma. */
function gyd_corte_rol(mixed $rol): string { return strtoupper(trim((string)$rol)); }
function gyd_corte_permitido(mixed $rol): bool {
    return in_array(gyd_corte_rol($rol), ['GERENTE','DIRECCION','DUEÑO','DUENO','PROPIETARIO','CONTABILIDAD','ADMINISTRADOR','ADMIN'], true);
}
function gyd_corte_exigir(array $usuario): string {
    $rol=gyd_corte_rol($usuario['rol_original']??$usuario['rol']??'');
    if ((int)($usuario['id']??0)<1 || !gyd_corte_permitido($rol))
        throw new DomainException('Solo Gerencia, Dirección, Contabilidad o Administración pueden autorizar cortes de caja.');
    return $rol;
}
function gyd_corte_caja_permitida(PDO $db,array $usuario,int $caja): array {
    gyd_corte_exigir($usuario);
    if ($caja<1) throw new DomainException('Caja inválida.');
    $st=$db->prepare('SELECT c.id,c.sucursal_id,s.nombre sucursal_nombre,c.nombre caja_nombre FROM pos_cajas c JOIN pos_sucursales s ON s.id=c.sucursal_id WHERE c.id=? AND c.activa=1 AND s.activo=1 LIMIT 1');
    $st->execute([$caja]);$fila=$st->fetch(PDO::FETCH_ASSOC);
    if (!$fila) throw new DomainException('La caja no está activa o no existe.');
    return $fila;
}
function gyd_corte_resultado(int $centavos): array {
    return ['tipo'=>$centavos>0?'SOBRANTE':($centavos<0?'FALTANTE':'EXACTO'),
            'importe'=>number_format(abs($centavos)/100,2,'.','')];
}
/** Valida contraseña en servidor; no se guarda ni imprime la clave. */
function gyd_corte_autorizar(PDO $db,array $usuario,int $caja,string $login,string $contrasena): array {
    $bd=(string)$db->query('SELECT DATABASE()')->fetchColumn();
    $port=(int)$db->query('SELECT @@port')->fetchColumn();
    if ($bd!=='prueba' || $port!==3307) throw new DomainException('Autorización permitida únicamente en prueba:3307.');
    $solicitante=(int)($usuario['id']??0);
    $rolSolicitante=gyd_corte_rol($usuario['rol_original']??$usuario['rol']??'');
    if ($solicitante<1 || $caja<1 || ($rolSolicitante!=='CAJERO' && !gyd_corte_permitido($rolSolicitante)))
        throw new DomainException('El perfil no puede solicitar cortes.');
    if ($contrasena==='' || strlen($contrasena)>512) throw new DomainException('La contraseña del autorizador es obligatoria.');
    $login=trim($login);
    if (mb_strlen($login,'UTF-8')>120) throw new DomainException('Identificador de cuenta demasiado largo.');
    $claveIntentada=$rolSolicitante==='CAJERO'?$login:'sesion:#'.$solicitante;
    if ($rolSolicitante==='CAJERO' && $login==='') throw new DomainException('Ingresa el usuario de Gerencia, Dirección, Contabilidad o Administración.');
    $ipHash=hash('sha256',(string)($_SERVER['REMOTE_ADDR']??''));
    $intentos=$db->prepare('SELECT COUNT(*) FROM gyd_cortes_intentos WHERE exitoso=0 AND creado_en>DATE_SUB(NOW(), INTERVAL 15 MINUTE) AND (solicitante_id=? OR usuario_intentado=? OR ip_hash=?)');
    $intentos->execute([$solicitante,$claveIntentada,$ipHash]);
    if ((int)$intentos->fetchColumn()>=5) throw new DomainException('Demasiados intentos de autorización. Espera 15 minutos.');
    if ($rolSolicitante==='CAJERO') {
        $st=$db->prepare('SELECT id,rol,estado,password FROM usuarios WHERE (usuario=? OR correo=?) LIMIT 1');
        $st->execute([$login,$login]);$cuenta=$st->fetch(PDO::FETCH_ASSOC);
    } else {
        if($login!=='') {
            $ident=$db->prepare('SELECT usuario,correo FROM usuarios WHERE id=? LIMIT 1');
            $ident->execute([$solicitante]);$identidad=$ident->fetch(PDO::FETCH_ASSOC);
            if (!$identidad || !in_array($login,[(string)$identidad['usuario'],(string)$identidad['correo']],true))
                throw new DomainException('Para autorizar el corte desde tu sesión, utiliza tu propia cuenta.');
        }
        $st=$db->prepare('SELECT id,rol,estado,password FROM usuarios WHERE id=? LIMIT 1');
        $st->execute([$solicitante]);$cuenta=$st->fetch(PDO::FETCH_ASSOC);
    }
    $rolDb=gyd_corte_rol($cuenta['rol']??'');
    $valida=is_array($cuenta) && (int)($cuenta['estado']??0)===1
        && gyd_corte_permitido($rolDb)
        && ($rolSolicitante==='CAJERO' ? (int)($cuenta['id']??0)!==$solicitante : $rolDb===$rolSolicitante)
        && password_verify($contrasena,(string)($cuenta['password']??''));
    $registro=$db->prepare('INSERT INTO gyd_cortes_intentos(solicitante_id,usuario_intentado,exitoso,ip_hash) VALUES(?,?,?,?)');
    $registro->execute([$solicitante,$claveIntentada,$valida?1:0,$ipHash]);
    if (!$valida) throw new DomainException('Autorización denegada. Verifica las credenciales y el rol del responsable.');
    return ['id'=>(int)$cuenta['id'],'rol'=>$rolDb,'modo'=>$rolSolicitante==='CAJERO'?'CLAVE_SUPERVISOR_POS':'CONTRASENA_SESION'];
}
