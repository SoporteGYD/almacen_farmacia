<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_catalogo_reportes.php';
require_once __DIR__.'/../app/helpers/erp_auditoria_catalogo.php';
requireLogin();
$actor=currentUser()??[];
$rol=strtoupper(trim((string)($actor['rol']??'')));
if(!gyd_cr_permitido($rol)){http_response_code(403);exit('No tienes permiso para consultar la auditoría del Catálogo Maestro.');}
$verCostos=gyd_cr_ver_costos($rol);
try{
    $pdo=(new Database())->connect();
    $ficha=gyd_ac_ficha($pdo);
    $reglas=gyd_ac_reglas($ficha,$verCostos);
    $filtro=gyd_ac_filtros($_GET,$reglas);
    $totales=gyd_ac_totales($pdo,$reglas,$ficha,$filtro);
    $cantidad=gyd_ac_contar($pdo,$reglas,$ficha,$filtro);
    /* GYD_CLASIFICACION_AUDITORIA_V2_CONTADORES */
    $gydV2FiltroResumen=$filtro;
    $gydV2FiltroResumen['tipo']='todos';
    $gydV2FiltroResumen['vista']='pendientes';
    $totalPendientes=gyd_ac_contar($pdo,$reglas,$ficha,$gydV2FiltroResumen);
    $gydV2FiltroResumen['vista']='observaciones';
    $totalObservaciones=gyd_ac_contar($pdo,$reglas,$ficha,$gydV2FiltroResumen);
}catch(Throwable $e){
    error_log('ERP auditoria catalogo: '.$e->getMessage());
    http_response_code(503);exit('No se pudo consultar el catálogo para su auditoría. Comprueba la conexión y la estructura de MySQL.');
}
$accion=(string)($_GET['formato']??'');
$campos=['Código','Código de barras','Descripción','Laboratorio','Categoría','Proveedor','Estado','Observaciones'];
if($verCostos)$campos[]='Último costo';
if($verCostos)$campos[]='Costo promedio';
if($ficha){$campos[]='IVA compra';$campos[]='IVA venta';$campos[]='IEPS';}
function gyd_ac_csv_seguro(string $valor):string{
    // Evitar interpretación como fórmula al abrir un CSV en Excel.
    return preg_match('/^[\t\r\n ]*[=+\-@]/u',$valor)===1?"'".$valor:$valor;
}
function gyd_ac_tabla(array $r,array $reglas,bool $costos,bool $ficha):array{
    $v=[(string)($r['codigo']??''),(string)($r['codigo_barras']??''),(string)($r['descripcion']??''),(string)($r['laboratorio']??''),(string)($r['categoria']??''),(string)($r['proveedor']??''),
        (int)($r['estado']??0)===1?'Activo':'Inactivo',implode('; ',array_map(static fn($a)=>$a['nombre'],gyd_ac_incidencias($r,$reglas)))];
    if($costos){$v[]=($r['costo_ultimo']===null?'Sin referencia':number_format((float)$r['costo_ultimo'],4,'.',''));$v[]=($r['costo_promedio']===null?'Sin referencia':number_format((float)$r['costo_promedio'],4,'.',''));}
    if($ficha)foreach(['iva_compra','iva_venta','ieps'] as $field)$v[]=($r[$field]===null?'Sin configurar':number_format((float)$r[$field],2,'.','').'%');
    return $v;
}
$nombre='gyd_auditoria_catalogo_'.date('Ymd_His');
if(in_array($accion,['csv','xlsx','pdf'],true)){
    $filas=gyd_ac_lotes($pdo,$reglas,$ficha,$verCostos,$filtro,null);
    if($accion==='csv'){
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$nombre.'.csv"');
        header('Cache-Control: no-store');
        $salida=fopen('php://output','wb');fwrite($salida,"\xEF\xBB\xBF");fputcsv($salida,$campos);
        while($r=$filas->fetch(PDO::FETCH_ASSOC))fputcsv($salida,array_map('gyd_ac_csv_seguro',gyd_ac_tabla($r,$reglas,$verCostos,$ficha)));
        fclose($salida);exit;
    }
    if($accion==='xlsx'){
        $autoload=__DIR__.'/../vendor/autoload.php';
        if(!is_file($autoload)){http_response_code(503);exit('No está disponible PhpSpreadsheet. Usa Exportar CSV.');}
        require_once $autoload;
        if(!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)){http_response_code(503);exit('No está disponible PhpSpreadsheet. Usa Exportar CSV.');}
        $book=new \PhpOffice\PhpSpreadsheet\Spreadsheet();$sheet=$book->getActiveSheet();$sheet->setTitle('Auditoría Catálogo');
        foreach($campos as $index=>$c){$sheet->setCellValueExplicit(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index+1).'1',$c,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);}
        $row=2;while($r=$filas->fetch(PDO::FETCH_ASSOC)){
            foreach(gyd_ac_tabla($r,$reglas,$verCostos,$ficha) as $i=>$val)
                $sheet->setCellValueExplicit(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i+1).$row,(string)$val,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $row++;
        }
        $last=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($campos));
        $sheet->getStyle('A1:'.$last.'1')->getFont()->setBold(true);
        $sheet->freezePane('A2');$sheet->setAutoFilter('A1:'.$last.max(1,$row-1));
        for($c=1;$c<=count($campos);$c++)$sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c))->setWidth($c===3?48:($c===8?75:23));
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.$nombre.'.xlsx"');header('Cache-Control: no-store');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');exit;
    }
    ?><!doctype html><html lang="es"><head><meta charset="utf-8"><title>Auditoría Catálogo Maestro G&D</title>
    <style>body{font:12px Arial;color:#163956}h1{font-size:18px}table{width:100%;border-collapse:collapse}th,td{padding:5px;border:1px solid #bacada;text-align:left;overflow-wrap:anywhere}th{background:#1a4d79;color:white}thead{display:table-header-group}tr{break-inside:avoid}.tools{text-align:right;margin:10px}.tools button{padding:10px;background:#165da7;color:white;border:0}@media print{.tools{display:none}@page{size:landscape}}</style></head><body><div class="tools"><button onclick="print()">Imprimir / Guardar PDF</button></div><h1>G&D · Auditoría del Catálogo Maestro</h1><p>Productos con observaciones: <?=number_format($cantidad)?> · Consulta de solo lectura · Generado <?=gyd_ac_h(date('d/m/Y H:i'))?></p><table><thead><tr><?php foreach($campos as $head):?><th><?=gyd_ac_h($head)?></th><?php endforeach;?></tr></thead><tbody><?php while($r=$filas->fetch(PDO::FETCH_ASSOC)):?><tr><?php foreach(gyd_ac_tabla($r,$reglas,$verCostos,$ficha) as $celda):?><td><?=gyd_ac_h($celda)?></td><?php endforeach;?></tr><?php endwhile;?></tbody></table>
<?php if (!defined('GYD_HIDE_GLOBAL_NAV')): ?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=1">
<script>window.GYD_NAV_HOME='index.php';window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=1"></script>
<?php endif; ?>
</body></html><?php exit;
}
$porPagina=50;$paginas=max(1,(int)ceil($cantidad/$porPagina));$filtro['pagina']=min($filtro['pagina'],$paginas);
$filas=gyd_ac_lotes($pdo,$reglas,$ficha,$verCostos,$filtro,$porPagina,($filtro['pagina']-1)*$porPagina)->fetchAll(PDO::FETCH_ASSOC);
function gyd_ac_link(array $f,array $c=[]):string{return 'erp_catalogo_auditoria.php?'.http_build_query(array_merge($f,$c));}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ERP G&D | Auditoría de Catálogo Maestro</title>
<style>:root{font-family:system-ui,'Segoe UI',Arial,sans-serif;color:#163956;background:#eef3f9}*{box-sizing:border-box}body{margin:0}header{background:#173e68;color:white;padding:18px clamp(16px,3vw,42px);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}header h1{font-size:23px;margin:0}header p{font-size:13px;color:#d9e4f1;margin:5px 0 0}a{color:#155b9b}header a{background:#fff;color:#143f71;padding:10px 14px;border-radius:8px;text-decoration:none;font-weight:750;font-size:13px}main{max-width:1510px;margin:auto;padding:20px}.panel{background:#fff;border:1px solid #dfe8f2;border-radius:12px;padding:18px;margin-bottom:15px;box-shadow:0 4px 14px #173e680a}.panel h2{margin:0 0 11px;font-size:18px}.notice{background:#fff9e9;border:1px solid #eacb84;color:#725318;padding:12px;border-radius:8px;font-size:13px;margin-bottom:14px}.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px}.stat{border:1px solid #dfe9f3;border-radius:10px;padding:12px;color:#16436c;text-decoration:none}.stat strong{display:block;font-size:26px}.stat span{font-size:12px;color:#667c90}.stat:hover{background:#f3f8ff}.filter{display:grid;grid-template-columns:1.3fr 1fr 1fr auto;gap:12px;align-items:end}.filter label{display:grid;gap:5px;font-size:13px;font-weight:700}input,select{min-height:40px;border:1px solid #c8d7e8;border-radius:8px;padding:9px;color:#143c61;font:inherit;width:100%}.actions{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0 0}.btn{background:#1b5fb6;color:white;text-decoration:none;border:0;border-radius:8px;padding:10px 13px;font-size:13px;font-weight:750;cursor:pointer;display:inline-block}.btn.alt{background:#ebf2fa;color:#154b83}.btn.green{background:#167952}.btn.red{background:#b93046}.tablewrap{overflow:auto}table{border-collapse:collapse;width:100%;min-width:1000px;font-size:13px}th{background:#1a4e7e;color:white;padding:11px;text-align:left}td{border-bottom:1px solid #e2eaf2;padding:12px;vertical-align:top}tr:hover td{background:#f7faff}.tags{display:flex;gap:5px;flex-wrap:wrap}.tag{display:inline-block;background:#fff3d6;color:#815d15;font-weight:650;padding:5px 7px;border-radius:8px;font-size:11px}.tag.alta{background:#fde5e5;color:#b12132}.tag.informativa{background:#e7f0ff;color:#14549b}.summary{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px}.pager{display:flex;justify-content:flex-end;gap:12px;align-items:center;font-size:13px;padding-top:13px}.pager a{padding:7px 10px;border:1px solid #bfcfe3;border-radius:7px;text-decoration:none}.muted{font-size:12px;color:#687e90}code{white-space:nowrap}@media(max-width:800px){main{padding:10px}.filter{grid-template-columns:1fr 1fr}}@media(max-width:500px){.filter{grid-template-columns:1fr}.panel{padding:12px}}</style></head><body>
<header><div><h1>G&D · Auditoría Inteligente del Catálogo Maestro</h1><p>Diagnóstico de productos, impuestos y costos · No modifica registros</p></div><a href="erp_catalogo_maestro.php">← Volver al Catálogo Maestro</a></header>
<main><div class="notice">Esta auditoría solo identifica posibles problemas. Una descripción repetida no demuestra duplicidad de artículos; el IVA o IEPS sin configurar no equivale a tasa 0%. Revisa la ficha y los documentos antes de modificar datos.</div>
<!-- GYD_CLASIFICACION_AUDITORIA_V2_PANEL: observaciones distintas de errores confirmados -->
<section class="panel"><div class="summary"><h2>Estado de la auditoría</h2><span class="muted">Las coincidencias y diferencias financieras necesitan verificación; no se eliminaron productos.</span></div>
<div class="stats">
<a class="stat" href="<?=gyd_ac_h(gyd_ac_link($filtro,['tipo'=>'todos','vista'=>'pendientes','pagina'=>1]))?>"><strong><?=number_format($totalPendientes)?></strong><span>Pendientes de corrección de datos</span></a>
<a class="stat" href="<?=gyd_ac_h(gyd_ac_link($filtro,['tipo'=>'todos','vista'=>'observaciones','pagina'=>1]))?>"><strong><?=number_format($totalObservaciones)?></strong><span>Observaciones por verificar</span></a>
<a class="stat" href="<?=gyd_ac_h(gyd_ac_link($filtro,['tipo'=>'todos','vista'=>'todas','pagina'=>1]))?>"><strong><?=number_format((int)($totales['productos_con_observaciones']??0))?></strong><span>Todos los hallazgos</span></a>
</div></section>
<?php if(!$ficha):?><div class="notice">No existe la tabla de fichas fiscales en esta base: las comprobaciones de IVA e IEPS quedan deshabilitadas.</div><?php endif;?>
<section class="panel"><h2>Resumen de incidencias</h2><div class="stats"><a class="stat" href="<?=gyd_ac_h(gyd_ac_link($filtro,['tipo'=>'todos','pagina'=>1]))?>"><strong><?=number_format((int)($totales['productos_con_observaciones']??0))?></strong><span>Productos con alguna observación</span></a><?php foreach($reglas as $id=>$r):?><a class="stat" href="<?=gyd_ac_h(gyd_ac_link($filtro,['tipo'=>$id,'pagina'=>1]))?>"><strong><?=number_format((int)($totales[$id]??0))?></strong><span><?=gyd_ac_h($r[0])?></span></a><?php endforeach;?></div></section>
<section class="panel"><h2>Buscar observaciones</h2><form method="get" action="erp_catalogo_auditoria.php" id="auditoria"><!-- GYD_CLASIFICACION_AUDITORIA_V2_FORM --><input type="hidden" name="vista" value="<?=gyd_ac_h($filtro['vista'])?>"><div class="filter"><label>Buscar producto o código<input name="q" value="<?=gyd_ac_h($filtro['q'])?>" placeholder="Código, barras, descripción o laboratorio"></label><label>Tipo de observación<select name="tipo"><option value="todos">Todas las observaciones</option><?php foreach($reglas as $id=>$r):?><option value="<?=gyd_ac_h($id)?>" <?=$filtro['tipo']===$id?'selected':''?>><?=gyd_ac_h($r[0])?></option><?php endforeach;?></select></label><label>Estado del producto<select name="estado"><option value="activos" <?=$filtro['estado']==='activos'?'selected':''?>>Activos</option><option value="inactivos" <?=$filtro['estado']==='inactivos'?'selected':''?>>Inactivos</option><option value="todos" <?=$filtro['estado']==='todos'?'selected':''?>>Todos</option></select></label><button class="btn" type="submit">Consultar</button></div><div class="actions"><button class="btn green" type="submit" name="formato" value="xlsx">Exportar Excel (.xlsx)</button><button class="btn alt" type="submit" name="formato" value="csv">Exportar CSV</button><button class="btn red" type="submit" name="formato" value="pdf" formtarget="_blank">Imprimir / Guardar PDF</button><a class="btn alt" href="erp_catalogo_auditoria.php">Limpiar filtros</a><a class="btn alt" href="erp_catalogo_reportes.php">Reportes personalizados</a></div></form></section>
<section class="panel"><div class="summary"><h2><!-- GYD_CLASIFICACION_AUDITORIA_V2_TITULO --><?= $filtro['tipo']!=='todos' ? 'Productos con la incidencia seleccionada' : ($filtro['vista']==='pendientes'?'Pendientes de corrección':($filtro['vista']==='observaciones'?'Observaciones por verificar':'Todos los hallazgos')) ?></h2><strong><?=number_format($cantidad)?> producto(s)</strong></div><div class="tablewrap"><table><thead><tr><th>Código / barras</th><th>Descripción</th><th>Categoría / proveedor</th><th>Observaciones</th><th>Acción</th></tr></thead><tbody><?php foreach($filas as $row):?><tr><td><strong><?=gyd_ac_h($row['codigo'])?></strong><div class="muted"><?=gyd_ac_h($row['codigo_barras'])?></div></td><td><strong><?=gyd_ac_h($row['descripcion'])?></strong><div class="muted"><?=gyd_ac_h($row['laboratorio'])?></div></td><td><?=gyd_ac_h($row['categoria']?:'Sin categoría')?><div class="muted"><?=gyd_ac_h($row['proveedor']?:'Sin proveedor')?></div></td><td><div class="tags"><?php foreach(gyd_ac_incidencias($row,$reglas) as $item):?><span class="tag <?=gyd_ac_h($item['nivel'])?>"><?=gyd_ac_h($item['nombre'])?></span><?php endforeach;?></div></td><td><a class="btn alt" href="erp_catalogo_maestro.php?<?=gyd_ac_h(http_build_query(['q'=>trim((string)$row['codigo'])!==''?$row['codigo']:$row['descripcion']]))?>">Abrir ficha</a></td></tr><?php endforeach;?><?php if(!$filas):?><tr><td colspan="5">No se encontraron productos con observaciones para este filtro.</td></tr><?php endif;?></tbody></table></div><nav class="pager"><span>Página <?=$filtro['pagina']?> de <?=$paginas?></span><?php if($filtro['pagina']>1):?><a href="<?=gyd_ac_h(gyd_ac_link($filtro,['pagina'=>$filtro['pagina']-1]))?>">← Anterior</a><?php endif;?><?php if($filtro['pagina']<$paginas):?><a href="<?=gyd_ac_h(gyd_ac_link($filtro,['pagina'=>$filtro['pagina']+1]))?>">Siguiente →</a><?php endif;?></nav></section>
<p class="muted">Muestra 50 productos por página; las exportaciones incluyen todos los registros filtrados. Los importes y alertas de costos se muestran a Administración y Compras autorizados. La corrección de datos debe hacerse en las pantallas originales con sus permisos y auditoría.</p></main>
<script src="assets/js/gyd_excel_reportes.js" defer></script>
</body></html>
