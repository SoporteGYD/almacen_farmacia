(()=>{'use strict';
 const $=id=>document.getElementById(id);
 function modoRetiro(){return document.querySelector('input[name="gyd-retiro-opcion"]:checked')?.value||'CAJERO';}
 function actualizarModoRetiro(){
    if(operacion!=='retiro')return;
    const cajero=modoRetiro()==='CAJERO';
    const numero=$('gyd-retiro-numero-grupo'),usuario=$('gyd-acceso-usuario-grupo');
    numero.hidden=!cajero;numero.style.display=cajero?'block':'none';
    usuario.hidden=cajero;usuario.style.display=cajero?'none':'block';
    $('gyd-retiro-numero').required=cajero;
    $('gyd-acceso-usuario').required=!cajero;
    $('gyd-acceso-usuario').placeholder='Usuario o correo del gerente, dueño o administrador';
    $('gyd-acceso-usuario').value='';$('gyd-acceso-clave').value='';
    $('gyd-acceso-error').hidden=true;
    (cajero?$('gyd-retiro-numero'):$('gyd-acceso-usuario')).focus();
 }
 let resolucion=null,operacion='',caja=0,enviando=false;
 function cerrar(){ $('gyd-modal-acceso').hidden=true; $('gyd-acceso-clave').value=''; $('gyd-acceso-usuario').value=''; $('gyd-retiro-numero').value=''; $('gyd-acceso-error').hidden=true; }
 function cancelar(){
    if(!resolucion||enviando)return;
    const resolver=resolucion;resolucion=null;cerrar();resolver(null);
 }
 function abrir(tipo,cajaId){
   if(!['retiro','corte'].includes(tipo)||!(cajaId>0))return Promise.reject(new Error('Selecciona una caja válida.'));
   if(resolucion)return Promise.reject(new Error('Ya existe una verificación en curso.'));
   operacion=tipo;caja=cajaId;
   $('gyd-modal-acceso-titulo').textContent=tipo==='retiro'?'Confirmar retiro de efectivo':'Autorizar acceso al corte';
   $('gyd-modal-acceso-texto').textContent=tipo==='retiro'?'Elige cómo autorizar: número de caja y contraseña del cajero que abrió el turno, o usuario y contraseña de Gerencia, Dirección o Administración. Se registrará quién autorizó.':'La autorización se solicita antes de mostrar el conteo. El corte finalizará y cerrará el turno.';
   const pedirUsuario=tipo==='corte'&&!window.POS_CONFIG?.admin;
   const grupo=$('gyd-acceso-usuario-grupo');grupo.hidden=!pedirUsuario;
   grupo.style.display=pedirUsuario?'block':'none';
   $('gyd-acceso-usuario').required=tipo==='corte'&&pedirUsuario;
   $('gyd-acceso-usuario').placeholder=tipo==='retiro'?'Usuario/correo del autorizador (vacío = cuenta actual)':'Usuario o correo';
   const etiqueta=grupo.firstChild;if(etiqueta&&etiqueta.nodeType===3)etiqueta.nodeValue=tipo==='retiro'?'Usuario autorizador (Gerencia, Dirección o Administración)':'Usuario autorizador (Gerencia, Dirección, Contabilidad o Administración)';
   $('gyd-acceso-usuario').value='';$('gyd-acceso-clave').value='';
   $('gyd-acceso-error').hidden=true;
   $('gyd-retiro-metodo').hidden=tipo!=='retiro';
   document.querySelector('input[name="gyd-retiro-opcion"][value="CAJERO"]').checked=true;
   $('gyd-retiro-numero').value=''; $('gyd-retiro-numero-grupo').hidden=true;
   $('gyd-retiro-numero-grupo').style.display='none'; $('gyd-retiro-numero').required=false;
   if(tipo==='retiro')actualizarModoRetiro();
   $('gyd-modal-acceso').hidden=false;
   setTimeout(()=> (tipo==='retiro'?(modoRetiro()==='CAJERO'?$('gyd-retiro-numero'):$('gyd-acceso-usuario')):(pedirUsuario?$('gyd-acceso-usuario'):$('gyd-acceso-clave'))).focus(),0);
   return new Promise(resolve=>{resolucion=resolve;});
 }
 document.querySelectorAll('input[name="gyd-retiro-opcion"]').forEach(r=>r.addEventListener('change',actualizarModoRetiro));
 $('gyd-acceso-cancelar').addEventListener('click',cancelar);
 $('gyd-form-acceso').addEventListener('submit',async ev=>{
   ev.preventDefault();if(enviando||!resolucion)return;
   enviando=true;const boton=$('gyd-acceso-confirmar');boton.disabled=true;$('gyd-acceso-cancelar').disabled=true;
   try{
     const respuesta=await fetch('pos_api.php?action=acceso_previo',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:window.POS_CONFIG.csrf,caja_id:caja,operacion,contrasena:$('gyd-acceso-clave').value,login_autorizador:$('gyd-acceso-usuario').value.trim(),modo_retiro:operacion==='retiro'?modoRetiro():null,numero_caja:operacion==='retiro'&&modoRetiro()==='CAJERO'?$('gyd-retiro-numero').value.trim():''})});
     const obj=await respuesta.json();if(!respuesta.ok||obj.error)throw Error(obj.error||'No se pudo autorizar.');
     const resolver=resolucion;resolucion=null;cerrar();resolver({operacion,caja});
   }catch(error){$('gyd-acceso-error').textContent=error?.message||'No fue posible verificar la contraseña.';$('gyd-acceso-error').hidden=false;$('gyd-acceso-clave').value='';$('gyd-acceso-clave').focus();}
   finally{enviando=false;boton.disabled=false;$('gyd-acceso-cancelar').disabled=false;}
 });
 document.addEventListener('keydown',ev=>{if(ev.key==='Escape'&&resolucion){ev.preventDefault();cancelar();}});
 window.GYD_Acceso={abrir,cancelar,
   anular:async(tipo,cajaId)=>{try{await fetch('pos_api.php?action=acceso_cancelar',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:window.POS_CONFIG.csrf,operacion:tipo,caja_id:cajaId})});}catch(_ignored){}}};
})();
