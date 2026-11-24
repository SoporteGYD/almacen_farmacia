(()=>{'use strict';
const $=id=>document.getElementById(id);
const form=$('crear');if(!form)return;
const itemList=[];let seleccionado=null,sugeridos=[],buscaTimer=null,solicitudActual=0;
const origen=$('origen'),destino=$('destino'),origenTipo=$('origen_tipo'),destinoTipo=$('destino_tipo');
const modalidad=form.querySelector('select[name="modalidad"]');
const codigo=$('codigo'),cantidad=$('cantidad'),sugerencias=$('tr_catalogo_sugerencias'),elegido=$('tr_producto_elegido');
const modal=$('tr_catalogo_overlay'),modalQ=$('tr_catalogo_q'),modalResultados=$('tr_catalogo_resultados');
let modalPagina=1,modalPaginas=1;
function mensaje(t,el=elegido){el.textContent=t;}
function endpoint(q,pagina){const x=new URL('traspasos_catalogo.php',location.href);x.search=new URLSearchParams({
  q, pagina:String(pagina), origen_id:origen.value, destino_id:destino.value,
  origen_tipo:origenTipo.value,modalidad:modalidad.value}).toString();return x.toString();}
async function consultar(q,pagina=1){
  const res=await fetch(endpoint(q,pagina),{credentials:'same-origin',headers:{'Accept':'application/json'},cache:'no-store'});
  let data;try{data=await res.json();}catch{throw new Error('El servidor devolvió una respuesta inválida.');}
  if(!res.ok||data.error)throw new Error(data.error||'No se pudo consultar el catálogo.');
  return data;
}
function stock(p){return p.disponible===null?'Existencia física no configurada':`${p.etiqueta}: ${p.disponible} pzas`}
function seleccionar(p){seleccionado=p;codigo.value=p.codigo;sugerencias.hidden=true;sugerencias.replaceChildren();
  mensaje(`${p.descripcion} · ${p.codigo} · ${stock(p)}`);cantidad.focus();cantidad.select();}
function el(tag,content='',cl=''){const n=document.createElement(tag);if(content)n.textContent=content;if(cl)n.className=cl;return n;}
function crearOpcion(p){const b=el('button','','tc-opcion');b.type='button';
 const info=el('span');info.append(el('b',p.descripcion),el('small',`${p.codigo}${p.codigo_barras?' · Barras: '+p.codigo_barras:''}`));
 b.append(info,el('strong',p.disponible===null?'Sin conteo':`${p.disponible} pzas`));b.onclick=()=>seleccionar(p);return b;}
function contextoValido(){if(!origen.value){mensaje('Selecciona la sucursal de origen.');return false;}
 if(modalidad.value==='SOLICITUD'&&!destino.value){mensaje('Selecciona la sucursal que solicita.');return false;}return true;}
async function sugerir(){const id=++solicitudActual,query=codigo.value.trim();
 if(!contextoValido()){sugerencias.hidden=true;return;}
 if(query.length<2){sugerencias.hidden=true;return;}
 sugerencias.hidden=false;mensaje('Buscando productos...',sugerencias);
 try{const data=await consultar(query,1);if(id!==solicitudActual)return;
  sugeridos=data.productos;sugerencias.replaceChildren();
  if(!sugeridos.length){sugerencias.append(el('div','No se encontraron productos activos.','tc-ayuda'));return;}
  sugeridos.forEach(p=>sugerencias.append(crearOpcion(p)));
  if(data.paginas>1)sugerencias.append(el('div','Hay más resultados; usa «Ver catálogo completo».','tc-ayuda'));
 }catch(err){if(id!==solicitudActual)return;sugerencias.replaceChildren(el('div',err.message,'tc-ayuda'));}}
function refrescar(){seleccionado=null;sugeridos=[];codigo.value='';sugerencias.hidden=true;mensaje('Selecciona un producto del catálogo.');}
function lista(){const tb=$('items_tbody');tb.replaceChildren();$('items_json').value=JSON.stringify(itemList.map(p=>({codigo:p.codigo,cantidad:p.cantidad})));
 if(!itemList.length){const tr=el('tr'),td=el('td','Agrega los productos a traspasar.');td.colSpan=3;tr.append(td);tb.append(tr);return;}
 itemList.forEach((p,i)=>{const tr=el('tr'),td=el('td'),cant=el('td',String(p.cantidad)),accion=el('td');
 td.append(el('b',p.codigo),el('div',p.descripcion),el('small',stock(p),'tc-ayuda'));
 const quitar=el('button','Quitar','red');quitar.type='button';quitar.onclick=()=>{itemList.splice(i,1);lista()};accion.append(quitar);tr.append(td,cant,accion);tb.append(tr);});}
async function agregar(){let p=seleccionado;const texto=codigo.value.trim();
 if(!p||p.codigo!==texto){
   if(!texto){alert('Busca y selecciona un producto del catálogo.');return;}
   try{const data=await consultar(texto,1);p=data.productos.find(x=>x.codigo.toUpperCase()===texto.toUpperCase()||x.codigo_barras.toUpperCase()===texto.toUpperCase());
    if(!p){alert('Selecciona el producto desde el catálogo.');await sugerir();return;}seleccionar(p);
   }catch(err){alert(err.message);return;}
 }
 const n=Number(cantidad.value);if(!Number.isSafeInteger(n)||n<1||n>100000){alert('Captura una cantidad válida en piezas.');return;}
 if(itemList.some(x=>x.id===p.id)){alert('Este artículo ya fue agregado. Elimínalo para cambiar su cantidad.');return;}
 if(itemList.length>=60){alert('Máximo 60 productos por traspaso.');return;}
 if(modalidad.value==='DIRECTO'&&(p.disponible===null||n>p.disponible)){
   alert(`Existencia de origen insuficiente o no configurada. ${stock(p)}. Puedes solicitar mercancía, pero no enviarla directamente sin stock.`);return;}
 itemList.push({...p,cantidad:n});lista();refrescar();cantidad.value='1';codigo.focus();}
$('agregar').addEventListener('click',agregar);
codigo.addEventListener('input',()=>{seleccionado=null;mensaje('Busca y selecciona un artículo.');clearTimeout(buscaTimer);buscaTimer=setTimeout(sugerir,220);});
codigo.addEventListener('keydown',ev=>{if(ev.key==='Escape'){sugerencias.hidden=true;return;}
 if(ev.key==='Enter'){ev.preventDefault();if(sugeridos.length===1)seleccionar(sugeridos[0]);else{
  const term=codigo.value.trim().toUpperCase();const exact=sugeridos.find(x=>x.codigo.toUpperCase()===term||x.codigo_barras.toUpperCase()===term);
  if(exact)seleccionar(exact);else agregar();}}});
function tipo(sel,t){const sid=Number(sel.value),output=$(t);output.replaceChildren();
 const choices=(sid===1||sid===2)?[['ALMACEN','Almacén'],['FARMACIA','Farmacia']]:[['UNICO','Inventario único']];
 choices.forEach(([v,label])=>{const op=el('option',label);op.value=v;output.append(op)});}
for(const [sel,t] of [[origen,'origen_tipo'],[destino,'destino_tipo']])sel.addEventListener('change',()=>{
  tipo(sel,t);if(sel===origen){itemList.length=0;lista();}refrescar();});
origenTipo.addEventListener('change',()=>{itemList.length=0;lista();refrescar()});
modalidad.addEventListener('change',()=>{itemList.length=0;lista();refrescar()});
destino.addEventListener('change',refrescar);
form.addEventListener('submit',ev=>{if(!itemList.length){ev.preventDefault();alert('Agrega al menos un producto del catálogo.');return;}
 const o=Number(origen.value),d=Number(destino.value);
 if(!o||!d||o===d||([3,4].includes(o)&&[3,4].includes(d))){ev.preventDefault();alert('Verifica el origen y destino. Tapachula y Toscana requieren configuración especial entre ambas.');}});
async function cargarModal(){if(!contextoValido())return;const numero=++solicitudActual;
 modalResultados.replaceChildren(el('p','Consultando catálogo...','tc-ayuda'));
 try{const data=await consultar(modalQ.value.trim(),modalPagina);if(numero!==solicitudActual)return;
 modalPagina=data.pagina;modalPaginas=Math.max(1,data.paginas);modalResultados.replaceChildren();
 if(!data.productos.length)modalResultados.append(el('p','No hay resultados para esta búsqueda.','tc-ayuda'));
 for(const p of data.productos){const fila=el('div','','tc-fila'),info=el('div'),btn=el('button','Seleccionar');btn.type='button';btn.onclick=()=>{seleccionar(p);cerrarModal()};
  info.append(el('b',p.descripcion),el('small',`${p.codigo} · ${p.codigo_barras||'Sin código de barras'} · ${stock(p)}`));fila.append(info,btn);modalResultados.append(fila);}
 $('tr_catalogo_pagina').textContent=`Página ${data.pagina} de ${Math.max(1,data.paginas)} · ${data.total} artículos`;
 $('tr_catalogo_atras').disabled=data.pagina<=1;$('tr_catalogo_siguiente').disabled=data.pagina>=data.paginas;
 }catch(err){modalResultados.replaceChildren(el('p',err.message,'tc-ayuda'));}}
function cerrarModal(){modal.hidden=true;codigo.focus();}
$('ver_catalogo').onclick=()=>{if(!contextoValido())return;modal.hidden=false;modalPagina=1;modalQ.value='';modalQ.focus();cargarModal();};
$('tr_catalogo_cerrar').onclick=cerrarModal;
modal.addEventListener('click',ev=>{if(ev.target===modal)cerrarModal();});
document.addEventListener('keydown',ev=>{if(ev.key==='Escape'&&!modal.hidden)cerrarModal();});
let modalTimer=null;modalQ.addEventListener('input',()=>{clearTimeout(modalTimer);modalTimer=setTimeout(()=>{modalPagina=1;cargarModal()},250)});
$('tr_catalogo_atras').onclick=()=>{if(modalPagina>1){modalPagina--;cargarModal()}};
$('tr_catalogo_siguiente').onclick=()=>{if(modalPagina<modalPaginas){modalPagina++;cargarModal()}};
lista();})();
