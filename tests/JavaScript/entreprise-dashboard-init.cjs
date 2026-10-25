const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

test('dashboard head script defers modal bindings until DOM ready', () => {
  const template = fs.readFileSync(path.join(__dirname, '../../templates/entreprise/dashboard.html.twig'), 'utf8');
  const script = template.split('<script>')[1].split('</script>')[0]
    // Twig URLs are irrelevant to initialization; retain valid JS for quoted and unquoted values.
    .replace(/{{[\s\S]*?}}/g, (expression) => expression.includes('json_encode') ? '"/fixture"' : '/fixture');
  const ready = [];
  vm.runInNewContext(script, {
    document: { getElementById: () => null },
    $: callback => { assert.equal(typeof callback, 'function'); ready.push(callback); },
  });
  assert.equal(ready.length, 1, 'table and calendar initialization must be registered even before the DOM exists');
});
