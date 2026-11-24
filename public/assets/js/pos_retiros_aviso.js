/* G&D | V7 | Aviso de retiro automático. No depende de solicitudes antiguas. */
(() => {
  'use strict';
  const caja = document.getElementById('caja');
  const cabecera = document.querySelector('.headrow');
  if (!caja || !cabecera) return;
  let aviso = document.getElementById('gyd-retiro-alerta');
  if (!aviso) {
    aviso = document.createElement('div');
    aviso.id = 'gyd-retiro-alerta';
    aviso.setAttribute('role', 'status');
    aviso.setAttribute('aria-live', 'polite');
    aviso.style.cssText = 'display:none;padding:12px 16px;margin:10px 0;background:#e9f5ff;border:1px solid #91b8d8;border-radius:10px;color:#174971;font:600 13px system-ui;line-height:1.5';
    cabecera.insertAdjacentElement('beforebegin', aviso);
  }
  let actualizando = false;
  let ultimaCaja = '';
  let secuencia = 0;
  const pesos = centavos => (centavos / 100).toLocaleString('es-MX', { style: 'currency', currency: 'MXN' });
  const ocultar = () => { aviso.style.display = 'none'; aviso.replaceChildren(); };

  async function consultar() {
    const cajaId = caja.value;
    if (!cajaId || document.hidden) { ocultar(); return; }
    if (actualizando) return;
    actualizando = true;
    const consulta = ++secuencia;
    try {
      const respuesta = await fetch('pos_retiros_estado.php?caja_id=' + encodeURIComponent(cajaId), {
        credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }
      });
      if (!respuesta.ok) throw new Error('Saldo no disponible');
      const estado = await respuesta.json();
      // Evitar un aviso de la caja anterior si el usuario cambió su selección durante la consulta.
      if (consulta !== secuencia || caja.value !== cajaId) return;
      const centavos = Number(estado.sugeridoCentavos);
      if (!estado.abierto || !estado.alerta || !Number.isSafeInteger(centavos) || centavos <= 0) {
        ocultar();
        return;
      }
      const referencia = 'Retiro disponible: ' + pesos(centavos) + '. El fondo inicial queda protegido.';
      if (ultimaCaja !== cajaId || aviso.dataset.mensaje !== referencia) {
        aviso.replaceChildren();
        aviso.append(document.createTextNode(referencia + ' '));
        const enlace = document.createElement('a');
        enlace.href = 'pos_retiros.php?caja_id=' + encodeURIComponent(cajaId);
        enlace.textContent = 'Registrar e imprimir retiro';
        enlace.style.cssText = 'font-weight:800;color:#135a9d;text-decoration:underline;margin-left:9px';
        aviso.append(enlace);
        aviso.dataset.mensaje = referencia;
        ultimaCaja = cajaId;
      }
      aviso.style.display = 'block';
    } catch (_) {
      // No mostrar estados viejos si falla el enlace con el servidor.
      ocultar();
    } finally {
      actualizando = false;
      if (caja.value && caja.value !== cajaId && !document.hidden) consultar();
    }
  }

  caja.addEventListener('change', () => { secuencia++; ultimaCaja = ''; ocultar(); consultar(); });
  document.addEventListener('visibilitychange', () => { if (!document.hidden) consultar(); });
  window.setInterval(consultar, 30000);
  window.setTimeout(consultar, 500);
})();
