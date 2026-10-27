const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
class Element {
  constructor() { this.dataset={}; this.listeners={}; this.value=''; this.children=[]; this.isConnected=true; }
  addEventListener(type, fn) {(this.listeners[type] ||= []).push(fn);}
  dispatchEvent(e) {return Promise.all((this.listeners[e.type] || []).map(fn=>fn(e)));}
  append(el){this.children.push(el);}
  setAttribute(){}
  closest(){return null;}
}
async function setup(activate = true) {
 const events = {};
 const groups = [0,1].map(i=> {
   const fields = Object.fromEntries(['adresse','codePostal','ville','latitude','longitude','googlePlaceId','formattedAddress','pays','complement'].map(k=>[k,new Element()]));
   const form={name:'test',elements:Object.values(fields)};
   Object.entries(fields).forEach(([k,f])=>{f.name=`form[${i}][${k}]`;f.form=form;});
   fields.adresse.parentElement={insertBefore(p){fields.panel=p;}};
   return fields;
 });
 const google={maps:{importLibrary:async()=>({PlaceAutocompleteElement:Element}),Map:class{setCenter(){}},Circle:class{setCenter(){}}}};
 const document={addEventListener:(name, fn)=>{events[name]=fn;},body:{},querySelector:()=>({dataset:{addressAssistantKey:'key'}}),querySelectorAll:()=>groups.map(g=>g.adresse),createElement:()=>{const panel=new Element();panel.parts=Object.fromEntries(['search','status','map','caption'].map(k=>[`[data-${k}]`,new Element()]));panel.parts.details=new Element();panel.querySelector=s=>panel.parts[s];return panel;}};
 vm.runInNewContext(fs.readFileSync('public/dist/js/address-assistant.js','utf8'),{document,window:{google},google,MutationObserver:class{observe(){}},Event:class{constructor(type){this.type=type;}}});
 if (activate) events['wiki:enable-google-maps']();
 await new Promise(r=>setImmediate(r));
 return groups;
}
function place() {return {id:'place-123',location:{lat:()=>43.5,lng:()=>4.2},formattedAddress:'10 rue Test',fetchFields:async()=>{},addressComponents:[{types:['street_number'],longText:'10'},{types:['route'],longText:'rue Test'},{types:['locality'],longText:'Ville'},{types:['country'],longText:'France',shortText:'FR'}]};}
test('Selection fills only its address group, updates coordinates and preserves complement',async()=>{
 const [a,b]=await setup(); a.complement.value='Bâtiment B';
 await a.panel.parts['[data-search]'].children[0].dispatchEvent({type:'gmp-select',placePrediction:{toPlace:place}});
 assert.equal(a.adresse.value,'10 rue Test');assert.equal(a.latitude.value,'43.5');assert.equal(a.googlePlaceId.value,'place-123');assert.equal(a.complement.value,'Bâtiment B');assert.equal(b.adresse.value,'');
 await a.adresse.dispatchEvent({type:'input'});
 assert.equal(a.latitude.value,'');assert.equal(a.googlePlaceId.value,'');assert.equal(a.panel.parts['[data-map]'].hidden,true);
});
test('Late selection cannot overwrite a newer manual edit',async()=>{
 const [a]=await setup();let finish;const p=place();p.fetchFields=()=>new Promise(r=>finish=r);
 const pending=a.panel.parts['[data-search]'].children[0].dispatchEvent({type:'gmp-select',placePrediction:{toPlace:()=>p}});
 a.adresse.value='Nouvelle adresse';await a.adresse.dispatchEvent({type:'input'});finish();await pending;
 assert.equal(a.adresse.value,'Nouvelle adresse');assert.equal(a.latitude.value,'');
});

test('Address suggestions do not initialize before explicit activation, even with Google already present', async()=>{
 const [a]=await setup(false); assert.equal(a.panel, undefined);
});
