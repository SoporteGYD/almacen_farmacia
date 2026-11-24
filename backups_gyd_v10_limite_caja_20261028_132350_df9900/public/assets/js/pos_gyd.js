'use strict';
const $=id=>document.getElementById(id), money=c=>'$'+(c/100).toLocaleString('es-MX',{minimumFractionDigits:2,maximumFractionDigits:2});
const cents=x=>Math.round((Number(x)||0)*100), escapeHtml=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
let cart=new Map(),cajas=[],current=null,turno=null,busy=false,key=crypto.randomUUID();
let catalogPage=1,catalogPages=1,catalogSeq=0,searchSeq=0,searchDebounce,modalTimer,lastSpecialId=null;
const asInt=x=>Math.max(0,Number(x)||0);
function effectivePrice(x){return x.special!==null ? x.special : (x.wholesale>0 && x.minQty>=2 && x.qty>=x.minQty && x.wholesale<=x.base ? x.wholesale : x.base);}
function quoteUnit(x){return x.special!==null?'Especial':(x.wholesale>0&&x.minQty>=2&&x.qty>=x.minQty&&x.wholesale<=x.base?'Mayoreo':'Público');}
function normalizePrice(x){x.price=effectivePrice(x);return x;}
async function fetchCatalog(q,page=1,priced=true){
 const v=new URLSearchParams({action:'catalogo',caja_id:$('caja').value,q,page:String(page),solo_precio:priced?'1':'0'});
 const response=await fetch('pos_api.php?'+v,{credentials:'same-origin',cache:'no-store'});
 const result=await response.json();if(!response.ok||result.error)throw Error(result.error||'No se pudo consultar catálogo.');return result;
}
function productMarkup(p){
 const price=cents(p.precio_publico),stock=Number(p.existencia)||0,may=cents(p.precio_mayoreo),min=Number(p.cantidad_minima)||0,modo=p.modalidad_presentacion||'PIEZA',factor=Number(p.piezas_por_caja)||1,boxPrice=price*factor;
 const almacenable=!!p.tiene_almacen,stockFarm=p.conteo_configurado?Number(p.stock_farmacia)||0:null,stockBodega=Number(p.almacen_disponible)||0,minimum=Number(p.minimo_farmacia??24);
 const stores=[['Tapachula',p.stock_tapachula],['Toscana',p.stock_toscana],['Tuxtla',p.stock_tuxtla],['Hidalgo',p.stock_hidalgo]];
 const branch=String(current?.nombre||'').toUpperCase();
 const storesHtml=stores.map(([label,n])=>`<span class="stock-branch${(label==='Hidalgo'&&branch.includes('HIDALGO'))||(label==='Tuxtla'&&branch.includes('TUXTLA'))||(label==='Toscana'&&branch.includes('TOSCANA'))||(label==='Tapachula'&&branch.includes('TAPACHULA'))?' current':''}">${label}: <b>${Number(n)||0}</b></span>`).join('');
 const descuadre=almacenable&&stockFarm!==null&&stockFarm+(Number(p.stock_almacen)||0)!==stock;
 const stockHtml=almacenable
   ? `<span class="gyd-stock-panels"><span class="gyd-stock-pill">POS general <strong>${stock}</strong></span><span class="gyd-stock-pill">Farmacia física <strong>${stockFarm===null?'Sin conteo':stockFarm}</strong></span><span class="gyd-stock-pill">Almacén disponible <strong>${stockBodega}</strong></span><span class="gyd-stock-pill">Reserva <strong>${minimum}</strong></span></span>`
   : `<span class="gyd-stock-panels"><span class="gyd-stock-pill">POS general <strong>${stock}</strong></span></span>`;
 return `<button type="button" class="product gyd-product" data-product="${Number(p.id)}" ${price<=0||stock<=0?'disabled':''}>
  <span class="gyd-product-details"><b>${escapeHtml(p.descripcion)}</b><small>${escapeHtml(p.codigo_barras||p.codigo)}${min>=2&&may>0?' · Mayoreo desde '+min+' pzas: '+money(may):''}${modo!=='PIEZA'?' · Caja '+factor+' pzas: '+money(boxPrice):''}</small><span class="stocks-multi">${storesHtml}</span>${stockHtml}${descuadre?'<small class="gyd-inventory-warning">¡Revisar conciliación! El POS general no coincide con Farmacia + Almacén físico.</small>':''}</span>
  <strong class="gyd-product-price">${price>0?money(price):'Sin precio'}${stock<=0?'<small>Sin stock POS</small>':''}</strong></button>`;
}

function notify(s,bad=false){const t=$('toast');t.textContent=s;t.hidden=false;t.className=bad?'bad':'';setTimeout(()=>t.hidden=true,6000);}
function showSurtidoModal(tipo,productos,venta=null){
 const modal=$('pos_origen_modal');if(!modal)return;
 const titulo=$('pos_origen_title'),detalle=$('pos_origen_description'),lista=$('pos_origen_list');
 const almacen=tipo==='almacen';
 titulo.textContent=almacen?'SOLICITUD ENVIADA A ALMACÉN':'SE SURTIRÁ DESDE FARMACIA';
 detalle.textContent=almacen
  ? 'La cantidad supera lo disponible en Farmacia sin tocar la reserva configurada. Almacén recibió la solicitud. El ticket de venta se puede imprimir inmediatamente; el surtido físico continuará con los verificadores.'
  : 'La cantidad se surtirá directamente de Farmacia. No se envió solicitud a Almacén.';
 lista.replaceChildren();
 for(const p of productos){const li=document.createElement('li');li.textContent=(p.nombre||'Producto')+(p.piezas?' · '+p.piezas+' piezas':'')+(p.folio?' · Solicitud '+p.folio:'')+(p.motivo?' · '+p.motivo:'');lista.append(li);}
 $('pos_origen_sale').textContent=venta?.folio?'Venta registrada: '+venta.folio:'';
 $('pos_origen_print').hidden=!venta?.venta_id;
 $('pos_origen_print').onclick=()=>{const suffix=$('auto_print').checked?'&copia=cliente&imprimir=1&secuencia=1':'';window.open('pos_ticket.php?id='+encodeURIComponent(venta.venta_id)+suffix,'_blank','noopener');modal.hidden=true;};
 modal.hidden=false;
 ($('pos_origen_print').hidden?$('pos_origen_close'):$('pos_origen_print')).focus();
}
$('pos_origen_close').onclick=()=>$('pos_origen_modal').hidden=true;

