const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../../public/dist/js/cookie-consent.js'), 'utf8');
function browser(cookies = {}, analytics = false) {
  const jar = new Map(Object.entries(cookies)), writes = [], events = {}, ga = [];
  const elements = Object.fromEntries(['cookie-banner', 'cookie-preferences', 'reject-cookies'].map(id => [id, {hidden: true, focus() { this.focused = true; }}]));
  let reloads = 0;
  const document = {readyState: 'loading', getElementById: id => elements[id], addEventListener(type, fn) { events[type] = fn; }};
  Object.defineProperty(document, 'cookie', {get: () => [...jar].map(([key, value]) => `${key}=${value}`).join('; '), set: value => {
    writes.push(value);const [first] = value.split(';');const [key, data] = first.split('=');
    if (/Max-Age=0(?:;|$)/.test(value)) jar.delete(key);else jar.set(key, data);
  }});
  const window = {addEventListener(type, fn) { events[type] = fn; }};
  if (analytics) window.gtag = (...args) => ga.push(args);
  vm.runInNewContext(source, {window, document, location: {protocol: 'https:', hostname: 'www.wikiformation.fr', reload() { reloads++; }}});
  const click = id => events.click({target: {closest: () => ({id})}, preventDefault() {}});
  return {jar, writes, elements, window, ga, click, events, document, reloads: () => reloads};
}
test('no consent or an old version never enables analytics', () => {
  for (const initial of [{}, {cookieConsent: 'accepted'}, {cookieConsent: 'accepted', cookieConsentVersion: 'old'}]) {
    const b = browser(initial);b.events.DOMContentLoaded();
    assert.equal(b.elements['cookie-banner'].hidden, false);
    assert.equal(b.window['ga-disable-G-KKCHBDBP0H'], true);
  }
});
test('accept and reject have the same six-month expiry and Secure cookies', () => {
  for (const value of ['accept', 'reject']) {
    const b = browser();b.click(`${value}-cookies`);
    assert.equal(b.jar.get('cookieConsent'), `${value}ed`);
    assert.equal(b.jar.get('cookieConsentVersion'), '2026-10-25');
    assert.ok(b.writes.every(cookie => cookie.includes('Max-Age=15552000') && cookie.includes('SameSite=Lax; Secure')));
  }
});
test('withdrawal disables loaded analytics, clears analytics cookies and fully reloads', () => {
  const b = browser({cookieConsent: 'accepted', cookieConsentVersion: '2026-10-25', _ga: 'id', _ga_TEST: 'id2', WKFORMATIONSESSID: 'private'}, true);
  b.click('manage-cookies');assert.equal(b.jar.get('cookieConsent'), 'accepted', 'Opening preferences is not a withdrawal.');
  b.click('reject-cookies');
  assert.equal(b.reloads(), 1);assert.equal(b.window['ga-disable-G-KKCHBDBP0H'], true);
  assert.equal(b.jar.has('_ga'), false);assert.equal(b.jar.has('_ga_TEST'), false);
  assert.equal(b.jar.get('WKFORMATIONSESSID'), 'private');assert.equal(b.ga[0][2].analytics_storage, 'denied');
  assert.ok(b.writes.some(write => write.includes('Domain=wikiformation.fr')));
});
test('preferences work after Turbo navigation and restored pages', () => {
  const b = browser({cookieConsent: 'rejected', cookieConsentVersion: '2026-10-25'});
  b.events['turbo:load']();assert.equal(b.elements['cookie-banner'].hidden, true);assert.equal(b.elements['cookie-preferences'].hidden, false);
  b.click('cookie-preferences');assert.equal(b.elements['cookie-banner'].hidden, false);
  b.events.pageshow();assert.equal(b.elements['cookie-banner'].hidden, true);
});
