(()=>{'use strict';
 const $=id=>document.getElementById(id);
 let resolucion=null,operacion='',caja=0,enviando=false;
 function cerrar(){ $('gyd-modal-acceso').hidden=true; $('gyd-acceso-clave').value=''; $('gyd-acceso-usuario').value=''; $('gyd-acceso-error').hidden=true; }
 function cancelar(){
    if(!resolucion||enviando)return;
    const resolver=resolucion;resolucion=null;cerrar();resolver(null);
 }
 function abrir(tipo,cajaId){
   if(!['retiro','corte'].includes(tipo)||!(cajaId>0))return Promise.reject(new Error('Selecciona una caja válida.'));
   if(resolucion)return Promise.reject(new Error('Ya existe una verificación en curso.'));
   operacion=tipo;caja=cajaId;
   $('gyd-modal-acceso-titulo').textContent=tipo==='retiro'?'Confirmar retiro de efectivo':'Autorizar acceso al corte';
   $('gyd-modal-acceso-texto').textContent=tipo==='retiro'?'Confirma la contraseña del cajero del turno antes de entrar al módulo.':'La autorización se solicita antes de mostrar el conteo. El corte finalizará y cerrará el turno.';
   const pedirUsuario=tipo==='corte'&&!window.POS_CONFIG?.admin;
   const grupo=$('gyd-acceso-usuario-grupo');grupo.hidden=!pedirUsuario;
   grupo.style.display=pedirUsuario?'block':'none';
   $('gyd-acceso-usuario').required=pedirUsuario;
   $('gyd-acceso-usuario').value='';$('gyd-acceso-clave').value='';
   $('gyd-acceso-error').hidden=true;
   $('gyd-modal-acceso').hidden=false;
   setTimeout(()=> (pedirUsuario?$('gyd-acceso-usuario'):$('gyd-acceso-clave')).focus(),0);
   return new Promise(resolve=>{resolucion=resolve;});
 }
 $('gyd-acceso-cancelar').addEventListener('click',cancelar);
 $('gyd-form-acceso').addEventListener('submit',async ev=>{
   ev.preventDefault();if(enviando||!resolucion)return;
   enviando=true;const boton=$('gyd-acceso-confirmar');boton.disabled=true;$('gyd-acceso-cancelar').disabled=true;
   try{
     const respuesta=await fetch('pos_api.php?action=acceso_previo',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:window.POS_CONFIG.csrf,caja_id:caja,operacion,contrasena:$('gyd-acceso-clave').value,login_autorizador:$('gyd-acceso-usuario').value.trim()})});
     const obj=await respuesta.json();if(!respuesta.ok||obj.error)throw Error(obj.error||'No se pudo autorizar.');
     const resolver=resolucion;resolucion=null;cerrar();resolver({operacion,caja});
   }catch(error){$('gyd-acceso-error').textContent=error?.message||'No fue posible verificar la contraseña.';$('gyd-acceso-error').hidden=false;$('gyd-acceso-clave').value='';$('gyd-acceso-clave').focus();}
   finally{enviando=false;boton.disabled=false;$('gyd-acceso-cancelar').disabled=false;}
 });
 document.addEventListener('keydown',ev=>{if(ev.key==='Escape'&&resolucion){ev.preventDefault();cancelar();}});
 window.GYD_Acceso={abrir,cancelar,
   anular:async(tipo,cajaId)=>{try{await fetch('pos_api.php?action=acceso_cancelar',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:window.POS_CONFIG.csrf,operacion:tipo,caja_id:cajaId})});}catch(_ignored){}}};
})();