async function api(action,data=null){const method=data?'POST':'GET';const query=new URLSearchParams({action,...(!data?{caja_id:$('caja').value,q:$('buscar').value}:{})});
 const res=await fetch('pos_api.php?'+query,{method,headers:data?{'Content-Type':'application/json'}:{},body:data?JSON.stringify({...data,csrf:window.POS_CONFIG.csrf}):undefined,credentials:'same-origin'});
 const text=await res.text();let v;try{v=JSON.parse(text)}catch{throw Error('No se recibió JSON. Comprueba la sesión y la instalación del POS.');}
 if(!res.ok||v.error)throw Error(v.error||'Error de servidor');return v;
}
function totalPiezasProducto(id){return [...cart.values()].filter(x=>x.id===id).reduce((a,x)=>a+x.qty*x.factor,0);}
function routePOS(x){
 if(!x.tieneAlmacen)return 'FARMACIA_GENERAL';
 if(x.ticketId)return 'ALMACEN';
 if(!x.conteo)return 'SIN_CONTEO';
 const piezas=totalPiezasProducto(x.id);
 if(piezas>x.stock)return 'INSUFICIENTE';
 if(piezas<=Math.max(0,x.farm-x.minimo))return 'FARMACIA';
 if(piezas<=x.bodega)return 'ALMACEN';
 if(piezas<=x.farm)return 'FARMACIA';
 return 'SIN_ORIGEN';
}
async function apiOrigen(action,data=null,extra={}){
 const v=new URLSearchParams({action,...(!data?extra:{})});
 const response=await fetch('pos_inteligente_api.php?'+v,{credentials:'same-origin',cache:'no-store',method:data?'POST':'GET',headers:data?{'Content-Type':'application/json'}:{},body:data?JSON.stringify({...data,csrf:window.POS_CONFIG.csrf}):undefined});
 const r=await response.json();if(!response.ok||r.error)throw Error(r.error||'Error de la solicitud.');return r;
}
async function pedirBodega(keyCart,automatico=false){
 const x=cart.get(keyCart);if(!x)return null;
 const lines=[...cart.values()].filter(y=>y.id===x.id),attached=lines.find(y=>y.ticketId);
 if(attached){lines.forEach(y=>{y.ticketId=attached.ticketId;y.ticketFolio=attached.ticketFolio;y.ticketReady=attached.ticketReady;});return {id:attached.ticketId,folio:attached.ticketFolio,reutilizado:true};}
 const piezas=totalPiezasProducto(x.id);
 if(piezas<1)throw Error('No hay piezas válidas para solicitar al Almacén.');
 const gdWarehouseStarted=performance.now();
 const r=await apiOrigen('pedir',{caja_id:Number($('caja').value),producto_id:x.id,cantidad:piezas,clave:crypto.randomUUID()});
 window.GYD_EFICIENCIA?.warehouse(gdWarehouseStarted);
 lines.forEach(y=>{y.ticketId=r.id;y.ticketFolio=r.folio;y.ticketReady=r.estado==='SURTIDO';});
 rowCart();
 if(!automatico)showSurtidoModal('almacen',[{nombre:x.description,piezas,folio:r.folio,minimo:x.minimo,farmacia:x.farm,bodega:x.bodega}]);
 return {id:r.id,folio:r.folio};
}

async function recuperarBodega(keyCart){
 const x=cart.get(keyCart);if(!x||x.ticketId)return;
 try{const r=await apiOrigen('mis_tickets',null,{caja_id:$('caja').value});
 const matching=(r.tickets||[]).filter(t=>Number(t.producto_id)===x.id&&Number(t.cantidad)===totalPiezasProducto(x.id));
 if(!matching.length){notify('No hay solicitud pendiente con este producto y cantidad en la caja.',true);return;}
 const t=matching[0];[...cart.values()].filter(y=>y.id===x.id).forEach(y=>{y.ticketId=Number(t.id);y.ticketFolio=t.folio;y.ticketReady=t.estado==='SURTIDO';});
 notify('Solicitud de Almacén '+t.folio+' recuperada.');rowCart();
 }catch(e){notify(e.message,true);}
}

async function verificarBodega(keyCart){
 const x=cart.get(keyCart);if(!x||!x.ticketId)return;
 try{const r=await apiOrigen('ticket',null,{caja_id:$('caja').value,ticket_id:x.ticketId});
 if(r.ticket.estado==='CANCELADO'){notify('El ticket fue cancelado. Solicita revisión antes de cobrar.',true);return;}
 const listo=!!r.listo;[...cart.values()].filter(y=>y.id===x.id).forEach(y=>y.ticketReady=listo);
 notify('Solicitud '+r.ticket.folio+': '+r.ticket.estado+'. Puedes imprimir el ticket de venta sin esperar el surtido.');rowCart();
 }catch(e){notify(e.message,true);}
}

