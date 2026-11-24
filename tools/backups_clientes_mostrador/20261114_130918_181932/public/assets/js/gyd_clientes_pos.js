/* G&D V36 · Buscador de clientes + inscripción + asociación segura a la venta V35. */
(()=>{
 'use strict';
 const $=id=>document.getElementById(id);
 let selected=null,seq=0,debounce=null;
 const safe=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 async function call(action,data=null){
  const q=new URLSearchParams({action});
  if(data===null){q.set('q',$('gyd_client_q').value.trim());}
  const response=await fetch('gyd_clientes_api.php?'+q.toString(),{method:data===null?'GET':'POST',credentials:'same-origin',cache:'no-store',headers:data===null?{}:{'Content-Type':'application/json'},body:data===null?undefined:JSON.stringify({...data,csrf:window.POS_CONFIG.csrf})});
  let json;try{json=await response.json();}catch{throw new Error('No hay respuesta del módulo Clientes. Verifica que esté instalado el control de clientes.');}
  if(!response.ok||json.error)throw new Error(json.error||'Error de clientes');return json;
 }
 const err=message=>{const x=$('gyd_client_error');x.textContent=message;x.hidden=!message;};
 function currentState(){
  $('gyd_cliente_estado').textContent=selected?`${selected.codigo} · ${selected.nombre} · ${Number(selected.saldo)||0} puntos acumulados (canje aún no disponible)`:'Venta sin cliente asociado · sin puntos';
  $('gyd_cliente_quitar').hidden=!selected;
  $('cliente_nombre').readOnly=!!selected;
 }
 function selectClient(c){selected=c;$('cliente_nombre').value=c.nombre;currentState();close();}
 function clear(){selected=null;$('cliente_nombre').value='';currentState();}
 function open(tab){$('gyd_client_modal').hidden=false;switchTab(tab);$('gyd_client_error').hidden=true;setTimeout(()=>$(tab==='nuevo'?'gyd_client_nombre':'gyd_client_q').focus(),0);}
 function close(){$('gyd_client_modal').hidden=true;err('');}
 function switchTab(tab){const nuevo=tab==='nuevo';$('gyd_client_form').hidden=!nuevo;$('gyd_client_search_area').hidden=nuevo;$('gyd_client_tab_buscar').classList.toggle('active',!nuevo);$('gyd_client_tab_nuevo').classList.toggle('active',nuevo);err('');}
 async function search(){const n=++seq,q=$('gyd_client_q').value.trim();if(q.length<2){$('gyd_client_results').textContent='Escribe al menos 2 caracteres para buscar.';return;}
  $('gyd_client_results').textContent='Buscando clientes...';
  try{const r=await call('buscar');if(n!==seq)return;
   const clientes=r.clientes||[];
   $('gyd_client_results').innerHTML=clientes.length?clientes.map(c=>`<button type="button" class="gyd-client-choice" data-cid="${Number(c.id)}"><strong>${safe(c.nombre)}</strong><span>${safe(c.codigo)} · ${safe(c.telefono||'Sin teléfono')} · <b>${Number(c.saldo)||0} puntos</b></span></button>`).join(''):'No hay coincidencias. Puedes registrar a este cliente.';
   const map=new Map(clientes.map(c=>[Number(c.id),c]));
   $('gyd_client_results').onclick=e=>{const b=e.target.closest('[data-cid]');if(b&&map.has(Number(b.dataset.cid)))selectClient(map.get(Number(b.dataset.cid)));};
  }catch(e){if(n===seq)err(e.message);}
 }
 $('gyd_cliente_buscar').addEventListener('click',()=>open('buscar'));
 $('gyd_cliente_nuevo').addEventListener('click',()=>open('nuevo'));
 $('gyd_cliente_quitar').addEventListener('click',()=>clear());
 $('gyd_client_close').addEventListener('click',close);
 $('gyd_client_tab_buscar').addEventListener('click',()=>switchTab('buscar'));
 $('gyd_client_tab_nuevo').addEventListener('click',()=>switchTab('nuevo'));
 $('gyd_client_q').addEventListener('input',()=>{clearTimeout(debounce);debounce=setTimeout(search,200);});
 $('gyd_client_modal').addEventListener('click',e=>{if(e.target===$('gyd_client_modal'))close();});
 document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!$('gyd_client_modal').hidden)close();});
 $('gyd_client_form').addEventListener('submit',async e=>{
  e.preventDefault();err('');const button=e.submitter||$('gyd_client_form').querySelector('button[type=submit]');
  button.disabled=true;
  try{const r=await call('crear',{nombre:$('gyd_client_nombre').value.trim(),telefono:$('gyd_client_telefono').value.trim(),correo:$('gyd_client_correo').value.trim(),consentimiento:$('gyd_client_consent').checked});$('gyd_client_form').reset();selectClient(r.cliente);}
  catch(ex){err(ex.message);}finally{button.disabled=false;}
 });
 window.GYD_Clientes={
  tieneSeleccion:()=>!!selected,
  // Llamar antes del cobro. El modelo verifica UUID+caja+sesión y otorga una sola vez.
  vincularParaCobro:async(key,caja)=>{await call('vincular',{key,caja_id:caja,cliente_id:selected?Number(selected.id):null});},
  ventaCompletada:result=>{if(result.recompensa){const r=result.recompensa;console.info('Cliente G&D: puntos registrados en la venta',r.puntos);}clear();},
  reset:clear
 };
 currentState();
})();
