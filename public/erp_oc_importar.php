<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_oc_win.php';
require_once __DIR__.'/../app/helpers/erp_oc_importar.php';
requireLogin();$u=currentUser()?:[];
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
try{
 if(!ocw_can_edit($u))throw new RuntimeException('No tienes permiso para importar artículos.');
 if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new RuntimeException('Método no admitido.');
 ocw_check_token($_POST['csrf']??null);
 $mode=(string)($_POST['accion_importar']??'');
 if($mode==='leer'){
  $result=oci_parse_upload($_FILES['archivo']??[]);
  $heads=$result['rows'][0];$result['hgo']=oci_es_txt_hgo($result['rows']);$result['columns']=array_map(fn($i)=>'Columna '.($i+1),array_keys($heads));
  $result['suggest']=$result['hgo']?['codigo'=>0,'cantidad'=>1,'costo'=>2]:oci_suggest($heads);
  $result['preview']=array_slice($result['rows'],0,7);
  echo json_encode(['ok'=>true]+$result,JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE);exit;
 }
 if($mode==='comparar'){
  $arr=json_decode((string)($_POST['datos']??''),true,32,JSON_THROW_ON_ERROR);
  if(!is_array($arr)||!isset($arr['rows'],$arr['mapping'])||!is_array($arr['rows'])||!is_array($arr['mapping']))throw new RuntimeException('Los datos de importación no son válidos.');
  $r=$arr['rows']; // GYD_COMPARAR_SIN_TOPE_V1
  foreach($r as $row){if(!is_array($row)||count($row)>22)throw new RuntimeException('Fila inválida.');foreach($row as $value)if(!is_scalar($value)||strlen((string)$value)>250)throw new RuntimeException('Dato demasiado largo.');}
  $mapping=[];foreach(['codigo','descripcion','cantidad','costo','cajas','iva','ieps','unidad'] as $k){$val=$arr['mapping'][$k]??null;$mapping[$k]=($val===null||$val==='')?null:filter_var($val,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>21]]);if($mapping[$k]===false)throw new RuntimeException('Columna fuera de rango.');}
  $db=(new Database())->connect();$result=oci_match_rows($db,$r,$mapping);
  echo json_encode(['ok'=>true]+$result,JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE);exit;
 }
 throw new RuntimeException('Operación desconocida.');
}catch(Throwable $e){http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE);}
