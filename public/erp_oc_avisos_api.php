<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/helpers/erp_oc_avisos.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
try { $db=(new Database())->connect(); echo json_encode(['ok'=>true,'contadores'=>ocav_conteos($db,currentUser()?:[])],JSON_UNESCAPED_UNICODE); }
catch(Throwable $e){error_log('ERP OC avisos: '.$e->getMessage());http_response_code(503);echo json_encode(['ok'=>false,'error'=>'No fue posible consultar avisos.']);}
