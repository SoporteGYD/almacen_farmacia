<?php
/** ERP G&D · Centro de Alertas de Inventario, sólo lectura. */
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/gyd_alertas_stock.php';
requireLogin();
$usr=currentUser()?:[];
$role=strtoupper(trim((string)($usr['rol_original']??$usr['rol']??'')));
if(!in_array($role,['ADMINISTRADOR','ADMIN','DIRECCION'],true)){
    http_response_code(403); exit('No tienes permiso para consultar las alertas de inventario.');
}
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
$sid=(int)($_GET['sucursal']??0);if(!in_array($sid,[0,1,2,3,4],true))$sid=0;
$tipos=['TODOS'=>'Todas las alertas','DESCUADRE'=>'Descuadres','NEGATIVO'=>'Existencias negativas',
'AGOTADO'=>'Productos agotados','SIN_REGISTRO'=>'Sin registro POS','SIN_CONTEO'=>'Sin conteo de Farmacia','AUDITORIA'=>'Auditoría inconsistente'];
$tipo=(string)($_GET['tipo']??'TODOS');if(!isset($tipos[$tipo]))$tipo='TODOS';
$niveles=['TODOS'=>'Todas las prioridades','critica'=>'Crítica','alta'=>'Alta','media'=>'Media'];
$nivel=(string)($_GET['nivel']??'TODOS');if(!isset($niveles[$nivel]))$nivel='TODOS';
$dias=(int)($_GET['dias']??30);if(!in_array($dias,[7,15,30],true))$dias=30;
$q=function_exists('mb_substr')?mb_substr(trim((string)($_GET['q']??'')),0,80):substr(trim((string)($_GET['q']??'')),0,80);
$page=max(1,min(500,(int)($_GET['pagina']??1)));
$export=(string)($_GET['exportar']??'');
$error='';$out=[];$scan=['stats'=>[],'pool'=>'INEXISTENTE','audit'=>['estado'=>'AUSENTE','disparadores'=>0,'avisos'=>[]], 'count_products'=>0,'consultado_en'=>''];
try{
    $db=(new Database())->connect();
    $scan=gai_scan($db,$dias);
    $out=gai_filter($scan['rows'],$sid,$tipo,$nivel,$q);
}catch(Throwable $e){error_log('G&D alertas inventario: '.$e->getMessage());$error=$e->getMessage()==='Este panel está habilitado exclusivamente para la base de pruebas prueba:3307.'?$e->getMessage():'No fue posible completar la revisión. Comprueba la base de pruebas, las tablas y el registro PHP.';}

