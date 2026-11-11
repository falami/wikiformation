const {test}=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const fs=require('node:fs');
const source=fs.readFileSync('public/dist/js/caces-practice-draft.js','utf8');
const tick=()=>new Promise(resolve=>setImmediate(resolve));
function setup(server={},local=null){
 const events={},windowEvents={},requests=[],timers=[];
 const field={name:'scores[q1]',type:'select-one',tagName:'SELECT',value:'',options:[{value:''},{value:'0'},{value:'1'}]};
 const notice={setAttribute(){}};
 let stored=local?JSON.stringify(local):null;
 const form={dataset:{practiceClock:'1-1-1',practiceSchema:'schema',practiceSaved:JSON.stringify(server),practiceSaveUrl:'/save'},querySelectorAll:()=>[field],prepend(){},addEventListener:(k,f)=>events[k]=f};
 vm.runInNewContext(source,{document:{querySelector:()=>form,createElement:()=>notice},window:{addEventListener:(k,f)=>windowEvents[k]=f},localStorage:{getItem:()=>stored,setItem:(_,v)=>stored=v,removeItem:()=>stored=null},FormData:class{constructor(){this.values={'scores[q1]':field.value};}set(k,v){this.values[k]=v;}},fetch:(_,options)=>new Promise(resolve=>requests.push({options,resolve})),setTimeout:fn=>timers.push(fn),Date,JSON});
 return {field,events,windowEvents,requests,timers,notice,stored:()=>JSON.parse(stored)};
}
test('Restores a saved zero and ignores a local draft based on an older server revision',()=>{
 const f=setup({revision:2,at:1,values:{'scores[q1]':'0'}},{schema:'schema',revision:1,at:Date.now(),values:{'scores[q1]':'1'}});
 assert.equal(f.field.value,'0');assert.equal(f.requests.length,0);
});
test('Serializes rapid changes and acknowledges only the latest saved scores',async()=>{
 const f=setup();f.field.value='0';f.events.change();f.field.value='1';f.events.change();
 assert.equal(f.requests.length,1);
 f.requests[0].resolve({ok:true,json:async()=>({saved:true,revision:1})});await tick();
 assert.equal(f.stored().revision,1);f.timers.shift()();
 assert.equal(f.requests[1].options.body.values['scores[q1]'],'1');
 assert.equal(f.requests[1].options.body.values.revision,'1');
 f.requests[1].resolve({ok:true,json:async()=>({saved:true,revision:2})});await tick();
 assert.match(f.notice.textContent,/Toutes les notes/);assert.equal(f.stored(),null);
});
test('Network errors retain local notes and warn before leaving',async()=>{
 const f=setup();f.field.value='1';f.events.change();f.requests[0].resolve({ok:false,status:503,json:async()=>({})});await tick();
 assert.equal(f.stored().values['scores[q1]'],'1');assert.match(f.notice.textContent,/non enregistrées/);
 let warned=false;f.windowEvents.beforeunload({preventDefault(){warned=true;}});assert.equal(warned,true);
});
