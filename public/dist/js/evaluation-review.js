(() => {
  'use strict';
  const form = document.getElementById('evaluation-review');
  if (!form) return;
  const key = form.dataset.draftKey;
  if (form.dataset.editable !== '1') {
    if (!form.querySelector('button[value="publish"]')) { try { localStorage.removeItem(key); } catch (_) {} }
    return;
  }
  const fields = [...form.querySelectorAll('input[name], textarea[name], select[name]')]
    .filter(field => !['_token', 'version', 'validation_confirmed'].includes(field.name));
  const version = form.elements.namedItem('version');
  const status = form.querySelector('[data-save-status]');
  const backup = form.querySelector('[data-export-draft]');
  const confirmed = form.elements.namedItem('validation_confirmed');
  let dirty = false, saving = null, timer, blocked = false, leaving = false, localOk = true;
  const values = () => Object.fromEntries(fields.map(f => [f.name, f.type === 'checkbox' ? f.checked : f.value]));
  const say = message => { status.textContent = message; };
  const persist = () => {
    try {
      localStorage.setItem(key, JSON.stringify({version: version.value, values: values(), at: Date.now()}));
      localOk = true;
    } catch (_) { localOk = false; }
  };
  const update = () => {
    form.querySelectorAll('[data-practice-item]').forEach(row => {
      const na = row.querySelector('[data-practice-na]')?.checked;
      row.querySelectorAll('[data-practice-points], [data-practice-failed]').forEach(f => { f.disabled = !!na; });
    });
    const progress = [];
    for (const part of ['theory', 'practice']) {
      const inputs = fields.filter(f => f.name.startsWith(part + '[') && f.name.endsWith('[points]'));
      let points = 0, max = 0, count = 0, applicable = 0, invalid = false;
      inputs.forEach(f => {
        if (f.disabled) return;
        applicable++;
        if (!f.validity.valid) { invalid = true; return; }
        if (f.value === '') return;
        points += Number(f.value); max += Number(f.max); count++;
      });
      const label = part === 'practice' ? 'Pratique' : 'Théorie';
      form.querySelector('[data-live-score="' + part + '"]').textContent = invalid ? 'Note invalide' :
        max > 0 ? (20 * points / max).toLocaleString('fr-FR', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' / 20' + (count < applicable ? ' (provisoire)' : '') :
        inputs.length && !applicable ? 'Aucun critère applicable' : 'À compléter';
      progress.push(label + ' : ' + count + ' / ' + applicable + ' renseignés');
    }
    if (form.querySelector('[data-practice-failed]:checked:not(:disabled)')) progress.push('Critère éliminatoire non maîtrisé : avis favorable impossible.');
    form.querySelector('[data-score-progress]').textContent = progress.join(' · ');
  };
  let recovered = null;
  try { recovered = JSON.parse(localStorage.getItem(key)); } catch (_) {}
  if (recovered?.values && recovered.version === version.value) {
    fields.forEach(f => {
      const value = recovered.values[f.name];
      if (f.type === 'checkbox' && typeof value === 'boolean') f.checked = value;
      else if (f.type !== 'checkbox' && typeof value === 'string') f.value = value;
    });
    recovered = null;
    dirty = true;
    say('Saisie récupérée sur cet appareil. Synchronisation en cours…');
  } else if (recovered?.values) {
    blocked = true;
    backup.hidden = false;
    say('Une saisie locale existe pour une ancienne version. Téléchargez-la avant de poursuivre ; le dossier serveur est affiché. La sauvegarde automatique est suspendue pour éviter tout écrasement.');
  }
  backup.addEventListener('click', () => {
    const blob = new Blob([JSON.stringify(recovered || {values: values()}, null, 2)], {type: 'application/json'});
    const url = URL.createObjectURL(blob), a = document.createElement('a');
    a.href = url; a.download = 'evaluation-saisie-secours.json'; a.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  });
  const save = async () => {
    clearTimeout(timer);
    if (saving) { await saving; if (dirty && !blocked) return save(); return; }
    if (!dirty || blocked || leaving) return;
    if (!fields.every(f => f.disabled || f.checkValidity())) {
      say('Corrigez les champs invalides pour synchroniser. ' + (localOk ? 'Saisie conservée sur cet appareil.' : 'Sauvegarde locale indisponible : ne fermez pas cette page.'));
      return;
    }
    const sent = JSON.stringify(values());
    const data = new FormData(form);
    data.set('action', 'save');
    data.delete('validation_confirmed');
    say('Enregistrement en cours…');
    saving = (async () => {
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 15000);
      try {
        // Buttons named "action" shadow form.action with a RadioNodeList.
        const endpoint = form.getAttribute('action') || window.location.href;
        const response = await fetch(endpoint, {method: 'POST', body: data, headers: {'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json'}, signal: controller.signal});
        if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Votre session a peut-être expiré. Reconnectez-vous puis rechargez le dossier.');
        const result = await response.json();
        if (!response.ok) {
          if (response.status === 409) { blocked = true; backup.hidden = false; }
          throw new Error(result.error || 'Enregistrement impossible (HTTP ' + response.status + ').');
        }
        if (!Number.isInteger(result.version)) throw new Error('Réponse du serveur inattendue.');
        version.value = String(result.version);
        dirty = JSON.stringify(values()) !== sent;
        // Keep the acknowledged version alongside any newer local edits.
        persist();
        say(dirty ? 'Modifications suivantes en attente…' : 'Brouillon enregistré à ' + new Date().toLocaleTimeString('fr-FR') + '.');
      } catch (error) {
        say(error.message + ' ' + (localOk ? 'Votre saisie reste conservée sur cet appareil.' : 'Sauvegarde locale indisponible : ne fermez pas cette page.'));
      } finally { clearTimeout(timeout); }
    })();
    await saving;
    saving = null;
    if (dirty && !blocked) timer = setTimeout(save, 10000);
  };
  const change = () => {
    dirty = true; update();
    if (blocked) return;
    persist();
    say(localOk ? 'Saisie conservée sur cet appareil · synchronisation en attente…' : 'Sauvegarde locale indisponible · synchronisation en attente…');
    clearTimeout(timer); timer = setTimeout(save, 800);
  };
  form.addEventListener('input', event => {
    const input = event.target;
    if (!fields.includes(input)) return;
    if (input.type === 'number' && Number.isFinite(input.valueAsNumber) &&
        (input.valueAsNumber < 0 || input.valueAsNumber > Number(input.max))) {
      input.value = String(Math.min(Number(input.max), Math.max(0, input.valueAsNumber)));
    }
    change();
  });
  form.addEventListener('change', event => { if (fields.includes(event.target)) change(); });
  window.addEventListener('online', () => { if (dirty) save(); });
  window.addEventListener('beforeunload', event => {
    if (dirty && !leaving) { if (!blocked) persist(); event.preventDefault(); event.returnValue = ''; }
  });
  form.querySelectorAll('button[name="action"]').forEach(button => button.addEventListener('click', () => { confirmed.required = button.value === 'publish'; }));
  form.addEventListener('submit', async event => {
    if (leaving) return;
    event.preventDefault();
    if (blocked) { say('Conflit de versions : téléchargez votre saisie de secours puis rechargez le dossier avant de poursuivre.'); return; }
    const button = event.submitter;
    if (button?.value === 'publish' && !window.confirm('Publier définitivement ces résultats au stagiaire ?')) return;
    if (button?.value === 'save') { dirty = true; persist(); await save(); return; }
    clearTimeout(timer);
    if (saving) await saving;
    if (blocked) return;
    leaving = true;
    form.requestSubmit(button);
  });
  update();
  if (dirty) timer = setTimeout(save, 800);
})();
