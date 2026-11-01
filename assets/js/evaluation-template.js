(() => {
  'use strict';
  const form = document.getElementById('evaluation-builder');
  if (!form) return;
  const hidden = document.getElementById('evaluation-schema');
  const error = document.getElementById('builder-error');
  let initial;
  try { initial = JSON.parse(hidden.value); } catch (_) { error.hidden = false; error.textContent = 'Le modèle ne peut pas être chargé. Rechargez la page.'; return; }
  const uid = () => 'n' + (globalThis.crypto?.randomUUID?.() || Date.now().toString(36) + Math.random().toString(36).slice(2)).replaceAll('-', '');
  const element = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };
  const button = (text, title, action) => { const b = element('button', 'ev-btn', text); b.type = 'button'; b.title = title; b.setAttribute('aria-label', title); b.addEventListener('click', action); return b; };
  const input = (type, value, attrs = {}) => { const n = document.createElement('input'); n.type = type; n.value = value ?? ''; Object.entries(attrs).forEach(([k, v]) => n.setAttribute(k, v)); return n; };
  const field = (title, control) => { const wrap = element('label', 'ev-field'); wrap.append(element('span', 'ev-label', title), control); return wrap; };
  const empty = kind => { const list = document.getElementById(kind + '-items'); const old = list.querySelector('.ev-builder-empty'); if (old) old.remove(); if (!list.querySelector('.ev-builder-item')) list.append(element('p', 'ev-builder-empty', kind === 'theory' ? 'Ajoutez votre première question.' : 'Ajoutez votre premier geste à évaluer.')); };
  function addOption(card, data = {}) {
    const wrap = element('div', 'ev-builder-option'); wrap.dataset.id = data.id || uid();
    const checked = input('checkbox', '1', {'aria-label': 'Bonne réponse'}); checked.className = 'ev-option-correct'; checked.checked = !!data.correct;
    const rank = input('number', data.rank || card.querySelectorAll('.ev-builder-option').length + 1, {min:1, step:1, 'aria-label':'Position dans l’ordre attendu'}); rank.className = 'ev-option-rank';
    const mark = element('label'); mark.append(checked, rank);
    const label = input('text', data.label, {required:'required', maxlength:3000, placeholder:'Proposition de réponse', 'aria-label':'Proposition de réponse'}); label.className = 'ev-option-label';
    wrap.append(mark, label, button('×', 'Retirer cette proposition', () => { wrap.remove(); update(); }));
    card.querySelector('.ev-builder-options').append(wrap);
    syncType(card); return wrap;
  }
  function syncType(card) {
    const type = card.querySelector('.ev-question-type').value;
    const options = card.querySelector('.ev-builder-options'); options.hidden = type === 'text';
    card.querySelector('.ev-add-option').hidden = type === 'text';
    card.querySelector('.ev-correct-hint').textContent = type === 'order' ? 'Indiquez la position attendue de chaque proposition (1, 2, 3…). Le stagiaire devra les remettre dans cet ordre.' : type === 'text' ? 'La réponse libre sera corrigée et notée par le formateur.' : 'Cochez ' + (type === 'single' ? 'la bonne réponse.' : 'toutes les bonnes réponses.');
    options.querySelectorAll('.ev-builder-option').forEach(row => {
      const choice = row.querySelector('.ev-option-correct'); const rank = row.querySelector('.ev-option-rank'); const label = row.querySelector('.ev-option-label');
      choice.type = type === 'single' ? 'radio' : 'checkbox'; choice.name = 'correct-' + card.dataset.id; choice.hidden = type === 'order'; choice.disabled = type === 'text';
      rank.hidden = type !== 'order'; rank.disabled = type !== 'order'; rank.required = type === 'order';
      label.disabled = type === 'text'; row.classList.toggle('is-order', type === 'order');
    });
  }
  function add(kind, data = {}) {
    if (!data || typeof data !== 'object' || Array.isArray(data)) data = {};
    const card = element('div', 'ev-builder-item'); card.dataset.id = data.id || uid(); card.dataset.kind = kind;
    const head = element('div', 'ev-builder-item-head'); const index = element('strong', 'ev-item-index');
    const actions = element('div', 'ev-builder-small-actions');
    actions.append(button('↑', 'Monter cet élément', () => { const prev = card.previousElementSibling; if (prev?.classList.contains('ev-builder-item')) prev.before(card); update(); }), button('↓', 'Descendre cet élément', () => { const next = card.nextElementSibling; if (next?.classList.contains('ev-builder-item')) next.after(card); update(); }), button('×', 'Supprimer cet élément', () => { if (window.confirm('Supprimer cet élément du modèle ?')) {card.remove(); update();} }));
    head.append(index, actions); card.append(head);
    const label = element('textarea', 'ev-item-label'); label.value = data.label || ''; label.required = true; label.maxLength = 5000; label.rows = 2; label.placeholder = kind === 'theory' ? 'Votre question…' : 'Le geste ou la compétence à observer…';
    card.append(field(kind === 'theory' ? 'Énoncé de la question' : 'Critère d’évaluation', label));
    const meta = element('div', 'ev-builder-meta'); const points = input('number', data.points ?? 1, {min:'0.01', max:'1000', step:'0.01', required:'required'}); points.className = 'ev-item-points';
    if (kind === 'theory') {
      const type = document.createElement('select'); type.className = 'ev-question-type'; [['single','Choix unique'],['multiple','Choix multiples'],['order','Remettre dans l’ordre'],['text','Réponse libre']].forEach(([v,t]) => {const option = document.createElement('option');option.value=v;option.textContent=t;type.append(option);}); type.value = data.type || 'single';
      type.addEventListener('change', () => {syncType(card); update();}); meta.append(field('Type de réponse',type),field('Points',points)); card.append(meta,element('p','ev-muted ev-correct-hint'),element('div','ev-builder-options'));
      const addChoice = button('+ Ajouter une proposition','Ajouter une proposition',()=>{addOption(card);update();});addChoice.classList.add('ev-add-option');card.append(addChoice);
      const opts = Array.isArray(data.options) ? data.options.filter(o=>o && typeof o === 'object') : [{id:uid(),label:''},{id:uid(),label:''}]; const correct = Array.isArray(data.correct) ? data.correct : []; opts.forEach((o,i)=>addOption(card,{...o,correct:correct.includes(o.id),rank:correct.includes(o.id)?correct.indexOf(o.id)+1:i+1}));syncType(card);
    } else {
      meta.append(field('Barème maximal',points));card.append(meta);
      const critical = input('checkbox','1');critical.className='ev-item-critical';critical.checked=!!data.critical; const criticalLabel=element('label','ev-builder-check');criticalLabel.append(critical,element('span','','Critère éliminatoire : un échec constaté empêche l’avis pratique favorable.'));
      const na=input('checkbox','1');na.className='ev-item-na';na.checked=data.allowNa !== false;const naLabel=element('label','ev-builder-check');naLabel.append(na,element('span','','Autoriser « Non applicable » pour ce critère.'));
      card.append(criticalLabel,naLabel);
    }
    document.getElementById(kind+'-items').append(card);update();return card;
  }
  function collect(kind) {
    return [...document.querySelectorAll('#'+kind+'-items .ev-builder-item')].map(card => {
      const row={id:card.dataset.id,label:card.querySelector('.ev-item-label').value.trim(),points:Number(card.querySelector('.ev-item-points').value)};
      if(kind==='practice') return {...row,critical:card.querySelector('.ev-item-critical').checked,allowNa:card.querySelector('.ev-item-na').checked};
      const type=card.querySelector('.ev-question-type').value;
      const optionRows=[...card.querySelectorAll('.ev-builder-option')];
      const options=type==='text'?[]:optionRows.map(o=>({id:o.dataset.id,label:o.querySelector('.ev-option-label').value.trim()}));
      const correct=type==='text'?[]:type==='order'?[...optionRows].sort((a,b)=>Number(a.querySelector('.ev-option-rank').value)-Number(b.querySelector('.ev-option-rank').value)).map(o=>o.dataset.id):optionRows.filter(o=>o.querySelector('.ev-option-correct').checked).map(o=>o.dataset.id);
      return {...row,type,options,correct};
    });
  }
  function update() {
    ['theory','practice'].forEach(kind=>{empty(kind);const items=collect(kind);document.querySelectorAll('#'+kind+'-items .ev-item-index').forEach((n,i)=>n.textContent=(kind==='theory'?'Question ':'Critère ')+String(i+1).padStart(2,'0'));document.getElementById(kind+'-count').textContent=items.length+(kind==='theory'?' question':' critère')+(items.length>1?'s':'');document.getElementById(kind+'-total').textContent=items.reduce((sum,i)=>sum+(Number.isFinite(i.points)?i.points:0),0).toLocaleString('fr-FR')+' points';});
  }
  (Array.isArray(initial.theory)?initial.theory:[]).forEach(x=>add('theory',x));(Array.isArray(initial.practice)?initial.practice:[]).forEach(x=>add('practice',x));update();
  form.querySelectorAll('[data-add]').forEach(b=>b.addEventListener('click',()=>{const card=add(b.dataset.add);card.querySelector('textarea').focus();}));form.addEventListener('input',update);
  form.addEventListener('submit',event=>{
    error.hidden=true;
    const schema={theory:collect('theory'),practice:collect('practice'),theoryThreshold:document.getElementById('theory-threshold').value===''?null:Number(document.getElementById('theory-threshold').value),practiceThreshold:document.getElementById('practice-threshold').value===''?null:Number(document.getElementById('practice-threshold').value)};
    let message='';
    if(!schema.theory.length||!schema.practice.length)message='Ajoutez au moins une question théorique et un critère pratique.';
    schema.theory.forEach((q,i)=>{if(q.type!=='text'&&(!q.correct.length||q.options.length<2))message='Question '+(i+1)+' : ajoutez au moins deux propositions et indiquez le corrigé.';});
    form.querySelectorAll('[data-kind="theory"]').forEach(card=>{if(card.querySelector('.ev-question-type').value!=='order')return;const ranks=[...card.querySelectorAll('.ev-option-rank')].map(n=>Number(n.value)).sort((a,b)=>a-b);if(ranks.some((rank,i)=>rank!==i+1))message='Pour une question à ordonner, utilisez chaque position une seule fois, de 1 au nombre de propositions.';});
    hidden.value=JSON.stringify(schema);
    if(message){event.preventDefault();error.textContent=message;error.hidden=false;error.scrollIntoView({block:'center',behavior:'smooth'});}
  });
})();
