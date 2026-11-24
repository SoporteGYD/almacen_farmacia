<?php
declare(strict_types=1);
/** Arqueos POS G&D: permiso secundario, conteo ciego y sin ajustes. */
function arq_h(mixed $v):string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function arq_roles():array { return ['ADMINISTRADOR','DIRECCION','GERENTE','CONTABILIDAD']; }
function arq_rol_valido(string $rol):bool { return in_array(strtoupper(trim($rol)),arq_roles(),true); }
function arq_csrf():string {
 if(!isset($_SESSION['gyd_arqueo_csrf'])) $_SESSION['gyd_arqueo_csrf']=bin2hex(random_bytes(32));
 return (string)$_SESSION['gyd_arqueo_csrf'];
}
function arq_csrf_check(mixed $csrf):void {
 if(!is_string($csrf) || !hash_equals(arq_csrf(),$csrf))throw new DomainException('Sesión de autorización vencida. Vuelve a intentarlo.');
}
function arq_denominaciones():array {
 // Claves únicas: el billete de $20 y la moneda de $20 son distintos.
 return ['b1000'=>100000,'b500'=>50000,'b200'=>20000,'b100'=>10000,'b50'=>5000,'b20'=>2000,'m20'=>2000,'m10'=>1000,'m5'=>500,'m2'=>200,'m1'=>100,'m050'=>50,'m020'=>20,'m010'=>10];
}
function arq_total_denominaciones(array $captura):array {
 $sum=0;$detalle=[];
 foreach(arq_denominaciones() as $clave=>$centavos){
  $raw=$captura[$clave]??'0';
  if(!is_scalar($raw) || !preg_match('/^\d{1,6}$/D',(string)$raw))throw new DomainException('Cantidad inválida en '.$clave.'. Usa números enteros no negativos.');
  $cantidad=(int)$raw;
  if($cantidad>100000)throw new DomainException('Cantidad excesiva en '.$clave.'.');
  $sum += $centavos*$cantidad;
  if($sum > 999999999999)throw new DomainException('Conteo superior al máximo permitido.');
  $detalle[$clave]=$cantidad;
 }
 return ['centavos'=>$sum,'detalle'=>$detalle];
}
function arq_grant_valido(array $grant,int $solicitante,int $caja,int $turno,int $ahora):bool {
 return (int)($grant['solicitante']??0)===$solicitante
  && (int)($grant['caja']??0)===$caja
  && (int)($grant['turno']??0)===$turno
  && (int)($grant['vence']??0)>=$ahora
  && (int)($grant['autorizador']??0)>0
  && arq_rol_valido((string)($grant['rol']??''));
}
function arq_db(PDO $db,string $sql,array $p=[]):PDOStatement {$s=$db->prepare($sql);$s->execute($p);return $s;}
function arq_comprobar_tablas(PDO $db):void {
 if((string)$db->query('SELECT DATABASE()')->fetchColumn()!=='prueba')throw new DomainException('Arqueos habilitados únicamente para base prueba.');
 foreach(['erp_caja_arqueos','gyd_arqueos_autorizaciones','gyd_arqueos_intentos'] as $t){
  $stmt=arq_db($db,'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',[$t]);
  if(!$stmt->fetchColumn())throw new RuntimeException('Falta tabla de arqueos '.$t.'. Ejecuta la instalación.');
 }
}
function arq_credenciales(PDO $db,string $login,string $password):?array {
 if($login==='' || $password==='')return null;
 $user=arq_db($db,'SELECT id,nombre,rol,password FROM usuarios WHERE (usuario=? OR correo=?) AND estado=1 LIMIT 1',[$login,$login])->fetch(PDO::FETCH_ASSOC);
 if(!$user || !arq_rol_valido((string)$user['rol']) || !password_verify($password,(string)$user['password']))return null;
 return $user;
}
