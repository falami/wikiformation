document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('.doc-library');
  if (!root) return;
  root.querySelectorAll('[data-document-category], [data-document-category-filter]').forEach(select => {
    if (window.TomSelect && !select.tomselect) new TomSelect(select, {create:false, allowEmptyOption:true, controlInput:null});
  });
  const search = root.querySelector('[data-document-search]');
  if (!search) return;
  const category = root.querySelector('[data-document-category-filter]');
  const cards = [...root.querySelectorAll('[data-document-card]')];
  const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('fr');
  const update = () => {
    const term = normalize(search.value.trim());
    let shown = 0;
    cards.forEach(card => {
      card.hidden = !normalize(card.dataset.search).includes(term) || (category.value !== '' && card.dataset.category !== category.value);
      if (!card.hidden) shown++;
    });
    root.querySelector('[data-document-empty]').hidden = shown !== 0;
    root.querySelector('[data-document-results]').textContent = `${shown} document${shown > 1 ? 's' : ''} affiché${shown > 1 ? 's' : ''}`;
  };
  search.addEventListener('input', update);
  category.addEventListener('change', update);
  root.querySelector('[data-document-reset]').addEventListener('click', () => {
    search.value = '';
    if (category.tomselect) category.tomselect.setValue(''); else category.value = '';
    update();
  });
  update();
});
