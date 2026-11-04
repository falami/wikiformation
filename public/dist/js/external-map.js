(() => {
  'use strict';
  if (window.WikiExternalMaps) return;
  window.WikiExternalMaps = true;

  const reset = root => root.querySelectorAll('[data-external-map]').forEach(map => {
    map.querySelector('[data-map-canvas]').replaceChildren();
    map.querySelector('[data-map-placeholder]').hidden = false;
    map.querySelector('[data-map-error]').textContent = '';
  });

  function loadMap(map, focus = false) {
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
    frame.referrerPolicy = 'strict-origin'; // Domain only, required for restricted Maps browser keys.
    frame.allowFullscreen = true;
    frame.src = url.href;
    canvas.append(frame);
    map.querySelector('[data-map-placeholder]').hidden = true;
    if (focus) frame.focus();
  }

  document.addEventListener('click', event => {
    const button = event.target.closest?.('[data-load-google-map]');
    if (!button) return;
    event.preventDefault();
    loadMap(button.closest('[data-external-map]'), true);
  });
  document.addEventListener('wiki:load-session-map', event => {
    const map = event.target;
    if (map.matches?.('[data-external-map]')) loadMap(map);
  });
  function loadAuthorizedMaps(){
    const choice = window.WikiGoogleServices?.choice?.();
    const selector = choice === 'allow' ? '[data-external-map]' : '[data-external-map][data-map-autoload="true"]';
    if (choice !== 'deny') document.querySelectorAll(selector).forEach(map => loadMap(map));
  }
  document.addEventListener('wiki:google-preference-changed', event => {
    if (event.detail.choice !== 'allow') reset(document);
    loadAuthorizedMaps();
  });
  document.addEventListener('shown.bs.modal', loadAuthorizedMaps);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', loadAuthorizedMaps);
  else loadAuthorizedMaps();
  document.addEventListener('turbo:load', loadAuthorizedMaps);
  // Release embedded frames when the modal closes or the page is cached.
  document.addEventListener('turbo:before-cache', () => reset(document));
  document.addEventListener('hidden.bs.modal', event => reset(event.target));
})();
