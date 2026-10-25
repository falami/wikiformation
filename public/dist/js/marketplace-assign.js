(() => {
  function initialize() {
    document.querySelectorAll('[data-course-assignment]').forEach(form => {
      if (form.dataset.ready) return;
      form.dataset.ready = 'true';
      const rows = [...form.querySelectorAll('[data-learner-row]')];
      const search = form.querySelector('#learner-search');
      const session = form.querySelector('#session-filter');
      const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase().trim();
      function update() {
        const terms = normalize(search.value).split(/\s+/).filter(Boolean);
        rows.forEach(row => {
          row.hidden = !terms.every(term => normalize(row.dataset.search).includes(term)) ||
            (session.value !== '' && !row.dataset.sessions.split(',').includes(session.value));
        });
        const visible = rows.filter(row => !row.hidden);
        const selected = rows.filter(row => row.querySelector('input').checked);
        const hidden = selected.filter(row => row.hidden).length;
        form.querySelector('[data-result-count]').textContent = visible.length + ' stagiaire(s)';
        form.querySelector('[data-selected-count]').textContent = selected.length + ' bénéficiaire(s) sélectionné(s)' + (hidden ? ' · ' + hidden + ' hors filtre' : '');
        form.querySelector('[data-empty-results]').hidden = visible.length > 0 || rows.length === 0;
        form.querySelector('[type=submit]').disabled = selected.length === 0;
      }
      search.addEventListener('input', update);
      session.addEventListener('change', update);
      form.addEventListener('change', update);
      form.querySelector('[data-select-visible]').addEventListener('click', () => {
        rows.filter(row => !row.hidden).forEach(row => { const input = row.querySelector('input'); if (!input.disabled) input.checked = true; });
        update();
      });
      form.querySelector('[data-clear-selection]').addEventListener('click', () => {
        rows.forEach(row => { row.querySelector('input').checked = false; });
        update();
      });
      update();
    });
  }
  initialize();
  document.addEventListener('turbo:load', initialize);
  document.addEventListener('DOMContentLoaded', initialize);
  document.addEventListener('turbo:before-cache', () => {
    document.querySelectorAll('[data-course-assignment]').forEach(form => delete form.dataset.ready);
  });
})();
