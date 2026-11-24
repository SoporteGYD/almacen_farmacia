<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/gyd_retiros.php';
requireLogin();header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
$u=currentUser()?:[];
if(!in_array(gr_role($u),['CAJERO','ADMINISTRADOR','ADMIN','DIRECCION'],true)){http_response_code(403);echo json_encode(['error'=>'No autorizado']);exit;}
try{
 $db=(new Database())->connect();gr_test_db($db);$caja=(int)($_GET['caja_id']??0);$info=gr_permission_caja($db,$u,$caja);
 $t=gr_query($db,"SELECT id,usuario_apertura_id FROM pos_turnos WHERE caja_id=? AND estado='ABIERTO' ORDER BY id DESC LIMIT 1",[$caja])->fetch(PDO::FETCH_ASSOC);
 if(!$t){echo json_encode(['abierto'=>false,'alerta'=>false]);exit;}
 if(!gr_admin($u)&&(int)$t['usuario_apertura_id']!==(int)$u['id'])throw new DomainException('Turno ajeno.');
 $c=gr_expected($db,(int)$t['id']);$pend=(bool)gr_query($db,"SELECT id FROM erp_retiros_supervisados WHERE turno_id=? AND estado='SOLICITADO' LIMIT 1",[(int)$t['id']])->fetchColumn();
 echo json_encode(['abierto'=>true,'alerta'=>gr_sugerencia($c['esperado'])>0,'pendiente'=>$pend,'esperado'=>gr_pesos($c['esperado']),'sugerido'=>gr_pesos(gr_sugerencia($c['esperado']))],JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){http_response_code(400);echo json_encode(['error'=>$e instanceof DomainException?$e->getMessage():'No se pudo verificar efectivo de caja.']);}
