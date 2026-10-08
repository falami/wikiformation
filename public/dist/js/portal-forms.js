(() => {
  'use strict';
  function enhance(root) {
    if (window.TomSelect) root.querySelectorAll('select:not([disabled])').forEach(select => {
      if (select.tomselect) return;
      const placeholder = select.querySelector('option[value=""]');
      new TomSelect(select, {
        create: false, maxOptions: null, closeAfterSelect: !select.multiple,
        plugins: select.multiple ? {remove_button: {title: 'Retirer'}} : (placeholder ? {clear_button: {title: 'Effacer la sélection'}} : []),
        placeholder: placeholder?.textContent || 'Rechercher…', allowEmptyOption: false,
        render: {
          option(data, escape) { return `<div><div class="pf-option-title">${escape(data.text)}</div>${data.detail ? `<div class="pf-option-detail">${escape(data.detail)}</div>` : ''}</div>`; },
          no_results() { return '<div class="no-results">Aucun résultat pour cette recherche.</div>'; }
        }
      });
    });
    if (window.flatpickr) root.querySelectorAll('input[type="date"],input[type="datetime-local"]').forEach(input => {
      if (input._flatpickr) return;
      const timed = input.type === 'datetime-local';
      const originalId = input.id;
      const picker = flatpickr(input, {
        locale: {...flatpickr.l10ns.fr, monthAriaLabel: 'Mois', yearAriaLabel: 'Année', hourAriaLabel: 'Heure', minuteAriaLabel: 'Minute'}, ariaDateFormat: 'j F Y', enableTime: timed, time_24hr: true, disableMobile: true,
        dateFormat: timed ? 'Y-m-d\\TH:i' : 'Y-m-d', altInput: true,
        altFormat: timed ? 'd/m/Y à H:i' : 'd/m/Y', allowInput: true,
        minDate: input.min || undefined, maxDate: input.max || undefined,
        onReady(_, __, instance) { instance.calendarContainer.classList.add('pf-calendar'); },
        onChange() { input.dispatchEvent(new Event('change', {bubbles: true})); }
      });
      // Keep the visible calendar field connected to its label and help/error text.
      input.id = originalId + '_value';
      picker.altInput.id = originalId;
      picker.altInput.placeholder = timed ? 'Choisir une date et une heure' : 'Choisir une date';
      ['aria-describedby','aria-invalid','aria-required'].forEach(attr => {
        if (input.hasAttribute(attr)) picker.altInput.setAttribute(attr, input.getAttribute(attr));
      });
      picker.altInput.required = input.required;
    });
  }
  function initialize() {
    document.querySelectorAll('[data-portal-form]').forEach(form => {
      if (form.dataset.enhanced) return;
      form.dataset.enhanced = 'true';
      enhance(form);
      const completion = () => {
        const required = [...form.querySelectorAll('[required][name]')];
        const filled = required.filter(input => input.type === 'checkbox' ? input.checked : input.value.trim() !== '').length;
        const label = form.querySelector('[data-completion]');
        if (label) label.textContent = required.length ? `${filled} / ${required.length} champs obligatoires renseignés` : 'Aucun champ obligatoire restant.';
        const bar = form.querySelector('[data-completion-bar]');
        if (bar) bar.style.width = `${required.length ? filled / required.length * 100 : 100}%`;
      };
      form.addEventListener('input', completion);
      form.addEventListener('change', completion);
      completion();
      const lines = form.querySelector('#document-lines');
      if (!lines) return;
      // Submitted collections can contain gaps after deleting a line.
      const existingIndices = [...lines.querySelectorAll('[name]')].map(input => {
        const match = input.name.match(/\[lines\]\[(\d+)\]/);
        return match ? Number(match[1]) + 1 : 0;
      });
      lines.dataset.index = String(Math.max(Number(lines.dataset.index), ...existingIndices));
      const numberLines = () => {
        lines.querySelectorAll('[data-line-number]').forEach((node, index) => {node.textContent = String(index + 1);});
        form.querySelector('[data-lines-empty]').hidden = lines.children.length !== 0;
      };
      form.querySelector('#add-document-line').addEventListener('click', () => {
        const index = Number(lines.dataset.index);
        lines.dataset.index = String(index + 1);
        const template = document.createElement('template');
        template.innerHTML = lines.dataset.prototype.replaceAll('__name__', String(index));
        const row = template.content.firstElementChild;
        lines.append(row);
        enhance(row);
        numberLines();
        row.querySelector('input')?.focus();
      });
      lines.addEventListener('click', event => {
        const button = event.target.closest('[data-remove-line]');
        if (!button) return;
        const row = button.closest('.document-line');
        row.querySelectorAll('select').forEach(select => select.tomselect?.destroy());
        row.querySelectorAll('input').forEach(input => input._flatpickr?.destroy());
        row.remove();
        numberLines();
        form.querySelector('#add-document-line').focus();
      });
      numberLines();
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
  else initialize();
})();
