'use strict';
(() => {
  const el = id => document.getElementById(id);
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const dinero = cents => cents === null || cents === undefined ? 'Sin dato' : '$' + (Number(cents) / 100).toLocaleString('es-MX', {minimumFractionDigits:2,maximumFractionDigits:2});
  const ent = x => Number.parseInt(String(x), 10) || 0;
  let elegido = null;
  let registros = new Map();
  let pagina = 0;
  let timer = 0;
  async function api(accion, datos, params = {}) {
    const qs = new URLSearchParams({accion,...params});
    const opt = {credentials:'same-origin',cache:'no-store'};
    if(datos) { opt.method='POST';opt.headers={'Content-Type':'application/json'};opt.body=JSON.stringify({...datos,csrf:window.GYD_REWARDS_V38.csrf}); }
    const res = await fetch('gyd_recompensas_api.php?' + qs, opt);
    const json = await res.json();
    if(!res.ok || json.error) throw Error(json.error || 'No fue posible completar la solicitud');
    return json;
  }
  function mensaje(txt,ok=false) {
    el('feedback').textContent=txt;
    el('feedback').style.color=ok?'#11613b':'#a12f26';
  }
  function vaciar() {
    elegido=null;
    el('productoActual').textContent='Elige un producto del buscador.';
    el('precio').textContent=el('costo').textContent=el('margen').textContent=el('minimo').textContent='—';
    el('elegibilidad').className='status';el('elegibilidad').textContent='Consulta un producto para evaluar la rentabilidad.';
    el('puntos').value='';el('puntos').disabled=true;el('guardar').disabled=true;
  }
  function mostrar(p, existente=null) {
    elegido=p;
    el('productoActual').textContent=`${p.descripcion} · ${p.codigo || 'Sin código'} · ${p.sucursal_nombre}`;
    el('precio').textContent=dinero(p.precio_centavos);
    el('costo').textContent=dinero(p.costo_centavos);
    el('margen').textContent=p.margen_pct===null?'—':Number(p.margen_pct).toFixed(2)+'%';
    el('minimo').textContent=p.minimo_puntos===null?'—':Number(p.minimo_puntos).toLocaleString('es-MX')+' pts';
    el('elegibilidad').textContent=p.apto? 'Apta para propuesta. Verifica que sea un artículo promocionable y que exista stock cuando se habilite el canje.' : p.razon;
    el('elegibilidad').className='status '+(p.apto?'ok':'bad');
    el('puntos').disabled=!p.apto;el('guardar').disabled=!p.apto;
    el('puntos').min=p.minimo_puntos ?? 1;
    el('puntos').value=existente?.puntos_requeridos ?? (p.minimo_puntos ?? '');
    el('estado').value=existente?.estado ?? 'BORRADOR';
    el('observaciones').value=existente?.observaciones ?? '';
  }
  async function buscar() {
    const n=++pagina,q=el('q').value.trim(),sid=ent(el('sucursal').value);
    if(q.length<2) {el('encontrados').textContent='Escribe al menos dos caracteres.';return;}
    el('encontrados').textContent='Buscando...';
    try {
      const r=await api('buscar',null,{sucursal_id:sid,q});
      if(n!==pagina)return;
      const lista=r.productos || [];
      el('encontrados').innerHTML=lista.length?lista.map(p=>`<button class="result" type="button" data-producto="${ent(p.producto_id)}"><strong>${esc(p.descripcion)}</strong><small>${esc(p.codigo)} · ${dinero(p.precio_centavos)} · Mínimo ${p.minimo_puntos ?? 'no disponible'} puntos · ${p.apto?'Apto':'No apto'}</small></button>`).join(''):'No hay coincidencias.';
      el('encontrados')._encontrados=new Map(lista.map(p=>[ent(p.producto_id),p]));
    } catch(e) {if(n===pagina)el('encontrados').textContent=e.message;}
  }
  async function cargarCatalogo() {
    try {
      const r=await api('catalogo',null,{sucursal_id:el('sucursal').value});
      registros=new Map((r.catalogo || []).map(x=>[ent(x.id),x]));
      el('registros').innerHTML=(r.catalogo || []).length?(r.catalogo || []).map(c=>{
        const status=c.estado==='ACTIVO'?(c.habilitable?'Aprobada (sin canje)':'REVISIÓN requerida'):c.estado==='PAUSADO'?'Pausada':'Borrador';
        const st=c.estado==='ACTIVO'?(c.habilitable?'ok':'bad'):'';
        return `<tr><td><strong>${esc(c.descripcion)}</strong><small>${esc(c.codigo)} · ${esc(c.sucursal_nombre)}</small></td><td>${dinero(c.precio_actual_centavos)}</td><td>${dinero(c.costo_actual_centavos)}</td><td>${ent(c.puntos_requeridos).toLocaleString('es-MX')}</td><td><span class="flag ${st}">${esc(status)}</span>${!c.habilitable&&c.estado==='ACTIVO'?'<small>Precio/costo/puntos a revisar</small>':''}</td><td><div class="actions"><button type="button" class="secondary" data-editar="${ent(c.id)}">Editar</button><button type="button" class="secondary" data-auditoria="${ent(c.id)}">Auditoría</button>${c.estado!=='PAUSADO'?`<button type="button" class="danger" data-pausar="${ent(c.id)}">Pausar</button>`:''}</div></td></tr>`;
      }).join(''):'<tr><td colspan="6">Todavía no hay productos configurados para esta sucursal.</td></tr>';
    } catch(e) {el('registros').innerHTML='<tr><td colspan="6">'+esc(e.message)+'</td></tr>';}
  }
  el('sucursal').addEventListener('change',()=>{pagina++;vaciar();el('encontrados').textContent='Escribe para buscar en esta sucursal.';cargarCatalogo();});
  el('q').addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(buscar,250);});
  el('encontrados').addEventListener('click',e=>{
    const b=e.target.closest('[data-producto]');if(!b)return;
    const p=el('encontrados')._encontrados?.get(ent(b.dataset.producto));
    if(p){mostrar(p);mensaje('');el('puntos').focus();}
  });
  el('configForm').addEventListener('submit',async e=>{
    e.preventDefault();if(!elegido)return;
    const datos={sucursal_id:ent(el('sucursal').value),producto_id:ent(elegido.producto_id),puntos_requeridos:ent(el('puntos').value),estado:el('estado').value,observaciones:el('observaciones').value,motivo:el('motivo').value};
    if(!Number.isInteger(datos.puntos_requeridos)||datos.puntos_requeridos<ent(elegido.minimo_puntos)){mensaje('Puntos insuficientes. Respeta el mínimo calculado.');return;}
    el('guardar').disabled=true;
    try {
      const r=await api('guardar',datos);
      mensaje(`Recompensa #${r.resultado.id} guardada correctamente. Canje continúa deshabilitado.`,true);
      el('motivo').value='';await cargarCatalogo();
      const x=await api('evaluar',null,{sucursal_id:datos.sucursal_id,producto_id:datos.producto_id});mostrar(x.producto,{puntos_requeridos:datos.puntos_requeridos,estado:datos.estado,observaciones:datos.observaciones});
    } catch(err) {mensaje(err.message);} finally {el('guardar').disabled=!(elegido&&elegido.apto);}
  });
  el('registros').addEventListener('click',async e=>{
    const editar=e.target.closest('[data-editar]'),auditar=e.target.closest('[data-auditoria]'),pausar=e.target.closest('[data-pausar]');
    const b=editar||auditar||pausar;if(!b)return;
    const id=ent(editar?.dataset.editar||auditar?.dataset.auditoria||pausar?.dataset.pausar),r=registros.get(id);if(!r)return;
    if(editar) {
      try{
        el('sucursal').value=String(r.sucursal_id);
        const x=await api('evaluar',null,{sucursal_id:r.sucursal_id,producto_id:r.producto_id});
        mostrar(x.producto,r);el('motivo').value='';
        el('configForm').scrollIntoView({behavior:'smooth',block:'center'});
        mensaje('Editando registro existente: captura el motivo del cambio.',true);
      }catch(err){mensaje(err.message);}
    }
    if(auditar) {
      try {
        const x=await api('auditoria',null,{id});
        el('auditoria').innerHTML=x.movimientos.length?`<table><thead><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Motivo</th></tr></thead><tbody>${x.movimientos.map(m=>`<tr><td>${esc(m.creado_en)}</td><td>#${ent(m.usuario_id)}</td><td>${esc(m.accion)}</td><td>${esc(m.motivo)}</td></tr>`).join('')}</tbody></table>`:'No hay eventos.';
        el('auditoria').scrollIntoView({behavior:'smooth',block:'center'});
      } catch(err) {mensaje(err.message);}
    }
    if(pausar) {
      const motivo=window.prompt(`Motivo para pausar "${r.descripcion}" (10 a 240 caracteres):`);
      if(motivo===null)return;
      try{await api('pausar',{id,motivo});mensaje('Recompensa pausada correctamente. No es canjeable.',true);await cargarCatalogo();}
      catch(err){mensaje(err.message);}
    }
  });
  el('refrescar').addEventListener('click',cargarCatalogo);
  (async()=>{
    try {
      const {sucursales}=await api('sucursales');
      if(!sucursales.length)throw Error('No hay sucursales activas.');
      el('sucursal').innerHTML=sucursales.map(s=>`<option value="${ent(s.id)}">${esc(s.nombre)}</option>`).join('');
      await cargarCatalogo();
    }catch(e){mensaje(e.message);el('encontrados').textContent=e.message;}
  })();
})();
