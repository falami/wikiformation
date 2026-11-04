(() => {
  'use strict';
  const board = document.querySelector('.crm-board');
  if (!board || !window.Sortable) return;
  const lists = [...board.querySelectorAll('.crm-kanban-cards')];
  const status = document.createElement('p');
  status.className = 'crm-kanban-status'; status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
  board.before(status);
  let busy = false, dragged = false;
  const storageKey = 'crm-kanban:' + location.pathname + location.search;
  try {
    const saved = JSON.parse(sessionStorage.getItem(storageKey) || 'null');
    sessionStorage.removeItem(storageKey);
    if (saved && Date.now() - saved.time < 15000) {
      board.scrollLeft = saved.left; window.scrollTo(0, saved.top); status.textContent = saved.message;
    }
  } catch (_) {}
  const sortables = lists.map(list => new Sortable(list, {
    group:'prospect-pipeline', draggable:'.crm-contact', handle:'.crm-drag-handle',
    animation:180, sort:false, ghostClass:'crm-kanban-ghost', chosenClass:'crm-kanban-chosen',
    delay:150, delayOnTouchOnly:true, touchStartThreshold:5, scroll:true, bubbleScroll:true,
    scrollSensitivity:70, scrollSpeed:12,
    onStart() { dragged = true; board.classList.add('is-dragging'); },
    onEnd(event) {
      board.classList.remove('is-dragging');
      setTimeout(() => { dragged = false; }, 0);
      if (event.from === event.to) return;
      move(event.item, event.from, event.to, event.oldDraggableIndex);
    }
  }));
  function lock(value) {
    busy = value; sortables.forEach(sortable => sortable.option('disabled', value));
    board.setAttribute('aria-busy', String(value));
    // Prevent other mutations while a card's status is being saved.
    document.querySelector('.crm')?.toggleAttribute('inert', value);
  }
  function restore(card, from, index) {
    const siblings = [...from.querySelectorAll(':scope > .crm-contact')].filter(el => el !== card);
    from.insertBefore(card, siblings[index] || null);
  }
  async function move(card, from, to, index) {
    if (busy) { restore(card, from, index); return; }
    const target = to.closest('[data-kanban-stage]');
    const action = card.querySelector('[data-followup-url]');
    if (!target || !action) { restore(card, from, index); return; }
    lock(true); card.classList.add('is-saving');
    status.textContent = 'Enregistrement du changement d’étape…';
    try {
      const response = await fetch(action.dataset.followupUrl, {
        method:'POST', headers:{'X-Requested-With':'XMLHttpRequest', 'Accept':'application/json'},
        body:new URLSearchParams({_token:action.dataset.token, action:'status', status:target.dataset.kanbanStage, date:'', note:''})
      });
      if (response.redirected || !response.headers.get('content-type')?.includes('application/json')) throw new Error('Session expirée ou réponse indisponible. Actualisez la page pour vérifier l’étape.');
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || 'Le changement d’étape n’a pas pu être confirmé. Actualisez la page.');
      const message = `${action.dataset.name} déplacé vers « ${target.dataset.kanbanLabel} ».`;
      // Reload the authoritative filtered page, including totals, reminders and bulk-selection data.
      try { sessionStorage.setItem(storageKey, JSON.stringify({left:board.scrollLeft, top:window.scrollY, message, time:Date.now()})); } catch (_) {}
      location.reload();
    } catch (error) {
      restore(card, from, index); card.classList.remove('is-saving'); lock(false);
      status.textContent = error instanceof TypeError ? 'Connexion interrompue. Actualisez la page pour vérifier l’étape avant de réessayer.' : error.message;
      card.querySelector('.crm-drag-handle')?.focus();
    }
  }
  // The same handle opens the existing accessible follow-up dialog on click/keyboard.
  board.addEventListener('click', event => {
    const handle = event.target.closest('.crm-drag-handle');
    if (!handle || dragged || busy) return;
    const action = handle.closest('.crm-contact').querySelector('[data-followup-url]');
    action.click();
    document.querySelector('#crmFollowup [data-followup-action="status"]')?.click();
  });
})();
