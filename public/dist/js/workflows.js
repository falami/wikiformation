(() => {
  'use strict';
  document.querySelectorAll('.js-workflow-select').forEach(el => {
    if (window.TomSelect && !el.tomselect) new TomSelect(el, {create: false, allowEmptyOption: true, maxOptions: 250, dropdownParent: 'body', onInitialize() { this.dropdown.classList.add('wf-select-dropdown'); }});
  });
  const labelDateInput = (_dates, _value, instance) => {
    const label = instance.input.id && document.querySelector(`label[for="${instance.input.id}"]`);
    if (instance.altInput && label) instance.altInput.setAttribute('aria-label', label.textContent.trim());
  };
  if (window.flatpickr) {
    document.querySelectorAll('.js-certificate-datepicker').forEach(el => flatpickr(el, {locale:'fr',dateFormat:'Y-m-d',altInput:true,altFormat:'d/m/Y',maxDate:'today',onReady:labelDateInput}));
    document.querySelectorAll('.js-workflow-dates').forEach(el => flatpickr(el, {mode: 'multiple', locale: 'fr', dateFormat: 'Y-m-d', altInput: true, altFormat: 'd/m/Y', conjunction: ', ', minDate: 'today', onReady: labelDateInput}));
    document.querySelectorAll('.js-workflow-time').forEach(el => flatpickr(el, {enableTime: true, noCalendar: true, dateFormat: 'H:i', time_24hr: true, minuteIncrement: 15, allowInput: true}));
  }
  const form = document.querySelector('[data-workflow-form]');
  if (form) {
    const preview = form.querySelector('[data-time-preview]');
    const update = () => {
      const start = form.querySelector('[name$="[startTime]"]').value;
      const hours = Number(form.querySelector('[name$="[hours]"]').value.replace(',', '.'));
      if (!/^\d{2}:\d{2}$/.test(start) || !Number.isFinite(hours) || hours <= 0) return;
      const [h, m] = start.split(':').map(Number), pause = hours > 4 ? 90 : 0;
      const finish = h * 60 + m + Math.round(hours * 60) + pause;
      preview.textContent = finish >= 1440 ? 'Le créneau doit se terminer le même jour.' : `${start} → ${String(Math.floor(finish / 60)).padStart(2,'0')}:${String(finish % 60).padStart(2,'0')} · ${hours.toLocaleString('fr-FR')} h de cours · ${pause ? '1 h 30 de pause' : 'sans pause déduite'}`;
    };
    form.addEventListener('input', update); form.addEventListener('change', update); update();
    form.addEventListener('submit', () => { const b = form.querySelector('[type="submit"]'); if (b) {b.disabled = true; b.textContent = 'Préparation du dossier…';} });
  }
  document.querySelector('[data-copy-workflow-link]')?.addEventListener('click', async e => {
    const input = document.getElementById('portal-link');
    try { await navigator.clipboard.writeText(input.value); e.currentTarget.textContent = 'Lien copié'; } catch { input.focus(); input.select(); }
  });
  const qr = document.querySelector('[data-workflow-qr]');
  if (qr && window.QRCode) new QRCode(qr, {text: qr.dataset.workflowQr, width: 280, height: 280, correctLevel: QRCode.CorrectLevel.M});
  document.querySelector('[data-workflow-print]')?.addEventListener('click', () => window.print());
})();
