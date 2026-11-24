/* Buscador de clientes con tabla paginada y mostradores independientes por sucursal. */
(()=>{
 'use strict';
 const $=id=>document.getElementById(id);
 let selected=null,seq=0,debounce=null,page=1,listCaja=0,listSucursal=0;
 const safe=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 async function call(action,data=null){
   const q=new URLSearchParams({action});
   if(data===null)q.set('q',$('gyd_client_q').value.trim());
   const response=await fetch('gyd_clientes_api.php?'+q,{method:data===null?'GET':'POST',credentials:'same-origin',cache:'no-store',headers:data===null?{}:{'Content-Type':'application/json'},body:data===null?undefined:JSON.stringify({...data,csrf:window.POS_CONFIG.csrf})});
   let json;try{json=await response.json();}catch{throw Error('No hubo respuesta del registro de clientes.');}
   if(!response.ok||json.error)throw Error(json.error||'Error al consultar los clientes');return json;
 }
 const error=msg=>{const el=$('gyd_client_error');el.textContent=msg;el.hidden=!msg;};
 function state(){
   const isCounter=!!(selected&&selected.es_mostrador);
   $('gyd_cliente_estado').textContent=selected?(isCounter?`${selected.nombre} · venta sin acumulación de puntos`:`${selected.codigo} · ${selected.nombre} · ${Number(selected.saldo)||0} puntos acumulados`):'Venta sin cliente asociado · sin puntos';
   $('gyd_cliente_quitar').hidden=!selected;
   $('cliente_nombre').readOnly=!!selected;
 }
 function selectClient(c){
   const selSucursal=Number(c.sucursal_mostrador)||0;
   if(selSucursal && (!getSucursal() || getSucursal()!==selSucursal)){error('El cliente Venta de mostrador pertenece a otra sucursal.');return;}
   selected=c;$('cliente_nombre').value=c.nombre;state();close();
 }
 function clear(){selected=null;$('cliente_nombre').value='';state();}
 function open(tab){$('gyd_client_modal').hidden=false;switchTab(tab);setTimeout(()=>$(tab==='nuevo'?'gyd_client_nombre':'gyd_client_q').focus(),0);}
 function close(){$('gyd_client_modal').hidden=true;error('');}
 function switchTab(tab){
   const nuevo=tab==='nuevo';$('gyd_client_form').hidden=!nuevo;$('gyd_client_search_area').hidden=nuevo;
   $('gyd_client_tab_buscar').classList.toggle('active',!nuevo);$('gyd_client_tab_nuevo').classList.toggle('active',nuevo);
   error('');if(!nuevo){page=1;load();}
 }
 function getSucursal(){return listCaja===Number($('caja').value)?listSucursal:0;}
 async function load(p=page){
   const n=++seq, area=$('gyd_client_results'),cajaId=Number($('caja').value),q=$('gyd_client_q').value.trim();
   if(!cajaId){area.textContent='Primero selecciona una caja.';return;}
   area.textContent='Cargando clientes...';
   try{
     const r=await fetch('gyd_clientes_api.php?'+new URLSearchParams({action:'listar',caja_id:cajaId,q,pagina:p}),{credentials:'same-origin',cache:'no-store'});
     const data=await r.json();if(!r.ok||data.error)throw Error(data.error||'No se pudo cargar el catálogo.');if(n!==seq)return;
     page=Number(data.pagina)||1;listCaja=cajaId;listSucursal=Number(data.sucursal_id)||0;
     const clientes=data.clientes||[],map=new Map(clientes.map(c=>[Number(c.id),c]));
     const rows=clientes.map(c=>{
        const counter=!!c.es_mostrador;
        return `<tr class="${counter?'gyd-client-walkin':''}"><td data-label="Código">${safe(c.codigo)}</td><td data-label="Cliente"><strong>${safe(c.nombre)}</strong>${counter?'<small>Sin puntos · sucursal actual</small>':''}</td><td data-label="Teléfono">${safe(c.telefono||'—')}</td><td data-label="Puntos">${counter?'—':Number(c.saldo)||0}</td><td><button type="button" class="gyd-client-pick" data-cid="${Number(c.id)}">Seleccionar</button></td></tr>`;
     }).join('');
     const total=Number(data.total)||0, paginas=Number(data.paginas)||1;
     area.innerHTML=`<div class="gyd-client-table-wrap"><table class="gyd-client-table"><thead><tr><th>Código</th><th>Cliente</th><th>Teléfono</th><th>Puntos</th><th>Acción</th></tr></thead><tbody>${rows||'<tr><td colspan="5">No encontramos clientes. Prueba otro nombre, código o teléfono.</td></tr>'}</tbody></table></div><div class="gyd-client-pager"><span>${total} cliente(s) · página ${page} de ${paginas}</span><span><button type="button" data-page="${page-1}" ${page<=1?'disabled':''}>Anterior</button><button type="button" data-page="${page+1}" ${page>=paginas?'disabled':''}>Siguiente</button></span></div>`;
     area.onclick=e=>{
       const target=e.target.closest('[data-cid],[data-page]');if(!target)return;
       if(target.dataset.cid){const c=map.get(Number(target.dataset.cid));if(c)selectClient(c);}
       else if(target.dataset.page && !target.disabled){load(Number(target.dataset.page));}
     };
   }catch(e){if(n===seq){area.textContent='No fue posible cargar los clientes.';error(e.message);}}
 }
 $('gyd_cliente_buscar').addEventListener('click',()=>open('buscar'));
 $('gyd_cliente_nuevo').addEventListener('click',()=>open('nuevo'));
 $('gyd_cliente_quitar').addEventListener('click',clear);
 $('gyd_client_close').addEventListener('click',close);
 $('gyd_client_tab_buscar').addEventListener('click',()=>switchTab('buscar'));
 $('gyd_client_tab_nuevo').addEventListener('click',()=>switchTab('nuevo'));
 $('gyd_client_q').addEventListener('input',()=>{clearTimeout(debounce);debounce=setTimeout(()=>{page=1;load();},220);});
 $('caja').addEventListener('change',()=>{listCaja=0;listSucursal=0;clear();if(!$('gyd_client_modal').hidden && !$('gyd_client_search_area').hidden){page=1;load();}});
 $('gyd_client_modal').addEventListener('click',e=>{if(e.target===$('gyd_client_modal'))close();});
 document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!$('gyd_client_modal').hidden)close();});
 $('gyd_client_form').addEventListener('submit',async e=>{
   e.preventDefault();error('');const button=e.submitter||$('gyd_client_form').querySelector('button[type=submit]');button.disabled=true;
   try{const r=await call('crear',{nombre:$('gyd_client_nombre').value.trim(),telefono:$('gyd_client_telefono').value.trim(),correo:$('gyd_client_correo').value.trim(),consentimiento:$('gyd_client_consent').checked});$('gyd_client_form').reset();selectClient(r.cliente);}
   catch(ex){error(ex.message);}finally{button.disabled=false;}
 });
 window.GYD_Clientes={
   tieneSeleccion:()=>!!selected,
   cajaSucursal:getSucursal,
   vincularParaCobro:async(key,caja)=>{if(selected?.es_mostrador && Number(selected.sucursal_mostrador)!==getSucursal())throw Error('La Venta de mostrador no corresponde a esta sucursal.');await call('vincular',{key,caja_id:caja,cliente_id:selected?Number(selected.id):null});},
   ventaCompletada:result=>{if(result.recompensa && !selected?.es_mostrador)console.info('Puntos registrados',result.recompensa.puntos);clear();},
   reset:clear
 };
 state();
})();
