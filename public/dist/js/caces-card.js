(() => {
  'use strict';
  const button = document.querySelector('[data-print]');
  if (!button) return;
  button.addEventListener('click', async () => {
    button.disabled = true;
    document.querySelector('[data-card-print-error]')?.remove();
    try {
      await document.fonts.ready;
      await Promise.all([...document.querySelectorAll('.cc-page img')].map(img => img.decode()));
      window.print();
    } catch (_) {
      const error = document.createElement('p');
      error.className = 'cc-print-error cx-no-print';
      error.dataset.cardPrintError = '';
      error.setAttribute('role', 'alert');
      error.textContent = 'Une photo, un logo ou une signature n’a pas pu être chargé. Rechargez la page avant d’imprimer pour obtenir un document complet.';
      button.closest('.cx-actions').after(error);
    } finally {
      button.disabled = false;
    }
  });
})();
