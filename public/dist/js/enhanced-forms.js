/* Shared, opt-in controls for new forms: add data-enhanced-form to their container. */
(() => {
  const french = {
    firstDayOfWeek: 1,
    weekdays: { shorthand: ['dim','lun','mar','mer','jeu','ven','sam'], longhand: ['dimanche','lundi','mardi','mercredi','jeudi','vendredi','samedi'] },
    months: { shorthand: ['janv','févr','mars','avr','mai','juin','juil','août','sept','oct','nov','déc'], longhand: ['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'] },
    rangeSeparator: ' au ', scrollTitle: 'Défiler pour augmenter', toggleTitle: 'Cliquer pour basculer',
    yearAriaLabel: 'Année', monthAriaLabel: 'Mois', time_24hr: true
  };
  function init(root = document) {
    root.querySelectorAll('select').forEach(select => {
      if (!select.closest('[data-enhanced-form]') || select.tomselect || !window.TomSelect) return;
      const disabled = select.matches(':disabled');
      const control = new TomSelect(select, {
        create: false,
        plugins: select.multiple ? ['remove_button'] : [],
        maxOptions: null,
        hidePlaceholder: true,
        render: { no_results: () => '<div class="no-results">Aucun résultat</div>' }
      });
      if (disabled) control.disable();
    });
    root.querySelectorAll('input[type="date"], input[data-datepicker]').forEach(input => {
      if (!input.closest('[data-enhanced-form]') || input._flatpickr || !window.flatpickr) return;
      const disabled = input.matches(':disabled');
      const picker = flatpickr(input, {
        locale: french, dateFormat: 'Y-m-d', altInput: true, altFormat: 'd/m/Y',
        ariaDateFormat: 'j F Y', allowInput: true, disableMobile: true,
        minDate: input.min || undefined, maxDate: input.max || undefined,
        clickOpens: !disabled && !input.readOnly,
        onReady: (_, __, instance) => {
          instance.calendarContainer.classList.add('wiki-datepicker');
        }
      });
      if (picker.altInput) {
        picker.altInput.disabled = disabled;
        picker.altInput.readOnly = disabled || input.readOnly;
        picker.altInput.placeholder = 'jj/mm/aaaa';
        // Keep the visible control associated with the original label.
        if (input.id) {
          const label = Array.from(document.querySelectorAll('label')).find(label => label.htmlFor === input.id);
          if (label) {
            picker.altInput.id = input.id + '-display';
            label.htmlFor = picker.altInput.id;
          }
        }
      }
    });
  }
  window.WikiFormationForms = { init };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => init());
  else init();
})();
