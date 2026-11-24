/* Centro G&D: aviso por departamento. No modifica tareas ni inventario. */
(() => {
  'use strict';
  if (document.getElementById('gyd-centro-bell')) return;
  const link = document.createElement('a');
  link.id = 'gyd-centro-bell';
  link.href = 'erp_notificaciones.php';
  link.setAttribute('aria-label','Abrir centro de notificaciones');
  link.title = 'Pendientes por departamento';
  link.style.cssText = 'display:inline-flex;align-items:center;gap:7px;padding:8px 11px;border-radius:9px;background:#e8f2fc;color:#144b7c;font:700 13px system-ui,sans-serif;text-decoration:none;white-space:nowrap;vertical-align:middle;min-height:35px;';
  link.textContent = '🔔 Pendientes';
  const badge = document.createElement('span');
  badge.style.cssText = 'background:#c72c45;color:white;border-radius:999px;min-width:19px;min-height:19px;padding:2px 5px;font-size:11px;text-align:center;display:none';
  badge.setAttribute('aria-live','polite');
  link.append(badge);
  let mounted = false;
  function showBell() {
    if (mounted) return;
    const host = document.querySelector('.navbar.gyd-menu-principal, nav.top-nav, .cab, .header-content, header.app-header, header');
    if (host) host.append(link);
    else {link.style.position='fixed';link.style.right='15px';link.style.bottom='15px';link.style.zIndex='1000';document.body.append(link);}
    mounted = true;
  }
  const refresh = async () => {
    try {
      const response = await fetch('erp_notificaciones_api.php',{credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}});
      if(!response.ok) return;
      const json = await response.json();
      if(json.ok && json.contadores){
        showBell();
        const n=Number(json.contadores.total)||0;
        badge.textContent=n>99?'99+':String(n);
        badge.style.display=n>0?'inline-block':'none';
        link.title = `${n} trámites pendientes · ${Number(json.contadores.sin_leer)||0} avisos sin leer`;
      }
    } catch (_) { /* La red no debe interrumpir la pantalla actual. */ }
  };
  refresh();
  setInterval(() => { if(!document.hidden) refresh(); }, 60000);
  document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});
})();
