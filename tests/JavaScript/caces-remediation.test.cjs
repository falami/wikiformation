const { test } = require('node:test');
const assert = require('node:assert/strict');
const grade = require('../../public/dist/js/caces-practice-score.js');

const schema = {
  durationMinutes: 60,
  themes: [15, 25, 10, 50].map((points, i) => ({
    title: `Thème ${i}`, questions: [{ id: `q${i}`, label: `Critère ${i}`, points }]
  }))
};

test('58/100 unlocks every theme even when every individual minimum is met', () => {
  const result = grade(schema, { q0: 9, q1: 13, q2: 8, q3: 28 }, 60);
  assert.equal(result.score, 58);
  assert.ok(result.themes.every(t => t.score >= t.threshold && t.remediationEligible));
});

test('70/100 keeps successful themes disabled', () => {
  const result = grade(schema, { q0: 9, q1: 13, q2: 8, q3: 40 }, 60);
  assert.equal(result.score, 70);
  assert.ok(result.themes.every(t => !t.remediationEligible));
});

test('incomplete scoring does not unlock every theme', () => {
  const result = grade(schema, { q0: 9, q1: 13, q2: 8 }, 60);
  assert.equal(result.allThemesEligible, false);
  assert.ok(result.themes.every(t => !t.remediationEligible));
});
