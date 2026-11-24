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
        const base = Number(row.dataset.catalogCost);
        if (Number.isFinite(base) && base > 0 && amount > 0 && Math.abs(amount - base) / base >= 0.20) {
          const delta = ((amount - base) / base * 100).toFixed(1);
          warn(prefix + ': costo ' + money(amount) + ' vs. referencia ' + money(base) + ' (' + (Number(delta) > 0 ? '+' : '') + delta + '%).');
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
  function mostrar(resultado) {
    const {errors, warnings, items} = resultado;
    panel.hidden = false;
    panel.dataset.status = errors.length ? 'error' : warnings.length ? 'warning' : 'ok';
    title.textContent = errors.length
      ? `${errors.length} error(es) que debes corregir · ${warnings.length} advertencia(s)`
      : warnings.length ? `${warnings.length} advertencia(s) · puedes continuar` : `${items} artículo(s) · orden lista para registrar`;
    list.replaceChildren();
    const problems = [...errors.map(t => '⛔ ' + t), ...warnings.map(t => '⚠ ' + t)];
    problems.slice(0, 12).forEach(message => {
      const li = document.createElement('li');
      li.textContent = message;
      list.append(li);
    });
    if (problems.length > 12) {
      const li = document.createElement('li');
      li.textContent = `Y ${problems.length - 12} observaciones más. Revisa los artículos.`;
      list.append(li);
    }
    if (!problems.length) { const li = document.createElement('li'); li.textContent = 'Sin inconsistencias detectadas en la captura.'; list.append(li); }
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
