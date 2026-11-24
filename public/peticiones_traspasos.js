(()=>{'use strict';
const $=id=>document.getElementById(id), origen=$('origen'),destino=$('destino'),dt=$('destino_tipo'),ot=$('origen_tipo'),busca=$('producto_buscar');
if(!origen||!destino)return;
const items=[],selected={value:null};let page=1,pages=1,searchTimer=null,inlineTimer=null;
function el(tag,txt='',cls=''){const n=document.createElement(tag);if(txt)n.textContent=txt;if(cls)n.className=cls;return n;}
function context(){const o=Number(origen.value),d=Number(destino.value);if(!o||!d||o===d||(o===3||o===4)&&(d===3||d===4)){alert('Selecciona origen y destino válidos. Tapachula/Toscana comparten inventario.');return null;}return {o,d};}
function tipos(){const o=Number(origen.value),d=Number(destino.value);const fill=(s,sid)=>{s.replaceChildren();const arr=[1,2].includes(sid)?['ALMACEN','FARMACIA']:['UNICO'];arr.forEach(t=>{const option=el('option',t==='UNICO'?'Inventario único':t==='ALMACEN'?'Almacén':'Farmacia');option.value=t;s.append(option);});};fill(ot,o);fill(dt,d);selected.value=null;busca.value='';$('busqueda_estado').replaceChildren();}
[origen,destino].forEach(s=>s.addEventListener('change',tipos));tipos();
async function get(op,q='',p=1){const c=context();if(!c)throw new Error('Selecciona las sucursales primero.');
const x=new URL('peticiones_traspasos_api.php',location.href);x.search=new URLSearchParams({op,origen:String(c.o),destino:String(c.d),q,pagina:String(p)});
const res=await fetch(x.toString(),{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
let body;try{body=await res.json()}catch{throw Error('El servidor no devolvió datos válidos.');}if(!res.ok||body.error)throw Error(body.error||'Error al consultar');return body;
}
function stock(p){const s=p.origen_saldo||{},t=ot.value;return s[t]===null||s[t]===undefined?'No configurado':`${s[t]} pzas`}
function choose(p){selected.value=p;busca.value=`${p.codigo} · ${p.descripcion}`;$('busqueda_estado').replaceChildren(el('span',`${p.descripcion} · ${stock(p)}`,'muted'));$('cantidad').focus();}
function pushItem(p,cant,t=ot.value){const n=Number(cant),o=Number(origen.value);if(!Number.isSafeInteger(n)||n<1||n>100000){alert('La cantidad debe ser un número entero entre 1 y 100000.');return;}
if(!p||!p.id){alert('Selecciona primero un producto.');return;}
if(!([1,2].includes(o)?['ALMACEN','FARMACIA']:['UNICO']).includes(t)){alert('Ubicación de origen no válida.');return;}
const key=p.id+':'+t;if(items.some(x=>x.key===key)){alert('Ya agregaste este producto desde '+t+'. Puedes quitarlo y modificar la cantidad.');return;}
if(items.length>=100){alert('El límite es de 100 partidas por petición.');return;}
items.push({key,producto_id:p.id,codigo:p.codigo,descripcion:p.descripcion,origen_tipo:t,cantidad:n,stock:p.origen_saldo?.[t]??null});draw();
}
function draw(){const tbody=$('tabla_items');tbody.replaceChildren();if(!items.length){const tr=el('tr'),td=el('td','Agrega artículos desde el catálogo o las sugerencias.');td.colSpan=6;tr.append(td);tbody.append(tr);}
items.forEach((it,i)=>{const tr=el('tr'),col=[it.codigo,it.descripcion,it.origen_tipo,it.stock===null?'Sin conteo':it.stock+' pzas',String(it.cantidad)];col.forEach(v=>tr.append(el('td',v)));const td=el('td'),b=el('button','Quitar','warn');b.type='button';b.onclick=()=>{items.splice(i,1);draw()};td.append(b);tr.append(td);tbody.append(tr);});
$('items_json').value=JSON.stringify(items.map(({producto_id,origen_tipo,cantidad})=>({producto_id,origen_tipo,cantidad})));}
$('agregar').onclick=()=>{if(!selected.value){alert('Selecciona un producto real del catálogo.');return;}pushItem(selected.value,$('cantidad').value);selected.value=null;busca.value='';$('busqueda_estado').replaceChildren();busca.focus()};
async function inline(){const q=busca.value.trim(),target=$('busqueda_estado');selected.value=null;target.replaceChildren();if(q.length<2)return;target.append(el('span','Buscando...'));try{const data=await get('catalogo',q,1);if(q!==busca.value.trim())return;target.replaceChildren();if(!data.items.length){target.append(el('span','No hay coincidencias.'));return;}data.items.slice(0,8).forEach(p=>{const b=el('button',`${p.codigo} · ${p.descripcion} · ${stock(p)}`,'alt');b.type='button';b.style.margin='3px';b.onclick=()=>choose(p);target.append(b)});}
catch(e){target.replaceChildren(el('span',e.message));}}
busca.addEventListener('input',()=>{clearTimeout(inlineTimer);inlineTimer=setTimeout(inline,280)});
busca.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();if(selected.value)$('agregar').click();else inline()}});
function show(id){const node=$(id);node.hidden=false;node.querySelector('input')?.focus()}
window.cerrarModal=id=>{$(id).hidden=true;busca.focus()};
for(const mid of ['modal_catalogo','modal_sugerencias'])$(mid).addEventListener('click',e=>{if(e.target===$(mid))window.cerrarModal(mid)});
document.addEventListener('keydown',e=>{if(e.key==='Escape'){['modal_catalogo','modal_sugerencias'].forEach(mid=>$(mid).hidden=true)}});
$('abrir_catalogo').onclick=()=>{if(!context())return;show('modal_catalogo');$('modal_q').value='';page=1;loadCat()};
async function loadCat(){const box=$('modal_resultados');box.replaceChildren(el('p','Buscando en catálogo...'));
try{const data=await get('catalogo',$('modal_q').value,page);pages=data.paginas||1;box.replaceChildren();$('modal_pagina').textContent=`${data.total} productos · Página ${page} / ${pages}`;
if(!data.items.length)box.append(el('p','No se encontraron artículos.'));data.items.forEach(p=>{const line=el('div','','suggestRow'),inf=el('div');inf.append(el('b',p.descripcion),el('div',p.codigo+' · '+(p.codigo_barras||'Sin barras'),'muted'));line.append(inf,el('span',`Almacén: ${p.origen_saldo.ALMACEN??'—'}`),el('span',`Farmacia: ${p.origen_saldo.FARMACIA??'—'}`),el('span',`Único: ${p.origen_saldo.UNICO??'—'}`));const b=el('button','Seleccionar');b.onclick=()=>{choose(p);window.cerrarModal('modal_catalogo')};line.append(b);box.append(line)});
$('anterior').disabled=page<=1;$('siguiente').disabled=page>=pages;}
catch(e){box.replaceChildren(el('p',e.message))}}
$('modal_q').addEventListener('input',()=>{clearTimeout(searchTimer);searchTimer=setTimeout(()=>{page=1;loadCat()},260)});
$('anterior').onclick=()=>{if(page>1){page--;loadCat()}};$('siguiente').onclick=()=>{if(page<pages){page++;loadCat()}};
$('abrir_sugerencias').onclick=async()=>{if(!context())return;show('modal_sugerencias');const box=$('sug_resultados');box.replaceChildren(el('p','Calculando movimientos y existencias...'));try{const data=await get('sugerencias');box.replaceChildren();if(!data.items.length){box.append(el('p','No hay sugerencias con ventas y stock de los últimos 30 días. También puedes consultar el catálogo para productos sin historial.'));return;}
for(const s of data.items){const row=el('div','','suggestRow');const info=el('div');info.append(el('b',s.descripcion),el('div',s.codigo+' · '+s.vendido_30d+' pzas vendidas / 30 días','muted'));
row.append(info,el('div',`Stock destino: ${s.stock_destino}`),el('div',`Cobertura: ${s.dias_cobertura} días`),el('div',`Ya pedidas: ${s.pendiente}`));
const n=Math.min(s.sugerido,100000);row.append(el('div',`Sugerir: ${n}`));const botones=el('div');const tipos=[1,2].includes(Number(origen.value))?['ALMACEN','FARMACIA']:['UNICO'];
for(const tipo of tipos){const b=el('button',tipo==='UNICO'?'Agregar':tipo==='ALMACEN'?'Desde Almacén':'Desde Farmacia','alt');b.style.margin='3px';b.type='button';b.disabled=n<1;b.onclick=()=>{pushItem({id:s.producto_id,codigo:s.codigo,descripcion:s.descripcion,origen_saldo:s.origen},n,tipo);b.disabled=true;b.textContent='Agregado';};botones.append(b)}
row.append(botones);if(s.pendiente>0)row.append(el('div','⚠ Ya existe una petición pendiente por este artículo.','warningTag'));box.append(row)}
}catch(e){box.replaceChildren(el('p',e.message))}};
$('crear').addEventListener('submit',ev=>{if(!context()||!items.length){ev.preventDefault();alert('Selecciona las sucursales y agrega al menos un producto.');return;}
if(!confirm(`Enviar ${items.length} partidas a Compras para validación? No se descontará inventario.`))ev.preventDefault()});draw();})();
