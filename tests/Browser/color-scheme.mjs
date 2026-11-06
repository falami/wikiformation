/* Run with an isolated Chrome --headless --remote-debugging-port=9337.
 * No application account, database or production network is used.
 * CDP_URL may point at a different isolated browser instance.
 */
import fs from 'node:fs';
import assert from 'node:assert/strict';
const endpoint=process.env.CDP_URL || 'http://127.0.0.1:9337';
const tabs=await(await fetch(endpoint+'/json/list')).json();
const socket=new WebSocket(tabs.find(t=>t.type==='page').webSocketDebuggerUrl);
await new Promise(r=>socket.onopen=r);
let sequence=0;const waiting=new Map();
socket.onmessage=e=>{const m=JSON.parse(e.data);if(!m.id)return;const p=waiting.get(m.id);waiting.delete(m.id);m.error?p.reject(m.error):p.resolve(m.result)};
const send=(method,params={})=>new Promise((resolve,reject)=>{const id=++sequence;waiting.set(id,{resolve,reject});socket.send(JSON.stringify({id,method,params}))});
const evaluate=async expression=>(await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true})).result.value;
const settle=()=>new Promise(r=>setTimeout(r,250));
const mode=async (value,forced='none',media='screen')=>{await send('Emulation.setEmulatedMedia',{media,features:[{name:'prefers-color-scheme',value},{name:'forced-colors',value:forced}]});await settle()};
const style=fs.readFileSync(new URL('../../public/dist/css/color-scheme.css',import.meta.url),'utf8');
const script=fs.readFileSync(new URL('../../public/dist/js/color-scheme.js',import.meta.url),'utf8');
try {
 await send('Page.enable');await send('Page.navigate',{url:'about:blank'});await settle();
 await mode('light');
 const frame=(await send('Page.getFrameTree')).frameTree.frame.id;
 await send('Page.setDocumentContent',{frameId:frame,html:`<!doctype html><html><head><style>
 body{background:#f5f7fa;color:#203747}.panel{background:#fff;border:1px solid #dde5ed;padding:20px}.mixed{background:color-mix(in srgb,white 90%,#f0f0f0)}.muted{color:#65798a}.badge{background:#ffc107;color:#203747}.menu{display:none;background:#fff;color:#203747}.menu.open{display:block}input{color:#203747;background:#fff}canvas{background:white}#strong .panel{background:#fff!important;color:#203747!important}input:checked+label{background:#203747;color:white}label{background:#fff;color:#203747}
 </style></head><body><div id="strong"><section class="panel"><h1>Formation</h1><div class="mixed">En-tête</div><p class="muted">Informations complémentaires</p><button class="badge">Enregistrer</button><input value="Saisie"><input id="check" type="checkbox"><label for="check">Choix</label><div class="menu">Menu</div><canvas width="100" height="40"></canvas></section></div></body></html>`});
 const measure=`JSON.stringify([...document.querySelectorAll('body,body *')].map(e=>{const s=getComputedStyle(e),r=e.getBoundingClientRect();return [s.color,s.backgroundColor,s.borderColor,r.width,r.height]}))`;
 const baseline=await evaluate(measure);
 await evaluate(`{const s=document.createElement('style');s.textContent=${JSON.stringify(style)};document.head.append(s);}`);
 await send('Runtime.evaluate',{expression:script});await settle();
 assert.equal(await evaluate(measure),baseline,'Light palette and layout unchanged');
 await mode('dark');
 assert.equal(await evaluate("document.documentElement.hasAttribute('data-wf-dark')"),true);
 assert.notEqual(await evaluate("getComputedStyle(document.querySelector('.panel')).backgroundColor"),'rgb(255, 255, 255)','High-specificity important surface adapts');
 assert.match(await evaluate("getComputedStyle(document.querySelector('.mixed')).backgroundColor"), /^rgba?\(/, 'Colour-mix surfaces adapt');
 assert.equal(await evaluate("getComputedStyle(document.querySelector('canvas')).backgroundColor"),'rgb(255, 255, 255)','Canvas pixels preserved');
 await evaluate("document.querySelector('.panel').insertAdjacentHTML('beforeend','<p id=dynamic style=\"background:white;color:black\">Nouvelle ligne</p>');document.querySelector('.menu').classList.add('open')");await settle();
 assert.equal(await evaluate("document.querySelector('#dynamic').hasAttribute('data-wf-palette')"),true,'Dynamic content adapted');
 assert.notEqual(await evaluate("getComputedStyle(document.querySelector('.menu')).backgroundColor"),'rgb(255, 255, 255)','Opened menu adapts');
 await evaluate("document.querySelector('#dynamic').remove();document.querySelector('.menu').classList.remove('open')");await settle();
 await mode('light');assert.equal(await evaluate(measure),baseline,'Switching back restores exact light styles and geometry');
 await mode('dark','active');assert.equal(await evaluate("document.documentElement.hasAttribute('data-wf-dark')"),false,'Windows high contrast retains control');
 await mode('dark','none','print');assert.equal(await evaluate("document.documentElement.hasAttribute('data-wf-dark')"),false,'Printed documents are not darkened');
 console.log('PASS: light unchanged, dark surfaces, dynamic content, menus, canvas, theme switching, forced colours and print.');
} finally {await mode('light');socket.close();}
