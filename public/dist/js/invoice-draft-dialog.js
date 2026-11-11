(() => {
  const init = () => {
    const dialog = document.getElementById('draft-delete-dialog');
    if (!dialog || dialog.dataset.initialized) return;
    dialog.dataset.initialized = 'true';
    const confirm = dialog.querySelector('[data-confirm]');
    let pendingForm = null;
    let trigger = null;
    document.querySelectorAll('.iw-delete-action').forEach(form => {
      form.addEventListener('submit', event => {
        if (form.dataset.confirmed === 'true') return;
        event.preventDefault();
        pendingForm = form;
        trigger = event.submitter || form.querySelector('button');
        dialog.querySelector('[data-reference]').textContent = form.dataset.draftReference || '';
        dialog.querySelector('[data-client]').textContent = form.dataset.draftClient || '';
        confirm.disabled = false;
        dialog.showModal();
      });
    });
    dialog.querySelectorAll('[data-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('close', () => {
      pendingForm = null;
      trigger?.focus();
    });
    confirm.addEventListener('click', () => {
      if (!pendingForm || confirm.disabled) return;
      confirm.disabled = true;
      pendingForm.dataset.confirmed = 'true';
      pendingForm.requestSubmit();
    });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
  document.addEventListener('turbo:load', init);
})();
