/* G&D | Navegación interna en una sola pestaña. UI únicamente; no altera transacciones. */
(function () {
  'use strict';
  if (window.GYD_EnMismaPestana) return;
  const here = new URL(window.location.href);
  const publicDir = here.pathname.substring(0, here.pathname.lastIndexOf('/') + 1);
  const originalOpen = window.open.bind(window);
  let activeModal = null, returnFocus = null;
  function internalURL(candidate) {
    try {
      if (typeof candidate !== 'string' || !candidate.trim()) return null;
      const u = new URL(candidate, window.location.href);
      if (!['http:', 'https:'].includes(u.protocol) || u.origin !== here.origin) return null;
      if (!u.pathname.startsWith(publicDir)) return null;
      return u;
    } catch (_) { return null; }
  }
  function isSalesTicket(u) { return /\/pos_ticket\.php$/i.test(u.pathname); }
  function addStyles() {
    if (document.getElementById('gyd-unatab-styles')) return;
    const el = document.createElement('style');
    el.id = 'gyd-unatab-styles';
    el.textContent = `
      .gyd-unatab-shade{position:fixed;inset:0;z-index:2147483000;display:grid;place-items:center;padding:16px;background:rgba(9,28,48,.74);box-sizing:border-box;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
      .gyd-unatab-pane{display:flex;flex-direction:column;width:min(900px,100%);height:min(94vh,1040px);overflow:hidden;background:#fff;border-radius:18px;box-shadow:0 24px 70px rgba(0,0,0,.35)}
      .gyd-unatab-head{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:12px 16px;background:#174a80;color:#fff}
      .gyd-unatab-head strong{font-size:16px;margin-right:auto}
      .gyd-unatab-head button,.gyd-unatab-head a{font:700 13px system-ui,-apple-system,Segoe UI,Roboto,sans-serif;border:0;border-radius:9px;padding:10px 12px;cursor:pointer;text-decoration:none;background:#eaf1fa;color:#163b66}
      .gyd-unatab-head .gyd-unatab-close{background:#fff;color:#183650}
      .gyd-unatab-frame{flex:1;width:100%;min-height:0;border:0;background:#f2f5fa}
      .gyd-unatab-footer{padding:9px 16px;color:#65788e;font-size:12px;background:#f7f9fc}
      @media(max-width:620px){.gyd-unatab-shade{padding:0}.gyd-unatab-pane{width:100%;height:100dvh;border-radius:0}.gyd-unatab-head{padding:9px}.gyd-unatab-head strong{width:100%;font-size:14px}}
      @media print{.gyd-unatab-shade{display:none!important}}
    `;
    document.head.appendChild(el);
  }
  function closeTicket() {
    if (!activeModal) return;
    const modal = activeModal;
    activeModal = null;
    document.removeEventListener('keydown', escClose, true);
    modal.remove();
    document.body.style.removeProperty('overflow');
    if (returnFocus && returnFocus.isConnected) returnFocus.focus();
  }
  function escClose(event) { if (event.key === 'Escape' && activeModal) {event.preventDefault(); event.stopPropagation(); closeTicket();} }
  function openTicket(url) {
    const target = internalURL(url);
    if (!target || !isSalesTicket(target) || !document.body) return false;
    closeTicket();
    addStyles();
    returnFocus = document.activeElement;
    const shade = document.createElement('div');
    shade.className = 'gyd-unatab-shade';
    shade.setAttribute('role', 'dialog');
    shade.setAttribute('aria-modal', 'true');
    shade.setAttribute('aria-label', 'Comprobante de venta');
    const panel = document.createElement('div'); panel.className = 'gyd-unatab-pane';
    const header = document.createElement('div'); header.className = 'gyd-unatab-head';
    const title = document.createElement('strong'); title.textContent = 'Comprobante de venta';
    const print = document.createElement('button'); print.type='button'; print.textContent = 'Imprimir dos copias';
    const direct = document.createElement('a'); direct.textContent = 'Ver en esta pestaña'; direct.href = target.href;
    const close = document.createElement('button'); close.type='button'; close.className='gyd-unatab-close';close.textContent='Cerrar y seguir vendiendo';
    const frame = document.createElement('iframe'); frame.className='gyd-unatab-frame';frame.title='Ticket de venta';
    frame.setAttribute('referrerpolicy','same-origin'); frame.src = target.href;
    const footer = document.createElement('div'); footer.className='gyd-unatab-footer';
    footer.textContent='El POS continúa abierto. Puedes imprimir o cerrar el comprobante sin crear otra pestaña.';
    print.addEventListener('click', function () {
      try {
        const w = frame.contentWindow;
        if (!w) throw new Error('No está disponible la vista del ticket');
        if (typeof w.imprimirDosTrabajos === 'function') w.imprimirDosTrabajos();
        else if (typeof w.imprimirActual === 'function') w.imprimirActual();
        else w.print();
      } catch (error) { footer.textContent = 'No se pudo imprimir dentro del visor. Usa “Ver en esta pestaña”.'; }
    });
    close.addEventListener('click', closeTicket);
    shade.addEventListener('click', e => { if (e.target===shade) closeTicket(); });
    header.append(title,print,direct,close);
    panel.append(header,frame,footer); shade.append(panel);document.body.append(shade);
    activeModal=shade;
    document.body.style.overflow='hidden';
    document.addEventListener('keydown',escClose,true);
    close.focus();
    return true;
  }
  window.GYD_EnMismaPestana = { abrirTicket: openTicket, cerrarTicket: closeTicket };

  const atPOS = /\/pos\.php$/i.test(here.pathname);
  document.addEventListener('click', function (event) {
    if (event.defaultPrevented || event.button!==0 || event.ctrlKey||event.metaKey||event.altKey||event.shiftKey) return;
    const link = event.target.closest?.('a[href]');
    if (!link || link.hasAttribute('download')) return;
    const url = internalURL(link.getAttribute('href'));
    if (!url) return;
    if (atPOS && isSalesTicket(url)) {
      if (openTicket(url.href)) {event.preventDefault();event.stopPropagation();}
      return;
    }
    if (String(link.target).toLowerCase() === '_blank') link.target = '_self';
  }, true);
  document.addEventListener('submit', function (event) {
    const form=event.target;
    if (!(form instanceof HTMLFormElement)) return;
    const url=internalURL(form.getAttribute('action') || location.href);
    if (!url) return;
    if (String(form.target).toLowerCase()==='_blank') form.target='_self';
    const submitter=event.submitter;
    if (submitter && String(submitter.formTarget).toLowerCase()==='_blank') submitter.formTarget='_self';
  }, true);

  // Limit legacy popup calls to the active browser tab. External sites unaffected.
  window.open = function (input, target, features) {
    const url=internalURL(typeof input==='string'?input:(input&&input.href)||'');
    if (url && (target==='_blank'||target===undefined||target==='')) {
      if (atPOS && isSalesTicket(url)) { openTicket(url.href); return window; }
      window.location.assign(url.href); return window;
    }
    return originalOpen(input, target, features);
  };

  // The ticket may be embedded in POS: prevent 'Volver al POS' from nesting another POS inside the frame.
  if (window.parent!==window && isSalesTicket(here)) {
    document.addEventListener('click', function(event){
      const link=event.target.closest?.('a[href]');
      if(!link)return;
      const url=internalURL(link.getAttribute('href'));
      if(url && /\/pos\.php$/i.test(url.pathname)) {
        try { if (window.parent.GYD_EnMismaPestana && typeof window.parent.GYD_EnMismaPestana.cerrarTicket==='function') {
          event.preventDefault();window.parent.GYD_EnMismaPestana.cerrarTicket();
        }} catch(_){}
      }
    },true);
  }
})();
