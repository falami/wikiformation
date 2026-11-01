(() => {
  function init() {
    if (!window.TomSelect) return;
    const fields = [
      ['session-picker', 'bi-calendar3', 'Rechercher une session…'],
      ['template', 'bi-patch-check', 'Rechercher un modèle…'],
      ['evaluator', 'bi-person-badge', 'Rechercher un formateur…'],
    ];
    fields.forEach(([id, icon, placeholder]) => {
      const select = document.querySelector('.ev-assignment #' + id);
      if (!select || select.tomselect) return;
      const control = new TomSelect(select, {
        create: false,
        maxItems: 1,
        maxOptions: null,
        allowEmptyOption: false,
        placeholder,
        plugins: ['dropdown_input'],
        render: {
          option(data, escape) {
            return '<div class="ev-select-option"><span class="ev-select-icon"><i class="bi ' + icon + '" aria-hidden="true"></i></span><span>' + escape(data.text) + '</span></div>';
          },
          no_results() { return '<div class="no-results">Aucun résultat pour cette recherche.</div>'; },
        },
        onDropdownOpen() { this.dropdown.querySelector('input')?.focus(); },
      });
      control.wrapper.classList.add('ev-premium-select');
      control.control_input.setAttribute('aria-label', select.labels?.[0]?.textContent.trim() || placeholder);
      const search = control.dropdown.querySelector('input');
      if (search) { search.placeholder = placeholder; search.setAttribute('aria-label', placeholder); }
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once: true});
  else init();
})();
