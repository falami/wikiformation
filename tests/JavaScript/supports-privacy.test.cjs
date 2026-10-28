const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const template = fs.readFileSync(path.join(__dirname, '../../templates/stagiaire/supports.html.twig'), 'utf8');
const renderSource = template.slice(template.indexOf('    function render(list){'), template.indexOf('    function applyFilter(){'));

test('support names and metadata are text, never executable HTML', () => {
  const children = [];
  const wrap = {innerHTML: '', appendChild(child) { children.push(child); }};
  const document = {createElement() {
    const elements = Object.fromEntries(['.name', '.support-meta', 'a'].map(selector => [selector, {}]));
    return {innerHTML: '', elements, querySelector(selector) { return elements[selector]; }};
  }};
  const hostile = '<img src=x onerror="stealAccount()">';
  const row = {titre: hostile, mimeType: hostile, sessionCode: hostile, url: '/uploads/supports/library/document.txt'};
  vm.runInNewContext(renderSource + '\nrender([row]);', {wrap, document, row});
  assert.equal(children.length, 1);
  assert.ok(!children[0].innerHTML.includes(hostile));
  assert.equal(children[0].elements['.name'].textContent, hostile);
  assert.equal(children[0].elements['.name'].title, hostile);
  assert.ok(children[0].elements['.support-meta'].textContent.includes(hostile));
  assert.equal(children[0].elements.a.href, row.url);
});

test('an empty support list still renders its empty state', () => {
  const wrap = {innerHTML: ''};
  vm.runInNewContext(renderSource + '\nrender([]);', {wrap});
  assert.match(wrap.innerHTML, /Aucun support/);
});
