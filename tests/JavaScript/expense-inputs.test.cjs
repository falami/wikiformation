const assert = require('node:assert/strict');
const { test } = require('node:test');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const utils = require('../../public/dist/js/expense-inputs.js');

for (const [value, cents] of [['1 234,56', 123456], ['1\u202f234,56', 123456], ['1.234,56', 123456], ['1234.56', 123456], ['0,29', 29], ['5,50 €', 550], ['0', 0]]) {
  test(`montant français ${value} conserve les centimes`, () => assert.equal(utils.toCents(value), cents));
}
for (const value of ['abc12,50', '12,50oops', '12,3,4', '1.2.3', '1e3', '-2,00€garbage', '1,005', '9007199254740999999']) {
  test(`montant invalide ${value} ne devient pas un montant plausible`, () => assert.ok(Number.isNaN(utils.toCents(value))));
}
test('la TVA mixte conserve ses montants, sans arrondi vers le taux le plus proche', () => {
  assert.equal(utils.receiptRate({ montantHtCents: 10000, montantTvaCents: 1500, montantTtcCents: 11500, tauxTva: 20 }), null);
  assert.equal(utils.receiptRate({ montantHtCents: 10000, montantTvaCents: 1950, montantTtcCents: 11950, tauxTva: 20 }), null);
  assert.equal(utils.receiptRate({ montantHtCents: 10000, montantTvaCents: 2000, montantTtcCents: 12000, tauxTva: 20 }), 20);
  assert.equal(utils.receiptRate({ montantHtCents: 10000, montantTvaCents: 2000, montantTtcCents: 12000, tauxTva: null }), null);
  assert.equal(utils.receiptRate({ montantHtCents: 10000, montantTvaCents: 2000, montantTtcCents: 12000, tauxTva: 20, multipleVatRates: true }), null);
});
test('les montants OCR absents ne sont pas remplacés par zéro', () => {
  assert.deepEqual(utils.receiptAmounts({ montantTvaCents: 0 }), { ht: null, tva: 0, ttc: null });
  assert.equal(utils.receiptRate({ montantHtCents: 100, montantTvaCents: 100, montantTtcCents: 100 }), null);
  assert.equal(utils.validIsoDate('2026-02-30'), false);
});
test('changer de fichier ou vider le scan invalide la proposition et les requêtes en cours', () => {
  const state = utils.createScanState(), fileA = {}, fileB = {};
  state.select(fileA);
  const requestA = state.begin();
  state.select(fileB);
  const requestB = state.begin();
  assert.equal(state.complete(requestA, { montantTtcCents: 999 }), false);
  assert.equal(state.complete(requestB, { montantTtcCents: 1200 }), true);
  state.select(null);
  assert.equal(state.proposal, null);
  assert.equal(state.complete(requestB, {}), false);
});
test('relancer un scan du même fichier invalide aussi la première requête', () => {
  const state = utils.createScanState(); state.select({});
  const first = state.begin(), last = state.begin();
  assert.equal(state.complete(first, {}), false);
  assert.equal(state.complete(last, { date: '2026-01-02' }), true);
  state.applied(); assert.equal(state.proposal, null);
});
test('les noms de fichiers contenant du HTML sont échappés', () => {
  assert.equal(utils.escapeHtml('<img src=x onerror="bad()">'), '&lt;img src=x onerror=&quot;bad()&quot;&gt;');
});

function fixture(fetch) {
  class Element {
    constructor() { this.events = {}; this.style = {}; this.dataset = {}; this.files = []; this.value = ''; this.disabled = false; this.checked = false; this.options = []; this.children = []; this.classList = { toggle() {} }; }
    addEventListener(type, callback) { (this.events[type] ||= []).push(callback); }
    async fire(type, details = {}) { for (const callback of this.events[type] || []) await callback({ type, ...details }); }
    dispatchEvent(event) { this.fire(event.type, event); }
    replaceChildren() { this.children = []; }
    append(child) { this.children.push(child); }
    closest() { return null; }
  }
  const ids = ['scanFile', 'scanCamera', 'btnScan', 'btnScanClear', 'btnApplyProposal', 'scanStatus', 'scanProposal', 'scanDropzone', 'scanPreview', 'scanFileBadge', 'scanFileMeta', 'btnScanPick', 'btnScanCamera', 'scanConfidence', 'pDate', 'pTtc', 'pHt', 'pTva', 'proofPreviewWrap', 'date', 'ht', 'tva', 'ttc', 'rate', 'manual', 'proof'];
  const nodes = Object.fromEntries(ids.map(id => [id, new Element()]));
  nodes.rate.options = [{ value: '0' }, { value: '20' }];
  const form = { dataset: {} };
  for (const id of ['date', 'ht', 'tva', 'ttc', 'rate', 'manual', 'proof']) nodes[id].form = form;
  const requests = [], confirmations = [];
  const context = {
    window: { WikiExpenseInputs: utils, confirm: message => { confirmations.push(message); return true; } },
    document: { getElementById: id => nodes[id], createElement: () => new Element() },
    URL: { createObjectURL: () => 'blob:receipt', revokeObjectURL() {} },
    AbortController, setTimeout, clearTimeout,
    Event: class { constructor(type) { this.type = type; } },
    FormData: class { constructor() { this.fields = {}; } append(key, value) { this.fields[key] = value; } },
    DataTransfer: class { constructor() { this.files = []; this.items = { add: file => this.files.push(file) }; } },
    fetch: async (url, options) => { requests.push(options); return fetch(url, options); },
  };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../public/dist/js/expense-receipt.js'), 'utf8'), context);
  context.window.WikiExpenseReceipt({ url: '/scan', token: 'csrf-token', fields: { date: 'date', ht: 'ht', tva: 'tva', ttc: 'ttc', rate: 'rate', manual: 'manual', proof: 'proof' } });
  return { nodes, form, requests, confirmations };
}
const receipt = name => ({ name, type: 'image/jpeg', size: 1500 });
const response = proposal => ({ ok: true, json: async () => ({ ok: true, proposal }) });

