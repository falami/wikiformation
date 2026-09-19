(() => {
  'use strict';

  const frenchCalendar = {
    weekdays: { shorthand: ['dim', 'lun', 'mar', 'mer', 'jeu', 'ven', 'sam'], longhand: ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'] },
    months: { shorthand: ['janv', 'févr', 'mars', 'avr', 'mai', 'juin', 'juil', 'août', 'sept', 'oct', 'nov', 'déc'], longhand: ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'] },
    firstDayOfWeek: 1, rangeSeparator: ' au ', weekAbbreviation: 'Sem', scrollTitle: 'Défiler pour augmenter la valeur', toggleTitle: 'Cliquer pour basculer', time_24hr: true,
  };
  const titles = { session: 'Nouvelle session', site: 'Nouveau site de formation', client: 'Nouveau client' };
  const icons = { session: 'calendar2-plus', site: 'geo-alt', client: 'person-plus' };
  const messages = { session: 'La session a été créée et sélectionnée.', site: 'Le site a été créé et sélectionné.', client: 'Le client a été ajouté aux participants.' };
  const make = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  };
  const readJSON = value => { try { return JSON.parse(value || '{}'); } catch { return {}; } };

  function boot() {
    const root = document.getElementById('convention-workspace');
    if (!root || root.dataset.initialized) return;
    root.dataset.initialized = '1';
    const form = document.getElementById('cv-convention-form');
    const modalElement = document.getElementById('cv-create-modal');
    const modalContent = document.getElementById('cv-modal-content');
    const back = document.getElementById('cv-modal-back');
    const modal = window.bootstrap?.Modal.getOrCreateInstance(modalElement);
    const metadata = new WeakMap();
    const sessionSelect = root.querySelector('select[data-entity="session"]');
    const formationSelect = root.querySelector('select[data-entity="formation"]');
    const participantsSelect = root.querySelector('select[data-entity="client"]');
    let defaultTitle = root.dataset.catalogueTitle || '';
    let defaultDuration = root.dataset.catalogueDuration || '';
    const individual = root.dataset.individual === '1';
    const newSession = root.dataset.createSession === '1';
    const stack = [];
    let current = null;
    let loading = null;
    let saving = false;
    let noticeTimeout;

    function collectMetadata(select) {
      if (!metadata.has(select)) metadata.set(select, new Map());
      const options = metadata.get(select);
      Array.from(select.options).forEach(option => {
        if (!options.has(option.value)) options.set(option.value, { label: option.textContent, ...readJSON(option.dataset.session || option.dataset.client || option.dataset.formation) });
      });
      return options;
    }

    function initControls(container) {
      container.querySelectorAll('select.js-convention-select').forEach(select => {
        const options = collectMetadata(select);
        if (select.tomselect || !window.TomSelect) return;
        const config = {
          create: false, maxOptions: 100, hideSelected: select.multiple,
          placeholder: select.dataset.placeholder || select.querySelector('option[value=""]')?.textContent || 'Rechercher…',
          plugins: select.multiple ? ['remove_button'] : [],
          render: {
            no_results: () => '<div class="no-results">Aucun résultat. Essayez un autre terme.</div>',
            option: (data, escape) => {
              const info = options.get(String(data.value)) || {};
              if (select.dataset.entity === 'session') {
                return `<div><span class="cv-option-heading"><span class="cv-option-code">${escape(info.code || '')}</span>${escape(info.title || data.text)}</span><span class="cv-option-subtitle">${escape([info.startLabel, info.site].filter(Boolean).join(' · '))}</span></div>`;
              }
              if (select.dataset.entity === 'client' && info.firstName) {
                return `<div><span class="cv-option-heading">${escape(`${info.firstName} ${info.lastName || ''}`)}</span><span class="cv-option-subtitle">${escape([info.email, info.company].filter(Boolean).join(' · '))}</span></div>`;
              }
              return `<div>${escape(data.text)}</div>`;
            },
          },
        };
        new window.TomSelect(select, config);
      });
      container.querySelectorAll('input[data-datepicker]').forEach(input => {
        if (input._flatpickr || !window.flatpickr) return;
        const calendar = window.flatpickr(input, {
          enableTime: true, time_24hr: true, dateFormat: 'Y-m-d\\TH:i', altInput: true,
          altFormat: 'd/m/Y à H:i', allowInput: true, disableMobile: true,
          locale: frenchCalendar, minuteIncrement: 15,
          // Keep calendar controls inside Bootstrap's accessible focus trap.
          appendTo: input.closest('.modal') || document.body,
          position: (instance) => {
            const anchor = instance._positionElement.getBoundingClientRect();
            const popup = instance.calendarContainer;
            const parent = popup.parentElement;
            const inModal = parent === modalElement;
            const width = popup.offsetWidth || 308;
            const left = Math.max(8, Math.min(anchor.left, window.innerWidth - width - 8));
            const above = anchor.bottom + popup.offsetHeight + 8 > window.innerHeight && anchor.top > popup.offsetHeight;
            popup.style.position = inModal ? 'fixed' : 'absolute';
            popup.style.left = `${left + (inModal ? 0 : window.scrollX)}px`;
            popup.style.top = `${(above ? anchor.top - popup.offsetHeight - 5 : anchor.bottom + 5) + (inModal ? 0 : window.scrollY)}px`;
            popup.classList.toggle('arrowBottom', above);
            popup.classList.toggle('arrowTop', !above);
          },
          onReady: (_dates, _value, instance) => {
            instance.calendarContainer.classList.add('cv-calendar');
            if (instance.altInput) {
              instance.altInput.placeholder = 'Choisir une date et une heure';
              // Symfony labels target the original hidden field: retarget them to the visible input.
              instance.altInput.id = `${input.id}_calendar`;
              const label = container.querySelector(`label[for="${input.id}"]`);
              if (label) label.htmlFor = instance.altInput.id;
            }
          },
        });
        calendar.altInput?.addEventListener('change', update);
      });
    }

    function destroyControls(container) {
      container.querySelectorAll('select').forEach(select => select.tomselect?.destroy());
      container.querySelectorAll('input[data-datepicker]').forEach(input => input._flatpickr?.destroy());
    }

    function syncTrainingDefaults() {
      const select = sessionSelect || formationSelect;
      const info = select ? collectMetadata(select).get(select.value) : null;
      if (!select?.value || !info) return;
      const title = root.querySelector('[data-document-title]');
      const duration = root.querySelector('[data-document-duration]');
      if (title && (!title.value.trim() || title.value === defaultTitle)) title.value = info.title || defaultTitle;
      if (duration && (!duration.value.trim() || duration.value === defaultDuration)) duration.value = info.duration || '';
      defaultTitle = info.title || defaultTitle;
      defaultDuration = info.duration || '';
    }

    function update() {
      const session = sessionSelect ? collectMetadata(sessionSelect).get(sessionSelect.value) : null;
      const slots = Array.from(root.querySelectorAll('[data-slot]'));
      const sessionChosen = newSession
        ? Boolean(root.querySelector('select[data-entity="formation"]')?.value && root.querySelector('select[data-entity="site"]')?.value && slots.length && slots.every(slot => {
          const start = slot.querySelector('input[name$="[dateDebut]"]')?.value;
          const end = slot.querySelector('input[name$="[dateFin]"]')?.value;
          return start && end && end > start;
        }))
        : Boolean(sessionSelect?.value);
      const selected = participantsSelect ? Array.from(participantsSelect.selectedOptions).filter(option => option.value) : [];
      const freeNames = (root.querySelector('[data-free-participants]')?.value || '').split(/\r\n|[\n\r\u0085\u2028\u2029]/u).map(name => name.trim()).filter(Boolean);
      const knownCount = individual ? 1 : selected.length + freeNames.length;
      const plannedValue = root.querySelector('[data-planned-count]')?.value || '';
      const count = individual ? 1 : (plannedValue === '' ? knownCount : Number(plannedValue));
      const inconsistentCount = !Number.isInteger(count) || count < knownCount || count < 0;
      root.querySelectorAll('[data-participant-count]').forEach(el => { el.textContent = count; });
      document.getElementById('cv-summary-participants').textContent = count ? `${count} stagiaire${count > 1 ? 's' : ''}` : 'À sélectionner';
      document.getElementById('cv-summary-session').textContent = newSession ? 'Nouvelle session' : (sessionChosen ? session?.code || session?.label : 'À sélectionner');
      const title = root.querySelector('[data-document-title]')?.value.trim();
      const duration = root.querySelector('[data-document-duration]')?.value.trim();
      document.getElementById('cv-summary-training-title').textContent = title || session?.title || 'Intitulé du catalogue';
      document.getElementById('cv-summary-training-duration').textContent = duration || session?.duration || defaultDuration || 'Durée du catalogue';
      const rosterStatus = document.getElementById('cv-roster-status');
      if (rosterStatus) {
        const unnamed = Math.max(0, count - knownCount);
        rosterStatus.textContent = inconsistentCount ? `L’effectif doit inclure les ${knownCount} participants déjà renseignés.`
          : `${selected.length} client${selected.length > 1 ? 's' : ''} sélectionné${selected.length > 1 ? 's' : ''} · ${freeNames.length} nom${freeNames.length > 1 ? 's' : ''} libre${freeNames.length > 1 ? 's' : ''} · ${unnamed} nom${unnamed > 1 ? 's' : ''} à préciser.`;
        rosterStatus.classList.toggle('text-danger', inconsistentCount);
      }
      if (sessionSelect) {
        document.getElementById('cv-session-detail').hidden = !sessionChosen;
        document.getElementById('cv-session-empty').hidden = sessionChosen;
        if (sessionChosen) {
          document.getElementById('cv-session-code').textContent = session?.code || '';
          document.getElementById('cv-session-title-value').textContent = session?.title || session?.label || '';
          document.getElementById('cv-session-dates').textContent = [session?.startLabel, session?.endLabel].filter(Boolean).join('\n→ ') || 'Dates à compléter';
          document.getElementById('cv-session-site').textContent = session?.site || 'Lieu à compléter';
          const remaining = session?.remaining;
          document.getElementById('cv-session-capacity').textContent = remaining !== undefined ? `${remaining} place${remaining > 1 ? 's' : ''} disponible${remaining > 1 ? 's' : ''}\n${session.capacity} places au total` : '—';
        }
      }
      const list = document.getElementById('cv-participant-list');
      if (list) {
        list.replaceChildren();
        selected.forEach(option => {
          const info = collectMetadata(participantsSelect).get(option.value) || {};
          const name = [info.firstName, info.lastName].filter(Boolean).join(' ') || option.textContent;
          const row = make('div', 'cv-person-row');
          row.append(make('span', 'cv-avatar', `${info.firstName?.slice(0, 1) || ''}${info.lastName?.slice(0, 1) || ''}` || name.slice(0, 2)));
          const person = make('span', 'cv-person-info');
          person.append(make('strong', '', name), make('span', '', info.email || ''));
          row.append(person);
          if (session?.enrolled?.includes(option.value)) row.append(make('span', 'cv-person-badge', 'Déjà inscrit'));
          const remove = make('button', 'cv-person-remove');
          remove.type = 'button';
          remove.setAttribute('aria-label', `Retirer ${name}`);
          remove.append(make('i', 'bi bi-x'));
          remove.addEventListener('click', () => {
            if (participantsSelect.tomselect) participantsSelect.tomselect.removeItem(option.value);
            else { option.selected = false; participantsSelect.dispatchEvent(new Event('change', { bubbles: true })); }
            participantsSelect.tomselect?.focus();
          });
          row.append(remove);
          list.append(row);
        });
        document.getElementById('cv-participant-empty').hidden = count > 0;
      }
      const alreadyEnrolled = individual ? (session?.enrolled?.includes(root.dataset.individualId) ? 1 : 0) : selected.filter(option => session?.enrolled?.includes(option.value)).length;
      const newCount = Math.max(0, count - alreadyEnrolled);
      const capacity = newSession ? Number(root.querySelector('input[name$="[capacite]"]')?.value) : session?.remaining;
      const exceeds = capacity !== undefined && sessionChosen && newCount > capacity;
      const warning = document.getElementById('cv-capacity-warning');
      warning.hidden = !exceeds;
      warning.textContent = `La session ne dispose pas d’assez de places pour cet effectif (${newCount} place${newCount > 1 ? 's' : ''} en plus des inscriptions existantes). Modifiez l’effectif ou la capacité de la session.`;
      const ready = sessionChosen && count > 0 && !exceeds && !inconsistentCount;
      const readiness = document.getElementById('cv-readiness');
      readiness.classList.toggle('is-ready', ready);
      readiness.querySelector('.bi').className = `bi ${ready ? 'bi-check-circle-fill' : 'bi-circle'}`;
      readiness.querySelector('span').textContent = inconsistentCount ? 'Vérifiez le nombre total de stagiaires.' : (exceeds ? 'Vérifiez le nombre de places disponibles.' : (ready ? 'Votre convention est prête à être créée.' : (!sessionChosen ? 'Complétez la session de formation.' : 'Ajoutez des participants ou un effectif prévu.')));
      root.querySelector('[data-step="session"]').classList.toggle('is-complete', sessionChosen);
      root.querySelector('[data-step="participants"]').classList.toggle('is-active', sessionChosen);
      root.querySelector('[data-step="participants"]').classList.toggle('is-complete', count > 0);
      root.querySelector('[data-step="ready"]').classList.toggle('is-active', ready);
    }

    function notify(message, error = false) {
      const notice = document.getElementById('cv-notification');
      clearTimeout(noticeTimeout);
      notice.replaceChildren(make('i', `bi ${error ? 'bi-exclamation-circle' : 'bi-check-circle'}`), document.createTextNode(message));
      notice.classList.toggle('is-error', error);
      notice.hidden = false;
      noticeTimeout = setTimeout(() => { notice.hidden = true; }, 8000);
    }

    function setModalTitle(kind) {
      document.getElementById('cv-modal-title').textContent = titles[kind] || 'Ajouter';
      modalElement.querySelector('.cv-modal-icon .bi').className = `bi bi-${icons[kind] || 'plus-lg'}`;
      back.hidden = stack.length === 0;
    }

    function showModalError(message) {
      modalContent.querySelector('.cv-modal-error')?.remove();
      const error = make('div', 'cv-modal-error', message);
      error.setAttribute('role', 'alert');
      modalContent.prepend(error);
      modalElement.querySelector('.modal-body').scrollTop = 0;
    }

    async function request(url, options = {}) {
      const response = await fetch(url, {
        credentials: 'same-origin', ...options,
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...options.headers },
      });
      if (!response.headers.get('Content-Type')?.includes('application/json')) throw new Error('La page n’est plus disponible. Vérifiez votre connexion puis réessayez.');
      const data = await response.json();
      if (!response.ok && !(response.status === 422 && data.html)) throw new Error(data.message || 'L’enregistrement a échoué. Veuillez réessayer.');
      return { response, data };
    }

    function focusForm() {
      const usableInput = 'input:not([type="hidden"]):not(:disabled)';
      const first = modalContent.querySelector(`.is-invalid ${usableInput}, ${usableInput}.is-invalid, textarea.is-invalid`)
        || modalContent.querySelector(`.ts-control input:not(:disabled), ${usableInput}, textarea:not(:disabled)`);
      first?.focus({ preventScroll: true });
    }

    async function openCreate(trigger) {
      if (!modal || saving) { if (!modal) notify('La fenêtre n’a pas pu s’ouvrir. Rechargez la page puis réessayez.', true); return; }
      loading?.abort();
      // Retain the actual form nodes (and widget state) while creating its site.
      if (modalElement.classList.contains('show') && current) {
        modalContent.querySelectorAll('input[data-datepicker]').forEach(input => input._flatpickr?.close());
        const fragment = document.createDocumentFragment();
        fragment.append(...modalContent.childNodes);
        stack.push({ current, fragment });
      } else {
        destroyControls(modalContent);
        modalContent.replaceChildren();
      }
      current = { kind: trigger.dataset.quickCreate, url: trigger.dataset.url };
      setModalTitle(current.kind);
      const spinner = make('div', 'cv-loading');
      spinner.append(make('span', 'spinner-border spinner-border-sm'), make('span', '', 'Préparation du formulaire…'));
      modalContent.replaceChildren(spinner);
      modal.show();
      const controller = new AbortController();
      loading = controller;
      try {
        const { data } = await request(current.url, { signal: controller.signal });
        if (controller.signal.aborted) return;
        modalContent.innerHTML = data.html;
        initControls(modalContent);
        focusForm();
      } catch (error) {
        if (error.name === 'AbortError') return;
        modalContent.replaceChildren();
        showModalError(error.message);
        const retry = make('button', 'cv-button cv-button-soft', 'Réessayer');
        retry.type = 'button';
        retry.addEventListener('click', () => {
          current = null;
          openCreate(trigger);
        });
        modalContent.append(retry);
      }
    }

    function restoreParent() {
      if (saving || !stack.length) return;
      loading?.abort();
      destroyControls(modalContent);
      const parent = stack.pop();
      current = parent.current;
      modalContent.replaceChildren(parent.fragment);
      setModalTitle(current.kind);
      focusForm();
    }

    function addOption(select, data, multiple = false) {
      const id = String(data.id);
      collectMetadata(select).set(id, data);
      if (select.tomselect) {
        const ts = select.tomselect;
        const option = { value: id, text: data.label };
        if (ts.options[id]) ts.updateOption(id, option);
        else ts.addOption(option);
        if (multiple) ts.addItem(id);
        else ts.setValue(id);
      } else {
        let option = Array.from(select.options).find(item => item.value === id);
        if (!option) { option = new Option(data.label, id); select.add(option); }
        if (!multiple) select.value = id;
        option.selected = true;
        select.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }

    async function save(event) {
      const quickForm = event.target.closest('.js-quick-create-form');
      if (!quickForm) return;
      event.preventDefault();
      if (saving) return;
      saving = true;
      const submit = quickForm.querySelector('button[type="submit"]');
      const original = submit.innerHTML;
      submit.replaceChildren(make('span', 'spinner-border spinner-border-sm'), document.createTextNode(' Enregistrement…'));
      submit.disabled = true;
      quickForm.setAttribute('aria-busy', 'true');
      try {
        const { response, data } = await request(quickForm.action, { method: 'POST', body: new FormData(quickForm) });
        if (response.status === 422) {
          destroyControls(modalContent);
          modalContent.innerHTML = data.html;
          initControls(modalContent);
          showModalError(data.message || 'Vérifiez les champs indiqués ci-dessous.');
          focusForm();
          return;
        }
        if (!data.success) throw new Error(data.message || 'L’enregistrement a échoué.');
        saving = false;
        if (data.kind === 'site' && stack.length) restoreParent();
        if (data.kind === 'site') {
          document.querySelectorAll('#convention-workspace select[data-entity="site"], #cv-modal-content select[data-entity="site"], #cv-modal-content select[data-quick-target="site"]').forEach(select => addOption(select, data));
          if (current?.kind === 'site') modal.hide();
        } else if (data.kind === 'session' && sessionSelect) {
          addOption(sessionSelect, data);
          modal.hide();
        } else if (data.kind === 'client' && participantsSelect) {
          addOption(participantsSelect, data, true);
          modal.hide();
        }
        update();
        notify(messages[data.kind] || 'Enregistrement effectué.');
      } catch (error) { showModalError(error.message); }
      finally {
        saving = false;
        submit.disabled = false;
        submit.innerHTML = original;
        quickForm.removeAttribute('aria-busy');
      }
    }

    function actions(event) {
      const create = event.target.closest('[data-quick-create]');
      if (create) { event.preventDefault(); openCreate(create); return; }
      if (event.target.closest('[data-quick-cancel]')) { if (!saving) { if (stack.length) restoreParent(); else modal?.hide(); } return; }
      const add = event.target.closest('[data-add-slot]');
      if (add) {
        const collection = add.closest('form').querySelector('[data-collection]');
        const index = Number(collection.dataset.index || 0);
        collection.dataset.index = index + 1;
        const template = document.createElement('template');
        template.innerHTML = collection.dataset.prototype.replace(/__name__/g, index);
        if (!template.content.querySelector('[data-slot], .js-session-slot')) {
          const slot = make('div', 'cv-slot');
          slot.dataset.slot = '';
          slot.append(template.content);
          const remove = make('button', 'cv-slot-remove');
          remove.type = 'button'; remove.dataset.removeSlot = ''; remove.setAttribute('aria-label', 'Retirer ce créneau');
          remove.append(make('i', 'bi bi-trash3')); slot.append(remove);
          collection.append(slot);
        } else collection.append(template.content);
        initControls(collection);
        update();
        collection.lastElementChild.querySelector('input:not([type="hidden"])')?.focus();
        return;
      }
      const remove = event.target.closest('[data-remove-slot]');
      if (remove) {
        const slot = remove.closest('[data-slot], .js-session-slot');
        destroyControls(slot);
        slot.remove();
        update();
      }
    }

    root.addEventListener('click', actions);
    root.addEventListener('change', event => {
      if (event.target === sessionSelect || event.target === formationSelect) syncTrainingDefaults();
      update();
    });
    root.addEventListener('input', event => {
      if (event.target.matches('[data-free-participants], [data-planned-count], [data-document-title], [data-document-duration]')) update();
    });
    modalElement.addEventListener('click', actions);
    modalElement.addEventListener('submit', save);
    back.addEventListener('click', restoreParent);
    modalElement.addEventListener('hide.bs.modal', event => { if (saving) event.preventDefault(); });
    modalElement.addEventListener('shown.bs.modal', focusForm);
    modalElement.addEventListener('hidden.bs.modal', () => {
      loading?.abort();
      destroyControls(modalContent);
      stack.forEach(entry => destroyControls(entry.fragment));
      stack.length = 0;
      modalContent.replaceChildren();
      current = null;
      back.hidden = true;
    });
    modalElement.querySelector('.modal-body').addEventListener('scroll', () => {
      modalContent.querySelectorAll('input[data-datepicker]').forEach(input => input._flatpickr?.close());
    }, { passive: true });
    form.addEventListener('submit', () => {
      const submit = document.getElementById('cv-submit');
      submit.disabled = true;
      submit.setAttribute('aria-busy', 'true');
    });
    initControls(root);
    syncTrainingDefaults();
    update();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
  document.addEventListener('turbo:load', boot);
})();
