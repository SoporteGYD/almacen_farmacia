/* G&D - Wizard de Peticiones de Traspaso. Solo presentación: no altera API ni inventarios. */
(()=>{'use strict';
const $=id=>document.getElementById(id);
const form=$('crear');
if(!form || $('ptw_root'))return; // No modifica la bandeja de Compras ni el detalle de peticiones.
const origen=$('origen'), destino=$('destino'), destinoTipo=$('destino_tipo');
const list=$('tabla_items'), json=$('items_json');
const fields=Array.from(form.children).filter(e=>e.classList?.contains('fields'));
const ruta=fields.find(e=>e.querySelector('#origen')&&e.querySelector('#destino'));
const productos=fields.find(e=>e.querySelector('#producto_buscar')&&e.querySelector('#cantidad'));
const barraCatalogo=Array.from(form.children).find(e=>e.querySelector?.('#abrir_catalogo')&&e.querySelector?.('#abrir_sugerencias'));
const tabla=list?.closest('.scroll');
const observaciones=form.querySelector('textarea[name="observaciones"]');
const obsEtiqueta=Array.from(form.children).find(e=>e.tagName==='LABEL'&&e.textContent.includes('Observaciones'));
const enviar=Array.from(form.children).find(e=>e.classList?.contains('bar')&&e.querySelector('button.green')&&e.querySelector('span.muted'));
const encabezadoProductos=Array.from(form.children).find(e=>e.tagName==='H3'&&e.textContent.includes('Agregar productos'));
if(!ruta||!productos||!barraCatalogo||!tabla||!observaciones||!obsEtiqueta||!enviar||!origen||!destino||!destinoTipo||!json)return;
const node=(tag,cl,text)=>{const n=document.createElement(tag);if(cl)n.className=cl;if(text!==undefined)n.textContent=text;return n};
const root=node('div','ptw','');root.id='ptw_root';
const intro=node('div','ptw-hero');intro.append(node('div','ptw-eyebrow','SOLICITUD DE MERCANCÍA'),node('h3','','Captura guiada de traspasos'),node('p','','Completa cada paso; Compras recibirá la petición y validará las cantidades antes del surtido.'));
const progress=node('div','ptw-progress');progress.setAttribute('aria-label','Progreso de la solicitud');
const steps=[
 {title:'Sucursales y ruta',short:'Ruta',subtitle:'¿Quién necesita mercancía y quién la enviará?'},
 {title:'Productos y cantidades',short:'Productos',subtitle:'Busca en el catálogo, indica cantidades y consulta sugerencias.'},
 {title:'Revisión y envío',short:'Confirmar',subtitle:'Revisa el documento antes de enviarlo al área de Compras.'}
];
const buttons=steps.map((s,i)=>{const b=node('button','ptw-progress-step');b.type='button';b.dataset.target=String(i+1);b.append(node('span','ptw-progress-number',String(i+1)),node('span','ptw-progress-text',s.short));progress.append(b);return b;});
const msg=node('div','ptw-message');msg.id='ptw_message';msg.setAttribute('role','alert');msg.hidden=true;
const panels=steps.map((s,i)=>{const panel=node('section','ptw-panel');panel.dataset.step=String(i+1);panel.id='ptw-step-'+(i+1);panel.setAttribute('aria-labelledby','ptw-title-'+(i+1));const heading=node('div','ptw-panel-heading');const title=node('h3','',s.title);title.id='ptw-title-'+(i+1);heading.append(title,node('p','',s.subtitle));panel.append(heading);return panel;});
// Mover campos ORIGINALES, sin duplicar inputs ni sus validaciones/eventos.
panels[0].append(node('div','ptw-tip','Selecciona primero la sucursal solicitante, después la sucursal que surtirá. Para Tapachula y Toscana se mantiene el inventario compartido.'),ruta);
const routePreview=node('div','ptw-route-preview','Selecciona las sucursales para continuar');panels[0].append(routePreview);
if(encabezadoProductos)panels[1].append(encabezadoProductos);
panels[1].append(productos,barraCatalogo,tabla);
const count=node('div','ptw-count','0 productos agregados');panels[1].append(count);
const help=node('p','ptw-small','Atajo: Ctrl + B abre el catálogo. Puedes capturar productos de Almacén y Farmacia dentro de una sola petición.');panels[1].append(help);
const resume=node('div','ptw-summary');resume.id='ptw_resumen';panels[2].append(resume,obsEtiqueta,observaciones,node('div','ptw-review-note','Las existencias son referenciales. No se descontará mercancía al enviar la petición; Compras validará y Almacén confirmará posteriormente el despacho.'),enviar);
const actions1=node('div','ptw-actions');const next1=node('button','ptw-next','Continuar a productos →');next1.type='button';actions1.append(next1);panels[0].append(actions1);
const actions2=node('div','ptw-actions');const prev2=node('button','ptw-back','← Regresar a sucursales');prev2.type='button';const next2=node('button','ptw-next','Revisar petición →');next2.type='button';actions2.append(prev2,next2);panels[1].append(actions2);
const actions3=node('div','ptw-actions ptw-review-actions');const prev3=node('button','ptw-back','← Modificar productos');prev3.type='button';actions3.append(prev3);panels[2].append(actions3);
root.append(intro,progress,msg,...panels);form.append(root);
let step=1,visited=1;
const prevRoute={origen:origen.value,destino:destino.value};
function dataItems(){try{const data=JSON.parse(json.value||'[]');return Array.isArray(data)?data:[]}catch{return []}}
function clearMessage(){msg.hidden=true;msg.textContent=''}
function warn(text){msg.textContent=text;msg.hidden=false;msg.scrollIntoView({block:'nearest',behavior:'smooth'});}
function textOption(sel){return sel.selectedOptions?.[0]?.textContent?.trim()||'Sin definir'}
function validRoute(){const o=Number(origen.value),d=Number(destino.value);if(!o||!d){warn('Selecciona la sucursal solicitante y la que surtirá los productos.');return false;}
if(o===d||([3,4].includes(o)&&[3,4].includes(d))){warn('Origen y destino deben ser diferentes. Tapachula y Toscana comparten inventario y no pueden traspasarse entre sí desde este módulo.');return false;}
if(!destinoTipo.value){warn('Selecciona la ubicación física de recepción.');return false;}return true;}
function validItems(){const items=dataItems();if(!items.length){warn('Agrega al menos un artículo desde el catálogo o la ventana de sugerencias.');return false;}
if(items.length>100){warn('Solo se permiten hasta 100 partidas por petición.');return false;}return true;}
function updateRoute(){const o=origen.value,d=destino.value;routePreview.textContent=o&&d?`${textOption(destino)} solicita a ${textOption(origen)} · Recibe en ${textOption(destinoTipo)}`:'Selecciona las sucursales para continuar';}
function addCell(tr,text,cls){const td=node('td',cls||'',String(text));tr.append(td);}
function renderSummary(){const items=dataItems();resume.replaceChildren();
const banner=node('div','ptw-sum-route');banner.append(node('strong','',textOption(destino)+'  ←  '+textOption(origen)),node('span','','Destino físico: '+textOption(destinoTipo)));resume.append(banner);
const set=new Map(items.map(item=>[String(item.producto_id)+':'+String(item.origen_tipo),item]));
const trItems=Array.from(list.querySelectorAll('tr')).filter(tr=>tr.querySelectorAll('td').length>=5);
const table=node('table','ptw-table');const head=node('thead');const row=node('tr');['Código','Producto','Origen físico','Piezas'].forEach(h=>{const th=node('th','',h);row.append(th)});head.append(row);table.append(head);
const body=node('tbody');let total=0;const groups={ALMACEN:0,FARMACIA:0,UNICO:0};
items.forEach((item,i)=>{const n=Number(item.cantidad)||0;total+=n;groups[item.origen_tipo]=(groups[item.origen_tipo]||0)+n;const tr=node('tr');const display=trItems[i]?.querySelectorAll('td')||[];
const code=(display[0]?.textContent||String(item.producto_id)).trim(),description=(display[1]?.textContent||'Producto del catálogo').trim();
addCell(tr,code);addCell(tr,description);addCell(tr,item.origen_tipo||'—');addCell(tr,n.toLocaleString('es-MX'),'ptw-num');body.append(tr)});
if(!items.length){const tr=node('tr');const td=node('td','','Sin productos');td.colSpan=4;tr.append(td);body.append(tr)}table.append(body);
const wrap=node('div','ptw-table-wrap');wrap.append(table);resume.append(wrap);
const totals=node('div','ptw-total-bar');totals.append(node('b','',`${items.length} partidas · ${total.toLocaleString('es-MX')} piezas`));
const summary=Object.entries(groups).filter(([,n])=>n>0).map(([k,v])=>`${k==='UNICO'?'Inventario único':k}: ${v.toLocaleString('es-MX')}`).join(' · ');
totals.append(node('span','',summary||'Aún sin partidas'));resume.append(totals);
}
function goto(n){if(n>step){if(step===1&&!validRoute())return;if(step===2&&!validItems())return;}
step=n;visited=Math.max(visited,n);clearMessage();panels.forEach((p,i)=>{p.hidden=(i+1)!==step});buttons.forEach((b,i)=>{const k=i+1;b.classList.toggle('is-current',k===step);b.classList.toggle('is-done',k<step);b.disabled=k>visited;b.setAttribute('aria-current',k===step?'step':'false');});
updateRoute();if(step===2){$('producto_buscar')?.focus({preventScroll:true});}if(step===3)renderSummary();
root.scrollIntoView({behavior:'smooth',block:'start'});}
// El cambio de origen/destino con partidas cargadas podría mezclar existencias de dos sucursales.
// No se borra silenciosamente el pedido: se exige quitar primero esas partidas.
for(const sel of [origen,destino])sel.addEventListener('change',e=>{if(dataItems().length){sel.value=prevRoute[sel.id]||'';e.stopImmediatePropagation();warn('Para cambiar las sucursales, regresa a Productos y quita primero las partidas. Evitamos mezclarlas entre distintos inventarios.');return;}
prevRoute[sel.id]=sel.value;},true);
[origen,destino,destinoTipo].forEach(sel=>sel.addEventListener('change',updateRoute));
next1.onclick=()=>goto(2);next2.onclick=()=>goto(3);prev2.onclick=()=>goto(1);prev3.onclick=()=>goto(2);
buttons.forEach(b=>b.onclick=()=>{const to=Number(b.dataset.target);if(to<=visited)goto(to)});
// En cualquier paso previo, la tecla Enter no debe enviar accidentalmente la petición.
form.addEventListener('submit',e=>{if(step!==3){e.preventDefault();e.stopImmediatePropagation();warn('Antes de enviar, completa los pasos y revisa el resumen.');return;}
if(!validRoute()||!validItems()){e.preventDefault();e.stopImmediatePropagation();}},true);
// Ayudas de teclado: respeta el buscador y nunca activa el envío.
document.addEventListener('keydown',e=>{if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='b'&&step===2){e.preventDefault();$('abrir_catalogo')?.click();}});
const observer=new MutationObserver(()=>{const items=dataItems();count.textContent=items.length?`${items.length} partida${items.length===1?'':'s'} agregada${items.length===1?'':'s'} · Puedes seguir agregando productos`:'Agrega al menos un producto para continuar';if(step===3)renderSummary();});observer.observe(list,{childList:true,subtree:true});
goto(1);
})();