function rowCart(){
 let html='',sum=0;
 for(const x of cart.values()){
  normalizePrice(x);sum+=x.qty*x.price;
  const tier=quoteUnit(x),strike=x.base!==x.price?`<small class="strike">${money(x.base)}</small>`:'';
  const changeButton=window.POS_CONFIG.admin?`<button type="button" class="price-edit" data-special="${x.key}">Editar precio</button>`:'';
  const origen=routePOS(x),unidades=totalPiezasProducto(x.id);
  const estado=x.ticketId?`<b>ALMACÉN · ${escapeHtml(x.ticketFolio||'')} · ${x.ticketReady?'SURTIDO':'SOLICITADO'}</b>`:origen==='ALMACEN'?'<b>ALMACÉN · solicitud automática al cobrar</b>':origen==='FARMACIA'?'<b>FARMACIA · surtido directo</b>':'<b>Revisar existencias</b>';
  html+=`<tr><td>${escapeHtml(x.description)}<small>${escapeHtml(x.code)} · ${x.presentation==='CAJA'?'CAJA ('+x.factor+' piezas)':'PIEZA'} · Stock POS general ${x.stock} piezas</small>${x.tieneAlmacen?`<div class="gyd-cart-stock"><span>Farmacia <b>${x.conteo?x.farm:'Sin conteo'}</b></span><span>Almacén <b>${x.bodega}</b></span><span>Reserva <b>${x.minimo}</b></span><span>Solicitado <b>${unidades} pzas.</b></span></div><small class="pos-origen">${estado}</small>${x.ticketId?`<button type="button" class="pos-solicitar" data-verificar-ticket="${x.key}">Consultar solicitud</button>`:origen==='ALMACEN'?`<button type="button" class="pos-solicitar" data-pedir="${x.key}">Solicitar antes del cobro</button><button type="button" class="pos-solicitar" data-recuperar="${x.key}">Retomar solicitud</button>`:''}`:''}</td>
  <td><div class="cart-price"><strong>${money(x.price)}</strong>${strike}<span class="tier ${x.special!==null?'manual':''}">${tier}</span>${changeButton}</div></td>
  <td><input class="cart-qty" type="number" min="1" max="${Math.min(Math.floor(x.stock/x.factor),100000)}" data-qty="${x.key}" value="${x.qty}" aria-label="Cantidad de ${escapeHtml(x.description)}"></td>
  <td>${money(x.qty*x.price)}</td><td><button type="button" data-del="${x.key}" class="sm danger">×</button></td></tr>`;
 }
 $('carrito').innerHTML=html||'<tr><td colspan="5">Carrito vacío</td></tr>';
 $('total').textContent=money(sum);updateMoney();
}
function entregaObligatoria(){return Number(current?.ticket_sin_importes||0)===1;}
function validateDelivery(){
 const required=entregaObligatoria(), name=$('quien_lleva').value.trim();
 $('quien_lleva').required=required;
 $('lleva_required').textContent=required?'*':'';
 $('delivery_hint').textContent=required
   ? 'Obligatorio en Ciudad Hidalgo: el ticket se imprime sin precios ni montos.'
   : 'Opcional: este nombre aparecerá en el ticket.';
 return !required || name.length>=2;
}
function paymentMode(){return document.querySelector('input[name="pay_method"]:checked').value;}
function cartTotal(){return [...cart.values()].reduce((a,x)=>a+x.price*x.qty,0);}
function nonnegativeCents(id){return Math.max(0,cents($(id).value));}
function setVisible(id,visible){$(id).hidden=!visible;}
function paymentState(){
 const total=cartTotal(),mode=paymentMode();
 const card=mode==='tarjeta'?total:(mode==='mixto'?nonnegativeCents('p_tar'):0);
 const transfer=mode==='transferencia'?total:(mode==='mixto'?nonnegativeCents('p_tra'):0);
 const digital=card+transfer, digitalTooHigh=digital>total;
 const cashDue=Math.max(0,total-digital);
 const cashNeeded=(mode==='efectivo'||(mode==='mixto'&&cashDue>0));
 const received=cashNeeded?nonnegativeCents('p_rec'):0;
 const cashPaid=Math.min(received,cashDue);
 const applied=cashPaid+digital;
 const remaining=Math.max(0,total-applied);
 const change=received>cashDue?received-cashDue:0;
 const hasCard=card>0, hasTransfer=transfer>0, hasDigital=hasCard||hasTransfer;
 const refsOk=(!hasCard||$('ref_tar').value.trim().length>=3)&&(!hasTransfer||$('ref_tra').value.trim().length>=3);
 const cardTypeOk=!hasCard||!!document.querySelector('input[name="tipo_tarjeta"]:checked');
 const confirmed=!hasDigital||$('confirmacion').checked;
 const valid=total>0&&!digitalTooHigh&&applied===total&&refsOk&&cardTypeOk&&confirmed;
 return {total,mode,card,transfer,cashDue,cashNeeded,received,cashPaid,applied,remaining,change,hasCard,hasTransfer,hasDigital,digitalTooHigh,refsOk,cardTypeOk,confirmed,valid};
}
function quickCash(due){
 const opts=[due,Math.ceil(due/5000)*5000,Math.ceil(due/10000)*10000,Math.ceil(due/50000)*50000,Math.ceil(due/100000)*100000];
 const unique=[...new Set(opts)].filter(v=>v>0).slice(0,3);
 const box=$('pay_quick');
 const signature=unique.join(',');if(box.dataset.values===signature)return;
 box.dataset.values=signature;
 box.innerHTML=unique.map((v,i)=>`<button type="button" class="quick-amount" data-cents="${v}">${i===0?'Exacto · ':''}${money(v)}</button>`).join('');
}
function updateMoney(){
 const p=paymentState();
 // Contrato original del backend: el efectivo aplicado no es el recibido bruto.
 $('p_ef').value=(p.cashPaid/100).toFixed(2);
 if(p.mode!=='mixto'){
   $('p_tar').value=(p.card/100).toFixed(2);
   $('p_tra').value=(p.transfer/100).toFixed(2);
 }
 setVisible('pay_mixed_box',p.mode==='mixto');
 setVisible('pay_digital_box',p.mode==='tarjeta'||p.mode==='transferencia');
 setVisible('pay_cash_box',p.cashNeeded);
 setVisible('pay_card_ref',p.hasCard);
 setVisible('pay_card_type_box',p.hasCard);
 setVisible('pay_transfer_ref',p.hasTransfer);
 setVisible('pay_confirm_box',p.hasDigital);
 $('pay_digital_amount').textContent=money(p.total);
 $('pay_cash_due').textContent=money(p.cashDue);
 $('aplicado').textContent=money(p.applied);
 $('faltante').textContent=money(p.remaining);
 $('cambio').textContent=money(p.change);
 if(p.cashNeeded)quickCash(p.cashDue);
 const hint=$('pay_hint');
 hint.className='pay-hint';
 if(!p.total){hint.textContent='Agrega productos para comenzar a cobrar.';}
 else if(p.digitalTooHigh){hint.textContent='La suma de tarjeta y transferencia supera el total.';hint.classList.add('error');}
 else if(p.remaining){hint.textContent='Faltan '+money(p.remaining)+' para completar el pago.';}
 else if(!p.cardTypeOk){hint.textContent='Selecciona si la tarjeta es de débito o crédito.';}
 else if(!p.refsOk){hint.textContent='Ingresa la referencia de cada pago electrónico.';}
 else if(!p.confirmed){hint.textContent='Confirma que el pago electrónico se realizó.';}
 else{hint.textContent=p.change?'Cobro completo · entrega '+money(p.change)+' de cambio.':'Cobro completo · listo para registrar.';hint.classList.add('ok');}
 $('cobrar').disabled=busy||!turno||!p.valid||!validateDelivery();
 return p;
}
function changePaymentMode(){
 // Evitar que importes de un método anterior se guarden por accidente.
 $('p_rec').value='';$('p_tar').value='0';$('p_tra').value='0';
 $('ref_tar').value='';$('ref_tra').value='';$('confirmacion').checked=false;
 updateMoney();
}
async function init(){try{const r=await api('sucursales');cajas=r.sucursales;$('caja').innerHTML=cajas.map(x=>`<option value="${x.caja_id}">${escapeHtml(x.nombre)} · ${escapeHtml(x.caja_nombre)}</option>`).join('');if(!cajas.length){$('estado').textContent='SIN CAJAS POS CONFIGURADAS';$('abrir').disabled=true;$('cerrar').disabled=true;$('cobrar').disabled=true;notify(window.POS_CONFIG.admin?'No hay sucursales/cajas activas en la base prueba. Abre Precios y stock POS e importa el SQL de reparación.':'No hay caja disponible para tu cuenta. Solicita al administrador verificar las cajas y tu asignación de almacén.',true);return;}await state();}catch(e){notify(e.message,true);}}
async function state(){try{const r=await api('estado');turno=r.turno;current=cajas.find(c=>String(c.caja_id)===$('caja').value);$('estado').textContent=turno?'TURNO ABIERTO #'+turno.id+' · '+turno.cajero:'CAJA CERRADA';$('abrir').disabled=!!turno;$('cerrar').disabled=!turno;$('cobrar').disabled=!turno;
 $('historial').innerHTML=r.ventas.map(v=>`<tr><td>${escapeHtml(v.folio)}</td><td>${escapeHtml(v.pagada_en)}</td><td>${money(cents(v.total))}</td><td><a target="_blank" rel="noopener" href="pos_ticket.php?id=${v.id}">Ver / imprimir</a></td></tr>`).join('')||'<tr><td colspan="4">Sin ventas todavía</td></tr>';updateMoney();}catch(e){notify(e.message,true);}}
