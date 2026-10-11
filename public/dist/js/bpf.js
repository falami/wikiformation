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
