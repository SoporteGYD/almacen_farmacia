<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/gyd_alertas_gerenciales.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
function gag_json(int $status,array $arr):never {http_response_code($status);echo json_encode($arr,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
if(!isLoggedIn())gag_json(401,['ok'=>false,'mensaje'=>'La sesión ha caducado.']);
$u=currentUser()?:[];
if(!gag_role($u))gag_json(403,['ok'=>false,'mensaje'=>'Acceso no autorizado.']);
$method=(string)($_SERVER['REQUEST_METHOD']??'GET');
if(!in_array($method,['GET','POST'],true))gag_json(405,['ok'=>false,'mensaje'=>'Método no permitido.']);
$input=$method==='POST'?$_POST:$_GET;
$sid=filter_var($input['sucursal']??'0',FILTER_VALIDATE_INT);
$days=filter_var($input['dias']??'7',FILTER_VALIDATE_INT);
if($sid===false||$sid<0||$days===false||!in_array($days,[7,15,30],true))gag_json(422,['ok'=>false,'mensaje'=>'Filtros inválidos.']);
try{
 $db=(new Database())->connect();
 if($method==='POST'){
  $cs=(string)($_SESSION['gyd_alertas_csrf']??'');
  if($cs===''||!hash_equals($cs,(string)($_POST['csrf']??'')))gag_json(403,['ok'=>false,'mensaje'=>'La sesión de seguridad caducó. Recarga la página.']);
  if(($_POST['accion']??'')!=='atender')gag_json(422,['ok'=>false,'mensaje'=>'Acción inválida.']);
  $result=gag_ack($db,$u,trim((string)($_POST['clave']??'')),$sid,$days);
  gag_json(200,['ok'=>true,'mensaje'=>'Alerta marcada como atendida.','resultado'=>$result]);
 }
 $result=gag_feed($db,$u,$sid,$days);
 gag_json(200,['ok'=>true,'data'=>$result]);
}catch(DomainException $e){gag_json(403,['ok'=>false,'mensaje'=>$e->getMessage()]);}
catch(Throwable $e){error_log('Alertas gerenciales API: '.$e->getMessage());gag_json(500,['ok'=>false,'mensaje'=>'No se pudo consultar el centro de alertas. Revisa el registro de PHP.']);}
