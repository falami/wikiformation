document.addEventListener('DOMContentLoaded', () => {
  const tree = document.getElementById('categoryTree');
  if (!tree) return;
  const input = document.getElementById('q');
  const nodes = [...tree.querySelectorAll('.category-node')];
  const pills = ['all', 'main', 'sub'].map(mode => [mode, document.getElementById(`pill-${mode}`)]);
  const normalize = text => text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  let mode = 'all';
  function apply() {
    const term = normalize(input.value.trim());
    let matches = 0;
    // Children are evaluated first so matching descendants keep their ancestors visible.
    [...nodes].reverse().forEach(node => {
      const match = (mode === 'all' || node.dataset.type === mode) && normalize(node.dataset.search).includes(term);
      const descendants = [...node.querySelectorAll(':scope > .category-children > .category-node')];
      const hasMatch = descendants.some(child => !child.hidden);
      node.hidden = !match && !hasMatch;
      node.classList.toggle('is-context', !match && hasMatch);
      const branch = node.querySelector(':scope > .category-children');
      if (branch) branch.hidden = !hasMatch;
      if (match) matches++;
    });
    document.getElementById('kpi-visible').textContent = String(matches);
    document.getElementById('categoryEmpty').hidden = matches > 0;
    pills.forEach(([value, button]) => {
      button.classList.toggle('active', value === mode);
      button.setAttribute('aria-pressed', String(value === mode));
    });
  }
  pills.forEach(([value, button]) => button.addEventListener('click', () => { mode = value; apply(); }));
  input.addEventListener('input', apply);
  document.getElementById('btnClear').addEventListener('click', () => { input.value = ''; input.focus(); apply(); });
  document.getElementById('btnReset').addEventListener('click', () => { mode = 'all'; input.value = ''; apply(); });
  document.getElementById('kpi-visible').setAttribute('aria-live', 'polite');
  apply();
});
