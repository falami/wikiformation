document.addEventListener('DOMContentLoaded', () => {
  const all = document.getElementById('hab-select-all');
  const button = document.getElementById('hab-bulk-button');
  const rows = [...document.querySelectorAll('.hab-select-row')];
  if (!all || !button) return;
  function update() {
    const count = rows.filter(row => row.checked).length;
    button.disabled = count === 0;
    button.textContent = 'Modifier la sélection (' + count + ')';
    all.checked = rows.length > 0 && count === rows.length;
    all.indeterminate = count > 0 && count < rows.length;
    all.disabled = rows.length === 0;
  }
  all.addEventListener('change', () => { rows.forEach(row => { row.checked = all.checked; }); update(); });
  rows.forEach(row => row.addEventListener('change', update));
  window.addEventListener('pageshow', update);
  update();
});
