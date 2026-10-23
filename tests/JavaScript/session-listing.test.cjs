const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const code = fs.readFileSync(path.join(__dirname, '../../templates/administrateur/session/_listing_ajax.js.twig'), 'utf8');
function setup() {
  const context = {};
  vm.runInNewContext(code, context);
  const requests = [], states = [], draws = [], settings = {oLanguage: {}};
  const ajax = context.createSessionListingAjax({
    url: '/fr/administrateur/1/session/ajax',
    getFilters: () => ({statusFilter: '["done","published"]', formationFilter: 'all'}),
    onState: (state, message) => states.push({state, message}),
    request(options) {
      const xhr = {status: 0, responseText: '', abort() { options.error(xhr, 'abort'); }};
      requests.push({options, xhr});
      return xhr;
    }
  });
  function load(draw = 1) {
    return ajax({draw, start: 25, length: 25, order: [{column: 1, dir: 'asc'}],
      columns: [{name: 'dossier'}, {name: 'dates'}], search: {value: 'Formation été'}},
      json => draws.push(json), settings);
  }
  return {ajax, load, requests, states, draws, settings};
}
function result(draw = 1) { return {draw, recordsTotal: 49, recordsFiltered: 30, data: [{infos: 'Session test'}]}; }

test('lecture GET compacte conserve tri, recherche, filtres et pagination', () => {
  const page = setup(); page.load();
  const options = page.requests[0].options;
  assert.equal(options.type, 'GET');
  assert.equal(page.ajax.url, options.url); // Discovery by shared multi-select filters.
  assert.equal(options.data.searchTerm, 'Formation été');
  assert.equal(options.data.statusFilter, '["done","published"]');
  assert.equal(options.data.orderBy, 'dates');
  assert.equal(options.data.orderDir, 'asc');
  assert.equal(options.data.start, 25);
  assert.equal(options.data.length, 25);
  assert.equal('columns' in options.data, false);
  options.success(result());
  assert.equal(page.draws[0].data[0].infos, 'Session test');
  assert.equal(page.states.at(-1).state, 'ready');
});

test('503 Tiger Protect termine le chargement et permet une nouvelle tentative réussie', () => {
  const page = setup(); page.load();
  const first = page.requests[0];
  first.xhr.status = 503;
  first.xhr.responseText = '<html><div class="o2s-browser-check"><script>unsafe()</script></div></html>';
  first.options.error(first.xhr, 'error');
  assert.equal(page.draws.length, 1);
  assert.equal(page.draws[0].draw, 1);
  assert.equal(page.draws[0].data.length, 0);
  assert.match(page.states.at(-1).message, /hébergeur/);
  assert.doesNotMatch(page.states.at(-1).message, /unsafe|<html>/);
  assert.match(page.settings.oLanguage.sEmptyTable, /indisponible/);
  page.load(2);
  page.requests[1].options.success(result(2));
  assert.equal(page.draws.length, 2);
  assert.equal(page.states.at(-1).state, 'ready');
  assert.doesNotMatch(page.settings.oLanguage.sEmptyTable, /indisponible/);
});

for (const [status, code, message] of [
  ['timeout', 0, /trop de temps/], ['error', 0, /Impossible/],
  ['error', 403, /droits/], ['parsererror', 200, /page au lieu/]
]) test(`l’échec ${status}/${code} ne laisse pas le tableau en chargement`, () => {
  const page = setup(); page.load();
  page.requests[0].xhr.status = code;
  page.requests[0].options.error(page.requests[0].xhr, status);
  assert.equal(page.draws.length, 1);
  assert.match(page.states.at(-1).message, message);
});

test('réponse JSON invalide ou erreur serveur ne se présente pas comme une liste vide réussie', () => {
  for (const json of [null, '<html>Login</html>', {}, {...result(), error: 'SQL secret'}, {...result(), recordsTotal: null}, result(0)]) {
    const page = setup(); page.load(); page.requests[0].options.success(json);
    assert.equal(page.states.at(-1).state, 'error');
    assert.equal(page.draws.length, 1);
    assert.doesNotMatch(page.states.at(-1).message, /SQL secret/);
  }
});

test('une ancienne requête annulée ou terminée tardivement ne remplace pas les filtres actuels', () => {
  const page = setup(); page.load(1); page.load(2);
  assert.equal(page.draws.length, 0);
  page.requests[1].options.success(result(2));
  page.requests[0].options.success(result(1));
  page.requests[0].options.error(page.requests[0].xhr, 'error');
  assert.equal(page.draws.length, 1);
  assert.equal(page.draws[0].draw, 2);
  assert.equal(page.states.at(-1).state, 'ready');
});
