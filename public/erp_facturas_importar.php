<?php
declare(strict_types=1);
/** Solo vista previa: no registra facturas, movimientos ni costos. */
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_facturas.php';
require_once __DIR__.'/../app/helpers/erp_oc_importar.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
 $usuario=currentUser()?:[];
 if(!ef_puede_capturar($usuario))throw new RuntimeException('Solo Compras o Administración pueden importar facturas.');
 if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new RuntimeException('Método no permitido.');
 ef_verify_csrf((string)($_POST['csrf']??''));
 $data=oci_parse_upload($_FILES['archivo']??[]);
 $rows=$data['rows'];$format=oci_es_txt_hgo($rows);
 if($format){$first=0;$codeCol=0;$quantityCol=1;$costCol=2;}
 else {
  $head=oci_suggest($rows[0]);
  if(!isset($head['codigo'],$head['cantidad'],$head['costo']))throw new RuntimeException('Formato no reconocido en Facturas. Usa Código,Cantidad,Costo sin encabezados (G&D) o una tabla con títulos Código, Cantidad y Costo.');
  $first=1;$codeCol=$head['codigo'];$quantityCol=$head['cantidad'];$costCol=$head['costo'];
 }
 if(count($rows)-$first>200)throw new RuntimeException('El documento supera 200 filas. Divídelo en dos archivos antes de importar.');
 $db=(new Database())->connect();
 $query=$db->prepare('SELECT id,codigo,descripcion FROM productos WHERE estado=1 AND (codigo=? OR codigo_barras=?) LIMIT 3');
 $items=[];$ready=0;$revision=0;$known=[];$count=0;
 foreach(array_slice($rows,$first,200) as $line){
  $count++;$code=trim((string)($line[$codeCol]??''));$rawQty=trim((string)($line[$quantityCol]??''));$rawCost=trim((string)($line[$costCol]??''));
  $qty=oci_numeric($rawQty,0);$qty4=oci_numeric($rawQty,4);$cost=oci_numeric($rawCost,4);
  $base=['codigo'=>$code,'descripcion'=>'','qty'=>null,'costo_original'=>$rawCost,'ok'=>false,'status'=>''];
  if($code===''||strlen($code)>40||$qty===null||$qty<1||$qty>10000000||$qty4===null||abs($qty-$qty4)>0.00001||$cost===null||$cost<0||$cost>99999999){$base['status']='Código, cantidad o costo no válidos';$items[]=$base;$revision++;continue;}
  $query->execute([$code,$code]);$found=$query->fetchAll(PDO::FETCH_ASSOC);
  if(count($found)!==1){$base['status']=count($found)===0?'Código no encontrado en Catálogo Maestro':'Código ambiguo';$items[]=$base;$revision++;continue;}
  $prod=$found[0];$id=(int)$prod['id'];$base['descripcion']=$prod['descripcion'];
  if(isset($known[$id])){$base['status']='Producto repetido dentro del archivo';$items[]=$base;$revision++;continue;}
  $known[$id]=true;
  $base['ok']=true;$base['id']=$id;$base['codigo']=$prod['codigo'];$base['qty']=(int)$qty;$base['costo']=$cost;
  $base['status']=preg_match('/\\.[0-9]{5,}$/D',$rawCost)?'Coincidencia exacta; costo redondeado a 4 decimales':'Coincidencia exacta';
  $items[]=$base;$ready++;
 }
 echo json_encode(['ok'=>true,'items'=>$items,'total'=>$count,'revision'=>$revision],JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE);
} catch(Throwable $e){http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE);}
