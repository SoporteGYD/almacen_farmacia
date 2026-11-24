<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_centro_notificaciones.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
if(!isLoggedIn()){http_response_code(401);echo json_encode(['ok'=>false]);exit;}
requireLogin();
$user=currentUser()?:[];
if(!gdnt_can_read($user)){http_response_code(403);echo json_encode(['ok'=>false]);exit;}
try{$pdo=(new Database())->connect();$counts=gdnt_counts(gdnt_items($pdo,$user));echo json_encode(['ok'=>true,'contadores'=>$counts],JSON_UNESCAPED_UNICODE);}
catch(Throwable $e){error_log('G&D centro API: '.$e->getMessage());http_response_code(503);echo json_encode(['ok'=>false,'mensaje'=>'Sin datos disponibles.']);}
