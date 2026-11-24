/* Pruebas puras de variación costo nuevo vs. costo histórico, sin dependencias. */
const assert = require('node:assert/strict');
const porcentaje=(nuevo,old)=>old>0 ? ((nuevo-old)/old*100).toFixed(1) : 'Sin referencia';
assert.equal(porcentaje(76.69,55.8),'37.4');
assert.equal(porcentaje(87.33,127),'-31.2');
assert.equal(porcentaje(0,0),'Sin referencia');
assert.equal(porcentaje(55.8,55.8),'0.0');
console.log('OK 4 casos de variación de costos');
