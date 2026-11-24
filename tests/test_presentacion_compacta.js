/* Prueba sin dependencias: emula los nodos usados por la revisión de OC. */
'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');

class El {
  constructor(tag='div') { this.tag=tag;this.children=[];this.events={};this.attrs={};this.dataset={};this.hidden=false;this.textContent='';this.className='';this.value=''; this.classList={add:n=>this.className+=(this.className?' ':'')+n}; }
  append(...children) {for(const child of children) this.appendChild(child);}
  appendChild(child){this.children.push(child);return child;}
  replaceChildren(...children){this.children=[];for(const child of children){if(child.tag==='fragment')this.children.push(...child.children);else this.children.push(child);}}
  setAttribute(k,v){this.attrs[k]=v;}
  removeAttribute(k){delete this.attrs[k];}
  addEventListener(event,listener){this.events[event]=listener;}
  trigger(event){assert.equal(typeof this.events[event],'function');this.events[event]();}
  scrollIntoView(){}
}
const elems={};
for (const id of ['ocForm','ocRevisionPanel','ocRevisionTitulo','ocRevisionLista','ocRevisionBtn'])elems[id]=new El();
const values={proveedor_id:'3',destino:'Tapachula',fecha:'2026-10-21',fecha_entrega:'2026-10-24', descuento_1:'0',descuento_2:'0',descuento_3:'0',descuento_4:'0',descuento_general:'0',fpgc:'0'};
const rows=Array.from({length:13},(_,i)=>{
  const el=new El('tr');el.dataset={id:String(i+1),catalogCost:'100',ivaPendiente:'true',proveedorId:'3'};
  el.querySelector=sel=>sel==='td'?{textContent:'COD'+(i+1)}:({value:{cantidad:'10',costo:i<2?'130':'100',cajas:'0',iva:'0',ieps:'0'}[sel.match(/\[name="(\w+)\[\]"\]/)?.[1]]});
  return el;
});
elems.ocForm.querySelector=selector=>({value:values[selector.match(/\[name="([^"]+)"\]/)?.[1]]});
elems.ocForm.querySelectorAll=selector=>selector==='#lineas tr[data-id]'?rows:[];
const document={
  getElementById:id=>elems[id]||null,
  createElement:tag=>new El(tag),
  createDocumentFragment:()=>new El('fragment'),
  head:new El('head')
};
const window={};
const jsPath=process.argv[2]||path.join(__dirname,'../public/assets/js/erp_oc_validacion.js');
vm.runInNewContext(fs.readFileSync(jsPath,'utf8'),{document,window,Intl,Set,Number,String,Array}, {filename:jsPath});
const review=window.gydOcRevisionEvaluar();
assert.equal(review.errors.length,0,'Debe aceptar la orden válida');
assert.equal(review.warnings.length,15,'13 avisos IVA + 2 de precio');
elems.ocRevisionBtn.trigger('click');
assert.equal(elems.ocRevisionPanel.hidden,false,'Debe mostrar un panel cuando se revisa');
const bar=elems.ocRevisionPanel.children[0];
const errorBtn=bar.children[1].children[0];
const warnBtn=bar.children[1].children[1];
const errorDetail=elems.ocRevisionPanel.children[1];
const warnDetail=elems.ocRevisionPanel.children[2];
assert.equal(errorBtn.hidden,true,'Sin errores, no muestra botón de errores');
assert.equal(warnDetail.hidden,true,'Debe mantener plegadas las advertencias');
assert.match(warnBtn.textContent,/15/);
assert.equal(elems.ocRevisionTitulo.textContent,'Orden lista para registrar');
warnBtn.trigger('click');
assert.equal(warnDetail.hidden,false,'Debe desplegar advertencias al pulsar');
assert.equal(warnDetail.children[1].children.length,15,'Debe mostrar las 15 sin truncar');
values.proveedor_id='';
elems.ocForm.trigger('input');
assert.equal(errorDetail.hidden,false,'Errores bloqueantes abiertos de inmediato');
assert.match(elems.ocRevisionTitulo.textContent,/1 error/);
assert.equal(errorDetail.children[1].children.length,1);
values.proveedor_id='3';
elems.ocForm.trigger('change');
assert.equal(errorDetail.hidden,true,'Desaparecen los errores corregidos');
assert.equal(warnDetail.hidden,false,'La preferencia del usuario sobre advertencias se conserva');
warnBtn.trigger('click');
assert.equal(warnDetail.hidden,true,'Puede volver a plegarlas');
console.log('OK: 15 advertencias plegables, detalle completo, errores separados y corrección en vivo.');
