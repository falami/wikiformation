const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../public/dist/js/document-description.js'), 'utf8');

class Element {
  constructor(tag, value = '') { this.tag = tag; this.value = value; this.children = []; this.events = {}; this.nodeType = 1; this.textContent = ''; }
  append(...nodes) { this.children.push(...nodes); }
  replaceChildren(...nodes) { this.children = nodes; }
  addEventListener(event, callback) { this.events[event] = callback; }
  insertAdjacentElement(position, node) { this.preview = node; }
  matches() { return this.tag === 'textarea'; }
  querySelectorAll() { return []; }
}
function setup(value) {
  const input = new Element('textarea', value);
  let observer;
  const document = {readyState: 'complete', body: {}, querySelectorAll: () => [input], createElement: tag => new Element(tag)};
  vm.runInNewContext(source, {document, MutationObserver: class { constructor(callback) { observer = callback; } observe() {} }});
  return {input, added: node => observer([{addedNodes: [node]}])};
}
const body = input => input.preview.children[1];
const details = input => body(input).children[1]?.children.map(node => node.textContent) || [];

test('preview makes newline formatting clear without modifying the stored text', () => {
  const original = 'Formation\r\n- - Vérification\nSous-traitance\n-5 %\n• Analyse';
  const {input} = setup(original);
  assert.equal(input.value, original);
  assert.equal(body(input).children[0].textContent, 'Formation');
  assert.deepEqual(details(input), ['Vérification', 'Sous-traitance', '-5 %', 'Analyse']);
  input.value = 'Nouveau titre\nDétail ajouté';
  input.events.input();
  assert.deepEqual(details(input), ['Détail ajouté']);
  assert.equal(input.events.keydown, undefined, 'Enter remains a normal newline');
});

test('new invoice or quote rows receive a preview exactly once', () => {
  const env = setup('');
  const added = new Element('textarea', 'Prestation\nPremier module');
  env.added(added);
  const preview = added.preview;
  env.added(added);
  assert.equal(added.preview, preview);
  assert.deepEqual(details(added), ['Premier module']);
  added.value = 'Valeur remplie par la formation\nDeuxième module';
  preview.open = true;
  preview.events.toggle();
  assert.equal(body(added).children[0].textContent, 'Valeur remplie par la formation');
});

test('pasted HTML stays text and marker-only lines do not make empty bullets', () => {
  const {input} = setup('<img src=x>\n- <script>alert(1)</script>\n-\n•');
  assert.equal(body(input).children[0].tag, 'strong');
  assert.equal(body(input).children[0].textContent, '<img src=x>');
  assert.deepEqual(details(input), ['<script>alert(1)</script>']);
  assert.equal(body(input).children[1].children[0].children.length, 0);
});
