/* G&D · Retroalimentación compartida V5. No afirma éxito sin confirmación del servidor. */
(function(){'use strict';
 if(window.GYDFeedback)return;
 const st=document.createElement('style');st.textContent=`
 .gyd-modal-overlay{position:fixed;inset:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;background:rgba(13,30,53,.63);padding:20px}
 .gyd-modal-card{width:min(480px,100%);background:#fff;color:#163c63;padding:28px;border-radius:16px;box-shadow:0 16px 70px rgba(0,0,0,.32);font:15px system-ui,-apple-system,Segoe UI,sans-serif}
 .gyd-modal-top{display:flex;align-items:center;gap:12px;margin-bottom:14px}
 .gyd-modal-symbol{font-size:24px;width:48px;height:48px;display:grid;place-items:center;background:#f2f6fc;border-radius:100%}
 .gyd-modal-heading{font-size:19px;font-weight:750;color:#163c63}
 .gyd-modal-msg{line-height:1.6;white-space:pre-wrap;overflow-wrap:anywhere}
 .gyd-modal-actions{margin-top:22px;display:flex;justify-content:flex-end}
 .gyd-modal-button{background:#155a99;color:white;border:0;border-radius:9px;padding:11px 23px;font-size:14px;font-weight:650;cursor:pointer}
 .gyd-modal-button:focus-visible{outline:3px solid #70b6ff;outline-offset:3px}
 @media print {.gyd-modal-overlay{display:none!important}}
 `;
 (document.head||document.documentElement).appendChild(st);
 const queue=[];let showing=false;
 function show(opts){opts=opts||{};return new Promise(resolve=>{queue.push([opts,resolve]);run();});}
 function run(){if(showing||!queue.length)return;showing=true;
  const [o,resolve]=queue.shift(),type=o.type||'info';
  const wrap=document.createElement('div');wrap.className='gyd-modal-overlay';wrap.setAttribute('role','presentation');
  const card=document.createElement('div');card.className='gyd-modal-card';card.setAttribute('role','alertdialog');card.setAttribute('aria-modal','true');card.setAttribute('aria-labelledby','gyd-modal-title');card.setAttribute('aria-describedby','gyd-modal-content');
  const top=document.createElement('div');top.className='gyd-modal-top';
  const sym=document.createElement('div');sym.className='gyd-modal-symbol';sym.textContent=type==='success'?'✓':type==='error'?'!':type==='warning'?'!':'i';
  const title=document.createElement('div');title.className='gyd-modal-heading';title.id='gyd-modal-title';title.textContent=o.title||(type==='success'?'Operación realizada':type==='error'?'No se pudo completar':'Aviso');
  top.append(sym,title);const msg=document.createElement('div');msg.className='gyd-modal-msg';msg.id='gyd-modal-content';msg.textContent=String(o.message||'');
  const actions=document.createElement('div');actions.className='gyd-modal-actions';const b=document.createElement('button');b.type='button';b.className='gyd-modal-button';b.textContent=o.buttonText||'Entendido';actions.append(b);
  card.append(top,msg,actions);wrap.append(card);document.body.append(wrap);const prev=document.activeElement; b.focus();
  const finish=()=>{wrap.remove();showing=false;if(prev&&prev.focus)try{prev.focus()}catch(e){}resolve(true);run()};
  b.addEventListener('click',finish,{once:true});wrap.addEventListener('keydown',e=>{if(e.key==='Escape'||e.key==='Enter'){e.preventDefault();finish()}});
 }
 window.GYDFeedback={show:show,success:(m)=>show({type:'success',message:m}),error:(m)=>show({type:'error',message:m})};
 // Reemplaza alertas nativas ya emitidas por los módulos (no intercepta confirmaciones).
 const nativeAlert=window.alert.bind(window);
 window.alert=function(msg){if(!document.body){nativeAlert(msg);return;}show({type:'info',message:String(msg)});};
 // Validaciones comunes del navegador: mostrar modal, pero NO asumir que un POST fue exitoso.
 document.addEventListener('invalid',function(e){const input=e.target;if(!input||!input.form)return;const form=input.form;
  if(form.dataset.gydInvalidNotice==='1')return;form.dataset.gydInvalidNotice='1';
  window.setTimeout(()=>{form.dataset.gydInvalidNotice='0'},150);
  const name=input.labels&&input.labels.length?input.labels[0].textContent.trim():(input.placeholder||input.name||'el campo');
  show({type:'warning',title:'Revisa los datos',message:'Corrige '+name+': '+(input.validationMessage||'valor no válido')});
 },true);
 // Servidor explicita éxito/error: la página debe incluir data-gyd-modal-type y texto.
 document.addEventListener('DOMContentLoaded',function(){
   document.querySelectorAll('[data-gyd-modal-type],.alert-success,.alert-danger,.alert-error').forEach(el=>{if(el.id==='gyd-retiro-error')return;
     const cls=el.className||'';const type=el.getAttribute('data-gyd-modal-type')||(String(cls).includes('success')?'success':'error');if(!['success','error','warning'].includes(type))return;
     show({type,title:el.getAttribute('data-gyd-modal-title')||undefined,message:el.textContent.trim()});
   });
 });
})();
