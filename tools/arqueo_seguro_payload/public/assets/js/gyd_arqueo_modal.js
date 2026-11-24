/* Modal para autorización secundaria; la validación de roles/contraseña está en PHP. */
(() => {
 'use strict';
 const overlay=document.getElementById('gyd-arqueo-overlay');
 const open=document.getElementById('gyd-arq-open');
 const close=document.getElementById('gyd-arq-close');
 const caja=document.getElementById('caja');
 const form=document.getElementById('gyd-arq-autorizacion');
 if(overlay && open && close && caja && form){
  let previous=null;
  const cerrar=()=>{overlay.hidden=true;document.body.classList.remove('gyd-arq-modal-open');form.reset();previous?.focus?.();};
  open.addEventListener('click',()=>{
   if(!caja.value){alert('Selecciona una caja con turno abierto.');return;}
   previous=document.activeElement;
   form.elements['caja_id'].value=caja.value;
   overlay.hidden=false;document.body.classList.add('gyd-arq-modal-open');
   form.elements['autorizador'].focus();
  });
  close.addEventListener('click',cerrar);
  overlay.addEventListener('click',e=>{if(e.target===overlay)cerrar();});
  document.addEventListener('keydown',e=>{
   if(overlay.hidden)return;
   if(e.key==='Escape'){e.preventDefault();cerrar();}
   if(e.key==='Tab'){
    const list=[...overlay.querySelectorAll('button:not([disabled]),input:not([disabled])')];
    if(list.length){const first=list[0],last=list[list.length-1];if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}}
   }
  });
 }
 // Conteo por denominaciones en vista autorizada; el servidor vuelve a calcular.
 const calc=document.getElementById('arq-total');const fields=[...document.querySelectorAll('input[data-cents]')];
 if(calc && fields.length){
  const formatter=new Intl.NumberFormat('es-MX',{style:'currency',currency:'MXN'});
  const update=()=>{
   const cents=fields.reduce((s,x)=>s+(Math.max(0,Number.parseInt(x.value,10)||0)*(Number(x.dataset.cents)||0)),0);
   calc.textContent=formatter.format(cents/100);
  };
  fields.forEach(f=>f.addEventListener('input',update));update();
 }
})();
