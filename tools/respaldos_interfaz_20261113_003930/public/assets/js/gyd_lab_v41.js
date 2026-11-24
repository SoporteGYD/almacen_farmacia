'use strict';
(() => {
  const $ = id => document.getElementById(id);
  const app = { clientes:[], productos:[], seleccionado:null, historial:[], busy:false, clave:null, sucursalesCargadas:false };
  const clean = v => String(v??'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const n = v => Number(v??0).toLocaleString('es-MX');
  const pesos = cent => (Number(cent)/100).toLocaleString('es-MX',{style:'currency',currency:'MXN'});
  const api = 'gyd_lab_v41_api.php';
  const sid=()=>Number($('sucursal').value||0), cid=()=>Number($('cliente').value||0);
  const cliente=()=>app.clientes.find(c=>Number(c.id)===cid());
  const msg=(txt,bad=false)=>{$('mensaje').textContent=txt;$('mensaje').className=bad?'error':'ok';};
  async function fetchApi(accion, params){
    const url = api+'?'+new URLSearchParams({accion,...params});
    const r=await fetch(url,{credentials:'same-origin',cache:'no-store'});
    const d=await r.json();if(!r.ok||d.error)throw Error(d.error||'Error de conexión');return d;
  }
  async function send(accion, data){
    const r=await fetch(api+'?accion='+encodeURIComponent(accion),{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({...data,csrf:window.GYD_LAB_V41.csrf})});
    const d=await r.json();if(!r.ok||d.error)throw Error(d.error||'Error al guardar');return d;
  }
  function eligible(p){return !!cliente() && !!p && p.apto && Number(cliente().puntos)>=Number(p.puntos_requeridos);}
  function renderProducts(){
    const filter=$('filtrar').value.trim().toLowerCase();
    const list=app.productos.filter(x=>!filter||(String(x.descripcion).toLowerCase().includes(filter)||String(x.codigo).toLowerCase().includes(filter)));
    $('aptos').textContent=n(app.productos.filter(eligible).length);
    $('productos').innerHTML=list.length?list.map(x=>{
      const enough=cliente()&&Number(cliente().puntos)>=Number(x.puntos_requeridos);
      const ok=x.apto&&enough;
      const selected=Number(app.seleccionado?.producto_id)===Number(x.producto_id);
      return `<button type="button" data-id="${Number(x.producto_id)}" class="product ${selected?'active':''}"><span class="product-head"><b>${clean(x.descripcion)}</b><span class="tag ${ok?'green':'gray'}">${ok?'Canje disponible':(!x.apto?'Revisar':'Faltan puntos')}</span></span>`+
        `<span class="muted">${clean(x.codigo)} · ${clean(x.escenario)} · Costo: <b>${clean(x.origen_costo||'LABORATORIO')}</b>${x.costo_simulado?' (FICTICIO)':''}</span>`+
        `<span class="product-facts"><span><small>Precio normal</small><b>${pesos(x.precio_centavos)}</b></span><span><small>Costo</small><b>${pesos(x.costo_centavos)}</b></span><span><small>Margen bruto</small><b>${Number(x.margen_pct).toFixed(2)}%</b></span><span><small>Puntos</small><b>${n(x.puntos_requeridos)}</b></span><span><small>Stock virtual</small><b>${n(x.stock_virtual)}</b></span></span></button>`;
    }).join(''):'<p>No hay productos en esta sucursal. Pulsa «Habilitar otros 20 productos de prueba» o configura uno en V40.</p>';
    $('productos').querySelectorAll('[data-id]').forEach(btn=>btn.addEventListener('click',()=>{
      app.seleccionado=app.productos.find(x=>Number(x.producto_id)===Number(btn.dataset.id)); app.clave=null;renderProducts();renderCheckout();
    }));
  }
  function renderCheckout(){
    const c=cliente(),p=app.seleccionado;
    $('saldo').textContent=c?n(c.puntos):'—';
    if(!c||!p){$('seleccion').textContent='Selecciona un cliente y un producto.';$('resumen').innerHTML='';$('canjear').disabled=true;return;}
    $('seleccion').textContent=`${p.descripcion} · ${c.nombre}`;
    const sufficient=Number(c.puntos)>=Number(p.puntos_requeridos);
    const gain=(Number(p.precio_centavos)-Number(p.costo_centavos));
    $('resumen').innerHTML=`<dl><div><dt>Saldo del cliente</dt><dd>${n(c.puntos)} puntos</dd></div>`+
      `<div><dt>Costo de recompensa</dt><dd>${pesos(p.costo_centavos)}</dd></div>`+
      `<div><dt>Precio de venta normal</dt><dd>${pesos(p.precio_centavos)}</dd></div>`+
      `<div><dt>Margen bruto de venta normal</dt><dd>${Number(p.margen_pct).toFixed(2)}% (${pesos(gain)})</dd></div>`+
      `<div><dt>Puntos a descontar</dt><dd>${n(p.puntos_requeridos)}</dd></div>`+
      `<div><dt>Reserva teórica cubierta por puntos</dt><dd>${pesos(Number(p.puntos_requeridos)*10)}</dd></div>`+
      `<div><dt>Saldo después</dt><dd>${sufficient?n(Number(c.puntos)-Number(p.puntos_requeridos)):'Insuficiente'}</dd></div>`+
      `<div><dt>Stock virtual después</dt><dd>${Number(p.stock_virtual)>0?n(Number(p.stock_virtual)-1):'Agotado'}</dd></div></dl>`+
      `<p class="muted">${clean(p.advertencia_costo||'Escenario de prueba.')} El canje NO genera una venta nueva. Costo del premio ${pesos(p.costo_centavos)}; el margen indicado es sólo el de una venta normal y NO garantiza utilidad neta.</p>`+
      `<p class="${eligible(p)?'good':'bad'}">${eligible(p)?'Listo para canje virtual.':(!p.apto?clean(p.motivo):'El cliente de prueba no tiene suficientes puntos.')}</p>`;
    $('canjear').disabled=app.busy||!eligible(p);
  }
  async function loadClients(keep=true){
    const previous=keep?$('cliente').value:'';
    const data=await fetchApi('inicial');app.clientes=data.clientes;
    if(!app.sucursalesCargadas){
      $('sucursal').innerHTML=data.sucursales.map(s=>`<option value="${Number(s.id)}">${clean(s.nombre)}</option>`).join('');
      app.sucursalesCargadas=true;
    }
    $('cliente').innerHTML='<option value="">Elegir cliente de prueba…</option>'+app.clientes.map(c=>`<option value="${Number(c.id)}">${clean(c.nombre)} · ${n(c.puntos)} pts</option>`).join('');
    if(previous && app.clientes.some(c=>String(c.id)===previous))$('cliente').value=previous;
    else if(app.clientes.length)$('cliente').value=String(app.clientes[app.clientes.length-1].id);
    renderCheckout();
  }
  async function loadProducts(){
    if(!sid())return;
    const data=await fetchApi('premios',{sucursal_id:sid()});app.productos=data.premios;
    if(app.seleccionado){app.seleccionado=app.productos.find(x=>Number(x.producto_id)===Number(app.seleccionado.producto_id))||null;}
    renderProducts();renderCheckout();
  }
  async function loadHistory(){
    const data=await fetchApi('historial');app.historial=data.historial;$('entregadas').textContent=n(data.total);
    $('historial').innerHTML=app.historial.length?app.historial.map(x=>`<tr><td>${clean(x.creado_en)}<small>#${Number(x.id)} · VIRTUAL</small></td>`+
      `<td>${clean(x.sucursal)}</td><td>${clean(x.cliente)}</td><td>${clean(x.producto)}</td><td>${n(x.puntos_gastados)}</td><td>${pesos(x.costo_centavos)}</td></tr>`).join(''):'<tr><td colspan="6">Aún no has realizado canjes virtuales.</td></tr>';
  }
  async function refresh(){await loadClients();await Promise.all([loadProducts(),loadHistory()]);}
  $('sucursal').addEventListener('change',()=>{app.seleccionado=null;app.clave=null;loadProducts().catch(e=>msg(e.message,true));});
  $('cliente').addEventListener('change',()=>{app.clave=null;renderProducts();renderCheckout();});
  $('filtrar').addEventListener('input',renderProducts);
  $('agregar').addEventListener('click',async()=>{
    $('agregar').disabled=true;
    try {const r=(await send('sembrar_v43',{sucursal_id:sid()})).resultado;
      msg(`Se habilitaron ${r.agregados} productos para canjes virtuales. ${r.nota}`); await loadProducts();
    }catch(e){msg(e.message,true);}finally{$('agregar').disabled=false;}
  });
  $('canjear').addEventListener('click',async()=>{
    const p=app.seleccionado,c=cliente();if(!p||!c||!eligible(p)||app.busy)return;
    if(!confirm(`¿Confirmar canje VIRTUAL de ${p.descripcion} por ${n(p.puntos_requeridos)} puntos del cliente ${c.nombre}?\nSolo afecta laboratorio prueba:3307.`))return;
    app.busy=true;$('canjear').disabled=true;
    // Reutilizar la misma clave tras errores de red para evitar doble canje al reintentar.
    if(!app.clave)app.clave=crypto.randomUUID();
    try {
      const r=(await send('canjear_virtual',{clave:app.clave,sucursal_id:sid(),cliente_id:cid(),producto_id:Number(p.producto_id)})).resultado;
      msg(`CANJE VIRTUAL #${r.canje_id}${r.duplicado?' (ya registrado)':''}: −${n(r.puntos_descontados)} puntos. Saldo ${n(r.saldo_despues)}. Stock virtual ${n(r.stock_despues)}.`);
      app.clave=null;await refresh();
    }catch(e){msg(e.message,true);}finally{app.busy=false;renderCheckout();}
  });
  $('recargar').addEventListener('click',()=>refresh().catch(e=>msg(e.message,true)));
  refresh().catch(e=>msg('No se pudo abrir V41: '+e.message,true));
})();
