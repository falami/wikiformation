(() => {
  'use strict';
  const element = document.getElementById('invoiceSendModal');
  if (!element || !window.bootstrap?.Modal) return;
  const modal = bootstrap.Modal.getOrCreateInstance(element);
  const confirm = element.querySelector('[data-send-confirm]');
  let pending = null, trigger = null, sending = false;
  document.addEventListener('submit', event => {
    const form = event.target.closest('[data-invoice-send]');
    if (!form) return;
    event.preventDefault();
    if (sending) return;
    pending = form;
    trigger = event.submitter;
    const recipient = (form.dataset.recipient || '').trim();
    element.querySelector('[data-send-number]').textContent = form.dataset.number;
    element.querySelector('[data-send-recipient]').textContent = recipient || 'Non renseignée';
    element.querySelector('[data-send-preview]').href = form.dataset.preview;
    element.querySelector('[data-send-error]').hidden = !!recipient;
    confirm.disabled = !recipient;
    modal.show();
  });
  confirm.addEventListener('click', () => {
    if (!pending || sending || confirm.disabled) return;
    sending = true;
    confirm.disabled = true;
    confirm.textContent = 'Envoi en cours…';
    element.querySelector('[data-send-status]').textContent = 'Envoi du document en cours.';
    element.querySelectorAll('[data-bs-dismiss]').forEach(button => button.disabled = true);
    HTMLFormElement.prototype.submit.call(pending);
  });
  element.addEventListener('hide.bs.modal', event => { if (sending) event.preventDefault(); });
  element.addEventListener('hidden.bs.modal', () => { pending = null; trigger?.focus(); });
})();
