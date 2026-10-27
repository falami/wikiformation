(() => {
  'use strict';
  if (window.WikiGoogleServices) return;
  window.WikiGoogleServices = true;
  document.addEventListener('DOMContentLoaded', () => {
    const legacy = document.querySelector('script[data-google-maps-src]');
    const form = document.querySelector('[data-client-address]')?.closest('form')
      || document.querySelector('input[name$="[adresse]"]')?.form;
    if (!legacy && !form) return;
    const notice = document.createElement('section');
    notice.className = 'alert alert-light border rounded-3 my-3';
    notice.setAttribute('aria-label', 'Service de localisation facultatif');
    notice.innerHTML = '<p class="mb-2"><strong>Recherche d’adresses et cartes Google</strong><br>En activant ce service pour cette page, Google reçoit votre adresse IP et les adresses recherchées ou affichées. Vous pouvez continuer à saisir les adresses manuellement.</p><button type="button" class="btn btn-sm btn-outline-dark" data-enable>Activer Google pour cette page</button> <a href="https://policies.google.com/privacy?hl=fr" target="_blank" rel="noopener noreferrer">Confidentialité Google</a><p class="small mb-0 mt-2" data-status role="status"></p>';
    (form || legacy.parentElement).prepend(notice);
    const button = notice.querySelector('[data-enable]');
    button.addEventListener('click', () => {
      button.disabled = true;
      const activate = () => {
        notice.querySelector('[data-status]').textContent = 'Service activé pour cette page. Rechargez la page pour le désactiver.';
        document.dispatchEvent(new CustomEvent('wiki:enable-google-maps'));
      };
      if (!legacy) { activate(); return; }
      let url;
      try {
        url = new URL(legacy.dataset.googleMapsSrc);
        if (url.protocol !== 'https:' || url.hostname !== 'maps.googleapis.com' || url.pathname !== '/maps/api/js' || url.username || url.password) throw new Error();
      } catch (_) {
        notice.querySelector('[data-status]').textContent = 'Le service est indisponible. La saisie manuelle reste possible.';
        return;
      }
      const script = document.createElement('script');
      script.src = url.href;
      script.async = true;
      script.referrerPolicy = 'strict-origin';
      script.onload = activate;
      script.onerror = () => {
        notice.querySelector('[data-status]').textContent = 'Google est indisponible. La saisie manuelle reste possible.';
        script.remove(); button.disabled = false;
      };
      document.head.append(script);
    });
  });
})();
