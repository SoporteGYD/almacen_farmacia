<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_cxp.php';
requireLogin();
$user=currentUser()??[];
if (!cxp_compras($user)) {http_response_code(403);exit('Sin autorización.');}
$id=(int)($_GET['id']??0);
if($id<1){http_response_code(400);exit('Solicitud inválida.');}
$db=(new Database())->connect();
$st=$db->prepare('SELECT evidencia_archivo,evidencia_mime FROM erp_cxp_aplicaciones WHERE id=?');
$st->execute([$id]);$item=$st->fetch();
if (!$item || !$item['evidencia_archivo']) {http_response_code(404);exit('No existe evidencia para esta operación.');}
$nombre=(string)$item['evidencia_archivo'];
if (!preg_match('/^[a-f0-9]{48}\.(?:pdf|jpg|png|xml)$/D',$nombre)){http_response_code(500);exit('Referencia incorrecta.');}
$ruta=cxp_storage_dir().DIRECTORY_SEPARATOR.$nombre;
if(!is_file($ruta)||!is_readable($ruta)){http_response_code(404);exit('Archivo de evidencia no disponible.');}
$mime=(string)$item['evidencia_mime'];
if(!in_array($mime,['application/pdf','image/jpeg','image/png','application/xml','text/xml'],true)) {http_response_code(500);exit('Tipo de archivo no admitido.');}
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: sandbox');
header('Cache-Control: no-store, private');
header('Content-Disposition: attachment; filename="comprobante_cxp_'.$id.'.'.pathinfo($nombre,PATHINFO_EXTENSION).'"');
header('Content-Type: '.$mime);
header('Content-Length: '.filesize($ruta));
readfile($ruta);
