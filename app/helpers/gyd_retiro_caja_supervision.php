<?php
declare(strict_types=1);
/**
 * Autorización del retiro por dos vías EXCLUYENTES:
 *  - CAJERO: número de la caja abierta y contraseña del cajero que abrió ese turno.
 *  - SUPERVISOR: usuario y contraseña de GERENTE / DIRECCION / ADMINISTRADOR.
 * Ninguna contraseña se almacena. La firma se asocia al folio mediante el flujo anterior.
 */
require_once __DIR__.'/gyd_retiros_autorizaciones.php';

function gd_retiro_numero_fisico(string $codigo):?string {
    // Compatible con código "CAJA-01", "CAJA-002" o "002".
    if (!preg_match('/(?:^|[^0-9])([0-9]{1,3})$/D', trim($codigo), $m)) return null;
    $numero=(int)$m[1];
    return ($numero>=1 && $numero<=999) ? str_pad((string)$numero,3,'0',STR_PAD_LEFT) : null;
}

function gd_retiro_credencial_por_caja(
    PDO $db,array $operador,int $aperturaId,int $cajaId,
    string $modo,string $numeroCaja,string $login,string $clave
):array {
    gr_test_db($db);
    $operadorId=(int)($operador['id']??0);
    if ($operadorId<1 || $aperturaId<1 || $cajaId<1) throw new DomainException('Sesión, turno o caja inválidos.');
    $modo=strtoupper(trim($modo));
    if (!in_array($modo,['CAJERO','SUPERVISOR'],true))
        throw new DomainException('Selecciona cajero del turno o Gerencia / Dirección / Administración.');
    if ($clave==='' || strlen($clave)>512) throw new DomainException('Escribe la contraseña del usuario autorizador.');

    // No admitir el número de otra caja ni firmas para turnos que ya cerraron.
    $turno=gr_query($db,"SELECT usuario_apertura_id FROM pos_turnos WHERE caja_id=? AND estado='ABIERTO' ORDER BY id DESC LIMIT 1",[$cajaId])->fetch(PDO::FETCH_ASSOC);
    if (!$turno || (int)$turno['usuario_apertura_id']!==$aperturaId)
        throw new DomainException('El turno cambió. Actualiza la caja antes de solicitar el retiro.');
    $caja=gr_query($db,"SELECT codigo FROM pos_cajas WHERE id=? AND activa=1 LIMIT 1",[$cajaId])->fetch(PDO::FETCH_ASSOC);
    if (!$caja) throw new DomainException('La caja no está activa.');


    $cuenta=null;
    $numeroValido=false;
    $login=trim($login);
    if ($modo==='CAJERO') {
        if (!preg_match('/^[0-9]{1,3}$/D',trim($numeroCaja)) || (int)$numeroCaja<1)
            throw new DomainException('Escribe el número de caja asignado al cajero del turno.');
        $numeroValido=(gd_retiro_numero_fisico((string)$caja['codigo'])===str_pad((string)(int)$numeroCaja,3,'0',STR_PAD_LEFT));
        // Identidad fija: NO se permite escribir el nombre de otro cajero.
        $cuenta=gr_query($db,"SELECT id,nombre,usuario,rol,estado,password FROM usuarios WHERE id=? LIMIT 1",[$aperturaId])->fetch(PDO::FETCH_ASSOC);
        $loginAuditoria='CAJA:'.str_pad((string)(int)$numeroCaja,3,'0',STR_PAD_LEFT);
    } else {
        if ($login==='' || mb_strlen($login,'UTF-8')>120)
            throw new DomainException('Escribe el usuario del gerente, dueño o administrador.');
        $cuenta=gr_query($db,"SELECT id,nombre,usuario,rol,estado,password FROM usuarios WHERE (usuario=? OR correo=?) LIMIT 1",[$login,$login])->fetch(PDO::FETCH_ASSOC);
        $loginAuditoria=$login;
    }
    $autorizadorId=is_array($cuenta)?(int)$cuenta['id']:0;
    $rol=is_array($cuenta)?gd_retiro_rol((string)$cuenta['rol']):'';

    $rolValido=$modo==='CAJERO'
      ? $rol==='CAJERO' && $autorizadorId===$aperturaId && $numeroValido
      : in_array($rol,['GERENTE','DIRECCION','ADMINISTRADOR'],true);
    $valido=$autorizadorId>0 && (int)($cuenta['estado']??0)===1 && $rolValido
      && password_verify($clave,(string)($cuenta['password']??''));
    gr_query($db,"INSERT INTO gyd_retiros_intentos_autorizacion(operador_id,autorizador_id,login_intentado,exitoso,ip_hash) VALUES (?,?,?,?,?)",
      [$operadorId,$autorizadorId?:null,mb_substr($loginAuditoria,0,120,'UTF-8'),$valido?1:0,hash('sha256',(string)($_SERVER['REMOTE_ADDR']??''))]);
    if (!$valido) throw new DomainException('Autorización denegada: número de caja, usuario, contraseña o permisos incorrectos. No se registró el retiro.');
    return ['id'=>$autorizadorId,'rol'=>$rol,'modo'=>$modo==='CAJERO'?'CAJERO_CAJA_Y_CLAVE':'SUPERVISOR_USUARIO_Y_CLAVE'];
}
