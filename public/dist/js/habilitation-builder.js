(() => {
  const form = document.getElementById('hab-builder'); if (!form) return;
  const hidden = document.getElementById('hab-schema'), host = document.getElementById('hab-sections');
  let schema;
  try { schema = JSON.parse(hidden.value); if (!Array.isArray(schema.sections)) throw Error(); }
  catch { host.textContent = 'Modèle illisible. Rechargez la page.'; form.querySelector('button[type="submit"],.hab-savebar button').disabled = true; return; }
  let serial = 0;
  const id = () => 'n_' + Date.now().toString(36) + '_' + serial++;
  const el = (tag, className, text) => { const e = document.createElement(tag); e.className = className || ''; if (text) e.textContent = text; return e; };
  const button = (text, action, label) => { const b = el('button', 'hab-btn', text); b.type = 'button'; if(label) b.setAttribute('aria-label',label); b.addEventListener('click', action); return b; };
  const field = (parent, label, value, change, tag = 'input') => { const wrap=el('div','hab-grow'), l=el('label','',label), input=el(tag); input.id=id();l.htmlFor=input.id;input.value=value;input.required=true;input.maxLength=tag==='textarea'?8000:180;input.addEventListener('input',()=>change(input.value)); wrap.append(l,input);parent.append(wrap);return input; };
  const controls = (array, index, what) => { const box=el('div','hab-builder-controls');box.append(button('↑',()=>{if(index>0){[array[index-1],array[index]]=[array[index],array[index-1]];render();}},'Monter '+what),button('↓',()=>{if(index<array.length-1){[array[index+1],array[index]]=[array[index],array[index+1]];render();}},'Descendre '+what),button('Supprimer',()=>{if(window.confirm('Supprimer '+what+' de ce modèle ?')){array.splice(index,1);render();}}));return box; };
  const newField = (label,type,options=[]) => ({id:id(),label,type,options});
  const newRow = () => ({id:id(),title:'Nouvelle sous-section',fields:[newField('Symbole d’habilitation électrique','checkbox',['B0','H0']),newField('Domaine de tension','checkbox',['TBT','BT','HTA','HTB']),newField('Ouvrages ou installations concernés','text'),newField('Indications supplémentaires','text')]});
  function render(){host.querySelectorAll('select').forEach(select=>select.tomselect?.destroy());host.replaceChildren();schema.sections.forEach((section,si)=>{
    const panel=el('section','hab-panel hab-builder-section'),heading=el('div','hab-heading');
    field(heading,'Section '+(si+1),section.title,v=>section.title=v);heading.append(controls(schema.sections,si,'la section'));panel.append(heading);
    section.rows.forEach((row,ri)=>{const block=el('div','hab-builder-row'),head=el('div','hab-heading');field(head,'Sous-section',row.title,v=>row.title=v);head.append(controls(section.rows,ri,'la sous-section'));block.append(head);const cells=el('div','hab-builder-fields');row.fields.forEach((f,fi)=>{const cell=el('div','hab-builder-field');field(cell,'Intitulé de la colonne',f.label,v=>f.label=v);const label=el('label','','Type de réponse'),select=el('select');select.id=id();label.htmlFor=select.id;[['checkbox','Cases à cocher'],['text','Champ libre']].forEach(([v,t])=>{const o=el('option','',t);o.value=v;select.append(o);});select.value=f.type;select.addEventListener('change',()=>{f.type=select.value;if(f.type==='checkbox'&&!f.options.length)f.options=['Nouveau choix'];render();});cell.append(label,select);if(f.type==='checkbox')field(cell,'Choix proposés · un par ligne',f.options.join('\n'),v=>f.options=v.split('\n').map(s=>s.trim()).filter(Boolean),'textarea');cell.append(controls(row.fields,fi,'la colonne'));cells.append(cell);});block.append(cells);if(row.fields.length<6)block.append(button('+ Ajouter une colonne',()=>{row.fields.push(newField('Nouveau champ','text'));render();}));panel.append(block);});
    if(section.rows.length<25)panel.append(button('+ Ajouter une sous-section',()=>{section.rows.push(newRow());render();}));host.append(panel);
  });hidden.value=JSON.stringify(schema);window.WikiFormationForms?.init(host);}
  document.getElementById('hab-add-section').addEventListener('click',()=>{if(schema.sections.length>=20)return;schema.sections.push({id:id(),title:'Nouvelle section',rows:[newRow()]});render();});
  form.addEventListener('submit',()=>{hidden.value=JSON.stringify(schema);});render();
})();
