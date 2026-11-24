/* G&D · extensión aditiva: pendientes, cotizaciones y repetir venta. */
(()=>{
'use strict';
const base='pos_api.php';
const el=id=>document.getElementById(id);
const enc=s=>String(s??'').replace(/[&<>"']/g,x=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[x]));
let documents=[];let isLoading=false;
function aviso(t,error=false){ if(typeof notify==='function')notify(t,error);else window.alert(t); }
async function request(action,data=null,params={}){
 const q=new URLSearchParams({action,caja_id:el('caja').value,...params});
 const resp=await fetch(base+'?'+q.toString(),{
   method:data?'POST':'GET',credentials:'same-origin',cache:'no-store',
   headers:data?{'Content-Type':'application/json'}:{},
   body:data?JSON.stringify({...data,caja_id:Number(el('caja').value),csrf:window.POS_CONFIG.csrf}):undefined
 });
 const content=await resp.text();let obj;
 try{obj=JSON.parse(content)}catch{throw new Error('Respuesta inesperada del servidor. Revisa la sesión y el instalador.');}
 if(!resp.ok||obj.error)throw new Error(obj.error||'Error de operación.');
 return obj;
}
function modal(){return el('gd_doc_modal');}
function show(){modal().hidden=false;document.body.style.overflow='hidden';refresh();}
function hide(){modal().hidden=true;document.body.style.overflow='';}
function disableSubmit(flag){const b=el('gd_doc_save');if(b)b.disabled=flag;}
async function refresh(){
 const box=el('gd_doc_list');box.textContent='Buscando documentos...';
 try{
   const r=await request('documentos_listar');documents=r.documentos||[];
   box.innerHTML=documents.length?documents.map(d=>`<div class="gd-doc-row"><div><strong>${enc(d.titulo)}</strong><small>${d.tipo==='COTIZACION'?'Cotización':'Venta pendiente'} · ${enc(d.actualizado_en)}</small></div><button type="button" data-doc-open="${Number(d.id)}">Recuperar</button><button type="button" class="gd-doc-del" data-doc-delete="${Number(d.id)}" aria-label="Eliminar documento">Eliminar</button></div>`).join(''):'<p class="gd-doc-muted">Todavía no hay pendientes ni cotizaciones de esta caja y usuario.</p>';
 }catch(e){box.textContent=e.message;}
}
function snapshot(){
 if(typeof cart==='undefined'||!(cart instanceof Map))throw new Error('El carrito POS no está disponible en esta versión.');
 const rows=[...cart.values()];
 if(!rows.length)throw new Error('Agrega al menos un producto.');
 if(rows.some(x=>x.ticketId))throw new Error('No puedes guardar productos con tickets de Almacén ya solicitados. Finaliza o cancela esa solicitud primero.');
 if(rows.some(x=>x.special!==null))throw new Error('No guardamos precios especiales en pendientes: retira el precio especial antes de guardar.');
 return rows.map(x=>({id:Number(x.id),codigo:String(x.code??''),presentacion:x.presentation,cantidad:Number(x.qty)}));
}
async function save(){
 if(isLoading)return;
 try{
  const items=snapshot(),name=el('gd_doc_title').value.trim();
  const tipo=el('gd_doc_kind').value;
  if(!name)throw Error('Escribe un nombre identificador para el documento.');
  isLoading=true;disableSubmit(true);
  const r=await request('documentos_guardar',{
   tipo,titulo:name,items,
   cliente_nombre:el('cliente_nombre')?.value||'',quien_lleva:el('quien_lleva')?.value||''
  });
  aviso((tipo==='COTIZACION'?'Cotización':'Pendiente')+' guardada (#'+r.id+'). Sin afectar inventario.');
  await refresh();
  // No se vacía el carrito automáticamente: el operador decide qué hacer con él.
 }catch(e){aviso(e.message,true);}finally{isLoading=false;disableSubmit(false);}
}
async function prepare(items){
 if(!Array.isArray(items)||items.length<1||items.length>150)throw Error('Partidas no válidas.');
 if(typeof fetchCatalog!=='function'||typeof add!=='function'||typeof rowCart!=='function')throw Error('Esta instalación del POS tiene una estructura de carrito no reconocida.');
 const pre=[];const requested=new Map();
 for(const line of items){
  const qty=Number(line.cantidad),id=Number(line.id),pres=String(line.presentacion||'').toUpperCase();
  if(!Number.isInteger(qty)||qty<1||qty>100000||id<=0||!['CAJA','PIEZA'].includes(pres))throw Error('Cantidad o presentación inválida.');
  const code=String(line.codigo||'').trim();
  if(!code)throw Error('El producto #'+id+' no tiene código para consultar el catálogo actual.');
  const r=await fetchCatalog(code,1,false);
  const p=(r.productos||[]).find(x=>Number(x.id)===id);
  if(!p)throw Error('No se encontró el producto '+code+' en el catálogo de esta caja.');
  const available=Math.max(0,Number(p.existencia)||0),price=Number(p.precio_publico);
  const units=Number(p.piezas_por_caja)||1;
  const factor=pres==='CAJA'?units:1;
  const mode=String(p.modalidad_presentacion||'PIEZA').toUpperCase();
  if(pres==='CAJA'&&!['CAJA','AMBAS'].includes(mode))throw Error('El producto '+code+' ya no se vende por caja.');
  if(!Number.isFinite(price)||price<=0)throw Error('El producto '+code+' no tiene precio vigente.');
  if(!Number.isInteger(factor)||factor<1)throw Error('Presentación no válida de '+code+'.');
  const curr=(requested.get(id)||0)+qty*factor;requested.set(id,curr);
  if(curr>available)throw Error('Existencias insuficientes para '+code+' (solicitadas '+curr+', disponibles '+available+').');
  pre.push({p,pres,qty});
 }
 return pre;
}
async function restore(doc){
 if(isLoading)return;
 if(typeof busy!=='undefined'&&busy) return aviso('Termina la operación en curso antes de recuperar.',true);
 if(typeof cart==='undefined'||!(cart instanceof Map))return aviso('No se reconoce el carrito de esta versión.',true);
 if([...cart.values()].some(x=>x.ticketId))return aviso('Hay solicitudes activas de Almacén en el carrito. Termina o cancela esas solicitudes antes de reemplazarlo.',true);
 if(cart.size && !window.confirm('Se reemplazará el carrito actual. ¿Quieres continuar?'))return;
 isLoading=true;
 const previous=new Map(cart);const prevCustomer=el('cliente_nombre').value,prevDelivery=el('quien_lleva').value;let started=false;
 try{
  const scope=String(el('caja').value);
  const validated=await prepare(doc.items);
  if(scope!==String(el('caja').value))throw Error('Cambió la caja durante la carga, inténtalo nuevamente.');
  started=true;cart.clear();
  for(const it of validated){
    add(it.p,it.pres);
    const line=cart.get(Number(it.p.id)+':'+it.pres);
    if(!line)throw Error('No se pudo añadir '+String(it.p.codigo||it.p.id));
    line.qty=it.qty;
  }
  el('cliente_nombre').value=String(doc.cliente_nombre||'');
  el('quien_lleva').value=String(doc.quien_lleva||'');
  rowCart();hide();
  aviso('Artículos recuperados. Revisa precios, origen y pagos antes de cobrar.');
 }catch(e){if(started){cart.clear();for(const [k,v] of previous)cart.set(k,v);el('cliente_nombre').value=prevCustomer;el('quien_lleva').value=prevDelivery;rowCart();}aviso(e.message,true);}finally{isLoading=false;}
}
async function openDoc(id){
 if(isLoading)return;
 try{const r=await request('documentos_detalle',null,{documento_id:String(id)});await restore(r.documento);}
 catch(e){aviso(e.message,true);}
}
async function repeat(id){
 if(isLoading)return;
 try{const r=await request('documentos_repetir',null,{venta_id:String(id)});await restore(r.documento);}
 catch(e){aviso(e.message,true);}
}
async function removeDoc(id){
 if(!window.confirm('¿Eliminar esta cotización o pendiente? No se alterará el inventario.'))return;
 try{await request('documentos_eliminar',{documento_id:id});await refresh();aviso('Documento eliminado.');}
 catch(e){aviso(e.message,true);}
}
function injectRepeat(){
 const table=el('historial');if(!table)return;
 for(const row of table.querySelectorAll('tr')){
  const link=row.querySelector('a[href*="pos_ticket.php?id="]');if(!link||row.querySelector('.gd-repeat'))continue;
  const id=new URL(link.href,location.href).searchParams.get('id');
  if(!id||!/^[1-9]\d*$/.test(id))continue;
  const cell=link.closest('td');if(!cell)continue;
  const btn=document.createElement('button');btn.type='button';btn.className='gd-repeat';btn.textContent='Repetir';
  btn.addEventListener('click',()=>repeat(Number(id)));cell.append(' ',btn);
 }
}
function init(){
 if(!el('carrito')||!el('caja')||!el('historial'))return;
 const wrap=document.createElement('div');wrap.className='gd-doc-tools';
 wrap.innerHTML='<button type="button" id="gd_doc_open" class="gd-doc-primary">📁 Pendientes y cotizaciones</button><span>Guarda sin cobrar · Recupera · Repite tickets anteriores</span>';
 const anchor=document.querySelector('.sales-panel .find');if(anchor)anchor.after(wrap);else document.querySelector('.sales-panel')?.prepend(wrap);
 const dlg=document.createElement('div');dlg.id='gd_doc_modal';dlg.hidden=true;dlg.className='gd-doc-backdrop';
 dlg.innerHTML=`<div class="gd-doc-sheet" role="dialog" aria-modal="true" aria-labelledby="gd_doc_heading">
  <div class="gd-doc-head"><h2 id="gd_doc_heading">Pendientes y cotizaciones</h2><button type="button" id="gd_doc_close" aria-label="Cerrar">×</button></div>
  <p class="gd-doc-muted">Guardar una venta pendiente o cotización no crea ticket, cobro ni movimiento de stock.</p>
  <div class="gd-doc-fields"><label>Tipo<select id="gd_doc_kind"><option value="PENDIENTE">Venta pendiente</option><option value="COTIZACION">Cotización</option></select></label><label>Identificación<input id="gd_doc_title" maxlength="120" placeholder="Ej. Cliente mostrador / pedido pendiente"></label></div>
  <button type="button" id="gd_doc_save" class="gd-doc-primary">Guardar carrito actual</button>
  <h3>Mis documentos guardados</h3><div id="gd_doc_list" class="gd-doc-list" aria-live="polite"></div>
  <p class="gd-doc-muted">Al recuperar, el sistema revisa productos, precio vigente, presentación y existencias. Un precio anterior no queda garantizado.</p>
 </div>`;
 document.body.append(dlg);
 el('gd_doc_open').onclick=show;el('gd_doc_close').onclick=hide;el('gd_doc_save').onclick=save;
 dlg.addEventListener('click',e=>{if(e.target===dlg)hide();const op=e.target.closest('[data-doc-open]'),del=e.target.closest('[data-doc-delete]');if(op)openDoc(Number(op.dataset.docOpen));if(del)removeDoc(Number(del.dataset.docDelete));});
 document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!dlg.hidden){e.stopImmediatePropagation();e.preventDefault();hide();}},true);
 const history=el('historial');new MutationObserver(injectRepeat).observe(history,{childList:true,subtree:true});injectRepeat();
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
