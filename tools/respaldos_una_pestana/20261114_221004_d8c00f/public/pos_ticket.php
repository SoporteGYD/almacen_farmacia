<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
requireLogin();
$u=currentUser()??[];
if(!in_array(($u['rol']??''),['CAJERO','ADMINISTRADOR'],true)){
    http_response_code(403);
    exit('Acceso denegado.');
}
require_once __DIR__.'/../app/models/PuntoVenta.php';
try{
    $venta=(new PuntoVenta())->ticket((int)($_GET['id']??0),$u);
    if(!$venta) throw new DomainException('Ticket no encontrado.');
}catch(Throwable $e){
    http_response_code(404);
    exit('No disponible: '.htmlspecialchars($e instanceof DomainException?$e->getMessage():'Consulta no disponible.'));
}
// Una copia por trabajo de impresión; la portada coordina ambas copias.
$copia=(string)($_GET['copia']??'ambas');
if(!in_array($copia,['ambas','cliente','tienda'],true)) $copia='ambas';
$soloCopia=$copia!=='ambas';
$esCopiaTienda=$copia==='tienda';
$roll=($_GET['ancho']??'80')==='58'?58:80;
$mm=max(5,min(14,(int)($_GET['margen']??($roll===80?9:7))));
function h(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function currency($v):string{return '$'.number_format((float)$v,2,'.',',');}
function normalize_branch(string $s): string {
    $s=mb_strtoupper(trim($s),'UTF-8');
    $map=['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N'];
    return strtr($s,$map);
}
function branch_iva_rate(string $sucursal): float {
    $s=normalize_branch($sucursal);
    if(str_contains($s,'TUXTLA')) return 0.16;
    if(str_contains($s,'CIUDAD HIDALGO') || str_contains($s,'TAPACHULA') || str_contains($s,'TOSCANA')) return 0.08;
    return 0.16;
}
$sinImportes=(int)($venta['ticket_sin_importes']??0)===1;
$ivaRate=branch_iva_rate((string)($venta['sucursal']??''));
$total=(float)($venta['total']??0);
$subtotal=$ivaRate>0? round($total/(1+$ivaRate),2) : round($total,2);
$ivaMonto=round($total-$subtotal,2);
$totalUnidades=array_sum(array_map(static fn(array $r)=>(int)($r['unidades_descontadas']??$r['cantidad']),$venta['items']));
$totalArticulos=count($venta['items']);

// CODE128-B renderizado en servidor: no requiere CDN ni JavaScript.
function barcode_folio_svg(string $folio): string {
    static $p = ['212222','222122','222221','121223','121322','131222','122213','122312','132212','221213','221312','231212','112232','122132','122231','113222','123122','123221','223211','221132','221231','213212','223112','312131','311222','321122','321221','312212','322112','322211','212123','212321','232121','111323','131123','131321','112313','132113','132311','211313','231113','231311','112133','112331','132131','113123','113321','133121','313121','211331','231131','213113','213311','213131','311123','311321','331121','312113','312311','332111','314111','221411','431111','111224','111422','121124','121421','141122','141221','112214','112412','122114','122411','142112','142211','241211','221114','413111','241112','134111','111242','121142','121241','114212','124112','124211','411212','421112','421211','212141','214121','412121','111143','111341','131141','114113','114311','411113','411311','113141','114131','311141','411131','211412','211214','211232','2331112'];
    $folio = trim($folio);
    if ($folio === '') return '';
    $codes = [104]; // Inicio CODE128-B.
    $checksum = 104;
    $length = strlen($folio);
    for ($i = 0; $i < $length; $i++) {
        $ascii = ord($folio[$i]);
        if ($ascii < 32 || $ascii > 126) return '';
        $value = $ascii - 32;
        $codes[] = $value;
        $checksum += $value * ($i + 1);
    }
    $codes[] = $checksum % 103;
    $codes[] = 106; // Parada.
    $unit = 0;
    $bars = [];
    foreach ($codes as $code) {
        $widths = $p[$code];
        for ($j = 0, $n = strlen($widths); $j < $n; $j++) {
            $w = (int)$widths[$j];
            if ($j % 2 === 0) $bars[] = '<rect x="'.$unit.'" y="0" width="'.$w.'" height="55"/>';
            $unit += $w;
        }
    }
    // Zona silenciosa de 10 módulos a cada lado.
    return '<svg class="barcode-svg" xmlns="http://www.w3.org/2000/svg" viewBox="-10 0 '.($unit+20).' 55" role="img" aria-label="Código de barras del folio '.h($folio).'" preserveAspectRatio="xMidYMid meet"><g fill="#000">'.implode('', $bars).'</g></svg>';
}

$barcodeFolio=barcode_folio_svg((string)$venta['folio']);
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $sinImportes ? 'Nota de entrega' : 'Ticket' ?> <?=h((string)$venta['folio'])?></title><style>
*{box-sizing:border-box}body{background:#f4f7fa;color:#111;margin:0;font:12px Arial,sans-serif}.toolbar{max-width:660px;margin:20px auto;display:flex;gap:9px;align-items:center;justify-content:center;flex-wrap:wrap}.toolbar button,.toolbar a,.toolbar select{font:inherit;background:#145aaa;color:white;padding:9px 12px;border:none;border-radius:7px;text-decoration:none}.toolbar select{background:#e1e9f4;color:#123}.sheet{width:<?=($roll-2*$mm)?>mm;margin:8px auto 25px;padding:1mm 0 5mm;background:white;color:#111}.ticket-header{text-align:center}.logo{display:block;max-height:25mm;max-width:42mm;object-fit:contain;margin:0 auto 1.2mm}.lema{display:block;text-align:center;font-size:11px;font-weight:900;letter-spacing:.35px;line-height:1.25;margin:0 auto 3mm;padding:1.3mm 1mm;border-top:1px solid #222;border-bottom:1px solid #222;max-width:100%}.title{font-weight:900;font-size:13px;letter-spacing:.15px}.business{font-size:10px;font-weight:700;text-align:center;margin-top:1mm}.fiscal{font-size:10px;text-align:center;margin:1mm 0 2mm}.seller{font-size:10px}.subtitle{font-size:10px}.sep{border-top:1px dashed #444;margin:3mm 0 2mm}.meta{font-size:10px;line-height:1.48;overflow-wrap:anywhere}.meta b{font-weight:700}.product{padding:2mm 0}.product-code{font-size:9px;margin-bottom:1mm;overflow-wrap:anywhere}.product strong{display:block;overflow-wrap:anywhere;font-size:11px}.line{display:flex;justify-content:space-between;gap:3mm;align-items:flex-start}.line .left{flex:1;min-width:0;overflow-wrap:anywhere}.line .right{text-align:right;white-space:nowrap}.total{font-size:15px;font-weight:900}.center{text-align:center}.footer{font-size:10px;text-align:center;line-height:1.4}.test{border:1px solid #333;padding:2mm 1mm;margin:4mm 0;font-weight:800;text-align:center;font-size:10px}.tag{font-size:9px;text-align:center;letter-spacing:.4px}.mono{font-family:Arial,Helvetica,sans-serif}.sheet-wrap{margin-left:0}.copy-heading{text-align:center;border:2px solid #111;padding:2mm 1mm;margin:1.5mm 0 3mm;font-size:13px;line-height:1.25;font-weight:900}.copy-heading small{font-size:10px;display:block}.copy-break{height:8mm;border-top:1px dashed #777;margin:4mm auto 2mm;max-width:65mm}.sheet-copy{margin-top:0} .folio-barcode{margin:4mm auto 2mm;text-align:center;page-break-inside:avoid;break-inside:avoid}.folio-barcode-label{font-size:10px;font-weight:700;margin-bottom:1.5mm;overflow-wrap:anywhere}.barcode-svg{display:block;width:100%;max-width:100%;height:18mm;margin:0 auto;fill:#000}.folio-barcode-note{font-size:8px;text-align:center;margin-top:1mm}
@media print{html,body{background:#fff!important;margin:0!important;padding:0!important} .toolbar{display:none!important}.sheet{margin:0 <?= $mm ?>mm!important;padding:2mm 0 6mm!important;width:<?=($roll-2*$mm)?>mm!important;box-shadow:none!important;break-inside:avoid;}.sheet-wrap{margin:0!important;padding:0!important} @page{margin:0}}
</style></head><body><div class="toolbar"><label>Ancho <select id="roll"><option value="80" <?=$roll===80?'selected':''?>>80 mm</option><option value="58" <?=$roll===58?'selected':''?>>58 mm</option></select></label><label>Margen izquierdo <select id="margin"><?php foreach([7,9,11,13,14] as $m):?><option value="<?=$m?>" <?=$mm===$m?'selected':''?>><?=$m?> mm</option><?php endforeach;?></select></label><button type="button" onclick="<?= $soloCopia ? 'imprimirActual()' : 'imprimirDosTrabajos()' ?>"><?= $soloCopia ? 'IMPRIMIR COPIA' : 'IMPRIMIR Y CORTAR LAS DOS COPIAS' ?></button><a href="pos.php">Volver al POS</a><span id="aviso-corte" style="max-width:550px;text-align:center;font-size:12px">Cada copia se envía como impresión separada; activa el autocorte por trabajo en Epson.</span></div><div class="sheet-wrap"><?php if($soloCopia): ?><article class="sheet <?= $esCopiaTienda ? 'sheet-copy' : '' ?>"><?php if($esCopiaTienda): ?><div class="copy-heading">REIMPRESIÓN<small>COPIA PARA TIENDA</small></div><?php endif; ?><header class="ticket-header"><img class="logo" src="assets/img/logo_farmacia_ticket.png" alt="G&D Farmacia"><div class="lema">¡CUIDAMOS TU SALUD AHORRANDO!</div><div class="title">DISTRIBUCIÓN G&amp;D, S.A. DE C.V.</div><div class="fiscal">RFC: DGD151211PP5</div><div class="subtitle">SUCURSAL: <?=h((string)$venta['sucursal'])?></div></header><div class="sep"></div><div class="meta">Ticket #: <b><?=h((string)$venta['folio'])?></b><br>Fecha y hora: <?=h((string)($venta['pagada_en']??$venta['creada_en']))?><br><?=h(preg_match('/\b0*(\d+)\b/', (string)$venta['caja_nombre'], $cm) ? 'Caja '.str_pad($cm[1],2,'0',STR_PAD_LEFT) : (string)$venta['caja_nombre'])?> · <?=h((string)$venta['cajero'])?><br><b>CLIENTE:</b> <?=h(trim((string)($venta['cliente_nombre']??''))?:'PÚBLICO EN GENERAL')?><br><b>QUIÉN LLEVA:</b> <?=h(trim((string)($venta['quien_lleva']??''))?:'NO REGISTRADO')?></div><div class="sep"></div><div class="center" style="font-weight:bold;font-size:10px"><?= $sinImportes ? 'DESCRIPCIÓN / CANTIDAD' : 'DESCRIPCIÓN / CANTIDAD / IMPORTE' ?></div><div class="sep"></div>
<?php foreach($venta['items'] as $row): ?>
<div class="product"><div class="product-code">CÓDIGO: <?=h((string)($row['codigo_snapshot']??'SIN CÓDIGO'))?></div><strong><?=h((string)$row['descripcion_snapshot'])?></strong><?php if(($row['presentacion']??'PIEZA')==='CAJA'): ?><div class="product-code">1 CAJA = <?= (int)($row['piezas_por_presentacion']??1) ?> PIEZAS</div><?php endif; ?>
<?php if($sinImportes): ?>
<div class="line"><span class="left">Cantidad entregada</span><strong class="right"><?= (int)$row['cantidad']?> <?=($row['presentacion']??'PIEZA')==='CAJA'?'cajas':'piezas'?></strong></div>
<?php else: ?>
<div class="line"><span class="left"><?= (int)$row['cantidad']?> <?=($row['presentacion']??'PIEZA')==='CAJA'?'CAJAS':'PIEZAS'?> × <?=currency($row['precio_unitario'])?></span><span class="right"><?=currency($row['total_renglon'])?></span></div>
<?php endif; ?>
</div>
<?php endforeach; ?>
<div class="sep"></div>
<div class="line"><span>UNIDADES</span><b><?= (int)$totalUnidades ?></b></div>
<div class="line"><span>ARTÍCULOS</span><b><?= (int)$totalArticulos ?></b></div>
<?php if($sinImportes): ?>
<div class="footer" style="margin-top:4mm">Mercancía entregada según las cantidades indicadas.</div>
<?php else: ?>
<div class="line"><span>SUBTOTAL</span><span><?=currency($subtotal)?></span></div>
<div class="line"><span>IVA <?= (int)round($ivaRate*100) ?>%</span><span><?=currency($ivaMonto)?></span></div>
<div class="line total"><span>TOTAL</span><span><?=currency($total)?></span></div>
<div class="sep"></div>
<?php foreach($venta['pagos'] as $p):?><div class="line"><span><?=h(($p['metodo']==='TARJETA' && in_array($p['tipo_tarjeta']??'', ['DEBITO','CREDITO'],true)) ? ('TARJETA DE '.($p['tipo_tarjeta']==='DEBITO'?'DÉBITO':'CRÉDITO')) : (string)$p['metodo'])?></span><span><?=currency($p['monto'])?></span></div><?php endforeach;?>
<?php if((float)$venta['efectivo_recibido']>0):?><div class="line"><span>EFECTIVO RECIBIDO</span><span><?=currency($venta['efectivo_recibido'])?></span></div><?php endif;?>
<div class="line"><b>CAMBIO</b><b><?=currency($venta['cambio'])?></b></div>
<?php endif; ?>
<div class="sep"></div><div class="test">*** <?= $sinImportes ? 'NOTA DE ENTREGA' : 'TICKET DE PRUEBA' ?> ***<br>NO ES COMPROBANTE FISCAL<br>BASE DE DATOS: prueba</div><div class="footer">GRACIAS POR SU PREFERENCIA<br>DISTRIBUCIÓN G&amp;D</div><?php if($esCopiaTienda): ?><div class="copy-heading" style="margin-top:4mm">REIMPRESIÓN<small>ARCHIVO DE TIENDA · MISMO FOLIO</small></div><?php endif; ?><?php if($barcodeFolio!==''): ?><div class="folio-barcode"><div class="folio-barcode-label">FOLIO: <?=h((string)$venta['folio'])?></div><?=$barcodeFolio?></div><?php endif; ?></article><?php endif; ?></div><script>function recalc(){const v=new URLSearchParams({id:<?=json_encode((string)$venta['id'])?>,copia:<?=json_encode($copia)?>,ancho:document.getElementById('roll').value,margen:document.getElementById('margin').value});location.href='pos_ticket.php?'+v}document.getElementById('roll').onchange=recalc;document.getElementById('margin').onchange=recalc;
// Cada copia es un TRABAJO distinto: cliente primero, tienda después.
const paramsTicket=new URLSearchParams(location.search);
const ticketId=<?=json_encode((string)$venta['id'])?>;
const ticketRoll=<?=json_encode((string)$roll)?>;
const ticketMargin=<?=json_encode((string)$mm)?>;
let yaImprimio=false;
function urlCopia(tipo, extra={}){
  const params=new URLSearchParams({id:ticketId,copia:tipo,ancho:ticketRoll,margen:ticketMargin,...extra});
  return 'pos_ticket.php?'+params.toString();
}
async function documentoListo(){
  if(document.readyState!=='complete') await new Promise(resolve=>window.addEventListener('load',resolve,{once:true}));
  if(document.fonts&&document.fonts.ready) await document.fonts.ready.catch(()=>{});
  await Promise.all(Array.from(document.images).map(img=>{
    if(img.decode) return img.decode().catch(()=>{});
    if(img.complete) return Promise.resolve();
    return new Promise(resolve=>{img.onload=resolve;img.onerror=resolve;});
  }));
  // Dos repintados para estabilizar la composición antes de imprimir.
  await new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));
}
async function imprimirActual(){
  if(yaImprimio)return;
  yaImprimio=true;
  try{
    await documentoListo();
    // Una imagen ausente no debe provocar un ticket vacío.
    if(!document.querySelector('.sheet')) throw new Error('No hay contenido de ticket.');
    window.focus();
    window.print();
  }catch(error){
    yaImprimio=false;
    const aviso=document.getElementById('aviso-corte');
    if(aviso)aviso.textContent='No se pudo iniciar la impresión: '+error.message;
  }
}
function imprimirDosTrabajos(){
  // Inicia aquí mismo la copia de cliente, evitando popups en blanco.
  location.href=urlCopia('cliente',{imprimir:'1',secuencia:'1'});
}
let segundoTrabajoSolicitado=false;
if(paramsTicket.get('copia')==='cliente'&&paramsTicket.get('secuencia')==='1'){
  window.addEventListener('afterprint',()=>{
    if(segundoTrabajoSolicitado)return;
    segundoTrabajoSolicitado=true;
    // Chrome informa que terminó la interfaz, NO el papel; Epson corta al final de cada trabajo.
    setTimeout(()=>location.replace(urlCopia('tienda',{imprimir:'1',secuencia:'2'})),550);
  });
}
if(paramsTicket.get('imprimir')==='1'&&['cliente','tienda'].includes(paramsTicket.get('copia'))){
  imprimirActual();
}
</script>
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
