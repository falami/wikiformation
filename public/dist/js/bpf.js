(() => {
  const form = document.querySelector('[data-bpf-form]');
  if (!form) return;
  const fields = new Map(Array.from(form.elements).map(el => [el.name.match(/\[([^\]]+)\]$/)?.[1], el]));
  let dirty = false;
  function sum(keys) {
    const values = keys.map(key => fields.get(key)?.value.trim());
    return values.some(v => !v || !/^\d+$/.test(v)) ? null : values.reduce((total, v) => total + Number(v), 0);
  }
  function refresh() {
    const products = 'abcdefgh'.split('').map(x => 'c_' + x);
    const totals = {c_2: sum(products), c_total: sum(['c_1', ...products, ...Array.from({length: 9}, (_, i) => 'c_' + (i + 3))])};
    for (const [prefix, suffixes] of Object.entries({f1: ['a','b','c','d','e'], f3: ['a','b','c','d','e','f'], f4: ['1','2','3','4','5','other']})) {
      for (const metric of ['count', 'hours']) totals[`${prefix}_${metric}`] = sum(suffixes.map(s => `${prefix}_${s}_${metric}`));
    }
    form.querySelectorAll('[data-bpf-total]').forEach(el => {const value = totals[el.dataset.bpfTotal]; el.textContent = value === null ? 'À compléter' : value.toLocaleString('fr-FR');});
  }
  function edited() {dirty = true; form.querySelector('[data-bpf-save-status]').textContent = 'Modifications non enregistrées'; refresh();}
  form.addEventListener('input', edited);
  form.addEventListener('change', edited);
  form.querySelectorAll('[data-bpf-zero]').forEach(button => button.addEventListener('click', () => {
    button.closest('[data-bpf-section]').querySelectorAll('input[type=number]').forEach(input => {if (!input.value) input.value = '0';}); edited();
  }));
  form.addEventListener('submit', () => {dirty = false;});
  window.addEventListener('beforeunload', event => {if (dirty) {event.preventDefault(); event.returnValue = '';}});
  form.querySelector('[data-bpf-export]')?.addEventListener('click', event => {if (dirty) {event.preventDefault(); form.querySelector('[data-bpf-save-status]').textContent = 'Enregistrez les modifications avant l’export.';}});
  refresh();
})();

// Searchable BPF classifications, matching the platform's representative selectors.
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('select.bpf-classification').forEach(select => {
    if (!window.TomSelect || select.tomselect) return;
    new TomSelect(select, {create: false, maxOptions: null, allowEmptyOption: false,
      plugins: {clear_button: {title: 'Effacer la sélection'}},
      render: {option(data, escape) { return `<div class="bpf-select-option"><strong>${escape(data.text)}</strong></div>`; },
        no_results() { return '<div class="no-results">Aucun résultat</div>'; }}});
  });
});

// Period navigation keeps the declaration's existing unsaved-change protection.
(() => {
  const start = document.getElementById('bpf-start');
  const end = document.getElementById('bpf-end');
  const year = document.getElementById('bpf-year');
  if (!start || !end || !year) return;
  const period = start.form;
  const locale = {
    firstDayOfWeek: 1,
    weekdays: {shorthand: ['Dim','Lun','Mar','Mer','Jeu','Ven','Sam'], longhand: ['Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi']},
    months: {shorthand: ['Jan','Fév','Mar','Avr','Mai','Juin','Juil','Août','Sep','Oct','Nov','Déc'], longhand: ['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre']},
    yearAriaLabel: 'Année', monthAriaLabel: 'Mois', scrollTitle: 'Défiler pour changer', toggleTitle: 'Cliquer pour changer'
  };
  function validate() {
    end._flatpickr?.altInput.setCustomValidity('');
    end.setCustomValidity(end.value < start.value ? 'La fin de l’exercice doit être postérieure ou égale au début.' : '');
    const calendarYear = start.value.slice(0,4);
    year.value = start.value === calendarYear + '-01-01' && end.value === calendarYear + '-12-31' ? calendarYear : '';
  }
  [start,end].forEach(input => {
    if (window.flatpickr) window.flatpickr(input, {
      locale, dateFormat: 'Y-m-d', altInput: true, altFormat: 'd/m/Y',
      disableMobile: true, allowInput: false,
      onReady(_, __, instance) {
        instance.calendarContainer.classList.add('bpf-calendar');
        instance.altInput.id = input.id + '-display';
        document.querySelector('label[for="' + input.id + '"]').htmlFor = instance.altInput.id;
        instance.altInput.setAttribute('aria-label', input === start ? 'Début de l’exercice' : 'Fin de l’exercice');
      },
      onChange: validate
    });
    input.addEventListener('change', validate);
  });
  function setDate(input, date) {
    if (input._flatpickr) input._flatpickr.setDate(date, false, 'Y-m-d');
    else input.value = date;
  }
  function openYear(value) {
    setDate(start, value + '-01-01');
    setDate(end, value + '-12-31');
    validate();
    period.requestSubmit();
  }
  year.addEventListener('change', () => {if (year.value) openYear(year.value);});
  document.querySelector('[data-bpf-current]').addEventListener('click', event => openYear(event.currentTarget.dataset.bpfCurrent));
  document.querySelectorAll('[data-bpf-shift]').forEach(button => button.addEventListener('click', () => {
    // Preserve non-calendar accounting periods, clamping leap days.
    const shift = Number(button.dataset.bpfShift);
    [start,end].forEach(input => {
      const [y,m,d] = input.value.split('-').map(Number);
      const targetYear = y + shift;
      const day = Math.min(d, new Date(targetYear, m, 0).getDate());
      setDate(input, targetYear + '-' + String(m).padStart(2,'0') + '-' + String(day).padStart(2,'0'));
    });
    validate();
    period.requestSubmit();
  }));
  period.addEventListener('submit', event => {
    validate();
    if (end.validationMessage) {
      event.preventDefault();
      if (end._flatpickr) end._flatpickr.altInput.setCustomValidity(end.validationMessage);
      (end._flatpickr?.altInput || end).reportValidity();
    }
  });
  end.addEventListener('change', () => end._flatpickr?.altInput.setCustomValidity(''));
  validate();
})();
