const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/dist/js/external-video.js', 'utf8');
function setup(src) {
  const events = {}, created = [];
  const canvas = {children:[],get childElementCount(){return this.children.length;},append(x){this.children.push(x);},replaceChildren(){this.children=[];}};
  const nodes = {'[data-video-canvas]':canvas,'[data-video-placeholder]':{hidden:false},'[data-close-external-video]':{hidden:true},'[data-video-error]':{},'[data-video-policy]':{hidden:true}};
  const video = {dataset:{videoSrc:src},querySelector:s=>nodes[s]};
  const button = {closest:()=>video};
  const document={addEventListener:(n,fn)=>events[n]=fn,querySelectorAll:()=>[video],createElement:()=>{const el={style:{}};created.push(el);return el;}};
  vm.runInNewContext(source,{window:{},document,URL});
  events.DOMContentLoaded();
  const click=(close=false)=>events.click({preventDefault(){},target:{closest:s=>s===(close?'[data-close-external-video]':'[data-load-external-video]')?button:null}});
  return {created,canvas,nodes,click,events};
}
test('Only explicit activation loads a privacy enhanced player and closing removes it',()=>{
 const f=setup('https://www.youtube.com/watch?v=abcdefghijk&autoplay=1');
 assert.equal(f.created.length,0);assert.equal(f.nodes['[data-video-policy]'].hidden,false);
 f.click();f.click();assert.equal(f.created.length,1);
 assert.equal(f.created[0].src,'https://www.youtube-nocookie.com/embed/abcdefghijk');
 assert.equal(f.created[0].referrerPolicy,'strict-origin');
 f.click(true);assert.equal(f.canvas.childElementCount,0);
 f.click();f.events['turbo:before-cache']();assert.equal(f.canvas.childElementCount,0);
});
test('Rejects active schemes, lookalike hosts, embedded credentials and unknown providers',()=>{
 for(const src of ['javascript:alert(1)','http://youtube.com/watch?v=abcdefghijk','https://youtube.com.attacker.test/embed/abcdefghijk','https://user:password@www.youtube.com/embed/abcdefghijk','https://unknown.test/video','https://www.youtube.com/embed/../../../x']) {
  const f=setup(src);f.click();assert.equal(f.created.length,0,src);assert.ok(f.nodes['[data-video-error]'].textContent);
 }
});
test('Vimeo minimizes player options while preserving the unlisted video hash',()=>{
 const f=setup('https://player.vimeo.com/video/12345?h=abcdef&autoplay=1');f.click();
 assert.equal(f.created[0].src,'https://player.vimeo.com/video/12345?dnt=1&h=abcdef');
});

test('Direct video files also remain unloaded until activation',()=>{
 const f=setup('https://media.example.test/lesson.mp4');assert.equal(f.created.length,0);f.click();
 assert.equal(f.created[0].src,'https://media.example.test/lesson.mp4');assert.equal(f.created[0].controls,true);assert.equal(f.created[0].preload,'none');
});
