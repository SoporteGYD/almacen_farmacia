<?php
/**
 * Vista para impresora térmica (58 / 80 mm). PROTOTIPO NO FISCAL.
 * SIN guardar ventas, movimientos ni modificar inventarios.
 */
declare(strict_types=1);
require_once __DIR__ . '/../app/helpers/auth.php';
requireLogin();
$user = currentUser() ?? [];
if (!in_array(strtoupper(trim((string)($user['rol'] ?? ''))), ['ADMINISTRADOR', 'CAJERO'], true)) {
    http_response_code(403);
    exit('Acceso restringido.');
}
function ht(string $s): string {return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');}
function precio(int $cent): string {return '$' . number_format($cent / 100, 2, '.', ',');}
function textoCorto(string $text, int $max): string {return function_exists('mb_substr') ? mb_substr($text,0,$max,'UTF-8') : substr($text,0,$max);}
$papel = '80';
$items = [
 ['nombre'=>'BARMICIL 40 GR', 'cantidad'=>2, 'precioCentavos'=>8500],
 ['nombre'=>'DES OBAO ROLLON WILD 65G', 'cantidad'=>1,'precioCentavos'=>4950],
];
$pagos = [['tipo'=>'EFECTIVO','centavos'=>25000,'referencia'=>'']];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string)($_SESSION['pos_piloto_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403); exit('Solicitud no autorizada.');
    }
    $raw = (string)($_POST['datos'] ?? '');
    if (strlen($raw)>25000) {http_response_code(400);exit('Datos demasiado largos.');}
    $payload=json_decode($raw,true);
    if (!is_array($payload) || !is_array($payload['items']??null) || !is_array($payload['pagos']??null)) {
        http_response_code(400);exit('Datos no válidos.');
    }
    $papel = ($payload['papel']??'80')==='58'?'58':'80';
    $items=[];$pagos=[];
    foreach (array_slice($payload['items'],0,35) as $item) {
        if (!is_array($item)) continue;
        $nombre=trim((string)($item['nombre']??''));$cantidad=(int)($item['cantidad']??0);$unit=(int)($item['precioCentavos']??0);
        if ($nombre===''||$cantidad<1||$cantidad>100||$unit<1||$unit>100000000)continue;
        $items[]=['nombre'=>textoCorto($nombre,90),'cantidad'=>$cantidad,'precioCentavos'=>$unit];
    }
    foreach (array_slice($payload['pagos'],0,3) as $item) {
        if (!is_array($item))continue;
        $tipo=strtoupper((string)($item['tipo']??''));$centavos=(int)($item['centavos']??0);
        if (!in_array($tipo,['EFECTIVO','TARJETA','TRANSFERENCIA'],true)||$centavos<=0||$centavos>100000000)continue;
        $pagos[]=['tipo'=>$tipo,'centavos'=>$centavos,'referencia'=>textoCorto(trim((string)($item['referencia']??'')),100)];
    }
}
$total=0;foreach($items as $item){$total+=$item['cantidad']*$item['precioCentavos'];}
$pagado=0;$digital=0;
foreach($pagos as $p){$pagado+=$p['centavos'];if($p['tipo']!=='EFECTIVO')$digital+=$p['centavos'];}
if (count($items)===0||$total<=0||$pagado<$total||$digital>$total) {http_response_code(400);exit('Importes de prueba inválidos.');}
$cambio=$pagado-$total;
$folio='PRUEBA-'.date('Ymd-His');
$fecha=date('d/m/Y H:i:s');
$cajero=trim((string)($user['nombre']??'Administrador'));
$anchoInterno=$papel==='58'?'48mm':'72mm';
$fontSize=$papel==='58'?'10px':'12px';
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ticket de prueba G&D · <?=ht($folio)?></title>
<style>
/* Ancho físico del rollo y zona segura de impresión para evitar recorte izquierdo. */
:root{--papel:<?=$papel?>mm;--margen-izq:9mm;--margen-der:4mm;--letra:<?=$papel==='58'?'10px':'11.5px'?>}
*{box-sizing:border-box}
html,body{margin:0;padding:0;color:#000}
body{background:#e5ebf2;font-family:Arial,Helvetica,sans-serif}
.acciones{margin:14px auto;max-width:650px;background:#fff;border-radius:11px;padding:12px 16px;display:flex;gap:12px;align-items:center;justify-content:center;flex-wrap:wrap;font:14px system-ui}
.acciones button,.acciones a,.acciones select{border:1px solid #c3d4e6;border-radius:6px;padding:9px 11px;font:700 13px system-ui}
.acciones button{background:#1558bc;color:white;cursor:pointer}.acciones a{color:#12478d;text-decoration:none}
.ticket{box-sizing:border-box;width:var(--papel);min-height:20mm;padding:3mm var(--margen-der) 3mm var(--margen-izq);background:white;margin:12px auto;box-shadow:0 4px 18px #0002;font-family:Arial,Helvetica,sans-serif;font-size:var(--letra);font-weight:500;line-height:1.28;overflow-wrap:anywhere;word-break:normal}
.centrado{text-align:center}.logo{max-width:21mm;height:auto;display:block;margin:0 auto 2mm;filter:grayscale(1) contrast(2)}.empresa{font-weight:800;font-size:1.12em}
.sep{border-top:1px dashed #111;margin:2.8mm 0}.datos{font-size:.91em;overflow-wrap:anywhere}
.linea,.detalle{display:flex;justify-content:space-between;gap:1.5mm;margin:1.5mm 0;align-items:baseline;min-width:0}
.linea>*:first-child,.detalle>*:first-child{min-width:0;overflow-wrap:anywhere}
.linea>*:last-child,.detalle>*:last-child{white-space:nowrap;flex-shrink:0;text-align:right}
.nombre-producto{font-weight:800;margin-top:2.8mm;overflow-wrap:anywhere}
.titulo-total{font-size:1.28em;font-weight:900}
.prueba{border:1px solid black;padding:2mm 1mm;text-align:center;font-weight:850;margin:4mm 0 2mm;line-height:1.3;font-size:.94em}
.pie{text-align:center;font-weight:750;margin:3mm 0 1mm}.fin{height:5mm}
@page{margin:0}
@media print{
 html,body{margin:0!important;padding:0!important;background:#fff!important;width:var(--papel)!important;max-width:var(--papel)!important}
 .acciones{display:none!important}
 .ticket{margin:0!important;width:var(--papel)!important;max-width:var(--papel)!important;box-shadow:none!important;break-inside:auto!important;page-break-inside:auto!important}
 .fin{height:2mm}
}
</style>
</head><body>
<div class="acciones"><strong>Ticket de prueba · <?=$papel?> mm</strong><label>Margen izquierdo <select id="margen" aria-label="Margen izquierdo"><option value="7">7 mm</option><option value="9" selected>9 mm</option><option value="11">11 mm</option><option value="13">13 mm</option></select></label><button type="button" onclick="window.print()">🖨️ Imprimir</button><a href="pos_prueba.php">← Volver al POS</a></div>
<article class="ticket" role="document">
 <img class="logo" src="assets/img/logo2.png" alt="G&D" onerror="this.style.display='none'">
 <div class="centrado empresa">DISTRIBUCIÓN G&D</div>
 <div class="centrado datos">SIMULACIÓN DE TICKET<br>SUCURSAL: PRUEBAS</div>
 <div class="sep"></div>
 <div class="datos">Folio: <?=ht($folio)?><br>Fecha: <?=ht($fecha)?><br>Cajero: <?=ht($cajero)?></div>
 <div class="sep"></div>
 <div class="centrado"><strong>DESCRIPCIÓN / CANTIDAD / IMPORTE</strong></div>
 <?php foreach($items as $item):?>
  <div class="nombre-producto"><?=ht((string)$item['nombre'])?></div>
  <div class="detalle"><span><?=$item['cantidad']?> x <?=precio($item['precioCentavos'])?></span><span><?=precio($item['cantidad']*$item['precioCentavos'])?></span></div>
 <?php endforeach;?>
 <div class="sep"></div>
 <div class="linea titulo-total"><span>TOTAL</span><span><?=precio($total)?></span></div>
 <div class="sep"></div>
 <?php foreach($pagos as $p):?>
 <div class="linea"><span><?=ht($p['tipo'])?></span><span><?=precio($p['centavos'])?></span></div>
 <?php if($p['referencia']!==''):?><div class="datos">Ref: <?=ht($p['referencia'])?></div><?php endif;?>
 <?php endforeach;?>
 <div class="linea"><strong>CAMBIO</strong><strong><?=precio($cambio)?></strong></div>
 <div class="prueba">*** TICKET DE PRUEBA ***<br>NO ES UNA VENTA REAL<br>NO VÁLIDO COMO COMPROBANTE</div>
 <div class="pie">GRACIAS POR SU PREFERENCIA</div>
 <div class="centrado datos">Diseño de impresión G&D</div>
 <div class="fin"></div>
</article>
<script>
const opt=document.getElementById('margen');
const guardado=localStorage.getItem('gyd_pos_margen_ticket');
if(['7','9','11','13'].includes(guardado)){opt.value=guardado;}
function aplicarMargen(){document.documentElement.style.setProperty('--margen-izq',opt.value+'mm');localStorage.setItem('gyd_pos_margen_ticket',opt.value);}
opt.addEventListener('change',aplicarMargen);aplicarMargen();
</script>

<?php if (!defined('GYD_HIDE_GLOBAL_NAV')): ?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=1">
<script>window.GYD_NAV_HOME='index.php';window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=1"></script>
<?php endif; ?>
</body></html>
