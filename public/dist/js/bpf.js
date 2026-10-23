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

document.querySelectorAll('[data-bpf-field-link]').forEach(link => link.addEventListener('click', event => {
  const field = document.getElementById(link.hash.slice(1));
  if (!field) return;
  event.preventDefault();
  for (let parent = field.parentElement; parent; parent = parent.parentElement) {
    if (parent.tagName === 'DETAILS') parent.open = true;
  }
  const target = field.tomselect?.control_input || field._flatpickr?.altInput || field;
  target.scrollIntoView({block: 'center', behavior: 'smooth'});
  if (!target.matches('input, select, textarea, button, a, [tabindex]')) target.setAttribute('tabindex', '-1');
  target.focus({preventScroll: true});
  document.querySelectorAll('.bpf-error-target').forEach(item => item.classList.remove('bpf-error-target'));
  const highlight = field.tomselect?.wrapper || target;
  highlight.classList.add('bpf-error-target');
  setTimeout(() => highlight.classList.remove('bpf-error-target'), 4000);
}));

// Keep incomplete declaration fields visible, including enhanced classification selects.
document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('[data-bpf-form]');
  if (!form) return;
  const tracked = new Set(form.querySelectorAll('select.bpf-classification, input[required], select[required], textarea[required]'));
  document.querySelectorAll('#bpf-controls [data-bpf-field-link]').forEach(link => {
    const field = document.getElementById(link.hash.slice(1));
    if (field && form.contains(field) && field.matches('input, select, textarea')) tracked.add(field);
  });
  function update() {
    let classificationCount = 0;
    tracked.forEach(field => {
      const missing = !field.disabled && !String(field.value ?? '').trim();
      field.classList.toggle('bpf-missing-field', missing);
      const wrapper = field.tomselect?.wrapper;
      wrapper?.classList.toggle('bpf-missing-field', missing);
      const target = field.tomselect?.control_input || field;
      const hintId = field.id + '-missing-hint';
      let hint = document.getElementById(hintId);
      if (!hint) {
        hint = document.createElement('span'); hint.id = hintId;
        hint.className = 'bpf-missing-hint'; hint.textContent = 'À compléter';
        (wrapper || field).insertAdjacentElement('afterend', hint);
        const describedBy = target.getAttribute('aria-describedby') || '';
        target.setAttribute('aria-describedby', (describedBy + ' ' + hintId).trim());
      }
      hint.hidden = !missing;
      if (missing && field.matches('.bpf-classification')) classificationCount++;
    });
    const summary = form.querySelector('.bpf-classifications > summary');
    if (summary) {
      let badge = summary.querySelector('[data-bpf-missing-count]');
      if (!badge) {badge = document.createElement('span'); badge.dataset.bpfMissingCount = ''; badge.className = 'bpf-missing-hint'; summary.append(badge);}
      badge.hidden = classificationCount === 0;
      badge.textContent = classificationCount + ' correspondance(s) à compléter';
    }
  }
  form.addEventListener('input', update);
  form.addEventListener('change', update);
  form.querySelectorAll('[data-bpf-zero]').forEach(button => button.addEventListener('click', update));
  update();
});
