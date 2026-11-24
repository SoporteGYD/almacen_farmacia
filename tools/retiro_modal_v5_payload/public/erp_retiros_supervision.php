<?php
 declare(strict_types=1);
 require_once __DIR__.'/../app/helpers/auth.php';requireLogin();
 if($_SERVER['REQUEST_METHOD']!=='GET'){
  http_response_code(405);header('Allow: GET');exit('El retiro de efectivo ya no requiere confirmación ni verificación en el sistema.');
 }
 header('Location: erp_cortes_boveda.php',true,303);exit;
 