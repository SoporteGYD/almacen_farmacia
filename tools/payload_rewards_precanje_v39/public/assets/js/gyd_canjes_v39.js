'use strict';
(()=>{
 const $=id=>document.getElementById(id);
 const escape=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 let cliente=null,recompensa=null,cotizacion=null,clave=null,cajas=[],catalogo=[];
 const mensaje=(m,ok=false)=>{ $('mensaje').textContent=m;$('mensaje').style.color=ok?'#0a773d':'#a82b35';};
 async function api(accion,params={},body){
   const url='gyd_canjes_api.php?'+new URLSearchParams({accion,...params});
   const opts={credentials:'same-origin',cache:'no-store'};
   if(body!==undefined){opts.method='POST';opts.headers={'Content-Type':'application/json'};opts.body=JSON.stringify({...body,csrf:window.GYD_CANJES_V39.csrf});}
   const res=await fetch(url,opts);const json=await res.json();
   if(!res.ok||json.error)throw new Error(json.error||'No fue posible procesar la solicitud.');
   return json;
 }
 function invalidate(){cotizacion=null;clave=null;$('solicitar').disabled=true;$('cotizacion').textContent='Selecciona un cliente y una recompensa para calcular.';}
 const caja=()=>Number($('caja').value)||0;
 async function cargarCajas(){
   const r=await api('cajas');cajas=r.cajas||[];
   $('caja').innerHTML='<option value="">Selecciona una caja</option>'+cajas.map(c=>`<option value="${Number(c.caja_id)}">${escape(c.nombre)} · ${escape(c.caja_nombre)}</option>`).join('');
   if(cajas.length===1){$('caja').value=String(cajas[0].caja_id);await cargarOfertas();}
 }
 async function buscarClientes(){
   const q=$('q').value.trim();
   if(q.length<2){$('clientes').textContent='Escribe al menos 2 caracteres.';return;}
   try{
     const r=await api('buscar_cliente',{q});
     $('clientes').innerHTML=(r.clientes||[]).length?r.clientes.map(c=>`<button type="button" class="choice" data-cliente="${Number(c.id)}"><strong>${escape(c.nombre)}</strong><small>${escape(c.codigo)} · ${escape(c.telefono)} · ${Number(c.saldo)} puntos provisionales${c.en_revision?' · DEVOLUCIÓN EN REVISIÓN':''}</small></button>`).join(''):'Sin coincidencias.';
     $('clientes')._datos=r.clientes||[];
   }catch(e){mensaje(e.message);}
 }
 function elegirCliente(id){
   cliente=($('clientes')._datos||[]).find(x=>Number(x.id)===id)||null;invalidate();
   $('seleccion').textContent=cliente?`${cliente.nombre} · ${cliente.codigo} · ${Number(cliente.saldo)} puntos provisionales${cliente.en_revision?' · EN REVISIÓN':''}`:'Ningún cliente seleccionado.';
   if(cliente && recompensa)cotizar();
 }
 async function cargarOfertas(){
   recompensa=null;invalidate();
   if(!caja()){$('ofertas').textContent='Selecciona una caja.';return;}
   try{
     const r=await api('ofertas',{caja_id:String(caja())});catalogo=r.ofertas||[];
     $('ofertas').innerHTML=catalogo.length?catalogo.map(p=>`<button type="button" class="choice" data-premio="${Number(p.id)}" ${!p.disponible?'disabled':''}><strong>${escape(p.producto)}</strong><small>${escape(p.codigo)} · ${Number(p.puntos)} puntos · Existencia: ${Number(p.existencia)} ${p.disponible?'':'· '+escape(p.advertencia)}</small></button>`).join(''):'No hay recompensas activas en esta sucursal. Administración debe aprobarlas en V38.';
     await cargarHistorial();
   }catch(e){$('ofertas').textContent='No fue posible cargar los productos.';mensaje(e.message);}
 }
 function elegirRecompensa(id){
   recompensa=catalogo.find(x=>Number(x.id)===id)||null;invalidate();
   document.querySelectorAll('[data-premio]').forEach(b=>b.classList.toggle('active',Number(b.dataset.premio)===id));
   if(cliente && recompensa)cotizar();
 }
 async function cotizar(){
   if(!caja()||!cliente||!recompensa)return;
   try{
     const r=await api('cotizar',{caja_id:String(caja()),cliente_id:String(cliente.id),catalogo_id:String(recompensa.id)});
     const x=r.cotizacion;cotizacion=x;
     $('cotizacion').className='selection '+(x.apto_para_solicitud?'ok':'warning');
     $('cotizacion').innerHTML=`<strong>${escape(x.cliente)}</strong><p>${escape(x.producto)}</p><div class="fact-grid"><div class="fact"><small>Saldo provisional</small><b>${Number(x.saldo_provisional)}</b></div><div class="fact"><small>Puntos solicitados</small><b>${Number(x.puntos_requeridos)}</b></div><div class="fact"><small>Saldo hipotético</small><b>${Number(x.saldo_hipotetico)}</b></div></div><p><b>${escape(x.razon)}</b></p><p class="bad"><strong>NO entregar producto. NO se descuentan puntos ni existencias.</strong></p>`;
     $('solicitar').disabled=!x.apto_para_solicitud;
   }catch(e){invalidate();mensaje(e.message);}
 }
 async function cargarHistorial(){
   if(!caja())return;
   try{
     const r=await api('solicitudes',{caja_id:String(caja())});
     $('historial').innerHTML=(r.solicitudes||[]).length?`<table><thead><tr><th>Solicitud</th><th>Cliente</th><th>Producto</th><th>Puntos</th><th>Estado</th><th>Acción</th></tr></thead><tbody>${r.solicitudes.map(x=>`<tr><td>#${Number(x.id)}<br><small>${escape(x.creado_en)}</small></td><td>${escape(x.cliente)}<br><small>${escape(x.cliente_codigo)}</small></td><td>${escape(x.producto)}</td><td>${Number(x.puntos_snapshot)}</td><td><span class="state">${escape(x.estado)}</span></td><td>${x.estado==='PENDIENTE'?`<button type="button" class="secondary" data-anular="${Number(x.id)}">Cancelar solicitud</button>`:'—'}</td></tr>`).join('')}</tbody></table>`:'Todavía no hay solicitudes de prueba en esta sucursal.';
   }catch(e){$('historial').textContent=e.message;mensaje(e.message);}
 }
 $('caja').addEventListener('change',()=>{recompensa=null;invalidate();cargarOfertas();});
 let timer;$('q').addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(buscarClientes,180);});
 $('clientes').addEventListener('click',e=>{const b=e.target.closest('[data-cliente]');if(b)elegirCliente(Number(b.dataset.cliente));});
 $('ofertas').addEventListener('click',e=>{const b=e.target.closest('[data-premio]');if(b)elegirRecompensa(Number(b.dataset.premio));});
 $('solicitar').addEventListener('click',async()=>{
   if(!cotizacion||!cotizacion.apto_para_solicitud)return;
   const b=$('solicitar');b.disabled=true;
   if(!clave){if(!globalThis.crypto?.randomUUID){mensaje('No se pudo generar la clave segura; usa HTTPS o localhost.');return;}clave=crypto.randomUUID();}
   try{
     const r=await api('solicitar',{}, {cliente_id:cliente.id,catalogo_id:recompensa.id,caja_id:caja(),clave});
     mensaje(`Solicitud de prueba #${r.resultado.id} registrada. NO autoriza entrega de mercancía.`,true);
     clave=null;await cargarHistorial();
     await cotizar();
   }catch(e){mensaje(e.message);b.disabled=false;}
 });
 $('historial').addEventListener('click',async e=>{
   const b=e.target.closest('[data-anular]');if(!b)return;
   const id=Number(b.dataset.anular);const motivo=prompt('Motivo de cancelación (mínimo 8 caracteres):');
   if(motivo===null)return;
   try{await api('cancelar',{}, {id,motivo});mensaje(`Solicitud #${id} cancelada; no hubo movimientos de inventario o puntos.`,true);await cargarHistorial();}catch(ex){mensaje(ex.message);}
 });
 $('recargar').addEventListener('click',cargarHistorial);
 cargarCajas().catch(e=>mensaje(e.message));
})();
