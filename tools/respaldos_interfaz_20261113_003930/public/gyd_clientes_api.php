<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/gyd_clientes_rewards.php';
function respuesta(array $v,int $code=200):never {http_response_code($code);echo json_encode($v,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
try {
    $u=currentUser()??[];$rol=strtoupper((string)($u['rol']??''));
    if(!in_array($rol,['ADMINISTRADOR','DIRECCION','CAJERO'],true))respuesta(['error'=>'No autorizado'],403);
    $db=(new Database())->connect();
    // Defensa adicional: esta fase sólo habilita la BD de laboratorio.
    $ctx=$db->query('SELECT DATABASE() AS base,@@port AS puerto')->fetch(PDO::FETCH_ASSOC);
    if(strtolower((string)($ctx['base']??''))!=='prueba'||(int)($ctx['puerto']??0)!==3307)respuesta(['error'=>'Módulo V36 exclusivo para prueba:3307'],403);
    $action=(string)($_GET['action']??'');
    if($_SERVER['REQUEST_METHOD']==='GET'){
        if($action==='buscar')respuesta(['clientes'=>GydClientesRewards::buscar($db,(string)($_GET['q']??''))]);
        if($action==='recientes')respuesta(['clientes'=>GydClientesRewards::recientes($db)]);
        if($action==='ver')respuesta(['cliente'=>GydClientesRewards::obtener($db,(int)($_GET['id']??0))]);
        if($action==='pendientes'){
            if(!in_array($rol,['ADMINISTRADOR','DIRECCION'],true))respuesta(['error'=>'Sin autorización'],403);
            respuesta(['pendientes'=>GydRewardsReversionesV37::pendientes($db,20)]);
        }
        respuesta(['error'=>'Acción inválida'],404);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')respuesta(['error'=>'Método no permitido'],405);
    $body=json_decode((string)file_get_contents('php://input'),true);
    if(!is_array($body))respuesta(['error'=>'JSON incorrecto'],400);
    if(empty($_SESSION['pos_csrf']) || !is_string($body['csrf']??null) || !hash_equals((string)$_SESSION['pos_csrf'],$body['csrf']))respuesta(['error'=>'Sesión vencida o token inválido'],403);
    if($action==='crear')respuesta(['cliente'=>GydClientesRewards::crear($db,$body,(int)$u['id'])]);
    if($action==='editar'){
        if(!in_array($rol,['ADMINISTRADOR','DIRECCION'],true))respuesta(['error'=>'La edición requiere Administración o Dirección'],403);
        respuesta(['cliente'=>GydClientesRewards::editar($db,(int)($body['id']??0),$body)]);
    }
    if($action==='vincular'){
        require_once __DIR__.'/../app/models/PuntoVenta.php';
        $caja=(int)($body['caja_id']??0);$pos=new PuntoVenta();$pos->cajaAutorizada($u,$caja);
        $id=isset($body['cliente_id'])&&(int)$body['cliente_id']>0?(int)$body['cliente_id']:null;
        if($id!==null)GydClientesRewards::validar($db,$id);
        GydClientesRewards::vincularSesion((string)($body['key']??''),$caja,$id);
        respuesta(['ok'=>true,'cliente_id'=>$id]);
    }
    respuesta(['error'=>'Acción inválida'],404);
}catch(Throwable $e){
    error_log('G&D Clientes V37: '.$e->getMessage());
    $publico=$e instanceof DomainException || $e instanceof InvalidArgumentException;
    respuesta(['error'=>$publico?$e->getMessage():'Error interno de clientes. Revisa migración e instalación.'],$publico?422:500);
}
