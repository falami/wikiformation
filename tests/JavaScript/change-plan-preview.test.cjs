const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const template = fs.readFileSync(require('node:path').join(__dirname, '../../templates/administrateur/billing/index.html.twig'), 'utf8');
const script = template.match(/<script>\s*([\s\S]*?)<\/script>/)[1];
function setup() {
  const elements = {};
  for (const id of ['btnChangePlan','changePlanModal','newPlan','prorationBox','prorationText','changePlanError','btnConfirmChange','applyPlan','applyInterval','previewToken']) {
    elements[id] = {value:'', style:{}, textContent:'', innerHTML:'', events:{}, addEventListener(name, fn) {this.events[name]=fn;}};
  }
  elements.newPlan.value = 'SOLO';
  elements.newPlan.selectedOptions = [{dataset:{monthReady:'1'}}];
  const requests = [];
  vm.runInNewContext(script, {document:{getElementById:id=>elements[id]}, bootstrap:{Modal:class{}}, URLSearchParams, Intl, fetch:()=>new Promise(resolve=>requests.push(resolve))});
  return {elements, requests, change:()=>elements.newPlan.events.change()};
}
const response = data => ({ok:data.ok, headers:{get:()=> 'application/json'}, json:async()=>data});
test('failed preview hides and clears the previous quote', async()=>{
  const b=setup(); const first=b.change(); b.requests[0](response({ok:true, amount_due_cents:3480})); await first;
  assert.equal(b.elements.btnConfirmChange.disabled,false);
  b.elements.newPlan.value='PRO'; const second=b.change();
  assert.equal(b.elements.prorationText.textContent,'');
  assert.equal(b.elements.applyPlan.value,'');
  b.requests[1](response({ok:false,error:'Tarif indisponible'})); await second;
  assert.equal(b.elements.prorationBox.style.display,'none');
  assert.equal(b.elements.btnConfirmChange.disabled,true);
  assert.equal(b.elements.changePlanError.textContent,'Tarif indisponible');
  assert.doesNotMatch(template,/class="[^"]*d-flex[^"]*" id="prorationBox"/);
});
test('out-of-order responses never enable confirmation for another offer',async()=>{
  const b=setup(); const first=b.change(); b.elements.newPlan.value='PRO'; const second=b.change();
  b.requests[1](response({ok:false,error:'PRO unavailable'})); await second;
  b.requests[0](response({ok:true,amount_due_cents:3480})); await first;
  assert.equal(b.elements.btnConfirmChange.disabled,true);
  assert.equal(b.elements.prorationBox.style.display,'none');
  assert.equal(b.elements.changePlanError.textContent,'PRO unavailable');
});
