(() => {
  'use strict';
  let apiPromise;
  const loadApi = () => {
    if (window.turnstile) return Promise.resolve(window.turnstile);
    if (apiPromise) return apiPromise;
    apiPromise = new Promise((resolve, reject) => {
      const script = document.createElement('script');
      const timeout = setTimeout(() => fail(), 20000);
      const fail = () => {
        clearTimeout(timeout);
        script.remove();
        apiPromise = null;
        reject(new Error('Turnstile unavailable'));
      };
      window.contactTurnstileReady = () => {
        clearTimeout(timeout);
        resolve(window.turnstile);
      };
      script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=contactTurnstileReady';
      script.async = true;
      script.onerror = fail;
      document.head.appendChild(script);
    });
    return apiPromise;
  };

  document.querySelectorAll('[data-contact-captcha]').forEach(container => {
    const form = container.closest('form');
    const token = container.querySelector('[data-contact-token]');
    const status = container.querySelector('[data-contact-captcha-status]');
    const retry = container.querySelector('[data-contact-captcha-retry]');
    const submits = [...form.querySelectorAll('[type="submit"]')];
    let widget, loading = false, verifiedAt = 0;
    const invalidate = (message, canRetry = false) => {
      token.value = '';
      verifiedAt = 0;
      submits.forEach(button => { button.disabled = true; });
      status.textContent = message;
      retry.hidden = !canRetry;
    };
    invalidate(token.dataset.sitekey ? 'Protection anti-spam active. La vérification démarrera pendant votre saisie.' :
      'Le formulaire est temporairement indisponible. Veuillez utiliser l’adresse e-mail de contact.');
    const start = async () => {
      if (!token.dataset.sitekey || loading || widget !== undefined) return;
      loading = true;
      invalidate('Vérification de sécurité en cours…');
      try {
        const api = await loadApi();
        widget = api.render(container.querySelector('[data-contact-challenge]'), {
          sitekey: token.dataset.sitekey,
          action: 'public_contact',
          cData: token.dataset.context,
          appearance: 'interaction-only',
          size: 'flexible',
          theme: 'auto',
          language: document.documentElement.lang || 'fr',
          'response-field': false,
          callback: value => {
            token.value = value;
            verifiedAt = Date.now();
            submits.forEach(button => { button.disabled = false; });
            status.textContent = 'Vérification réussie. Vous pouvez envoyer votre message.';
            retry.hidden = true;
          },
          'expired-callback': () => invalidate('Vérification expirée. Veuillez la renouveler.', true),
          'timeout-callback': () => invalidate('La vérification a pris trop de temps. Réessayez.', true),
          'error-callback': () => {
            invalidate('Vérification indisponible. Vérifiez votre connexion puis réessayez.', true);
            return true;
          }
        });
      } catch (_) {
        invalidate('Impossible de charger la vérification. Vérifiez votre connexion ou votre bloqueur de scripts.', true);
      } finally { loading = false; }
    };
    retry.addEventListener('click', () => {
      invalidate('Vérification de sécurité en cours…');
      if (widget !== undefined && window.turnstile) window.turnstile.reset(widget);
      else start();
    });
    form.addEventListener('focusin', start);
    form.addEventListener('pointerdown', start);
    form.addEventListener('submit', event => {
      if (!token.value || Date.now() - verifiedAt >= 290000) {
        event.preventDefault();
        invalidate('Veuillez terminer la vérification de sécurité avant l’envoi.', true);
        if (widget === undefined) start();
        else window.turnstile.reset(widget);
      }
    });
    window.addEventListener('pageshow', event => {
      if (event.persisted) {
        invalidate('Veuillez renouveler la vérification avant un nouvel envoi.', true);
        if (widget !== undefined && window.turnstile) window.turnstile.reset(widget);
      }
    });
  });
})();
