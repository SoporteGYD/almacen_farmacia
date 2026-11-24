'use strict';
(() => {
  const $ = id => document.getElementById(id);
  const S = { sucursales: [], clientes: [], escenarios: [], producto: null, clienteId: 0 };
  const api = 'gyd_lab_v40_api.php';
  const texto = v => String(v ?? '');
  const esc = v => texto(v).replace(/[&<>"']/g, x => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[x]));
  const pesos = n => Number(n / 100).toLocaleString('es-MX', {style:'currency',currency:'MXN'});
  const numero = n => Number(n || 0).toLocaleString('es-MX');
  const cv = n => (Number(n) / 100).toFixed(2);
  const msg = (s, err = false) => { $('mensaje').textContent = s; $('mensaje').className = 'feedback' + (err ? ' error' : ''); };
  async function get(accion, params = {}) {
    const q = new URLSearchParams({accion, ...params});
    const r = await fetch(`${api}?${q}`, {credentials:'same-origin',cache:'no-store'});
    const d = await r.json(); if (!r.ok || d.error) throw Error(d.error || 'Error de consulta'); return d;
  }
  async function post(accion, data = {}) {
    const r = await fetch(`${api}?accion=${encodeURIComponent(accion)}`, {
      method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({...data,csrf:window.GYD_LAB_V40.csrf})
    }); const d = await r.json(); if (!r.ok || d.error) throw Error(d.error || 'Error al guardar'); return d;
  }
  const sid = () => Number($('sucursal').value || 0);
  function choose(x) {
    S.producto = x; $('producto_id').value = x.producto_id;
    $('seleccion').textContent = `${x.codigo || '(sin código)'} · ${x.descripcion}`;
    const precio = x.precio_centavos ?? x.precio_real_centavos ?? x.precio_real_base ?? x.precio_centavos_actual;
    const costo = x.costo_centavos ?? x.costo_real_centavos ?? x.costo_centavos_actual;
    $('precio').value = precio == null ? '' : cv(precio);
    $('costo').value = costo == null ? '' : cv(costo);
    $('stock').value = x.stock_virtual ?? 10;
    $('puntos').value = x.puntos_requeridos ?? (x.minimo_puntos || 1);
    $('guardar').disabled = false; $('simular').disabled = !$('cliente').value;
    $('resultado').textContent = 'Pulsa "Calcular simulación" para evaluar el saldo de un cliente.';
    calcularLocal();
  }
  function evaluar(precio,costo,stock,puntos){
    const utilidad=precio-costo;
    const margen=precio>0?100*utilidad/precio:0;
    const min=Math.ceil(costo/8);
    const apto=precio>0&&costo>0&&margen>=20;
    const elegible=precio>0&&costo>0&&margen>=15;
    const puntosVenta=elegible?Math.min(Math.floor(precio/2000),Math.floor(Math.max(0,utilidad)/200)):0;
    const reserva=puntosVenta*10;
    const ok=apto&&puntos>=min&&stock>0;
    return {utilidad,margen,min,apto,puntosVenta,reserva,ok};
  }
  function calcularLocal() {
    const precio=Math.round(Number($('precio').value)*100), costo=Math.round(Number($('costo').value)*100);
    const stock=Number($('stock').value), puntos=Number($('puntos').value);
    if (![precio,costo,stock,puntos].every(Number.isFinite)||precio<=0||costo<=0){$('evaluacion').textContent='Introduce precio y costo válidos para calcular.';return;}
    const e=evaluar(precio,costo,stock,puntos);
    $('evaluacion').innerHTML = `<div class="metrics"><div><small>Margen bruto de venta</small><strong>${e.margen.toFixed(2)}%</strong></div>`+
      `<div><small>Ganancia bruta antes de puntos</small><strong>${pesos(e.utilidad)}</strong></div>`+
      `<div><small>Puntos generados en venta</small><strong>${numero(e.puntosVenta)}</strong></div>`+
      `<div><small>Reserva por venta</small><strong>${pesos(e.reserva)}</strong></div>`+
      `<div><small>Ganancia bruta tras reserva</small><strong>${pesos(e.utilidad-e.reserva)}</strong></div>`+
      `<div><small>Puntos mínimos de premio</small><strong>${numero(e.min)}</strong></div></div>`+
      `<p class="${e.ok?'good':'bad'}">${e.ok?'✓ Escenario apto para simular, si el cliente tiene puntos suficientes.':'⚠ Revisión: margen mínimo 20%, puntos suficientes para cubrir costo y stock virtual positivo.'}</p>`+
      `<p class="muted">El canje no genera otra venta. Entregar el premio cuesta ${pesos(costo)}; ${numero(puntos)} puntos representan una reserva teórica de ${pesos(puntos*10)}. La reserva no es un ingreso nuevo. Cifras brutas sin impuestos, nómina ni gastos generales.</p>`;
  }
  async function cargarInicial() {
    const d = await get('inicial'); S.sucursales=d.sucursales; S.clientes=d.clientes;
    $('sucursal').innerHTML = d.sucursales.map(x=>`<option value="${Number(x.id)}">${esc(x.nombre)}</option>`).join('');
    $('cliente').innerHTML = '<option value="">Selecciona cliente…</option>' + d.clientes.map(x=>`<option value="${Number(x.id)}">${esc(x.nombre)} · ${numero(x.puntos)} puntos</option>`).join('');
    $('clientes').innerHTML = d.clientes.length?d.clientes.map(x=>`<div class="client"><div><strong>${esc(x.nombre)}</strong><small>${esc(x.codigo)} · ${esc(x.escenario)}</small></div><span>${numero(x.puntos)} pts</span></div>`).join(''):'No hay clientes ficticios. Pulsa "Cargar ejemplos de prueba".';
    if(d.sucursales.length)await cargarEscenarios();
  }
  async function cargarEscenarios() {
    const d=await get('escenarios',{sucursal_id:sid()}); S.escenarios=d.escenarios;
    $('escenarios').innerHTML = d.escenarios.length?d.escenarios.map((x,i)=>{
      const margen=x.indicadores.margen_bruto_pct;
      return `<tr><td><strong>${esc(x.descripcion)}</strong><small>${esc(x.codigo)} · ${esc(x.escenario)}</small></td><td>${pesos(x.precio_centavos)}</td><td>${pesos(x.costo_centavos)}</td>`+
        `<td>${numero(x.stock_virtual)}</td><td class="${margen>=20?'good':'bad'}">${margen===null?'—':margen+'%'}</td>`+
        `<td><button type="button" class="tiny" data-i="${i}">Editar</button></td></tr>`;
      }).join(''):'<tr><td colspan="6">No hay escenarios para esta sucursal. Puedes buscar un producto y configurarlo.</td></tr>';
    $('escenarios').querySelectorAll('[data-i]').forEach(b=>b.addEventListener('click',()=>choose(S.escenarios[Number(b.dataset.i)])));
    $('auditoria').innerHTML = d.auditoria.length?d.auditoria.map(a=>`<div>${esc(a.creado_en)} · ${esc(a.accion)} · ${esc(a.sucursal)} · ${esc(a.producto)} · usuario ${Number(a.usuario_id)||'—'}</div>`).join(''):'Todavía no hay ajustes manuales.';
  }
  let busquedaSerial=0;
  async function buscar() {
    const q=$('buscar').value.trim(), n=++busquedaSerial;
    if(q.length<2){$('encontrados').textContent='Escribe al menos dos caracteres.';return;}
    try {
      const d=await get('buscar',{sucursal_id:sid(),q}); if(n!==busquedaSerial)return;
      const x=d.productos;
      $('encontrados').innerHTML = x.length?x.map((p,i)=>`<button type="button" class="find-item" data-i="${i}"><strong>${esc(p.descripcion)}</strong><small>${esc(p.codigo)} · Precio ERP ${p.precio_centavos==null?'no disponible':pesos(p.precio_centavos)} · Costo ERP ${p.costo_centavos==null?'no disponible':pesos(p.costo_centavos)}</small></button>`).join(''):'Sin resultados.';
      $('encontrados').querySelectorAll('[data-i]').forEach(b=>b.addEventListener('click',()=>{
        const p=x[Number(b.dataset.i)];
        choose({...p,precio_real_centavos:p.precio_centavos,costo_real_centavos:p.costo_centavos});
      }));
    } catch(e){msg(e.message,true);}
  }
  $('buscar').addEventListener('input',()=>{ clearTimeout(window._gydbusca); window._gydbusca=setTimeout(buscar,260); });
  $('sucursal').addEventListener('change',()=>{ S.producto=null;$('producto_id').value='';$('guardar').disabled=true;$('simular').disabled=true;$('seleccion').textContent='Selecciona un artículo.';cargarEscenarios().catch(e=>msg(e.message,true));if($('buscar').value.trim().length>=2)buscar(); });
  ['precio','costo','stock','puntos'].forEach(id=>$(id).addEventListener('input',calcularLocal));
  $('cliente').addEventListener('change',()=>{ $('simular').disabled=!$('producto_id').value || !$('cliente').value; });
  $('editar').addEventListener('submit',async e=>{
    e.preventDefault();if(!$('producto_id').value)return;
    $('guardar').disabled=true;
    try {await post('guardar',{sucursal_id:sid(),producto_id:Number($('producto_id').value),precio:$('precio').value,costo:$('costo').value,stock_virtual:Number($('stock').value),puntos_requeridos:Number($('puntos').value)});
      msg('Escenario virtual guardado; ningún inventario real fue modificado.'); await cargarEscenarios();
    }catch(err){msg(err.message,true);}finally{$('guardar').disabled=false;}
  });
  $('simular').addEventListener('click',async()=>{
    if(!$('producto_id').value || !$('cliente').value)return;
    try {
      const r=(await get('simular',{sucursal_id:sid(),producto_id:Number($('producto_id').value),cliente_id:Number($('cliente').value)})).simulacion;
      const a=r.articulo,i=r.indicadores;
      $('resultado').innerHTML=`<strong>${esc(r.cliente.nombre)}</strong> · ${esc(a.descripcion)}<div class="metrics">`+
        `<div><small>Puntos iniciales</small><strong>${numero(r.saldo)}</strong></div><div><small>Puntos del premio</small><strong>${numero(a.puntos_requeridos)}</strong></div>`+
        `<div><small>Puntos hipotéticos después</small><strong>${numero(i.saldo_hipotetico)}</strong></div>`+
        `<div><small>Stock virtual antes / después</small><strong>${numero(a.stock_virtual)} / ${numero(i.stock_hipotetico)}</strong></div>`+
        `<div><small>Margen bruto normal</small><strong>${i.margen_bruto_pct}%</strong></div><div><small>Margen tras reserva</small><strong>${i.margen_tras_reserva_pct}%</strong></div></div>`+
        `<p class="${i.simulacion_posible?'good':'bad'}">${esc(i.razon)}</p><p class="muted">${esc(i.aviso)}</p>`;
    } catch(err){msg(err.message,true);}
  });
  $('sembrar').addEventListener('click',async()=>{
    $('sembrar').disabled=true;
    try {const d=(await post('sembrar')).resultado; msg(`Ejemplos cargados. Clientes nuevos: ${d.clientes_nuevos}, puntos iniciales nuevos: ${d.ajustes_puntos_nuevos}, artículos virtuales nuevos: ${d.articulos_virtuales_nuevos}. Sin duplicar ni sobrescribir.`);await cargarInicial();}
    catch(e){msg(e.message,true);}finally{$('sembrar').disabled=false;}
  });
  cargarInicial().catch(e=>msg('No se pudo cargar el laboratorio: '+e.message,true));
})();
