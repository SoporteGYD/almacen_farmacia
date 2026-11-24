<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_oc_recepciones.php';
requireLogin();
$acceso=erpr_acceso(currentUser()?:[]);
$destinos=erpr_destinos();$rows=[];$lineas=[];$detalle=null;$error='';
$ver=(int)($_GET['ver']??0);
$destino=trim((string)($_GET['destino']??''));
if(!isset($destinos[$destino]))$destino='';
if($acceso['destino']!==null)$destino=$acceso['destino'];
try{
    if($destino!=='__SIN_DESTINO__'){
        $pdo=(new Database())->connect();
        $sql="SELECT r.id,r.folio,r.fecha_recepcion,r.referencia_factura,r.observaciones,r.destino,r.estado,r.creado_en,
                 o.folio AS orden_folio,o.id AS orden_id,p.nombre AS proveedor,u.nombre AS receptor,
                 COALESCE(SUM(rd.cantidad_piezas),0) AS piezas
              FROM erp_oc_recepciones r
              JOIN erp_ordenes_compra o ON o.id=r.orden_id
              JOIN proveedores p ON p.id=o.proveedor_id
              LEFT JOIN usuarios u ON u.id=r.usuario_id
              LEFT JOIN erp_oc_recepciones_detalle rd ON rd.recepcion_id=r.id
              WHERE 1=1";
        $args=[];
        if($destino!==''){$sql.=' AND r.destino=:destino';$args['destino']=$destino;}
        $sql.=' GROUP BY r.id,r.folio,r.fecha_recepcion,r.referencia_factura,r.observaciones,r.destino,r.estado,r.creado_en,o.folio,o.id,p.nombre,u.nombre ORDER BY r.id DESC LIMIT 300';
        $st=$pdo->prepare($sql);$st->execute($args);$rows=$st->fetchAll(PDO::FETCH_ASSOC);
        if($ver>0){
            $st=$pdo->prepare("SELECT r.*,o.folio AS orden_folio,o.id AS orden_id,p.nombre AS proveedor,u.nombre AS receptor
                 FROM erp_oc_recepciones r JOIN erp_ordenes_compra o ON o.id=r.orden_id
                 JOIN proveedores p ON p.id=o.proveedor_id LEFT JOIN usuarios u ON u.id=r.usuario_id
                 WHERE r.id=?");
            $st->execute([$ver]);$detalle=$st->fetch(PDO::FETCH_ASSOC)?:null;
            if(!$detalle){http_response_code(404);exit('Recepción no encontrada.');}
            if(!erpr_puede_ver_destino($acceso,(string)$detalle['destino'])){http_response_code(403);exit('Acceso a destino no autorizado.');}
            $st=$pdo->prepare("SELECT rd.cantidad_piezas,rd.lote,rd.caducidad,d.codigo_snapshot,d.descripcion_snapshot
                 FROM erp_oc_recepciones_detalle rd
                 JOIN erp_ordenes_compra_detalle d ON d.id=rd.orden_detalle_id
                 WHERE rd.recepcion_id=? ORDER BY rd.id");
            $st->execute([$ver]);$lineas=$st->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}catch(Throwable $e){error_log('ERP historial recepción: '.$e->getMessage());$error='No se pudo consultar el historial. Comprueba que instalaste sql/erp_oc_recepciones.sql.';}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ERP G&D | Historial de recepciones</title><style>
:root{font:15px system-ui,Segoe UI,Arial,sans-serif;color:#183959;background:#eff4fa}*{box-sizing:border-box}body{margin:0}.head{background:#123e6b;color:white;padding:22px max(18px,calc((100% - 1170px)/2));border-bottom:4px solid #ce2e47;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}h1{font-size:22px;margin:0}.head a{color:white;border:1px solid #9ab8d5;padding:10px 12px;border-radius:8px;text-decoration:none}main{max-width:1180px;margin:20px auto;padding:0 14px}.panel{background:white;padding:20px;margin-bottom:15px;border-radius:14px;box-shadow:0 4px 20px #1236530d}.warning{padding:14px;border:1px solid #e7d9a9;background:#fff5df;border-radius:9px;margin-bottom:15px}.error{background:#fee6e6}.row{display:flex;gap:10px;align-items:end;flex-wrap:wrap}label{display:grid;gap:7px;font-weight:700;font-size:13px}select{border:1px solid #bdd0e4;padding:10px;border-radius:9px;min-width:240px}.btn,button{background:#165da7;color:white;border:0;border-radius:9px;padding:10px 12px;display:inline-block;text-decoration:none;font:inherit;cursor:pointer;font-weight:700}.secondary{background:#eaf2fb;color:#194c7a}.scroll{overflow:auto}table{width:100%;min-width:900px;border-collapse:collapse}th{background:#16497f;color:white;text-align:left}th,td{padding:11px;border-bottom:1px solid #e3ebf3;font-size:13px}.muted{color:#6c8295;font-size:13px}.chips{display:flex;gap:12px;flex-wrap:wrap}.chips span{background:#eff5fc;border-radius:9px;padding:10px}h2{margin-top:0;font-size:18px}.tag{background:#e7f0fd;color:#1b538e;border-radius:20px;padding:5px 9px;font-weight:700}.ok{background:#e1f4e7;padding:13px;border-radius:10px;margin-bottom:15px}@media(max-width:750px){.panel{padding:14px}.head h1{font-size:19px}}
</style></head><body><?php require __DIR__.'/../app/views/layouts/erp_barra_sesion.php'; ?><header class="head"><div><h1>ERP G&amp;D · Historial de recepciones</h1><small>Entregas registradas · Sin aplicación al inventario</small></div><div><a href="erp_recepciones_pendientes.php">← Pendientes</a></div></header><main>
<div class="warning"><b>Atención:</b> todas las recepciones de este módulo se guardan <b>sin actualizar existencias</b>. El folio REC indica recepción registrada para seguimiento, no entrada de almacén aplicada.</div>
<?php if($error):?><div class="warning error"><?=erpr_html($error)?></div><?php endif;?>
<?php if(($_GET['ok']??'')==='1'):?><div class="ok">Recepción guardada correctamente. Las cantidades quedaron registradas y pendientes de aplicar al inventario.</div><?php endif;?>
<?php if($acceso['destino']==='__SIN_DESTINO__'):?><div class="warning">No tienes bodega vinculada para consultar este módulo. Solicita la asignación correspondiente.</div><?php endif;?>
<?php if($detalle):?><section class="panel"><h2>Detalle de <?=erpr_html($detalle['folio'])?></h2><p><a class="btn secondary" href="erp_recepciones_calidad_historial.php?rec=<?=(int)$detalle['id']?>">Ver incidencias y entregas complementarias</a></p><div class="chips"><span><b>Orden:</b> <?=erpr_html($detalle['orden_folio'])?></span><span><b>Proveedor:</b> <?=erpr_html($detalle['proveedor'])?></span><span><b>Destino:</b> <?=erpr_html($destinos[$detalle['destino']]??$detalle['destino'])?></span><span><b>Fecha:</b> <?=erpr_html($detalle['fecha_recepcion'])?></span><span><b>Recibido por:</b> <?=erpr_html($detalle['receptor'])?></span></div><p><b>Factura o remisión:</b> <?=erpr_html($detalle['referencia_factura']?:'Sin registrar')?></p><p><b>Observaciones:</b> <?=erpr_html($detalle['observaciones']?:'Ninguna')?></p><p><span class="tag">REGISTRADA · NO APLICADA A INVENTARIO</span></p><div class="scroll"><table><thead><tr><th>Código</th><th>Producto</th><th>Piezas recibidas</th><th>Lote</th><th>Caducidad</th></tr></thead><tbody><?php foreach($lineas as $r):?><tr><td><?=erpr_html($r['codigo_snapshot'])?></td><td><?=erpr_html($r['descripcion_snapshot'])?></td><td><b><?=number_format((int)$r['cantidad_piezas'])?></b></td><td><?=erpr_html($r['lote']?:'No capturado')?></td><td><?=erpr_html($r['caducidad']?:'No capturada')?></td></tr><?php endforeach;?></tbody></table></div><p><a class="btn secondary" href="erp_recepcion_registrar.php?id=<?=(int)$detalle['orden_id']?>">Ver avance de esta orden</a>
<?php if($detalle['estado']==='REGISTRADA' && in_array($detalle['destino'],['HIDALGO_BODEGA','TUXTLA_BODEGA'],true) && erpr_puede_registrar_destino($acceso,(string)$detalle['destino'])):?>
<a class="btn" href="entradas.php?oc_recepcion=<?=(int)$detalle['id']?>">📥 Preparar entrada de Almacén desde esta recepción</a>
<?php endif;?> <a class="btn secondary" href="erp_oc_inventarios.php?oc=<?=(int)$detalle['orden_id']?>">Comparar existencias Almacén / POS</a></p></section><?php endif;?>
<section class="panel"><h2>Buscar recepciones registradas</h2><form method="get" class="row"><label>Destino<select name="destino" <?=$acceso['destino']!==null?'disabled':''?>><option value="">Todos</option><?php foreach($destinos as $k=>$v):?><option value="<?=erpr_html($k)?>" <?=$destino===$k?'selected':''?>><?=erpr_html($v)?></option><?php endforeach;?></select></label><button>Filtrar</button><a class="btn secondary" href="erp_recepciones_historial.php">Limpiar</a></form></section>
<section class="panel"><h2>Últimas recepciones</h2><div class="scroll"><table><thead><tr><th>Folio recepción</th><th>Orden</th><th>Fecha</th><th>Proveedor</th><th>Destino</th><th>Piezas</th><th>Recibido por</th><th>Estado</th><th>Acción</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><b><?=erpr_html($r['folio'])?></b></td><td><?=erpr_html($r['orden_folio'])?></td><td><?=erpr_html($r['fecha_recepcion'])?></td><td><?=erpr_html($r['proveedor'])?></td><td><?=erpr_html($destinos[$r['destino']]??$r['destino'])?></td><td><?=number_format((int)$r['piezas'])?></td><td><?=erpr_html($r['receptor'])?></td><td><span class="tag"><?=erpr_html($r['estado'])?></span></td><td><a class="btn" href="erp_recepciones_historial.php?ver=<?=(int)$r['id']?>">Ver detalle</a></td></tr><?php endforeach;?><?php if(!$rows):?><tr><td colspan="9" class="muted">Todavía no hay recepciones registradas.</td></tr><?php endif;?></tbody></table></div></section></main></body></html>
