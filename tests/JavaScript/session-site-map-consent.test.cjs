const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const template = fs.readFileSync('templates/administrateur/session/form.html.twig', 'utf8');
const marker = "document.addEventListener('DOMContentLoaded', function () {\n  const modalEl = document.getElementById('modal-new-site');";
const start = template.indexOf(marker);
assert.ok(start !== -1);
const source = template.slice(start, template.indexOf('</script>', start));
const tick = () => new Promise(resolve => setImmediate(resolve));

function setup() {
  const nodes = {}, events = {}, requests = [], intervals = [], geoCallbacks = [];
  const calls = {maps: 0, tiles: 0, markers: 0, views: []};
  for (const id of ['modal-new-site', 'new-site-autocomplete', 'new-site-adresse', 'new-site-cp', 'new-site-ville', 'new-site-dept', 'new-site-region', 'new-site-pays', 'new-site-lat', 'new-site-lng', 'new-site-placeId', 'new-site-formatted', 'new-site-latlng-display', 'new-site-map', 'btn-newsite-map-enable', 'btn-newsite-clear', 'btn-newsite-geoloc']) {
    nodes[id] = {value: '', handlers: {}, addEventListener(name, handler) { this.handlers[name] = handler; }};
  }
  nodes['new-site-adresse'].value = '1 rue Exemple';
  nodes['new-site-cp'].value = '30000'; nodes['new-site-ville'].value = 'Nîmes';
  const map = {handlers: {}, setView(position) { calls.views.push([...position]); return this; }, getZoom() { return 10; }, invalidateSize() {}, on(name, handler) { this.handlers[name] = handler; }, removeLayer() {}};
  const window = {google: {maps: {places: {PlaceAutocompleteElement: class {}}}}};
  vm.runInNewContext(source, {
    window, URL,
    document: {getElementById: id => nodes[id], addEventListener(name, handler) { events[name] = handler; }},
    setTimeout() {}, clearInterval() {}, setInterval(fn) { intervals.push(fn); return intervals.length; },
    fetch(url, options) { return new Promise(resolve => requests.push({url, options, resolve})); },
    navigator: {geolocation: {getCurrentPosition(success) { geoCallbacks.push(success); }}},
    alert() {}, console: {warn() {}},
    L: {
      map() { calls.maps++; return map; },
      tileLayer() { calls.tiles++; return {addTo() {}}; },
      marker() { calls.markers++; return {addTo() { return this; }, on() {}, setLatLng() {}}; },
    },
  });
  events.DOMContentLoaded();
  return {
    nodes, calls, requests, map, geoCallbacks,
    click: id => nodes[id].handlers.click(),
    edit(id, value) { nodes[id].value = value; nodes[id].handlers.input(); },
    enableGoogle() { events['wiki:enable-google-maps'](); intervals.at(-1)(); },
    respond(index, lat, lon) { requests[index].resolve({ok: true, json: async () => [{lat, lon}]}); },
  };
}

test('Only explicit activation loads OSM; double activation preserves the one pending lookup', async () => {
  const f = setup();
  assert.equal(f.calls.maps, 0); assert.equal(f.requests.length, 0);
  assert.equal(f.nodes['new-site-autocomplete'].handlers['gmp-select'], undefined);
  f.click('btn-newsite-map-enable'); f.click('btn-newsite-map-enable');
  assert.equal(f.calls.maps, 1); assert.equal(f.calls.tiles, 1); assert.equal(f.requests.length, 1);
  assert.equal(f.requests[0].options.credentials, 'omit');
  f.respond(0, '43.8', '4.3'); await tick();
  assert.equal(f.nodes['new-site-lat'].value, '43.800000');
  assert.equal(f.nodes['new-site-lng'].value, '4.300000');
});

test('Manual address changes clear coordinates and ignore late Nominatim without another disclosure', async () => {
  const f = setup();
  f.click('btn-newsite-map-enable');
  f.nodes['new-site-lat'].value = '44'; f.nodes['new-site-lng'].value = '4';
  f.nodes['new-site-placeId'].value = 'old-place'; f.nodes['new-site-formatted'].value = 'old address';
  f.edit('new-site-ville', 'Lyon');
  for (const id of ['new-site-lat', 'new-site-lng', 'new-site-placeId', 'new-site-formatted']) assert.equal(f.nodes[id].value, '');
  f.respond(0, '43.8', '4.3'); await tick();
  assert.equal(f.nodes['new-site-lat'].value, ''); assert.equal(f.requests.length, 1);
  f.click('btn-newsite-map-enable');
  assert.equal(f.requests.length, 2); assert.match(decodeURIComponent(f.requests[1].url).replaceAll('+', ' '), /Lyon/);
});

test('A manually chosen map point wins over a late provider response', async () => {
  const f = setup();
  f.click('btn-newsite-map-enable');
  f.map.handlers.click({latlng: {lat: 47, lng: 2}});
  f.respond(0, '43.8', '4.3'); await tick();
  assert.equal(f.nodes['new-site-lat'].value, '47.000000');
  assert.equal(f.nodes['new-site-lng'].value, '2.000000');
});

test('Late geolocation and Google details cannot overwrite a newer manual address', async () => {
  const f = setup();
  f.click('btn-newsite-geoloc');
  f.edit('new-site-adresse', '2 rue Nouvelle');
  f.geoCallbacks[0]({coords: {latitude: 49, longitude: 3}});
  assert.equal(f.nodes['new-site-lat'].value, ''); assert.equal(f.nodes['btn-newsite-geoloc'].disabled, false);
  f.enableGoogle();
  let resolveDetails;
  const pending = f.nodes['new-site-autocomplete'].handlers['gmp-select']({placePrediction: {toPlace() { return {fetchFields: () => new Promise(resolve => { resolveDetails = resolve; }), location: {lat: () => 50, lng: () => 4}, addressComponents: []}; }}});
  f.edit('new-site-adresse', '3 rue Manuelle');
  resolveDetails(); await pending;
  assert.equal(f.nodes['new-site-adresse'].value, '3 rue Manuelle');
  assert.equal(f.nodes['new-site-lat'].value, '');
  assert.equal(f.calls.maps, 0); assert.equal(f.requests.length, 0);
});
