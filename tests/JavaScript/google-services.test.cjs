const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/dist/js/google-services.js','utf8');
function setup(url) {
 const events={}, inserted=[], emitted=[];
 const button={addEventListener:(n,fn)=>button[n]=fn};
 const status={};
 const notice={setAttribute(){},querySelector:s=>s==='[data-enable]'?button:status};
 const legacy=url?{dataset:{googleMapsSrc:url}}:null;
 const form={prepend(el){this.notice=el;}};
 const document={addEventListener:(n,fn)=>events[n]=fn,dispatchEvent:e=>emitted.push(e.type),querySelector:s=>s.startsWith('script')?legacy:s==='[data-client-address]'?{closest:()=>form}:null,createElement:tag=>tag==='section'?notice:{remove(){}},head:{append:script=>inserted.push(script)}};
 vm.runInNewContext(source,{window:{},document,URL,CustomEvent:class{constructor(type){this.type=type;}}});
 events.DOMContentLoaded();return {inserted,emitted,button,status};
}
test('Legacy Google scripts stay inert until the page activation button is clicked',()=>{
 const f=setup('https://maps.googleapis.com/maps/api/js?key=test&callback=init');
 assert.equal(f.inserted.length,0);assert.equal(f.emitted.length,0);
 f.button.click();assert.equal(f.inserted.length,1);assert.equal(f.inserted[0].referrerPolicy,'strict-origin');
 assert.equal(f.emitted.length,0);f.inserted[0].onload();assert.deepEqual(f.emitted,['wiki:enable-google-maps']);
});
test('Modern assistants receive no activation event before the click',()=>{
 const f=setup();assert.equal(f.emitted.length,0);f.button.click();assert.deepEqual(f.emitted,['wiki:enable-google-maps']);
});
test('Malformed legacy sources are never loaded',()=>{
 for(const src of ['https://maps.googleapis.com.attacker.test/maps/api/js','javascript:alert(1)','http://maps.googleapis.com/maps/api/js']){
  const f=setup(src);f.button.click();assert.equal(f.inserted.length,0);assert.match(f.status.textContent,/indisponible/);
 }
});
