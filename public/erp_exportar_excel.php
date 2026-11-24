<?php
declare(strict_types=1);
/**
 * G&D | Descarga XLSX de tablas de reportes ya consultadas por el usuario.
 * No realiza consultas a la base ni cambia datos. La autorizacion de lectura
 * permanece en la pagina que origino el reporte.
 */
require_once __DIR__.'/../app/helpers/auth.php';
requireLogin();
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

function ge_error(int $code, string $message): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error'=>$message], JSON_UNESCAPED_UNICODE);
    exit;
}
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') ge_error(405,'La exportación requiere una solicitud POST.');
if (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH']??''))!=='xmlhttprequest') ge_error(403,'Solicitud de exportación no autorizada.');
if (isset($_SERVER['HTTP_SEC_FETCH_SITE']) && !in_array(strtolower((string)$_SERVER['HTTP_SEC_FETCH_SITE']),['same-origin','none'],true)) ge_error(403,'Origen no autorizado.');
// Defensa adicional de mismo origen para navegadores que informan Origin.
if (!empty($_SERVER['HTTP_ORIGIN'])) {
    $origin=parse_url((string)$_SERVER['HTTP_ORIGIN']);
    $host=(string)($_SERVER['HTTP_HOST']??'');
    $originHost=strtolower((string)($origin['host']??''));
    $httpHost=strtolower((string)(parse_url('http://'.$host,PHP_URL_HOST)??''));
    if (!$originHost || !hash_equals($httpHost,$originHost)) ge_error(403,'Origen no autorizado.');
}
$user=currentUser()??[];
$role=strtoupper(trim((string)($user['rol_original']??$user['rol']??'')));
if (in_array($role,['CAJERO',''],true)) ge_error(403,'El perfil de caja no tiene acceso a exportaciones administrativas.');
$raw=file_get_contents('php://input',false,null,0,8*1024*1024+1);
if (!is_string($raw)||strlen($raw)>8*1024*1024) ge_error(413,'El reporte supera el límite de transferencia. Reduce los filtros.');
$data=json_decode($raw,true,32);
if (!is_array($data)||json_last_error()!==JSON_ERROR_NONE) ge_error(400,'El reporte enviado no es válido.');
$source=basename((string)($data['source']??''));
// El exportador no consulta informes arbitrarios: solo convierte tablas que el cliente ya ve.
$allowed=[
 'reportes.php','agotados.php','existencias.php','inventario_virtual.php','inventario_virtual_historial.php',
 'historial_entradas.php','historial_salidas.php','historial_movimientos.php','kardex.php',
 'erp_rendimiento_cajeros.php','erp_eficiencia_cajeros.php','erp_eficiencia_diagnostico.php',
 'erp_dashboard_gerencial.php','erp_comparativo_sucursales.php','erp_control_cajas.php',
 'erp_auditoria_cajas_v12.php','erp_retiros_diarios.php','erp_flujo_efectivo.php',
 'erp_catalogo_reportes.php','erp_catalogo_finanzas.php','erp_catalogo_auditoria.php',
 'erp_catalogo_historial_costos.php','erp_historial_precios.php','erp_catalogo_comparar_proveedores.php',
 'erp_kardex_general.php','erp_analisis_compras.php','erp_oc_inventarios.php',
 'erp_conciliacion_integral.php','erp_conciliacion_inventarios.php','erp_conciliacion_diaria_cajas.php',
 'erp_facturas_conciliacion.php','erp_conciliacion_gestion.php','erp_recepciones_historial.php',
 'erp_recepciones_calidad_historial.php','erp_oc_facturas_seguimiento.php','erp_cuentas_por_pagar.php',
 'erp_tesoreria.php','erp_alertas_compras.php','erp_alertas_gerenciales.php',
 'erp_reservas_almacen.php','erp_proveedores.php','erp_cortes_boveda.php',
 'erp_facturas_verificacion.php','erp_facturas.php','erp_facturas_aplicar.php',
 'erp_oc_integracion_facturas.php','erp_recepcion_calidad.php','erp_recepcion_registrar.php',
 'erp_rendimiento_sistema.php','erp_validacion_operativa.php',
 'erp_oc_win.php','erp_oc_win_gerentes.php','erp_oc_autorizaciones.php','erp_oc_cierre_controlado.php',
 'erp_oc_entrada_protegida.php','erp_recepciones_pendientes.php','erp_compras_avisos.php',
 'erp_compras_alertas_seguimiento.php','erp_facturas_verificacion.php','erp_pos_reversiones.php',
 'erp_pos_devoluciones_almacen.php','erp_resurtidos_pos.php','erp_flujo_efectivo.php',
 'erp_retiros_diarios.php','erp_cambio_precios.php','erp_catalogo_maestro.php',
 'erp_compras_inicio.php','erp_pruebas_integrales.php','erp_simulador_operaciones.php',
 'erp_diagnostico.php','erp_cambio_precios.php','erp_cuentas_por_pagar.php','erp_oc_win_enviar.php',
 'gyd_panel_cajas.php','gyd_reportes_gerenciales.php','pos_conciliacion_turno.php',
 'gyd_cortes_caja.php','gyd_clientes.php','gyd_recompensas.php',
];
if (!in_array($source,$allowed,true)) ge_error(403,'No está habilitada la exportación de esta página.');
$title=trim((string)($data['title']??'Reporte de G&D'));
$title=mb_substr(preg_replace('/[\x00-\x1F\x7F]/u',' ', $title)??'Reporte de G&D',0,120);
$mode=(string)($data['mode']??'visible');
if (!in_array($mode,['visible','csv'],true)) ge_error(400,'Modo de exportación desconocido.');
$tabs=$data['tables']??null;
if (!is_array($tabs)||count($tabs)<1||count($tabs)>20) ge_error(400,'Debes enviar de 1 a 20 tablas para exportar.');
$totalCells=0;
foreach ($tabs as $tab) {
    if (!is_array($tab)||!isset($tab['headers'],$tab['rows'])||!is_array($tab['headers'])||!is_array($tab['rows'])) ge_error(400,'La estructura del reporte es inválida.');
    $cols=count($tab['headers']);$rows=count($tab['rows']);
    if ($cols<1||$cols>70||$rows>10000) ge_error(413,'El reporte es demasiado grande. Reduce los filtros o divide el periodo.');
    $totalCells+=($rows+1)*$cols;
    if($totalCells>150000) ge_error(413,'El reporte supera 150,000 celdas. Reduce los filtros.');
    foreach($tab['rows'] as $row)if(!is_array($row)||count($row)>$cols) ge_error(400,'El reporte contiene columnas incompatibles.');
}
$autoload=__DIR__.'/../vendor/autoload.php';
if (!is_file($autoload)) ge_error(500,'Falta PhpSpreadsheet en vendor. Conserva la carpeta vendor original.');
require_once $autoload;
if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) ge_error(500,'No está disponible la biblioteca de Excel del ERP.');

