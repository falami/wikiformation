const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../../public/dist/js/external-map.js'), 'utf8');

function browser(src = 'https://www.google.com/maps?q=Paris&output=embed') {
  const listeners = {}, created = [];
  const canvas = {children: [], get childElementCount() { return this.children.length; }, append(child) { this.children.push(child); }, replaceChildren() { this.children = []; }};
  const placeholder = {hidden: false}, error = {textContent: ''};
  const map = {dataset: {mapSrc: src}, querySelector: selector => ({'[data-map-canvas]': canvas, '[data-map-placeholder]': placeholder, '[data-map-error]': error})[selector]};
  const button = {closest: () => map};
  const root = {querySelectorAll: () => [map]};
  const document = {...root, addEventListener(name, fn) { listeners[name] = fn; }, createElement(tag) { const frame = {tag, focus() { this.focused = true; }};created.push(frame);return frame; }};
  const window = {};
  const run = () => vm.runInNewContext(source, {window, document, URL});run();
  const click = () => listeners.click({target: {closest: () => button}, preventDefault() {}});
  return {created, canvas, placeholder, error, listeners, click, root, run};
}

test('creates one external iframe only after an explicit click', () => {
  const b = browser();assert.equal(b.created.length, 0);assert.equal(b.placeholder.hidden, false);
  b.click();b.click();b.run();
  assert.equal(b.created.length, 1);assert.equal(b.canvas.children.length, 1);
  assert.equal(b.created[0].src, 'https://www.google.com/maps?q=Paris&output=embed');
  assert.equal(b.created[0].referrerPolicy, 'no-referrer');assert.equal(b.created[0].focused, true);
  assert.equal(b.placeholder.hidden, true);
});
test('refuses malformed and unexpected external URLs', () => {
  for (const src of ['', 'javascript:alert(1)', 'http://www.google.com/maps', 'https://www.google.com.evil.test/maps', 'https://www.google.com/other']) {
    const b = browser(src);b.click();assert.equal(b.created.length, 0);assert.match(b.error.textContent, /indisponible/);
  }
});
test('closing a modal or caching a Turbo page removes the frame and restores the preview', () => {
  const b = browser();b.click();b.listeners['hidden.bs.modal']({target: b.root});
  assert.equal(b.canvas.childElementCount, 0);assert.equal(b.placeholder.hidden, false);
  b.click();assert.equal(b.created.length, 2);b.listeners['turbo:before-cache']();
  assert.equal(b.canvas.childElementCount, 0);assert.equal(b.placeholder.hidden, false);
});
