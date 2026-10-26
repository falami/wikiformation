const assert = require('node:assert/strict');
const { test } = require('node:test');
const editor = require('../../public/dist/js/satisfaction-editor.js');

test('l’ajout après une erreur de validation respecte les clés de collection éparses', () => {
  assert.equal(editor.nextIndex('2', [
    'satisfaction_template[chapters][0][titre]',
    'satisfaction_template[chapters][8][questions][15][libelle]',
  ], 'chapters'), 9);
  assert.equal(editor.nextIndex('2', [
    'satisfaction_template[chapters][8][questions][0][libelle]',
    'satisfaction_template[chapters][8][questions][15][libelle]',
  ], 'questions'), 16);
});

test('supprimer la dernière entrée ne réutilise pas un index déjà attribué', () => {
  assert.equal(editor.nextIndex('12', ['form[chapters][0][titre]'], 'chapters'), 12);
  assert.equal(editor.nextIndex('invalide', [], 'chapters'), 0);
});

test('une nouvelle question est rattachée au chapitre existant et possède un id unique', () => {
  const prototype = '<label for="form___chapter_____question___libelle">Libellé</label>' +
    '<input id="form___chapter_____question___libelle" name="form[chapters][__chapter__][questions][__question__][libelle]">';
  const html = editor.fillPrototype(prototype, 7, 4);
  assert.match(html, /name="form\[chapters\]\[7\]\[questions\]\[4\]\[libelle\]"/);
  assert.match(html, /for="form_7_4_libelle"/);
  assert.match(html, /id="form_7_4_libelle"/);
  assert.doesNotMatch(html, /__chapter__|__question__/);
});

test('le prototype de chapitre garde le token des questions pour leur ajout ultérieur', () => {
  assert.equal(editor.fillPrototype('form[chapters][__chapter__][questions][__question__]', 3), 'form[chapters][3][questions][__question__]');
  assert.equal(editor.fillPrototype('form[chapters][3][questions][__question__]', 3, 2), 'form[chapters][3][questions][2]');
});

function questionFixture(type = 'scale') {
  const field = classes => ({
    hidden: false, style: { display: 'none' }, value: 'Option conservée',
    classList: { contains: name => classes.includes(name) },
  });
  const scale = field(['js-for-scale']);
  const choice = field(['js-for-choice', 'js-for-multichoice']);
  const text = field(['js-for-text', 'js-for-textarea']);
  const metricField = { hidden: false, style: {} };
  const metric = { value: 'organism_rating', disabled: false, closest: () => metricField };
  const typeSelect = { value: type };
  const help = { textContent: '' };
  const pill = { innerHTML: '' };
  const nodes = { '.js-qtype': typeSelect, '.js-typepill': pill, '.js-metrickey': metric, '.js-metric-help': help };
  return {
    question: { querySelector: selector => nodes[selector] || null, querySelectorAll: () => [scale, choice, text] },
    scale, choice, text, metric, metricField, typeSelect, help, pill,
  };
}

test('les options de choix deviennent réellement visibles malgré le CSS display:none', () => {
  const fixture = questionFixture('choice');
  editor.applyQuestionUI(fixture.question);
  assert.equal(fixture.choice.hidden, false);
  assert.equal(fixture.choice.style.display, 'block');
  assert.equal(fixture.scale.hidden, true);
  assert.equal(fixture.text.hidden, true);
  assert.equal(fixture.choice.value, 'Option conservée');
  fixture.typeSelect.value = 'textarea';
  editor.applyQuestionUI(fixture.question);
  assert.equal(fixture.text.style.display, 'block');
  assert.equal(fixture.choice.hidden, true);
  assert.equal(fixture.choice.value, 'Option conservée');
});

test('les KPI sont réservés aux échelles et le changement de type efface la liaison', () => {
  const fixture = questionFixture();
  editor.applyQuestionUI(fixture.question);
  assert.equal(fixture.metric.value, 'organism_rating');
  assert.equal(fixture.metric.disabled, false);
  assert.match(fixture.help.textContent, /organisme/);
  fixture.typeSelect.value = 'yes_no';
  editor.applyQuestionUI(fixture.question);
  assert.equal(fixture.metric.value, '');
  assert.equal(fixture.metric.disabled, true);
  assert.equal(fixture.metricField.hidden, true);
  fixture.typeSelect.value = 'scale';
  editor.applyQuestionUI(fixture.question);
  assert.equal(fixture.metric.disabled, false);
  assert.equal(fixture.metricField.hidden, false);
  assert.equal(fixture.scale.style.display, 'block');
});

test('DOMContentLoaded et turbo:load ne doublent ni les actions ni Sortable, sans TomSelect', () => {
  const previousSortable = globalThis.Sortable;
  let instances = 0;
  globalThis.Sortable = class { constructor() { instances++; } };
  const events = [];
  const form = {
    querySelector: () => null, querySelectorAll: () => [],
    addEventListener: type => events.push(type),
  };
  const chapters = { dataset: {}, querySelectorAll: () => [] };
  const doc = {
    getElementById: id => ({ tplForm: form, chapters })[id] || null,
    querySelectorAll: () => [],
  };
  try {
    const first = editor.boot(doc);
    const second = editor.boot(doc);
    assert.equal(first, second);
    assert.equal(instances, 1);
    assert.deepEqual(events, ['click', 'input', 'change', 'submit']);
    assert.equal(first.dirty, false);
  } finally {
    if (previousSortable === undefined) delete globalThis.Sortable;
    else globalThis.Sortable = previousSortable;
  }
});