async function search(){
 const q=$('buscar').value.trim();
 if(q.length<1){$('resultados').textContent='Escribe código o descripción, o pulsa Ctrl + B para consultar el catálogo.';return;}
 const seq=++searchSeq;
 try{
  const r=await fetchCatalog(q,1,false);if(seq!==searchSeq)return;
  $('resultados').innerHTML=r.productos.map(productMarkup).join('')||'No se encontraron productos.';
  window.lastProducts=r.productos;
  const exact=r.productos.find(p=>q===String(p.codigo_barras||'')||q===String(p.codigo||''));
  if(exact&&window.pendingScan===q){window.pendingScan='';add(exact);window.GYD_EFICIENCIA?.count('escaneos');}
 }catch(e){if(seq===searchSeq)notify(e.message,true);}
}
// Selector directo de presentación: no usa confirm() ni cambia la venta hasta elegir.
let presentationPicker=null;
function closePresentationPicker(){
 if(presentationPicker){presentationPicker.remove();presentationPicker=null;}
 document.removeEventListener('keydown',escapePresentationPicker,true);
}
function escapePresentationPicker(event){if(event.key==='Escape'){event.preventDefault();event.stopPropagation();closePresentationPicker();}}
function choosePresentation(p, units, piecePrice){
 closePresentationPicker();
 const overlay=document.createElement('div');
 overlay.className='gyd-presentation-overlay';
 overlay.setAttribute('role','dialog');overlay.setAttribute('aria-modal','true');
 overlay.setAttribute('aria-label','Selecciona la presentación');
 overlay.innerHTML=`<div class="gyd-presentation-panel">
   <div class="gyd-presentation-title">Selecciona la presentación</div>
   <p class="gyd-presentation-hint">¿Cómo deseas vender este producto?</p>
   <div class="gyd-presentation-product"></div>
   <div class="gyd-presentation-actions">
     <button type="button" class="gyd-presentation-option" data-choice="CAJA"><span class="gyd-presentation-emoji" aria-hidden="true">📦</span><strong>CAJA</strong><small>${units} piezas</small><b>${money(piecePrice*units)}</b></button>
     <button type="button" class="gyd-presentation-option" data-choice="PIEZA"><span class="gyd-presentation-emoji" aria-hidden="true">💊</span><strong>PIEZA</strong><small>1 pieza</small><b>${money(piecePrice)}</b></button>
   </div>
   <p class="gyd-presentation-footnote">Presiona Esc para cerrar sin agregar.</p>
 </div>`;
 overlay.querySelector('.gyd-presentation-product').textContent=p.descripcion||p.codigo||'';
 overlay.addEventListener('click',e=>{if(e.target===overlay)closePresentationPicker();});
 overlay.querySelectorAll('[data-choice]').forEach(btn=>btn.addEventListener('click',()=>{
   const choice=btn.dataset.choice;
   closePresentationPicker();
   add(p,choice);
 }));
 let style=document.getElementById('gyd-presentation-styles');
 if(!style){style=document.createElement('style');style.id='gyd-presentation-styles';style.textContent=`
 .gyd-presentation-overlay{position:fixed;inset:0;z-index:999999;background:rgba(8,27,52,.65);display:flex;align-items:center;justify-content:center;padding:18px;box-sizing:border-box}
 .gyd-presentation-panel{width:min(460px,100%);max-height:calc(100vh - 36px);overflow-y:auto;background:#fff;color:#153758;padding:24px;border-radius:17px;box-shadow:0 22px 70px rgba(0,0,0,.25);font-family:inherit;box-sizing:border-box}
 .gyd-presentation-title{font-size:22px;font-weight:800;line-height:1.2}
 .gyd-presentation-hint{font-size:14px;color:#57708c;margin:8px 0 12px}
 .gyd-presentation-product{font-size:13px;font-weight:700;margin-bottom:16px;overflow-wrap:anywhere}
 .gyd-presentation-actions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
 .gyd-presentation-option{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:7px;min-width:0;min-height:155px;padding:14px 8px;border:2px solid #cfddf0;border-radius:13px;background:#f9fbff;color:#163b6d;cursor:pointer;font:inherit;transition:background .15s,border-color .15s,transform .15s}
 .gyd-presentation-option:hover,.gyd-presentation-option:focus-visible{background:#edf5ff;border-color:#2165cf;transform:translateY(-1px);outline:none}
 .gyd-presentation-emoji{font-size:29px;line-height:1.2}.gyd-presentation-option strong{font-size:17px}.gyd-presentation-option small{font-size:12px;color:#546985}.gyd-presentation-option b{font-size:20px;color:#1556b0}
 .gyd-presentation-footnote{text-align:center;color:#73849a;font-size:12px;margin:16px 0 0}
 @media(max-width:370px){.gyd-presentation-panel{padding:16px}.gyd-presentation-actions{gap:8px}.gyd-presentation-option{min-height:135px}.gyd-presentation-option b{font-size:17px}}
 `;document.head.appendChild(style);}
 document.body.appendChild(overlay);presentationPicker=overlay;
 document.addEventListener('keydown',escapePresentationPicker,true);
 overlay.querySelector('[data-choice="CAJA"]').focus();
}
function add(p,presentationOverride=null){
 const id=Number(p.id),piecePrice=cents(p.precio_publico),stock=Number(p.existencia),may=cents(p.precio_mayoreo),min=Number(p.cantidad_minima)||0;
 const mode=p.modalidad_presentacion||'PIEZA',units=Number(p.piezas_por_caja)||1,boxPrice=piecePrice*units;
 if(piecePrice<=0||stock<1)return notify('Producto sin precio o existencias POS.',true);
 if(mode==='AMBAS'&&!presentationOverride){choosePresentation(p,units,piecePrice);return;}
 const presentation=presentationOverride||mode;
 const factor=presentation==='CAJA'?units:1;
 if(factor<1||(presentation==='CAJA'&&boxPrice<=0))return notify('Configura correctamente el precio y las piezas por caja.',true);
 const base=presentation==='CAJA'?boxPrice:piecePrice;
 const rowKey=id+':'+presentation;
 const row=cart.get(rowKey)||{id,key:rowKey,presentation,factor,code:p.codigo,description:p.descripcion,base,price:base,qty:0,stock,wholesale:presentation==='PIEZA'?may:0,minQty:presentation==='PIEZA'?min:0,special:null,motivo:'',farm:Number(p.stock_farmacia)||0,bodega:Number(p.almacen_disponible)||0,minimo:Number(p.minimo_farmacia??24),conteo:!!p.conteo_configurado,tieneAlmacen:!!p.tiene_almacen,ticketId:null,ticketReady:false};
 if([...cart.values()].some(x=>x.id===id&&x.ticketId))return notify('Ya solicitaste Almacén para este producto; termina o cancela el ticket antes de añadir otra presentación.',true);
 const reserved=[...cart.values()].filter(x=>x.id===id).reduce((a,x)=>a+x.qty*x.factor,0);
 if(reserved+factor>stock)return notify('No hay existencias suficientes para agregar esta presentación.',true);
 row.qty++;normalizePrice(row);cart.set(rowKey,row);window.GYD_EFICIENCIA?.begin($('caja').value);
 $('buscar').value='';$('buscar').focus();rowCart();
 if(!$('catalog_modal').hidden)closeCatalog();
}
function openCatalog(){
 $('catalog_modal').hidden=false;document.body.classList.add('catalog-opened');
 $('catalog_q').value=$('buscar').value;
 $('catalog_all').checked=false;catalogPage=1;loadCatalog();$('catalog_q').focus();
}
function closeCatalog(){$('catalog_modal').hidden=true;document.body.classList.remove('catalog-opened');$('buscar').focus();}
async function loadCatalog(){
 const seq=++catalogSeq,q=$('catalog_q').value.trim(),priced=!$('catalog_all').checked;
 $('catalog_results').textContent='Buscando productos...';
 try{
  const r=await fetchCatalog(q,catalogPage,priced);if(seq!==catalogSeq)return;
  catalogPage=r.pagina;catalogPages=r.paginas;window.catalogProducts=r.productos;
  $('catalog_results').innerHTML=r.productos.map(productMarkup).join('')||'<p>No se encontraron productos.</p>';
  $('catalog_count').textContent=`${r.total} productos encontrados · ${priced?'Solo con precio':'Catálogo completo, incluso sin precio'}`;
  $('catalog_page').textContent=`Página ${r.pagina} de ${r.paginas}`;
  $('catalog_prev').disabled=r.pagina<=1;$('catalog_next').disabled=r.pagina>=r.paginas;
 }catch(e){if(seq===catalogSeq){$('catalog_results').textContent=e.message;$('catalog_count').textContent='No fue posible cargar el catálogo.';}}
}
function editSpecial(id){
 if(!window.POS_CONFIG.admin)return notify('Solo el administrador puede autorizar precios especiales.',true);
 const x=cart.get(String(id));if(!x)return;
 lastSpecialId=String(id);
 $('special_description').textContent=x.description+' · Público '+money(x.base)+' · Mayoreo '+(x.wholesale?money(x.wholesale):'sin configurar');
 $('special_price').value=(x.price/100).toFixed(2);$('special_reason').value=x.motivo||'';
 $('special_modal').hidden=false;$('special_price').focus();
}
function closeSpecial(){$('special_modal').hidden=true;lastSpecialId=null;}
function saveSpecial(){
 const x=cart.get(String(lastSpecialId));if(!x)return closeSpecial();
 const value=$('special_price').value.trim(),price=cents(value),reason=$('special_reason').value.trim();
 if(price<=0||value.length<1)return notify('El precio especial debe ser positivo.',true);
 if(reason.length<5)return notify('Escribe un motivo de al menos 5 caracteres.',true);
 x.special=price;x.motivo=reason;normalizePrice(x);closeSpecial();rowCart();
}
function showDialog(title,desc,action){$('tituloDialog').textContent=title;$('descripcionDialog').textContent=desc;$('montoDialog').value='0';$('modal').hidden=false;window.GYD_Corte?.preparar(action);window.GYD_CorteAutorizado?.preparar(action);$('confirmDialog').onclick=async()=>{if(busy)return;busy=true;try{if(action==='cerrar'&&!window.GYD_Corte?.valido())throw new Error('Revisa que todas las cantidades de billetes y monedas sean números enteros no negativos.');const solicitud={caja_id:Number($('caja').value),[action==='abrir'?'fondo':'contado']:$('montoDialog').value};if(action==='cerrar')solicitud.denominaciones=window.GYD_Corte.datos();if(action==='cerrar')Object.assign(solicitud,window.GYD_CorteAutorizado?.credenciales()||{});const res=await api(action,solicitud);$('modal').hidden=true;if(action==='cerrar'){notify(window.GYD_CorteAutorizado.mensaje(res));await state();window.location.href='pos_corte_retiros.php?turno_id='+encodeURIComponent(String(res.turno_id));return;}else notify('Caja abierta.');await state();}catch(e){notify(e.message,true)}finally{if(action==='cerrar')window.GYD_CorteAutorizado?.limpiar();busy=false}};}
async function charge(){
 if(busy)return;
 window.GYD_EFICIENCIA?.count('intentos_cobro');
 if(!validateDelivery()){notify('Escribe quién lleva la mercancía antes de cobrar.',true);$('quien_lleva').focus();return;}
 const statePay=updateMoney();if(!statePay.valid)return notify('Revisa importe, referencias y confirmación del cobro.',true);
 if(!cart.size)return notify('Agrega al menos un producto.',true);
 const products=new Map();
 for(const x of cart.values())if(!products.has(x.id))products.set(x.id,x);
 for(const x of products.values()){
  const origen=routePOS(x);
  if(['SIN_CONTEO','SIN_ORIGEN','INSUFICIENTE'].includes(origen))return notify('Revisa existencias de '+x.description+'.',true);
 }
 const payments=[];
 for(const [metodo,i,ref] of [['EFECTIVO','p_ef',null],['TARJETA','p_tar','ref_tar'],['TRANSFERENCIA','p_tra','ref_tra']]){
  const amount=cents($(i).value);
  if(amount>0){const p={metodo,monto:(amount/100).toFixed(2)};
   if(metodo==='EFECTIVO')p.recibido=(cents($('p_rec').value)/100).toFixed(2);
   else{p.referencia=$(ref).value.trim();p.confirmado=$('confirmacion').checked;if(metodo==='TARJETA')p.tipo_tarjeta=document.querySelector('input[name="tipo_tarjeta"]:checked')?.value||'';}
   payments.push(p);
  }
 }
 const sum=payments.reduce((a,p)=>a+cents(p.monto),0),total=cartTotal();
 if(total!==sum)return notify('Los pagos deben cubrir exactamente el total.',true);
 busy=true;$('cobrar').disabled=true;
 window.GYD_EFICIENCIA?.beforeCharge();
 try{
  const pendientes=[];
  for(const x of products.values()){
   if(routePOS(x)!=='ALMACEN')continue;
   let folio=x.ticketFolio;
   if(!x.ticketId){const t=await pedirBodega(x.key,true);folio=t.folio;}
   pendientes.push({nombre:x.description,piezas:totalPiezasProducto(x.id),folio,minimum:x.minimo,farmacia:x.farm,motivo:'Supera la cantidad vendible sin reducir la reserva de Farmacia ('+x.minimo+' piezas).'});
  }
  const items=[...cart.values()].map(x=>({id:x.id,cantidad:x.qty,presentacion:x.presentation,...(x.ticketId?{almacen_ticket_id:x.ticketId}:{}),...(x.special!==null?{precio_especial:(x.special/100).toFixed(2),motivo:x.motivo}:{})}));
  const r=await api('cobrar',{caja_id:Number($('caja').value),items,pagos:payments,key,cliente_nombre:$('cliente_nombre').value.trim(),quien_lleva:$('quien_lleva').value.trim()});
  const farmacia=[];
  for(const x of products.values())if(routePOS(x)!=='ALMACEN'){const piezas=totalPiezasProducto(x.id);const motivo=x.tieneAlmacen && piezas>Math.max(0,x.farm-x.minimo)&&piezas>x.bodega ? 'Almacén no tiene suficientes piezas; se utilizará Farmacia aunque se reduzca la reserva.' : 'La cantidad está dentro del límite de venta directa en Farmacia.';farmacia.push({nombre:x.description,piezas,motivo});}
  if(pendientes.length && farmacia.length)pendientes.push(...farmacia.map(x=>({...x,motivo:'Surtido directo desde Farmacia; no requiere solicitud a Almacén.'})));
  if(r.alertas_farmacia?.length)(pendientes.length?pendientes:farmacia).push(...r.alertas_farmacia.map(x=>({nombre:'Aviso para verificadores',piezas:0,motivo:x})));
  const gdSaleMetrics={lineas:cart.size,piezas:[...cart.values()].reduce((a,x)=>a+x.qty*x.factor,0),metodo_pago:statePay.mode};
  window.GYD_EFICIENCIA?.finish(r.venta_id,gdSaleMetrics);
  key=crypto.randomUUID();cart.clear();$('cliente_nombre').value='';$('quien_lleva').value='';rowCart();
  ['p_ef','p_tar','p_tra'].forEach(k=>$(k).value='0');$('p_rec').value='';['ref_tar','ref_tra'].forEach(k=>$(k).value='');$('confirmacion').checked=false;document.querySelectorAll('input[name="tipo_tarjeta"]').forEach(x=>x.checked=false);
  notify('Venta '+r.folio+' registrada. Ticket listo para imprimir.');await state();
  showSurtidoModal(pendientes.length?'almacen':'farmacia',pendientes.length?pendientes:farmacia,r);
  // Los avisos de bajo inventario se muestran también en la ventana central.
 }catch(e){window.GYD_EFICIENCIA?.error();notify('Venta no completada: '+e.message,true);}
 finally{busy=false;updateMoney();}
}

