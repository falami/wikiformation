(() => {
  const tables = ['tblUnsigned', 'tblAppreciations', 'tblTrainerCompliance'];
  let busy = false;
  const visible = table => [...table.querySelectorAll('[data-reminder-row]')].filter(box => !box.disabled && box.closest('tr').getClientRects().length);
  function refresh() {
    tables.forEach(id => {
      const table = document.getElementById(id);
      if (!table) return;
      const boxes = visible(table), checked = boxes.filter(box => box.checked);
      const all = document.querySelector(`[data-reminder-all="${id}"]`);
      const button = document.querySelector(`[data-reminder-send="${id}"]`);
      if (all) { all.checked = boxes.length > 0 && checked.length === boxes.length; all.indeterminate = checked.length > 0 && checked.length < boxes.length; all.disabled = busy || !boxes.length; }
      if (button) { button.disabled = busy || !checked.length; button.textContent = `Relancer la sélection (${checked.length})`; }
    });
  }
  document.addEventListener('change', event => {
    if (event.target.matches('[data-reminder-all]')) {
      visible(document.getElementById(event.target.dataset.reminderAll)).forEach(box => { box.checked = event.target.checked; });
    }
    refresh();
  });
  document.addEventListener('input', () => setTimeout(refresh, 0));
  document.addEventListener('click', async event => {
    const button = event.target.closest('[data-reminder-send]');
    if (!button || busy) return;
    const id = button.dataset.reminderSend;
    const boxes = visible(document.getElementById(id)).filter(box => box.checked);
    const status = document.querySelector(`[data-reminder-feedback="${id}"]`);
    busy = true; refresh();
    let sent = 0, skipped = 0, failed = 0;
    const details = new Set();
    for (const [index, box] of boxes.entries()) {
      status.textContent = `Relances en cours… ${index + 1}/${boxes.length}`;
      const form = box.closest('tr').querySelector('form');
      try {
        const response = await fetch(form.action, {method:'POST', body:new FormData(form), headers:{'X-Requested-With':'XMLHttpRequest'}, credentials:'same-origin'});
        if (!response.ok) throw new Error();
        const result = await response.json();
        if (result.sent && result.lastReminderAt) {
          const cell = box.closest('tr').querySelector('[data-reminder-history]');
          if (cell) cell.textContent = new Date(result.lastReminderAt).toLocaleString('fr-FR', {timeZone:'Europe/Paris', dateStyle:'short', timeStyle:'short'});
        }
        sent += result.sent; skipped += result.skipped; failed += result.failed;
        Object.values(result.messages || {}).flat().forEach(message => details.add(message));
        if (!result.failed) box.checked = false;
        if (id === 'tblTrainerCompliance') {
          box.checked = false;
          box.disabled = true;
          form.querySelector('button').disabled = true;
          const note = document.createElement('small');
          note.className = 'd-block text-muted';
          note.textContent = result.sent ? 'Relance transmise' : (result.failed ? 'Envoi à vérifier avant toute nouvelle relance' : 'Relance indisponible');
          form.append(note);
        }
      } catch (_) { failed++; }
    }
    status.textContent = `${sent} mail(s) confié(s) au service d’envoi · ${skipped} relance(s) ignorée(s) · ${failed} échec(s).`;
    status.title = [...details].join('\n');
    busy = false; refresh();
  });
  function init() {
    tables.forEach(id => { const body = document.querySelector(`#${id} tbody`); if (body) new MutationObserver(refresh).observe(body, {childList:true, subtree:true, attributes:true, attributeFilter:['style','class']}); });
    refresh();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
