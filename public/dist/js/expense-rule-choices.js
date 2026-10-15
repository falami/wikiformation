(() => {
  document.querySelectorAll('[data-rule-choice]').forEach(form => {
    const modal = form.closest('.modal');
    let busy = false;
    modal.addEventListener('shown.bs.modal', () => form.elements.name.focus());
    modal.addEventListener('hide.bs.modal', event => { if (busy) event.preventDefault(); });
    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (busy || !form.reportValidity()) return;
      const error = form.querySelector('[data-choice-error]');
      const button = form.querySelector('[type=submit]');
      error.classList.add('d-none');
      busy = true; button.disabled = true; button.textContent = 'Création en cours…';
      try {
        const response = await fetch(form.action, {method:'POST',body:new FormData(form),headers:{'X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'});
        const result = await response.json();
        if (!response.ok || !result.id) throw new Error(result.message || 'La création n’a pas abouti. Réessayez.');
        const select = document.getElementById(form.dataset.target);
        const value = String(result.id);
        if (select.tomselect) {
          select.tomselect.addOption({value,text:result.text});
          select.tomselect.setValue(value);
        } else {
          if (![...select.options].some(option=>option.value===value)) select.add(new Option(result.text,value));
          select.value=value;
          select.dispatchEvent(new Event('change',{bubbles:true}));
        }
        busy=false;
        bootstrap.Modal.getInstance(modal)?.hide();
        form.reset();
        (select.tomselect?.control_input || select).focus();
      } catch (e) {
        error.textContent=e.message || 'Connexion interrompue. Réessayez avec le même nom.';
        error.classList.remove('d-none');
      } finally {
        busy=false;button.disabled=false;button.textContent='Créer et sélectionner';
      }
    });
  });
})();
