<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_oc_recepciones.php';
require_once __DIR__.'/../app/helpers/erp_facturas.php';
require_once __DIR__.'/../app/helpers/erp_oc_integracion_facturas.php';
requireLogin();$u=currentUser()?:[];$ac=erpr_acceso($u);$id=(int)($_GET['oc']??0);
if($id<1){http_response_code(400);exit('Selecciona una orden de compra válida.');}
$error='';$res=null;
try{
    $db=(new Database())->connect();
    $res=gyd_rfi_resumen($db,$id);
    if(!erpr_puede_ver_destino($ac,(string)$res['oc']['destino'])){http_response_code(403);exit('Sin permiso para ese destino.');}
}catch(Throwable $e){error_log('G&D puente OC-factura: '.$e->getMessage());$error=$e instanceof RuntimeException?$e->getMessage():'No se pudo consultar la integración. Revisa los registros PHP.';}
$e=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>G&D · Recepciones y facturas por OC</title><style>
body{margin:0;background:#edf3fa;color:#173c63;font:14px system-ui,sans-serif}header{background:#153f6d;color:#fff;padding:22px 5%}header a{color:#fff}main{max-width:1280px;margin:20px auto;padding:0 14px}.card{background:white;padding:18px;margin:14px 0;border:1px solid #d7e3ef;border-radius:12px}.metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:9px}.metric{padding:12px;border-radius:9px;background:#eff5fc}.metric strong{display:block;font-size:24px}h1{font-size:23px;margin:0 0 6px}h2{font-size:17px}.muted{color:#596b7e}.warn{background:#fff4da;padding:12px;border-radius:8px;border:1px solid #e9cf86}.scroll{overflow:auto}table{border-collapse:collapse;min-width:920px;width:100%}td,th{padding:10px;border-bottom:1px solid #dce6f0;text-align:left}th{background:#16538c;color:white}.btn{background:#155a98;border-radius:7px;padding:9px 12px;color:white;text-decoration:none;display:inline-block;margin:4px 5px 4px 0;font-weight:700}.tag{padding:4px 7px;border-radius:7px;background:#e8f0fa}.bad{color:#a32626;font-weight:800}.ok{color:#1c693c;font-weight:800}@media(max-width:700px){.metrics{grid-template-columns:repeat(2,1fr)}}
</style></head><body><header><h1>G&D · Recepciones → Facturas → Inventario</h1><a href="erp_recepciones_pendientes.php">← Recepciones OC</a></header><main>
<?php if($error):?><section class="card warn" role="alert"><?=$e($error)?></section><?php endif;?>
<?php if($res):$o=$res['oc'];?><section class="card"><h2><?=$e($o['folio'])?> · <?=$e($o['proveedor'])?></h2><p><?=$e(erpr_destinos()[$o['destino']]??$o['destino'])?> · Estado de OC: <?=$e($o['estado'])?></p>
<div class="metrics"><?php foreach(['solicitadas'=>'Solicitadas','buenas'=>'Buenas recibidas','facturadas'=>'Facturadas','verificadas'=>'Verificadas netas','aplicadas'=>'Aplicadas netas','pendientes'=>'Por recibir buenas'] as $k=>$name):?><div class="metric"><strong><?=number_format($res['tot'][$k])?></strong><?=$e($name)?></div><?php endforeach;?></div>
<p class="warn"><b>Importante:</b> solo las piezas BUENAS registradas en la recepción pueden avanzar. Las piezas dañadas, caducadas, sobrantes y faltantes no generan existencias. Esta pantalla es de consulta, no confirma inventario ni cierra órdenes. Las cantidades facturadas pueden ser superiores a las buenas recibidas: se muestra como documentación pendiente, no como entrada.</p>
<a class="btn" href="erp_recepciones_calidad_historial.php?oc=<?=$id?>">Historial de recepciones</a>
<?php if(ef_puede_capturar($u)):?><a class="btn" href="erp_facturas.php">Registrar / consultar facturas</a><?php endif;?>
<a class="btn" href="erp_conciliacion_integral.php?oc=<?=$id?>">Centro de conciliación</a></section>
<section class="card"><h2>Avance por producto</h2><div class="scroll"><table><thead><tr><th>Código</th><th>Producto</th><th>Solicitadas</th><th>Buenas</th><th>Facturadas</th><th>Verificadas netas</th><th>Aplicadas netas</th><th>Por recibir buenas</th><th>Estado</th></tr></thead><tbody>
<?php foreach($res['filas'] as $r):?><tr><td><?=$e($r['codigo'])?></td><td><?=$e($r['descripcion'])?></td><td><?=(int)$r['solicitadas']?></td><td><?=(int)$r['buenas']?></td><td><?=(int)$r['facturadas']?></td><td><?=(int)$r['verificadas']?></td><td><?=(int)$r['aplicadas']?></td><td><?=(int)$r['pendientes']?></td><td><span class="<?=$r['estado']==='REVISAR'?'bad':($r['estado']==='CONCILIADO'?'ok':'tag')?>"><?=$e($r['estado'])?></span></td></tr><?php endforeach;?></tbody></table></div></section>
<section class="card"><h2>Facturas vinculadas</h2><?php if(!$res['facturas']):?><p class="muted">No hay facturas todavía. Compras puede capturar la factura real vinculándola a esta OC.</p><?php endif;?>
<div class="scroll"><table><thead><tr><th>Folio ERP</th><th>Factura proveedor</th><th>Fecha</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
<?php foreach($res['facturas'] as $f):?><tr><td><?=$e($f['folio_interno'])?></td><td><?=$e($f['numero_factura'])?></td><td><?=$e($f['fecha_factura'])?></td><td><?=$e($f['estado'])?></td><td><?php if(ef_puede_ver($u,(string)$f['sucursal'])):?><a class="btn" href="erp_facturas_verificacion.php?id=<?=(int)$f['id']?>">Verificar buenas</a><?php if(ef_puede_verificar($u,(string)$f['sucursal'])):?><a class="btn" href="erp_facturas_aplicar.php?id=<?=(int)$f['id']?>">Aplicación manual</a><?php endif; ?><?php else:?>Sin permiso a la sucursal<?php endif;?></td></tr><?php endforeach;?></tbody></table></div></section>
<section class="card"><h2>Entregas físicas registradas</h2><div class="scroll"><table><thead><tr><th>Folio REC</th><th>Fecha</th><th>Referencia</th><th>Piezas buenas</th></tr></thead><tbody><?php foreach($res['entregas'] as $r):?><tr><td><?=$e($r['folio'])?></td><td><?=$e($r['fecha_recepcion'])?></td><td><?=$e($r['referencia_factura']??'')?></td><td><?=(int)$r['buenas']?></td></tr><?php endforeach;?></tbody></table></div><p class="muted">Las incidencias (dañadas, faltantes, sobrantes y caducadas) se consultan en Historial de recepciones. Una entrega posterior crea su propio folio REC y aumenta exclusivamente las piezas buenas acumuladas.</p></section><?php endif;?></main>
<script src="assets/js/gyd_excel_reportes.js" defer></script>

<?php if (!defined('GYD_HIDE_GLOBAL_NAV')): ?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=4">
<script>window.GYD_NAV_HOME='index.php';window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=4"></script>
<?php endif; ?>
</body></html>
