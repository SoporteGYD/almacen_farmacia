<?php
declare(strict_types=1);
/** Autorización individual del retiro. No guardar ni imprimir contraseñas. */
function gd_retiro_rol(string $rol):string {
    $r=strtoupper(trim($rol));
    return match ($r) {
        'ADMIN' => 'ADMINISTRADOR',
        'DUEÑO','DUENO','PROPIETARIO' => 'DIRECCION',
        default => $r,
    };
}
function gd_retiro_autorizador_permitido(string $rol,int $id,int $cajeroApertura):bool {
    $r=gd_retiro_rol($rol);
    return in_array($r,['GERENTE','ADMINISTRADOR','DIRECCION'],true)
        || ($r==='CAJERO' && $id===$cajeroApertura);
}
function gd_retiro_credencial(PDO $db,array $operador,int $cajeroApertura,string $login,string $clave):array {
    gr_test_db($db);
    $operadorId=(int)($operador['id']??0);
    if($operadorId<1 || $cajeroApertura<1)throw new DomainException('Operador o turno inválido.');
    $login=trim($login);
    if($clave==='' || strlen($clave)>512 || mb_strlen($login,'UTF-8')>120)
        throw new DomainException('Ingresa usuario y contraseña válidos.');
    $n=(int)gr_query($db,'SELECT COUNT(*) FROM gyd_retiros_intentos_autorizacion WHERE operador_id=? AND exitoso=0 AND creado_en>DATE_SUB(NOW(),INTERVAL 15 MINUTE)',[$operadorId])->fetchColumn();
    if($n>=5)throw new DomainException('Demasiados intentos fallidos. Vuelve a intentar en 15 minutos.');
    // Sin nombre: la contraseña corresponde a la propia sesión (cajero de apertura o directivo).
    $autor=$login===''?
        gr_query($db,'SELECT id,nombre,rol,estado,password FROM usuarios WHERE id=? LIMIT 1',[$operadorId])->fetch(PDO::FETCH_ASSOC):
        gr_query($db,'SELECT id,nombre,rol,estado,password FROM usuarios WHERE (usuario=? OR correo=?) LIMIT 1',[$login,$login])->fetch(PDO::FETCH_ASSOC);
    $autorId=is_array($autor)?(int)$autor['id']:0;
    $rol=is_array($autor)?gd_retiro_rol((string)$autor['rol']):'';
    // Evita que una cuenta de cajero distinta de quien abrió el turno firme el retiro.
    $ok=$autorId>0 && (int)($autor['estado']??0)===1
       && gd_retiro_autorizador_permitido($rol,$autorId,$cajeroApertura)
       && password_verify($clave,(string)($autor['password']??''));
    // Un autorizador bloqueado no puede ser probado desde múltiples cajas para evadir el límite.
    if($autorId>0){
        $fallos=(int)gr_query($db,'SELECT COUNT(*) FROM gyd_retiros_intentos_autorizacion WHERE autorizador_id=? AND exitoso=0 AND creado_en>DATE_SUB(NOW(),INTERVAL 15 MINUTE)',[$autorId])->fetchColumn();
        if($fallos>=5)throw new DomainException('Esta cuenta autorizadora está temporalmente bloqueada para retiros. Espera 15 minutos.');
    }
    // Cada intento queda asociado al operador, y cada firma exitosa a una cuenta concreta.
    gr_query($db,'INSERT INTO gyd_retiros_intentos_autorizacion(operador_id,autorizador_id,login_intentado,exitoso,ip_hash) VALUES (?,?,?,?,?)',
       [$operadorId,$autorId?:null,mb_substr($login!==''?$login:'SESION',0,120,'UTF-8'),$ok?1:0,hash('sha256',(string)($_SERVER['REMOTE_ADDR']??''))]);
    if(!$ok)throw new DomainException('Autorización denegada: usuario, contraseña o permiso incorrecto. No se registró ningún retiro.');
    return ['id'=>$autorId,'rol'=>$rol,'modo'=>$login===''?'CONTRASENA_SESION':'CONTRASENA_AUTORIZADOR'];
}
function gd_retiro_firma_vigente(PDO $db,array $gate,int $opener):void {
    $id=(int)($gate['id']??0);
    $u=gr_query($db,'SELECT id,estado,rol FROM usuarios WHERE id=? LIMIT 1',[$id])->fetch(PDO::FETCH_ASSOC);
    if(!$u || (int)$u['estado']!==1 || !gd_retiro_autorizador_permitido((string)$u['rol'],$id,$opener)
        || gd_retiro_rol((string)$u['rol'])!==gd_retiro_rol((string)($gate['rol']??'')))
        throw new DomainException('La autorización ya no es válida. Confirma nuevamente con una cuenta permitida.');
}
