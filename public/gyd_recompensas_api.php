<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
function gyd_v38_reply(array $data,int $http=200):never {
    http_response_code($http);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;
}
try {
    $u=currentUser()??[];
    $rol=strtoupper((string)($u['rol']??''));
    if(!in_array($rol,['ADMINISTRADOR','ADMIN','DIRECCION'],true))gyd_v38_reply(['error'=>'Permiso restringido a Administración/Dirección.'],403);
    require_once __DIR__.'/../app/config/database.php';
    require_once __DIR__.'/../app/helpers/gyd_rewards_catalogo_v38.php';
    $db=(new Database())->connect();GydRewardsCatalogoV38::laboratorio($db);
    $accion=(string)($_GET['accion']??'');
    if($_SERVER['REQUEST_METHOD']==='GET') {
        if($accion==='sucursales')gyd_v38_reply(['sucursales'=>GydRewardsCatalogoV38::sucursales($db)]);
        if($accion==='buscar')gyd_v38_reply(['productos'=>GydRewardsCatalogoV38::buscarProductos($db,(int)($_GET['sucursal_id']??0),(string)($_GET['q']??''))]);
        if($accion==='evaluar')gyd_v38_reply(['producto'=>GydRewardsCatalogoV38::evaluarProducto($db,(int)($_GET['sucursal_id']??0),(int)($_GET['producto_id']??0))]);
        if($accion==='catalogo')gyd_v38_reply(['catalogo'=>GydRewardsCatalogoV38::listado($db,(int)($_GET['sucursal_id']??0)),'canje_habilitado'=>false]);
        if($accion==='auditoria')gyd_v38_reply(['movimientos'=>GydRewardsCatalogoV38::auditoria($db,(int)($_GET['id']??0))]);
        gyd_v38_reply(['error'=>'Acción desconocida.'],404);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')gyd_v38_reply(['error'=>'Método no permitido.'],405);
    $raw=file_get_contents('php://input');
    if($raw===false||strlen($raw)>20000)gyd_v38_reply(['error'=>'Solicitud demasiado grande.'],413);
    $body=json_decode($raw,true);
    if(!is_array($body))gyd_v38_reply(['error'=>'JSON inválido.'],400);
    $token=$body['csrf']??null;
    if(!is_string($token)||empty($_SESSION['pos_csrf'])||!hash_equals((string)$_SESSION['pos_csrf'],$token))
        gyd_v38_reply(['error'=>'Sesión expirada; recarga el formulario.'],403);
    if($accion==='guardar')gyd_v38_reply(['resultado'=>GydRewardsCatalogoV38::guardar($db,$body,(int)($u['id']??0))]);
    if($accion==='pausar')gyd_v38_reply(['resultado'=>GydRewardsCatalogoV38::pausar($db,(int)($body['id']??0),(string)($body['motivo']??''),(int)($u['id']??0))]);
    gyd_v38_reply(['error'=>'Acción desconocida.'],404);
} catch(Throwable $e) {
    error_log('G&D Catálogo Rewards V38: '.$e->getMessage());
    if($e instanceof DomainException || $e instanceof InvalidArgumentException)gyd_v38_reply(['error'=>$e->getMessage()],422);
    gyd_v38_reply(['error'=>'Error del catálogo de recompensas. Consulta el log del servidor y verifica la instalación.'],500);
}
