/* Aviso informativo; todo saldo y autorización se validan en PHP. */
(()=>{
 const caja=document.getElementById('caja');const head=document.querySelector('.headrow');
 if(!caja||!head)return;
 const wrap=document.createElement('div');wrap.id='gyd-retiro-alerta';wrap.style.cssText='display:none;padding:12px 16px;margin:10px 0;background:#fff0c8;border:1px solid #e0af43;border-radius:10px;color:#694411;font:600 13px system-ui;line-height:1.5';
 head.insertAdjacentElement('beforebegin',wrap);
 let last='',working=false;
 async function check(){
  if(working||document.hidden||!caja.value)return;
  working=true;
  try{
   const res=await fetch('pos_retiros_estado.php?caja_id='+encodeURIComponent(caja.value),{credentials:'same-origin',cache:'no-store'});
   if(!res.ok)throw new Error('No disponible');const d=await res.json();
   const open=!!d.abierto;const shown=open&&(!!d.alerta||!!d.pendiente);
   wrap.style.display=shown?'block':'none';
   if(shown){
    const message=d.pendiente?'Hay un retiro solicitado sin confirmar. No entregues efectivo sin confirmación.':'Efectivo en caja: $'+d.esperado+' · Límite $3,000.00 · Retiro sugerido $'+d.sugerido+' (deja $1,000.00 para cambio).';
    const url='pos_retiros.php?caja_id='+encodeURIComponent(caja.value);
    if(last!==message+'|'+url){wrap.replaceChildren();wrap.append(document.createTextNode(message+' '));const a=document.createElement('a');a.href=url;a.textContent='Abrir arqueo / retiro';a.style.cssText='font-weight:800;color:#135a9d;text-decoration:underline;margin-left:9px';wrap.append(a);last=message+'|'+url;}
   }
  }catch(_){wrap.style.display='none';}finally{working=false;}
 }
 setInterval(check,30000);caja.addEventListener('change',()=>{last='';check();});setTimeout(check,1500);
})();
