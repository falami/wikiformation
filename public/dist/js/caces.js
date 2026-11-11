(() => {
'use strict';
const make=(tag,text,cls)=>{const e=document.createElement(tag);if(text!==undefined)e.textContent=text;if(cls)e.className=cls;return e;};
// Images are stored in the existing schema, including explicit removal.
let pendingImages = 0;
function imageField(question, sync) {
  const box = make('div', undefined, 'cx-image-field');
  const drop = make('button', undefined, 'cx-image-drop');
  drop.type = 'button';
  drop.setAttribute('aria-label', 'Choisir ou remplacer l’illustration');
  const preview = make('img');
  preview.alt = 'Aperçu de l’illustration';
  const icon = make('span', '↥', 'cx-image-icon');
  const copy = make('span', undefined, 'cx-image-copy');
  const title = make('strong');
  copy.append(title, make('span', 'Glissez une image ici ou cliquez pour parcourir'),
    make('small', 'JPG, PNG ou WebP · 1,5 Mo maximum'));
  drop.append(icon, preview, copy);
  const input = make('input');
  input.type = 'file';
  input.accept = 'image/png,image/jpeg,image/webp';
  input.hidden = true;
  const actions = make('div', undefined, 'cx-image-actions');
  const choose = make('button', 'Choisir une image', 'cx-btn');
  choose.type = 'button';
  const remove = make('button', 'Supprimer l’image', 'cx-btn');
  remove.type = 'button';
  const status = make('p', '', 'cx-image-status');
  status.setAttribute('role', 'status');
  status.setAttribute('aria-live', 'polite');
  actions.append(choose, remove);
  box.append(drop, input, actions, status);
  let revision = 0;
  const refresh = () => {
    const hasImage = !!question.image;
    preview.hidden = !hasImage;
    icon.hidden = hasImage;
    if (hasImage) preview.src = question.image;
    else preview.removeAttribute('src');
    title.textContent = hasImage ? 'Votre illustration' : 'Illustration de la question';
    choose.textContent = hasImage ? 'Remplacer l’image' : 'Choisir une image';
    remove.hidden = !hasImage;
  };
  const fail = message => { status.textContent = message; status.classList.add('is-error'); };
  const load = file => {
    if (!file) return;
    if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) {
      fail('Format non accepté. Choisissez une image JPG, PNG ou WebP.'); return;
    }
    if (file.size > 1500000) { fail('Cette image dépasse 1,5 Mo. Choisissez un fichier plus léger.'); return; }
    const current = ++revision;
    pendingImages++;
    status.classList.remove('is-error');
    status.textContent = 'Préparation de l’aperçu…';
    const reader = new FileReader();
    const finish = () => { pendingImages--; };
    reader.onerror = () => { finish(); if (current === revision) fail('Impossible de lire cette image. Réessayez.'); };
    reader.onload = () => {
      const check = new Image();
      check.onerror = () => { finish(); if (current === revision) fail('Ce fichier ne contient pas une image valide.'); };
      check.onload = () => {
        finish();
        if (current !== revision || !box.isConnected) return;
        question.image = reader.result;
        sync(); refresh();
        status.textContent = file.name + ' · Image prête à être enregistrée.';
      };
      check.src = reader.result;
    };
    reader.readAsDataURL(file);
  };
  drop.onclick = choose.onclick = () => input.click();
  input.onchange = () => { load(input.files[0]); input.value = ''; };
  remove.onclick = () => {
    revision++;
    delete question.image;
    sync(); refresh();
    status.classList.remove('is-error');
    status.textContent = 'Image supprimée. Enregistrez le modèle pour confirmer.';
    choose.focus();
  };
  for (const event of ['dragenter', 'dragover']) drop.addEventListener(event, e => {
    e.preventDefault(); drop.classList.add('is-dragging');
  });
  drop.addEventListener('dragleave', e => {
    if (!drop.contains(e.relatedTarget)) drop.classList.remove('is-dragging');
  });
  drop.addEventListener('drop', e => {
    e.preventDefault(); drop.classList.remove('is-dragging');
    if (e.dataTransfer.files.length !== 1) { fail('Déposez une seule image à la fois.'); return; }
    load(e.dataTransfer.files[0]);
  });
  refresh();
  return box;
}
// Keep the native fields as the source of truth for submission and dependencies.
function enhanceCacesSelect(select) {
  if (!window.TomSelect || select.tomselect) return;
  const label = document.querySelector('label[for="' + select.id + '"]');
  const excluded = [...select.options].filter(option => option.hidden || option.disabled).map(option => option.value);
  const options = [...select.options].filter(option => !option.hidden && !option.disabled)
    .map(option => ({value: option.value, text: option.textContent}));
  const isScore = select.name.startsWith('scores[');
  new TomSelect(select, {
    ...(isScore ? {controlInput: null} : {}),
    create: false, maxOptions: null, options,
    placeholder: select.options[0]?.value === '' ? select.options[0].textContent : 'Rechercher…',
    searchField: ['text'], allowEmptyOption: false,
    render: {no_results() { return '<div class="no-results">Aucun résultat pour cette recherche</div>'; }},
    onInitialize() {
      excluded.forEach(value => this.removeOption(value, true));
      this.wrapper.classList.add('cx-select');
      if (isScore) this.wrapper.classList.add('cx-score-select');
      this.control_input.setAttribute('aria-label', label?.textContent.trim() || select.name);
    }
  });
}
const rec=document.querySelector('#recommendation'), cat=document.querySelector('[data-categories]');
if(rec&&cat){const cats=JSON.parse(cat.dataset.categories);const refresh=()=>{cat.tomselect?.destroy();cat.replaceChildren();for(const c of cats[rec.value]||[]){const o=make('option',c);o.value=c;cat.append(o);}if(cat.dataset.selected){cat.value=cat.dataset.selected;delete cat.dataset.selected;}if(!cat.value&&cat.options.length)cat.selectedIndex=0;enhanceCacesSelect(cat);filterPractice();};const filterPractice=()=>{const practice=document.querySelector('#practice');if(!practice)return;practice.tomselect?.destroy();for(const o of practice.options){o.hidden=!!o.value&&(o.dataset.rec!==rec.value||o.dataset.category!==cat.value);o.disabled=o.hidden;}practice.value='';enhanceCacesSelect(practice);const empty=document.querySelector('[data-practice-empty]');if(empty)empty.hidden=[...practice.options].some(o=>o.value&&!o.disabled);const link=document.querySelector('[data-create-practice]');if(link){const url=new URL(link.href);url.searchParams.set('recommendation',rec.value);url.searchParams.set('category',cat.value);link.href=url.href;}};rec.addEventListener('change',refresh);cat.addEventListener('change',filterPractice);refresh();}
document.querySelectorAll('.cx form select:not([data-native-select])').forEach(enhanceCacesSelect);
for(const box of document.querySelectorAll('[data-signature]')){const canvas=box.querySelector('canvas'),input=box.querySelector('input'),ctx=canvas.getContext('2d');let drawing=false,dirty=false;ctx.lineWidth=3;ctx.lineCap='round';ctx.strokeStyle='#17212b';const point=e=>{const r=canvas.getBoundingClientRect();return [(e.clientX-r.left)*canvas.width/r.width,(e.clientY-r.top)*canvas.height/r.height];};canvas.addEventListener('pointerdown',e=>{drawing=true;dirty=true;canvas.setPointerCapture(e.pointerId);ctx.beginPath();ctx.moveTo(...point(e));});canvas.addEventListener('pointermove',e=>{if(drawing){ctx.lineTo(...point(e));ctx.stroke();}});const finish=()=>{if(drawing){drawing=false;input.value=canvas.toDataURL('image/png');}};canvas.addEventListener('pointerup',finish);canvas.addEventListener('pointercancel',finish);box.querySelector('[data-clear-signature]').addEventListener('click',()=>{ctx.clearRect(0,0,canvas.width,canvas.height);input.value='';dirty=false;});box.closest('form').addEventListener('submit',e=>{if(!dirty){e.preventDefault();alert('Veuillez apposer votre signature avant de valider.');}});}
const editor=document.querySelector('[data-caces-editor]');
if(editor){const hidden=editor.querySelector('[data-schema]'),kind=editor.querySelector('#kind'),duration=editor.querySelector('#duration'),holder=editor.querySelector('[data-themes]');let schema;try{schema=JSON.parse(hidden.value);}catch{schema={themes:[],durationMinutes:60};}schema.themes??=[];const sync=()=>{schema.durationMinutes=kind.value==='theory'?60:Number(duration.value);hidden.value=JSON.stringify(schema);const total=schema.themes.reduce((s,t)=>s+t.questions.reduce((n,q)=>n+(kind.value==='theory'?1:Number(q.points)),0),0);const assessed=new Set(schema.themes.map(t=>t.assessment||'main'));editor.querySelector('[data-total]').textContent=total+' / '+(Math.max(1,assessed.size)*100)+' points'+(assessed.size>1?' · '+assessed.size+' engins à valider séparément':'');schema.themes.forEach((t,i)=>{const el=holder.querySelectorAll('[data-theme-total]')[i];const max=t.questions.reduce((n,q)=>n+(kind.value==='theory'?1:Number(q.points)),0);if(el)el.textContent=max+' points · minimum requis : '+Math.ceil(max/2);});};
const field=(label,value,change,type='text')=>{const box=make('div');const l=make('label',label);const i=make('input');i.type=type;i.value=value;i.required=true;i.addEventListener('input',()=>{change(i.value);sync();});l.append(i);box.append(l);return box;};
const render=()=>{const practical=kind.value==='practice';document.querySelector('[data-editor-title]').textContent=practical?'Créer une grille pratique.':'Créer un questionnaire théorique.';document.querySelector('[data-editor-description]').textContent=practical?'Décrivez les situations à observer et les critères à noter. Le testeur attribuera les points à chaque stagiaire dans son espace formateur.':'Organisez les thèmes et définissez la bonne réponse A ou B pour chaque question.';cat.closest('div').hidden=!practical;holder.replaceChildren();duration.readOnly=kind.value==='theory';if(duration.readOnly)duration.value=60;schema.themes.forEach((t,ti)=>{const block=make('section',undefined,'cx-editor-theme');const head=make('div',undefined,'cx-heading');head.append(field('Thème '+(ti+1),t.title,v=>t.title=v));const remove=make('button','Supprimer le thème','cx-btn');remove.type='button';remove.onclick=()=>{if(confirm('Supprimer ce thème et ses questions ?')){schema.themes.splice(ti,1);render();}};head.append(remove);block.append(head);const subtotal=make('p','','cx-muted');subtotal.dataset.themeTotal='';block.append(subtotal);if(t.assessment)block.prepend(make('p',t.assessmentLabel||t.assessment,'cx-eyebrow'));
t.questions.forEach((q,qi)=>{const card=make('div',undefined,'cx-editor-question');card.append(field((kind.value==='theory'?'Question ':'Critère ')+(qi+1),q.label,v=>q.label=v));if(q.instructions){const details=make('details');details.append(make('summary','Consignes du point d’évaluation '+q.evaluationPoint),make('p',q.instructions));card.append(details);}const row=make('div',undefined,'cx-editor-row');if(kind.value==='theory'){q.points=1;const label=make('label','Bonne réponse ');const sel=make('select');for(const [v,txt]of [['','Choisir…'],['A','A · Vrai'],['B','B · Faux']]){const o=make('option',txt);o.value=v;sel.append(o);}sel.value=q.correct||'';sel.required=true;sel.onchange=()=>{q.correct=sel.value;sync();};label.append(sel);row.append(label);}else{q.points=Number(q.points)||1;const points=field('Points maximum du critère',q.points,v=>q.points=Number(v),'number');Object.assign(points.querySelector('input'),{min:'1',max:'100',step:'1'});row.append(points);}
row.append(imageField(q, sync));const del=make('button',kind.value==='theory'?'Supprimer la question':'Supprimer le critère','cx-btn');del.type='button';del.onclick=()=>{if(confirm('Supprimer cet élément et son illustration ?')){t.questions.splice(qi,1);render();}};row.append(del);card.append(row);if(q.sourcePage)card.append(make('p','Page source '+q.sourcePage+(q.sourceNumber?' · affirmation '+q.sourceNumber:''),'cx-muted'));block.append(card);});const add=make('button',kind.value==='theory'?'+ Ajouter une question':'+ Ajouter un critère','cx-btn');add.type='button';add.onclick=()=>{t.questions.push({id:'q'+crypto.randomUUID().replaceAll('-',''),label:'',points:1,correct:''});render();};block.append(add);holder.append(block);});sync();};
editor.querySelector('[data-add-theme]').onclick=()=>{schema.themes.push({title:'',questions:[]});render();};let previousKind=kind.value;const schemas={};kind.onchange=()=>{schemas[previousKind]=structuredClone(schema);schema=schemas[kind.value]||{themes:[],durationMinutes:60};previousKind=kind.value;render();};duration.oninput=sync;editor.addEventListener('submit',e=>{if(pendingImages){e.preventDefault();alert('Veuillez patienter pendant la préparation des images.');return;}sync();});render();}
document.querySelectorAll('.cx input[type="date"]').forEach(input=>{
 if(!window.flatpickr || input._flatpickr)return;
 const picker=flatpickr(input,{dateFormat:'Y-m-d',altInput:true,altFormat:'d/m/Y',allowInput:true,disableMobile:false,
 locale:{firstDayOfWeek:1,weekdays:{shorthand:['Dim','Lun','Mar','Mer','Jeu','Ven','Sam'],longhand:['Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi']},months:{shorthand:['Jan','Fév','Mar','Avr','Mai','Juin','Juil','Août','Sep','Oct','Nov','Déc'],longhand:['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre']},rangeSeparator:' au ',time_24hr:true},
 ...(input.name==='birthDate'?{maxDate:'today'}:{})});
 if(picker.altInput){picker.altInput.id=input.id+'-display';const label=document.querySelector('label[for="'+input.id+'"]');if(label)label.htmlFor=picker.altInput.id;}
});
const compactExam=window.matchMedia('(max-width: 767.98px)');
document.querySelectorAll('.cx-reference').forEach(reference=>{reference.open=!compactExam.matches;});
const exam=document.querySelector('[data-caces-exam]');
if(exam){const status=exam.querySelector('[data-save-status]'),timer=exam.querySelector('[data-timer]');let deadline=Number(exam.dataset.deadline),serverBase=Number(exam.dataset.serverNow),started=performance.now(),pending=Promise.resolve(),closed=false;const now=()=>serverBase+(performance.now()-started)/1000;
const questions=[...exam.querySelectorAll('.cx-answer')];
const missing=()=>questions.filter(question=>!question.querySelector('input[type=radio]:checked'));
const dialog=document.querySelector('[data-exam-confirm]');
const revealQuestion=question=>{
 const section=question.closest('[data-exam-page]');
 if(section){const button=[...exam.querySelectorAll('[data-page-target]')].find(b=>b.dataset.pageTarget===section.dataset.examPage);button?.click();}
 question.tabIndex=-1;
 question.focus({preventScroll:true});
 question.scrollIntoView({behavior:'auto',block:'center'});
 question.classList.add('cx-answer-highlight');
 setTimeout(()=>question.classList.remove('cx-answer-highlight'),2200);
};
const progress=()=>{
 const unanswered=missing(),count=questions.length-unanswered.length;
 exam.querySelector('[data-count]').textContent=count+' réponses renseignées sur '+questions.length;
 exam.querySelector('[data-progress]').style.width=(questions.length?count/questions.length*100:0)+'%';
 exam.querySelector('[data-missing-count]').textContent=unanswered.length;
 const links=exam.querySelector('[data-missing-links]');links.replaceChildren();
 unanswered.forEach(question=>{
  const button=document.createElement('button');button.type='button';button.className='cx-btn';
  const number=questions.indexOf(question)+1;
  button.textContent='Question '+number;
  button.setAttribute('aria-label','Aller à la question '+number+', sans réponse');
  button.onclick=()=>revealQuestion(question);links.append(button);
 });
 if(!unanswered.length)links.textContent='Toutes les questions ont une réponse.';
 for(const button of exam.querySelectorAll('[data-page-target]')){
  const section=[...exam.querySelectorAll('[data-exam-page]')].find(p=>p.dataset.examPage===button.dataset.pageTarget);
  const remaining=section?[...section.querySelectorAll('.cx-answer')].filter(q=>!q.querySelector('input:checked')).length:0;
  button.textContent='Page '+button.dataset.pageTarget;
  const badge=document.createElement('span');badge.className='cx-page-count';badge.textContent=remaining?' '+remaining:' ✓';
  badge.setAttribute('aria-hidden','true');button.append(badge);
  button.setAttribute('aria-label','Page '+button.dataset.pageTarget+' : '+remaining+' question(s) sans réponse');
 }
 if(dialog){dialog.querySelector('[data-confirm-answered]').textContent=count;dialog.querySelector('[data-confirm-missing]').textContent=unanswered.length;dialog.querySelector('[data-confirm-review]').hidden=!unanswered.length;dialog.querySelector('[data-confirm-notice]').textContent=unanswered.length?'Il reste '+unanswered.length+' question(s) sans réponse. Elles vaudront 0 point si vous transmettez maintenant.':'Vous avez répondu à toutes les questions. Vous pouvez encore les relire avant de transmettre.';}
};
const save=(submit=false)=>{if(closed)return pending;const data=new FormData(exam);data.set('action',submit?'submit':'save');status.textContent='Enregistrement…';pending=pending.catch(()=>{}).then(async()=>{const r=await fetch(location.href,{method:'POST',body:data,headers:{'X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'});if(!r.ok)throw new Error('Votre enregistrement a échoué.');const json=await r.json();if(json.error)throw new Error(json.error);if(json.serverNow){serverBase=json.serverNow;started=performance.now();}status.textContent='Toutes vos réponses sont enregistrées';if(json.submitted){closed=true;location.reload();}return json;}).catch(e=>{status.textContent='Connexion interrompue — réponses non enregistrées. Réessayez avant la fin du temps imparti.';status.style.color='#ad3f32';if(submit)throw e;});return pending;};
let debounce;exam.addEventListener('change',()=>{progress();clearTimeout(debounce);debounce=setTimeout(()=>save(),350);});exam.querySelector('[data-save-now]').onclick=()=>save();exam.addEventListener('submit',e=>{e.preventDefault();if(closed)return;progress();dialog.querySelector('[data-confirm-error]').hidden=true;dialog.showModal();});
dialog.querySelectorAll('[data-confirm-close]').forEach(button=>button.onclick=()=>dialog.close());
dialog.querySelector('[data-confirm-review]').onclick=()=>{dialog.close();const question=missing()[0];if(question)revealQuestion(question);};
let transmitting=false;
dialog.addEventListener('cancel',event=>{if(transmitting)event.preventDefault();});
dialog.querySelector('[data-confirm-send]').onclick=async()=>{
 if(transmitting||closed)return;
 transmitting=true;clearTimeout(debounce);
 const buttons=[...dialog.querySelectorAll('button')];buttons.forEach(button=>button.disabled=true);
 const send=dialog.querySelector('[data-confirm-send]');send.textContent='Transmission en cours…';
 try{await save(true);}catch{const error=dialog.querySelector('[data-confirm-error]');error.hidden=false;error.textContent='La transmission a échoué. Vérifiez votre connexion puis réessayez.';}
 finally{if(!closed){transmitting=false;buttons.forEach(button=>button.disabled=false);send.textContent='Transmettre définitivement';}}
};
for(const b of exam.querySelectorAll('[data-page-target]'))b.onclick=()=>{for(const section of exam.querySelectorAll('[data-exam-page]'))section.hidden=section.dataset.examPage!==b.dataset.pageTarget;for(const other of exam.querySelectorAll('[data-page-target]')){const active=other===b;other.classList.toggle('active',active);other.setAttribute('aria-current',active?'page':'false');}const page=[...exam.querySelectorAll('[data-exam-page]')].find(section=>!section.hidden);page?.focus({preventScroll:true});page?.scrollIntoView({behavior:'auto',block:'start'});};
const tick=()=>{const left=Math.max(0,Math.ceil(deadline-now()));timer.textContent=String(Math.floor(left/60)).padStart(2,'0')+':'+String(left%60).padStart(2,'0');if(left<300)timer.style.color='#ba5427';if(left===0&&!closed){closed=true;for(const input of exam.querySelectorAll('input[type=radio]'))input.disabled=true;status.textContent='Temps écoulé. Finalisation du test…';fetch(location.href,{method:'POST',body:new URLSearchParams({_token:exam.querySelector('[name=_token]').value,action:'save'}),headers:{'X-Requested-With':'XMLHttpRequest'}}).then(()=>location.reload()).catch(()=>{status.textContent='Temps écoulé. Reconnectez-vous pour consulter le résultat des réponses enregistrées.';});}};
progress();tick();setInterval(tick,1000);setInterval(()=>{if(!closed)save();},15000);window.addEventListener('online',()=>{if(closed)location.reload();else save();});}
for(const b of document.querySelectorAll('[data-print]'))b.onclick=()=>window.print();
})();

 document.querySelectorAll('form[data-confirm-delete]').forEach(form => {
  form.addEventListener('submit', event => {
    if (!window.confirm('Supprimer définitivement cette grille inutilisée ? Cette action est irréversible.')) event.preventDefault();
  });
});
