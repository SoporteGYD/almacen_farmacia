'use strict';
(() => {
 const root=document.getElementById('app');if(!root)return;
 const get=(id)=>document.getElementById(id),list=get('alerts'),state=get('estado');
 const csrf=root.dataset.csrf,api='erp_alertas_gerenciales_api.php';
 const filters=()=>new URLSearchParams({sucursal:get('sucursal').value,dias:get('dias').value});
 const text=(tag,value,cls='')=>{const e=document.createElement(tag);e.textContent=value;if(cls)e.className=cls;return e;};
 const msg=(value,isError=false)=>{get('message').textContent=value;get('message').classList.toggle('error',isError);};
 let busy=false;
 const show=()=>{let num=0;list.querySelectorAll('.alert').forEach(e=>{let yes=state.value==='todas'||e.dataset.estado===state.value;e.hidden=!yes;if(yes)num++;});get('empty').hidden=num>0;};
 const render=(data)=>{
  get('countPending').textContent=String(data.pendientes);get('countDone').textContent=String(data.atendidas);get('countTotal').textContent=String(data.alertas.length);get('stamp').textContent=data.consultado_en;
  get('warnings').replaceChildren(...data.avisos.map(x=>text('div',x,'warning')));
  const fragment=document.createDocumentFragment();
  for(const item of data.alertas){
   const article=document.createElement('article');article.className='alert'+(item.atendida?' done':'');article.dataset.estado=item.atendida?'atendidas':'pendientes';
   const head=text('div','','alert-head');head.append(text('span',item.nivel.toUpperCase(),'severity '+item.nivel),text('span',item.sucursal,'scope'),text('time',item.fecha));article.append(head,text('h3',item.titulo),text('p',item.detalle));
   const foot=text('div','','alert-footer');foot.append(text('span',item.atendida?'Atendida por '+item.atendida_por+' · '+item.atendida_en:(item.atendible?'Pendiente de revisión':'Se resuelve con el retiro correspondiente')));
   const actions=text('div','');
   if(item.url){const a=text('a','Ir al módulo','view');a.href=item.url;actions.append(a);}
   if(!item.atendida&&item.atendible){const b=text('button','Marcar atendida','ack');b.type='button';b.dataset.clave=item.clave;actions.append(b);}
   foot.append(actions);article.append(foot);fragment.append(article);
  }
  list.replaceChildren(fragment);show();
 };
 async function refresh(quiet=false){if(busy||document.hidden)return;busy=true;try{
  const r=await fetch(api+'?'+filters().toString(),{credentials:'same-origin',headers:{'Accept':'application/json'},cache:'no-store'});const d=await r.json();if(!r.ok||!d.ok)throw new Error(d.mensaje||'Error al consultar');render(d.data);if(!quiet)msg('Alertas actualizadas.');
 }catch(e){msg(e.message||'No se pudo actualizar.',true);}finally{busy=false;}}
 list.addEventListener('click',async(ev)=>{const b=ev.target.closest('button.ack');if(!b)return;if(!confirm('¿Marcar esta alerta como atendida? El movimiento de caja no cambiará.'))return;
  b.disabled=true;try{const form=filters();form.set('accion','atender');form.set('csrf',csrf);form.set('clave',b.dataset.clave);
   const r=await fetch(api,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body:form.toString()});const d=await r.json();if(!r.ok||!d.ok)throw new Error(d.mensaje||'No pudo atenderse.');await refresh(true);msg('Alerta marcada como atendida.');
  }catch(e){msg(e.message||'No se pudo guardar.',true);b.disabled=false;}
 });
 get('refresh').addEventListener('click',()=>refresh());get('print').addEventListener('click',()=>window.print());state.addEventListener('change',show);show();
 setInterval(()=>refresh(true),60000);
})();
