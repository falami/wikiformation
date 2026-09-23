const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const context = { window: {}, document: { readyState: 'loading', addEventListener() {} }, Event: class { constructor(type) { this.type = type; } } };
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../public/dist/js/session-duration.js'), 'utf8'), context);
const duration = context.window.WikiFormationDuration;

function slot(start, end, pause = '', iso = true) {
  const output = { textContent: '' };
  const element = { dataset: {}, matches: selector => selector === '[data-training-slot]', querySelectorAll: () => [] };
  function input(value) {
    return { value, closest: () => element, matches: () => iso, dispatchEvent() { duration.handleDateChange(this); } };
  }
  element.start = input(start); element.end = input(end); element.pause = input(pause);
  element.querySelector = selector => {
    if (selector.includes('[dateDebut]')) return element.start;
    if (selector.includes('[dateFin]')) return element.end;
    if (selector === '[data-training-pause]') return element.pause;
    if (selector === '[data-training-duration]') return output;
    if (selector === '[data-training-presets]') return {};
    return null;
  };
  element.output = output;
  return element;
}
function change(element, field, value) {
  element[field].value = value;
  duration.handleDateChange(element[field]);
}

for (const [start, end, expected] of [
  ['08:30', '17:00', 420], ['08:00', '17:30', 480], ['09:00', '17:00', 390],
  ['09:00', '15:00', 270], ['08:00', '12:30', 270], ['13:30', '17:00', 210],
  ['06:00', '13:00', 420], ['13:00', '20:00', 420],
]) {
  test(`pause automatique fixe : ${start}–${end} → ${expected} minutes`, () => {
    assert.equal(duration.getMinutes(slot(`2026-10-07T${start}`, `2026-10-07T${end}`)), expected);
  });
}
test('trois dates historiques 08:30–17:00 donnent 21 heures sans compter les nuits', () => {
  assert.equal(duration.getMinutes(slot('2026-10-07T08:30', '2026-10-09T17:00')), 1260);
});
test('une pause explicite remplace la pause automatique, y compris zéro', () => {
  assert.equal(duration.getMinutes(slot('2026-10-07T08:30', '2026-10-07T17:00', '0')), 510);
  assert.equal(duration.getMinutes(slot('2026-10-07T08:30', '2026-10-07T17:00', '60')), 450);
  assert.equal(duration.getMinutes(slot('2026-10-07T08:30', '2026-10-07T17:00', '510')), null);
});
test('un créneau de nuit reste continu et ne déduit pas de pause déjeuner', () => {
  assert.equal(duration.getMinutes(slot('2026-10-07T22:00', '2026-10-08T06:00')), 480);
});
test('le chargement ne modifie aucun horaire existant', () => {
  const element = slot('2026-10-07T08:00', '2026-10-07T17:30');
  duration.init(element);
  assert.equal(element.end.value, '2026-10-07T17:30');
  assert.equal(duration.getMinutes(element), 480);
});
test('la fin automatique suit un début avancé ou retardé, toujours pour 7 heures', () => {
  const element = slot('2026-10-07T08:30', '2026-10-07T17:00');
  duration.init(element);
  change(element, 'start', '2026-10-07T08:00');
  assert.equal(element.end.value, '2026-10-07T16:30');
  change(element, 'start', '2026-10-07T09:00');
  assert.equal(element.end.value, '2026-10-07T17:30');
  assert.equal(duration.getMinutes(element), 420);
});
test('modifier la fin autorise une durée supérieure à 7 heures et désactive le lien', () => {
  const element = slot('2026-10-07T08:30', '2026-10-07T17:00');
  duration.init(element);
  change(element, 'end', '2026-10-07T18:00');
  assert.equal(duration.getMinutes(element), 480);
  change(element, 'start', '2026-10-07T08:00');
  assert.equal(element.end.value, '2026-10-07T18:00');
  assert.equal(duration.getMinutes(element), 510);
});
test('une nouvelle journée utilise 08:30–17:00 puis continue à suivre le début', () => {
  const element = slot('', '');
  duration.preset(element, 'day', new Date(2026, 9, 7));
  assert.equal(element.start.value, '2026-10-07T08:30');
  assert.equal(element.end.value, '2026-10-07T17:00');
  change(element, 'start', '2026-10-07T08:00');
  assert.equal(element.end.value, '2026-10-07T16:30');
});
test('les presets demi-journée proposent 3 h 30 et conservent cette durée après modification du début', () => {
  const element = slot('', '');
  duration.preset(element, 'am', new Date(2026, 9, 7));
  assert.equal(element.start.value, '2026-10-07T09:00');
  assert.equal(element.end.value, '2026-10-07T12:30');
  change(element, 'start', '2026-10-07T08:00');
  assert.equal(element.end.value, '2026-10-07T11:30');
  duration.preset(element, 'pm', new Date(2026, 9, 7));
  assert.equal(element.start.value, '2026-10-07T13:30');
  assert.equal(element.end.value, '2026-10-07T17:00');
  assert.equal(duration.getMinutes(element), 210);
});
test('saisir seulement le début propose la fin sans réécrire le début', () => {
  const element = slot('', '');
  duration.init(element);
  change(element, 'start', '2026-10-07T09:00');
  assert.equal(element.start.value, '2026-10-07T09:00');
  assert.equal(element.end.value, '2026-10-07T17:30');
});
test('le formulaire français utilise la même proposition et le même calcul que la modale ISO', () => {
  const element = slot('07/10/2026 08:30', '07/10/2026 17:00', '', false);
  duration.init(element);
  change(element, 'start', '07/10/2026 08:00');
  assert.equal(element.end.value, '07/10/2026 16:30');
  assert.equal(duration.getMinutes(element), 420);
  assert.match(element.output.textContent, /1 h 30 de pause/);
});
test('modifier la pause d’une journée liée ajuste sa fin et garde 7 heures de formation', () => {
  const element = slot('2026-10-07T08:30', '2026-10-07T17:00');
  duration.init(element);
  change(element, 'pause', '60');
  assert.equal(element.end.value, '2026-10-07T16:30');
  assert.equal(duration.getMinutes(element), 420);
});
