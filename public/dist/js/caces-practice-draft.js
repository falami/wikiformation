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
  notice.textContent = 'Brouillon sauvegardé automatiquement sur cet appareil pendant la saisie.';
  form.prepend(notice);
  const identity = field => field.name + (field.type === 'checkbox' ? ':' + field.value : '');
  try {
    const saved = JSON.parse(localStorage.getItem(key));
    if (saved && saved.schema === schema && saved.values && Date.now() - saved.at < 30 * 86400000) {
      fields.forEach(field => {
        const value = saved.values[identity(field)];
        if (field.type === 'checkbox') field.checked = value === true;
        else if (typeof value === 'string') {
          if (field.tagName === 'SELECT' && ![...field.options].some(option => option.value === value)) return;
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
    const values = {};
    fields.forEach(field => { values[identity(field)] = field.type === 'checkbox' ? field.checked : field.value; });
    try {
      localStorage.setItem(key, JSON.stringify({schema, values, at: Date.now()}));
      notice.textContent = 'Brouillon sauvegardé sur cet appareil à ' + new Date().toLocaleTimeString('fr-FR') + '. La validation reste à effectuer.';
    } catch (_) {
      notice.textContent = 'Sauvegarde automatique indisponible. Gardez cette page ouverte pour conserver votre saisie.';
    }
  }
  form.addEventListener('input', save);
  form.addEventListener('change', save);
  window.addEventListener('pagehide', save);
})();
