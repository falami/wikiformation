(() => {
  const modal = document.getElementById('formationQuickCreate');
  if (!modal || modal.dataset.ready) return;
  modal.dataset.ready = 'true';
  const body = modal.querySelector('.modal-body');
  let target;
  let loading = false;
  const showError = () => {
    const alert = document.createElement('div');
    alert.className = 'alert alert-danger';
    alert.setAttribute('role', 'alert');
    alert.textContent = 'La création n’a pas pu être confirmée. Vérifiez votre connexion puis réessayez.';
    body.prepend(alert);
  };
  document.querySelectorAll('[data-quick-create-url]').forEach(button => button.addEventListener('click', async () => {
    target = document.getElementById(button.dataset.quickCreateTarget);
    modal.querySelector('h2').textContent = button.dataset.quickCreateTitle;
    body.textContent = 'Chargement…';
    bootstrap.Modal.getOrCreateInstance(modal).show();
    try {
      const response = await fetch(button.dataset.quickCreateUrl, {headers: {'Accept': 'application/json'}});
      if (!response.ok) throw new Error();
      const data = await response.json();
      body.innerHTML = data.html;
    } catch { body.textContent = ''; showError(); }
  }));
  modal.addEventListener('hide.bs.modal', event => { if (loading) event.preventDefault(); });
  body.addEventListener('submit', async event => {
    const form = event.target.closest('[data-quick-create-form]');
    if (!form) return;
    event.preventDefault();
    if (loading) return;
    loading = true;
    const submit = form.querySelector('[type="submit"]');
    submit.disabled = true;
    try {
      const response = await fetch(form.action, {method: 'POST', body: new FormData(form), headers: {'Accept': 'application/json'}});
      const data = await response.json();
      if (response.status === 422 && data.html) {
        body.innerHTML = data.html;
        body.querySelector('.is-invalid, input, select')?.focus();
      } else if (response.ok && data.id) {
        const value = String(data.id);
        if (target.tomselect) {
          target.tomselect.addOption({value, text: data.label});
          target.tomselect.setValue(value);
        } else {
          target.add(new Option(data.label, value, true, true));
          target.dispatchEvent(new Event('change', {bubbles: true}));
        }
        loading = false;
        bootstrap.Modal.getOrCreateInstance(modal).hide();
        target.tomselect ? target.tomselect.focus() : target.focus();
      } else throw new Error();
    } catch { showError(); }
    finally { loading = false; submit.disabled = false; }
  });
})();
