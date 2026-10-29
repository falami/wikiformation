(() => {
  'use strict';
  const key = 'wiki.osm.v1.' + (document.currentScript?.dataset.user || 'anonymous');
  function read() {
    try {
      const saved = JSON.parse(localStorage.getItem(key));
      return saved && saved.expires > Date.now() && ['allow', 'deny'].includes(saved.choice) ? saved.choice : null;
    } catch (_) { return null; }
  }
  function update() {
    document.querySelectorAll('[data-osm-status]').forEach(node => {
      node.textContent = read() === 'allow' ? 'Affichage automatique des cartes activé dans ce navigateur.' : 'Les cartes sont chargées uniquement sur votre demande.';
    });
  }
  window.WikiMapPreferences = {
    allowed: () => read() === 'allow',
    set(choice) {
      if (!['allow', 'deny'].includes(choice)) return;
      try { localStorage.setItem(key, JSON.stringify({choice, expires: Date.now() + 180 * 86400000})); }
      catch (_) {
        document.querySelectorAll('[data-osm-status]').forEach(node => { node.textContent = 'Votre navigateur bloque la mémorisation du choix.'; });
        return;
      }
      update();
      document.dispatchEvent(new CustomEvent('wiki:osm-preference', {detail: {allowed: read() === 'allow'}}));
    }
  };
  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-osm-choice]').forEach(button => {
      button.addEventListener('click', () => window.WikiMapPreferences.set(button.dataset.osmChoice));
    });
    update();
  });
  window.addEventListener('storage', event => {
    if (event.key !== key && event.key !== null) return;
    update();
    document.dispatchEvent(new CustomEvent('wiki:osm-preference', {detail: {allowed: read() === 'allow'}}));
  });
})();
