import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const template=readFileSync(new URL('../../templates/administrateur/formateur/contrat/form.html.twig',import.meta.url),'utf8');
const raw=template.split('{# ===== Ton script métier')[1].split('<script>')[1].split('</script>')[0];
function setup({edit=false,submitted=false,value='0,00'}={}) {
  const elements=new Map(), pending=[];
  const element=id=>{if(!elements.has(id))elements.set(id,{value:'',textContent:'',listeners:{},options:[{value:'1',dataset:{}}],selectedIndex:0,addEventListener(type,fn){this.listeners[type]=fn;}});return elements.get(id);};
  element('formateur').value='1';element('session').value='1';element('montantPrevuCents').value=value;
  const document={getElementById:element,addEventListener(type,fn){if(type==='DOMContentLoaded')fn();},dispatchEvent(){}};
  const code=raw.replace(/{{ form\.(\w+)\.vars\.id }}/g,'$1').replace(/{{ is_edit \? 'true' : 'false' }}/g,String(edit)).replace(/{{ form.vars.submitted \? 'true' : 'false' }}/g,String(submitted)).replace(/{{ path\([^\n]+? }}/g,'/suggest');
  vm.runInNewContext(code,{document,Event,URLSearchParams,fetch:()=>new Promise(resolve=>pending.push(resolve))});
  return {element,pending,async respond(index,cents){pending[index]({json:async()=>({success:true,montantPrevuCents:cents})});await new Promise(resolve=>setImmediate(resolve));}};
}
let page=setup();await page.respond(0,30000);assert.equal(page.element('montantPrevuCents').value,'300,00');
page.element('montantPrevuCents').value='450,00';page.element('montantPrevuCents').listeners.input();
page.element('session').value='2';page.element('session').listeners.change();await page.respond(1,70000);
assert.equal(page.element('montantPrevuCents').value,'450,00');
page.element('restore-trainer-rate').listeners.click();await page.respond(2,70000);assert.equal(page.element('montantPrevuCents').value,'700,00');
page=setup();page.element('montantPrevuCents').value='0,00';page.element('montantPrevuCents').listeners.input();await page.respond(0,30000);assert.equal(page.element('montantPrevuCents').value,'0,00');
page=setup();page.element('session').value='2';page.element('session').listeners.change();await page.respond(1,70000);await page.respond(0,30000);assert.equal(page.element('montantPrevuCents').value,'700,00');
for(const options of [{edit:true,value:'250,00'},{submitted:true,value:'450,00'}]){page=setup(options);await page.respond(0,30000);assert.equal(page.element('montantPrevuCents').value,options.value);}
console.log('OK: defaults, manual amount, explicit reset, zero, stale response, persisted and resubmitted values.');
