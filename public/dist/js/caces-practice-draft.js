(() => {
  'use strict';
  const form = document.querySelector('[data-practice-clock]');
  if (!form) return;
  const key = 'caces-practice-draft-v1:' + form.dataset.practiceClock;
  const schema = form.dataset.practiceSchema;
  // Duration is persisted by the clock; signatures must be confirmed anew.
  const fields = [...form.querySelectorAll('input[name],select[name],textarea[name]')]
    .filter(field => /^(scores\[[^\]]+\]|remediationThemes\[\]|equipment|practiceDate|remediationHours)$/.test(field.name));
  const notice = document.createElement('p');
  notice.className = 'cx-muted';
  notice.setAttribute('role', 'status');
  notice.textContent = 'Les notes sont enregistrées automatiquement dans le dossier pendant la saisie.';
  form.prepend(notice);
  const identity = field => field.name + (field.type === 'checkbox' ? ':' + field.value : '');
  const server = JSON.parse(form.dataset.practiceSaved || '{}');
  let revision = server.revision || 0, dirty = false, saving = false, conflict = false, generation = 0;
  let local;
  try { local = JSON.parse(localStorage.getItem(key)); } catch (_) {}
  const saved = local && local.schema === schema && (local.revision || 0) === revision && local.at > (server.at || 0) && Date.now() - local.at < 30 * 86400000
    ? local : {...server, schema};
  try {
    if (saved && saved.schema === schema && saved.values) {
      fields.forEach(field => {
        const value = saved.values[identity(field)];
        if (field.type === 'checkbox') field.checked = value === true;
        else if (typeof value === 'string') {
          // Tom Select can remove unselected options from the native select.
          // Its option catalogue remains the source of valid saved values.
          if (field.tagName === 'SELECT') {
            const exists = field.tomselect
              ? Object.prototype.hasOwnProperty.call(field.tomselect.options, value)
              : [...field.options].some(option => option.value === value);
            if (value !== '' && !exists) return;
          }
          if (field.tomselect) field.tomselect.setValue(value, true);
          else if (field._flatpickr) field._flatpickr.setDate(value, false);
          else field.value = value;
        }
      });
      notice.textContent = 'Brouillon restauré. Vérifiez votre saisie puis signez pour valider le test.';
    }
  } catch (_) {
    notice.textContent = 'La sauvegarde automatique sur cet appareil est indisponible. Gardez cette page ouverte.';
  }
  function save() {
    dirty = true; ++generation;
    const values = {};
    fields.forEach(field => { values[identity(field)] = field.type === 'checkbox' ? field.checked : field.value; });
    try {
      localStorage.setItem(key, JSON.stringify({schema, values, revision, at: Date.now()}));
      notice.textContent = 'Enregistrement en cours…';
    } catch (_) {
      notice.textContent = 'Sauvegarde automatique indisponible. Gardez cette page ouverte pour conserver votre saisie.';
    }
    persist();
  }
  async function persist() {
    if (!dirty || saving || conflict || !form.dataset.practiceSaveUrl) return;
    saving = true;
    const current = generation;
    const data = new FormData(form);
    data.set('revision', String(revision));
    try {
      const response = await fetch(form.dataset.practiceSaveUrl, {method:'POST', body:data, credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'}});
      const result = await response.json();
      if (!response.ok || !result.saved) {
        conflict = response.status === 409 || response.status === 403;
        throw new Error(result.error || 'La sauvegarde a échoué.');
      }
      revision = result.revision;
      dirty = generation !== current;
      if (dirty) {
        try {
          const fallback = JSON.parse(localStorage.getItem(key));
          if (fallback) { fallback.revision = revision; localStorage.setItem(key, JSON.stringify(fallback)); }
        } catch (_) {}
      }
      if (!dirty) {
        notice.textContent = 'Toutes les notes sont enregistrées dans le dossier. La signature reste à effectuer.';
        try { localStorage.removeItem(key); } catch (_) {}
      }
    } catch (error) {
      notice.textContent = conflict ? error.message : 'Notes non enregistrées sur le serveur. Nouvelle tentative automatique dès que possible.';
    } finally {
      saving = false;
      if (dirty && !conflict) setTimeout(persist, generation !== current ? 0 : 3000);
    }
  }
  window.addEventListener('online', persist);
  window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
  // A newer local fallback is retried after restoring every field.
  if (saved === local) save();
  form.addEventListener('input', save);
  form.addEventListener('change', save);
  window.addEventListener('pagehide', () => { if (dirty) save(); });
})();
