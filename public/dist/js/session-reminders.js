(() => {
  const root = document.getElementById('reminder-calendar');
  if (!root) return;
  const days = JSON.parse(root.dataset.days || '[]');
  if (!days.length) { root.textContent = 'Aucune journée passée ou en cours.'; return; }
  let selected = days[days.length - 1];
  let month = new Date(selected + 'T12:00:00');
  const min = days[0].slice(0, 7), max = days[days.length - 1].slice(0, 7);
  const key = (date) => date.getFullYear() + '-' + String(date.getMonth()+1).padStart(2,'0') + '-' + String(date.getDate()).padStart(2,'0');
  function draw() {
    root.replaceChildren();
    const head = document.createElement('div'); head.className = 'reminder-calendar-head';
    for (const direction of [-1, 0, 1]) {
      const el = document.createElement(direction ? 'button' : 'strong');
      if (!direction) { el.textContent = month.toLocaleDateString('fr-FR', {month:'long',year:'numeric'}); el.setAttribute('aria-live','polite'); }
      else {
        el.type = 'button'; el.className = 'btn btn-sm btn-light'; el.textContent = direction < 0 ? '‹' : '›';
        el.setAttribute('aria-label', direction < 0 ? 'Mois précédent' : 'Mois suivant');
        el.disabled = direction < 0 ? key(month).slice(0,7) <= min : key(month).slice(0,7) >= max;
        el.onclick = () => { month = new Date(month.getFullYear(), month.getMonth()+direction, 1,12); draw(); };
      }
      head.append(el);
    }
    root.append(head);
    const grid = document.createElement('div'); grid.className = 'reminder-calendar-grid';
    ['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'].forEach(label => {const e=document.createElement('small'); e.textContent=label; grid.append(e);});
    const first = new Date(month.getFullYear(),month.getMonth(),1,12);
    for (let i=0;i<(first.getDay()+6)%7;i++) grid.append(document.createElement('span'));
    const count = new Date(month.getFullYear(),month.getMonth()+1,0).getDate();
    for(let d=1;d<=count;d++){
      const date = new Date(month.getFullYear(),month.getMonth(),d,12), value=key(date);
      const button=document.createElement('button');button.type='button';button.textContent=d;button.disabled=!days.includes(value);
      button.setAttribute('aria-label', date.toLocaleDateString('fr-FR',{dateStyle:'full'}));button.setAttribute('aria-pressed',String(value===selected));
      button.onclick=()=>{selected=value;document.querySelectorAll('[data-reminder-date]').forEach(e=>e.value=value);document.querySelectorAll('[data-reminder-label]').forEach(e=>e.textContent=date.toLocaleDateString('fr-FR'));draw();};
      grid.append(button);
    }
    root.append(grid);
  }
  draw();
  document.querySelectorAll('[data-reminder-form]').forEach(form=>form.addEventListener('submit',()=>{const button=form.querySelector('button[type=submit]');if(button){button.disabled=true;button.textContent='Envoi en cours…';}}));
})();
