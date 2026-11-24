<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_catalogo_reportes.php';
requireLogin();
$user=currentUser()??[];
$rol=strtoupper((string)($user['rol']??''));
if(!gyd_cr_permitido($rol)){http_response_code(403);exit('No tienes permiso para consultar reportes del Catálogo Maestro.');}
$pdo=(new Database())->connect();
$hayFicha=gyd_cr_hay_ficha($pdo);
$campos=gyd_cr_columnas($hayFicha,gyd_cr_ver_costos($rol));
$s=gyd_cr_solicitud($_GET,$campos,$hayFicha);
$totales=gyd_cr_contar($pdo,$s,$hayFicha);
$nombre='catalogo_maestro_gyd_'.date('Ymd_His');
$modo=(string)($_GET['formato']??'');
if($modo==='xlsx'){
    $autoload=__DIR__.'/../vendor/autoload.php';
    if(!is_file($autoload)){http_response_code(503);exit('No está disponible la biblioteca de Excel. Puedes usar el archivo CSV.');}
    require_once $autoload;
    if(!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)){
        http_response_code(503);exit('La biblioteca PhpSpreadsheet no está disponible. Utiliza Exportar CSV.');
    }
    $xlsx=new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet=$xlsx->getActiveSheet();$sheet->setTitle('Catálogo Maestro');
    $sheet->getSheetView()->setZoomScale(90);
    $numCol=count($s['columnas']);
    foreach($s['columnas'] as $idx=>$clave){
        $celda=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx+1).'1';
        $sheet->setCellValueExplicit($celda,$campos[$clave][0],\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    }
    $last=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($numCol);
    $sheet->getStyle('A1:'.$last.'1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
    $sheet->getStyle('A1:'.$last.'1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF194C7D');
    $sheet->getRowDimension(1)->setRowHeight(27);
    $sheet->freezePane('A2');
    $n=2;
    $st=gyd_cr_consulta($pdo,$s,$hayFicha,$campos);
    while($fila=$st->fetch(PDO::FETCH_ASSOC)){
        foreach($s['columnas'] as $idx=>$clave){
            $celda=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx+1).$n;
            $v=$fila[$clave]??null;$tipo=$campos[$clave][2];
            if($v===null||$v===''){
                if($tipo==='impuesto')$sheet->setCellValueExplicit($celda,'Sin configurar',\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }elseif(in_array($tipo,['moneda','moneda4','numero','impuesto','porcentaje'],true)){
                $sheet->setCellValue($celda,(float)$v);
                $format=$tipo==='moneda4'?'"$"#,##0.0000':($tipo==='moneda'?'"$"#,##0.00':($tipo==='numero'?'0.0000':'0.00"%"'));
                $sheet->getStyle($celda)->getNumberFormat()->setFormatCode($format);
            }else{
                // Evita perder ceros iniciales en códigos y neutraliza fórmulas de celdas.
                $sheet->setCellValueExplicit($celda,(string)$v,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        }
        $n++;
    }
    foreach($s['columnas'] as $idx=>$clave){
        $letter=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx+1);
        $sheet->getColumnDimension($letter)->setWidth($clave==='descripcion'?52:($clave==='codigo_barras'?21:($clave==='proveedor'?29:20)));
    }
    $sheet->setAutoFilter('A1:'.$last.'1');
    $xlsx->getProperties()->setCreator('ERP G&D')->setTitle('Reporte Catálogo Maestro');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$nombre.'.xlsx"');
    header('Cache-Control: no-store, private');
    $writer=new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($xlsx);
    $writer->save('php://output');$xlsx->disconnectWorksheets();exit;
}
if($modo==='csv'){
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$nombre.'.csv"');
    header('Cache-Control: no-store, private');
    $f=fopen('php://output','wb');fwrite($f,"\xEF\xBB\xBF");
    fputcsv($f,array_map(fn($key)=>$campos[$key][0],$s['columnas']));
    $st=gyd_cr_consulta($pdo,$s,$hayFicha,$campos);
    while($fila=$st->fetch(PDO::FETCH_ASSOC)){
        $salida=[];
        foreach($s['columnas'] as $clave){
            $v=$fila[$clave]??null;
            // TSV/CSV no debe ejecutar fórmulas al abrirse en Excel.
            if($v===null)$v=$campos[$clave][2]==='impuesto'?'Sin configurar':'';
            $v=(string)$v;
            if($v!=='' && preg_match('/^[\s]*[=+@\-]/u',$v))$v="'".$v;
            $salida[]=$v;
        }
        fputcsv($f,$salida);
    }
    fclose($f);exit;
}
if($modo==='pdf'){
    $st=gyd_cr_consulta($pdo,$s,$hayFicha,$campos);
    ?><!doctype html><html lang="es"><head><meta charset="utf-8"><title>Reporte del Catálogo Maestro G&D</title><style>
    @page{size:letter landscape;margin:10mm}body{font-family:Arial,Helvetica,sans-serif;font-size:10px;color:#163350;margin:14px}h1{font-size:19px;margin:5px 0}p{margin:3px 0 12px;color:#526b84}table{border-collapse:collapse;width:100%;table-layout:auto}thead{display:table-header-group}tr{break-inside:avoid}th{background:#17477a;color:white}td,th{padding:6px;border:1px solid #afc1d5;text-align:left;overflow-wrap:anywhere}.toolbar{text-align:right;margin-bottom:18px}.toolbar button{background:#195db4;color:white;border:0;border-radius:5px;padding:9px 12px;cursor:pointer}@media print{.toolbar{display:none}}
    </style></head><body><div class="toolbar"><button type="button" onclick="window.print()">Imprimir / Guardar PDF</button></div><h1>G&D · REPORTE DEL CATÁLOGO MAESTRO</h1><p>Generado: <?=gyd_cr_h(date('d/m/Y H:i'))?> · Productos encontrados: <?=number_format($totales)?> · Solo lectura</p><table><thead><tr><?php foreach($s['columnas'] as $clave):?><th><?=gyd_cr_h($campos[$clave][0])?></th><?php endforeach;?></tr></thead><tbody>
    <?php while($r=$st->fetch(PDO::FETCH_ASSOC)):?><tr><?php foreach($s['columnas'] as $clave):?><td><?=gyd_cr_h(gyd_cr_valor($r[$clave]??null,$campos[$clave][2]))?></td><?php endforeach;?></tr><?php endwhile;?>
    </tbody></table>
<?php if (!defined('GYD_HIDE_GLOBAL_NAV')): ?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=1">
<script>window.GYD_NAV_HOME='index.php';window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=1"></script>
<?php endif; ?>
</body></html><?php exit;
}
$categorias=$pdo->query('SELECT id,nombre FROM categorias ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
$proveedores=$pdo->query('SELECT id,nombre FROM proveedores ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
$porPagina=50;$paginas=max(1,(int)ceil($totales/$porPagina));$s['pagina']=min($s['pagina'],$paginas);
$datos=gyd_cr_consulta($pdo,$s,$hayFicha,$campos,$porPagina,($s['pagina']-1)*$porPagina)->fetchAll(PDO::FETCH_ASSOC);
function cr_url(array $sol,int $pag):string {$sol['pagina']=$pag;return 'erp_catalogo_reportes.php?'.http_build_query($sol);}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ERP G&D | Reportes del Catálogo Maestro</title><style>
:root{font-family:system-ui,-apple-system,'Segoe UI',Arial,sans-serif;color:#173756;background:#eef3f9}*{box-sizing:border-box}body{margin:0}header{background:#173e68;color:white;padding:17px clamp(16px,3vw,42px);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}header h1{font-size:23px;margin:0}header p{margin:3px 0 0;color:#d8e6f4;font-size:13px}header a{background:#fff;color:#164775;padding:10px 14px;text-decoration:none;border-radius:8px;font-weight:750;font-size:13px}main{max-width:1560px;margin:auto;padding:20px}.card{background:#fff;border:1px solid #e0e9f1;border-radius:13px;padding:18px;margin-bottom:16px;box-shadow:0 3px 13px #193f6410}.card h2{font-size:18px;margin:0 0 10px;color:#143d65}.hint{font-size:13px;color:#5c7490}.filters{display:grid;grid-template-columns:repeat(4,minmax(155px,1fr));gap:12px}label.field{font-size:13px;font-weight:700;color:#184264;display:grid;gap:6px}input,select{min-height:41px;width:100%;border:1px solid #cad9e8;border-radius:8px;padding:9px 11px;background:#fff;color:#163653;font:inherit}.checks{display:grid;grid-template-columns:repeat(4,minmax(160px,1fr));gap:8px;margin:12px 0 15px}.ck{display:flex;align-items:center;gap:8px;background:#f2f6fc;border:1px solid #dae5f1;padding:9px;border-radius:7px;font-size:13px;cursor:pointer}.ck input{min-height:0;height:16px;width:16px;flex:none}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:13px}.btn{border:0;border-radius:8px;min-height:39px;padding:10px 16px;background:#1d5bb1;color:white;text-decoration:none;font-size:13px;font-weight:750;cursor:pointer}.btn.green{background:#17764d}.btn.red{background:#b92f4a}.btn.gray{background:#eaf0f7;color:#174775}.summary{display:flex;gap:15px;align-items:center;justify-content:space-between;flex-wrap:wrap}.total{background:#e9f1ff;border-radius:99px;padding:7px 12px;color:#1d548d;font-weight:800;font-size:13px}.tablewrap{overflow:auto}.data{border-collapse:collapse;width:100%;font-size:12px;min-width:760px}.data th{text-align:left;background:#184e80;color:white;padding:11px;white-space:nowrap}.data td{padding:10px;border-bottom:1px solid #e1eaf3;vertical-align:top}.data tr:hover{background:#f5f9fe}.empty{text-align:center;padding:24px!important;color:#64778e}.pager{display:flex;justify-content:flex-end;align-items:center;gap:10px;margin-top:14px;font-size:13px}.pager a{color:#1559a7;text-decoration:none;border:1px solid #bacfe6;padding:7px 12px;border-radius:8px}.tip{padding:10px 13px;border-radius:8px;background:#fff8e5;border:1px solid #efdb9e;font-size:13px;color:#71541d;margin-bottom:12px}@media(max-width:1000px){.filters,.checks{grid-template-columns:repeat(2,minmax(150px,1fr))}}@media(max-width:560px){main{padding:10px}.filters,.checks{grid-template-columns:1fr}.card{padding:13px}}
</style></head><body>
<header><div><h1>G&D · Reportes del Catálogo Maestro</h1><p>Informes personalizados de productos · No modifica inventarios ni precios</p></div><a href="erp_catalogo_maestro.php">← Volver al Catálogo Maestro</a><a href="erp_catalogo_auditoria.php">🔎 Auditoría del catálogo</a><?php if(gyd_cr_ver_costos($rol)):?><a href="erp_catalogo_finanzas.php" style="background:#fff0d7;color:#744800">💰 Costos y rentabilidad</a><?php endif;?></header>
<main>
<?php if(!$hayFicha):?><div class="tip">La tabla de ficha fiscal todavía no existe en esta base de datos. Los campos y filtros de IVA e IEPS aparecerán cuando se encuentre instalada.</div><?php endif;?>
<section class="card"><h2>Filtros del reporte</h2><form id="reporteForm" method="get" action="erp_catalogo_reportes.php"><div class="filters">
<label class="field">Buscar por código o descripción<input name="q" value="<?=gyd_cr_h($s['q'])?>" placeholder="Código, barras, nombre..."></label>
<label class="field">Categoría<select name="categoria"><option value="0">Todas</option><?php foreach($categorias as $r):?><option value="<?= (int)$r['id'] ?>" <?= $s['categoria']===(int)$r['id']?'selected':'' ?>><?=gyd_cr_h($r['nombre'])?></option><?php endforeach;?></select></label>
<label class="field">Proveedor<select name="proveedor"><option value="0">Todos</option><?php foreach($proveedores as $r):?><option value="<?= (int)$r['id'] ?>" <?= $s['proveedor']===(int)$r['id']?'selected':'' ?>><?=gyd_cr_h($r['nombre'])?></option><?php endforeach;?></select></label>
<label class="field">Laboratorio / marca<input name="laboratorio" value="<?=gyd_cr_h($s['laboratorio'])?>" placeholder="Todos los laboratorios"></label>
<label class="field">Estado<select name="estado"><option value="todos" <?=$s['estado']==='todos'?'selected':''?>>Todos</option><option value="activos" <?=$s['estado']==='activos'?'selected':''?>>Activos</option><option value="inactivos" <?=$s['estado']==='inactivos'?'selected':''?>>Inactivos</option></select></label>
<label class="field">Integridad de datos<select name="datos"><?php foreach(['todos'=>'Todos','completos'=>'Datos completos','incompletos'=>'Datos incompletos','sin_codigo_barras'=>'Sin código de barras','sin_categoria'=>'Sin categoría','sin_proveedor'=>'Sin proveedor','sin_laboratorio'=>'Sin laboratorio','sin_unidad'=>'Sin unidad de medida'] as $key=>$title):?><option value="<?=gyd_cr_h($key)?>" <?=$s['datos']===$key?'selected':''?>><?=gyd_cr_h($title)?></option><?php endforeach;?></select></label>
<?php if($hayFicha):?><label class="field">IVA de compra<select name="iva"><?php foreach(['todos'=>'Todos','sin_configurar'=>'IVA sin configurar','configurado'=>'IVA configurado','cero'=>'IVA 0% configurado','ocho'=>'IVA 8%','dieciseis'=>'IVA 16%'] as $key=>$title):?><option value="<?=gyd_cr_h($key)?>" <?=$s['iva']===$key?'selected':''?>><?=gyd_cr_h($title)?></option><?php endforeach;?></select></label><?php endif;?>
<label class="field">Ordenar por<select name="orden"><?php foreach(['descripcion'=>'Descripción','codigo'=>'Código','categoria'=>'Categoría','proveedor'=>'Proveedor','estado'=>'Estado'] as $key=>$title):?><option value="<?=gyd_cr_h($key)?>" <?=$s['orden']===$key?'selected':''?>><?=gyd_cr_h($title)?></option><?php endforeach;?></select></label></div>
<h2 style="margin-top:22px">Columnas que llevará el reporte</h2><p class="hint">Selecciona la información que necesitas. Los importes están disponibles para Administración y Compras autorizados.</p><div class="checks"><?php foreach($campos as $key=>$meta):?><label class="ck"><input type="checkbox" name="columnas[]" value="<?=gyd_cr_h($key)?>" <?=in_array($key,$s['columnas'],true)?'checked':''?>><?=gyd_cr_h($meta[0])?></label><?php endforeach;?></div>
<div class="actions"><button class="btn" type="submit">Generar reporte</button><button class="btn green" name="formato" value="xlsx" type="submit">Exportar Excel (.xlsx)</button><button class="btn gray" name="formato" value="csv" type="submit">Exportar CSV</button><button class="btn red" name="formato" value="pdf" type="submit" formtarget="_blank">Imprimir / Guardar PDF</button><a class="btn gray" href="erp_catalogo_reportes.php">Limpiar filtros</a></div>
</form></section>
<section class="card"><div class="summary"><h2>Resultados del reporte</h2><span class="total">Total: <?=number_format($totales)?> productos</span></div><div class="tablewrap"><table class="data"><thead><tr><?php foreach($s['columnas'] as $clave):?><th><?=gyd_cr_h($campos[$clave][0])?></th><?php endforeach;?></tr></thead><tbody>
<?php foreach($datos as $r):?><tr><?php foreach($s['columnas'] as $clave):?><td><?=gyd_cr_h(gyd_cr_valor($r[$clave]??null,$campos[$clave][2]))?></td><?php endforeach;?></tr><?php endforeach;?>
<?php if(!$datos):?><tr><td class="empty" colspan="<?=count($s['columnas'])?>">No hay productos con los filtros seleccionados.</td></tr><?php endif;?></tbody></table></div>
<div class="pager"><span>Página <?=$s['pagina']?> de <?=$paginas?></span><?php if($s['pagina']>1):?><a href="<?=gyd_cr_h(cr_url($s,$s['pagina']-1))?>">← Anterior</a><?php endif;?><?php if($s['pagina']<$paginas):?><a href="<?=gyd_cr_h(cr_url($s,$s['pagina']+1))?>">Siguiente →</a><?php endif;?></div></section>
<p class="hint">La vista presenta 50 productos por página. Los archivos exportados incluyen todos los resultados del filtro. La opción PDF abre una versión lista para imprimir o guardar como PDF desde el navegador.</p>
</main>
<script src="assets/js/gyd_excel_reportes.js" defer></script>
</body></html>
