<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
function reply39(array $r,int $status=200):never {
    http_response_code($status);
    echo json_encode($r,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;
}
try {
    $u=currentUser()??[];
    $rol=strtoupper((string)($u['rol']??''));
    if(!in_array($rol,['ADMINISTRADOR','ADMIN','DIRECCION','CAJERO'],true))reply39(['error'=>'No tienes permiso para preparar solicitudes de recompensas.'],403);
    require_once __DIR__.'/../app/config/database.php';
    require_once __DIR__.'/../app/helpers/gyd_rewards_precanje_v39.php';
    $db=(new Database())->connect();GydRewardsCatalogoV38::laboratorio($db);
    $act=(string)($_GET['accion']??'');
    if($_SERVER['REQUEST_METHOD']==='GET'){
        if($act==='cajas')reply39(['cajas'=>GydRewardsPrecanjeV39::cajasAutorizadas($u),'canje_real_habilitado'=>false]);
        if($act==='buscar_cliente')reply39(['clientes'=>GydRewardsPrecanjeV39::buscarClientes($db,(string)($_GET['q']??''))]);
        if($act==='ofertas')reply39(GydRewardsPrecanjeV39::ofertas($db,$u,(int)($_GET['caja_id']??0)));
        if($act==='cotizar')reply39(['cotizacion'=>GydRewardsPrecanjeV39::cotizar($db,$u,(int)($_GET['caja_id']??0),(int)($_GET['cliente_id']??0),(int)($_GET['catalogo_id']??0))]);
        if($act==='solicitudes')reply39(['solicitudes'=>GydRewardsPrecanjeV39::solicitudes($db,$u,(int)($_GET['caja_id']??0))]);
        reply39(['error'=>'Acción desconocida.'],404);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')reply39(['error'=>'Método no permitido.'],405);
    $raw=file_get_contents('php://input');
    if($raw===false || strlen($raw)>10000)reply39(['error'=>'Solicitud demasiado extensa.'],413);
    $body=json_decode($raw,true);
    if(!is_array($body))reply39(['error'=>'JSON inválido.'],400);
    $token=$body['csrf']??null;
    if(!is_string($token)||empty($_SESSION['pos_csrf'])||!hash_equals((string)$_SESSION['pos_csrf'],$token))
        reply39(['error'=>'La sesión cambió o expiró. Recarga la página.'],403);
    if($act==='solicitar')reply39(['resultado'=>GydRewardsPrecanjeV39::solicitar($db,$u,$body)]);
    if($act==='cancelar')reply39(['resultado'=>GydRewardsPrecanjeV39::cancelar($db,$u,(int)($body['id']??0),(string)($body['motivo']??''))]);
    if($act==='canjear')reply39(['error'=>'CANJE REAL INHABILITADO. No está disponible en este módulo.'],403);
    reply39(['error'=>'Acción desconocida.'],404);
} catch(Throwable $e){
    error_log('G&D V39 Precanje: '.$e->getMessage());
    if($e instanceof DomainException || $e instanceof InvalidArgumentException)reply39(['error'=>$e->getMessage()],422);
    reply39(['error'=>'No fue posible consultar el módulo de recompensas. Verifica la instalación y el registro del servidor.'],500);
}
