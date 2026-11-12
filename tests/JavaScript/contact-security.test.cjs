const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/dist/js/contact-security.js', 'utf8');

function setup(configured = true) {
  const callbacks = [], resets = [], pageEvents = [];
  const forms = [0, 1].map(() => {
    const events = {}, button = {disabled: false};
    const token = {value: 'stale-token', dataset: {sitekey: configured ? '0x-site' : '', context: 'session'}};
    const status = {textContent: ''};
    const retry = {hidden: true, addEventListener: (_, callback) => { events.retry = callback; }};
    const form = {querySelectorAll: () => [button], addEventListener: (name, callback) => { events[name] = callback; }};
    const container = {
      closest: () => form,
      querySelector: selector => ({
        '[data-contact-token]': token, '[data-contact-captcha-status]': status,
        '[data-contact-captcha-retry]': retry, '[data-contact-challenge]': {}
      })[selector]
    };
    return {container, events, button, token, status, retry};
  });
  const api = {render: (_, config) => { callbacks.push(config); return callbacks.length - 1; }, reset: id => resets.push(id)};
  vm.runInNewContext(source, {
    document: {querySelectorAll: () => forms.map(f => f.container), documentElement: {lang: 'fr'}},
    window: {turnstile: api, addEventListener: (_, callback) => pageEvents.push(callback)},
    Date, setTimeout, clearTimeout
  });
  return {forms, callbacks, resets, pageEvents};
}

test('widgets load on interaction and validate each form independently', async () => {
  const s = setup();
  assert.equal(s.callbacks.length, 0);
  assert.ok(s.forms.every(f => f.button.disabled && f.token.value === ''));
  await s.forms[0].events.focusin();
  await s.forms[1].events.focusin();
  assert.equal(s.callbacks.length, 2);
  assert.equal(s.callbacks[0].action, 'public_contact');
  assert.equal(s.callbacks[0].cData, 'session');
  s.callbacks[1].callback('footer-token');
  assert.equal(s.forms[1].token.value, 'footer-token');
  assert.equal(s.forms[1].button.disabled, false);
  assert.equal(s.forms[0].button.disabled, true);
  let prevented = false;
  s.forms[1].events.submit({preventDefault() { prevented = true; }});
  assert.equal(prevented, false);
});

test('expiry, provider errors and browser history invalidate tokens', async () => {
  const s = setup(), f = s.forms[0];
  await f.events.focusin();
  s.callbacks[0].callback('token');
  s.callbacks[0]['expired-callback']();
  assert.equal(f.token.value, '');
  assert.equal(f.button.disabled, true);
  assert.equal(f.retry.hidden, false);
  f.events.retry();
  assert.deepEqual(s.resets, [0]);
  s.callbacks[0].callback('token');
  s.callbacks[0]['error-callback']();
  assert.equal(f.token.value, '');
  s.callbacks[0].callback('token');
  s.pageEvents[0]({persisted: true});
  assert.equal(f.token.value, '');
  let prevented = false;
  f.events.submit({preventDefault() { prevented = true; }});
  assert.equal(prevented, true);
});

test('unconfigured forms stay blocked without requesting a widget', async () => {
  const s = setup(false);
  await s.forms[0].events.focusin();
  assert.equal(s.callbacks.length, 0);
  assert.equal(s.forms[0].button.disabled, true);
  assert.match(s.forms[0].status.textContent, /temporairement indisponible/);
});
