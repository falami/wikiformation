(() => {
  'use strict';
  const form = document.getElementById('internal-usage-checkout');
  if (!form) return;
  const button = form.querySelector('button[type="submit"]');
  const status = document.getElementById('payment-status');
  const label = button.textContent;
  let pending = false;
  form.addEventListener('submit', event => {
    if (pending) {
      event.preventDefault();
      return;
    }
    if (event.defaultPrevented || !form.checkValidity()) return;
    pending = true;
    button.disabled = true;
    button.textContent = 'Connexion à Stripe…';
    form.setAttribute('aria-busy', 'true');
    status.hidden = false;
    status.textContent = 'Préparation de votre page de paiement sécurisée. Veuillez patienter.';
    // Keep the native POST and server redirect, including CSRF and consent checks.
  });
  window.addEventListener('pageshow', () => {
    pending = false;
    button.disabled = false;
    button.textContent = label;
    form.removeAttribute('aria-busy');
    status.hidden = true;
  });
  const feedback = document.getElementById('payment-feedback');
  if (feedback?.querySelector('[role="alert"]')) {
    feedback.focus({preventScroll: true});
    feedback.scrollIntoView({block: 'center'});
  }
})();