if($export!=='' && $error===''){
    if($export!=='xlsx'){http_response_code(400);exit('Formato no disponible.');}
    if(count($out)>12000){http_response_code(413);exit('Hay más de 12,000 alertas filtradas. Selecciona una sucursal o tipo para descargar el reporte completo, sin recortes.');}
    $vendor=__DIR__.'/../vendor/autoload.php';
    if(!is_file($vendor)){http_response_code(503);exit('No está instalado PhpSpreadsheet. Ejecuta composer install en tu ERP para habilitar Excel.');}
    require_once $vendor;
    if(!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)){http_response_code(503);exit('La librería PhpSpreadsheet no está disponible.');}
    try{
        $book=new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $summary=$book->getActiveSheet();$summary->setTitle('Resumen');
        $summary->fromArray([
          ['G&D · Centro de Alertas de Inventario'],
          ['Base', 'prueba:3307'],
          ['Consulta', $scan['consultado_en']],
          ['Sucursal', $sid===0?'Todas':gai_branches()[$sid]],
          ['Tipo', $tipos[$tipo]],
          ['Prioridad', $niveles[$nivel]],
          ['Búsqueda', $q],
          ['Alertas incluidas', count($out)],
          ['Productos activos en catálogo', $scan['count_products']],
          ['Inventarios', 'Tapachula y Toscana independientes; Hidalgo/Tuxtla General = Farmacia + Almacén'],
          ['Auditoría', $scan['audit']['estado'].' ('.$scan['audit']['disparadores'].' de 9 disparadores)'],
          ['Periodo auditado', 'Últimos '.$dias.' días; las existencias son actuales'],
          ['Aviso', 'Las existencias son una foto actual. No son saldos históricos ni un conteo físico certificado.']
        ],null,'A1');
        $summary->getColumnDimension('A')->setWidth(39);$summary->getColumnDimension('B')->setWidth(99);
        $sheet=$book->createSheet();$sheet->setTitle('Incidencias');
        $headers=['Sucursal','Código','Producto','Tipo de alerta','Prioridad','General POS','Farmacia','Almacén','Diferencia','Descripción','Fecha auditada'];
        $sheet->fromArray([$headers],null,'A1');
        $i=2;
        foreach($out as $r){
            foreach([1=>$r['sucursal'],2=>$r['codigo'],3=>$r['producto'],4=>$r['tipo'],5=>$r['nivel'],10=>$r['detalle'],11=>$r['fecha']??''] as $col=>$value){
                $sheet->setCellValueExplicit(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col).$i,(string)$value,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
            foreach([6=>$r['general'],7=>$r['farmacia'],8=>$r['almacen'],9=>$r['diferencia']] as $col=>$value){
                if($value!==null)$sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col).$i,(int)$value);
            }
            $i++;
        }
        $sheet->getStyle('A1:K1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A1:K1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('174B82');
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:K'.max(1,$i-1));
        foreach(['A'=>23,'B'=>19,'C'=>44,'D'=>23,'E'=>13,'F'=>16,'G'=>16,'H'=>16,'I'=>16,'J'=>80,'K'=>23] as $column=>$width)$sheet->getColumnDimension($column)->setWidth($width);
        foreach(['F','G','H','I'] as $column)$sheet->getStyle($column.'2:'.$column.max(2,$i-1))->getNumberFormat()->setFormatCode('#,##0;[Red](#,##0)');
        $book->setActiveSheetIndex(1);
        $filename='G_D_alertas_inventario_'.date('Ymd_His').'.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Cache-Control: no-store');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');
        $book->disconnectWorksheets();exit;
    }catch(Throwable $e){error_log('Exportar alertas G&D: '.$e->getMessage());http_response_code(500);exit('No fue posible generar el Excel completo. Revisa el registro de PHP.');}
}
$perPage=60;$pages=max(1,(int)ceil(count($out)/$perPage));$page=min($page,$pages);
$rows=array_slice($out,($page-1)*$perPage,$perPage);
$base=['sucursal'=>$sid,'tipo'=>$tipo,'nivel'=>$nivel,'dias'=>$dias,'q'=>$q];
$counts=['critica'=>0,'alta'=>0,'media'=>0];foreach($out as $r)$counts[$r['nivel']]++;
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>G&D | Alertas de inventario</title>
<style>
:root{font:15px system-ui,-apple-system,Segoe UI,Arial,sans-serif;color:#173b61;background:#edf3fa}*{box-sizing:border-box}body{margin:0}header{background:linear-gradient(110deg,#12365d,#2369b8);color:#fff;padding:28px max(18px,calc((100% - 1320px)/2))}header h1{margin:0 0 5px;font-size:clamp(22px,3.2vw,29px)}header p{margin:0;opacity:.9;font-size:13px}main{max-width:1340px;padding:18px 16px 55px;margin:auto}.nav{display:flex;gap:9px;flex-wrap:wrap;margin-bottom:15px}.btn,button{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:0;background:#1761ae;color:#fff;border-radius:10px;padding:11px 15px;text-decoration:none;cursor:pointer;font:700 13px system-ui}.btn.alt{background:#e2edf8;color:#174e7c}.btn.green{background:#127a58;color:#fff}.card{background:#fff;padding:20px;border-radius:16px;box-shadow:0 7px 22px #1b446d12;margin:0 0 15px}.filters{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;align-items:end}label{font-size:12px;font-weight:700;display:block;margin-bottom:6px}select,input{width:100%;font:inherit;padding:10px 11px;border:1px solid #bfcee0;border-radius:8px;min-width:0}.actions{display:flex;gap:8px;flex-wrap:wrap;align-items:end}.metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:15px}.metric{padding:16px;border-radius:13px;background:#eaf2fb}.metric span{display:block;font-size:12px;color:#52708d}.metric strong{font-size:26px;font-variant-numeric:tabular-nums}.note{padding:13px 15px;border:1px solid #efd09d;border-radius:11px;background:#fff8e8;line-height:1.5;font-size:13px;margin-bottom:12px}.error{border-color:#eaa; background:#fee}.ok{border-color:#acd6b7;background:#edf9f0}.scroll{overflow:auto;border:1px solid #e3ebf4;border-radius:10px}table{border-collapse:collapse;width:100%;min-width:1320px}th,td{border-bottom:1px solid #e6eef6;padding:12px 10px;font-size:12px;text-align:left;vertical-align:top}th{background:#164678;color:#fff;position:sticky;top:0}tbody tr:nth-child(even){background:#f6f9fc}.num{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}.pill{font-size:11px;font-weight:800;display:inline-block;border-radius:100px;padding:5px 9px;background:#eaf0f7;color:#194b7a}.pill.critica{background:#fde5e6;color:#a81d32}.pill.alta{background:#fff0d8;color:#986000}.pill.media{background:#e5f0fa;color:#185989}.muted{font-size:12px;color:#617d97}.pager{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-top:14px}.link{color:#185c9d;text-decoration:none;font-weight:750}.link:hover{text-decoration:underline}@media(max-width:1050px){.filters{grid-template-columns:repeat(2,1fr)}}@media(max-width:700px){.filters,.metrics{grid-template-columns:1fr}.card{padding:15px}}@media print{header{background:none;color:#000}.nav,.filters,.actions,.pager{display:none}table{min-width:0}th{color:#000;background:#ddd}}
</style></head><body>
<header><h1>Centro de Alertas de Inventario</h1><p>G&D · Análisis actual de existencias y movimientos auditados · Solo lectura</p></header>
<main>
<nav class="nav" aria-label="Navegación del módulo"><!-- GYD_CONCILIACION_TT_LINK_2026 --><a class="btn alt" href="erp_conciliacion_tt.php">Conciliación asistida: Tapachula y Toscana</a><a class="btn alt" href="dashboard.php">Inicio ERP</a><a class="btn alt" href="erp_kardex_general.php">Kardex General</a><a class="btn alt" href="erp_conciliacion_inventarios.php">Conciliación de inventarios</a></nav>
<?php if($error):?>
  <section class="card"><div class="note error" role="alert"><?=gai_html($error)?></div></section>
<?php else:?>
<section class="card"><form class="filters" method="get">
  <div><label for="sucursal">Sucursal</label><select id="sucursal" name="sucursal"><option value="0">Todas las sucursales</option><?php foreach(gai_branches() as $k=>$v):?><option value="<?=$k?>" <?=$sid===$k?'selected':''?>><?=gai_html($v)?></option><?php endforeach;?></select></div>
  <div><label for="tipo">Tipo de alerta</label><select id="tipo" name="tipo"><?php foreach($tipos as $k=>$v):?><option value="<?=gai_html($k)?>" <?=$tipo===$k?'selected':''?>><?=gai_html($v)?></option><?php endforeach;?></select></div>
  <div><label for="nivel">Prioridad</label><select id="nivel" name="nivel"><?php foreach($niveles as $k=>$v):?><option value="<?=gai_html($k)?>" <?=$nivel===$k?'selected':''?>><?=gai_html($v)?></option><?php endforeach;?></select></div>
  <div><label for="q">Buscar producto</label><input id="q" name="q" value="<?=gai_html($q)?>" placeholder="Código o descripción"></div>
  <div><label for="dias">Movimientos auditados</label><select id="dias" name="dias"><option value="7" <?=$dias===7?'selected':''?>>Últimos 7 días</option><option value="15" <?=$dias===15?'selected':''?>>Últimos 15 días</option><option value="30" <?=$dias===30?'selected':''?>>Últimos 30 días</option></select></div>
  <div class="actions"><button type="submit">Consultar</button><a class="btn alt" href="erp_alertas_inventario.php">Limpiar</a></div>
</form></section>
<div class="metrics"><div class="metric"><span>Productos activos revisados</span><strong><?=number_format($scan['count_products'])?></strong><div class="muted">En cada sucursal: <?=number_format($scan['stats']['revisados'])?> combinaciones</div></div><div class="metric"><span>Incidencias del filtro</span><strong><?=number_format(count($out))?></strong></div><div class="metric"><span>Prioridad crítica</span><strong><?=number_format($counts['critica'])?></strong></div><div class="metric"><span>Prioridad alta</span><strong><?=number_format($counts['alta'])?></strong></div></div>
<?php if($scan['pool']==='ACTIVO'):?><div class="note error"><b>Configuración peligrosa:</b> sigue activa la configuración antigua que comparte inventario entre Tapachula y Toscana. Los resultados muestran filas propias de cada sucursal, pero no aseguran que el POS esté operando de manera independiente. Desactiva la configuración antigua antes de confiar en las existencias.</div><?php endif;?>
<?php if($scan['audit']['estado']!=='ACTIVA'):?><div class="note"><b>Auditoría no completa:</b> la captura transaccional está en estado <?=gai_html($scan['audit']['estado'])?> (<?= (int)$scan['audit']['disparadores']?> de 9 disparadores). Se revisan existencias actuales, pero no es posible garantizar que todos los movimientos recientes tengan evidencia de antes y después. Instala/verifica primero la auditoría del Kardex.</div><?php endif;?>

<div class="note"><b>Interpretación:</b> en Ciudad Hidalgo y Tuxtla, General debe coincidir con Farmacia + Almacén dentro de la misma sucursal. Tapachula y Toscana tienen inventarios independientes. Los faltantes de registro se muestran como <b>sin datos</b>, nunca como cero. El período elegido afecta solo a la revisión de movimientos auditados, no a las existencias actuales. Una alerta no es un ajuste: confirma conteos, tickets pendientes, entradas y devoluciones antes de corregir. La foto de existencias corresponde al momento de esta consulta, no a un saldo histórico.</div>
<section class="card"><div class="actions" style="justify-content:space-between;margin-bottom:14px"><div><h2 style="margin:0 0 5px;font-size:19px">Incidencias detectadas</h2><div class="muted">Consulta: <?=gai_html($scan['consultado_en'])?> · <?=number_format(count($out))?> alertas según filtros · <?=number_format(count($rows))?> en esta página</div></div><a class="btn green" href="?<?=gai_html(http_build_query(array_merge($base,['exportar'=>'xlsx'])))?>">Descargar Excel (.xlsx)</a></div>
<div class="scroll"><table><thead><tr><th>Sucursal / Producto</th><th>Alerta</th><th>Prioridad</th><th class="num">General</th><th class="num">Farmacia</th><th class="num">Almacén</th><th class="num">Diferencia</th><th>Detalle</th><th>Investigar</th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr>
<td><b><?=gai_html($r['sucursal'])?></b><br><span class="muted"><?=gai_html($r['codigo'])?></span><br><?=gai_html($r['producto'])?></td>
<td><?=gai_html($tipos[$r['tipo']]??$r['tipo'])?></td><td><span class="pill <?=gai_html($r['nivel'])?>"><?=gai_html(ucfirst($r['nivel']))?></span></td>
<td class="num"><?=gai_num($r['general'])?></td><td class="num"><?=gai_num($r['farmacia'])?></td><td class="num"><?=gai_num($r['almacen'])?></td><td class="num"><?=gai_num($r['diferencia'])?></td>
<td><?=gai_html($r['detalle'])?><?php if($r['fecha']):?><div class="muted">Auditoría: <?=gai_html($r['fecha'])?></div><?php endif;?></td>
<td><?php if((int)$r['producto_id']>0):?><a class="link" href="erp_kardex_general.php?<?=gai_html(http_build_query(['sucursal'=>$r['sucursal_id'],'producto'=>$r['producto_id'],'desde'=>date('Y-m-d',strtotime('-30 days')),'hasta'=>date('Y-m-d')]))?>">Ver Kardex</a><?php endif;?></td>
</tr><?php endforeach;?>
<?php if(!$rows):?><tr><td colspan="9">No se encontraron incidencias para estos filtros.</td></tr><?php endif;?></tbody></table></div>
<div class="pager"><span class="muted">Página <?=$page?> de <?=$pages?></span><div class="actions"><?php if($page>1):?><a class="btn alt" href="?<?=gai_html(http_build_query(array_merge($base,['pagina'=>$page-1])))?>">← Anterior</a><?php endif;?><?php if($page<$pages):?><a class="btn alt" href="?<?=gai_html(http_build_query(array_merge($base,['pagina'=>$page+1])))?>">Siguiente →</a><?php endif;?></div></div>
</section>
<?php endif;?></main></body></html>
