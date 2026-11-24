<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
requireLogin();
$u=currentUser() ?: [];
$role=strtoupper((string)($u['rol_original'] ?? $u['rol'] ?? ''));
if (!in_array($role,['ADMINISTRADOR','ADMIN','DIRECCION'],true)) {http_response_code(403);exit('Acceso restringido.');}
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/pos_corte_denominaciones.php';
$id=filter_input(INPUT_GET,'turno_id',FILTER_VALIDATE_INT);
if (!$id || $id < 1) {http_response_code(400);exit('Turno inválido.');}
$db=(new Database())->connect();
$s=$db->prepare('SELECT t.id,t.estado,t.efectivo_contado,t.diferencia_efectivo,s.nombre sucursal,c.nombre caja,d.usuario_id,d.desglose_json,d.total_contado,d.creado_en FROM pos_turnos t JOIN pos_cajas c ON c.id=t.caja_id JOIN pos_sucursales s ON s.id=c.sucursal_id LEFT JOIN pos_cortes_denominaciones d ON d.turno_id=t.id WHERE t.id=?');
$s->execute([$id]);$r=$s->fetch(PDO::FETCH_ASSOC);
if (!$r) {http_response_code(404);exit('Turno no encontrado.');}
$details=json_decode((string)($r['desglose_json']??'{}'),true);
if (!is_array($details))$details=[];
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="corte_gyd_turno_'.(int)$id.'.csv"');
echo "\xEF\xBB\xBF";
$o=fopen('php://output','w');
function cut_csv(string $v):string{return preg_match('/^[\s]*[=+@\-]/u',$v) ? "'".$v:$v;}
fputcsv($o,['Turno','Sucursal','Caja','Estado','Efectivo cierre','Registrado','Total contado','Diferencia']);
fputcsv($o,[(int)$id,cut_csv((string)$r['sucursal']),cut_csv((string)$r['caja']),(string)$r['estado'],(string)$r['efectivo_contado'],(string)($r['creado_en']??''),(string)($r['total_contado']??''),(string)$r['diferencia_efectivo']]);
fputcsv($o,[]);fputcsv($o,['Denominación','Cantidad','Subtotal pesos']);
foreach(PosCorteDenominaciones::VALORES as $k=>$centavos){
 $q=(int)($details[$k]??0);fputcsv($o,[($k[0]==='b'?'Billete $':'Moneda $').number_format($centavos/100,2,'.',','),$q,number_format($q*$centavos/100,2,'.','')]);
}
fclose($o);
