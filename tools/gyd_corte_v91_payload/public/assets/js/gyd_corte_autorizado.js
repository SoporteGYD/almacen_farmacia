/* G&D V9: el corte es una operación única; no se solicita una segunda acción para cerrar caja. */
(()=>{'use strict';
 let panel=null;
 function preparar(accion){
  if(!panel){
   const campo=document.getElementById('campoDialog');if(!campo)return;
   panel=document.createElement('section');panel.id='gyd-corte-autorizado';panel.hidden=true;
   panel.innerHTML='<h3>Autorización del corte</h3><p>El corte cerrará inmediatamente esta caja. Debe autorizarlo Gerencia, Dueños o Administración, igual que el arqueo protegido.</p><label>Usuario autorizador<input id="gyd-corte-user" maxlength="120" autocomplete="username" placeholder="Usuario o correo"></label><label>Contraseña del autorizador<input id="gyd-corte-password" type="password" autocomplete="off" placeholder="Contraseña"></label><small>Las credenciales se comprueban en el servidor y no se guardan en el navegador.</small>';
   campo.insertAdjacentElement('afterend',panel);
  }
  const corte=accion==='cerrar';const autorizado=!!window.POS_CONFIG?.admin;
  panel.hidden=!corte||autorizado;
  panel.querySelectorAll('input').forEach(input=>{input.required=corte&&!autorizado;input.value='';});
  const btn=document.getElementById('confirmDialog');
  if(btn)btn.textContent=corte?'Realizar corte y cerrar caja':'Confirmar';
 }
 function credenciales(){
  if(!panel||panel.hidden)return {};
  return {supervisor_login:panel.querySelector('#gyd-corte-user').value.trim(),supervisor_password:panel.querySelector('#gyd-corte-password').value};
 }
 function limpiar(){if(panel)panel.querySelectorAll('input').forEach(input=>input.value='');}
 function mensaje(res){
  const etiqueta=res.resultado==='SOBRANTE'?'SOBRANTE':res.resultado==='FALTANTE'?'FALTANTE':'CUADRE EXACTO';
  const diferencia=res.resultado==='EXACTO'?'':` · ${etiqueta}: $${res.importe_diferencia}`;
  return `Corte registrado y CAJA CERRADA · Esperado: $${res.esperado}${diferencia}`;
 }
 window.GYD_CorteAutorizado={preparar,credenciales,limpiar,mensaje};
})();
