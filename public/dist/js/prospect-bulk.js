(() => {
  'use strict';
  const modal = document.getElementById('crmBulkModal');
  if (!modal) return;
  const get = id => document.getElementById('crmBulk' + id);
  const boxes = [...document.querySelectorAll('.crm-select-contact')];
  const all = document.getElementById('crmSelectAll');
  if (!all) return;
  let contacts = [], mode = 'schedule', busy = false, finished = false, changed = false;
  let previewTimer, previewController, previewVersion = 0;
  const selected = () => boxes.filter(box => box.checked);
  function selection() {
    const count = selected().length;
    all.checked = count === boxes.length; all.indeterminate = count > 0 && count < boxes.length;
    document.getElementById('crmSelectionCount').textContent = count ? `${count} contact${count > 1 ? 's' : ''} sélectionné${count > 1 ? 's' : ''}` : 'Aucun contact sélectionné';
    document.querySelectorAll('[data-bulk-open], #crmClearSelection').forEach(button => button.disabled = !count);
    boxes.forEach(box => box.closest('.crm-contact').classList.toggle('is-selected', box.checked));
  }
  boxes.forEach(box => box.addEventListener('change', selection));
  all.addEventListener('change', () => { boxes.forEach(box => box.checked = all.checked); selection(); });
  document.getElementById('crmClearSelection').addEventListener('click', () => { boxes.forEach(box => box.checked = false); selection(); });
  selection();

  const date = get('Date');
  const locale = document.getElementById('crmDate')?._flatpickr?.config.locale;
  const picker = window.flatpickr ? flatpickr(date, {
    dateFormat:'Y-m-d', altInput:true, altFormat:'d/m/Y', disableMobile:true,
    altInputClass:'form-control crm-premium-date', locale:locale || 'default',
    appendTo:get('Form'), monthSelectorType:'static',
    onReady(_, __, fp) {
      fp.calendarContainer.classList.add('crm-premium-calendar');
      fp.altInput.id = 'crmBulkDateDisplay'; fp.altInput.placeholder = 'Choisir une date';
      modal.querySelector('label[for="crmBulkDate"]').htmlFor = fp.altInput.id;
    },
    onOpen(_, __, fp) {
      requestAnimationFrame(() => {
        if (!fp.isOpen) return;
        const rect = fp.altInput.getBoundingClientRect(), cal = fp.calendarContainer;
        Object.assign(cal.style, {position:'fixed', top:Math.max(8, rect.bottom + cal.offsetHeight + 8 > innerHeight ? rect.top - cal.offsetHeight - 8 : rect.bottom + 8)+'px', left:Math.max(8, Math.min(rect.left, innerWidth - cal.offsetWidth - 8))+'px', right:'auto'});
      });
    }
  }) : null;
  modal.querySelector('.modal-body').addEventListener('scroll', () => picker?.close());
  window.addEventListener('resize', () => picker?.close());
  function exclusion(c) {
    if (mode === 'email') {
      if (!c.sendUrl) return 'Envoi non autorisé';
      if (c.active !== '1' || c.stage === 'converted') return 'Contact inactif ou déjà converti';
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(c.email)) return 'E-mail manquant ou invalide';
      if (contacts.some(other => other !== c && other.email.toLowerCase() === c.email.toLowerCase() && other.active === '1' && other.stage !== 'converted' && contacts.indexOf(other) < contacts.indexOf(c))) return 'Adresse déjà présente dans la sélection';
    }
    if (mode === 'schedule' && (c.active !== '1' || ['won','lost','converted'].includes(c.stage))) return 'Relance réservée aux prospects actifs en cours';
    if (mode === 'status' && c.stage === 'converted') return 'Prospect déjà converti';
    return '';
  }
  function update() {
    if (busy || finished) return;
    get('Email').hidden = mode !== 'email'; get('Followup').hidden = mode === 'email';
    get('DateGroup').hidden = mode !== 'schedule'; get('StageGroup').hidden = mode !== 'status';
    get('NoteHint').textContent = mode === 'note' ? '· obligatoire' : '· facultative';
    get('Subject').required = get('Message').required = mode === 'email';
    get('Note').required = mode === 'note'; date.required = !picker && mode === 'schedule';
    get('Recipients').replaceChildren();
    for (const c of contacts) {
      c.excluded = exclusion(c);
      const li = document.createElement('li'), name = document.createElement('span'), result = document.createElement('small');
      name.textContent = c.name + (mode === 'email' && c.email ? ' · ' + c.email : '');
      result.textContent = c.excluded || 'Prêt'; result.className = c.excluded ? 'text-muted' : 'text-success';
      li.append(name, result); get('Recipients').append(li); c.result = result;
    }
    const ready = contacts.filter(c => !c.excluded);
    get('Summary').textContent = `${contacts.length} contacts sélectionnés · ${ready.length} concernés par cette action`;
    get('Submit').textContent = mode === 'email' ? `Envoyer ${ready.length} e-mail${ready.length > 1 ? 's' : ''}` : `Appliquer à ${ready.length} contact${ready.length > 1 ? 's' : ''}`;
    get('Submit').disabled = !ready.length;
    get('PreviewContact').replaceChildren(...ready.map(c => new Option(c.name, c.id)));
    queuePreview();
  }
  document.querySelectorAll('[data-bulk-open]').forEach(button => button.addEventListener('click', () => {
    mode = button.dataset.bulkOpen; finished = changed = false;
    contacts = selected().map(box => {
      const follow = box.closest('.crm-contact').querySelector('[data-followup-url]');
      return {...box.dataset, id:box.value, followupUrl:follow.dataset.followupUrl, token:follow.dataset.token, key:crypto.randomUUID(), box};
    });
    get('Form').reset(); picker?.clear(); get('Fields').disabled = false;
    get('Fields').querySelectorAll('select').forEach(select => select.tomselect?.enable());
    get('Action').tomselect?.setValue('schedule', true); get('Days').tomselect?.setValue('7', true);
    get('Stage').tomselect?.setValue('new', true);
    get('Close').textContent = 'Annuler'; get('Result').textContent = '';
    update(); bootstrap.Modal.getOrCreateInstance(modal).show();
  }));
  get('Action').addEventListener('change', () => { mode = get('Action').value; update(); });
  function queuePreview() {
    clearTimeout(previewTimer); previewController?.abort(); ++previewVersion;
    if (mode !== 'email' || busy || finished) return;
    get('Preview').textContent = 'Actualisation de l’aperçu…';
    previewTimer = setTimeout(preview, 400);
  }
  async function preview() {
    const c = contacts.find(c => c.id === get('PreviewContact').value && !c.excluded);
    if (!c) { get('Preview').textContent = 'Aucun destinataire éligible.'; return; }
    const version = previewVersion; previewController = new AbortController();
    try {
      const response = await fetch(c.previewUrl, {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, signal:previewController.signal, body:new URLSearchParams({to:c.email, subject:get('Subject').value, message:get('Message').value, templateId:'0'})});
      if (!response.ok || response.redirected) throw new Error();
      const data = await response.json(); if (!data.ok) throw new Error();
      if (version === previewVersion) get('Preview').innerHTML = data.html;
    } catch (error) {
      if (error.name !== 'AbortError' && version === previewVersion) get('Preview').textContent = 'Aperçu indisponible. Vérifiez votre connexion.';
    }
  }
  ['Subject','Message'].forEach(id => get(id).addEventListener('input', queuePreview));
  get('PreviewContact').addEventListener('change', queuePreview);
  modal.addEventListener('hide.bs.modal', event => {
    if (busy) { event.preventDefault(); return; }
    clearTimeout(previewTimer); previewController?.abort(); ++previewVersion; picker?.close();
  });
  modal.addEventListener('hidden.bs.modal', () => { if (changed) location.reload(); });
  window.addEventListener('beforeunload', event => { if (busy) { event.preventDefault(); event.returnValue = ''; } });
  get('Form').addEventListener('submit', async event => {
    event.preventDefault(); if (busy || finished || !get('Form').reportValidity()) return;
    if (mode === 'schedule' && !date.value) { get('Result').textContent = 'Choisissez une date de relance.'; picker?.open(); return; }
    if (mode === 'email' && (!get('Subject').value.trim() || !get('Message').value.trim())) { get('Result').textContent = 'Renseignez un objet et un message.'; return; }
    if (mode === 'note' && !get('Note').value.trim()) { get('Result').textContent = 'Saisissez une note.'; return; }
    const ready = contacts.filter(c => !c.excluded); if (!ready.length) return;
    busy = true; clearTimeout(previewTimer); previewController?.abort(); ++previewVersion; picker?.close();
    get('Fields').disabled = true; get('Submit').disabled = true;
    get('Fields').querySelectorAll('select').forEach(select => select.tomselect?.disable());
    modal.querySelectorAll('[data-bs-dismiss]').forEach(button => button.disabled = true);
    let success = 0, failed = 0, stopped = false;
    for (const [index, c] of ready.entries()) {
      if (stopped) { c.result.textContent = 'Non traité — opération interrompue'; c.result.className = 'text-muted'; continue; }
      get('Result').textContent = `Traitement ${index + 1} / ${ready.length}… Gardez cette fenêtre ouverte.`;
      c.result.textContent = 'En cours…';
      const params = mode === 'email'
        ? {to:c.email, subject:get('Subject').value.trim(), message:get('Message').value, templateId:'0', followupDays:get('Days').value, idemKey:c.key, _token:c.sendToken}
        : {action:mode, date:mode === 'schedule' ? date.value : '', status:get('Stage').value, note:get('Note').value, _token:c.token};
      try {
        const response = await fetch(mode === 'email' ? c.sendUrl : c.followupUrl, {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}, body:new URLSearchParams(params)});
        if (response.redirected || !response.headers.get('content-type')?.includes('application/json')) throw new Error();
        const data = await response.json();
        if (!response.ok || !data.ok) {
          failed++; c.result.textContent = data.error || 'Échec de l’action'; c.result.className = 'text-danger';
          if (response.status === 401 || response.status === 403 || response.status >= 500) stopped = true;
        } else {
          success++; changed = true; c.box.checked = false; c.result.textContent = mode === 'email' ? 'E-mail envoyé' : 'Suivi enregistré'; c.result.className = 'text-success';
        }
      } catch (_) {
        failed++; stopped = true; changed = true;
        c.result.textContent = 'Résultat incertain — vérifiez l’historique avant de recommencer'; c.result.className = 'text-danger';
      }
    }
    busy = false; finished = true; selection();
    get('Result').textContent = `${success} réussite${success > 1 ? 's' : ''} · ${failed} échec${failed > 1 ? 's' : ''}${stopped ? ' · Traitement interrompu, consultez le détail ci-dessus.' : ''}`;
    get('Submit').textContent = 'Traitement terminé';
    modal.querySelectorAll('[data-bs-dismiss]').forEach(button => button.disabled = false);
    get('Close').textContent = changed ? 'Terminer et actualiser' : 'Fermer';
  });
})();
