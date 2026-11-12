(() => {
'use strict';
const form = document.querySelector('[data-caces-multi]');
if (!form) return;
const categories = JSON.parse(form.dataset.categoryMap);
const registrations = JSON.parse(form.dataset.registrations);
const initial = JSON.parse(form.dataset.rows);
const rec = form.querySelector('[name=recommendation]');
const session = form.querySelector('[name=session]');
const rows = form.querySelector('[data-practice-rows]');
const add = form.querySelector('[data-add-practice]');
let sequence = 0;
const enhance = select => {
  if (window.TomSelect) new TomSelect(select, {create:false, maxOptions:null, plugins: select.multiple ? ['remove_button'] : [], placeholder:select.multiple?'Tous les stagiaires éligibles':'Choisir…'});
};
const update = () => {
  const count = rows.children.length;
  form.querySelector('[data-practice-count]').textContent = count + ' épreuve' + (count > 1 ? 's' : '');
  form.querySelector('[data-assignment-summary]').textContent = `1 théorie commune · ${count} épreuve${count > 1 ? 's' : ''} pratique${count > 1 ? 's' : ''} · 1 synthèse par stagiaire`;
  add.disabled = count >= (categories[rec.value] || []).length;
  [...rows.children].forEach((row, i) => {
    row.querySelector('[data-row-title]').textContent = `Épreuve ${String(i + 1).padStart(2, '0')} · R.${rec.value}`;
    row.querySelector('[data-remove-practice]').disabled = count === 1;
    const field = row.querySelector('[data-field=category]');
    const duplicate = [...rows.querySelectorAll('[data-field=category]')].filter(s => s.value === field.value).length > 1;
    field.setCustomValidity(duplicate ? 'Cette catégorie est déjà sélectionnée.' : '');
  });
};
function create(values = {}) {
  const row = form.querySelector('template').content.firstElementChild.cloneNode(true);
  const index = sequence++;
  const fields = {};
  row.querySelectorAll('[data-field]').forEach(select => {
    const key = select.dataset.field;
    fields[key] = select;
    select.id = `practice-${index}-${key}`;
    select.name = `practices[${index}][${key}]${select.multiple?'[]':''}`;
    row.querySelector(`[data-label=${key}]`).htmlFor = select.id;
  });
  const allTemplates = [...fields.template.options].map(option=>option.cloneNode(true));
  const refreshTemplates = (selected = '') => {
    fields.template.tomselect?.destroy();
    fields.template.replaceChildren(...allTemplates.filter(o=>!o.value || (o.dataset.rec===rec.value && o.dataset.category===fields.category.value)).map(o=>o.cloneNode(true)));
    fields.template.value = selected;
    if (!fields.template.value && fields.template.options.length === 2) fields.template.selectedIndex = 1;
    row.querySelector('[data-empty]').hidden = fields.template.options.length > 1;
    const link = row.querySelector('[data-create]');
    const url = new URL(link.href);url.searchParams.set('recommendation',rec.value);url.searchParams.set('category',fields.category.value);link.href=url.href;
    enhance(fields.template);
  };
  row.refreshCategories = () => {
    fields.category.tomselect?.destroy();
    const used = [...rows.querySelectorAll('[data-field=category]')].filter(s=>s!==fields.category).map(s=>s.value);
    const list = categories[rec.value] || [];
    fields.category.replaceChildren(...list.map(c=>new Option(c,c)));
    fields.category.value = list.includes(values.category) ? values.category : (list.find(c=>!used.includes(c)) || list[0]);
    values.category = null;
    enhance(fields.category);refreshTemplates(values.template || '');values.template = null;
  };
  row.refreshLearners = (selected = []) => {
    fields.learners.tomselect?.destroy();
    fields.learners.replaceChildren(...(registrations[session.value] || []).map(u=>new Option(u.name,String(u.id),false,selected.map(String).includes(String(u.id)))));
    enhance(fields.learners);
  };
  rows.append(row);
  row.refreshCategories();row.refreshLearners(values.learners || []);
  fields.tester.value = values.tester || '';enhance(fields.tester);
  fields.category.addEventListener('change',()=>{refreshTemplates();update();});
  row.querySelector('[data-remove-practice]').addEventListener('click',()=>{row.querySelectorAll('select').forEach(s=>s.tomselect?.destroy());row.remove();update();add.focus();});
  update();
  return row;
}
Object.values(initial).forEach(create);
if (!rows.children.length) create();
add.addEventListener('click',()=>{const row=create();row.querySelector('[data-field=category]').tomselect?.focus();});
rec.addEventListener('change',()=>{[...rows.children].forEach(r=>r.refreshCategories());update();});
session.addEventListener('change',()=>{[...rows.children].forEach(r=>r.refreshLearners());});
form.addEventListener('submit',event=>{
  update();
  if(!form.checkValidity()){event.preventDefault();form.reportValidity();return;}
  const button = form.querySelector('[type=submit]');button.disabled=true;button.textContent='Création des épreuves…';
});
})();
