<?php
declare(strict_types=1);
/** ERP G&D: comprobante de cancelación o devolución. NO ejecuta reembolsos. */
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
requireLogin();
$u=currentUser() ?: [];
$rol=strtoupper(trim((string)($u['rol_original']??$u['rol']??'')));
if(!in_array($rol,['ADMINISTRADOR','ADMIN','DIRECCION'],true)){http_response_code(403);exit('Sin permiso.');}
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if(!$id){http_response_code(400);exit('Comprobante inválido.');}
function rvh(mixed $v):string {return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
try {
 $db=(new Database())->connect();
 $st=$db->prepare("SELECT r.id,r.tipo,r.estado,r.importe,r.cantidad_presentaciones,r.piezas,r.medio_reembolso,r.referencia_reembolso,r.motivo,r.creado_en,
      v.folio,v.total total_venta,s.nombre sucursal,c.nombre caja,ua.nombre autorizado_por,uc.nombre cajero,
      d.descripcion_snapshot producto,d.presentacion
   FROM pos_reversiones r
   INNER JOIN pos_ventas v ON v.id=r.venta_id
   INNER JOIN pos_sucursales s ON s.id=v.sucursal_id
   INNER JOIN pos_cajas c ON c.id=v.caja_id
   INNER JOIN usuarios ua ON ua.id=r.usuario_id
   INNER JOIN usuarios uc ON uc.id=v.usuario_id
   LEFT JOIN pos_venta_detalle d ON d.id=r.detalle_id
   WHERE r.id=? AND r.tipo IN ('CANCELACION_TOTAL','DEVOLUCION_PARCIAL') AND r.estado='APLICADA' LIMIT 1");
 $st->execute([$id]);$r=$st->fetch(PDO::FETCH_ASSOC);
 if(!$r){http_response_code(404);exit('No se encontró una reversa aplicada.');}
} catch(Throwable $e){error_log('Comprobante reversiones: '.$e->getMessage());http_response_code(500);exit('No se pudo generar el comprobante.');}
$folio='DEV-'.str_pad((string)$r['id'],8,'0',STR_PAD_LEFT);
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=rvh($folio)?> · G&amp;D</title>
<style>
*{box-sizing:border-box}body{font:12px/1.45 Consolas,'Courier New',monospace;color:#111;background:#edf2f7;margin:0}.receipt{width:78mm;min-height:60mm;background:white;margin:15px auto;padding:7mm 4mm}.center{text-align:center}.rule{border-top:1px dashed #333;margin:9px 0}.big{font-weight:800;font-size:16px}.row{display:flex;justify-content:space-between;gap:7px}.wrap{overflow-wrap:anywhere}.muted{font-size:10px}.signature{border-top:1px solid #111;margin-top:29px;text-align:center;padding-top:4px}.toolbar{text-align:center;margin:15px}.toolbar button{background:#1359a0;color:white;border:0;border-radius:8px;padding:11px 16px;cursor:pointer;font-weight:bold}
@media print { @page{size:80mm auto;margin:0}body{background:white}.receipt{width:78mm;box-shadow:none;margin:0;padding:4mm 3mm;min-height:auto}.toolbar{display:none} }
</style></head><body><div class="toolbar"><button onclick="window.print()">Imprimir comprobante</button></div><article class="receipt">
<div class="center"><div class="big">FARMACIA G&amp;D</div><div>CUIDAMOS TU SALUD AHORRANDO!</div><div class="rule"></div><b><?=($r['tipo']==='CANCELACION_TOTAL')?'CANCELACIÓN TOTAL':'DEVOLUCIÓN PARCIAL'?></b><div class="big"><?=rvh($folio)?></div></div>
<div class="rule"></div>
<div>Fecha: <?=rvh($r['creado_en'])?></div><div>Sucursal: <?=rvh($r['sucursal'])?></div><div>Caja: <?=rvh($r['caja'])?></div><div>Venta original: <strong><?=rvh($r['folio'])?></strong></div>
<div>Cajero original: <?=rvh($r['cajero'])?></div><div>Responsable: <?=rvh($r['autorizado_por'])?></div>
<?php if($r['tipo']==='DEVOLUCION_PARCIAL'):?><div class="rule"></div><div class="wrap">Artículo: <?=rvh($r['producto']??'—')?></div><div>Devolución: <?=rvh($r['cantidad_presentaciones'])?> <?=rvh($r['presentacion']??'presentación(es)')?> (<?=rvh($r['piezas'])?> piezas)</div><?php endif;?>
<div class="rule"></div><div class="row"><strong>Importe reembolsado</strong><strong>$<?=number_format((float)$r['importe'],2,'.',',')?></strong></div>
<div>Medio declarado: <?=rvh($r['medio_reembolso']??'—')?></div>
<?php if($r['referencia_reembolso']):?><div class="wrap">Referencia: <?=rvh($r['referencia_reembolso'])?></div><?php endif;?>
<div class="rule"></div><div class="wrap">Motivo: <?=rvh($r['motivo'])?></div>
<div class="signature">Firma responsable</div><div class="signature">Firma cliente / acuse</div>
<div class="rule"></div><div class="center muted">Documento generado desde el ERP. La confirmación del reembolso fue declarada por el usuario responsable. No es comprobante de procesamiento bancario. Conservar junto con el ticket original.</div>
</article>
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
<!-- GYD_UNA_PESTANA_INTERNA -->
<script src="assets/js/gyd_una_pestana.js?v=1"></script>
</body></html>
