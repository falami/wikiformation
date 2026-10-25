(() => {
  'use strict';
  if (window.WikiExternalMaps) return;
  window.WikiExternalMaps = true;

  const reset = root => root.querySelectorAll('[data-external-map]').forEach(map => {
    map.querySelector('[data-map-canvas]').replaceChildren();
    map.querySelector('[data-map-placeholder]').hidden = false;
    map.querySelector('[data-map-error]').textContent = '';
  });

  document.addEventListener('click', event => {
    const button = event.target.closest?.('[data-load-google-map]');
    if (!button) return;
    event.preventDefault();
    const map = button.closest('[data-external-map]');
    if (!map) return;
    const canvas = map.querySelector('[data-map-canvas]');
    if (canvas.childElementCount) return;
    let url;
    try {
      url = new URL(map.dataset.mapSrc);
      if (url.protocol !== 'https:' || url.hostname !== 'www.google.com' || !/^\/maps(?:\/|$)/.test(url.pathname)) throw new Error();
    } catch {
      map.querySelector('[data-map-error]').textContent = 'La carte est indisponible. Utilisez le lien vers Google Maps.';
      return;
    }
    const frame = document.createElement('iframe');
    frame.title = 'Carte Google Maps du lieu de formation';
    frame.referrerPolicy = 'no-referrer';
    frame.allowFullscreen = true;
    frame.src = url.href;
    canvas.append(frame);
    map.querySelector('[data-map-placeholder]').hidden = true;
    frame.focus();
  });
  // A restored page or a reopened modal must not silently reconnect to Google.
  document.addEventListener('turbo:before-cache', () => reset(document));
  document.addEventListener('hidden.bs.modal', event => reset(event.target));
})();
