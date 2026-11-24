<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
requireLogin();
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);header('Allow: GET');exit('Este formulario fue sustituido por Catálogo Maestro. No se modificó el conteo.');}
header('Location: erp_catalogo_maestro.php',true,302);exit;
