/* G&D | Navegación integrada en módulos. No crea barras globales ni flotantes. */
(function () {
  'use strict';
  if (window.__GYD_INLINE_NAV_ACTIVE__) return;
  window.__GYD_INLINE_NAV_ACTIVE__ = true;
  function start() {
    var oldFloating = document.querySelectorAll('.gyd-global-nav, .gyd-module-card');
    oldFloating.forEach(function (node) { node.remove(); });
    document.body.classList.remove('gyd-has-global-nav');

    var fileName = (location.pathname.split('/').pop() || '').toLowerCase();
    if (/^(login|logout|index)\.php$/.test(fileName)) return;
    var home = String(window.GYD_NAV_HOME || 'index.php');
    var pos = String(window.GYD_NAV_POS || 'pos.php');
    var isPosPage = /^(pos(?:_|\.)|gyd_(?:canjes|clientes|recompensas))/.test(fileName);
    var isHome = fileName === 'dashboard.php';
    var isPos = fileName === 'pos.php';
    var isReceipt = /^(pos_.*(?:ticket|comprobante|retiros?|cortes?)|erp_retiros_diarios)\.php$/.test(fileName);

    var reference = '';
    try {
      if (document.referrer) {
        var from = new URL(document.referrer);
        var baseDir = location.pathname.slice(0, location.pathname.lastIndexOf('/') + 1);
        if (from.origin === location.origin && from.pathname.startsWith(baseDir) &&
            from.pathname !== location.pathname && !/\/(login|logout)\.php$/i.test(from.pathname)) {
          reference = from.href;
        }
      }
    } catch (_) { /* Sin referencia segura: usar inicio del ERP */ }

    // Evita duplicados: primero busca enlaces nativos de navegación del módulo.
    function visible(el) {
      if (!el || el.closest('.gyd-global-nav,.gyd-module-card,.gyd-inline-nav,[hidden],.modal,.dialog,[role="dialog"]')) return false;
      if (el.closest('template')) return false;
      if (el.getAttribute('aria-hidden') === 'true') return false;
      return true;
    }
    function clean(s) {
      return String(s || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '')
        .replace(/[\s\u00a0]+/g, ' ').toLowerCase().trim();
    }
    var nativeLinks = Array.from(document.querySelectorAll('a[href],button')).filter(visible);
    function hasHome() {
      return nativeLinks.some(function(el){
        var t=clean(el.textContent); var href=(el.getAttribute('href')||'').toLowerCase();
        return /^(inicio(\s+(erp|del\s+erp))?|ir\s+al\s+inicio|volver\s+al\s+inicio)(\b|$)/.test(t) ||
            (/(?:^|\/)(?:index|dashboard)\.php(?:\?|$)/.test(href) && /inicio|dashboard/.test(t));
      });
    }
    function hasBack() {
      return nativeLinks.some(function(el){
        var t=clean(el.textContent);
        return /^(regresar|volver|atras|salir\s+del\s+modulo)(\b|$)/.test(t);
      });
    }
    function hasPos() {
      return nativeLinks.some(function(el){
        var t=clean(el.textContent); var href=(el.getAttribute('href')||'').toLowerCase();
        return /(?:regresar|volver|ir|regreso).*\b(pos|punto\s+de\s+venta)\b/.test(t) ||
          ((/(?:^|\/)pos\.php(?:\?|$)/.test(href)) && /\bpos\b|punto\s+de\s+venta/.test(t));
      });
    }

    // Primero intenta reutilizar barra existente de acciones del COMPROBANTE.
    var target = null;
    var nativeReceiptTools = null;
    if (isReceipt) {
      var receiptTools = document.querySelector('body > .tools');
      if (receiptTools && receiptTools.tagName.toLowerCase() !== 'form') {
        nativeReceiptTools = receiptTools;
        target = receiptTools;
        target.classList.add('gyd-receipt-tools');
      }
    }
    if (!target) {
      // Encabezado del módulo, NO el navbar general del ERP.
      target = document.querySelector('.module-header:not(.modal *), .page-header:not(.modal *), .content-header:not(.modal *)');
    }
    var nav = document.createElement('div');
    nav.className = 'gyd-inline-nav';
    nav.setAttribute('aria-label', 'Navegación del módulo');

    function add(label, href, className) {
      var a = document.createElement('a');
      a.href=href; a.className = 'gyd-inline-action ' + className;
      a.textContent = label;
      nav.appendChild(a);
    }
    if (!isHome && !hasHome()) add('⌂ Inicio ERP', home, 'gyd-action-home');
    // En los tickets «Volver a retiros» es el propio botón Regresar: no duplicarlo.
    if (!isHome && !hasBack()) add('← Regresar', reference || (isPosPage ? pos : home), 'gyd-action-back');
    if (isPosPage && !isPos && !hasPos()) add('▣ Regresar al POS', pos, 'gyd-action-pos');
    if (!nav.children.length) return;

    if (target) {
      // La barra del ticket no recibe un segundo encabezado ni una tarjeta adicional.
      if (nativeReceiptTools) {
        var firstMessage = target.querySelector('p');
        if (firstMessage) target.insertBefore(nav, firstMessage);
        else target.appendChild(nav);
      } else {
        target.appendChild(nav);
        target.classList.add('gyd-has-module-actions');
      }
      return;
    }

    // Si no hay encabezado, muestra los botones DENTRO del contenido del módulo.
    var main = document.querySelector('main, [role="main"], .container, .app, .content, .content-wrapper');
    if (main) {
      main.insertBefore(nav, main.firstChild);
      nav.classList.add('gyd-module-inline-row');
    } else {
      // Módulos antiguos sin <main>: después de su encabezado, nunca flotante.
      var header = document.querySelector('body > header, body > .header, body > .topbar');
      if (header && header.parentNode) header.parentNode.insertBefore(nav, header.nextSibling);
      else document.body.insertBefore(nav, document.body.firstChild);
      nav.classList.add('gyd-module-inline-row');
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once:true});
  else start();
})();
