const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync(require.resolve('../../public/dist/js/positioning-editor.js'), 'utf8');
function fixture(fetch) {
  const classes = {add(){}, remove(){}, toggle(){}};
  const notice = {textContent:'', classList:classes};
  const makeNode = id => ({dataset:{id:String(id)}, matches:()=>true, badge:{textContent:String(id)}, querySelector(s){return s === '.pe-children' ? null : this.badge;}});
  const first = makeNode(1), second = makeNode(2);
  const chapters = {children:[first,second], dataset:{csrf:'test-token', reorderUrl:'/order'}, querySelectorAll:s=>s === '.chapter' ? chapters.children : [], appendChild(node){this.children=this.children.filter(n=>n!==node);this.children.push(node);}};
  const editor = {querySelector:s=>({'#chapters-sortable':chapters,'#editorSaveState':notice,'#positioningOrderState':notice}[s]||null),addEventListener(){}};
  const form = {closest:()=>editor,querySelector:()=>({checked:true}),addEventListener(){}};
  const events = {}, instances=[];
  vm.runInNewContext(source, {
    document:{readyState:'complete',getElementById:()=>form,addEventListener:(name,fn)=>{events[name]=fn;}},
    Sortable:class {constructor(list, options){this.options=options;this.disabled=false;instances.push(this);}option(name,value){this.disabled=value;}}, fetch,
  });
  return {chapters,first,second,notice,instances,events};
}
test('un déplacement réussi envoie le CSRF, garde le nouvel ordre et actualise les numéros', async()=>{
  let sent;
  const f=fixture(async(url, options)=>{sent={url,body:JSON.parse(options.body)};return {ok:true,json:async()=>({ok:true})};});
  f.events['turbo:load']();
  assert.equal(f.instances.length,1);
  const drag=f.instances[0].options;
  drag.onStart();f.chapters.children.reverse();await drag.onEnd({oldIndex:0,newIndex:1});
  assert.deepEqual(sent,{url:'/order',body:{_token:'test-token',orderedIds:[2,1]}});
  assert.equal(f.second.badge.textContent,'1');
  assert.equal(f.notice.textContent,'Ordre enregistré');
  assert.equal(f.instances[0].disabled,false);
});
test('une erreur réseau restaure l’ordre précédent et permet de réessayer',async()=>{
  const f=fixture(async()=>{throw new Error('offline');});
  const drag=f.instances[0].options;
  drag.onStart();f.chapters.children.reverse();await drag.onEnd({oldIndex:0,newIndex:1});
  assert.deepEqual(f.chapters.children,[f.first,f.second]);
  assert.match(f.notice.textContent,/n’a pas pu être enregistré/);
  assert.equal(f.instances[0].disabled,false);
});
test('déplacer un chapitre conserve ses descendants et envoie tout l’arbre', async()=>{
  let sent;
  const f=fixture(async(url, options)=>{sent=JSON.parse(options.body);return {ok:true,json:async()=>({ok:true})};});
  const child={dataset:{id:'3'},matches:()=>true,badge:{textContent:''},querySelector(s){return s === '.pe-children' ? null : this.badge;}};
  const children={children:[child]};
  f.first.querySelector=s=>s === '.pe-children' ? children : f.first.badge;
  f.chapters.querySelectorAll=s=>s === '.chapter' ? f.chapters.children.flatMap(n=>n===f.first?[n,child]:[n]) : [];
  const drag=f.instances[0].options;
  drag.onStart();f.chapters.children.reverse();await drag.onEnd({oldIndex:0,newIndex:1});
  assert.deepEqual(sent.orderedIds,[2,1,3]);
  assert.equal(child.badge.textContent,'2.1');
  assert.equal(f.first.badge.textContent,'2');
});
