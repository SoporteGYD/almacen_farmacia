/* G&D | Comprobantes en POS, sin pestañas adicionales. */
(function () {
  'use strict';
  if (window.GYD_TicketsEnPOS) return;
  const $ = (tag, cls, text) => { const el = document.createElement(tag); if (cls) el.className = cls; if (text) el.textContent = text; return el; };
  let box = null, previousFocus = null;
  function urlTicket(path) {
    try {
      const url = new URL(path, location.href);
      if (url.origin !== location.origin || !/^\/.*\/public\/(?:pos_ticket|pos_retiro_ticket)\.php$/i.test(url.pathname)) return null;
      return url;
    } catch (e) { return null; }
  }
  function styles() {
    if (document.getElementById('gyd-ticket-local-style')) return;
    const css = $('style'); css.id = 'gyd-ticket-local-style';
    css.textContent = `.gyd-ticket-shade{position:fixed;inset:0;z-index:2147483200;display:grid;place-items:center;padding:18px;background:rgba(9,25,47,.77);box-sizing:border-box;font-family:system-ui,-apple-system,'Segoe UI',sans-serif}.gyd-ticket-pane{width:min(760px,100%);height:min(94dvh,1040px);display:flex;flex-direction:column;background:white;border-radius:20px;overflow:hidden;box-shadow:0 30px 80px rgba(0,0,0,.35)}.gyd-ticket-head{background:#143f70;color:white;padding:14px 17px;display:flex;align-items:center;gap:9px;flex-wrap:wrap}.gyd-ticket-title{margin:0 auto 0 0;font-size:16px;font-weight:800}.gyd-ticket-head button{cursor:pointer;border:0;border-radius:11px;padding:11px 15px;font:700 13px system-ui;background:white;color:#143f70}.gyd-ticket-head .gyd-ticket-print{background:#149278;color:white}.gyd-ticket-head .gyd-ticket-close{background:#e5eef8;color:#183954}.gyd-ticket-frame{flex:1;width:100%;min-height:0;border:0;background:#f1f5f9}.gyd-ticket-note{padding:9px 16px;color:#4b6078;font-size:12px;background:#f8fafc}.gyd-ticket-shade[hidden]{display:none!important}@media(max-width:620px){.gyd-ticket-shade{padding:0}.gyd-ticket-pane{width:100%;height:100dvh;border-radius:0}.gyd-ticket-title{width:100%}}@media print{.gyd-ticket-shade{display:none!important}}`;
    document.head.appendChild(css);
  }
  function close() {
    if (!box) return;
    box.remove(); box=null;
    document.body.style.overflow='';
    document.removeEventListener('keydown', onKey, true);
    if (previousFocus && previousFocus.isConnected) previousFocus.focus();
  }
  function onKey(e) { if (e.key === 'Escape' && box) {e.preventDefault(); e.stopPropagation(); close();} }
  function open(path, opts) {
    const url=urlTicket(path); if (!url || !document.body) return false;
    close(); styles(); previousFocus=document.activeElement;
    const isWithdrawal=/pos_retiro_ticket\.php$/i.test(url.pathname);
    if (isWithdrawal) url.searchParams.set('embed','1');
    const shade=$('div','gyd-ticket-shade'); shade.setAttribute('role','dialog');shade.setAttribute('aria-modal','true');
    const panel=$('div','gyd-ticket-pane');
    const head=$('div','gyd-ticket-head');
    const title=$('h2','gyd-ticket-title',isWithdrawal?'Comprobante de retiro de efectivo':'Ticket de venta');
    const print=$('button','gyd-ticket-print','Imprimir dos copias');print.type='button';
    const btn=$('button','gyd-ticket-close','Cerrar y seguir vendiendo');btn.type='button';
    const frame=$('iframe','gyd-ticket-frame');frame.title=isWithdrawal?'Comprobante de retiro':'Ticket de venta';frame.src=url.href;
    const note=$('div','gyd-ticket-note','El POS permanece abierto. Puedes imprimir y regresar a la venta sin abrir otra pestaña.');
    print.addEventListener('click', function(){
      try {const w=frame.contentWindow; if (!w) throw Error('No se encontró comprobante');
        if (typeof w.imprimirDosTrabajos==='function') w.imprimirDosTrabajos();
        else w.print();
      } catch(e){note.textContent='No se pudo iniciar la impresión. Intenta de nuevo desde el comprobante.';}
    });
    frame.addEventListener('load', function(){
      try {
        const doc=frame.contentDocument;
        if(doc){
          const tools=doc.querySelector('.tools'); if(tools) tools.style.display='none';
          doc.querySelectorAll('.gyd-global-nav,.gyd-module-card').forEach(el=>el.style.display='none');
        }
      } catch(e){};
      if(opts && opts.autoPrint){
        opts.autoPrint=false;
        window.setTimeout(function(){try{frame.contentWindow?.print();}catch(e){note.textContent='Para imprimir utiliza el botón superior.';}},200);
      }
    });
    btn.addEventListener('click',close);
    shade.addEventListener('click',e=>{if(e.target===shade)close();});
    head.append(title,print,btn);panel.append(head,frame,note);shade.append(panel);document.body.append(shade);
    box=shade;document.body.style.overflow='hidden';document.addEventListener('keydown',onKey,true);
    btn.focus();return true;
  }
  window.GYD_TicketsEnPOS={abrir:open,cerrar:close};
  // Catch reprints rendered dynamically in history while on the POS.
  // Also force the withdrawal flow to stay inside this tab if a legacy link still has target=_blank.
  function internalWithdrawal(path) {
    try {
      const u=new URL(path,location.href);
      return u.origin===location.origin && /^\/.*\/public\/(?:pos_retiros|pos_corte_retiros)\.php$/i.test(u.pathname) ? u : null;
    } catch(e) {return null;}
  }
  document.addEventListener('click',function(e){
    if(e.defaultPrevented||e.button!==0||e.ctrlKey||e.shiftKey||e.altKey||e.metaKey) return;
    const a=e.target.closest?.('a[href]'); if(!a||a.hasAttribute('download'))return;
    const u=urlTicket(a.getAttribute('href'));
    if(u && open(u.href)){e.preventDefault();e.stopImmediatePropagation();return;}
    if(internalWithdrawal(a.getAttribute('href')) && String(a.target).toLowerCase()==='_blank') a.target='_self';
  },true);
  const previousOpen=window.open.bind(window);
  window.open=function(path,target,features){
    const candidate=typeof path==='string'?path:(path&&path.href)||'';
    const ticket=urlTicket(candidate);
    if(ticket){open(ticket.href);return window;}
    const workflow=internalWithdrawal(candidate);
    if(workflow){window.location.assign(workflow.href);return window;}
    return previousOpen(path,target,features);
  };
  function landing() {
    const u=new URL(location.href);
    const id=u.searchParams.get('gyd_retiro_ticket');
    if (!id || !/^[1-9][0-9]*$/.test(id)) return;
    u.searchParams.delete('gyd_retiro_ticket');
    history.replaceState(history.state,'',u.pathname+u.search+u.hash);
    open('pos_retiro_ticket.php?id='+encodeURIComponent(id),{autoPrint:true});
  }
  if (document.readyState==='loading') document.addEventListener('DOMContentLoaded',landing,{once:true}); else landing();
})();
