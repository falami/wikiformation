(function (root, factory) {
  'use strict';
  const editor = factory(root);
  if (typeof module === 'object' && module.exports) module.exports = editor;
  if (!root.document) return;
  root.WikiSatisfactionEditor = editor;
  if (!root.__satisfactionEditorEvents) {
    root.__satisfactionEditorEvents = true;
    ['DOMContentLoaded', 'turbo:load'].forEach(event => {
      root.document.addEventListener(event, () => root.WikiSatisfactionEditor.boot());
    });
  }
  if (root.document.readyState !== 'loading') editor.boot();
})(typeof window !== 'undefined' ? window : globalThis, function (root) {
  'use strict';

  const qs = (selector, scope) => scope.querySelector(selector);
  const qsa = (selector, scope) => Array.from(scope.querySelectorAll(selector));
  const number = value => String(value).padStart(2, '0');
  const questionTypes = {
    scale: ['bi-bar-chart-steps', 'Échelle de 0 à 10'],
    stars: ['bi-star', 'Étoiles (ancien format)'],
    yes_no: ['bi-check2-circle', 'Oui ou non'],
    text: ['bi-input-cursor-text', 'Réponse courte'],
    textarea: ['bi-card-text', 'Réponse détaillée'],
    choice: ['bi-ui-radios', 'Un seul choix'],
    multichoice: ['bi-ui-checks-grid', 'Plusieurs choix'],
    multi_formations: ['bi-mortarboard', 'Sélection de formations']
  };
  const metricDescriptions = {
    '': 'Associez cette note à un indicateur pour suivre vos résultats.',
    overall_rating: 'Mesure la satisfaction globale de vos participants.',
    intern_rating: 'Mesure la satisfaction concernant les stagiaires.',
    trainer_rating: 'Mesure la satisfaction concernant le formateur.',
    site_rating: 'Mesure la satisfaction concernant le lieu de formation.',
    content_rating: 'Mesure la satisfaction concernant le contenu de la formation.',
    recommendation: 'Mesure la recommandation avec une note de 0 à 10 (NPS).',
    organism_rating: 'Mesure la satisfaction concernant votre organisme.'
  };

  // Collection keys can be sparse after a deletion or a validation error.
  function nextIndex(current, names, collection) {
    let next = /^\d+$/.test(String(current)) ? Number(current) : 0;
    const pattern = new RegExp('\\[' + collection + '\\]\\[(\\d+)\\]');
    names.forEach(name => {
      const match = String(name).match(pattern);
      if (match) next = Math.max(next, Number(match[1]) + 1);
    });
    return next;
  }

  function fillPrototype(prototype, chapterIndex, questionIndex) {
    let html = String(prototype || '');
    if (chapterIndex !== undefined && chapterIndex !== null) {
      html = html.replace(/__chapter__/g, String(chapterIndex));
    }
    if (questionIndex !== undefined && questionIndex !== null) {
      html = html.replace(/__question__/g, String(questionIndex));
    }
    return html;
  }

  function applyQuestionUI(question) {
    const select = qs('.js-qtype', question) || qs('select[name$="[type]"]', question);
    if (!select) return;
    const type = select.value;
    const description = type === 'scale' && qs('input[name$="[minValue]"]', question) ? ['bi-bar-chart-steps', 'Échelle de notation'] : questionTypes[type] || ['bi-info-circle', 'Type de réponse'];
    const pill = qs('.js-typepill', question);
    if (pill) {
      // Only our static icon and label are inserted as markup.
      pill.innerHTML = '<i class="bi ' + description[0] + '" aria-hidden="true"></i> ' + description[1];
    }
    qsa('.js-fields .js-field', question).forEach(field => {
      const visible = field.classList.contains('js-for-' + type);
      field.hidden = !visible;
      // Explicitly override the previous editor's display:none stylesheet.
      field.style.display = visible ? 'block' : 'none';
    });
    const metric = qs('.js-metrickey', question);
    if (!metric) return;
    const metricField = metric.closest('[data-metric-field]') || metric.closest('.col-md-4');
    const isScale = type === 'scale';
    if (metricField) {
      metricField.hidden = !isScale;
      metricField.style.display = isScale ? '' : 'none';
    }
    if (!isScale) metric.value = '';
    metric.disabled = !isScale;
    const help = qs('.js-metric-help', question);
    if (help) help.textContent = metricDescriptions[metric.value] || metricDescriptions[''];
  }

  function boot(doc = root.document) {
    if (!doc) return null;
    const form = doc.getElementById('tplForm');
    const chapters = doc.getElementById('chapters');
    if (!form || !chapters) return null;
    // An expando, rather than a data attribute, also works after Turbo clones DOM.
    if (form.__satisfactionEditor) return form.__satisfactionEditor;

    const state = { dirty: false, sortables: new Map() };
    form.__satisfactionEditor = state;
    const chapterNodes = () => qsa('[data-chapter]', chapters);
    const questionNodes = chapter => qsa('[data-question]', chapter);
    const names = scope => qsa('[name]', scope).map(input => input.name);

    function allocateIndex(container, collection) {
      const index = nextIndex(container.dataset.index, names(container), collection);
      container.dataset.index = String(index + 1);
      return index;
    }

    function chapterIndex(chapter) {
      const name = names(chapter).find(value => /\[chapters\]\[\d+\]/.test(value));
      return name ? name.match(/\[chapters\]\[(\d+)\]/)[1] : null;
    }

    function openChapter(chapter, open = true) {
      const body = qs('.chapter-body', chapter);
      if (body) body.hidden = !open;
      const button = qs('.js-toggle-chapter', chapter);
      if (button) {
        button.setAttribute('aria-expanded', String(open));
        button.setAttribute('aria-label', open ? 'Replier le chapitre' : 'Déplier le chapitre');
        button.title = open ? 'Replier le chapitre' : 'Déplier le chapitre';
      }
      chapter.classList.toggle('is-collapsed', !open);
    }

    function updateStatus() {
      const saveState = doc.getElementById('editorSaveState');
      if (saveState) {
        saveState.textContent = state.dirty ? 'Modifications non enregistrées' : 'Aucune modification';
        saveState.classList.toggle('is-dirty', state.dirty);
      }
      const active = qs('input[type="checkbox"][name$="[isActive]"]', form);
      const status = doc.getElementById('editorStatus');
      if (status && active) {
        status.textContent = active.checked ? 'Actif' : 'Inactif';
        status.classList.toggle('is-inactive', !active.checked);
      }
      const formations = qs('.js-tomselect', form);
      const formationCount = doc.getElementById('formationCount');
      if (formations && formationCount) {
        const count = Array.from(formations.options).filter(option => option.selected && option.value !== '').length;
        formationCount.textContent = count ? count + ' formation' + (count > 1 ? 's' : '') + ' sélectionnée' + (count > 1 ? 's' : '') : 'Toutes les formations';
      }
    }

    function renumber() {
      const allChapters = chapterNodes();
      const outline = doc.getElementById('chapterOutline');
      const links = [];
      let questionsCount = 0;
      let requiredCount = 0;
      allChapters.forEach((chapter, index) => {
        const key = chapterIndex(chapter);
        chapter.id = 'chapter-' + (key === null ? index : key);
        const position = qs('input[name$="[position]"]', chapter);
        if (position) position.value = String(index + 1);
        qsa('[data-chapter-number]', chapter).forEach(node => { node.textContent = number(index + 1); });
        const titleInput = qs('.js-chapter-title', chapter) || qs('input[name$="[titre]"]', chapter);
        const title = titleInput && titleInput.value.trim() ? titleInput.value.trim() : 'Nouveau chapitre';
        qsa('[data-chapter-summary]', chapter).forEach(node => { node.textContent = title; });
        const questions = questionNodes(chapter);
        qsa('[data-chapter-question-count]', chapter).forEach(node => { node.textContent = questions.length + ' question' + (questions.length > 1 ? 's' : ''); });
        const empty = qs('.questions-empty', chapter);
        if (empty) empty.hidden = questions.length !== 0;
        questions.forEach((question, questionIndex) => {
          const positionInput = qs('input[name$="[position]"]', question);
          if (positionInput) positionInput.value = String(questionIndex + 1);
          qsa('[data-question-number]', question).forEach(node => { node.textContent = number(questionIndex + 1); });
          const required = qs('input[type="checkbox"][name$="[required]"]', question);
          if (required && required.checked) requiredCount++;
        });
        questionsCount += questions.length;
        if (outline) {
          const item = doc.createElement('li');
          const link = doc.createElement('a');
          const label = doc.createElement('span');
          const ordinal = doc.createElement('span');
          link.href = '#' + chapter.id;
          link.className = 'editor-outline-link';
          ordinal.className = 'editor-outline-number';
          ordinal.textContent = number(index + 1);
          label.textContent = title;
          link.append(ordinal, label);
          item.append(link);
          links.push(item);
        }
      });
      if (outline) outline.replaceChildren(...links);
      qsa('[data-chapter-count]', doc).forEach(node => { node.textContent = String(allChapters.length); });
      qsa('[data-question-count]', doc).forEach(node => { node.textContent = String(questionsCount); });
      qsa('[data-required-count]', doc).forEach(node => { node.textContent = String(requiredCount); });
      const empty = doc.getElementById('chaptersEmpty');
      if (empty) empty.hidden = allChapters.length !== 0;
      updateStatus();
    }

    function changed() {
      state.dirty = true;
      renumber();
    }

    function initializeSortables() {
      if (typeof root.Sortable !== 'function') return;
      const attach = (container, draggable) => {
        if (state.sortables.has(container)) return;
        const existing = typeof root.Sortable.get === 'function' ? root.Sortable.get(container) : null;
        if (existing) {
          state.sortables.set(container, existing);
          return;
        }
        const instance = new root.Sortable(container, {
          handle: '.drag-handle', draggable, animation: 180,
          ghostClass: 'sortable-ghost', chosenClass: 'sortable-chosen',
          onEnd(event) { if (event.oldIndex !== event.newIndex) changed(); }
        });
        state.sortables.set(container, instance);
      };
      attach(chapters, '[data-chapter]');
      qsa('.questions', chapters).forEach(container => attach(container, '[data-question]'));
    }

    function createNode(html) {
      const holder = doc.createElement('template');
      holder.innerHTML = html.trim();
      return holder.content.firstElementChild;
    }

    function focusInput(node, selector) {
      const input = qs(selector, node);
      if (!input) return;
      input.focus({ preventScroll: true });
      input.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    function addChapter() {
      if (!chapters.dataset.prototype) return;
      const index = allocateIndex(chapters, 'chapters');
      const chapter = createNode(fillPrototype(chapters.dataset.prototype, index));
      if (!chapter) return;
      chapters.appendChild(chapter);
      questionNodes(chapter).forEach(applyQuestionUI);
      openChapter(chapter);
      initializeSortables();
      changed();
      focusInput(chapter, '.js-chapter-title, input[name$="[titre]"]');
    }

    function addQuestion(chapter) {
      const container = qs('.questions', chapter);
      if (!container || !container.dataset.questionPrototype) return;
      const index = allocateIndex(container, 'questions');
      const html = fillPrototype(container.dataset.questionPrototype, chapterIndex(chapter), index);
      const question = createNode(html);
      if (!question) return;
      container.appendChild(question);
      applyQuestionUI(question);
      openChapter(chapter);
      initializeSortables();
      changed();
      focusInput(question, 'input[name$="[libelle]"]');
    }

    function hasContent(node) {
      return qsa('input:not([type="hidden"]):not([type="checkbox"]), textarea', node)
        .some(input => input.value.trim() !== '') || qsa('.js-metrickey', node).some(input => input.value !== '');
    }

    function removeNode(node, isChapter) {
      const message = isChapter ? 'Supprimer ce chapitre et toutes ses questions ?' : 'Supprimer cette question ?';
      if (hasContent(node) && !root.confirm(message)) return;
      state.sortables.forEach((instance, container) => {
        if (node === container || node.contains(container)) {
          if (typeof instance.destroy === 'function') instance.destroy();
          state.sortables.delete(container);
        }
      });
      node.remove();
      changed();
    }

    form.addEventListener('click', event => {
      const button = event.target.closest('button');
      if (!button) return;
      if (button.id === 'btnAddChapter' || button.classList.contains('js-add-chapter')) {
        event.preventDefault();
        addChapter();
        return;
      }
      const chapter = button.closest('[data-chapter]');
      if (!chapter) return;
      if (button.classList.contains('js-add-question')) addQuestion(chapter);
      else if (button.classList.contains('js-remove-chapter')) removeNode(chapter, true);
      else if (button.classList.contains('js-remove-question')) {
        const question = button.closest('[data-question]');
        if (question) removeNode(question, false);
      } else if (button.classList.contains('js-toggle-chapter')) {
        openChapter(chapter, button.getAttribute('aria-expanded') !== 'true');
      }
    });

    function onInput(event) {
      if (!event.target.matches('input, select, textarea')) return;
      if (event.target.matches('.js-qtype, .js-metrickey')) {
        const question = event.target.closest('[data-question]');
        if (question) applyQuestionUI(question);
      }
      changed();
    }
    form.addEventListener('input', onInput);
    form.addEventListener('change', onInput);
    form.addEventListener('submit', renumber);

    const outline = doc.getElementById('chapterOutline');
    if (outline) outline.addEventListener('click', event => {
      const link = event.target.closest('a[href^="#chapter-"]');
      if (!link) return;
      const chapter = doc.getElementById(link.getAttribute('href').slice(1));
      if (chapter) openChapter(chapter);
    });

    qsa('[data-question]', chapters).forEach(applyQuestionUI);
    if (typeof root.TomSelect === 'function') qsa('.js-tomselect', form).forEach(select => {
      if (select.tomselect) return;
      try {
        new root.TomSelect(select, {
          plugins: ['remove_button'], create: false,
          placeholder: select.dataset.tsPlaceholder || 'Rechercher une formation…',
          maxOptions: null, hideSelected: true,
          onChange() { if (state.ready) changed(); }
        });
      } catch (_) {
        // The native select remains usable if the optional enhancement fails.
      }
    });
    initializeSortables();
    state.ready = true;
    renumber();
    return state;
  }

  return { boot, nextIndex, fillPrototype, applyQuestionUI };
});
