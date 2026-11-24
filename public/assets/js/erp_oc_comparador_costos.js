/* ERP G&D — comparador por código: lee costos registrados sin modificar la OC ni la BD. */
(() => {
  'use strict';
  const form = document.getElementById('ocForm');
  const lines = document.getElementById('lineas');
  const details = document.getElementById('ocCostosComparador');
  const tbody = document.getElementById('ocCostosFilas');
  const count = document.getElementById('ocCostosCuenta');
  const state = document.getElementById('ocCostosEstado');
  const retry = document.getElementById('ocCostosReintentar');
  if (!form || !lines || !details || !tbody || !count || !state || !retry) return;
  const csrf = form.querySelector('[name="csrf"]')?.value || '';
  const cache = new Map();
  const money = n => new Intl.NumberFormat('es-MX', {
    style: 'currency', currency: 'MXN', minimumFractionDigits: 2, maximumFractionDigits: 4
  }).format(n);
  const cell = (row, text, className='') => {
    const td = document.createElement('td'); td.textContent = String(text); td.className=className;
    row.appendChild(td); return td;
  };
  let busy=false, failed=false;
  const allRows=()=>Array.from(lines.querySelectorAll('tr[data-id]'));
  function percentage(nuevo, referencia) {
    if (!(referencia > 0) || !Number.isFinite(nuevo) || nuevo < 0) return 'Sin referencia';
    const d = (nuevo - referencia) / referencia * 100;
    return `${d > 0 ? '+' : ''}${d.toFixed(1)}%`;
  }
  function render() {
    const rows=allRows();
    count.textContent=`${rows.length} artículo${rows.length===1?'':'s'}`;
    if (!rows.length) { tbody.replaceChildren(); state.textContent='Agrega productos para comparar sus costos.'; return; }
    const fragment=document.createDocumentFragment();
    for (const original of rows) {
      const id=Number(original.dataset.id);
      const info=cache.get(id);
      const nuevoRaw=original.querySelector('[name="costo[]"]')?.value ?? '';
      const nuevo=Number(nuevoRaw);
      const nuevoOK=nuevoRaw.trim()!=='' && Number.isFinite(nuevo) && nuevo>=0;
      const tr=document.createElement('tr');
      const codigo=info?.codigo || original.querySelector('td')?.textContent?.trim() || String(id);
      cell(tr,codigo);
      cell(tr,info ? (info.ultimo===null ? 'Sin dato' : money(info.ultimo)) : 'Consultando…');
      cell(tr,info ? (info.promedio===null ? 'Sin dato' : money(info.promedio)) : 'Consultando…');
      cell(tr,nuevoOK ? money(nuevo) : 'Revisar costo', 'oc-nuevo');
      for (const base of [info?.ultimo,info?.promedio]) {
        const value=info && nuevoOK && base!==null ? percentage(nuevo,base) : '—';
        const n=info && nuevoOK && base > 0 ? (nuevo-base) : 0;
        cell(tr,value,info && nuevoOK && base > 0 ? (n>0.00001?'oc-alza':n< -0.00001?'oc-baja':'oc-igual') : '');
      }
      fragment.appendChild(tr);
    }
    tbody.replaceChildren(fragment);
    state.textContent=failed?'No se pudieron actualizar algunos costos; puedes reintentar. El registro de órdenes sigue disponible.':
      busy?'Consultando costos del Catálogo Maestro…':'Referencia por código del producto. Los importes no se modifican automáticamente.';
  }
  async function fetchMissing() {
    if (busy) return;
    const ids=[...new Set(allRows().map(tr=>Number(tr.dataset.id)).filter(id=>Number.isSafeInteger(id)&&id>0))]
      .filter(id=>!cache.has(id));
    if (!ids.length) { render(); return; }
    busy=true; failed=false; retry.hidden=true; render();
    try {
      const body=new URLSearchParams(); body.set('csrf', csrf); body.set('ids',JSON.stringify(ids.slice(0,80)));
      const response=await fetch('erp_oc_costos_consultar.php',{
        method:'POST',credentials:'same-origin',body,headers:{'Accept':'application/json'}
      });
      const result=await response.json();
      if (!response.ok || !result.ok || !Array.isArray(result.items)) throw new Error(result.error||'No se obtuvo una respuesta válida');
      for (const p of result.items) {
        if (!ids.includes(Number(p.id))) continue;
        cache.set(Number(p.id),{
          codigo:String(p.codigo||''),
          ultimo:Number(p.ultimo)>0?Number(p.ultimo):null,
          promedio:Number(p.promedio)>0?Number(p.promedio):null
        });
      }
      // Si un artículo no existe o está inactivo, no inventar costos.
      for (const id of ids) if (!cache.has(id)) cache.set(id,{codigo:'Código no disponible',ultimo:null,promedio:null});
      // La advertencia de diferencia se basa únicamente en el ÚLTIMO costo real disponible.
      for (const tr of allRows()) {
        const info=cache.get(Number(tr.dataset.id));
        if (info) tr.dataset.ultimoCosto=info.ultimo === null ? '' : String(info.ultimo);
      }
      if (typeof window.gydOcRevisionEvaluar==='function' && typeof window.gydOcRevisionMostrar==='function') {
        const p=document.getElementById('ocRevisionPanel');
        if (p && !p.hidden) window.gydOcRevisionMostrar(window.gydOcRevisionEvaluar());
      }
    } catch(e) {
      failed=true; retry.hidden=false;
      state.textContent='No se pudo consultar el catálogo. Reintenta para ver los costos históricos.';
    } finally {
      busy=false; render();
      if(!failed && allRows().some(tr=>!cache.has(Number(tr.dataset.id)))) fetchMissing();
    }
  }
  let scheduled=false;
  function queue() {
    if(scheduled)return;
    scheduled=true;
    queueMicrotask(()=>{scheduled=false; render(); fetchMissing();});
  }
  new MutationObserver(queue).observe(lines,{childList:true,subtree:false});
  form.addEventListener('input',ev=>{if(ev.target?.matches?.('[name="costo[]"]'))render();});
  form.addEventListener('change',ev=>{if(ev.target?.matches?.('[name="costo[]"]'))render();});
  retry.addEventListener('click',()=>{failed=false;retry.hidden=true;fetchMissing();});
  details.addEventListener('toggle',()=>{if(details.open)queue();});
  queue();
})();
