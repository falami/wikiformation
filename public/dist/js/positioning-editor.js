(function () {
  'use strict';
  function boot() {
    const form = document.getElementById('positioningForm');
    if (!form || form.__positioningEditor) return;
    form.__positioningEditor = true;
    const editor = form.closest('.positioning-editor');
    const chapters = editor.querySelector('#chapters-sortable');
    const state = editor.querySelector('#editorSaveState');
    const publication = form.querySelector('input[name$="[isPublished]"]');
    function changed() {
      state.textContent = 'Paramètres non enregistrés';
      state.classList.add('is-dirty');
      const badge = editor.querySelector('#positioningStatus');
      badge.textContent = publication.checked ? 'Publié' : 'Brouillon';
      badge.classList.toggle('is-inactive', !publication.checked);
    }
    form.addEventListener('input', changed);
    form.addEventListener('change', changed);
    function open(chapter, expanded) {
      chapter.querySelector('.chapter-body').hidden = !expanded;
      chapter.classList.toggle('is-collapsed', !expanded);
      const button = chapter.querySelector('.js-toggle-chapter');
      button.setAttribute('aria-expanded', String(expanded));
      button.setAttribute('aria-label', expanded ? 'Replier le chapitre' : 'Déplier le chapitre');
    }
    editor.addEventListener('click', event => {
      const toggle = event.target.closest('.js-toggle-chapter');
      if (toggle) open(toggle.closest('.chapter'), toggle.getAttribute('aria-expanded') !== 'true');
      const link = event.target.closest('#positioningOutline a');
      if (link) {
        const chapter = document.getElementById(link.getAttribute('href').slice(1));
        if (chapter) {
          event.preventDefault();
          let ancestor = chapter.parentElement.closest('.chapter');
          while (ancestor) { open(ancestor, true); ancestor = ancestor.parentElement.closest('.chapter'); }
          open(chapter, true);
          chapter.scrollIntoView({block: 'start', behavior: 'smooth'});
        }
      }
    });
    if (chapters) refreshTree();
    function refreshTree() {
      const outline = editor.querySelector('#positioningOutline');
      if (outline) outline.replaceChildren();
      function visit(list, prefix) {
        Array.from(list.children).filter(node => node.matches('.chapter')).forEach((chapter, index) => {
          const number = prefix ? prefix + '.' + (index + 1) : String(index + 1);
          chapter.querySelector('.pos-badge').textContent = number;
          if (outline) {
            const li = document.createElement('li');
            const link = document.createElement('a');
            link.href = '#' + chapter.id;
            link.textContent = number + ' · ' + chapter.querySelector('h3').textContent;
            li.style.paddingLeft = (prefix.split('.').filter(Boolean).length * 12) + 'px';
            li.appendChild(link);
            outline.appendChild(li);
          }
          const children = chapter.querySelector('.pe-children');
          if (children) visit(children, number);
        });
      }
      visit(chapters, '');
    }
    if (!chapters || typeof Sortable !== 'function') return;
    const notice = editor.querySelector('#positioningOrderState');
    const instances = [];
    const nodes = (list, selector) => Array.from(list.children).filter(node => node.matches(selector));
    function renumber(list, selector) {
      nodes(list, selector).forEach((node, index) => {
        node.querySelector('.pos-badge').textContent = String(index + 1).padStart(2, '0');
      });
      if (list !== chapters) return;
      const outline = editor.querySelector('#positioningOutline');
      if (outline) nodes(list, selector).forEach((chapter, index) => {
        const link = outline.querySelector('a[href="#' + chapter.id + '"]');
        if (link) {
          link.firstElementChild.textContent = String(index + 1).padStart(2, '0');
          outline.appendChild(link.parentElement);
        }
      });
    }
    function attach(list, selector, handle) {
      let previous = [];
      instances.push(new Sortable(list, {
        handle, draggable: selector, animation: 180,
        ghostClass: 'sortable-ghost', chosenClass: 'sortable-chosen',
        onStart() { previous = Array.from(list.children); },
        async onEnd(event) {
          if (event.oldIndex === event.newIndex) return;
          instances.forEach(instance => instance.option('disabled', true));
          notice.textContent = 'Enregistrement de l’ordre…';
          notice.classList.remove('is-error');
          try {
            const response = await fetch(list.dataset.reorderUrl, {
              method: 'POST',
              headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
              body: JSON.stringify({_token: chapters.dataset.csrf, orderedIds: (selector === '.chapter' ? Array.from(chapters.querySelectorAll('.chapter')) : nodes(list, selector)).map(node => Number(node.dataset.id))})
            });
            if (!response.ok) throw new Error('Reorder failed');
            const result = await response.json();
            if (result.ok === false) throw new Error('Reorder rejected');
            if (selector === '.chapter') refreshTree();
            else renumber(list, selector);
            notice.textContent = 'Ordre enregistré';
          } catch (_) {
            previous.forEach(node => list.appendChild(node));
            notice.textContent = 'L’ordre n’a pas pu être enregistré. Réessayez après avoir vérifié votre connexion.';
            notice.classList.add('is-error');
          } finally {
            instances.forEach(instance => instance.option('disabled', false));
          }
        }
      }));
    }
    attach(chapters, '.chapter', '.drag-handle-chapter');
    chapters.querySelectorAll('.pe-children').forEach(list => attach(list, '.chapter', '.drag-handle-chapter'));
    chapters.querySelectorAll('.items-sortable').forEach(list => attach(list, '.item', '.drag-handle-item'));
  }
  document.addEventListener('DOMContentLoaded', boot);
  document.addEventListener('turbo:load', boot);
  if (document.readyState !== 'loading') boot();
})();
