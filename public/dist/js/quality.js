(() => {
  document.querySelectorAll('select.quality-select').forEach(select => {
    if (!window.TomSelect || select.tomselect) return;
    new TomSelect(select, {create:false, maxOptions:null, render:{option(data, escape) {return `<div><strong>${escape(data.text)}</strong></div>`;}, no_results(){return '<div class="no-results">Aucun résultat</div>';}}});
  });
  document.querySelectorAll('input.quality-date').forEach(input => {
    if (window.flatpickr && !input._flatpickr) window.flatpickr(input, {
      locale:'fr', dateFormat:'Y-m-d', altInput:true, altFormat:'d/m/Y', allowInput:true, disableMobile:true,
      onReady(_dates, _value, picker) {
        if (!picker.altInput) return;
        picker.altInput.id = `${input.id}-display`;
        document.querySelectorAll(`label[for="${input.id}"]`).forEach(label => { label.htmlFor = picker.altInput.id; });
        ['aria-describedby', 'aria-invalid'].forEach(name => {
          if (input.hasAttribute(name)) picker.altInput.setAttribute(name, input.getAttribute(name));
        });
        picker.altInput.setAttribute('aria-required', String(input.required));
      }
    });
  });
})();
