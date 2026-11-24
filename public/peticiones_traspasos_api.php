<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';requireLogin();
require_once __DIR__.'/../app/models/PeticionesTraspasosGyD.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');
function pt_json(int $code,array $data):never{http_response_code($code);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
$u=currentUser()?:[];
if(!PeticionesTraspasosGyD::permitido($u)||(int)($u['id']??0)<1)pt_json(403,['error'=>'Sin permiso.']);
if($_SERVER['REQUEST_METHOD']!=='GET')pt_json(405,['error'=>'Método no permitido.']);
try{
 $m=new PeticionesTraspasosGyD();$o=(int)($_GET['origen']??0);$d=(int)($_GET['destino']??0);
 if(!in_array($o,[1,2,3,4],true)||!in_array($d,[1,2,3,4],true)||$o===$d)throw new DomainException('Selecciona origen y destino diferentes.');
 if(!PeticionesTraspasosGyD::compras($u) && !$m->permitirSucursal($u,$d) && !$m->permitirSucursal($u,$o))throw new DomainException('Consulta fuera de tu sucursal asignada.');
 $op=(string)($_GET['op']??'');
 if($op==='catalogo')pt_json(200,$m->buscar(trim((string)($_GET['q']??'')),$o,$d,(int)($_GET['pagina']??1)));
 if($op==='matriz'){
  $pid=(int)($_GET['producto_id']??0);if($pid<1)throw new DomainException('Producto inválido.');
  $st=$m->db()->prepare('SELECT codigo,descripcion FROM productos WHERE id=? AND estado=1');$st->execute([$pid]);$pr=$st->fetch(PDO::FETCH_ASSOC);if(!$pr)throw new DomainException('Producto no encontrado.');
  pt_json(200,['producto'=>$pr,'hidalgo'=>$m->saldo(1,$pid),'tuxtla'=>$m->saldo(2,$pid),'tapachula_toscana'=>$m->saldo(3,$pid)]);
 }
 if($op==='sugerencias')pt_json(200,['items'=>$m->sugerencias($o,$d),'nota'=>'Recomendación basada en unidades vendidas últimos 30 días y meta de cobertura 21 días. No sustituye revisión física; Tapachula/Toscana muestran saldo referencial compartido.']);
 throw new DomainException('Operación de consulta inválida.');
}catch(DomainException $e){pt_json(422,['error'=>$e->getMessage()]);}
catch(Throwable $e){error_log('Peticiones API: '.$e->getMessage());pt_json(500,['error'=>'No se pudo consultar catálogo o movimientos. Consulta el log PHP.']);}
