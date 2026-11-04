const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/dist/js/client-address.js', 'utf8');
class Element {
  constructor(value = '') { this.value = value; this.listeners = {}; this.dataset = {}; this.children = []; }
  addEventListener(type, fn) { (this.listeners[type] ||= []).push(fn); }
  dispatchEvent(event) { return Promise.all((this.listeners[event.type] || []).map(fn => fn(event))); }
  append(el) { this.children.push(el); }
  setAttribute() {}
  replaceChildren() { this.children = []; }
}
const tick = () => new Promise(resolve => setImmediate(resolve));
async function setup(key = 'test-key', activate = true, mapUnavailable = false) {
  const events = {};
  const address = new Element(), postal = new Element(), city = new Element();
  const selectors = Object.fromEntries(['status','map','empty','caption','locate','search'].map(name => [`[data-address-${name}]`, new Element()]));
  const root = {dataset:{addressId:'address',postalId:'postal',cityId:'city',googleKey:key},querySelector:s=>selectors[s]};
  const centers = [];
  let prediction;
  const timers = [];
  let geocode = async () => ({results:[]});
  const google = {maps:{
    importLibrary: async (library) => { if (mapUnavailable && library === 'maps') throw new Error('Map unavailable'); return ({AutocompleteSessionToken:class {}, AutocompleteSuggestion:{fetchAutocompleteSuggestions:async()=>({suggestions:prediction ? [{placePrediction:prediction}] : []})}, Geocoder:class {geocode(args){return geocode(args);}}}); },
    Map:class {constructor(_, options){centers.push(options.center);} setCenter(center){centers.push(center);} setZoom(){}},
    Circle:class {setCenter(){}}
  }};
  vm.runInNewContext(source,{document:{addEventListener:(name,fn)=>{events[name]=fn;},createElement:()=>new Element(),querySelector:()=>root,getElementById:id=>({address,postal,city})[id]},window:{google},google,Event:class{constructor(type){this.type=type;}},setTimeout:(fn, delay)=>{if(delay===250) timers.push(fn);return 1;},clearTimeout(){},URLSearchParams});
  if (activate) events['wiki:enable-google-maps']();
  await tick();
  return {address,postal,city,selectors,centers,async choose(place) { prediction = {text:{toString:()=> 'Google result'},toPlace:()=>place}; address.value='12 rue'; await address.dispatchEvent({type:'input'}); await timers.pop()(); address.value='Google result'; return address.dispatchEvent({type:'input'}); },setGeocoder:fn=>{geocode=fn;},search:selectors['[data-address-search]'].children[0]};
}
test('Google selection populates address fields, clears old postal code and locates the result',async()=>{
  const f=await setup(); f.postal.value='Ancien code';
  const place={formattedAddress:'12 rue Exemple, Ville',location:{lat:1,lng:2},addressComponents:[{types:['street_number'],longText:'12'},{types:['route'],longText:'rue Exemple'},{types:['locality'],longText:'Ville'}],fetchFields:async()=>{}};
  await f.choose(place);
  assert.equal(f.address.value,'12 rue Exemple'); assert.equal(f.postal.value,''); assert.equal(f.city.value,'Ville');
  assert.equal(f.selectors['[data-address-map]'].hidden,false); assert.deepEqual(f.centers,[place.location]);
});
test('A pending Google selection cannot overwrite a newer manual address',async()=>{
  const f=await setup(); let finish;
  const pending=f.choose({fetchFields:()=>new Promise(resolve=>{finish=resolve;}),formattedAddress:'Ancienne adresse'});
  await tick();
  f.address.value='Nouvelle adresse'; await f.address.dispatchEvent({type:'input'}); finish(); await pending;
  assert.equal(f.address.value,'Nouvelle adresse'); assert.equal(f.centers.length,0);
});
test('Missing key preserves manual entry and reports unavailable suggestions',async()=>{
  const f=await setup('');
  assert.equal(f.search,undefined); assert.equal(f.selectors['[data-address-locate]'].disabled,true);
  assert.match(f.selectors['[data-address-status]'].textContent,/manuellement/);
  f.address.value='Adresse manuelle'; await f.address.dispatchEvent({type:'input'}); assert.equal(f.address.value,'Adresse manuelle');
});

test('Client address never loads Google or geocodes an existing address without activation',async()=>{
 const f=await setup('test-key', false);assert.equal(f.search,undefined);assert.equal(f.centers.length,0);
});

test('Address suggestions remain available when the map library fails', async () => {
 const f = await setup('test-key', true, true);
 assert.ok(f.search);
 assert.match(f.selectors['[data-address-status]'].textContent, /champ Adresse/);
 assert.equal(f.selectors['[data-address-locate]'].disabled, true);
});

test('An SDK already loading is reused instead of loading Google twice', async () => {
  const events = {}, timers = [], fields = {address:new Element(), postal:new Element(), city:new Element()};
  const parts = Object.fromEntries(['status','map','empty','caption','locate','search'].map(name => [`[data-address-${name}]`, new Element()]));
  const root = {dataset:{addressId:'address',postalId:'postal',cityId:'city',googleKey:'key'},querySelector:s=>parts[s]};
  const google = {maps:{}};
  let appended = 0;
  vm.runInNewContext(source, {
    document:{addEventListener:(name,fn)=>events[name]=fn,querySelector:s=>s==='[data-client-address]' ? root : {},getElementById:id=>fields[id],createElement:()=>new Element(),head:{append:()=>appended++}},
    window:{google}, google, URLSearchParams, setTimeout:fn=>timers.push(fn),clearTimeout(){}
  });
  events['wiki:enable-google-maps']();
  assert.equal(appended, 0);
  google.maps.importLibrary = async () => ({AutocompleteSessionToken:class {}});
  timers.shift()();
  await tick();
  assert.equal(parts['[data-address-search]'].children.length, 1);
  assert.equal(appended, 0);
});
