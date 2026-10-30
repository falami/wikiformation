(() => {
  'use strict';

  function initializePassport() {
    const root = document.querySelector('[data-passport-workspace]');
    if (!root || root.dataset.initialized) return;
    root.dataset.initialized = '1';
    const byId = id => root.querySelector(`#pp-${id}`);
    const form = byId('export-form');
    const mode = byId('mode');
    const from = byId('date-from');
    const to = byId('date-to');
    const category = byId('category');
    const search = byId('search');
    const view = byId('view');
    const all = byId('check-all');
    const error = byId('error');
    const selectedIds = new Set();
    const eligibility = [...form.querySelectorAll('.pp-checklist input')];
    const rows = [...root.querySelectorAll('[data-pp-session]')].map(element => ({
      element,
      checkbox: element.querySelector('[data-session-id]'),
      available: element.dataset.selectable === '1',
      end: element.dataset.end,
      participants: Number(element.dataset.participants) || 0,
      search: normalize(element.textContent),
      status: element.querySelector('.pp-row-status'),
      statusText: element.querySelector('.pp-row-status').textContent,
    }));

    function normalize(value) {
      return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    }

    function declarationType() {
      return form.querySelector('input[name="declarationType"]:checked').value;
    }

    function validPeriod() {
      return from.value && to.value && from.value <= to.value && to.value <= root.dataset.today;
    }

    function selectable(row) {
      if (!row.available) return false;
      if (declarationType() === 'JDR' || !category.value) return true;
      const obligationStart = Number(category.value) <= 2 ? '2025-09-01' : '2026-07-01';
      return Boolean(row.end && row.end >= obligationStart);
    }

    function included(row) {
      return selectable(row) && (mode.value === 'manual'
        ? selectedIds.has(row.checkbox.dataset.sessionId)
        : validPeriod() && row.end && row.end >= from.value && row.end <= to.value);
    }

    function update() {
      const manual = mode.value === 'manual';
      const jdr = declarationType() === 'JDR';
      const query = normalize(search.value.trim());
      let visibleCount = 0;
      let sessions = 0;
      let participants = 0;
      rows.forEach(row => {
        const canSelect = selectable(row);
        if (!canSelect) selectedIds.delete(row.checkbox.dataset.sessionId);
        row.status.textContent = row.available && !canSelect ? 'Avant le début de l’obligation pour cette catégorie' : row.statusText;
        row.status.classList.toggle('pp-row-status-review', canSelect);
        row.element.classList.toggle('pp-row-excluded', !canSelect);
        const matchesView = view.value === 'all' || (view.value === 'available' ? canSelect : !canSelect);
        row.element.hidden = !(matchesView && row.search.includes(query));
        if (!row.element.hidden) visibleCount++;
        const selected = included(row);
        row.checkbox.checked = Boolean(selected);
        row.checkbox.disabled = !canSelect || !manual;
        row.element.classList.toggle('pp-row-selected', Boolean(selected));
        if (selected) { sessions++; participants += row.participants; }
      });
      const availableCount = rows.filter(selectable).length;
      view.querySelector('option[value="available"]').textContent = `Sessions à examiner (${availableCount})`;
      view.querySelector('option[value="excluded"]').textContent = `Sessions exclues (${rows.length - availableCount})`;
      const visibleAvailable = rows.filter(row => selectable(row) && !row.element.hidden);
      const checkedVisible = visibleAvailable.filter(row => row.checkbox.checked).length;
      all.disabled = !manual || visibleAvailable.length === 0;
      all.checked = visibleAvailable.length > 0 && checkedVisible === visibleAvailable.length;
      all.indeterminate = checkedVisible > 0 && checkedVisible < visibleAvailable.length;
      byId('session-ids').value = JSON.stringify([...selectedIds].map(Number));
      byId('selected-count').textContent = sessions;
      byId('participant-count').textContent = participants;
      byId('visible-count').textContent = `${visibleCount} session${visibleCount > 1 ? 's' : ''} affichée${visibleCount > 1 ? 's' : ''}`;
      byId('empty-results').hidden = visibleCount > 0;
      byId('clear-selection').hidden = !manual || selectedIds.size === 0;
      byId('period').hidden = manual;
      byId('period-presets').hidden = manual;
      from.required = !manual;
      to.required = !manual;
      from.disabled = manual;
      to.disabled = manual;
      byId('jdr-help').hidden = !jdr;
      byId('adf-help').hidden = jdr;
      byId('summary-type').textContent = jdr ? 'Justificatif de réussite' : 'Attestation de formation';
      byId('summary-category').textContent = category.value ? `Catégorie ${category.value}` : 'À préciser';
      byId('summary-checks').textContent = `${eligibility.filter(input => input.checked).length} / ${eligibility.length}`;
      byId('selection-help').textContent = manual
        ? 'Cochez les sessions à inclure. Une sélection reste conservée même si une recherche la masque.'
        : 'Toutes les sessions recevables terminées dans la période sont incluses. La recherche ci-dessous ne change pas le contenu du lot.';
      mode.querySelector('option[value="period"]').disabled = jdr;
    }

    function setPeriod(kind) {
      const [year, month, day] = root.dataset.today.split('-').map(Number);
      const quarter = Math.floor((month - 1) / 3);
      const localDate = date => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
      let first = new Date(year, quarter * 3, 1);
      let last = new Date(year, month - 1, day);
      if (kind === 'previous') {
        first = new Date(year, quarter * 3 - 3, 1);
        last = new Date(year, quarter * 3, 0);
      } else if (kind === 'year') {
        first = new Date(year, 0, 1);
      }
      from.value = localDate(first);
      to.value = localDate(last);
      update();
    }

    function showError(message) {
      error.textContent = message;
      error.hidden = false;
      error.focus();
    }

    root.querySelectorAll('[data-pp-period]').forEach(button => {
      button.addEventListener('click', () => setPeriod(button.dataset.ppPeriod));
    });
    rows.forEach(row => row.checkbox.addEventListener('change', () => {
      if (row.checkbox.checked) selectedIds.add(row.checkbox.dataset.sessionId);
      else selectedIds.delete(row.checkbox.dataset.sessionId);
      update();
    }));
    all.addEventListener('change', () => {
      rows.filter(row => selectable(row) && !row.element.hidden).forEach(row => {
        if (all.checked) selectedIds.add(row.checkbox.dataset.sessionId);
        else selectedIds.delete(row.checkbox.dataset.sessionId);
      });
      update();
    });
    byId('clear-selection').addEventListener('click', () => { selectedIds.clear(); update(); });
    form.querySelectorAll('input[name="declarationType"]').forEach(input => input.addEventListener('change', () => {
      if (declarationType() === 'JDR') mode.value = 'manual';
      update();
    }));
    [mode, from, to, category, view, ...eligibility].forEach(input => input.addEventListener('change', () => { error.hidden = true; update(); }));
    search.addEventListener('input', update);
    form.addEventListener('submit', event => {
      error.hidden = true;
      if (declarationType() === 'JDR' && mode.value !== 'manual') {
        event.preventDefault();
        showError('Pour un justificatif de réussite, choisissez les sessions manuellement puis complétez les dates de validité dans le fichier.');
      } else if (mode.value === 'period' && !validPeriod()) {
        event.preventDefault();
        showError('Choisissez une période valide, avec une date de fin comprise entre la date de début et aujourd’hui.');
      } else if (mode.value === 'manual' && selectedIds.size > 500) {
        event.preventDefault();
        showError('Préparez un lot de 500 sessions maximum. Réduisez votre sélection pour continuer.');
      } else if (!rows.some(included)) {
        event.preventDefault();
        showError(mode.value === 'manual' ? 'Sélectionnez au moins une session recevable pour préparer le fichier.' : 'Aucune session recevable ne se termine dans cette période. Choisissez une autre période ou une sélection manuelle.');
      }
    });
    let initial = {};
    try { initial = JSON.parse(root.dataset.initialValues || '{}'); } catch (_) { /* Empty defaults remain usable. */ }
    if (initial && !Array.isArray(initial) && Object.keys(initial).length) {
      const type = initial.declarationType === 'JDR' ? 'JDR' : 'ADF';
      form.querySelector(`input[name="declarationType"][value="${type}"]`).checked = true;
      mode.value = type === 'JDR' || initial.mode === 'manual' ? 'manual' : 'period';
      category.value = ['1', '2', '3', '4'].includes(String(initial.category)) ? String(initial.category) : '';
      from.value = typeof initial.dateFrom === 'string' ? initial.dateFrom : '';
      to.value = typeof initial.dateTo === 'string' ? initial.dateTo : '';
      eligibility.forEach(input => { input.checked = ['1', 1, true, 'on'].includes(initial[input.name]); });
      let ids = [];
      try { ids = JSON.parse(initial.sessionIds || '[]'); } catch (_) { /* Invalid IDs are not restored. */ }
      if (Array.isArray(ids)) {
        const knownIds = new Set(rows.filter(selectable).map(row => row.checkbox.dataset.sessionId));
        ids.forEach(id => { if (Number.isSafeInteger(id) && knownIds.has(String(id))) selectedIds.add(String(id)); });
      }
      update();
    } else {
      setPeriod('quarter');
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initializePassport, {once: true});
  else initializePassport();
})();