test('appliquer une TVA mixte transfère le bon justificatif sans recalculer les centimes', async () => {
  const { nodes, form, requests } = fixture(async () => response({ date: '2026-10-26', montantHtCents: 10000, montantTvaCents: 1500, montantTtcCents: 11500 }));
  const file = receipt('ticket.jpg'); nodes.scanFile.files = [file];
  await nodes.scanFile.fire('change'); await nodes.btnScan.fire('click');
  nodes.ttc.addEventListener('input', () => { assert.equal(form.dataset.applyingReceipt, '1'); });
  await nodes.btnApplyProposal.fire('click');
  assert.equal(utils.toCents(nodes.ht.value), 10000);
  assert.equal(utils.toCents(nodes.tva.value), 1500);
  assert.equal(utils.toCents(nodes.ttc.value), 11500);
  assert.equal(nodes.manual.checked, true);
  assert.equal(nodes.proof.files[0], file);
  assert.equal(requests[0].body.fields._token, 'csrf-token');
  assert.equal(nodes.btnApplyProposal.disabled, true);
});
test('une réponse tardive ne réactive pas la proposition du reçu précédent', async () => {
  let resolve;
  const { nodes } = fixture(() => new Promise(done => { resolve = done; }));
  nodes.scanFile.files = [receipt('a.jpg')]; await nodes.scanFile.fire('change');
  const running = nodes.btnScan.fire('click');
  nodes.scanFile.files = [receipt('b.jpg')]; await nodes.scanFile.fire('change');
  resolve(response({ montantTtcCents: 12000 })); await running;
  assert.equal(nodes.scanProposal.style.display, 'none');
  assert.equal(nodes.btnApplyProposal.disabled, true);
  assert.equal(nodes.scanFileBadge.textContent, 'b.jpg');
});
test('une proposition partielle ne remplace pas les montants non lus', async () => {
  const { nodes, confirmations } = fixture(async () => response({ montantTtcCents: 550, montantHtCents: null, montantTvaCents: null }));
  nodes.ht.value = '4,00'; nodes.tva.value = '1,00'; nodes.ttc.value = '5,00';
  nodes.scanFile.files = [receipt('receipt.jpg')]; await nodes.scanFile.fire('change'); await nodes.btnScan.fire('click');
  await nodes.btnApplyProposal.fire('click');
  assert.equal(nodes.ht.value, '4,00'); assert.equal(nodes.tva.value, '1,00');
  assert.equal(utils.toCents(nodes.ttc.value), 550); assert.equal(confirmations.length, 1);
  assert.equal(nodes.manual.checked, true, 'partial scans also prevent later automatic tax calculation');
});
test('fichier incompatible ou supérieur à 8 Mo ne lance aucune analyse', async () => {
  const { nodes, requests } = fixture(() => { throw new Error('must not call'); });
  nodes.scanFile.files = [{ name: 'photo.heic', type: 'image/heic', size: 1000 }]; await nodes.scanFile.fire('change');
  assert.equal(nodes.btnScan.disabled, true);
  nodes.scanFile.files = [{ ...receipt('large.jpg'), size: 9 * 1024 * 1024 }]; await nodes.scanFile.fire('change');
  await nodes.btnScan.fire('click'); assert.equal(requests.length, 0);
});
test('erreur réseau permet de réessayer sans proposition périmée', async () => {
  const { nodes } = fixture(() => { throw new Error('Réseau indisponible'); });
  nodes.scanFile.files = [receipt('ticket.jpg')]; await nodes.scanFile.fire('change'); await nodes.btnScan.fire('click');
  assert.equal(nodes.btnScan.disabled, false); assert.equal(nodes.btnApplyProposal.disabled, true);
  assert.equal(nodes.scanStatus.textContent, 'Réseau indisponible');
});
test('scripts Twig restent syntaxiquement valides après interpolation', () => {
  for (const file of ['form', 'import_preview']) {
    const template = fs.readFileSync(path.join(__dirname, `../../templates/administrateur/depense/${file}.html.twig`), 'utf8');
    const scripts = [...template.matchAll(/<script>([\s\S]*?)<\/script>/g)];
    for (const [, source] of scripts) {
      const js = source.replace(/{{[\s\S]*?}}/g, value => value.includes('json_encode') ? '"fixture"' : 'fixture');
      assert.doesNotThrow(() => new vm.Script(js));
    }
  }
});

