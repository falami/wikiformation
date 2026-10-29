(() => {
  'use strict';
  if (window.WikiExternalVideo) return;
  window.WikiExternalVideo = true;
  const key = 'wiki.external.video.v1.' + (document.currentScript?.dataset.videoUser || 'anonymous');
  const read = () => {
    try { const p = JSON.parse(localStorage.getItem(key)); return p?.expires > Date.now() && p.choice === 'allow'; }
    catch (_) { return false; }
  };
  let automatic = read();
  const renderPreference = () => {
    const status = document.querySelector('[data-video-status]');
    if (status) status.textContent = automatic ? 'Affichage automatique activé dans ce navigateur.' : 'Les vidéos sont chargées uniquement sur clic.';
    document.querySelectorAll('[data-video-choice]').forEach(b => b.setAttribute('aria-pressed', String((b.dataset.videoChoice === 'allow') === automatic)));
  };
  const resolve = raw => {
    const url = new URL(raw, window.location?.origin);
    if ((url.protocol !== 'https:' && !(url.origin === window.location?.origin && url.protocol === 'http:')) || url.username || url.password) throw new Error();
    if (/\.(mp4|webm|ogg)$/i.test(url.pathname)) return {src: url.href, kind: 'video'};
    if (['www.youtube.com', 'youtube.com', 'youtu.be', 'www.youtube-nocookie.com'].includes(url.hostname)) {
      const id = url.hostname === 'youtu.be' ? url.pathname.slice(1) : url.pathname.startsWith('/embed/') ? url.pathname.slice(7) : url.searchParams.get('v');
      if (!/^[A-Za-z0-9_-]{11}$/.test(id || '')) throw new Error();
      return {src: 'https://www.youtube-nocookie.com/embed/' + id, policy: 'https://policies.google.com/privacy?hl=fr'};
    }
    if (['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'].includes(url.hostname)) {
      const match = url.pathname.match(/^\/(?:video\/)?(\d+)\/?$/);
      if (!match) throw new Error();
      const embed = new URL('https://player.vimeo.com/video/' + match[1]);
      embed.searchParams.set('dnt', '1');
      // Vimeo unlisted videos need this non-identifying resource token.
      const hash = url.searchParams.get('h');
      if (hash && /^[a-f0-9]+$/i.test(hash)) embed.searchParams.set('h', hash);
      return {src: embed.href, policy: 'https://vimeo.com/privacy'};
    }
    throw new Error();
  };
  const reset = video => {
    video.querySelector('[data-video-canvas]').replaceChildren();
    video.querySelector('[data-video-placeholder]').hidden = false;
    video.querySelector('[data-close-external-video]').hidden = true;
  };
  const decorate = () => document.querySelectorAll('[data-external-video]').forEach(video => {
    try {
      const provider = resolve(video.dataset.videoSrc);
      const link = video.querySelector('[data-video-policy]');
      if (provider.policy) { link.href = provider.policy; link.hidden = false; }
      if (automatic) load(video);
    } catch (_) {
      video.querySelector('[data-video-error]').textContent = 'Ce fournisseur vidéo n’est pas pris en charge. Contactez votre organisme.';
    }
  });
  document.addEventListener('DOMContentLoaded', () => { renderPreference(); decorate(); });
  document.addEventListener('turbo:load', decorate);
  document.addEventListener('click', event => {
    const close = event.target.closest?.('[data-close-external-video]');
    if (close) { event.preventDefault(); reset(close.closest('[data-external-video]')); return; }
    const button = event.target.closest?.('[data-load-external-video]');
    if (!button) return;
    event.preventDefault();
    load(button.closest('[data-external-video]'));
  });
  function load(video) {
    const canvas = video.querySelector('[data-video-canvas]');
    if (canvas.childElementCount) return;
    try {
      const provider = resolve(video.dataset.videoSrc);
      const frame = document.createElement(provider.kind === 'video' ? 'video' : 'iframe');
      frame.title = 'Vidéo de formation';
      // An origin-only referrer is required by YouTube; never send the lesson path or query.
      frame.referrerPolicy = 'strict-origin';
      if (provider.kind === 'video') { frame.controls = true; frame.playsInline = true; frame.preload = 'none'; }
      frame.allow = 'encrypted-media; picture-in-picture; fullscreen';
      frame.allowFullscreen = true;
      frame.style.cssText = 'display:block;width:100%;height:auto;aspect-ratio:16/9;border:0;position:relative;';
      frame.src = provider.src;
      canvas.append(frame);
      video.querySelector('[data-video-placeholder]').hidden = true;
      video.querySelector('[data-close-external-video]').hidden = false;
    } catch (_) {
      video.querySelector('[data-video-error]').textContent = 'Vidéo indisponible : fournisseur ou adresse non pris en charge.';
    }
  }
  document.addEventListener('click', event => {
    const button = event.target.closest?.('[data-video-choice]');
    if (!button) return;
    automatic = button.dataset.videoChoice === 'allow';
    let stored = true;
    try { localStorage.setItem(key, JSON.stringify({choice: automatic ? 'allow' : 'deny', expires: Date.now() + 180 * 86400000})); }
    catch (_) { stored = false; }
    renderPreference();
    if (!stored) document.querySelector('[data-video-status]').textContent = 'Votre navigateur ne permet pas de mémoriser ce choix. Il ne s’applique qu’à cette page.';
    if (automatic) decorate();
    else document.querySelectorAll('[data-external-video]').forEach(reset);
  });
  window.addEventListener('storage', event => {
    if (event.key !== key && event.key !== null) return;
    automatic = read(); renderPreference();
    if (automatic) decorate(); else document.querySelectorAll('[data-external-video]').forEach(reset);
  });
  document.addEventListener('turbo:before-cache', () => document.querySelectorAll('[data-external-video]').forEach(reset));
})();
