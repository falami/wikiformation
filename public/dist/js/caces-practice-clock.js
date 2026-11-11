(() => {
  'use strict';
  const form = document.querySelector('[data-practice-clock]');
  if (!form) return;
  const key = 'caces-practice-clock-v1:' + form.dataset.practiceClock;
  const display = form.querySelector('[data-clock-display]');
  const status = form.querySelector('[data-clock-status]');
  const toggle = form.querySelector('[data-clock-toggle]');
  const finish = form.querySelector('[data-clock-finish]');
  const duration = form.querySelector('[data-practice-duration]');
  const dialog = document.querySelector('[data-duration-dialog]');
  const proposed = dialog.querySelector('#confirmed-duration');
  let state = {elapsed: 0, since: null, started: false, retained: null};
  let storageAvailable = true, confirmedSubmit = false, submitAfterReview = false;
  try {
    const saved = JSON.parse(localStorage.getItem(key));
    if (saved && Number.isFinite(saved.elapsed) && saved.elapsed >= 0 &&
        (saved.since === null || (Number.isFinite(saved.since) && saved.since <= Date.now()))) {
      state = {...state, ...saved};
    }
  } catch { storageAvailable = false; }
  const elapsed = () => state.elapsed + (state.since === null ? 0 : Math.max(0, Date.now() - state.since));
  const seconds = () => Math.floor(elapsed() / 1000);
  const format = value => [Math.floor(value / 3600), Math.floor(value / 60) % 60, value % 60]
    .map(part => String(part).padStart(2, '0')).join(':');
  const persist = () => {
    try { localStorage.setItem(key, JSON.stringify(state)); }
    catch { storageAvailable = false; }
  };
  const render = () => {
    display.textContent = format(seconds());
    toggle.textContent = state.since !== null ? 'Mettre en pause' : state.started ? 'Reprendre le chrono' : 'Démarrer le test';
    finish.disabled = !state.started;
    status.textContent = (state.since !== null ? 'En cours · pauses exclues' : state.started ? 'Chronomètre en pause' : 'Prêt à démarrer') +
      (storageAvailable ? '' : ' · Conservation indisponible : gardez cette page ouverte');
    if (state.retained === null && state.started) duration.value = Math.max(1, seconds());
  };
  const pause = () => { state.elapsed = elapsed(); state.since = null; persist(); render(); };
  const start = () => {
    state.started = true; state.since = Date.now(); state.retained = null;
    persist(); render();
  };
  toggle.addEventListener('click', () => state.since === null ? start() : pause());
  form.querySelectorAll('[name^="scores["]').forEach(input => input.addEventListener('change', () => {
    if (!state.started) start();
  }));
  duration.addEventListener('input', () => { state.retained = duration.value; persist(); });
  const review = submitting => {
    submitAfterReview = submitting;
    pause();
    proposed.setCustomValidity('');
    proposed.value = format(Number(state.retained !== null ? state.retained : Math.max(1, seconds())));
    dialog.querySelector('[data-duration-measured]').textContent = 'Temps chronométré : ' + format(seconds());
    dialog.querySelector('[data-duration-accept]').textContent = submitting ? 'Valider et enregistrer le test' : 'Valider la durée';
    dialog.showModal();
    proposed.focus();
  };
  proposed.addEventListener('input', () => proposed.setCustomValidity(''));
  finish.addEventListener('click', () => review(false));
  dialog.querySelector('[data-duration-cancel]').addEventListener('click', () => dialog.close());
  dialog.querySelector('[data-duration-accept]').addEventListener('click', () => {
    const match = /^(\d{2}):([0-5]\d):([0-5]\d)$/.exec(proposed.value.trim());
    const value = match ? Number(match[1]) * 3600 + Number(match[2]) * 60 + Number(match[3]) : NaN;
    proposed.setCustomValidity(!match ? 'Saisissez la durée au format hh:mm:ss, par exemple 00:20:00.' :
      value < 1 || value > 86400 ? 'La durée doit être comprise entre 00:00:01 et 24:00:00.' : '');
    if (!proposed.reportValidity()) return;
    state.retained = value;
    duration.value = state.retained;
    persist(); dialog.close();
    if (submitAfterReview) {
      confirmedSubmit = true;
      form.requestSubmit();
      confirmedSubmit = false;
    }
  });
  form.addEventListener('submit', event => {
    if (confirmedSubmit || event.defaultPrevented) return;
    event.preventDefault();
    review(true);
  });
  if (state.retained !== null) duration.value = state.retained;
  render();
  setInterval(render, 500);
  window.addEventListener('pagehide', persist);
})();
