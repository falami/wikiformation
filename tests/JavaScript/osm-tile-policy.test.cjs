const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

// Private pages deliberately send no Referer by default. Every active OSM
// layer must override that policy for its images without disclosing page paths.
const sources = [
  'templates/administrateur/session/show.html.twig',
  'templates/administrateur/session/form.html.twig',
  'templates/administrateur/site/form.html.twig',
  'templates/administrateur/utilisateur/show.html.twig',
  'public/dist/recruitment/recruitment-form.js',
];

for (const path of sources) {
  test(`${path}: OSM identifies the website without exposing the private URL`, () => {
    const source = fs.readFileSync(path, 'utf8');
    const start = source.indexOf('L.tileLayer(');
    assert.notEqual(start, -1);
    const end = source.indexOf(').addTo(', start);
    assert.notEqual(end, -1);
    let calls = 0;
    vm.runInNewContext(source.slice(start, end + 1), {
      L: {tileLayer(url, options) {
        calls++;
        assert.equal(url, 'https://tile.openstreetmap.org/{z}/{x}/{y}.png');
        assert.equal(options.referrerPolicy, 'strict-origin');
        assert.equal(options.maxZoom, 19);
        assert.match(options.attribution, /href="https:\/\/www\.openstreetmap\.org\/copyright"/);
        assert.match(options.attribution, /OpenStreetMap<\/a> contributors/);
      }},
    });
    assert.equal(calls, 1);
  });
}
