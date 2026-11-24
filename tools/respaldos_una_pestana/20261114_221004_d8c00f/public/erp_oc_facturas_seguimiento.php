<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_oc_recepciones.php';
requireLogin();
$usr=currentUser()?:[];$role=strtoupper(trim((string)($usr['rol']??'')));
if(!in_array($role,['ADMINISTRADOR','ADMIN','DIRECCION','COMPRAS'],true)){http_response_code(403);exit('Sin acceso a seguimiento de Compras.');}
$oc=filter_var($_GET['oc']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if(!$oc){http_response_code(400);exit('Orden de compra inválida.');}
$err='';$orden=null;$facturas=[];
try{
    $db=(new Database())->connect();
    $st=$db->prepare('SELECT o.id,o.folio,p.nombre AS proveedor,o.destino FROM erp_ordenes_compra o JOIN proveedores p ON p.id=o.proveedor_id WHERE o.id=?');
    $st->execute([$oc]);$orden=$st->fetch(PDO::FETCH_ASSOC);
    if(!$orden){http_response_code(404);exit('Orden inexistente.');}
    $st=$db->prepare("SELECT f.id,f.folio_interno,f.numero_factura,f.fecha_factura,f.estado,
        COALESCE(fd.documentadas,0) AS documentadas,COALESCE(ap.aplicadas,0) AS aplicadas
        FROM erp_facturas f
        LEFT JOIN (SELECT factura_id,SUM(cantidad_facturada) documentadas FROM erp_facturas_detalle GROUP BY factura_id) fd ON fd.factura_id=f.id
        LEFT JOIN (SELECT factura_id,SUM(cantidad) aplicadas FROM erp_facturas_stock_aplicaciones GROUP BY factura_id) ap ON ap.factura_id=f.id
        WHERE f.orden_id=? ORDER BY f.id DESC");
    $st->execute([$oc]);$facturas=$st->fetchAll(PDO::FETCH_ASSOC);
}catch(Throwable $ex){error_log('Seguimiento OC: '.$ex->getMessage());$err='No fue posible consultar las facturas de esta orden.';}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>G&D | Seguimiento de facturas</title><style>
body{margin:0;background:#eef4fa;color:#153f6c;font:14px system-ui,sans-serif}header{background:#153f6c;color:white;padding:22px max(18px,calc((100% - 1000px)/2))}header a{color:white}main{max-width:1000px;margin:25px auto;padding:0 16px}.card{background:white;border-radius:12px;padding:20px;margin:14px 0}h1{font-size:22px}table{width:100%;border-collapse:collapse}td,th{padding:11px;border-bottom:1px solid #dae5f0;text-align:left}th{background:#174c80;color:white}a.btn{display:inline-block;padding:8px 10px;border-radius:7px;background:#175d9f;color:white;text-decoration:none}small{color:#617d98}</style></head><body><header><h1>Seguimiento de facturas por OC</h1><a href="erp_compras_avisos.php">← Avisos de Compras</a></header><main><section class="card">
<?php if($err):?><p><?=erpr_html($err)?></p><?php elseif($orden):?><h2><?=erpr_html($orden['folio'])?> · <?=erpr_html($orden['proveedor'])?></h2><p>Destino: <?=erpr_html($orden['destino'])?></p><p><a class="btn" href="erp_facturas.php?orden_id=<?=(int)$oc?>">Registrar factura sólo si quedan piezas por facturar</a></p>
<p><small>Registrar la factura no suma existencias. Verificación y aplicación al inventario se realizan por separado.</small></p></section><section class="card"><table><thead><tr><th>Factura</th><th>Fecha</th><th>Estado</th><th>Piezas</th><th>Acción</th></tr></thead><tbody>
<?php foreach($facturas as $f):?><tr><td><?=erpr_html($f['folio_interno'])?><br><small><?=erpr_html($f['numero_factura'])?></small></td><td><?=erpr_html($f['fecha_factura'])?></td><td><?=erpr_html($f['estado'])?></td><td><?=number_format((int)$f['aplicadas'])?> / <?=number_format((int)$f['documentadas'])?> aplicadas</td><td><a class="btn" href="erp_facturas_verificacion.php?id=<?=(int)$f['id']?>">Continuar verificación</a></td></tr><?php endforeach;?>
<?php if(!$facturas):?><tr><td colspan="5">Esta orden todavía no tiene facturas registradas.</td></tr><?php endif;?></tbody></table></section><?php endif;?></main>
<script src="assets/js/gyd_excel_reportes.js" defer></script>

<?php /* GYD_NAV_ROLE_V1 */
$__gydNavigationAccount = function_exists('isLoggedIn') && isLoggedIn() && function_exists('currentUser')
    ? (currentUser() ?: []) : [];
$__gydNavigationRole = strtoupper(trim((string)($__gydNavigationAccount['rol_original'] ?? $__gydNavigationAccount['rol'] ?? '')));
$__gydNavigationAllowed = in_array($__gydNavigationRole, ['DIRECCION','ADMINISTRADOR','ADMIN','COMPRAS'], true);
if (!defined('GYD_HIDE_GLOBAL_NAV') && $__gydNavigationAllowed):
$__gydNavigationHome = $__gydNavigationRole === 'COMPRAS' ? 'erp_compras_inicio.php' : 'dashboard.php';
?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=5">
<script>window.GYD_NAV_ENABLED=true;window.GYD_NAV_HOME=<?=json_encode($__gydNavigationHome, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=5"></script>
<?php endif; unset($__gydNavigationAccount,$__gydNavigationRole,$__gydNavigationAllowed,$__gydNavigationHome); ?>
</body></html>
