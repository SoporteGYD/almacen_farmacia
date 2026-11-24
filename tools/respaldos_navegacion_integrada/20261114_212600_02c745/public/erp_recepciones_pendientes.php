<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_oc_recepciones.php';
requireLogin();
$acceso=erpr_acceso(currentUser()?:[]);
$destinos=erpr_destinos();
$filtro=trim((string)($_GET['destino']??''));
if($filtro!==''&&!array_key_exists($filtro,$destinos))$filtro='';
if($acceso['destino']!==null) $filtro=$acceso['destino'];
$rows=[];$error='';$totales=['ordenes'=>0,'solicitadas'=>0,'recibidas'=>0,'pendientes'=>0];
try{
    if($filtro!=='__SIN_DESTINO__'){
        $pdo=(new Database())->connect();
        $rows=erpr_resumen($pdo,$filtro,400);
        foreach($rows as $row){
            $totales['ordenes']++;
            $totales['solicitadas']+=(int)$row['solicitadas'];
            $totales['recibidas']+=(int)$row['recibidas'];
            $totales['pendientes']+=max(0,(int)$row['solicitadas']-(int)$row['recibidas']);
        }
    }
}catch(Throwable $e){error_log('ERP pendientes recepción: '.$e->getMessage());$error='No se pudo consultar la bandeja. Comprueba que ejecutaste sql/erp_oc_recepciones.sql.';}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ERP G&D | Pendientes de recepción</title><style>
:root{font:15px system-ui,Segoe UI,Arial,sans-serif;background:#f0f4fa;color:#16375b}*{box-sizing:border-box}body{margin:0}.top{background:#123e69;color:white;padding:23px max(18px,calc((100% - 1210px)/2));border-bottom:4px solid #cf2945;display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}.top h1{margin:0;font-size:22px}.top small{color:#cfdef1}.top a{color:white;text-decoration:none;border:1px solid #99b6d3;padding:11px 13px;border-radius:9px}main{max-width:1220px;margin:22px auto;padding:0 16px}.card{background:white;border-radius:14px;padding:20px;margin-bottom:16px;box-shadow:0 5px 20px #0d345311}.warning{background:#fff4de;border:1px solid #edddbb;padding:14px 16px;border-radius:10px;margin-bottom:15px}.error{background:#fee6e6}.summary{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.summary div{background:#eef5fd;border-radius:10px;padding:17px}.summary strong{display:block;font-size:26px;color:#13589e}.summary small{color:#5b7490}.controls{display:flex;gap:12px;align-items:end;flex-wrap:wrap}label{display:grid;gap:6px;font-size:13px;font-weight:700}select{padding:11px;border:1px solid #bcd0e3;border-radius:9px;min-width:260px;max-width:100%}.btn,button{background:#185fa9;border:0;color:white;border-radius:9px;padding:11px 13px;text-decoration:none;font:inherit;font-weight:700;cursor:pointer;display:inline-block}.secondary{background:#e6f0fb;color:#164a77}.scroll{overflow-x:auto}table{width:100%;min-width:1020px;border-collapse:collapse}th,td{text-align:left;border-bottom:1px solid #e2e9f3;padding:12px;font-size:13px}th{color:#fff;background:#16497d}.tag{background:#eaf2ff;color:#20568e;padding:5px 9px;border-radius:16px;font-weight:700}.muted{color:#6b8296}.actions{white-space:nowrap}h2{font-size:18px;margin:0 0 16px}@media(max-width:760px){.summary{grid-template-columns:repeat(2,1fr)}.top h1{font-size:19px}.card{padding:14px}}@media(max-width:440px){.summary{grid-template-columns:1fr}}
</style></head><body><?php require __DIR__.'/../app/views/layouts/erp_barra_sesion.php'; ?><header class="top"><div><h1>ERP G&amp;D · Pendientes de recepción</h1><small>Seguimiento de órdenes autorizadas y entregas parciales</small></div><div><a href="erp_recepciones_historial.php">Historial de recepciones</a> <a href="erp_ordenes_compra.php">Órdenes</a></div></header><main>
<div class="warning"><b>Control de seguridad:</b> registrar una recepción <b>no aumenta existencias</b>, no crea entradas ni actualiza costos. El módulo de inventario se conectará en una etapa posterior.</div>
<?php if($error):?><div class="warning error" role="alert"><?=erpr_html($error)?></div><?php endif;?>
<?php if($acceso['destino']==='__SIN_DESTINO__'):?><div class="warning">Tu usuario no tiene una bodega vinculada para consultar recepciones. Solicita al administrador la asignación de destino. Los responsables de farmacias deberán usar una asignación específica en una etapa posterior.</div><?php endif;?>
<section class="card summary"><div><strong><?=number_format($totales['ordenes'])?></strong><small>Órdenes con pendientes</small></div><div><strong><?=number_format($totales['solicitadas'])?></strong><small>Piezas solicitadas</small></div><div><strong><?=number_format($totales['recibidas'])?></strong><small>Piezas ya registradas</small></div><div><strong><?=number_format($totales['pendientes'])?></strong><small>Piezas por recibir</small></div></section>
<section class="card"><h2>Filtrar por destino</h2><form method="get" class="controls"><label>Farmacia o bodega<select name="destino" <?=$acceso['destino']!==null?'disabled':''?>><option value="">Todos los destinos</option><?php foreach($destinos as $key=>$name):?><option value="<?=erpr_html($key)?>" <?=$key===$filtro?'selected':''?>><?=erpr_html($name)?></option><?php endforeach;?></select></label><button type="submit">Aplicar filtro</button><a class="btn secondary" href="erp_recepciones_pendientes.php">Limpiar</a></form></section>
<section class="card"><h2>Órdenes por recibir</h2><div class="scroll"><table><thead><tr><th>Orden</th><th>Proveedor</th><th>Destino</th><th>Solicitadas</th><th>Recibidas</th><th>Pendientes</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
<?php foreach($rows as $r):$pedido=(int)$r['solicitadas'];$recibidas=(int)$r['recibidas'];$rest=max(0,$pedido-$recibidas);$puede=erpr_puede_registrar_destino($acceso,(string)$r['destino']);?><tr><td><b><?=erpr_html($r['folio'])?></b><div class="muted"><?=erpr_html($r['fecha'])?></div></td><td><?=erpr_html($r['proveedor'])?></td><td><?=erpr_html($destinos[$r['destino']]??$r['destino'])?></td><td><?=number_format($pedido)?></td><td><?=number_format($recibidas)?></td><td><b><?=number_format($rest)?></b></td><td><span class="tag"><?=$recibidas>0?'Parcial':'Pendiente'?></span></td><td class="actions"><?php if($puede):?><a class="btn" href="erp_recepcion_registrar.php?id=<?=(int)$r['id']?>">Registrar recepción</a> <?php else:?><a class="btn secondary" href="erp_recepcion_registrar.php?id=<?=(int)$r['id']?>">Consultar</a><?php endif;?> <a class="btn secondary" target="_blank" rel="noopener" href="erp_orden_compra_pdf.php?id=<?=(int)$r['id']?>">PDF</a></td></tr><?php endforeach;?>
<?php if(!$rows):?><tr><td colspan="8" class="muted">No hay órdenes con cantidades pendientes para este filtro.</td></tr><?php endif;?></tbody></table></div></section></main><script defer src="assets/js/erp_notificaciones.js?v=1"></script>
<script src="assets/js/gyd_feedback_modal.js?v=5" defer></script>

<script src="assets/js/gyd_excel_reportes.js" defer></script>

<?php if (!defined('GYD_HIDE_GLOBAL_NAV')): ?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=1">
<script>window.GYD_NAV_HOME='index.php';window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=1"></script>
<?php endif; ?>
</body></html>
