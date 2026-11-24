<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/gyd_stock_kardex_verified.php';
requireLogin();$u=currentUser()?:[];
$role=strtoupper(trim((string)($u['rol_original']??$u['rol']??'')));
if(!in_array($role,['ADMINISTRADOR','ADMIN','DIRECCION'],true)){http_response_code(403);exit('Acceso denegado.');}
$db=(new Database())->connect();
$sid=(int)($_GET['sucursal']??0);$pid=(int)($_GET['producto']??0);
$desde=(string)($_GET['desde']??'');$hasta=(string)($_GET['hasta']??'');
$valid=static function(string $v):bool{$d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);return $d!==false&&$d->format('Y-m-d')===$v;};
if(!in_array($sid,[1,2,3,4],true)||$pid<1||!$valid($desde)||!$valid($hasta)||$desde>$hasta||(strtotime($hasta)-strtotime($desde))>366*86400){http_response_code(422);exit('Filtros no válidos.');}
$rows=gyd_vk_filter($db,$sid,$pid,$desde,$hasta,1500);
$columns=['ID','Fecha','Área','Acción','Sucursal antes','Sucursal después','Producto antes','Producto después','Ubicación antes','Ubicación después','Existencia antes','Cambio','Existencia después','Usuario','Origen','Folio','ID solicitud','Conexión SQL'];
$extract=static fn(array $r)=>[$r['id'],$r['creado_en'],$r['area'],$r['operacion'],$r['sucursal_antes'],$r['sucursal_despues'],$r['producto_antes'],$r['producto_despues'],$r['ubicacion_antes'],$r['ubicacion_despues'],$r['existencia_antes'],$r['diferencia'],$r['existencia_despues'],$r['responsable'],$r['modulo'],$r['folio'],$r['id_solicitud'],$r['conexion_sql']];
$format=(string)($_GET['formato']??'xlsx');
if($format==='xlsx'){
    $vendor=__DIR__.'/../vendor/autoload.php';
    if(!is_file($vendor)){http_response_code(503);exit('Falta PhpSpreadsheet (vendor/autoload.php).');}
    require_once $vendor;
    $workbook=new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet=$workbook->getActiveSheet();$sheet->setTitle('Auditoria existencias');
    foreach($columns as $j=>$value){$col=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($j+1);$sheet->setCellValue($col.'1',$value);}
    $sheet->getStyle('A1:R1')->getFont()->setBold(true);
    $sheet->getStyle('A1:R1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF123F72');
    $sheet->getStyle('A1:R1')->getFont()->getColor()->setARGB('FFFFFFFF');
    $sheet->freezePane('A2');
    foreach($rows as $i=>$row){$values=$extract($row);foreach($values as $j=>$v){$cell=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($j+1).($i+2);
        if($v!==null&&in_array($j,[0,4,5,6,7,10,11,12,17],true))$sheet->setCellValue($cell,(int)$v);
        else $sheet->setCellValueExplicit($cell,(string)($v??''),\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    }}
    $sheet->setAutoFilter('A1:R'.max(2,count($rows)+1));
    foreach(range('A','R') as $col){$sheet->getColumnDimension($col)->setWidth(in_array($col,['B','I','J','O','P','Q'],true)?25:18);}
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="gyd_auditoria_existencias_'.date('Ymd_His').'.xlsx"');
    header('Cache-Control: no-store');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($workbook))->save('php://output');
    $workbook->disconnectWorksheets();exit;
}
if($format!=='csv'){http_response_code(422);exit('Formato inválido');}
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="gyd_auditoria_existencias_'.date('Ymd_His').'.csv"');
header('Cache-Control: no-store');
$out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");
fputcsv($out,$columns,';');
foreach($rows as $r){
    $cells=$extract($r);
    foreach($cells as $j=>&$v){if($v!==null&&!in_array($j,[0,4,5,6,7,10,11,12,17],true)){
        $v=(string)$v;if(preg_match('/^[=+\-@\t\r]/u',$v))$v="'".$v;
    }}unset($v);
    fputcsv($out,$cells,';');
}
fclose($out);
