(() => {
  'use strict';
  if (window.WikiGoogleServices) return;
  window.WikiGoogleServices = true;
  const boot = document.currentScript;
  const key = 'wiki.google.maps.v1.' + (boot?.dataset.googleUser || 'anonymous');
  const preferencesUrl = boot?.dataset.googlePreferencesUrl || '';
  const lifetime = 180 * 24 * 60 * 60 * 1000;
  function read() {
    try {
      const value = JSON.parse(localStorage.getItem(key));
      return value && value.expires > Date.now() && ['allow', 'deny'].includes(value.choice) ? value.choice : null;
    } catch (_) { return null; }
  }
  function save(choice) {
    try { localStorage.setItem(key, JSON.stringify({choice, expires: Date.now() + lifetime})); return true; }
    catch (_) { return false; }
  }
  document.addEventListener('DOMContentLoaded', () => {
    const settings = document.querySelector('[data-google-preferences]');
    const legacy = document.querySelector('script[data-google-maps-src]');
    const form = document.querySelector('[data-client-address]')?.closest('form')
      || document.querySelector('input[name$="[adresse]"]')?.form;
    let choice = read(), loaded = false, loading = false;
    let notice;
    if (legacy || form) {
      notice = document.createElement('section');
      notice.className = 'alert alert-light border rounded-3 my-3';
      notice.innerHTML = '<p><strong>Recherche d’adresses et cartes Google</strong><br>Google reçoit votre adresse IP et les adresses recherchées ou affichées. La saisie manuelle reste possible.</p><button type="button" class="btn btn-sm btn-outline-dark" data-google-choice="allow">Autoriser sur toute la plateforme</button> <button type="button" class="btn btn-sm btn-outline-secondary" data-google-choice="deny">Continuer sans Google</button> <a data-preferences>Préférences Google</a><p data-google-status role="status" class="small mt-2 mb-0"></p>';
      notice.querySelector('[data-preferences]').href = preferencesUrl;
      (form || legacy.parentElement).prepend(notice);
    }
    const status = message => {
      [settings, notice].filter(Boolean).forEach(root => { root.querySelector('[data-google-status]').textContent = message; });
    };
    function render() {
      status(choice === 'allow' ? 'Autorisation mémorisée pour toutes les pages de ce navigateur.' : choice === 'deny' ? 'Services Google désactivés. La saisie manuelle reste disponible.' : 'Les services Google sont désactivés tant que vous ne les autorisez pas.');
      if (notice) notice.hidden = choice !== null;
    }
    function activate() {
      if (loaded || loading || choice !== 'allow' || (!legacy && !form)) return;
      const ready = () => {
        loading = false;
        if (choice !== 'allow') return;
        loaded = true;
        document.dispatchEvent(new CustomEvent('wiki:enable-google-maps'));
      };
      if (!legacy) { ready(); return; }
      let url;
      try {
        url = new URL(legacy.dataset.googleMapsSrc);
        if (url.protocol !== 'https:' || url.hostname !== 'maps.googleapis.com' || url.pathname !== '/maps/api/js' || url.username || url.password) throw new Error();
      } catch (_) { if (notice) notice.hidden = false; status('Configuration Google indisponible. La saisie manuelle reste possible.'); return; }
      loading = true;
      const script = document.createElement('script');
      script.src = url.href; script.async = true; script.referrerPolicy = 'strict-origin';
      script.onload = ready;
      script.onerror = () => {
        loading = false; script.remove();
        if (notice) notice.hidden = false;
        status('Impossible de charger Google. Vérifiez la connexion ou réessayez. La saisie manuelle reste possible.');
      };
      document.head.append(script);
    }
    document.querySelectorAll('[data-google-choice]').forEach(button => {
      button.addEventListener('click', () => {
        choice = button.dataset.googleChoice;
        const stored = save(choice);
        render();
        if (choice === 'deny' && (loaded || loading)) { window.location.reload(); return; }
        activate();
        if (!stored) {
          if (notice) notice.hidden = false;
          status('Votre navigateur bloque la mémorisation. Ce choix ne peut être appliqué que sur cette page.');
        }
      });
    });
    window.addEventListener('storage', event => {
      if (event.key !== key) return;
      choice = read();
      if (choice !== 'allow' && (loaded || loading)) { window.location.reload(); return; }
      render(); activate();
    });
    render();
    // Let page-specific initializers register before automatic activation.
    setTimeout(activate, 0);
  });
})();
