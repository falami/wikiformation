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
}
const tick = () => new Promise(resolve => setImmediate(resolve));
async function setup(key = 'test-key') {
  const address = new Element(), postal = new Element(), city = new Element();
  const selectors = Object.fromEntries(['status','map','empty','caption','locate','search'].map(name => [`[data-address-${name}]`, new Element()]));
  const root = {dataset:{addressId:'address',postalId:'postal',cityId:'city',googleKey:key},querySelector:s=>selectors[s]};
  const centers = [];
  let geocode = async () => ({results:[]});
  const google = {maps:{
    importLibrary: async () => ({PlaceAutocompleteElement:Element, Geocoder:class {geocode(args){return geocode(args);}}}),
    Map:class {constructor(_, options){centers.push(options.center);} setCenter(center){centers.push(center);} setZoom(){}},
    Circle:class {setCenter(){}}
  }};
  vm.runInNewContext(source,{document:{querySelector:()=>root,getElementById:id=>({address,postal,city})[id]},window:{google},google,Event:class{constructor(type){this.type=type;}},setTimeout:()=>1,clearTimeout(){},URLSearchParams});
  await tick();
  return {address,postal,city,selectors,centers,setGeocoder:fn=>{geocode=fn;},search:selectors['[data-address-search]'].children[0]};
}
test('Google selection populates address fields, clears old postal code and locates the result',async()=>{
  const f=await setup(); f.postal.value='Ancien code';
  const place={formattedAddress:'12 rue Exemple, Ville',location:{lat:1,lng:2},addressComponents:[{types:['street_number'],longText:'12'},{types:['route'],longText:'rue Exemple'},{types:['locality'],longText:'Ville'}],fetchFields:async()=>{}};
  await f.search.dispatchEvent({type:'gmp-select',placePrediction:{toPlace:()=>place}});
  assert.equal(f.address.value,'12 rue Exemple'); assert.equal(f.postal.value,''); assert.equal(f.city.value,'Ville');
  assert.equal(f.selectors['[data-address-map]'].hidden,false); assert.deepEqual(f.centers,[place.location]);
});
test('A pending Google selection cannot overwrite a newer manual address',async()=>{
  const f=await setup(); let finish;
  const pending=f.search.dispatchEvent({type:'gmp-select',placePrediction:{toPlace:()=>({fetchFields:()=>new Promise(resolve=>{finish=resolve;}),formattedAddress:'Ancienne adresse'})}});
  f.address.value='Nouvelle adresse'; await f.address.dispatchEvent({type:'input'}); finish(); await pending;
  assert.equal(f.address.value,'Nouvelle adresse'); assert.equal(f.centers.length,0);
});
test('Missing key preserves manual entry and reports unavailable suggestions',async()=>{
  const f=await setup('');
  assert.equal(f.search,undefined); assert.equal(f.selectors['[data-address-locate]'].disabled,true);
  assert.match(f.selectors['[data-address-status]'].textContent,/manuellement/);
  f.address.value='Adresse manuelle'; await f.address.dispatchEvent({type:'input'}); assert.equal(f.address.value,'Adresse manuelle');
});