function importFixture(count) {
  const events = {}, json = { value: '' }, button = { disabled: false };
  const cards = Array.from({ length: count }, (_, index) => {
    const accepted = { checked: index % 2 === 0 };
    const inputs = [
      { name: `rows[${index}][accept]`, value: '1', type: 'checkbox', checked: accepted.checked },
      { name: `rows[${index}][libelle]`, value: `Ligne ${index}`, type: 'text' },
      { name: `rows[${index}][tvaDeductiblePct]`, value: '0', type: 'number' },
    ].map(input => ({ ...input, dataset: {}, disabled: false, checkValidity: () => true }));
    const card = {
      dataset: { idx: String(index) },
      querySelector: selector => selector === '.accept-switch' ? accepted : { files: index === 0 ? [{ name: 'proof.pdf' }] : [] },
      querySelectorAll: () => inputs,
      dispatchEvent() {}, inputs,
    };
    return card;
  });
  const form = {
    addEventListener: (type, listener) => { events[type] = listener; },
    querySelectorAll: selector => selector === '.imp-card' ? cards : selector === 'button[type="submit"]' ? [button] : cards.flatMap(card => card.inputs),
  };
  const template = fs.readFileSync(path.join(__dirname, '../../templates/administrateur/depense/import_preview.html.twig'), 'utf8');
  const code = template.slice(template.indexOf('  const importForm ='), template.indexOf('  const DUP_CHECK_URL ='));
  vm.runInNewContext(code, {
    document: { getElementById: id => id === 'importForm' ? form : json, querySelectorAll: () => cards },
    window: { addEventListener: (type, listener) => { events[type] = listener; } },
    Event: class { constructor(type) { this.type = type; } },
    alert() {},
  });
  return { events, json, button, cards };
}
test('un gros import transmet toutes les lignes en JSON avec les pièces jointes et les exclusions', () => {
  const { events, json, button } = importFixture(200);
  events.submit({ preventDefault() { assert.fail('batch should be submitted'); } });
  const rows = JSON.parse(json.value);
  assert.equal(Object.keys(rows).length, 200);
  assert.equal(rows['0'].hasProof, '1');
  assert.equal(rows['1'].hasProof, '0');
  assert.equal(rows['1'].accept, '0');
  assert.equal(rows['198'].tvaDeductiblePct, '0');
  assert.equal(button.disabled, true);
  let prevented = false;
  events.submit({ preventDefault() { prevented = true; } });
  assert.equal(prevented, true, 'a second click does not submit the same batch');
});
test('retour navigateur après import restaure les champs sans perdre les exclusions', () => {
  const { events, cards, button } = importFixture(3);
  cards[0].inputs[2].disabled = true;
  events.submit({ preventDefault() { assert.fail(); } });
  events.pageshow({ persisted: true });
  assert.equal(cards[0].inputs[2].disabled, true);
  assert.equal(cards[0].inputs[1].disabled, false);
  assert.equal(button.disabled, false);
  assert.equal(cards[1].inputs[0].checked, false);
});

test('doublons internes détectés même sans dépense existante, puis actualisés après correction ou retrait', () => {
  const first = { date: '2026-10-26', ttcCents: 1234 };
  const second = { date: '2026-10-26', ttcCents: 1234 };
  assert.deepEqual([...utils.localDuplicateKeys([first, second])], ['2026-10-26|1234']);
  second.ttcCents = 1300;
  assert.equal(utils.localDuplicateKeys([first, second]).size, 0);
  second.ttcCents = 1234; second.removed = true;
  assert.equal(utils.localDuplicateKeys([first, second]).size, 0);
  assert.equal(utils.localDuplicateKeys([{ date: '2026-02-30', ttcCents: 5 }, { date: '2026-02-30', ttcCents: 5 }]).size, 0);
});
