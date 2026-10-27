const assert = require('node:assert/strict');
const { test } = require('node:test');
const navigation = require('../../public/dist/js/workspace-navigation.js');

test('la recherche ignore les accents, la casse et les espaces superflus', () => {
  assert.equal(navigation.normalize('  ÉLÈVES   &   Électricité '), 'eleves & electricite');
  assert.equal(navigation.normalize('Évaluation'), navigation.normalize('E\u0301valuation'));
  assert.equal(navigation.normalize(null), '');
});

test('le texte du menu reste lisible sur des couleurs organisme claires, sombres et intermédiaires', () => {
  const backgrounds = [[35, 51, 66], [255, 255, 255], [0, 0, 0], [128, 128, 128], [255, 193, 7], [115, 66, 152], [14, 138, 112]];
  for (const background of backgrounds) {
    const ink = navigation.readableInk(background);
    assert.ok(navigation.contrast(background, ink) >= 4.5, `Contraste insuffisant pour ${background}`);
  }
});

test('l’accent jaune est conservé sur le menu bleu nuit', () => {
  const secondary = [255, 193, 7];
  assert.deepEqual(navigation.readableAccent([35, 51, 66], secondary), secondary);
});

test('l’accent est adapté lorsqu’il se confond avec le fond du menu', () => {
  const palettes = [
    [[255, 255, 255], [255, 193, 7]],
    [[35, 51, 66], [12, 40, 61]],
    [[128, 128, 128], [145, 130, 135]],
    [[0, 0, 0], [0, 0, 0]],
    [[255, 255, 255], [255, 255, 255]],
  ];
  for (const [primary, secondary] of palettes) {
    const accent = navigation.readableAccent(primary, secondary);
    assert.ok(navigation.contrast(primary, accent) >= 4.5, `Accent illisible pour ${primary}/${secondary}`);
    assert.ok(accent.every(channel => channel >= 0 && channel <= 255));
  }
});
