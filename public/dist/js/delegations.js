(() => {
  'use strict';
  function initialize() {
    if (!window.TomSelect) return;
    document.querySelectorAll('select.delegation-select').forEach(select => {
      if (select.tomselect) return;
      const optionalChoice = select.querySelector('option[value=""]');
      const options = Array.from(select.options).filter(option => option.value !== '').map(option => ({
        value: option.value,
        text: option.textContent.trim(),
        title: option.dataset.title || option.textContent.trim(),
        detail: option.dataset.detail || '',
        badge: option.dataset.badge || ''
      }));
      new TomSelect(select, {
        options,
        create: false,
        plugins: optionalChoice ? {clear_button: {title: 'Effacer la sélection'}} : [],
        placeholder: optionalChoice ? optionalChoice.textContent : 'Rechercher…',
        allowEmptyOption: false,
        closeAfterSelect: true,
        maxOptions: null,
        searchField: ['text', 'title', 'detail', 'badge'],
        render: {
          option(data, escape) {
            return `<div class="delegation-option"><div class="delegation-option-copy"><div class="delegation-option-title">${escape(data.title || data.text)}</div>${data.detail ? `<div class="delegation-option-detail">${escape(data.detail)}</div>` : ''}</div>${data.badge ? `<span class="delegation-option-badge">${escape(data.badge)}</span>` : ''}</div>`;
          },
          item(data, escape) {
            return `<div>${escape(data.title || data.text)}${data.badge ? `<span class="delegation-option-badge">${escape(data.badge)}</span>` : ''}</div>`;
          },
          no_results() {
            return '<div class="no-results">Aucun résultat pour cette recherche.</div>';
          }
        }
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
  else initialize();
})();
