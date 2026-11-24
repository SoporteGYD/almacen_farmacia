/* ERP G&D - Revisión previa de orden de compra (sin cambios a inventario ni BD). */
(function () {
  'use strict';
  const form = document.getElementById('ocForm');
  const panel = document.getElementById('ocRevisionPanel');
  const title = document.getElementById('ocRevisionTitulo');
  const list = document.getElementById('ocRevisionLista');
  if (!form || !panel || !title || !list) return;

  const get = name => form.querySelector('[name="' + name + '"]');
  const numeric = value => /^\d{1,9}(?:\.\d{1,4})?$/.test(String(value || '').trim());
  const pct = value => numeric(value) && (String(value).split('.')[1] || '').length <= 2 && Number(value) <= 100;
  const money = value => new Intl.NumberFormat('es-MX', {style:'currency',currency:'MXN'}).format(value);
  const labelOf = row => {
    const code = row.querySelector('td')?.textContent?.trim() || row.dataset.id || 'Sin código';
    return code.slice(0, 50);
  };
  function revisar() {
    const errors = [], warnings = [], rows = [...form.querySelectorAll('#lineas tr[data-id]')];
    const warn = message => { if (!warnings.includes(message)) warnings.push(message); };
    const error = message => { if (!errors.includes(message)) errors.push(message); };
    const supplier = get('proveedor_id')?.value || '';
    if (!supplier) error('Selecciona un proveedor válido.');
    if (!get('destino')?.value) error('Selecciona una sucursal o almacén de entrega.');
    const fecha = get('fecha')?.value || '', entrega = get('fecha_entrega')?.value || '';
    if (!/^\d{4}-\d{2}-\d{2}$/.test(fecha)) error('La fecha de la orden es obligatoria.');
    if (!/^\d{4}-\d{2}-\d{2}$/.test(entrega)) error('La fecha de entrega es obligatoria.');
    if (fecha && entrega && entrega < fecha) error('La entrega no puede ser anterior a la fecha de la orden.');
    if (!rows.length) error('Agrega por lo menos un artículo a la orden.');
    if (rows.length > 80) error('Sólo se permiten hasta 80 artículos por orden.');
    const seenIds = new Set(), seenCodes = new Set();
    let totalBruto = 0;
    rows.forEach((row, index) => {
      const prefix = 'Línea ' + (index + 1) + ' (' + labelOf(row) + ')';
      const id = row.dataset.id || '';
      const code = labelOf(row).toUpperCase();
      if (!id || seenIds.has(id) || seenCodes.has(code)) error(prefix + ': artículo duplicado o sin identificador.');
      seenIds.add(id); seenCodes.add(code);
      const val = name => row.querySelector('[name="' + name + '[]"]')?.value ?? '';
      const qty = val('cantidad');
      if (!/^\d+$/.test(qty) || Number(qty) < 1 || Number(qty) > 10000000) {
        error(prefix + ': la cantidad debe ser un número entero entre 1 y 10,000,000.');
      }
      const cost = val('costo');
      if (!numeric(cost) || Number(cost) > 99999999) {
        error(prefix + ': costo inválido; usa un valor de 0 a 99,999,999 con hasta 4 decimales.');
      } else {
        const amount = Number(cost);
        if (amount === 0) warn(prefix + ': costo $0.00; revisa antes de registrar.');
        const base = Number(row.dataset.ultimoCosto || 0);
        if (Number.isFinite(base) && base > 0 && amount > 0 && Math.abs(amount - base) / base >= 0.20) {
          const delta = ((amount - base) / base * 100).toFixed(1);
          warn(prefix + ': costo ' + money(amount) + ' vs. último costo ' + money(base) + ' (' + (Number(delta) > 0 ? '+' : '') + delta + '%).');
        }
        if (/^\d+$/.test(qty)) totalBruto += Number(qty) * amount;
      }
      const boxes = val('cajas');
      if (!numeric(boxes) || (String(boxes).split('.')[1] || '').length > 2 || Number(boxes) > 9999999) {
        error(prefix + ': cajas informativas inválidas.');
      }
      if (!pct(val('iva')) || !pct(val('ieps'))) error(prefix + ': IVA o IEPS fuera del rango de 0 a 100%.');
      if (row.dataset.ivaPendiente === 'true') warn(prefix + ': IVA sin configurar; se utilizará 0% provisional en esta orden.');
      if (supplier && row.dataset.proveedorId && row.dataset.proveedorId !== supplier) {
        warn(prefix + ': el proveedor principal del catálogo es distinto del proveedor elegido.');
      }
    });
    if (totalBruto > 999999999999) error('El importe total de la orden excede el límite permitido.');
    ['descuento_1','descuento_2','descuento_3','descuento_4','descuento_general'].forEach(name => {
      if (!pct(get(name)?.value ?? '0')) error('Revisa ' + name.replaceAll('_',' ') + ': porcentaje inválido.');
    });
    const fpgc = get('fpgc')?.value ?? '0';
    if (!numeric(fpgc) || (String(fpgc).split('.')[1] || '').length > 2 || Number(fpgc) > 9999999) error('FPGC debe ser un importe válido no negativo.');
    return {errors, warnings, items: rows.length};
  }
  /* GYD_OC_VALIDACION_COMPACTA_V1: solo cambia la vista, no la revisión de datos. */
  let ocRevUi = null;
  let ocRevOpenErrors = true;
  let ocRevOpenWarnings = false;
  let ocRevHadErrors = false;
  function ocRevInit() {
    if (ocRevUi) return ocRevUi;
    if (!document.getElementById('gyd-oc-validacion-compacta-css')) {
      const style = document.createElement('style');
      style.id = 'gyd-oc-validacion-compacta-css';
      style.textContent = `
        .oc-revision.ocrev-compact { padding:0; margin:0 23px 10px; border:1px solid #cbdceb;
          border-radius:10px; background:#fff; color:#203c56; overflow:hidden; font-size:12px; }
        .oc-revision.ocrev-compact[data-status="warning"],
        .oc-revision.ocrev-compact[data-status="ok"] {background:#fff; border-color:#bedcc9; color:#214d38;}
        .oc-revision.ocrev-compact[data-status="error"] {background:#fff; border-color:#ecb8b6; color:#832d2c;}
        .ocrev-bar {display:flex; align-items:center; justify-content:space-between; gap:12px; padding:9px 13px; flex-wrap:wrap;}
        .ocrev-status {display:flex; align-items:center; gap:9px; min-width:0; flex:1 1 200px;}
        .ocrev-mark {flex:none; display:inline-flex; align-items:center; justify-content:center;
          width:21px; height:21px; border-radius:50%; color:#fff; background:#218151; font-weight:800;}
        .oc-revision[data-status="error"] .ocrev-mark {background:#b73530;}
        .ocrev-text {display:flex; align-items:baseline; gap:7px; flex-wrap:wrap; min-width:0;}
        .ocrev-text strong {font-size:12px; font-weight:800;}
        .ocrev-counts {font-size:11px; color:#63748a;}
        .ocrev-actions {display:flex; flex-wrap:wrap; align-items:center; gap:7px;}
        .ocrev-toggle {appearance:none; border:1px solid #d6e1ec; background:#f8fbff; color:#174a75;
          border-radius:7px; cursor:pointer; padding:6px 10px; font:inherit; font-weight:700; white-space:nowrap;}
        .ocrev-toggle:hover {background:#eef6ff;}
        .ocrev-toggle:focus-visible {outline:2px solid #1b6aac;outline-offset:2px;}
        .ocrev-toggle.ocrev-error-btn {border-color:#efd4d4;color:#993936;background:#fff5f4;}
        .ocrev-toggle.ocrev-warning-btn {border-color:#eed9a8;color:#82601e;background:#fffaf0;}
        .ocrev-toggle[hidden],.ocrev-detail[hidden] {display:none!important;}
        .ocrev-detail {border-top:1px solid #e0e7ef; padding:9px 13px 10px; background:#fcfdff;}
        .ocrev-detail.ocrev-errors {background:#fffafa;border-top-color:#f1d5d3;}
        .ocrev-detail.ocrev-warnings {background:#fffdf8;border-top-color:#f0e2c4;}
        .ocrev-detail-title {display:block; font-weight:800; margin:0 0 5px;}
        .oc-revision.ocrev-compact .ocrev-detail ul {margin:0; padding-left:20px; max-height:230px; overflow:auto;}
        .oc-revision.ocrev-compact .ocrev-detail li {padding:2px 0; overflow-wrap:anywhere; line-height:1.45;}
        @media(max-width:640px){
          .oc-revision.ocrev-compact {margin:0 10px 10px;}
          .ocrev-bar {padding:10px;}
          .ocrev-actions {width:100%;}
          .ocrev-toggle {flex:1 1 auto;}
        }`;
      document.head.appendChild(style);
    }
    panel.classList.add('ocrev-compact');
    panel.setAttribute('role','region');
    panel.setAttribute('aria-label','Resultado de la revisión de la orden');
    panel.removeAttribute('aria-live');
    const bar = document.createElement('div'); bar.className = 'ocrev-bar';
    const status = document.createElement('div'); status.className='ocrev-status';
    const mark = document.createElement('span'); mark.className='ocrev-mark'; mark.setAttribute('aria-hidden','true');
    const labels = document.createElement('div'); labels.className='ocrev-text';
    const counts = document.createElement('span'); counts.className='ocrev-counts';
    labels.append(title, counts); status.append(mark,labels);
    const actions=document.createElement('div'); actions.className='ocrev-actions';
    const errorBtn=document.createElement('button'); errorBtn.type='button'; errorBtn.className='ocrev-toggle ocrev-error-btn';
    errorBtn.setAttribute('aria-controls','ocrev-errores-detalle');
    const warningBtn=document.createElement('button'); warningBtn.type='button'; warningBtn.className='ocrev-toggle ocrev-warning-btn';
    warningBtn.setAttribute('aria-controls','ocrev-advertencias-detalle');
    actions.append(errorBtn, warningBtn); bar.append(status,actions);
    const errorsBox=document.createElement('div'); errorsBox.id='ocrev-errores-detalle'; errorsBox.className='ocrev-detail ocrev-errors';
    const errHeading=document.createElement('strong'); errHeading.className='ocrev-detail-title'; errHeading.textContent='Corrige estos errores antes de registrar:';
    errorsBox.append(errHeading,list);
    const warnsBox=document.createElement('div'); warnsBox.id='ocrev-advertencias-detalle'; warnsBox.className='ocrev-detail ocrev-warnings';
    const warnHeading=document.createElement('strong'); warnHeading.className='ocrev-detail-title';
    warnHeading.textContent='Estas advertencias son informativas y no bloquean el registro:';
    const warningList=document.createElement('ul'); warnsBox.append(warnHeading,warningList);
    panel.replaceChildren(bar, errorsBox, warnsBox);
    errorBtn.addEventListener('click',()=>{ocRevOpenErrors=!ocRevOpenErrors;ocRevRefreshToggles();});
    warningBtn.addEventListener('click',()=>{ocRevOpenWarnings=!ocRevOpenWarnings;ocRevRefreshToggles();});
    ocRevUi={mark,counts,errorBtn,warningBtn,errorsBox,warnsBox,warningList,errorsCount:0,warningsCount:0};
    return ocRevUi;
  }
  function ocRevRefreshToggles() {
    if (!ocRevUi) return;
    const u=ocRevUi;
    u.errorBtn.hidden=u.errorsCount===0;
    u.warningBtn.hidden=u.warningsCount===0;
    u.errorsBox.hidden=u.errorsCount===0 || !ocRevOpenErrors;
    u.warnsBox.hidden=u.warningsCount===0 || !ocRevOpenWarnings;
    u.errorBtn.setAttribute('aria-expanded',String(!u.errorsBox.hidden));
    u.warningBtn.setAttribute('aria-expanded',String(!u.warnsBox.hidden));
    u.errorBtn.textContent=(ocRevOpenErrors?'Ocultar':'Ver')+' errores ('+u.errorsCount+')';
    u.warningBtn.textContent=(ocRevOpenWarnings?'Ocultar':'Ver')+' advertencias ('+u.warningsCount+')';
  }
  function ocRevFill(target, messages) {
    const fragment=document.createDocumentFragment();
    messages.forEach(message=>{
      const li=document.createElement('li');
      li.textContent=message;
      fragment.appendChild(li);
    });
    target.replaceChildren(fragment);
  }
  function mostrar(resultado) {
    const {errors, warnings, items} = resultado;
    const u=ocRevInit();
    panel.hidden=false;
    panel.dataset.status=errors.length?'error':warnings.length?'warning':'ok';
    if (errors.length && !ocRevHadErrors) ocRevOpenErrors=true;
    ocRevHadErrors=errors.length>0;
    if (!errors.length) ocRevOpenErrors=false;
    if (!warnings.length) ocRevOpenWarnings=false;
    u.errorsCount=errors.length;
    u.warningsCount=warnings.length;
    u.mark.textContent=errors.length?'!':'✓';
    title.textContent=errors.length
      ? (errors.length===1?'1 error impide registrar':errors.length+' errores impiden registrar')
      : 'Orden lista para registrar';
    u.counts.textContent=errors.length
      ? (warnings.length?'· '+warnings.length+' advertencia(s) adicionales':'· Requiere corrección')
      : warnings.length?'· '+warnings.length+' advertencia(s) informativas':'· '+items+' artículo(s) sin observaciones';
    ocRevFill(list,errors);
    ocRevFill(u.warningList,warnings);
    ocRevRefreshToggles();
  }
  window.gydOcRevisionEvaluar = revisar;
  window.gydOcRevisionMostrar = mostrar;
  document.getElementById('ocRevisionBtn')?.addEventListener('click', () => {
    mostrar(revisar()); panel.scrollIntoView({behavior:'smooth',block:'nearest'});
  });
  // Una revisión visible se actualiza tras modificar algún campo.
  form.addEventListener('input', () => {if (!panel.hidden) mostrar(revisar());});
  form.addEventListener('change', () => {if (!panel.hidden) mostrar(revisar());});
})();
