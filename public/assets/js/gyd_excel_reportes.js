/* G&D | Exportación XLSX de reportes. No modifica los registros del sistema. */
(() => {
  'use strict';
  const source = (location.pathname.split('/').pop() || '').toLowerCase();
  const baseReports = new Set(['reportes.php','agotados.php','existencias.php','inventario_virtual.php',
    'inventario_virtual_historial.php','historial_entradas.php','historial_salidas.php',
    'historial_movimientos.php','kardex.php','gyd_panel_cajas.php',
    'gyd_reportes_gerenciales.php','pos_conciliacion_turno.php',
    'gyd_clientes.php','gyd_recompensas.php']);
  if (!source.startsWith('erp_') && !baseReports.has(source)) return;
  if (document.getElementById('gyd-excel-toolbar')) return;

  const style = document.createElement('style');
  style.id = 'gyd-excel-style';
  style.textContent = `
    .gyd-excel-bar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;max-width:1200px;margin:12px auto;padding:11px 14px;border:1px solid #c5d5e4;border-radius:10px;background:#f2f7fb;color:#123b69;font:13px/1.35 system-ui,Arial,sans-serif}
    .gyd-excel-button,.gyd-excel-inline{display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:7px 12px;border:1px solid #1f7651;border-radius:7px;background:#167149;color:#fff;font:bold 13px system-ui,Arial,sans-serif;cursor:pointer;text-decoration:none!important}
    .gyd-excel-button:hover,.gyd-excel-inline:hover{background:#105b39;color:#fff}
    .gyd-excel-button:disabled,.gyd-excel-inline:disabled{opacity:.6;cursor:wait}
    .gyd-excel-note{font-size:12px;color:#41617e;flex:1 1 200px}
    .gyd-excel-inline{font-size:12px;min-height:30px;padding:5px 9px;margin-left:7px;vertical-align:middle}
    @media print{.gyd-excel-bar,.gyd-excel-inline{display:none!important}}
  `;
  document.head.appendChild(style);
  const norm = s => String(s || '').replace(/[\u00a0\u200b]+/g, ' ').replace(/[ \t]+/g, ' ').trim();
  const visible = el => {
    if (!el || el.closest('[hidden],[aria-hidden="true"]')) return false;
    if (typeof el.getClientRects === 'function' && !el.getClientRects().length) return false;
    const st = getComputedStyle(el);
    return st.display !== 'none' && st.visibility !== 'hidden';
  };
  const inReport = table => {
    if (!visible(table) || table.closest('dialog:not([open]),.modal:not(.show),[data-excel-ignore]')) return false;
    // Tablas de formularios vacíos o que contienen solo controles no son reportes.
    return Array.from(table.querySelectorAll('tr')).some(r => visible(r) && r.querySelectorAll('td,th').length > 0);
  };
  const cleanCell = cell => {
    const clone = cell.cloneNode(true);
    clone.querySelectorAll('button,input,select,textarea,svg,script,style,.no-print,[aria-hidden="true"]').forEach(e => e.remove());
    return norm(clone.textContent).slice(0,2500);
  };
  const headingFor = (table, idx) => {
    const cap = table.querySelector('caption');
    if (cap && norm(cap.textContent)) return norm(cap.textContent).slice(0,70);
    const sec = table.closest('section,article,.card,.panel,.report-card,.report-section');
    if (sec) {
      const h = sec.querySelector('h2,h3,h4');
      if (h && norm(h.textContent)) return norm(h.textContent).slice(0,70);
    }
    return `Tabla ${idx}`;
  };
  function extract(table, idx) {
    const rows = Array.from(table.querySelectorAll('tr')).filter(visible).filter(tr => tr.closest('table') === table);
    if (!rows.length) return null;
    let headRow = Array.from(table.querySelectorAll('thead tr')).filter(visible).slice(-1)[0] || null;
    if (!headRow && rows[0].querySelectorAll('th').length) headRow = rows[0];
    let heads = headRow ? Array.from(headRow.querySelectorAll('th,td')).filter(visible).map(cleanCell) : [];
    const dataRows = rows.filter(tr => tr !== headRow && !tr.closest('thead')).map(tr =>
      Array.from(tr.children).filter(el => /^(TD|TH)$/.test(el.tagName) && visible(el)).map(cleanCell)
    ).filter(r => r.some(Boolean));
    if (!dataRows.length) return null;
    const count = Math.min(70, Math.max(heads.length, ...dataRows.map(r => r.length)));
    if (!heads.length) heads = Array.from({length:count},(_,n)=>`Columna ${n+1}`);
    heads = [...heads.slice(0,count),...Array.from({length:Math.max(0,count-heads.length)},(_,n)=>`Columna ${heads.length+n+1}`)];
    return {name:headingFor(table,idx),headers:heads,rows:dataRows.map(row => row.slice(0,count))};
  }
  async function sendExcel(tables, mode, btn) {
    if (!tables.length) throw new Error('No hay datos para exportar. Aplica un filtro o consulta el reporte primero.');
    let rows = 0, cells = 0;
    for (const t of tables) {
      rows += t.rows.length;
      cells += (t.rows.length+1)*t.headers.length;
    }
    if (tables.length>20 || rows>10000 || cells>150000) throw new Error('El reporte es demasiado grande. Reduce el rango de fechas o los filtros.');
    const old = btn.textContent;
    btn.disabled = true; btn.textContent = 'Preparando Excel…';
    try {
      const response = await fetch('erp_exportar_excel.php', {
        method:'POST',credentials:'same-origin',cache:'no-store',
        headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
        body:JSON.stringify({source,title:document.title,mode,tables})
      });
      if (!response.ok) {
        const err = await response.json().catch(()=>({error:`Error HTTP ${response.status}`}));
        throw new Error(err.error || `Error HTTP ${response.status}`);
      }
      const blob = await response.blob();
      if (!blob.size) throw new Error('La descarga llegó vacía.');
      const cd = response.headers.get('content-disposition') || '';
      const match = cd.match(/filename="?([^";]+\.xlsx)"?/i);
      const filename = match ? match[1] : `GYD_Reporte_${new Date().toISOString().slice(0,10)}.xlsx`;
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');a.href=url;a.download=filename;
      document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),60000);
    } finally {btn.disabled = false;btn.textContent = old;}
  }
  const warn = e => alert('No se pudo exportar a Excel: '+(e && e.message || String(e)));
  function csvDelimiter(data) {
    const str = data.replace(/^\ufeff/,'');let quoted=false,semis=0,commas=0;
    for(let i=0;i<Math.min(str.length,10000);i++){
      const c=str[i];if(c==='"'){if(quoted&&str[i+1]==='"')i++;else quoted=!quoted;}
      else if(!quoted){if(c==='\n')break;if(c===';')semis++;else if(c===',')commas++;}
    }
    return semis>commas?';':',';
  }
  function parseCsv(data) {
    const delim=csvDelimiter(data);
    data=data.replace(/^\ufeff/,'');
    const rows=[];let col='',row=[],quoted=false;
    for(let i=0;i<data.length;i++){
      const c=data[i];
      if(c==='"'){if(quoted&&data[i+1]==='"'){col+='"';i++;}else quoted=!quoted;continue;}
      if(c===delim&&!quoted){row.push(col);col='';continue;}
      if((c==='\r'||c==='\n')&&!quoted){if(c==='\r'&&data[i+1]==='\n')i++;row.push(col);if(row.some(x=>x!==''))rows.push(row);row=[];col='';continue;}
      col+=c;
    }
    if(col!==''||row.length){row.push(col);rows.push(row);}
    if(quoted)throw new Error('El archivo CSV presenta comillas incompletas.');
    return rows;
  }
  async function exportCsv(url, btn, dataForm=null) {
    const old=btn.textContent;btn.disabled=true;btn.textContent='Consultando datos…';
    try {
      const opts={credentials:'same-origin',cache:'no-store'};
      if(dataForm){
        opts.method=dataForm.method;opts.headers={'X-Requested-With':'XMLHttpRequest'};
        if(dataForm.method==='POST'){opts.body=dataForm.body;}
        else url += (url.includes('?')?'&':'?') + dataForm.body.toString();
      }
      const r=await fetch(url,opts);
      if(!r.ok) throw new Error('El reporte CSV devolvió el error '+r.status+'. Revisa permisos o reduce los filtros.');
      const mime=(r.headers.get('content-type')||'').toLowerCase();
      if(mime.includes('text/html')) throw new Error('El servidor no entregó un CSV; revisa la sesión y los filtros.');
      const csv=await r.text();
      if(csv.length>5*1024*1024)throw new Error('El CSV es muy extenso. Reduce el periodo.');
      const rows=parseCsv(csv);
      if(rows.length<2)throw new Error('El CSV no contiene filas para exportar.');
      const headers=rows[0].map(norm);
      if(headers.length>70)throw new Error('Hay demasiadas columnas.');
      const body=rows.slice(1).map(r=>r.map(x=>String(x).slice(0,2500)));
      await sendExcel([{name:'Reporte completo',headers,rows:body}],'csv',btn);
    }finally{btn.disabled=false;btn.textContent=old;}
  }
  const isCSV = a => {
    const url=a.getAttribute('href')||'';
    const label=norm(a.textContent).toLowerCase();
    return /csv/.test(label) || /(?:[?&](?:csv|exportar|formato)=csv\b)|\.csv(?:[?#]|$)/i.test(url) || /(?:[?&]csv=[a-z]+)/i.test(url);
  };
  function enhanceCsv() {
    document.querySelectorAll('a[href]').forEach(a=>{
      if(a.dataset.gydExcelConverted || !isCSV(a) || a.closest('.gyd-excel-bar'))return;
      const url=new URL(a.href,location.href);
      if(url.origin!==location.origin)return;
      a.dataset.gydExcelConverted='1';
      const b=document.createElement('button');b.type='button';b.className='gyd-excel-inline';
      b.textContent='Excel (.xlsx)';b.title='Convertir la exportación CSV del reporte a Excel, conservando sus filtros';
      b.addEventListener('click',()=>exportCsv(url.href,b).catch(warn));a.after(b);
    });
    document.querySelectorAll('form').forEach(form=>{
      if(form.dataset.gydExcelConverted)return;
      const btn=Array.from(form.querySelectorAll('button[name],input[type="submit"][name]')).find(x=>
        /^(?:csv)$/i.test(x.value||'') && /^(?:formato|exportar|csv)$/i.test(x.name||''));
      if(!btn)return;
      if(Array.from(form.querySelectorAll('button[name],input[type="submit"][name]')).some(x=>/^(?:xlsx)$/i.test(x.value||'') && x.name===btn.name))return;
      form.dataset.gydExcelConverted='1';
      const b=document.createElement('button');b.type='button';b.className='gyd-excel-inline';b.textContent='Excel (.xlsx)';
      b.title='Convertir el reporte CSV completo a Excel usando los filtros seleccionados';
      b.addEventListener('click',()=>{
        const fd=new FormData(form);fd.set(btn.name,btn.value);
        const params=new URLSearchParams();for(const [k,v] of fd)if(typeof v==='string')params.append(k,v);
        const method=(form.method||'GET').toUpperCase();
        exportCsv(new URL(form.getAttribute('action')||location.href,location.href).href,b,{method,body:params}).catch(warn);
      });btn.after(b);
    });
  }
  async function exportGerencialCompleto(btn) {
    const urls=['sucursales','cajeros','turnos'].map(tipo => {
      const link=Array.from(document.querySelectorAll('.exports a[href]')).find(a =>
        new URL(a.href,location.href).searchParams.get('csv')===tipo);
      return link ? {name:tipo.charAt(0).toUpperCase()+tipo.slice(1),url:link.href} : null;
    });
    if(urls.some(x=>!x))throw new Error('No están disponibles los tres informes completos. Revisa los filtros.');
    const old=btn.textContent;btn.disabled=true;btn.textContent='Generando libro…';
    try {
      const tables=[];
      for(const item of urls){
        const r=await fetch(item.url,{credentials:'same-origin',cache:'no-store'});
        if(!r.ok)throw new Error(`El informe ${item.name} respondió con error ${r.status}.`);
        const type=(r.headers.get('content-type')||'').toLowerCase();
        if(type.includes('text/html'))throw new Error(`El informe ${item.name} no devolvió datos CSV.`);
        const csv=await r.text();
        if(csv.length>5*1024*1024)throw new Error(`El informe ${item.name} es demasiado extenso.`);
        const rows=parseCsv(csv);
        if(!rows.length)throw new Error(`El informe ${item.name} está vacío.`);
        tables.push({name:item.name,headers:rows[0].map(norm),rows:rows.slice(1)});
      }
      await sendExcel(tables,'csv',btn);
    }finally{btn.disabled=false;btn.textContent=old;}
  }
  function start() {
    let bar;
    function refresh() {
      enhanceCsv();
      if(source==='gyd_reportes_gerenciales.php'){
        const exports=document.querySelector('.exports');
        if(exports && !exports.querySelector('[data-gyd-excel-bulk]')){
          const full=document.createElement('button');full.type='button';full.className='gyd-excel-inline';
          full.dataset.gydExcelBulk='1';full.textContent='Excel completo (3 hojas)';
          full.addEventListener('click',()=>exportGerencialCompleto(full).catch(warn));exports.append(full);
        }
      }
      const tables=Array.from(document.querySelectorAll('table')).filter(inReport);
      const count=tables.reduce((n,t)=>n+(t.querySelectorAll('tbody tr').length||t.querySelectorAll('tr').length),0);
      if(!tables.length || count===0)return;
      if(bar)return;
      bar=document.createElement('div');bar.className='gyd-excel-bar';bar.id='gyd-excel-toolbar';
      const b=document.createElement('button');b.type='button';b.className='gyd-excel-button';b.textContent='Descargar Excel (.xlsx)';
      const note=document.createElement('span');note.className='gyd-excel-note';
      note.textContent='Vista actual: exporta las filas cargadas. Si el reporte tiene páginas adicionales, usa el CSV/Excel del módulo para obtener su alcance completo.';
      bar.append(b,note);
      b.addEventListener('click',()=>{
        const selected=Array.from(document.querySelectorAll('table')).filter(inReport).map((t,i)=>extract(t,i+1)).filter(Boolean);
        sendExcel(selected,'visible',b).catch(warn);
      });
      const main=document.querySelector('main')||document.body;
      const focus=Array.from(document.querySelectorAll('table')).find(inReport);
      if(focus && focus.closest('section,.card,.panel,.report-card')){
        const parent=focus.closest('section,.card,.panel,.report-card');parent.parentNode.insertBefore(bar,parent);
      } else main.insertBefore(bar,main.firstChild);
    }
    refresh();
    let pending=false;
    const observer=new MutationObserver(()=>{if(pending)return;pending=true;setTimeout(()=>{pending=false;refresh();},500);});
    observer.observe(document.body,{subtree:true,childList:true,attributes:true,attributeFilter:['style','class','hidden','open']});
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
