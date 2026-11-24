<?php
declare(strict_types=1);
/** Validación de acceso previo: se guarda exclusivamente en la sesión PHP. */
function gyd_gate_guardar(string $operacion,array $usuario,int $caja,int $turno,array $autorizador):void {
    if(!in_array($operacion,['retiro','corte'],true))throw new DomainException('Operación no permitida.');
    if(session_status()!==PHP_SESSION_ACTIVE)throw new DomainException('La sesión no está disponible.');
    if((int)($usuario['id']??0)<1||$caja<1||$turno<1|| (int)($autorizador['id']??0)<1)
        throw new DomainException('Identidad o caja inválida.');
    $_SESSION['gyd_gate_'.$operacion]=[
        'usuario_id'=>(int)$usuario['id'],'caja_id'=>$caja,'turno_id'=>$turno,
        'autorizador_id'=>(int)$autorizador['id'],'rol'=>(string)($autorizador['rol']??''),
        'modo'=>(string)($autorizador['modo']??'MODAL_PREVIO'),
        'expira'=>time()+($operacion==='corte'?600:180),
    ];
}
function gyd_gate_validar(string $operacion,array $usuario,int $caja,int $turno):array {
    if(!in_array($operacion,['retiro','corte'],true)||session_status()!==PHP_SESSION_ACTIVE)
        throw new DomainException('Acceso previo no disponible.');
    $registro=$_SESSION['gyd_gate_'.$operacion]??null;
    if(!is_array($registro) || (int)($registro['expira']??0)<=time() ||
       (int)($registro['usuario_id']??0)!==(int)($usuario['id']??0) ||
       (int)($registro['caja_id']??0)!==$caja ||
       (int)($registro['turno_id']??0)!==$turno) {
        unset($_SESSION['gyd_gate_'.$operacion]);
        throw new DomainException('La autorización expiró o no corresponde a este turno. Regresa al POS y confirma tu contraseña en el modal.');
    }
    return ['id'=>(int)$registro['autorizador_id'],'rol'=>(string)$registro['rol'],
            'modo'=>(string)$registro['modo']];
}
function gyd_gate_limpiar(string $operacion):void {
    if(in_array($operacion,['retiro','corte'],true))unset($_SESSION['gyd_gate_'.$operacion]);
}