$('btnbuscar').onclick=()=>{window.GYD_EFICIENCIA?.count('busquedas');search();};
$('buscar').addEventListener('input',()=>{window.pendingScan='';clearTimeout(searchDebounce);searchDebounce=setTimeout(search,180);});
$('buscar').addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();window.pendingScan=$('buscar').value.trim();window.GYD_EFICIENCIA?.count('busquedas');search();}});
$('resultados').onclick=e=>{const b=e.target.closest('[data-product]');if(b){const p=(window.lastProducts||[]).find(x=>Number(x.id)===Number(b.dataset.product));if(p){add(p);window.GYD_EFICIENCIA?.count('selecciones_buscador');}}};
$('carrito').onclick=e=>{
 const del=e.target.closest('[data-del]'),spec=e.target.closest('[data-special]');
 const solicitar=e.target.closest('[data-pedir]'),verificar=e.target.closest('[data-verificar-ticket]');
 const recuperar=e.target.closest('[data-recuperar]');if(recuperar){recuperarBodega(recuperar.dataset.recuperar);return;}
 if(solicitar){if(busy)return;busy=true;pedirBodega(solicitar.dataset.pedir).catch(e=>notify(e.message,true)).finally(()=>{busy=false;updateMoney();});return;}
 if(verificar){verificarBodega(verificar.dataset.verificarTicket);return;}
 if(spec){editSpecial(spec.dataset.special);return;}
 if(del){const x=cart.get(del.dataset.del);if(x?.ticketId)return notify('Existe un ticket solicitado. Pide su cancelación a Almacén antes de quitar el producto.',true);cart.delete(del.dataset.del);window.GYD_EFICIENCIA?.count('productos_quitados');if(!cart.size)window.GYD_EFICIENCIA?.abandon();rowCart();}
};
$('carrito').addEventListener('change',e=>{
 const input=e.target.closest('[data-qty]');if(!input)return;
 const x=cart.get(input.dataset.qty);if(!x)return;
 const raw=Number(input.value);
 if(!Number.isInteger(raw)||raw<1){notify('Cantidad no válida.',true);return rowCart();}
 const reservedOthers=[...cart.values()].filter(y=>y.id===x.id&&y.key!==x.key).reduce((a,y)=>a+y.qty*y.factor,0);
 const max=Math.min(100000,Math.floor((x.stock-reservedOthers)/x.factor));
 if(raw>max)notify('Existencias insuficientes para esta presentación.',true);
 if(x.ticketId&&raw!==x.qty){notify('Ya enviaste el ticket a Almacén; solicita al verificador cancelarlo antes de cambiar la cantidad.',true);return rowCart();}
 x.qty=Math.max(1,Math.min(max,raw));window.GYD_EFICIENCIA?.count('cambios_cantidad');rowCart();
});
$('abrir_catalogo').onclick=openCatalog;
$('catalog_close').onclick=closeCatalog;
$('catalog_search').onclick=()=>{catalogPage=1;loadCatalog();};
$('catalog_q').oninput=()=>{catalogPage=1;clearTimeout(modalTimer);modalTimer=setTimeout(loadCatalog,180);};
$('catalog_all').onchange=()=>{catalogPage=1;loadCatalog();};
$('catalog_prev').onclick=()=>{if(catalogPage>1){catalogPage--;loadCatalog();}};
$('catalog_next').onclick=()=>{if(catalogPage<catalogPages){catalogPage++;loadCatalog();}};
$('catalog_results').onclick=e=>{const b=e.target.closest('[data-product]');if(!b)return;const p=(window.catalogProducts||[]).find(x=>Number(x.id)===Number(b.dataset.product));if(p){add(p);window.GYD_EFICIENCIA?.count('seleccion_catalogo');}};
$('special_cancel').onclick=closeSpecial;$('special_confirm').onclick=saveSpecial;
// Atajos globales: captura temprana para evitar que los inputs intercepten las teclas.
// Ctrl+B puede estar reservado por Chrome; F3 ofrece un acceso alternativo al catálogo.
window.addEventListener('keydown',e=>{
 if(e.altKey || e.isComposing || e.repeat) return;
 const key=e.key.toUpperCase();
 const control=(e.ctrlKey||e.metaKey);
 const catalogShortcut=(control&&key==='B')||(!control&&!e.shiftKey&&key==='F3');
 const shortcut=!control&&!e.metaKey&&!e.shiftKey&&['F2','F4','F6','F8','F9','ESCAPE'].includes(key);
 if(!catalogShortcut&&!shortcut)return;
 e.preventDefault();
 e.stopImmediatePropagation();
 const origenModal=$('pos_origen_modal');if(origenModal&&!origenModal.hidden){if(key==='ESCAPE')origenModal.hidden=true;return;}
 const catalog=$('catalog_modal'), special=$('special_modal'), dialog=$('modal');
 const catalogOpen=catalog&&!catalog.hidden;
 const specialOpen=special&&!special.hidden;
 const dialogOpen=dialog&&!dialog.hidden;
 if(key==='ESCAPE'){
   if(catalogOpen)closeCatalog();else if(specialOpen)closeSpecial();else if(dialogOpen)dialog.hidden=true;
   return;
 }
 if(catalogShortcut){if(specialOpen||dialogOpen)return;if(catalogOpen){$('catalog_q').focus();}else openCatalog();return;}
 if(catalogOpen||specialOpen||dialogOpen)return;
 if(key==='F2'){$('buscar').focus();$('buscar').select();return;}
 if(key==='F4'){$('quien_lleva').focus();$('quien_lleva').select();return;}
 if(key==='F6'){document.querySelector('input[name="pay_method"][value="efectivo"]').click();$('p_rec').focus();$('p_rec').select();return;}
 if(key==='F8'){
   if(cartTotal()<=0)return;
   document.querySelector('input[name="pay_method"][value="efectivo"]').click();
   $('p_rec').value=(cartTotal()/100).toFixed(2);window.GYD_EFICIENCIA?.pay();updateMoney();$('p_rec').focus();return;
 }
 if(key==='F9'){
   if(busy||$('cobrar').disabled)return;
   if(window.confirm('¿Confirmar el cobro de '+money(cartTotal())+' y registrar la venta?')) charge();
 }
},true);
$('auto_print').checked=localStorage.getItem('gyd_pos_auto_print')!=='0';
$('auto_print').onchange=()=>localStorage.setItem('gyd_pos_auto_print',$('auto_print').checked?'1':'0');
$('caja').onchange=()=>{window.GYD_EFICIENCIA?.abandon();cart.clear();rowCart();state();};$('abrir').onclick=()=>showDialog('Abrir caja','Captura el fondo inicial en efectivo.','abrir');$('cerrar').onclick=()=>showDialog('Cerrar caja','Cuenta el efectivo físico en el cajón.','cerrar');$('cancelDialog').onclick=()=>{window.GYD_CorteAutorizado?.limpiar();$('modal').hidden=true;};$('cobrar').onclick=charge;
document.querySelectorAll('input[name="pay_method"]').forEach(el=>el.addEventListener('change',changePaymentMode));
['p_rec','p_tar','p_tra','ref_tar','ref_tra'].forEach(id=>$(id).addEventListener('input',updateMoney));
$('confirmacion').addEventListener('change',updateMoney);
document.querySelectorAll('input[name="tipo_tarjeta"]').forEach(el=>el.addEventListener('change',updateMoney));
$('quien_lleva').addEventListener('input',updateMoney);
$('pay_quick').addEventListener('click',e=>{const b=e.target.closest('[data-cents]');if(!b)return;$('p_rec').value=(Number(b.dataset.cents)/100).toFixed(2);window.GYD_EFICIENCIA?.pay();updateMoney();});
updateMoney();init();
