<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
requireLogin();
require_once __DIR__.'/../app/helpers/gyd_arqueos.php';
$u=currentUser()??[];
if(!in_array(($u['rol']??''),['CAJERO','ADMINISTRADOR','DIRECCION'],true)){http_response_code(403);exit('Acceso al POS no autorizado.');}
if(empty($_SESSION['pos_csrf']))$_SESSION['pos_csrf']=bin2hex(random_bytes(32));
$csrf=$_SESSION['pos_csrf'];
function he(string $v):string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>G&D | Punto de Venta · Pruebas</title>
<link rel="stylesheet" href="assets/css/pos_gyd.css?v=tarjetas-visual-20261012"><style>
/* Navegación superior G&D: modifica únicamente los tres enlaces de acciones. */
.bar .actions{display:flex;align-items:center;justify-content:flex-end;gap:10px;min-width:0}
.bar .actions>span{margin-right:10px;color:#f0f6ff;font-size:12px;white-space:nowrap}
.bar .actions .nav-pos-btn{display:inline-flex;align-items:center;justify-content:center;gap:9px;min-height:39px;padding:9px 15px;border:1px solid rgba(255,255,255,.17);border-radius:11px;color:#fff;text-decoration:none;font-size:13px;font-weight:750;line-height:1.2;white-space:nowrap;box-shadow:0 3px 9px rgba(0,0,0,.14);transition:background-color .15s,border-color .15s,transform .15s,box-shadow .15s}
.bar .actions .nav-pos-btn:hover{color:#fff;text-decoration:none;transform:translateY(-1px);box-shadow:0 5px 14px rgba(0,0,0,.22)}
.bar .actions .nav-pos-btn:focus-visible{outline:3px solid #f9cd6a;outline-offset:3px}
.bar .actions .nav-pos-prices{background:linear-gradient(180deg,#2879f0,#1558c5)}
.bar .actions .nav-pos-prices:hover{background:linear-gradient(180deg,#3686ff,#1765e6)}
.bar .actions .nav-pos-warehouse{background:linear-gradient(180deg,#21609f,#154576)}
.bar .actions .nav-pos-warehouse:hover{background:linear-gradient(180deg,#2d71b6,#17568e)}
.bar .actions .nav-pos-exit{background:linear-gradient(180deg,#f32639,#cc1024);border-color:#f55e6c}
.bar .actions .nav-pos-exit:hover{background:linear-gradient(180deg,#ff364b,#e11930)}
.bar .actions .nav-pos-icon{display:inline-flex;align-items:center;justify-content:center;font-size:18px;line-height:1;min-width:16px}
@media(max-width:1050px){.bar .actions{flex-wrap:wrap;gap:7px}.bar .actions>span{margin-right:2px}.bar .actions .nav-pos-btn{padding:8px 11px;font-size:12px}}
@media(max-width:640px){.bar{height:auto;min-height:58px;flex-wrap:wrap;align-items:flex-start}.bar .actions{width:100%;justify-content:flex-start}.bar .actions>span{flex-basis:100%}.bar .actions .nav-pos-btn{min-height:37px}.app{height:auto;min-height:0}}
.fisico-stocks{color:#12618b!important;font-weight:700}.fisico-stocks.alerta{color:#a24130!important}.pos-origen{font-weight:700;color:#166b59}.pos-solicitar{background:#175b95;border:0;border-radius:8px;padding:7px 10px;margin-top:5px;color:white;cursor:pointer;font-weight:600}.pos-solicitar:hover{background:#0e4270}
</style><link rel="stylesheet" href="assets/css/pos_origen_mejoras.css?v=<?=htmlspecialchars(substr(hash_file('sha256',__DIR__.'/assets/css/pos_origen_mejoras.css'),0,16),ENT_QUOTES,'UTF-8')?>"><link rel="stylesheet" href="assets/css/pos_corte_denominaciones.css?v=20261027"><link rel="stylesheet" href="assets/css/gyd_arqueo_modal.css?v=20261027"><link rel="stylesheet" href="assets/css/gyd_corte_autorizado.css?v=20261028_10"><link rel="stylesheet" href="assets/css/gyd_pos_documentos.css"></head><body>
<header class="bar"><div class="brand"><img src="assets/img/logo_farmacia_ticket.png" alt="G&D"><div><strong>G&D | PUNTO DE VENTA</strong><small>BASE LOCAL: prueba · Cobros de prueba registrados</small></div></div><div class="actions"><span><?=he((string)($u['nombre']??'Cajero'))?> · <?=he((string)($u['rol']??''))?></span><?php if(in_array(($u['rol']??''),['ADMINISTRADOR','DIRECCION'],true)):?><a class="nav-pos-btn nav-pos-warehouse" href="dashboard.php"><span class="nav-pos-icon" aria-hidden="true">⌂</span><span>Almacén</span></a><?php endif;?><?php if(in_array(($u['rol']??''),['ADMINISTRADOR','DIRECCION'],true)):?><a class="nav-pos-btn nav-pos-warehouse" href="pos_ubicaciones_config.php">Conteo y mínimos</a><a class="nav-pos-btn nav-pos-warehouse" href="erp_pos_reversiones.php">Cancelaciones / Devoluciones</a><?php endif;?><a class="nav-pos-btn nav-pos-exit" href="logout.php"><span class="nav-pos-icon" aria-hidden="true">➜</span><span>Salir</span></a></div></header>
<main class="app"><div class="notice"><strong>ENTORNO DE PRUEBA · BASE: prueba</strong><span>El POS lleva existencia GENERAL. Los tickets de Almacén NO suman al POS; ventas directas descuentan del conteo físico de Farmacia.</span></div>
<!-- GYD_ARQUEO_RETIRO_SEPARADOS_V1 -->
<div class="gyd-operaciones-efectivo">
  <a class="gyd-retiro-btn" id="gyd-retiro-link" href="pos_retiros.php">💵 Retiro de efectivo</a><a class="gyd-retiro-btn" href="pos_corte_retiros.php">🧾 Corte y tickets de retiros</a>
  <button class="gyd-arqueo-btn" id="gyd-arq-open" type="button">🧮 Arqueo protegido</button>
  <span class="gyd-ops-descr">Retiros sin corte · Arqueos solo con autorización de Gerencia o Dirección</span>
</div>
<div id="gyd-arqueo-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="gyd-arq-title">
 <div class="gyd-arq-dialog">
  <h2 id="gyd-arq-title">Autorizar arqueo de caja</h2>
  <p>Ingresa las credenciales de <strong>Gerencia, Dirección/Administración</strong> o <strong>Contabilidad</strong> cuando exista esa cuenta. La cajera no puede autorizar su propio arqueo.</p>
  <form id="gyd-arq-autorizacion" method="post" action="pos_arqueos.php" autocomplete="off">
   <input type="hidden" name="accion" value="autorizar">
   <input type="hidden" name="csrf" value="<?=arq_h(arq_csrf())?>">
   <input type="hidden" name="caja_id" value="">
   <label for="gyd-arq-user">Usuario autorizador</label>
   <input name="autorizador" id="gyd-arq-user" required maxlength="120" autocomplete="username">
   <label for="gyd-arq-password">Contraseña del autorizador</label>
   <input name="password" id="gyd-arq-password" type="password" required autocomplete="off">
   <div class="gyd-arq-actions"><button type="button" id="gyd-arq-close">Cancelar</button><button type="submit">Verificar y entrar</button></div>
  </form>
 </div>
</div>
<div class="headrow"><label>Sucursal y caja<select id="caja"></select></label><div class="status" id="estado">Cargando caja...</div><div class="headbuttons"><button id="abrir" class="secondary">Abrir caja</button><button id="gyd-acceso-retiro" class="secondary" type="button">Retiro de efectivo</button><button id="cerrar" class="secondary">Corte</button></div></div>
<div id="gyd-limite-aviso" hidden style="background:#fff1d7;border:2px solid #c77a16;border-radius:10px;padding:13px;margin:9px 0;color:#713c0d;font-weight:700" role="alert">LÍMITE DE EFECTIVO SUPERADO: debes registrar un retiro antes de realizar otra venta. <a id="gyd-retiro-link" href="pos_retiros.php" style="margin-left:9px;color:#0756a3">Registrar retiro obligatorio</a></div>
<div class="columns"><section class="panel sales-panel"><h2><span>VENTA ACTUAL</span><small>Escanear · F2 | Catálogo · Ctrl+B/F3</small></h2><div class="find"><input id="buscar" placeholder="Escanea código de barras o escribe producto..." autocomplete="off" autofocus><button id="btnbuscar">Buscar</button><button type="button" id="abrir_catalogo" class="secondary">Catálogo · Ctrl+B/F3</button></div><div id="resultados" class="resultados">Busca un producto para agregarlo al carrito.</div><h2 class="detail-title">ARTÍCULOS EN LA VENTA <small>Modifica cantidad para mayoreo</small></h2><div class="scroll cart-scroll"><table><thead><tr><th>Producto</th><th>Precio unitario</th><th>Cantidad</th><th>Importe</th><th></th></tr></thead><tbody id="carrito"><tr><td colspan="5">Carrito vacío</td></tr></tbody></table></div><div class="history-inline"><h2>ÚLTIMAS VENTAS <small>Reimprimir desde Ticket</small></h2><div class="scroll history-scroll"><table><thead><tr><th>Folio</th><th>Fecha</th><th>Total</th><th>Ticket</th></tr></thead><tbody id="historial"></tbody></table></div></div></section>
<aside class="panel pay" aria-labelledby="titulo-cobro">
  <h2 id="titulo-cobro">Cobro <small>Selecciona la forma de pago</small></h2>
  <div class="grand pay-grand"><span>TOTAL DE LA COMPRA</span><strong id="total">$0.00</strong></div>

  <div class="delivery-person">
    <label for="cliente_nombre">Cliente</label>
    <input id="cliente_nombre" type="text" maxlength="120" autocomplete="off" placeholder="Nombre de la persona que compra">
    <small>Persona a nombre de quien se registra la venta.</small>
  </div>
  <div class="delivery-person">
    <label for="quien_lleva">Quién lleva la mercancía <span id="lleva_required" aria-hidden="true"></span></label>
    <input id="quien_lleva" type="text" maxlength="120" autocomplete="off" placeholder="Nombre de quien lleva o recibe el pedido">
    <small id="delivery_hint">Se imprime en el comprobante para identificar a quien se entrega.</small>
  </div>
  <fieldset class="pay-methods">
    <legend>Forma de pago</legend>
    <div class="pay-options">
      <label class="pay-option"><input type="radio" name="pay_method" value="efectivo" checked><span>Efectivo</span></label>
      <label class="pay-option"><input type="radio" name="pay_method" value="tarjeta"><span>Tarjeta</span></label>
      <label class="pay-option"><input type="radio" name="pay_method" value="transferencia"><span>Transferencia</span></label>
      <label class="pay-option"><input type="radio" name="pay_method" value="mixto"><span>Mixto</span></label>
    </div>
  </fieldset>

  <!-- Se calculan los importes efectivos en JS; no se pide al cajero ingresarlos dos veces. -->
  <input type="hidden" id="p_ef" value="0.00">

  <div class="payment-details" id="pay_cash_box">
    <label for="p_rec" class="pay-field-label">Efectivo recibido del cliente ($)</label>
    <input id="p_rec" class="pay-large-input" type="number" min="0" step="0.01" inputmode="decimal" placeholder="Ej. 500.00" autocomplete="off">
    <div id="pay_quick" class="pay-quick" aria-label="Importes rápidos"></div>
    <p class="pay-subtext" id="pay_cash_note">El cambio se calculará automáticamente.</p>
  </div>

  <div class="payment-details" id="pay_digital_box" hidden>
    <span class="pay-field-label">Importe a pagar</span>
    <strong id="pay_digital_amount" class="pay-single-digital">$0.00</strong>
    <p class="pay-subtext">Confirma el pago en la terminal o banco antes de registrar la venta.</p>
  </div>

  <div class="payment-details" id="pay_mixed_box" hidden>
    <p class="pay-subtext pay-mixed-intro">Indica cuánto se cubrirá con tarjeta y transferencia. El resto será efectivo.</p>
    <div class="pay-two-fields">
      <label for="p_tar">Monto con tarjeta ($)<input id="p_tar" type="number" min="0" step="0.01" inputmode="decimal" value="0"></label>
      <label for="p_tra">Monto por transferencia ($)<input id="p_tra" type="number" min="0" step="0.01" inputmode="decimal" value="0"></label>
    </div>
    <div class="pay-cash-due"><span>Efectivo que debe aportar</span><strong id="pay_cash_due">$0.00</strong></div>
  </div>

  <div class="payment-details" id="pay_card_type_box" hidden>
    <span class="pay-field-label">Selecciona el tipo de tarjeta</span>
    <div class="pay-options" role="radiogroup" aria-label="Tarjeta de débito o crédito">
      <label class="pay-option"><input type="radio" name="tipo_tarjeta" value="DEBITO"><span>Débito</span></label>
      <label class="pay-option"><input type="radio" name="tipo_tarjeta" value="CREDITO"><span>Crédito</span></label>
    </div>
  </div>
  <div class="payment-details pay-ref" id="pay_card_ref" hidden>
    <label for="ref_tar" class="pay-field-label">Folio de autorización de tarjeta</label>
    <input id="ref_tar" maxlength="120" autocomplete="off" placeholder="Referencia de la terminal bancaria">
  </div>
  <div class="payment-details pay-ref" id="pay_transfer_ref" hidden>
    <label for="ref_tra" class="pay-field-label">Referencia de transferencia</label>
    <input id="ref_tra" maxlength="120" autocomplete="off" placeholder="Referencia bancaria confirmada">
  </div>

  <label class="check pay-check" id="pay_confirm_box" hidden>
    <input type="checkbox" id="confirmacion">
    <span>Confirmo que los pagos electrónicos fueron recibidos o autorizados en un medio externo.</span>
  </label>

  <div class="pay-summary" aria-live="polite">
    <div><span>Aplicado a la compra</span><strong id="aplicado">$0.00</strong></div>
    <div><span>Por cubrir</span><strong id="faltante">$0.00</strong></div>
    <div class="pay-change"><span>CAMBIO A ENTREGAR</span><strong id="cambio">$0.00</strong></div>
  </div>
  <p id="pay_hint" class="pay-hint" role="status" aria-live="polite">Agrega productos para comenzar a cobrar.</p>
  <button id="cobrar" class="primary" disabled>REGISTRAR VENTA E IMPRIMIR · F9</button>
  <div class="keyboard-hints" aria-label="Atajos de teclado">⌨ <b>F2</b> Buscar · <b>Ctrl+B/F3</b> Catálogo · <b>F4</b> Quién lleva · <b>F6</b> Efectivo · <b>F8</b> Exacto · <b>F9</b> Cobrar</div>
  <label class="print-preference"><input id="auto_print" type="checkbox"> Imprimir automáticamente al registrar venta</label>
  <div class="small">Para imprimir sin diálogo se requiere configurar Chrome con <code>--kiosk-printing</code> y definir la térmica como impresora predeterminada de Windows. El navegador por sí solo no puede elegir ni detectar silenciosamente una impresora.</div>
  <div class="small">Los pagos electrónicos se comprueban fuera del sistema; el POS registra el cobro en <b>prueba</b>.</div>
</aside></div>
<div class="pos-bottom-bar" aria-label="Resumen de caja"><div><b>G&D FARMACIA</b> · POS DE PRUEBAS</div><div>Artículos <strong id="bar_articulos">0</strong></div><div>Unidades <strong id="bar_unidades">0</strong></div><div>Total <strong id="bar_total">$0.00</strong></div><div>Teclas <b>F2</b> Buscar · <b>Ctrl+B/F3</b> Catálogo · <b>F9</b> Cobrar</div></div>
<div class="modal catalog-overlay" id="catalog_modal" hidden role="dialog" aria-modal="true" aria-labelledby="catalog_title">
 <div class="catalog-dialog">
  <div class="catalog-header"><h2 id="catalog_title">Buscar en catálogo · Ctrl+B</h2><button id="catalog_close" type="button" class="secondary" aria-label="Cerrar">✕ Cerrar</button></div>
  <div class="find catalog-search"><input id="catalog_q" placeholder="Empieza a escribir código, barras o descripción..." autocomplete="off" aria-label="Buscar producto"><button id="catalog_search" type="button">Buscar</button></div>
  <label class="catalog-toggle"><input type="checkbox" id="catalog_all"> Mostrar todo el catálogo (incluye productos sin precio)</label>
  <div id="catalog_count" class="catalog-meta" aria-live="polite">Cargando catálogo...</div>
  <div id="catalog_results" class="catalog-results"></div>
  <div class="catalog-pager"><button type="button" id="catalog_prev" class="secondary">← Anterior</button><span id="catalog_page">Página 1</span><button type="button" id="catalog_next" class="secondary">Siguiente →</button></div>
  <div class="small">Los productos sin precio se muestran para consulta, pero no podrán cobrarse hasta que el administrador les asigne un precio POS. Los productos sin stock también se muestran, pero no pueden venderse.</div>
 </div>
</div>
<div class="modal special-overlay" id="special_modal" hidden role="dialog" aria-modal="true" aria-labelledby="special_title">
 <div class="dialog"><h2 id="special_title">Precio especial autorizado</h2><p id="special_description"></p>
 <label>Precio unitario acordado ($)<input id="special_price" type="number" min="0.01" step="0.01" autocomplete="off"></label>
 <label style="display:block;margin-top:10px">Motivo del ajuste<input id="special_reason" placeholder="Motivo obligatorio" maxlength="250" autocomplete="off"></label>
 <div class="dialogActions"><button type="button" class="secondary" id="special_cancel">Cancelar</button><button type="button" id="special_confirm">Autorizar precio</button></div>
 </div>
</div>
<div class="gyd-origen-overlay" id="pos_origen_modal" hidden role="dialog" aria-modal="true" aria-labelledby="pos_origen_title">
 <div class="gyd-origen-dialog">
  <span class="gyd-origen-tag">G&D · Procedencia del pedido</span>
  <h2 id="pos_origen_title">Origen del surtido</h2>
  <p id="pos_origen_description"></p>
  <ul id="pos_origen_list"></ul>
  <strong id="pos_origen_sale"></strong>
  <div class="gyd-origen-actions"><button type="button" id="pos_origen_close" class="secondary">Cerrar aviso</button><button type="button" id="pos_origen_print" class="primary">Imprimir ticket ahora</button></div>
 </div>
</div>
<div class="modal" id="modal" hidden><div class="dialog"><h2 id="tituloDialog">Operación</h2><p id="descripcionDialog"></p><label id="campoDialog">Monto ($)<input type="number" step="0.01" min="0" id="montoDialog" value="0"></label><div class="dialogActions"><button id="cancelDialog" class="secondary">Cancelar</button><button id="confirmDialog">Confirmar</button></div></div></div>
<div class="modal" id="gyd-retiro-obligatorio" hidden role="dialog" aria-modal="true" aria-labelledby="gyd-limite-titulo"><div class="dialog" style="max-width:455px"><h2 id="gyd-limite-titulo">Retiro de efectivo obligatorio</h2><p id="gyd-limite-descripcion">La caja superó el límite de $3,000.00. Para continuar vendiendo primero debes registrar el retiro.</p><div class="dialogActions"><button class="secondary" type="button" id="gyd-limite-entendido">Entendido</button><a id="gyd-limite-retirar" class="primary" href="pos_retiros.php" style="padding:11px;border-radius:8px;background:#1560b6;color:white;text-decoration:none">Realizar retiro</a></div></div></div>
<div class="modal" id="gyd-modal-acceso" hidden role="dialog" aria-modal="true" aria-labelledby="gyd-modal-acceso-titulo">
 <div class="dialog" style="max-width:440px">
  <h2 id="gyd-modal-acceso-titulo">Confirmar identidad</h2>
  <p id="gyd-modal-acceso-texto">Confirma tu identidad antes de entrar.</p>
  <form id="gyd-form-acceso" autocomplete="off">
   <label id="gyd-acceso-usuario-grupo" hidden style="display:block;margin-top:10px">Usuario autorizador (Gerencia, Dueños o Administración)
    <input id="gyd-acceso-usuario" type="text" maxlength="120" autocomplete="off" placeholder="Usuario o correo">
   </label>
   <label style="display:block;margin-top:10px">Contraseña
    <input id="gyd-acceso-clave" type="password" maxlength="512" autocomplete="off" placeholder="Ingresa la contraseña" required>
   </label>
   <p id="gyd-acceso-error" hidden role="alert" style="color:#a21e20;font-weight:600"></p>
   <div class="dialogActions"><button class="secondary" type="button" id="gyd-acceso-cancelar">Cancelar</button><button type="submit" id="gyd-acceso-confirmar">Verificar y continuar</button></div>
  </form>
 </div>
</div>
<div id="toast" role="alert" hidden></div>
</main>
<script>window.POS_CONFIG={csrf:<?=json_encode($csrf)?>,admin:<?=json_encode(in_array(($u['rol']??''),['ADMINISTRADOR','DIRECCION'],true))?>};</script>
<script src="assets/js/pos_eficiencia.js?v=<?=htmlspecialchars(substr(hash_file('sha256', __DIR__.'/assets/js/pos_eficiencia.js'), 0, 16), ENT_QUOTES, 'UTF-8')?>"></script>
<script src="assets/js/pos_corte_denominaciones.js?v=20261027"></script>
<script src="assets/js/gyd_corte_autorizado.js?v=20261028_11"></script><script src="assets/js/gyd_acceso_previo.js?v=20261028_11"></script><script src="assets/js/pos_gyd.js?v=<?=htmlspecialchars(substr(hash_file('sha256', __DIR__.'/assets/js/pos_gyd.js'), 0, 16), ENT_QUOTES, 'UTF-8')?>"></script><script src="assets/js/pos_resumen_visual.js?v=1"></script><script defer src="assets/js/pos_retiros_aviso.js?v=20261028_7"></script>
<script defer src="assets/js/gyd_arqueo_modal.js?v=20261027"></script>
<script src="assets/js/gyd_feedback_modal.js?v=5" defer></script>
<script src="assets/js/gyd_pos_documentos.js"></script></body></html>
