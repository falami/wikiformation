const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/dist/js/evaluation-review.js', 'utf8');

function setup(snapshot) {
  const field = (name, value, max, type = 'number') => ({name, value, max, type, checked: false, disabled: false, validity: {valid: true}, checkValidity() { return this.validity.valid; }});
  const points = field('practice[p1][points]', '2', '4');
  const second = field('practice[p2][points]', '', '2');
  const na = field('practice[p2][na]', '1', '', 'checkbox');
  const comment = field('practice[p1][comment]', '', '', 'textarea');
  const fields = [points, second, na, comment];
  const nodes = Object.fromEntries(['[data-save-status]', '[data-export-draft]', '[data-score-progress]', '[data-live-score="theory"]', '[data-live-score="practice"]'].map(key => [key, {textContent: '', hidden: true, addEventListener() {}}]));
  const events = {};
  const version = {value: '1'};
  const rows = [
    {querySelector: () => null, querySelectorAll: () => [points]},
    {querySelector: () => na, querySelectorAll: () => [second]}
  ];
  const form = {
    dataset: {draftKey: 'test', editable: '1'},
    elements: {namedItem: name => name === 'version' ? version : {}},
    querySelector: selector => nodes[selector] || null,
    querySelectorAll: selector => selector === '[data-practice-item]' ? rows : selector === 'button[name="action"]' ? [] : fields,
    addEventListener: (name, fn) => { events[name] = fn; }
  };
  let stored = snapshot ? JSON.stringify(snapshot) : null;
  vm.runInNewContext(source, {
    document: {getElementById: () => form},
    window: {addEventListener() {}},
    localStorage: {getItem: () => stored, setItem: (_, value) => { stored = value; }},
    setTimeout() {}, clearTimeout() {}
  });
  return {points, second, na, comment, nodes, events, stored: () => JSON.parse(stored)};
}

test('partial score uses only assessed criteria and zero is a grade', () => {
  const s = setup();
  assert.match(s.nodes['[data-live-score="practice"]'].textContent, /10,00.*provisoire/);
  s.second.value = '0';
  s.events.input({target: s.second});
  assert.equal(s.nodes['[data-live-score="practice"]'].textContent, '6,67 / 20');
  s.na.checked = true;
  s.events.change({target: s.na});
  assert.equal(s.second.disabled, true);
  assert.equal(s.nodes['[data-live-score="practice"]'].textContent, '10,00 / 20');
});

test('edits are stored synchronously and recovered after reload', () => {
  const s = setup();
  s.comment.value = 'Geste à revoir';
  s.events.input({target: s.comment});
  const restored = setup(s.stored());
  assert.equal(restored.comment.value, 'Geste à revoir');
  assert.match(restored.nodes['[data-save-status]'].textContent, /récupérée/);
});

test('stale local copy never replaces current server values', () => {
  const s = setup({version: '0', values: {'practice[p1][points]': '4'}});
  assert.equal(s.points.value, '2');
  assert.equal(s.nodes['[data-export-draft]'].hidden, false);
  assert.match(s.nodes['[data-save-status]'].textContent, /ancienne version/);
});
