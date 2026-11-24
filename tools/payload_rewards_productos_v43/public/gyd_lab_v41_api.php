<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store,private');
header('X-Content-Type-Options: nosniff');
function gd41_json(array $data,int $status=200):never {
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;
}
try {
    $user=currentUser()??[];
    if(!in_array(strtoupper((string)($user['rol']??'')),['ADMINISTRADOR','ADMIN','DIRECCION'],true))
        gd41_json(['error'=>'Acceso exclusivo para Administración/Dirección.'],403);
    require_once __DIR__.'/../app/config/database.php';
    require_once __DIR__.'/../app/helpers/gyd_rewards_lab_v43.php';
    $db=(new Database())->connect();GydRewardsLabV40::entorno($db);
    $accion=(string)($_GET['accion']??'');
    if(($_SERVER['REQUEST_METHOD']??'')==='GET'){
        if($accion==='inicial')gd41_json(['sucursales'=>GydRewardsLabV40::sucursales($db),'clientes'=>GydRewardsLabV41::clientes($db),'virtual'=>true]);
        if($accion==='premios')gd41_json(['premios'=>GydRewardsLabV43::premios($db,(int)($_GET['sucursal_id']??0))]);
        if($accion==='historial')gd41_json(['historial'=>GydRewardsLabV41::historial($db),'total'=>GydRewardsLabV41::totalCanjes($db)]);
        gd41_json(['error'=>'Acción desconocida.'],404);
    }
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')gd41_json(['error'=>'Método no permitido.'],405);
    $raw=file_get_contents('php://input');
    if($raw===false||strlen($raw)>4000)gd41_json(['error'=>'Solicitud demasiado grande.'],413);
    $data=json_decode($raw,true);
    if(!is_array($data))gd41_json(['error'=>'JSON inválido.'],400);
    if(!is_string($data['csrf']??null)||empty($_SESSION['pos_csrf'])||!hash_equals((string)$_SESSION['pos_csrf'],$data['csrf']))
        gd41_json(['error'=>'Sesión vencida. Recarga la página.'],403);
    $actor=(int)($user['id']??0);
    if($accion==='sembrar_v43')gd41_json(['resultado'=>GydRewardsLabV43::agregar($db,(int)($data['sucursal_id']??0),$actor,20)]);
    if($accion==='agregar_aleatorios')gd41_json(['resultado'=>GydRewardsLabV41::agregarAleatorios($db,(int)($data['sucursal_id']??0),$actor)]);
    if($accion==='canjear_virtual')gd41_json(['resultado'=>GydRewardsLabV41::canjear($db,$data,$actor)]);
    gd41_json(['error'=>'Acción desconocida.'],404);
} catch(Throwable $e){
    error_log('G&D V41 laboratorio: '.$e->getMessage());
    if($e instanceof DomainException||$e instanceof InvalidArgumentException)gd41_json(['error'=>$e->getMessage()],422);
    gd41_json(['error'=>'Error del laboratorio V41. Comprueba la instalación y revisa el log de PHP.'],500);
}
