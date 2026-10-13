(() => {
  const root = document.querySelector('[data-notification-root]');
  if (!root) return;
  const center = document.querySelector('[data-notification-center]');
  const known = ['formateur','stagiaire','entreprise','of'];
  const pathSpace = location.pathname.split('/').find(part => known.includes(part));
  let space = center?.dataset.space || pathSpace || '';
  let page = 1, state = 'all', timer, controller;
  const bell = root.querySelector('.wn-bell'), popover = root.querySelector('.wn-popover');
  const selected=new Set(); let visibleKeys=[], busy=false;
  function selection() {
    if(!center)return;
    document.getElementById('wn-selected').textContent=selected.size+' sélectionnée'+(selected.size>1?'s':'');
    const all=document.getElementById('wn-select-all');all.checked=visibleKeys.length>0&&selected.size===visibleKeys.length;all.indeterminate=selected.size>0&&!all.checked;all.disabled=busy||!visibleKeys.length;
    ['read','archive'].forEach(action=>document.getElementById('wn-bulk-'+action).disabled=busy||!selected.size);
  }
  async function bulk(action) {
    if(busy||!selected.size)return;busy=true;selection();
    const error=document.getElementById('wn-bulk-error');error.hidden=true;
    try {
      const response=await fetch(root.dataset.stateUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrf},body:JSON.stringify({action,keys:[...selected],space})});
      if(!response.ok)throw new Error();selected.clear();await load();
    } catch {error.textContent='Impossible d’enregistrer la sélection. Réessayez.';error.hidden=false;}
    finally {busy=false;selection();}
  }
  const icons = {contracts:'file-earmark-text', attendance:'pen',documents:'folder2-open',feedback:'chat-square-text',invoices:'receipt',progress:'diagram-3'};
  function el(tag, className, text) { const node=document.createElement(tag); if(className) node.className=className; if(text!==undefined) node.textContent=text; return node; }
  function row(item, compact=false) {
    const article=el('article','wn-item'+(!item.read?' wn-unread':''));
    if(!compact) { const check=el('input','wn-select');check.type='checkbox';check.checked=selected.has(item.key);check.setAttribute('aria-label','Sélectionner '+item.title+' · '+item.detail);check.onchange=()=>{check.checked?selected.add(item.key):selected.delete(item.key);selection();};article.append(check); }
    const icon=el('span','wn-icon wn-'+item.category); icon.append(el('i','bi bi-'+(icons[item.category]||'bell'))); icon.setAttribute('aria-hidden','true');
    const body=el('div','wn-item-body');
    const link=el('a','wn-item-link',item.title); link.href=item.url;
    link.addEventListener('click',()=>{ mutate('read',item.key,false).catch(()=>{}); });
    body.append(link,el('p','wn-detail',item.detail));
    const meta=el('div','wn-meta');meta.append(el('span','wn-status '+(item.state==='action'?'wn-action':''),item.state==='action'?'À traiter':'Information'));
    if(item.date) { const time=el('time','',new Intl.DateTimeFormat('fr-FR',{dateStyle:'medium',timeStyle:'short',timeZone:'Europe/Paris'}).format(new Date(item.date)));time.dateTime=item.date;meta.append(time); }
    else meta.append(el('span','','État actuel du dossier'));
    body.append(meta);article.append(icon,body);
    if(!compact) {
      const actions=el('div','wn-item-actions');
      const read=el('button','',item.read?'Marquer non lu':'Marquer lu');read.type='button';read.onclick=()=>mutate(item.read?'unread':'read',item.key);
      const archive=el('button','',item.archived?'Restaurer':'Archiver');archive.type='button';archive.onclick=()=>mutate(item.archived?'restore':'archive',item.key);
      actions.append(read,archive);article.append(actions);
    }
    return article;
  }
  async function mutate(action,key,refresh=true) {
    try {
    const response=await fetch(root.dataset.stateUrl,{method:'POST',credentials:'same-origin',keepalive:true,headers:{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrf},body:JSON.stringify({action,key,space})});
    if(!response.ok) { if(refresh) showError('Impossible d’enregistrer cette action. Rechargez la page puis réessayez.'); return; }
    if(refresh) load();
    } catch(error) { if(refresh) showError("Connexion interrompue. Réessayez votre action."); }
  }
  function showError(message) { const target=center?document.getElementById('wn-list'):root.querySelector('.wn-preview'); target.replaceChildren(el('p','wn-empty',message)); }
  async function load() {
    controller?.abort();controller=new AbortController();
    const url=new URL(root.dataset.feed,location.origin);
    if(space) url.searchParams.set('space',space);
    url.searchParams.set('page',String(page));url.searchParams.set('limit',center?'25':'8');
    if(center) { url.searchParams.set('state',state);url.searchParams.set('q',document.getElementById('wn-search').value);url.searchParams.set('category',document.getElementById('wn-category').value); }
    try {
      const res=await fetch(url,{signal:controller.signal,credentials:'same-origin'});if(!res.ok) throw new Error('feed');
      const data=await res.json();space=data.space;
      const badge=root.querySelector('.wn-count');badge.hidden=!data.counts.unread;badge.textContent=data.counts.unread>99?'99+':data.counts.unread;
      bell.setAttribute('aria-label',`Notifications : ${data.counts.unread} non lues`);
      const centerLink=new URL(root.dataset.centerUrl,location.origin);centerLink.searchParams.set('space',space);root.querySelector('footer a').href=centerLink;
      const preview=root.querySelector('.wn-preview');preview.replaceChildren(...data.items.slice(0,8).map(i=>row(i,true)));if(!data.items.length) preview.append(el('p','wn-empty','Aucune notification dans cette vue.'));
      if(center) {
        for(const [key,value] of Object.entries(data.counts)) document.querySelectorAll(`[data-count="${key}"]`).forEach(n=>n.textContent=value);
        visibleKeys=data.items.map(i=>i.key);for(const key of selected)if(!visibleKeys.includes(key))selected.delete(key);selection();
        const list=document.getElementById('wn-list');list.replaceChildren(...data.items.map(i=>row(i)));
        if(!data.items.length) list.append(el('p','wn-empty','Aucun élément pour ces filtres.'));
        document.getElementById('wn-total').textContent=`${data.total} élément${data.total>1?'s':''}`;
        document.getElementById('wn-page').textContent=`${data.page} / ${Math.max(1,data.pages)}`;
        document.getElementById('wn-prev').disabled=page<=1;document.getElementById('wn-next').disabled=page>=data.pages;
      }
    } catch(error) { if(error.name!=='AbortError') showError('Le suivi est momentanément indisponible. Réessayez en ouvrant les notifications.'); }
  }
  document.addEventListener('show.bs.dropdown',event=>{if(event.target.closest('.adminTopNav')){popover.hidden=true;bell.setAttribute('aria-expanded','false');}});
  bell.onclick=()=>{
    document.querySelectorAll('.adminTopNav [data-bs-toggle="dropdown"][aria-expanded="true"]').forEach(toggle=>window.bootstrap?.Dropdown.getInstance(toggle)?.hide());
    popover.hidden=!popover.hidden;bell.setAttribute('aria-expanded',String(!popover.hidden));if(!popover.hidden) load();};
  document.addEventListener('click',event=>{if(!root.contains(event.target)){popover.hidden=true;bell.setAttribute('aria-expanded','false');}});
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!popover.hidden){popover.hidden=true;bell.setAttribute('aria-expanded','false');bell.focus();}});
  root.querySelector('[data-mark-all]').onclick=()=>mutate('read_all');
  if(center) {
    document.getElementById('wn-select-all').onchange=event=>{selected.clear();if(event.target.checked)visibleKeys.forEach(key=>selected.add(key));center.querySelectorAll('.wn-select').forEach(check=>check.checked=event.target.checked);selection();};
    ['read','archive'].forEach(action=>document.getElementById('wn-bulk-'+action).onclick=()=>bulk(action));
    center.querySelector('[data-center-mark-all]').onclick=()=>mutate('read_all');
    center.querySelectorAll('[data-filter]').forEach(button=>button.onclick=()=>{selected.clear();state=button.dataset.filter;page=1;center.querySelectorAll('.wn-tabs button').forEach(b=>b.classList.toggle('active',b.dataset.filter===state));load();});
    document.getElementById('wn-space').onchange=event=>{selected.clear();space=event.target.value;page=1;load();};
    document.getElementById('wn-category').onchange=()=>{selected.clear();page=1;load();};
    document.getElementById('wn-search').oninput=()=>{clearTimeout(timer);timer=setTimeout(()=>{selected.clear();page=1;load();},250);};
    document.getElementById('wn-prev').onclick=()=>{selected.clear();page--;load();};document.getElementById('wn-next').onclick=()=>{selected.clear();page++;load();};
  }
  load();setInterval(()=>{if(!document.hidden) load();},60000);
})();
