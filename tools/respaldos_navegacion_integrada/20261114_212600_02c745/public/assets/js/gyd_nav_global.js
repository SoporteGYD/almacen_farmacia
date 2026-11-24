(function(){
  if (window.__GYD_GLOBAL_NAV__) return; window.__GYD_GLOBAL_NAV__ = true;
  const body=document.body; if(!body) return;
  body.classList.add('gyd-has-global-nav');
  const current=(location.pathname.split('/').pop()||'').toLowerCase();
  const params=new URLSearchParams(location.search);
  const home=(window.GYD_NAV_HOME||'index.php');
  const pos=(window.GYD_NAV_POS||'pos.php');
  const sameOriginRef=(function(){try{if(!document.referrer) return ''; const u=new URL(document.referrer); return u.origin===location.origin?u.href:'';}catch(e){return '';}})();
  const nav=document.createElement('div');
  nav.className='gyd-global-nav';
  const mk=(tag, cls, text, href, onclick)=>{const el=document.createElement(tag); el.className=cls; el.textContent=text; if(href) el.href=href; if(onclick) el.onclick=onclick; return el;};
  nav.appendChild(mk('a','gyd-home','Inicio ERP', home));
  nav.appendChild(mk('button','gyd-back','Regresar', null, function(){ if(sameOriginRef){ location.href=sameOriginRef; } else if(history.length>1){ history.back(); } else { location.href=home; }}));
  if(current.indexOf('pos')!==-1 || current.indexOf('retiro')!==-1 || current.indexOf('corte')!==-1){
    nav.appendChild(mk('a','gyd-pos','Regresar al POS', pos));
  }
  document.body.appendChild(nav);

  // improve simple ticket pages with a module card if no main header is present
  const tools=document.querySelector('.tools');
  if (tools && !document.querySelector('.gyd-module-card')) {
    const wrap=document.createElement('div');
    wrap.className='gyd-module-card';
    const left=document.createElement('div');
    const title=document.createElement('h1');
    title.className='gyd-title';
    title.textContent = current.indexOf('retiro')!==-1 ? 'Comprobante de retiro de efectivo' : (document.title || 'Módulo ERP');
    const sub=document.createElement('p');
    sub.className='gyd-sub';
    sub.textContent='Accesos rápidos para volver al módulo anterior, al POS o al inicio del ERP.';
    left.appendChild(title); left.appendChild(sub);
    const actions=document.createElement('div'); actions.className='gyd-module-actions';
    actions.appendChild(mk('a','gyd-go-back','← Regresar', sameOriginRef || home));
    actions.appendChild(mk('a','gyd-go-pos','🧾 Regresar al POS', pos));
    actions.appendChild(mk('a','gyd-go-home','⌂ Inicio ERP', home));
    wrap.appendChild(left); wrap.appendChild(actions);
    tools.parentNode.insertBefore(wrap, tools);
  }
})();
