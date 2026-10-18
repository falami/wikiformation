document.addEventListener('DOMContentLoaded',()=>{
  const subjects=document.getElementById('rec-subjects'),departments=document.getElementById('rec-departments');
  if(subjects && window.TomSelect) new TomSelect(subjects,{plugins:['remove_button'],create:true,maxItems:40,placeholder:'Ex. Bureautique, habilitation électrique, management…',maxOptions:100,render:{option_create:(data,escape)=>'<div class="create">Ajouter « '+escape(data.input)+' »</div>',no_results:()=>'<div class="no-results">Aucune spécialité correspondante. Vous pouvez ajouter la vôtre.</div>'}});
  const zone=departments && window.TomSelect ? new TomSelect(departments,{plugins:['remove_button'],create:false,placeholder:'Rechercher un département…'}) : null;
  const national=document.getElementById('rec-national');
  function selected(){return departments ? Array.from(departments.selectedOptions).map(o=>o.value) : [];}
  function update(){
    const all=national?.checked,values=selected();
    document.querySelectorAll('[data-department]').forEach(p=>p.setAttribute('aria-checked',String(all || values.includes(p.dataset.department))));
    const count=document.getElementById('rec-zone-count'); if(count)count.textContent=all?'Mobilité : toute la France.':values.length+' département(s) sélectionné(s).';
  }
  document.querySelectorAll('[data-department]').forEach(p=>{
    function toggle(){if(national)national.checked=false;const code=p.dataset.department;if(zone){selected().includes(code)?zone.removeItem(code):zone.addItem(code);}else{const o=Array.from(departments.options).find(o=>o.value===code);o.selected=!o.selected;}update();}
    p.addEventListener('click',toggle);p.addEventListener('keydown',e=>{if(e.key===' '||e.key==='Enter'){e.preventDefault();toggle();}});
  });
  departments?.addEventListener('change',update);national?.addEventListener('change',update);update();
  const vat=document.getElementById('rec-vat'),number=document.getElementById('rec-vat-number');function syncVat(){if(number)number.required=!!vat?.checked;}vat?.addEventListener('change',syncVat);syncVat();
  document.getElementById('rec-error')?.focus();
  document.querySelector('.rec-form')?.addEventListener('submit',e=>{const button=e.target.querySelector('button[type=submit]');if(button){button.disabled=true;button.textContent='Envoi en cours…';}});
});
