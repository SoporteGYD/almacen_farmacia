/* G&D - Conteo blindado por billetes y monedas. El servidor vuelve a sumar cada valor. */
(() => {
 'use strict';
 const valores = [
 ['b1000','Billete $1,000',100000],['b500','Billete $500',50000],
 ['b200','Billete $200',20000],['b100','Billete $100',10000],
 ['b50','Billete $50',5000],['b20','Billete $20',2000],
 ['m20','Moneda $20',2000],['m10','Moneda $10',1000],['m5','Moneda $5',500],
 ['m2','Moneda $2',200],['m1','Moneda $1',100],
 ['m050','Moneda $0.50',50],['m020','Moneda $0.20',20],
 ['m010','Moneda $0.10',10],['m005','Moneda $0.05',5]
 ];
 let panel;
 const moneda = centavos => '$' + (centavos/100).toLocaleString('es-MX',{minimumFractionDigits:2,maximumFractionDigits:2});
 function iniciar(){
   if(panel) return;
   const original = document.getElementById('campoDialog');
   if(!original) return;
   panel=document.createElement('section'); panel.id='gyd-corte-conteo'; panel.hidden=true;
   panel.innerHTML='<h3>Conteo físico por billetes y monedas</h3><p>Cuenta las piezas disponibles. El total se calcula automáticamente y queda asociado al turno.</p><div class="gyd-corte-grid"></div><div class="gyd-corte-total">Total contado: <strong id="gyd-corte-suma">$0.00</strong></div>';
   const grid=panel.querySelector('.gyd-corte-grid');
   valores.forEach(([id,nombre])=>{
     const label=document.createElement('label'); label.textContent=nombre;
     const input=document.createElement('input');input.type='number';input.min='0';input.max='99999';input.step='1';input.value='0';input.inputMode='numeric';input.dataset.denominacion=id;
     input.addEventListener('input', recalcular); label.appendChild(input);grid.appendChild(label);
   });
   original.insertAdjacentElement('afterend',panel);
 }
 function datos(){ const d={};if(panel)panel.querySelectorAll('[data-denominacion]').forEach(x=>{d[x.dataset.denominacion]=x.value;});return d; }
 function valido(){
   return valores.every(([id])=>{
     const v=datos()[id];return /^(0|[1-9]\d{0,4})$/.test(v);
   });
 }
 function suma(){
   const d=datos();return valores.reduce((sum,[id,,cent])=>sum+Number(d[id]||0)*cent,0);
 }
 function recalcular(){
   if(!panel)return;
   const txt=panel.querySelector('#gyd-corte-suma');
   const bien=valido();
   txt.textContent=bien?moneda(suma()):'Revisa las cantidades';
   const monto=document.getElementById('montoDialog');
   if(monto)monto.value=bien?(suma()/100).toFixed(2):'';
 }
 function preparar(accion){
   iniciar();if(!panel)return;
   panel.hidden=accion!=='cerrar';
   const campo=document.getElementById('campoDialog'),monto=document.getElementById('montoDialog');
   if(campo)campo.hidden=accion==='cerrar';
   if(monto)monto.readOnly=accion==='cerrar';
   if(accion==='cerrar'){
     panel.querySelectorAll('[data-denominacion]').forEach(input=>input.value='0');
     recalcular();
     panel.querySelector('[data-denominacion]')?.focus();
   }
 }
 window.GYD_Corte={preparar,datos,valido,suma};
})();