/** Evita inyección de fórmulas; los códigos, IDs y folios siguen siendo texto. */
function ge_es_numero(string $s,string $encabezado):?array {
    $text=trim($s);
    if ($text===''||mb_strlen($text)>60) return null;
    $esDinero=(bool)preg_match('/\$|MXN/i',$text);
    $esPorcentaje=str_ends_with($text,'%');
    $t=trim(str_replace(['MXN','$','%',' '],'',$text));
    if (!preg_match('/^-?(?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d+)?$/D',$t))return null;
    $plain=str_replace(',','',$t);
    if (strlen(ltrim($plain,'-'))>14 || preg_match('/^-?0\d/',$plain))return null;
    if (preg_match('/folio|c[oó]digo|barra|sku|id(?:\s|$)|referencia|documento|tel[eé]fono|cuenta/i',$encabezado))return null;
    if ($esPorcentaje)return [(float)$plain/100,'0.00%'];
    if ($esDinero||preg_match('/precio|costo|importe|total|saldo|efectivo|tarjeta|transferencia|venta|diferencia|fondo|utilidad|reembolso|retiro|pago|cobro|mxn/i',$encabezado))return [(float)$plain,'#,##0.00;[Red](#,##0.00)'];
    return [strpos($plain,'.')===false?(int)$plain:(float)$plain, strpos($plain,'.')===false?'#,##0':'#,##0.00'];
}
function ge_texto(mixed $value):string {
    if (is_array($value)||is_object($value))return '';
    $s=(string)($value??'');
    $s=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',$s)??'';
    return mb_substr(trim($s),0,2500);
}
function ge_sheet_name(string $label,array &$taken):string {
    $n=trim((string)(preg_replace('/[\[\]:*?\\\/]/u',' ',ge_texto($label))??''));
    $n=mb_substr($n===''?'Reporte':$n,0,28);
    $base=$n;
    for($i=2;isset($taken[mb_strtolower($n)]);$i++)$n=mb_substr($base,0,26).'-'.$i;
    $taken[mb_strtolower($n)]=true;return $n;
}
try {
    $book=new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $book->getProperties()->setCreator('ERP G&D')->setTitle($title)->setSubject('Exportación administrativa');
    $meta=$book->getActiveSheet();$meta->setTitle('Información');
    $info=[
        ['REPORTE G&D',$title],
        ['Fecha de generación',date('Y-m-d H:i:s')],
        ['Origen',$source],
        ['Alcance',$mode==='csv'?'Datos entregados por la exportación CSV del módulo; se conservan sus filtros y límites.':'Filas cargadas y visibles en la pantalla; no incluye páginas adicionales ni datos ocultos.'],
        ['Advertencia','El archivo es de consulta y no modifica los registros de G&D.'],
    ];
    foreach($info as $i=>$pair){$r=$i+1;$meta->setCellValueExplicit('A'.$r,$pair[0],\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);$meta->setCellValueExplicit('B'.$r,ge_texto($pair[1]),\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);}
    $meta->getStyle('A1:A5')->getFont()->setBold(true)->getColor()->setRGB('123B69');
    $meta->getColumnDimension('A')->setWidth(25);$meta->getColumnDimension('B')->setWidth(90);
    $meta->getStyle('B1:B5')->getAlignment()->setWrapText(true);
    $meta->getRowDimension(4)->setRowHeight(36);
    $taken=['información'=>true];
    foreach($tabs as $index=>$tab){
        $ws=new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($book,ge_sheet_name((string)($tab['name']??('Tabla '.($index+1))),$taken));
        $book->addSheet($ws);
        $headers=array_map('ge_texto',$tab['headers']);
        $cols=count($headers);
        $lastCol=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($cols);
        $ws->mergeCells('A1:'.$lastCol.'1');
        $ws->setCellValueExplicit('A1',$title,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $ws->mergeCells('A2:'.$lastCol.'2');
        $ws->setCellValueExplicit('A2',ge_texto($tab['name']??('Tabla '.($index+1))),\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $ws->getStyle('A1:'.$lastCol.'1')->getFont()->setBold(true)->setSize(15)->getColor()->setRGB('FFFFFF');
        $ws->getStyle('A1:'.$lastCol.'1')->getFill()->setFillType('solid')->getStartColor()->setRGB('123B69');
        $ws->getRowDimension(1)->setRowHeight(30);
        $ws->getStyle('A2:'.$lastCol.'2')->getFont()->setItalic(true)->getColor()->setRGB('426184');
        foreach($headers as $i=>$head)$ws->getCell([$i+1,4])->setValueExplicit($head,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $ws->getStyle('A4:'.$lastCol.'4')->getFill()->setFillType('solid')->getStartColor()->setRGB('22649C');
        $ws->getStyle('A4:'.$lastCol.'4')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $ws->getStyle('A4:'.$lastCol.'4')->getAlignment()->setWrapText(true);
        $ws->getRowDimension(4)->setRowHeight(30);
        foreach($tab['rows'] as $ri=>$row){
            $er=$ri+5;
            foreach($headers as $ci=>$head){
                $value=ge_texto($row[$ci]??'');
                $typed=ge_es_numero($value,$head);
                $cell=$ws->getCell([$ci+1,$er]);
                if($typed){$cell->setValueExplicit($typed[0],\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);$cell->getStyle()->getNumberFormat()->setFormatCode($typed[1]);}
                else $cell->setValueExplicit($value,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        }
        $bottom=max(4,count($tab['rows'])+4);
        $ws->setAutoFilter('A4:'.$lastCol.$bottom);
        $ws->freezePane('A5');
        $ws->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
        for($ci=1;$ci<=$cols;$ci++){
            $dim=$ws->getColumnDimensionByColumn($ci);
            $dim->setWidth(min(42,max(13,mb_strlen($headers[$ci-1])+3)));
        }
    }
    $book->setActiveSheetIndex(1);
    $name='GYD_'.preg_replace('/[^a-zA-Z0-9_-]/','_',pathinfo($source,PATHINFO_FILENAME)).'_'.date('Ymd_His').'.xlsx';
    if(ob_get_level()>0){while(ob_get_level()>0)ob_end_clean();}
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$name.'"');
    header('Cache-Control: no-store, private');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');
    $book->disconnectWorksheets();
    exit;
} catch(Throwable $e){
    error_log('Excel G&D: '.$e->getMessage());
    if(!headers_sent())ge_error(500,'No se pudo generar el Excel. Revisa el registro de errores de PHP.');
    exit;
}
