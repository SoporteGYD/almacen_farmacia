<?php
declare(strict_types=1);
/** G&D: autorización de corte por Gerencia, Dueños y Administración. Solo base prueba. */
function gyd_corte_rol(mixed $r):string { return strtoupper(trim((string)$r)); }
function gyd_corte_permitido(mixed $r):bool {
    return in_array(gyd_corte_rol($r),['ADMINISTRADOR','ADMIN','DIRECCION','GERENTE','DUEÑO','DUENO','PROPIETARIO'],true);
}
function gyd_corte_resultado(int $centavos):array {
    return ['tipo'=>$centavos>0?'SOBRANTE':($centavos<0?'FALTANTE':'EXACTO'),
            'importe'=>number_format(abs($centavos)/100,2,'.','')];
}
function gyd_corte_autorizar(PDO $db,array $usuario,int $caja,string $login,string $contrasena):array {
    $dbname=(string)$db->query('SELECT DATABASE()')->fetchColumn();
    if($dbname!=='prueba')throw new DomainException('Los cortes autorizados V9 solo se habilitan en la base de pruebas.');
    $actor=(int)($usuario['id']??0);
    if($actor<1 || $caja<1)throw new DomainException('Sesión o caja inválida.');
    $rol=gyd_corte_rol($usuario['rol_original']??$usuario['rol']??'');
    if(gyd_corte_permitido($rol))return ['id'=>$actor,'rol'=>$rol,'modo'=>'SESION_PRIVILEGIADA'];
    if($rol!=='CAJERO')throw new DomainException('Solo Gerencia, Dueños o Administración pueden autorizar cortes.');
    $login=trim($login);
    if($login===''||mb_strlen($login,'UTF-8')>120||$contrasena===''||strlen($contrasena)>512)
        throw new DomainException('Ingresa usuario y contraseña del autorizador.');
    $consulta=$db->prepare('SELECT COUNT(*) FROM gyd_cortes_intentos WHERE solicitante_id=? AND exitoso=0 AND creado_en>DATE_SUB(NOW(),INTERVAL 15 MINUTE)');
    $consulta->execute([$actor]);
    if((int)$consulta->fetchColumn()>=5)throw new DomainException('Demasiados intentos fallidos. Espera 15 minutos o comunica el bloqueo a Administración.');
    $st=$db->prepare('SELECT id,rol,password FROM usuarios WHERE (usuario=? OR correo=?) AND estado=1 LIMIT 1');
    $st->execute([$login,$login]);
    $super=$st->fetch(PDO::FETCH_ASSOC);
    $valido=is_array($super)&&gyd_corte_permitido($super['rol']??'')
        &&(int)($super['id']??0)!==$actor
        &&password_verify($contrasena,(string)($super['password']??''));
    $iphash=hash('sha256',(string)($_SERVER['REMOTE_ADDR']??''));
    $ins=$db->prepare('INSERT INTO gyd_cortes_intentos(solicitante_id,usuario_intentado,exitoso,ip_hash) VALUES(?,?,?,?)');
    $ins->execute([$actor,mb_substr($login,0,120,'UTF-8'),$valido?1:0,$iphash]);
    if(!$valido)throw new DomainException('Autorización denegada. Credenciales de Gerencia, Dueños o Administración inválidas.');
    return ['id'=>(int)$super['id'],'rol'=>gyd_corte_rol($super['rol']),'modo'=>'MODAL_SUPERVISOR'];
}
