<?php
/**
 * POS G&D — Laboratorio de cobro y ticket (SIN REGISTROS NI DESCUENTOS).
 * Colocar en public/pos_prueba.php de la instalación actual.
 */
declare(strict_types=1);
require_once __DIR__ . '/../app/helpers/auth.php';
requireLogin();
$user = currentUser() ?? [];
$rol = strtoupper(trim((string)($user['rol'] ?? '')));
if (!in_array($rol, ['ADMINISTRADOR', 'CAJERO'], true)) {
    http_response_code(403);
    exit('Acceso restringido al personal de caja y administración.');
}
if (!isset($_SESSION['pos_piloto_csrf'])) {
    $_SESSION['pos_piloto_csrf'] = bin2hex(random_bytes(24));
}
$csrf = $_SESSION['pos_piloto_csrf'];
$nombreCajero = trim((string)($user['nombre'] ?? 'Administrador'));
function he(string $v): string {return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>POS G&D · Laboratorio de caja</title>
<style>
:root{--blue:#123b68;--accent:#185ec7;--bg:#edf3f9;--txt:#17293f;--line:#dce5ef}
*{box-sizing:border-box}body{background:var(--bg);margin:0;color:var(--txt);font-family:system-ui,Segoe UI,Arial,sans-serif}
.top{background:linear-gradient(110deg,#163a62,#185182);color:white;padding:16px max(16px,calc((100% - 1280px)/2));display:flex;align-items:center;justify-content:space-between;gap:10px}
.top h1{margin:0;font-size:19px}.tag{font-size:11px;border:1px solid #9cb4d1;padding:7px 10px;border-radius:99px;background:#294e75;font-weight:700}
.wrap{max-width:1280px;margin:24px auto;padding:0 16px}.note{padding:12px 14px;background:#fff8e2;border:1px solid #f0d990;border-radius:12px;margin:0 0 18px;font-size:14px}
.layout{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(330px,.85fr);gap:18px}.panel{background:white;border:1px solid var(--line);border-radius:16px;padding:20px;box-shadow:0 8px 20px #10345e0a;min-width:0}
h2{font-size:18px;margin:0 0 16px;color:#153c69}h3{font-size:14px;margin:18px 0 12px}.small{color:#6a7788;font-size:12px}
label{font-size:12px;font-weight:750;color:#34516d;display:block;margin:0 0 6px}input,select{width:100%;padding:10px;border:1px solid #c7d4e4;border-radius:9px;background:white;color:var(--txt);min-width:0;font-size:14px}
input:focus,select:focus{outline:2px solid #accbf8}.inputs{display:grid;grid-template-columns:1.2fr .72fr .72fr auto;gap:9px;align-items:end}button{cursor:pointer;border:0;border-radius:10px;padding:11px 13px;font-weight:750;font-size:13px}
.primary{background:#1857d6;color:white}.primary:disabled{opacity:.45;cursor:not-allowed}.light{background:#ecf3fc;color:#1552ac}.danger{background:#fff0f0;color:#aa2b2b}.btnsm{padding:5px 10px}
table{width:100%;border-collapse:collapse;font-size:13px;margin-top:14px}th{text-align:left;background:#e9f1fc;color:#164273;font-size:11px;padding:11px 8px}td{padding:10px 8px;border-bottom:1px solid var(--line);vertical-align:middle}.right{text-align:right;white-space:nowrap}.total{display:flex;align-items:center;justify-content:space-between;background:#e7f2ff;border-radius:12px;padding:16px;margin-top:14px}.total b{font-size:25px;color:#124aa9}
.paybtns{display:grid;grid-template-columns:repeat(2,1fr);gap:7px}.paybtn{border:1px solid #ccd9e8;background:white;color:#28506d}.paybtn.active{border:2px solid #2563eb;background:#ebf3ff;color:#174fbb}
.payarea{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:14px}.payarea>div.full{grid-column:1/-1}.summary{background:#f5f8fc;border:1px solid var(--line);padding:13px;border-radius:12px;margin:15px 0}.sumline{display:flex;justify-content:space-between;margin:7px 0}.sumline strong{font-size:18px}.ok{color:#127148}.bad{color:#b33333}.bottom-actions{display:grid;grid-template-columns:1fr;gap:10px}.hint{margin-top:15px;font-size:12px;color:#597089}
@media(max-width:850px){.layout{grid-template-columns:1fr}.inputs{grid-template-columns:1fr 1fr}.inputs>div:first-child{grid-column:1/-1}.top{padding:14px 16px}}
@media(max-width:540px){.inputs{grid-template-columns:1fr 1fr}.inputs>div:first-child{grid-column:1/-1}.panel{padding:14px}table{font-size:11px}.payarea{grid-template-columns:1fr}.tag{font-size:9px}}
</style>
</head>
<body>
<header class="top"><h1>📦 G&D · Punto de Venta</h1><span class="tag">MODO PRUEBA · SIN MOVIMIENTOS</span><a style="color:#fff;text-decoration:none;font-weight:700;font-size:13px" href="logout.php">Cerrar sesión ↗</a></header>
<main class="wrap">
<div class="note"><strong>Laboratorio de impresión:</strong> los importes son de ejemplo. Esta pantalla no se conecta al inventario, no registra ventas, no cobra tarjetas ni modifica MySQL. Úsala para probar el formato del ticket en tu impresora.</div>
<div class="layout">
<section class="panel">
 <h2>🛒 Carrito de prueba</h2>
 <div class="inputs">
  <div><label for="producto">Producto / descripción</label><input id="producto" maxlength="90" value="BARMICIL 40 GR" placeholder="Nombre del producto"></div>
  <div><label for="cantidad">Cantidad</label><input id="cantidad" inputmode="numeric" type="number" min="1" max="100" value="1"></div>
  <div><label for="precio">Precio prueba ($)</label><input id="precio" inputmode="decimal" type="number" min="0.01" step="0.01" value="85"></div>
  <button class="primary" id="agregar" type="button">+ Agregar</button>
 </div>
 <table><thead><tr><th>Descripción</th><th>Cant.</th><th class="right">P. Unit.</th><th class="right">Importe</th><th></th></tr></thead><tbody id="filas"></tbody></table>
 <div class="total"><span>Total del ejemplo</span><b id="total">$0.00</b></div>
 <p class="hint">Para diseñar la impresión puedes agregar o quitar artículos. Al cargar este laboratorio se incluyen dos productos ilustrativos.</p>
</section>
<section class="panel">
 <h2>💳 Cobro de prueba</h2>
 <div class="paybtns">
  <button class="paybtn active" type="button" data-metodo="EFECTIVO">💵 Efectivo</button>
  <button class="paybtn" type="button" data-metodo="TARJETA">💳 Tarjeta</button>
  <button class="paybtn" type="button" data-metodo="TRANSFERENCIA">🏦 Transferencia</button>
  <button class="paybtn" type="button" data-metodo="MIXTO">🔀 Mixto</button>
 </div>
 <div class="payarea" id="camposPago"></div>
 <div class="summary">
  <div class="sumline"><span>Total de compra</span><span id="tCompra">$0.00</span></div>
  <div class="sumline"><span>Monto ingresado</span><span id="tIngresado">$0.00</span></div>
  <div class="sumline"><span id="etiquetaFaltante">Faltante</span><span id="tFaltante">$0.00</span></div>
  <div class="sumline"><strong>Cambio</strong><strong id="tCambio">$0.00</strong></div>
  <p id="aviso" class="small" role="status"></p>
 </div>
 <label for="papel">Ancho del rollo de ticket</label>
 <select id="papel"><option value="80">80 mm (impresora estándar)</option><option value="58">58 mm (impresora compacta)</option></select>
 <div class="bottom-actions" style="margin-top:15px">
   <button class="primary" id="imprimir" type="button">🖨️ Abrir ticket para imprimir</button>
   <button class="light" type="button" id="restaurar">Restablecer ejemplo</button>
 </div>
 <p class="hint">Usuario actual: <?= he($nombreCajero) ?>. En la ventana del ticket pulsa <strong>Imprimir</strong> y selecciona la impresora instalada. Ajusta el papel a 58/80 mm en el controlador.</p>
</section>
</div>
<form id="enviar" method="post" action="pos_ticket_prueba.php" target="_blank" style="display:none">
  <input name="csrf" type="hidden" value="<?= he($csrf) ?>">
  <input name="datos" id="datos" type="hidden">
</form>
</main>
<script>
'use strict';
// Simulador local de cobro. No se realizan solicitudes de venta a la base de datos.
const $=id=>document.getElementById(id);
const money=cents=>(cents/100).toLocaleString('es-MX',{style:'currency',currency:'MXN'});
const asCents=v=>Math.round(Number(v||0)*100);
let productos=[],metodo='EFECTIVO';
const muestra={EFECTIVO:'<div class="full"><label>Monto recibido en efectivo ($)</label><input id="efectivo" type="number" min="0" step="0.01" value="250"></div>',
 TARJETA:'<div class="full"><label>Monto autorizado ($)</label><input id="tarjeta" type="number" min="0" step="0.01"></div><div class="full"><label>Referencia de terminal</label><input id="refTarjeta" maxlength="100" placeholder="Folio de autorización"></div>',
 TRANSFERENCIA:'<div class="full"><label>Monto confirmado ($)</label><input id="transferencia" type="number" min="0" step="0.01"></div><div class="full"><label>Referencia bancaria</label><input id="refBanco" maxlength="100" placeholder="Comprobante validado"></div>',
 MIXTO:'<div><label>Efectivo ($)</label><input id="efectivo" type="number" min="0" step="0.01" value="100"></div><div><label>Tarjeta ($)</label><input id="tarjeta" type="number" min="0" step="0.01" value="0"></div><div class="full"><label>Transferencia ($)</label><input id="transferencia" type="number" min="0" step="0.01" value="0"></div><div class="full"><label>Referencia de tarjeta (si aplica)</label><input id="refTarjeta" maxlength="100"></div><div class="full"><label>Referencia bancaria (si aplica)</label><input id="refBanco" maxlength="100"></div>'};
function totalCents(){return productos.reduce((s,p)=>s+p.cantidad*p.precioCentavos,0)}
function limpio(v){return String(v).replace(/[&<>\"']/g,'').trim()}
function pintar(){
 const t=$('filas');t.replaceChildren();
 productos.forEach((p,i)=>{
 const tr=document.createElement('tr');
 [p.nombre,String(p.cantidad),money(p.precioCentavos),money(p.precioCentavos*p.cantidad)].forEach((txt,j)=>{
 const td=document.createElement('td');td.textContent=txt;if(j>=2)td.className='right';tr.appendChild(td)});
 const td=document.createElement('td'),b=document.createElement('button');b.className='danger btnsm';b.type='button';b.textContent='×';b.setAttribute('aria-label','Quitar artículo');b.onclick=()=>{productos.splice(i,1);pintar()};td.appendChild(b);tr.appendChild(td);t.appendChild(tr)
 });
 $('total').textContent=money(totalCents());cobro();
}
function importe(id){let e=$(id);return e?Math.max(0,asCents(e.value)):0}
function estadoCobro(){
 const total=totalCents();let e=importe('efectivo'),t=importe('tarjeta'),b=importe('transferencia');
 const pagado=e+t+b,pend=Math.max(0,total-pagado),digital=t+b;
 const cambio=Math.max(0,pagado-total);
 let valido=productos.length>0 && total>0 && pend===0 && digital<=total;
 if(metodo==='TARJETA'||metodo==='TRANSFERENCIA')valido=valido&&pagado===total;
 if(t>0&&(!($('refTarjeta')?.value||'').trim()))valido=false;
 if(b>0&&(!($('refBanco')?.value||'').trim()))valido=false;
 return {total,e,t,b,pagado,pend,cambio,valido};
}
function cobro(){
 const a=estadoCobro();$('tCompra').textContent=money(a.total);$('tIngresado').textContent=money(a.pagado);$('tFaltante').textContent=money(a.pend);$('tCambio').textContent=money(a.cambio);
 let msg=a.total===0?'Agrega al menos un producto.':a.pend>0?'Falta cubrir '+money(a.pend)+'.':a.t+a.b>a.total?'Los pagos electrónicos superan el total.':!a.valido?'Revisa importes y referencias electrónicas.':'Importes completos para imprimir la prueba.';
 $('aviso').textContent=msg;$('aviso').className=a.valido?'ok':'bad';$('imprimir').disabled=!a.valido;
}
function cambiarMetodo(v){metodo=v;document.querySelectorAll('[data-metodo]').forEach(b=>b.classList.toggle('active',b.dataset.metodo===metodo));$('camposPago').innerHTML=muestra[metodo];
 if(metodo==='TARJETA')$('tarjeta').value=(totalCents()/100).toFixed(2);
 if(metodo==='TRANSFERENCIA')$('transferencia').value=(totalCents()/100).toFixed(2);
 $('camposPago').querySelectorAll('input').forEach(el=>el.addEventListener('input',cobro));cobro();}
document.querySelectorAll('[data-metodo]').forEach(el=>el.addEventListener('click',()=>cambiarMetodo(el.dataset.metodo)));
$('agregar').onclick=()=>{
 const nombre=limpio($('producto').value).slice(0,90),cantidad=Math.trunc(Number($('cantidad').value)),precioCentavos=asCents($('precio').value);
 if(!nombre||!Number.isInteger(cantidad)||cantidad<1||cantidad>100||precioCentavos<=0||precioCentavos>100000000){alert('Revisa descripción, cantidad y precio de ejemplo.');return}
 if(productos.length>=35){alert('Máximo 35 artículos por ticket de prueba.');return}
 productos.push({nombre,cantidad,precioCentavos});pintar();
};
function reset(){productos=[{nombre:'BARMICIL 40 GR',cantidad:2,precioCentavos:8500},{nombre:'DES OBAO ROLLON WILD 65G',cantidad:1,precioCentavos:4950}];cambiarMetodo('EFECTIVO');pintar()}
$('restaurar').onclick=reset;
$('imprimir').onclick=()=>{
 const p=estadoCobro();if(!p.valido)return;
 const pago=[];if(p.e>0)pago.push({tipo:'EFECTIVO',centavos:p.e});
 if(p.t>0)pago.push({tipo:'TARJETA',centavos:p.t,referencia:($('refTarjeta')?.value||'').trim().slice(0,100)});
 if(p.b>0)pago.push({tipo:'TRANSFERENCIA',centavos:p.b,referencia:($('refBanco')?.value||'').trim().slice(0,100)});
 $('datos').value=JSON.stringify({papel:$('papel').value,items:productos,pagos:pago});$('enviar').submit();
};
reset();
</script>

<?php if (!defined('GYD_HIDE_GLOBAL_NAV')): ?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=1">
<script>window.GYD_NAV_HOME='index.php';window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=1"></script>
<?php endif; ?>
</body></html>
