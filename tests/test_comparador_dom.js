const assert=require('node:assert/strict');
const fs=require('node:fs'); const vm=require('node:vm');
function elt(tag='div'){
  return {tag, textContent:'',className:'',children:[], dataset:{},hidden:false,
    appendChild(x){this.children.push(x);},
    replaceChildren(...xs){this.children=xs.flatMap(x=>x?.fragment?x.children:[x]);},
    addEventListener(event,fn){(this.handlers ||= {})[event]=fn;}
  };
}
const form=elt('form'),lineas=elt('tbody'),details=elt('details'),tbody=elt('tbody'),count=elt('span'),state=elt('p'),retry=elt('button');
form.querySelector=selector => selector==='[name="csrf"]'?{value:'test-csrf'}:null;
const tr=elt('tr');tr.dataset.id='91';tr.querySelector=selector=>selector==='[name="costo[]"]'?{value:tr.currentCost}:selector==='td'?{textContent:'650240007651'}:null;tr.currentCost='76.69';
lineas.querySelectorAll=()=>[tr];
const byId={ocForm:form,lineas,ocCostosComparador:details,ocCostosFilas:tbody,ocCostosCuenta:count,ocCostosEstado:state,ocCostosReintentar:retry,ocRevisionPanel:{hidden:true}};
let called=false;
const context={
 document:{getElementById:id=>byId[id]||null,createDocumentFragment(){const obj=elt('frag');obj.fragment=true;return obj;},createElement:elt},
 window:{},URLSearchParams,Intl,Map,Set,Number,String,Array,JSON,console,
 queueMicrotask,
 MutationObserver:class{observe(){}},
 fetch:async(_url,opts)=>{called=true;assert.equal(opts.method,'POST');assert.equal(opts.body.get('csrf'),'test-csrf');assert.deepEqual(JSON.parse(opts.body.get('ids')),[91]);return {ok:true,json:async()=>({ok:true,items:[{id:91,codigo:'650240007651',ultimo:55.8,promedio:60}]})};}
};
vm.runInNewContext(fs.readFileSync(process.argv[2],'utf8'),context);
setTimeout(()=>{
 assert.equal(called,true);
 assert.equal(count.textContent,'1 artículo');
 assert.equal(tr.dataset.ultimoCosto,'55.8');
 assert.equal(tbody.children.length,1);
 assert.equal(tbody.children[0].children[0].textContent,'650240007651');
 assert.equal(tbody.children[0].children[4].textContent,'+37.4%');
 assert.equal(tbody.children[0].children[5].textContent,'+27.8%');
 tr.currentCost='45';form.handlers.input({target:{matches:sel=>sel==='[name="costo[]"]'}});
 assert.equal(tbody.children[0].children[4].textContent,'-19.4%');
 assert.equal(tbody.children[0].children[5].textContent,'-25.0%');
 console.log('OK: carga por ID/código, históricos, nuevo costo editable, variaciones positivas y negativas.');
},20);
