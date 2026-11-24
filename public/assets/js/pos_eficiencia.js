/* G&D · Medición auxiliar de operaciones POS.
   No registra datos de clientes, números de tarjeta ni contenido de búsquedas.
   El servidor comprueba usuario, sucursal, caja, turno y venta real. */
(function(){
 'use strict';
 let s=null;
 let badge=null;
 function status(message,ok){
   const render=()=>{
     if(!badge){
       const host=document.querySelector('.headrow')||document.querySelector('main')||document.body;
       if(!host)return;
       badge=document.createElement('span');badge.id='gyd_eficiencia_estado';
       badge.style.cssText='display:inline-flex;align-items:center;max-width:100%;padding:7px 10px;border-radius:9px;border:1px solid #b9d9cd;font:600 12px system-ui;overflow-wrap:anywhere;margin:4px 0;';
       badge.setAttribute('role','status'); host.appendChild(badge);
     }
     badge.textContent='⏱ Medición: '+message;
     badge.style.background=ok?'#e6f7ef':'#fff1e8';
     badge.style.color=ok?'#14593d':'#854020';
     badge.style.borderColor=ok?'#b9d9cd':'#f1c6b4';
   };
   if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',render,{once:true});else render();
 }
 status('lista para una venta nueva',true);
 const now=()=>performance.now();
 const secs=ms=>Math.max(0,Math.round(ms/1000));
 const send=async (data)=>{
   const res=await fetch('pos_eficiencia_api.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:window.POS_CONFIG.csrf,...data})});
   const result=await res.json();if(!res.ok||!result.ok)throw Error(result.error||'Sin medición');return result;
 };
 const touch=()=>{
   if(!s)return;
   const t=now();
   if(!s.hiddenSince && s.lastTouch!==null){const gap=t-s.lastTouch;if(gap>120000)s.idleMs+=gap-120000;}
   s.lastTouch=t;
 };
 const detach=()=>{s=null;};
 function begin(caja){
   if(s)return;
   if(!Number(caja))return;
   const token=crypto.randomUUID();const t=now();
   s={token,caja_id:Number(caja),start:t,lastTouch:t,pay:null,charge:null,hiddenSince:null,hiddenMs:0,idleMs:0,warehouseMs:0,metrics:{busquedas:0,seleccion_catalogo:0,escaneos:0,selecciones_buscador:0,cambios_cantidad:0,productos_quitados:0,intentos_cobro:0,errores_cobro:0,solicitudes_almacen:0}};
   const tracked=s;
   status('iniciando ticket…',true);
   tracked.ready=send({action:'inicio',token,caja_id:tracked.caja_id})
     .then(()=>{status('cronometrando ticket',true);return true;})
     .catch(err=>{status('no se inició: '+err.message,false);console.error('G&D eficiencia inicio:',err);return false;});
 }
 function count(type){if(s&&Object.prototype.hasOwnProperty.call(s.metrics,type)){s.metrics[type]++;touch();}}
 function pay(){if(!s)return;touch();if(s.pay===null)s.pay=now();}
 function beforeCharge(){if(!s)return;touch();if(s.pay===null)s.pay=now();s.charge=now();}
 function error(){count('errores_cobro');if(s)s.charge=null;}
 async function finish(ventaId,datos){
   const m=s;if(!m)return;
   const end=now();touch();
   if(m.hiddenSince!==null){m.hiddenMs+=end-m.hiddenSince;m.hiddenSince=null;}
   detach();
   const total=secs(end-m.start),capture=secs((m.pay??m.charge??end)-m.start),payment=secs((m.charge??end)-(m.pay??m.charge??end));
   const payload={action:'fin',token:m.token,caja_id:m.caja_id,venta_id:Number(ventaId),total_seg:total,captura_seg:capture,pago_seg:payment,cobro_red_seg:secs(end-(m.charge??end)),inactivo_seg:secs(m.idleMs),oculto_seg:secs(m.hiddenMs),gestion_almacen_seg:secs(m.warehouseMs),lineas:Number(datos.lineas)||0,piezas:Number(datos.piezas)||0,metodo_pago:String(datos.metodo_pago||''),...m.metrics};
   try {
     if(await m.ready){
       await send(payload);status('ticket #'+Number(ventaId)+' registrado ('+total+' s)',true);
     }else{
       status('ticket #'+Number(ventaId)+' sin tiempo: no inició el cronómetro',false);
     }
   }catch(err){status('ticket #'+Number(ventaId)+' sin tiempo: '+err.message,false);console.error('G&D eficiencia fin:',err);}

 }
 document.addEventListener('visibilitychange',()=>{
   if(!s)return;const t=now();
   if(document.hidden){touch();s.hiddenSince=t;s.lastTouch=null;}
   else if(s.hiddenSince!==null){s.hiddenMs+=t-s.hiddenSince;s.hiddenSince=null;s.lastTouch=t;}
 });
 ['keydown','pointerdown'].forEach(evt=>document.addEventListener(evt,()=>touch(),{capture:true,passive:true}));
 document.addEventListener('input',e=>{
   if(!s||!e.target||!e.target.closest)return;
   if(e.target.closest('#p_rec,#p_tar,#p_tra,#ref_tar,#ref_tra,#cliente_nombre,#quien_lleva'))pay();
 },true);
 document.addEventListener('change',e=>{
   if(!s||!e.target||!e.target.matches)return;
   if(e.target.matches('input[name="pay_method"],input[name="tipo_tarjeta"],#confirmacion'))pay();
 },true);
 window.GYD_EFICIENCIA={begin,count,pay,beforeCharge,error,finish,touch,warehouse:start=>{if(s){s.warehouseMs+=Math.max(0,now()-start);count('solicitudes_almacen');}},abandon(){detach();}};
})();
