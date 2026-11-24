/* Corte: se autoriza en un modal independiente ANTES del conteo. */
(()=>{'use strict';
 function preparar(accion){const btn=document.getElementById('confirmDialog');if(btn)btn.textContent=accion==='cerrar'?'Realizar corte y cerrar caja':'Confirmar';}
 function mensaje(res){const etiqueta=res.resultado==='SOBRANTE'?'SOBRANTE':res.resultado==='FALTANTE'?'FALTANTE':'CUADRE EXACTO';
  const detalle=res.resultado==='EXACTO'?'':` · ${etiqueta}: $${res.importe_diferencia}`;
  return `Corte registrado y CAJA CERRADA · Esperado: $${res.esperado}${detalle}`;
 }
 window.GYD_CorteAutorizado={preparar,credenciales:()=>({}),limpiar:()=>{},mensaje};
})();
