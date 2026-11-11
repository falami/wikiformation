const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync(require('node:path').join(__dirname, '../../public/dist/js/entity-hero.js'), 'utf8');

test('les bandeaux adaptent leur contraste et suivent un changement de thème', () => {
  let primary = 'rgb(106, 46, 51)', secondary = 'rgb(95, 92, 84)';
  const values = {}, events = {};
  const document = {
    readyState: 'complete',
    body: { append() {} },
    createElement: () => ({ style: {}, remove() {} }),
    documentElement: { style: { setProperty: (key, value) => { values[key] = value; } } },
    addEventListener: (name, callback) => { events[name] = callback; }
  };
  vm.runInNewContext(source, {
    document,
    getComputedStyle: element => ({
      color: element.style.color.includes('principal') ? primary : secondary
    })
  });
  assert.equal(values['--wf-hero-ink'], '#ffffff');
  assert.equal(values['--wf-accent-ink'], '#ffffff');
  assert.equal(values['--wf-hero-accent'], '#ffffff');
  primary = 'rgb(255, 255, 255)';
  secondary = 'rgb(255, 193, 7)';
  events['theme:change']();
  assert.equal(values['--wf-hero-ink'], '#000000');
  assert.equal(values['--wf-accent-ink'], '#000000');
  primary = 'rgb(35, 51, 66)';
  events['turbo:load']();
  assert.equal(values['--wf-hero-ink'], '#ffffff');
  assert.equal(values['--wf-hero-accent'], 'var(--theme-color-secondaire,#ffc107)');
});
