document.addEventListener('DOMContentLoaded', () => {
  const all = document.getElementById('hab-select-all');
  const button = document.getElementById('hab-bulk-button');
  const download = document.getElementById('hab-export-button');
  const ids = document.getElementById('hab-export-ids');
  const rows = [...document.querySelectorAll('.hab-select-row:not(:disabled)')];
  if (!all) return;
  function update() {
    const selected = rows.filter(row => row.checked);
    const count = selected.length;
    if (button) { button.disabled = !count; button.textContent = 'Modifier la sélection (' + count + ')'; }
    if (download && ids) {
      const available = selected.filter(row => row.dataset.exportable === '1');
      download.disabled = !available.length;
      download.textContent = 'Télécharger les attestations (' + available.length + ')';
      ids.replaceChildren();
      available.forEach(row => { const input = document.createElement('input'); input.type = 'hidden'; input.name = 'ids[]'; input.value = row.value; ids.append(input); });
    }
    all.checked = rows.length > 0 && count === rows.length;
    all.indeterminate = count > 0 && count < rows.length;
    all.disabled = !rows.length;
  }
  all.addEventListener('change', () => { rows.forEach(row => { row.checked = all.checked; }); update(); });
  rows.forEach(row => row.addEventListener('change', update));
  window.addEventListener('pageshow', update);
  update();
});
