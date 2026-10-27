const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const template = fs.readFileSync('templates/administrateur/site/form.html.twig', 'utf8');
const source = template.slice(template.indexOf("const mapEl = document.getElementById('site-map');"), template.indexOf('  function refresh(){'));

function setup({lat = '', lng = '', leaflet = true} = {}) {
  const nodes = {};
  for (const id of ['site-map', 'lat', 'lng', 'latlng-display', 'site-map-status', 'btn-enable-site-map', 'btn-geoloc', 'btn-clear-marker']) {
    nodes[id] = {value: '', hidden: id === 'site-map', handlers: {}, dispatchEvent() {}, addEventListener(name, handler) { this.handlers[name] = handler; }};
  }
  nodes.lat.value = lat; nodes.lng.value = lng;
  const calls = {maps: 0, tiles: 0, markers: [], centers: [], removed: 0};
  const map = {setView(position) { calls.centers.push([...position]); return this; }, panTo(position) { calls.centers.push([...position]); }, on() {}, invalidateSize() {}, setZoom() {}, removeLayer() { calls.removed++; }};
  const window = {};
  const context = {
    window, idLat: '#lat', idLng: '#lng', Event: class {}, refresh() {}, setTimeout() {}, alert() {},
    document: {getElementById: id => nodes[id], querySelector: selector => nodes[selector.slice(1)]},
    navigator: {geolocation: {getCurrentPosition(success) { success({coords: {latitude: 48, longitude: 2}}); }}},
  };
  if (leaflet) context.L = {
    map() { calls.maps++; return map; },
    tileLayer() { calls.tiles++; return {addTo() {}}; },
    marker(position) {
      calls.markers.push([...position]);
      return {addTo() { return this; }, on() {}, setLatLng(position) { calls.markers.push([...position]); }};
    },
  };
  vm.runInNewContext(source, context);
  return {nodes, calls, window, click: id => nodes[id].handlers.click()};
}

test('Existing site coordinates are preserved without a map request until activation', () => {
  const f = setup({lat: '43.6', lng: '3.9'});
  assert.equal(f.calls.maps, 0); assert.equal(f.calls.tiles, 0);
  assert.equal(f.nodes.lat.value, '43.6'); assert.equal(f.window.siteMapGetMap(), null);
  f.click('btn-enable-site-map'); f.click('btn-enable-site-map');
  assert.equal(f.calls.maps, 1); assert.equal(f.calls.tiles, 1);
  assert.deepEqual(f.calls.centers[0], [43.6, 3.9]);
  assert.deepEqual(f.calls.markers[0], [43.6, 3.9]);
});

test('A Google suggestion updates saved coordinates without also activating OSM', () => {
  const f = setup();
  f.window.siteMapSetLatLng(50, 1, {source: 'auto', pan: true});
  assert.equal(f.calls.maps, 0); assert.equal(f.calls.tiles, 0);
  assert.equal(f.nodes.lat.value, '50.000000'); assert.equal(f.nodes.lng.value, '1.000000');
  f.click('btn-enable-site-map');
  assert.deepEqual(f.calls.centers[0], [50, 1]);
  f.window.siteMapSetLatLng(49, 2);
  assert.deepEqual(f.calls.centers.at(-1), [49, 2]);
});

test('Geolocation and clearing coordinates remain available without loading a map', () => {
  const f = setup();
  f.click('btn-geoloc');
  assert.equal(f.nodes.lat.value, '48.000000'); assert.equal(f.calls.tiles, 0);
  f.click('btn-clear-marker');
  assert.equal(f.nodes.lat.value, ''); assert.equal(f.nodes.lng.value, '');
  f.click('btn-enable-site-map'); f.window.siteMapSetLatLng(43, 4); f.click('btn-clear-marker');
  assert.equal(f.calls.removed, 1);
});

test('Unavailable Leaflet leaves saved coordinates and manual form usable', () => {
  const f = setup({lat: '44', lng: '4', leaflet: false});
  f.click('btn-enable-site-map');
  assert.equal(f.nodes.lat.value, '44'); assert.equal(f.nodes.lng.value, '4');
  assert.match(f.nodes['site-map-status'].textContent, /saisie manuelle/);
  f.window.siteMapSetLatLng(45, 5);
  assert.equal(f.nodes.lat.value, '45.000000');
});
