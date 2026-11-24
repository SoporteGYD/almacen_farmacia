<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
function lab40_json(array $x,int $status=200):never {
    http_response_code($status);
    echo json_encode($x,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;
}
try {
    $u=currentUser()??[];
    if(!in_array(strtoupper((string)($u['rol']??'')),['ADMINISTRADOR','ADMIN','DIRECCION'],true))
        lab40_json(['error'=>'Sólo Administración/Dirección pueden usar el laboratorio.'],403);
    require_once __DIR__.'/../app/config/database.php';
    require_once __DIR__.'/../app/helpers/gyd_rewards_lab_v40.php';
    $db=(new Database())->connect();GydRewardsLabV40::entorno($db);
    $act=(string)($_GET['accion']??'');
    if($_SERVER['REQUEST_METHOD']==='GET'){
        if($act==='inicial')lab40_json(['sucursales'=>GydRewardsLabV40::sucursales($db),'clientes'=>GydRewardsLabV40::clientes($db),'canje_real_habilitado'=>false]);
        if($act==='buscar')lab40_json(['productos'=>GydRewardsLabV40::buscar($db,(int)($_GET['sucursal_id']??0),(string)($_GET['q']??''))]);
        if($act==='escenarios')lab40_json(['escenarios'=>GydRewardsLabV40::escenarios($db,(int)($_GET['sucursal_id']??0)),'auditoria'=>GydRewardsLabV40::auditoria($db)]);
        if($act==='simular')lab40_json(['simulacion'=>GydRewardsLabV40::simular($db,(int)($_GET['sucursal_id']??0),(int)($_GET['producto_id']??0),(int)($_GET['cliente_id']??0))]);
        lab40_json(['error'=>'Acción desconocida.'],404);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')lab40_json(['error'=>'Método no permitido.'],405);
    $raw=file_get_contents('php://input');
    if($raw===false||strlen($raw)>8000)lab40_json(['error'=>'Contenido demasiado grande.'],413);
    $body=json_decode($raw,true);
    if(!is_array($body))lab40_json(['error'=>'Solicitud JSON inválida.'],400);
    if(!is_string($body['csrf']??null)||empty($_SESSION['pos_csrf'])||
       !hash_equals((string)$_SESSION['pos_csrf'],$body['csrf']))
         lab40_json(['error'=>'Sesión caducada. Actualiza la página.'],403);
    if($act==='guardar')lab40_json(['resultado'=>GydRewardsLabV40::guardar($db,$body,(int)($u['id']??0))]);
    if($act==='sembrar')lab40_json(['resultado'=>GydRewardsLabV40::sembrar($db,(int)($u['id']??0))]);
    if($act==='canjear')lab40_json(['error'=>'CANJE REAL INHABILITADO. Laboratorio de simulación.'],403);
    lab40_json(['error'=>'Acción desconocida.'],404);
} catch(Throwable $e){
    error_log('G&D V40 laboratorio: '.$e->getMessage());
    if($e instanceof DomainException||$e instanceof InvalidArgumentException)lab40_json(['error'=>$e->getMessage()],422);
    lab40_json(['error'=>'Error del laboratorio. Verifica la instalación y consulta el log de Apache.'],500);
}
