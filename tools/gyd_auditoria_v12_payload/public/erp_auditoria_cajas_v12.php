<?php
declare(strict_types=1);
/** V12 — consultas exclusivamente. No incluye handlers POST ni operaciones SQL de escritura. */
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/gyd_auditoria_cajas_v12.php';
requireLogin();
$usuario=currentUser()??[];
if(!gac12_rol_permitido($usuario)){http_response_code(403);exit('Acceso reservado a Gerencia, Dueños y Administración.');}
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
function gac12_param(string $key,string $default=''):string {
    return isset($_GET[$key]) && is_scalar($_GET[$key])?trim((string)$_GET[$key]):$default;
}
$hoy=(new DateTimeImmutable('today'))->format('Y-m-d');
$desde=gac12_param('desde',(new DateTimeImmutable('-6 days'))->format('Y-m-d'));
$hasta=gac12_param('hasta',$hoy);
$sucursal=filter_var(gac12_param('sucursal','0'),FILTER_VALIDATE_INT,['options'=>['min_range'=>0]])?:0;
$exportar=gac12_param('exportar');
$datos=null;$error='';
try {
    if($_SERVER['REQUEST_METHOD']!=='GET')throw new DomainException('Este módulo solo permite consultas.');
    $db=(new Database())->connect();
    if((string)$db->query('SELECT DATABASE()')->fetchColumn()!=='prueba')throw new DomainException('Este módulo V12 únicamente funciona con la base de pruebas `prueba`.');
    $datos=gac12_reporte($db,$desde,$hasta,$sucursal);
}catch(Throwable $e){
    $error=$e instanceof DomainException?$e->getMessage():'No se pudo generar el reporte: comprueba las tablas y la conexión MySQL.';
    error_log('G&D auditoría V12: '.$e->getMessage());
}
// Exportación CSV con datos filtrados, sin guardar nada en base de datos.
if($datos!==null && $exportar!==''){
    $opciones=['cajas','turnos','retiros','intentos','eventos'];
    if(!in_array($exportar,$opciones,true)){http_response_code(400);exit('Exportación no válida.');}
    $columnas=[
      'cajas'=>['Sucursal','Caja','Estado','Turno','Cajero','Abierta desde'],
      'turnos'=>['Turno','Sucursal','Caja','Cajero','Abierto','Cerrado','Estado','Fondo','Esperado del corte','Contado','Resultado','Diferencia absoluta','Usuario cierre','Autorizador','Modalidad','Comprobante denominaciones'],
      'retiros'=>['Folio','Sucursal','Caja','Turno','Cajero','Fecha','Importe','Clase','Movimiento POS'],
      'intentos'=>['Fecha','Operacion','Solicitante','Validacion'],
      'eventos'=>['Fecha','Usuario','Accion','Venta ID'],
    ];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="gyd_auditoria_'.$exportar.'_'.$desde.'_'.$hasta.'.csv"');
    $fp=fopen('php://output','wb');fwrite($fp,"\xEF\xBB\xBF");fwrite($fp,"sep=;\r\n");
    $csv=static function(array $fields)use($fp):void{fputcsv($fp,$fields,';', '"', '');};
    $csv($columnas[$exportar]);
    foreach($datos[$exportar] as $r){
        switch($exportar){
        case 'cajas':
          $csv(array_map('gac12_csvtexto',[$r['sucursal'],$r['caja'],$r['turno_id']!==null?'ABIERTO / PENDIENTE DE CORTE':'SIN TURNO ABIERTO',(string)($r['turno_id']??''),$r['cajero'],$r['abierto_en']]));break;
        case 'turnos':
          $d=$r['dif_c'];
          $csv([ (int)$r['id'],gac12_csvtexto($r['sucursal']),gac12_csvtexto($r['caja']),gac12_csvtexto($r['cajero']),$r['abierto_en'],$r['cerrado_en'],gac12_csvtexto($r['estado']),gac12_decimal($r['fondo_c']),gac12_decimal($r['esperado_c']),gac12_decimal($r['contado_c']),$r['tipo_dif'],gac12_decimal($d===null?null:abs($d)),gac12_csvtexto($r['usuario_cierre']),gac12_csvtexto($r['autorizador']),gac12_csvtexto($r['modalidad']),(string)($r['comprobante_id']??'')]);break;
        case 'retiros':
          $csv([($r['clase']==='BOVEDA'?'RET-'.str_pad((string)$r['id'],8,'0',STR_PAD_LEFT):'MOV-'.(int)$r['id']),gac12_csvtexto($r['sucursal']),gac12_csvtexto($r['caja']),(int)$r['turno_id'],gac12_csvtexto($r['cajero']),$r['creado_en'],gac12_decimal($r['importe_c']),$r['clase'],(int)$r['movimiento_id']]);break;
        case 'intentos':
          $csv([$r['creado_en'],$r['origen'],gac12_csvtexto($r['usuario']),(int)$r['valido']===1?'CORRECTA':'RECHAZADA']);break;
        case 'eventos':
          $csv([$r['creado_en'],gac12_csvtexto($r['usuario']),gac12_csvtexto($r['accion']),(string)($r['venta_id']??'')]);break;
        }
    }
    fclose($fp);exit;
}
$params=['desde'=>$desde,'hasta'=>$hasta,'sucursal'=>$sucursal];
function gac12_url(array $params,string $tipo):string {return '?'.http_build_query($params+['exportar'=>$tipo]);}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>G&D · Auditoría y Control de Cajas V12</title>
<style>
:root{color-scheme:light;font-family:system-ui,-apple-system,Segoe UI,Arial,sans-serif;background:#f3f6fa;color:#152b46}
*{box-sizing:border-box}body{margin:0}main{max-width:1440px;margin:0 auto;padding:25px 20px 55px}header{background:#103e78;color:white;padding:22px 24px}header .inner{max-width:1440px;margin:auto;display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap}h1{font-size:25px;margin:0 0 4px}header p{margin:0;color:#dce8fb}a{color:#164f95}header a{color:white}h2{font-size:19px;margin:0 0 12px}.sub{color:#56718c;font-size:13px}.card{background:white;border:1px solid #d8e1ea;border-radius:13px;padding:18px;margin:18px 0;box-shadow:0 2px 11px #1736590d}.filters{display:flex;align-items:end;gap:12px;flex-wrap:wrap}label{display:grid;gap:6px;font-size:13px;font-weight:700}input,select,button,.btn{font:inherit;border:1px solid #b9cadd;border-radius:8px;padding:9px 11px;background:white;min-height:39px}button,.btn{display:inline-block;background:#1357a0;color:white;border:0;cursor:pointer;text-decoration:none;font-weight:650;font-size:14px}.btn.secondary,button.secondary{background:#e9eff6;color:#143c6a}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px}.stat{border:1px solid #d8e1ea;border-radius:11px;padding:14px}.stat span{color:#506982;font-size:13px}.stat b{display:block;font-size:24px;margin-top:5px}.table-scroll{width:100%;overflow:auto}table{border-collapse:collapse;width:100%;font-size:13px;white-space:nowrap}th,td{text-align:left;padding:10px 12px;border-bottom:1px solid #e4eaf0}th{background:#eff4f9;font-weight:700}tr:nth-child(even) td{background:#fafcff}.money{text-align:right;font-variant-numeric:tabular-nums}.pill{display:inline-block;font-size:12px;font-weight:750;padding:4px 8px;border-radius:20px;background:#e7edf4;color:#37526a}.bad{background:#fff1ef;color:#b22929}.good{background:#e9f8f0;color:#16633c}.warn{background:#fff5de;color:#865400}.alert{padding:12px;border:1px solid #eccd86;background:#fff9e9;border-radius:8px;margin:8px 0}.error{background:#fff0ee;border:1px solid #dfaaa2;padding:12px;border-radius:8px}.headrow{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px}.actions{display:flex;gap:8px;flex-wrap:wrap}.fine{font-size:12px;color:#5a6e80;line-height:1.5}.empty{padding:16px;text-align:center;color:#59748d}
@media print{header a,.filters,.actions,.no-print,button{display:none!important}body,:root{background:#fff}main{padding:0}.card{box-shadow:none;page-break-inside:auto}tr{break-inside:avoid}.table-scroll{overflow:visible}table{white-space:normal;font-size:10px}h1{font-size:20px}}@media(max-width:650px){main{padding:13px}header{padding:17px}h1{font-size:20px}.stat b{font-size:21px}}
</style></head><body>
<header><div class="inner"><div><h1>Auditoría y Control de Cajas</h1><p>Farmacia G&D · V12 · Solo lectura · Base de pruebas</p></div><nav><a href="pos.php">POS</a> · <a href="erp_reportes_ventas_cajas.php">Reportes Gerenciales</a></nav></div></header>
<main>
<section class="card no-print"><h2>Filtros y consulta</h2><form class="filters" method="get">
<label>Desde <input type="date" name="desde" value="<?=gac12_h($desde)?>" required></label>
<label>Hasta <input type="date" name="hasta" value="<?=gac12_h($hasta)?>" required></label>
<label>Sucursal<select name="sucursal"><option value="0">Todas las sucursales</option><?php foreach(($datos['sucursales']??[]) as $s):?><option value="<?=(int)$s['id']?>" <?=$sucursal===(int)$s['id']?'selected':''?>><?=gac12_h($s['nombre'])?></option><?php endforeach;?></select></label>
<button type="submit">Consultar</button><button type="button" class="secondary" onclick="window.print()">Imprimir / Guardar PDF</button>
</form></section>
<?php if($error!==''):?><div class="error" role="alert"><?=gac12_h($error)?></div><?php else:?>
<?php foreach($datos['avisos'] as $aviso):?><div class="alert" role="status"><?=gac12_h($aviso)?></div><?php endforeach;?>
<section class="grid" aria-label="Indicadores del periodo">
<?php foreach([
 ['Cajas abiertas ahora',(string)$datos['resumen']['abiertas']],
 ['Cortes en periodo',(string)$datos['resumen']['cerradas']],
 ['Cortes con faltante',(string)$datos['resumen']['faltantes']],
 ['Faltantes acumulados',gac12_dinero($datos['resumen']['importe_faltantes'])],
 ['Cortes con sobrante',(string)$datos['resumen']['sobrantes']],
 ['Sobrantes acumulados',gac12_dinero($datos['resumen']['importe_sobrantes'])],
 ['Retiros mostrados',(string)$datos['resumen']['retiros']],
 ['Intentos rechazados*',(string)$datos['resumen']['intentos_fallidos']]
] as $par):?><div class="stat"><span><?=gac12_h($par[0])?></span><b><?=gac12_h($par[1])?></b></div><?php endforeach;?></section>
<section class="card"><div class="headrow"><div><h2>Estado actual de las cajas</h2><p class="sub">Independiente de las fechas; caja abierta significa turno pendiente de corte.</p></div><div class="actions"><a class="btn secondary" href="<?=gac12_h(gac12_url($params,'cajas'))?>">Exportar CSV</a></div></div>
<div class="table-scroll"><table><thead><tr><th>Sucursal</th><th>Caja</th><th>Estado</th><th>Turno</th><th>Cajero</th><th>Desde</th></tr></thead><tbody>
<?php foreach($datos['cajas'] as $r):?><tr><td><?=gac12_h($r['sucursal'])?></td><td><?=gac12_h($r['caja'])?></td><td><span class="pill <?=$r['turno_id']===null?'good':'warn'?>"><?=$r['turno_id']===null?'SIN TURNO ABIERTO':'ABIERTA · PENDIENTE DE CORTE'?></span></td><td><?=gac12_h($r['turno_id']??'—')?></td><td><?=gac12_h($r['cajero']??'—')?></td><td><?=gac12_h($r['abierto_en']??'—')?></td></tr><?php endforeach;?>
<?php if(!$datos['cajas']):?><tr><td colspan="6" class="empty">No hay cajas activas para este filtro.</td></tr><?php endif;?></tbody></table></div></section>
<section class="card"><div class="headrow"><div><h2>Historial de turnos y cortes</h2><p class="sub">El efectivo esperado se obtiene de contado menos diferencia almacenada al cerrar; no reemplaza la conciliación independiente.</p></div><div class="actions"><a class="btn secondary" href="<?=gac12_h(gac12_url($params,'turnos'))?>">Exportar CSV</a></div></div>
<div class="table-scroll"><table><thead><tr><th>Turno</th><th>Sucursal / caja</th><th>Cajero</th><th>Apertura</th><th>Cierre</th><th>Esperado</th><th>Contado</th><th>Resultado</th><th>Responsable de cierre</th><th>Autorizó</th><th>Detalle</th></tr></thead><tbody>
<?php foreach($datos['turnos'] as $t):?><tr><td>#<?=(int)$t['id']?></td><td><?=gac12_h($t['sucursal'])?> / <?=gac12_h($t['caja'])?></td><td><?=gac12_h($t['cajero']??'—')?></td><td><?=gac12_h($t['abierto_en'])?></td><td><?=gac12_h($t['cerrado_en']??'—')?></td><td class="money"><?=gac12_dinero($t['esperado_c'])?></td><td class="money"><?=gac12_dinero($t['contado_c'])?></td><td><span class="pill <?=$t['tipo_dif']==='FALTANTE'?'bad':($t['tipo_dif']==='SOBRANTE'?'warn':($t['tipo_dif']==='EXACTO'?'good':''))?>"><?=gac12_h($t['tipo_dif'])?></span><?=in_array($t['tipo_dif'],['SOBRANTE','FALTANTE'],true)?' '.gac12_dinero(abs($t['dif_c'])):''?></td><td><?=gac12_h($t['usuario_cierre']??'—')?></td><td><?=gac12_h($t['autorizador']??'Sin registro V9')?></td><td><?=gac12_h($t['comprobante_id']===null?'Sin desglose guardado':'Denominaciones registradas')?></td></tr><?php endforeach;?>
<?php if(!$datos['turnos']):?><tr><td colspan="11" class="empty">No hay turnos en las fechas seleccionadas.</td></tr><?php endif;?></tbody></table></div></section>
<section class="card"><div class="headrow"><div><h2>Historial de retiros</h2><p class="sub">Los retiros de bóveda se identifican con su folio; no se requiere verificación posterior.</p></div><div class="actions"><a class="btn secondary" href="<?=gac12_h(gac12_url($params,'retiros'))?>">Exportar CSV</a></div></div>
<div class="table-scroll"><table><thead><tr><th>Folio</th><th>Sucursal / caja</th><th>Turno</th><th>Cajero</th><th>Fecha</th><th>Importe</th><th>Clase</th><th>Movimiento</th></tr></thead><tbody>
<?php foreach($datos['retiros'] as $r):?><tr><td><?=gac12_h($r['clase']==='BOVEDA'?'RET-'.str_pad((string)$r['id'],8,'0',STR_PAD_LEFT):'MOV-'.(int)$r['id'])?></td><td><?=gac12_h($r['sucursal'])?> / <?=gac12_h($r['caja'])?></td><td>#<?=(int)$r['turno_id']?></td><td><?=gac12_h($r['cajero']??'—')?></td><td><?=gac12_h($r['creado_en'])?></td><td class="money"><?=gac12_dinero($r['importe_c'])?></td><td><?=gac12_h($r['clase'])?></td><td>#<?=(int)$r['movimiento_id']?></td></tr><?php endforeach;?>
<?php if(!$datos['retiros']):?><tr><td colspan="8" class="empty">Sin retiros en este periodo.</td></tr><?php endif;?></tbody></table></div></section>
<section class="card"><div class="headrow"><div><h2>Intentos de autenticación del corte y retiro</h2><p class="sub">Actividad global; las tablas de intentos no registran sucursal ni caja. No se almacenan ni se muestran contraseñas aquí.</p></div><div class="actions"><a class="btn secondary" href="<?=gac12_h(gac12_url($params,'intentos'))?>">Exportar CSV</a></div></div>
<div class="table-scroll"><table><thead><tr><th>Fecha</th><th>Operación</th><th>Solicitante</th><th>Validación</th></tr></thead><tbody>
<?php foreach($datos['intentos'] as $r):?><tr><td><?=gac12_h($r['creado_en'])?></td><td><?=gac12_h($r['origen'])?></td><td><?=gac12_h($r['usuario']??'Cuenta no disponible')?></td><td><span class="pill <?=(int)$r['valido']===1?'good':'bad'?>"><?=(int)$r['valido']===1?'CORRECTA':'RECHAZADA'?></span></td></tr><?php endforeach;?>
<?php if(!$datos['intentos']):?><tr><td colspan="4" class="empty">Sin intentos registrados o tablas de seguridad no instaladas.</td></tr><?php endif;?></tbody></table></div></section>
<section class="card"><div class="headrow"><div><h2>Bitácora de operaciones de caja</h2><p class="sub">Actividad global de pos_auditoria; no se atribuye a una sucursal sin evidencia. Se omiten detalles JSON para proteger datos sensibles.</p></div><div class="actions"><a class="btn secondary" href="<?=gac12_h(gac12_url($params,'eventos'))?>">Exportar CSV</a></div></div>
<div class="table-scroll"><table><thead><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Venta</th></tr></thead><tbody>
<?php foreach($datos['eventos'] as $r):?><tr><td><?=gac12_h($r['creado_en'])?></td><td><?=gac12_h($r['usuario']??'Sistema')?></td><td><?=gac12_h($r['accion'])?></td><td><?=gac12_h($r['venta_id']??'—')?></td></tr><?php endforeach;?>
<?php if(!$datos['eventos']):?><tr><td colspan="4" class="empty">Sin movimientos auditados en el periodo.</td></tr><?php endif;?></tbody></table></div></section>
<p class="fine">* Los indicadores de intentos corresponden a las filas visibles de las tablas instaladas. Los resultados se limitan a 300 movimientos por sección y la interfaz avisará si hay registros adicionales. Este panel no autoriza retiros, no valida cortes, no modifica saldos y no reemplaza el módulo de conciliación diaria.</p>
<?php endif;?></main></body></html>
