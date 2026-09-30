/* Shared filter presentation for listing pages. Native controls remain the source
 * of truth, including TomSelect events, dependent options and existing resets. */
(() => {
  'use strict';
  const roots = '.filterbar, .toolbar, .filters-bar, .filter-card, .filters-card, .filter-panel, .filters-panel, .filters';
  const selectFilters = `:is(${roots}) select, select[id$="Filter"], select[id^="filter"], #devisStatus, #factureStatus, #convSign, #emarSigned, #posState, #qcmPhase, #qcmState, #satisStagState, #satisFormState, #assiduiteState`;
  // Categorical filters backed by ChoiceFilter. Period type is a display mode,
  // so it keeps its select; years/months/quarters are independent multi-filters.
  const multiIds = new Set(('statusFilter sourceFilter activeFilter nextFilter enginFilter siteFilter categorieFilter sousCategorieFilter niveauFilter rolesFilter verifiedFilter lockedFilter sessionFilter formateurFilter stagiaireFilter requiredFilter phaseFilter submittedFilter publishedFilter questionnaireFilter dossierFilter sessionFormationFilter sessionStatusFilter yearFilter monthFilter quarterFilter factureStatusFilter devisStatusFilter payModeFilter reservationStatusFilter inscriptionStatusFilter qcmActiveFilter periodYear periodMonth periodQuarter tvaOnly filter-state').split(' '));
  const bridges = new Map();
  const tables = new Map();
  const panelOwners = new WeakMap();
  const listingPanels = `${roots}, .toolbarX, [data-list-filters]`;
  const clean = value => {
    const doc = new DOMParser().parseFromString(String(value ?? ''), 'text/html');
    return doc.body.textContent.trim();
  };
  const el = (tag, cls, text) => {
    const node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined) node.textContent = text;
    return node;
  };

  function resetIcon(button) {
    if (button.classList.contains('wf-reset-icon')) return;
    // Keep existing children (notably resetCount) and handlers for page scripts.
    const label = el('span', 'wf-reset-content');
    label.hidden = true; label.setAttribute('aria-hidden', 'true');
    while (button.firstChild) label.append(button.firstChild);
    const icon = el('i', 'bi bi-arrow-counterclockwise wf-reset-glyph');
    icon.setAttribute('aria-hidden', 'true');
    button.append(label, icon);
    button.classList.add('wf-reset-icon');
    button.setAttribute('aria-label', 'Réinitialiser les filtres');
    button.title = 'Réinitialiser les filtres';
  }

  function normalizeResets() {
    document.querySelectorAll(`button.resetbox, button.wf-filters-reset, button[id*="Reset"], button[id*="reset"], :is(${listingPanels}) button`).forEach(button => {
      if (/^(?:reset|réinitialiser(?: les filtres)?)(?:\s*\d+)?$/i.test(button.textContent.trim())) resetIcon(button);
    });
  }

  function nearbyPanel(state) {
    const container = state.api.table().container();
    const boundary = container.closest('.modal, .tab-pane') || document.body;
    const available = panel => panel && (!panelOwners.has(panel) || panelOwners.get(panel) === state);
    // An explicit target also supports layouts with multiple tables in one card.
    const explicit = boundary.querySelector(`[data-list-filters="${CSS.escape(state.node.id)}"]`);
    if (available(explicit)) return explicit;
    for (let current = container; current && current !== boundary; current = current.parentElement) {
      for (let previous = current.previousElementSibling; previous; previous = previous.previousElementSibling) {
        // Never borrow filters from the preceding table/card or another tab.
        if (previous.matches('table, .dt-container, .dataTables_wrapper, .tab-pane') || previous.querySelector('table')) return null;
        const panel = previous.matches(listingPanels) ? previous : previous.querySelector(listingPanels);
        if (available(panel)) return panel;
      }
    }
    return null;
  }

  function filterHost(panel) {
    let host = panel.querySelector('.filtergrid, .filters-grid, .toolbarLeft, .me-auto, [data-list-filter-controls], .wf-filter-body')
      || panel.querySelector('.filterbar__body');
    if (!host) {
      host = el('div', 'wf-list-filter-controls wf-filter-body');
      panel.append(host);
    }
    host.classList.add('wf-search-host');
    return host;
  }

  function placeSearch(state) {
    const container = state.api.table().container();
    const panel = state.bar || state.panel || nearbyPanel(state);
    if (panel) {
      state.panel = panel; panelOwners.set(panel, state);
      panel.querySelectorAll('button.wf-reset-icon').forEach(button => {
        if (button.dataset.wfSearchReset) return;
        button.dataset.wfSearchReset = 'true';
        // Legacy reset counters do not all include the global search.
        const enableForSearch = () => { if (state.api.search() && button.disabled) button.disabled = false; };
        state.api.on('search.dt.wfSearch', enableForSearch);
        new MutationObserver(enableForSearch).observe(button, {attributes:true, attributeFilter:['disabled']});
        enableForSearch();
      });
    }
    const input = state.search?.querySelector('input') || container.querySelector('.dt-search input[type="search"], .dataTables_filter input')
      || panel?.querySelector('#dt-search-slot input[type="search"]');
    if (!input) return; // Tables without a search feature keep their layout.
    const search = state.search || input.closest('.dt-search, .dataTables_filter');
    if (!search || search.closest('.d-none, [hidden]')) return; // Existing custom search, e.g. trainers/QCM.
    const custom = panel?.querySelector('#quickSearch, #qcmSearch, [data-list-search]')
      || document.querySelector(`[data-dt-search="#${CSS.escape(state.node.id)}"]`);
    if (custom) {
      if (!custom.hasAttribute('aria-label')) custom.setAttribute('aria-label', 'Rechercher dans la liste');
      search.hidden = true;
      return;
    }
    // Some older pages hide the native field with CSS rather than a class.
    for (let parent = search; parent && parent !== container; parent = parent.parentElement) {
      if (getComputedStyle(parent).display === 'none') return;
    }
    if (!state.search) {
      const icon = el('i', 'bi bi-search wf-search-glyph'); icon.setAttribute('aria-hidden', 'true');
      // Move the real input: DataTables retains its events, debounce and state.
      // Works with both DT1's wrapping label and DT2's separate label.
      search.replaceChildren(icon, input);
      search.classList.add('wf-list-search');
      input.setAttribute('aria-label', 'Rechercher dans la liste');
      if (!input.placeholder) input.placeholder = 'Rechercher…';
      state.search = search;
    }
    let host;
    if (state.bar) host = state.controls;
    else if (panel) host = filterHost(panel);
    else {
      toolbar(state); host = state.controls;
    }
    if (state.searchHost && state.searchHost !== host) state.searchHost.classList.remove('wf-search-host');
    state.searchHost = host;
    if (search.parentElement !== host) host.prepend(search);
  }

  function filterMenu(label, multiple, getOptions, getSelection, setSelection) {
    const shell = el('div', 'dropdown wf-filter');
    const trigger = el('button', 'btn wf-filter-toggle dropdown-toggle');
    trigger.type = 'button'; trigger.dataset.bsToggle = 'dropdown'; trigger.dataset.bsAutoClose = 'outside';
    trigger.setAttribute('aria-expanded', 'false');
    const title = el('span', 'wf-filter-label', label);
    const badge = el('span', 'wf-filter-count');
    trigger.append(title, badge);
    const menu = el('div', 'dropdown-menu wf-filter-menu');
    const search = el('input', 'form-control wf-filter-search');
    search.type = 'search'; search.placeholder = `Rechercher…`;
    search.setAttribute('aria-label', `Rechercher dans ${label}`);
    const tools = el('div', 'wf-filter-tools');
    const all = el('button', 'btn btn-sm', 'Tout'); all.type = 'button';
    const none = el('button', 'btn btn-sm', 'Aucun'); none.type = 'button';
    tools.append(all, none);
    tools.setAttribute('role', 'group'); tools.setAttribute('aria-label', `Sélection dans ${label}`);
    const list = el('div', 'wf-filter-options');
    const empty = el('p', 'small text-muted p-2 mb-0', 'Aucun résultat pour cette recherche.');
    menu.append(search, tools, list, empty); shell.append(trigger, menu);
    window.bootstrap?.Dropdown.getOrCreateInstance(trigger, {
      popperConfig: config => ({...config, modifiers:[...(config.modifiers || []),
        {name:'preventOverflow', options:{boundary:'viewport', rootBoundary:'viewport', padding:12, altAxis:true, tether:false}},
        {name:'flip', options:{padding:12}}
      ]})
    });
    let signature = '';
    function updateSearch() {
      const term = search.value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase();
      let count = 0;
      [...list.children].forEach(row => {
        const match = row.textContent.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase().includes(term);
        row.hidden = !match; if (match) count++;
      });
      empty.hidden = count > 0;
    }
    function sync() {
      const options = getOptions();
      const selected = getSelection();
      const next = JSON.stringify([options, selected]);
      if (signature === next) return;
      signature = next;
      const focusedValue = list.contains(document.activeElement) ? document.activeElement.value : null;
      list.replaceChildren();
      const any = selected === '*';
      const total = any ? options.filter(o => !o.disabled).length : selected.length;
      badge.textContent = any ? 'Tout' : (total === 0 ? 'Aucun' : String(total));
      all.setAttribute('aria-pressed', String(any));
      none.setAttribute('aria-pressed', String(!any && total === 0));
      trigger.setAttribute('aria-label', `${label} : ${multiple ? (any ? 'Tout' : `${total} sélectionné(s)`) : badge.textContent}`);
      trigger.classList.toggle('is-filtered', !any);
      for (const option of options) {
        const row = el('label', 'wf-filter-option');
        const input = el('input', 'form-check-input');
        input.type = 'checkbox';
        input.value = option.value;
        input.checked = !option.disabled && (any || selected.includes(option.value));
        input.disabled = !!option.disabled;
        input.addEventListener('change', () => {
          {
            const chosen = new Set(getSelection() === '*' ? options.filter(o => !o.disabled).map(o => o.value) : getSelection());
            input.checked ? chosen.add(option.value) : chosen.delete(option.value);
            setSelection([...chosen]);
          }
          sync();
        });
        row.append(input, el('span', '', option.label)); list.append(row);
      }
      updateSearch();
      if (focusedValue !== null) list.querySelector(`input[value="${CSS.escape(focusedValue)}"]`)?.focus({preventScroll:true});
    }
    all.addEventListener('click', () => { setSelection('*'); sync(); });
    none.addEventListener('click', () => { setSelection([]); sync(); });
    search.addEventListener('input', updateSearch);
    trigger.addEventListener('show.bs.dropdown', sync);
    trigger.addEventListener('shown.bs.dropdown', () => search.focus({preventScroll:true}));
    sync();
    return { shell, trigger, sync };
  }

  function bridge(select, allowedIds) {
    if (!allowedIds.has(select.id) || bridges.has(select) || !multiIds.has(select.id) || select.hasAttribute('data-wf-single') || select.closest('.modal, .wf-filter-menu')) return;
    const label = select.labels?.[0]?.textContent.trim()
      || select.closest('.filter-control, .filter-field, [class*="fcol-"]')?.querySelector('.form-label, label')?.textContent.trim()
      || ({ statusFilter:'Statut', sourceFilter:'Source', activeFilter:'Activation', nextFilter:'Relance', enginFilter:'Engin', siteFilter:'Site', categorieFilter:'Catégorie', sousCategorieFilter:'Sous-catégorie', niveauFilter:'Niveau', rolesFilter:'Rôles', verifiedFilter:'Vérification', lockedFilter:'Verrouillage', sessionFilter:'Session', formateurFilter:'Formateur', stagiaireFilter:'Stagiaire', requiredFilter:'Obligation', phaseFilter:'Phase', submittedFilter:'Réponse', publishedFilter:'Publication', dossierFilter:'Dossier', sessionFormationFilter:'Formation', sessionStatusFilter:'Statut', periodTypeFilter:'Période', yearFilter:'Année', monthFilter:'Mois', quarterFilter:'Trimestre', factureStatusFilter:'Statut', devisStatusFilter:'Statut', payModeFilter:'Mode de paiement', reservationStatusFilter:'Statut', inscriptionStatusFilter:'Statut', sessionStateFilter:'Période', contratStatusFilter:'Statut', periodYear:'Année', periodMonth:'Mois', periodQuarter:'Trimestre', tvaOnly:'TVA déductible', 'filter-state':'État' }[select.id])
      || select.getAttribute('aria-label') || select.getAttribute('data-placeholder') || 'Filtrer';
    const getOptions = () => {
      const ts = select.tomselect;
      const options = ts
        ? Object.values(ts.options).map(o => ({value:String(o[ts.settings.valueField]), label:clean(o[ts.settings.labelField]), disabled:!!o[ts.settings.disabledField]}))
        : [...select.options].map(o => ({value:o.value, label:o.textContent.trim(), disabled:o.disabled}));
      return options.filter(o => o.value !== 'all' && o.value !== '' && !o.value.startsWith('['));
    };
    const getSelection = () => {
      if (select.multiple) return [...select.selectedOptions].map(o => o.value);
      const value = select.value;
      if (!value || value === 'all') return '*';
      if (value.startsWith('[')) {
        try { const values = JSON.parse(value); return Array.isArray(values) ? values.map(String) : []; }
        catch (_) { return []; }
      }
      return [value];
    };
    const widget = filterMenu(label, true, getOptions, getSelection, selection => {
      const choices = getOptions().filter(o => !o.disabled);
      if (select.multiple) {
        const values = selection === '*' ? choices.map(o => o.value) : selection;
        if (select.tomselect) select.tomselect.setValue(values, false);
        else { [...select.options].forEach(o => o.selected = values.includes(o.value)); select.dispatchEvent(new Event('change', {bubbles:true})); }
        return;
      }
      // Keep the original select/events as transport, so AJAX, KPI requests and
      // dependent controls receive the very same selection without global hooks.
      const value = selection === '*' ? 'all' : (selection.length === 1 ? selection[0] : JSON.stringify(selection));
      const text = selection === '*' ? 'Tout' : (selection.length ? choices.filter(o => selection.includes(o.value)).map(o => o.label).join(', ') : 'Aucun');
      const ts = select.tomselect;
      if (ts) {
        if (!ts.options[value]) ts.addOption({[ts.settings.valueField]:value, [ts.settings.labelField]:text});
        ts.setValue(value, false);
        Object.keys(ts.options).filter(key => key.startsWith('[') && key !== value).forEach(key => ts.removeOption(key, true));
      } else {
        if (![...select.options].some(o => o.value === value)) select.add(new Option(text, value));
        select.value = value;
        [...select.options].filter(o => o.value.startsWith('[') && o.value !== value).forEach(o => o.remove());
        select.dispatchEvent(new Event('change', {bubbles:true}));
      }
    });
    select.classList.add('wf-filter-source');
    select.before(widget.shell);
    bridges.set(select, widget);
    const sync = () => {
      widget.shell.hidden = (select.classList.contains('d-none') || select.style.display === 'none');
      widget.trigger.disabled = select.disabled;
      widget.sync();
    };
    select.addEventListener('change', sync);
    new MutationObserver(sync).observe(select, { childList:true, subtree:true, attributes:true, attributeFilter:['disabled','class','selected','style'] });
    sync();
  }

  function normalizePanels() {
    document.querySelectorAll('.wf-filters-theme').forEach(root => {
      if (root.dataset.wfPanelReady) return;
      root.dataset.wfPanelReady = 'true';
      root.setAttribute('role', 'region'); root.setAttribute('aria-label', 'Filtres de la liste');
      const grid = root.querySelector('.filtergrid');
      const top = root.querySelector('.filterbar__top');
      if (grid && top) {
        root.classList.add('wf-filters-compact');
        // Move the existing export node, preserving its actions and listeners.
        const exportMenu = top.querySelector('#exportMenu');
        if (exportMenu) {
          const actions = el('div', 'wf-filter-exports');
          actions.append(exportMenu.closest('.dropdown'));
          const resetSlot = grid.querySelector('.fcol-actions, .fcol-reset');
          if (resetSlot) {
            resetSlot.classList.add('wf-filter-actions'); resetSlot.append(actions);
          } else grid.append(actions);
        }
        top.hidden = true;
        root.querySelector('.collapse')?.classList.add('show');
        root.querySelectorAll('.filter-control').forEach(control => {
          const label = control.querySelector('.form-label')?.textContent.trim();
          control.querySelectorAll('input:not([type="hidden"])').forEach(input => {
            if (!input.hasAttribute('aria-label')) input.setAttribute('aria-label', `${label || 'Filtre'}${input.placeholder ? ' : '+input.placeholder : ''}`);
          });
        });
      }
      root.querySelectorAll('.dropdown-toggle').forEach(trigger => {
        if (!trigger.parentElement.querySelector('.form-check-input, [id^="list"]')) return;
        window.bootstrap?.Dropdown.getOrCreateInstance(trigger, {
          popperConfig: config => ({...config, modifiers:[...(config.modifiers || []),
            {name:'preventOverflow', options:{boundary:'viewport', rootBoundary:'viewport', padding:12, altAxis:true, tether:false}},
            {name:'flip', options:{padding:12}}
          ]})
        });
      });
    });
  }

  function toolbar(state) {
    if (state.bar) return state.bar;
    const bar = state.panel || el('section', 'wf-list-filterbar');
    bar.setAttribute('aria-label', 'Filtres de la liste');
    const controls = state.panel ? filterHost(bar) : el('div', 'wf-list-filter-controls');
    const existingReset = bar.querySelector('button.wf-reset-icon');
    const reset = existingReset || el('button', 'btn btn-outline-secondary wf-filters-reset', 'Réinitialiser'); reset.type = 'button';
    resetIcon(reset);
    reset.addEventListener('click', () => {
      state.values = {}; state.api.search('');
      state.widgets.forEach(widget => widget.sync());
      if (!existingReset) state.api.draw();
    }, {capture:!!existingReset}); // Clear facets before the page's reset reloads the table.
    if (state.panel) {
      if (!existingReset) controls.append(reset);
    } else {
      bar.append(el('span', 'wf-filters-heading', 'Filtres'), controls, reset);
      state.api.table().container().before(bar);
    }
    state.bar = bar; state.controls = controls; state.reset = reset;
    return bar;
  }

  function facets(state, specs) {
    if (!specs.length) return;
    toolbar(state);
    for (const spec of specs) {
      state.specs.set(spec.key, spec);
      if (!state.widgets.has(spec.key)) {
        const widget = filterMenu(spec.label, true, () => state.specs.get(spec.key).options, () => state.values[spec.key] ?? '*', value => {
          state.values[spec.key] = value;
          state.api.draw();
        });
        state.controls.insertBefore(widget.shell, state.reset?.parentElement === state.controls ? state.reset : null);
        state.widgets.set(spec.key, widget);
      } else state.widgets.get(spec.key).sync();
    }
    placeSearch(state);
  }

  function register(api) {
    const node = api.table().node();
    // Loading a DataTables language file is asynchronous. The table can already
    // be registered in its API while its wrapper and search field do not exist.
    if (!node || !api.table().container()) return null;
    if (tables.has(node)) {
      const state = tables.get(node); placeSearch(state); return state;
    }
    const state = {api, node, values:{}, widgets:new Map(), specs:new Map(), bar:null};
    state.panel = nearbyPanel(state);
    const nativeFilters = !!state.panel?.querySelector('select, input:not([type="hidden"])');
    tables.set(node, state);
    const settings = api.settings()[0];
    if (settings.oFeatures.bServerSide) {
      facets(state, api.ajax.json()?.filters || []);
      // Every list retains a full-dataset text filter even without facet metadata.
      if (!state.panel && !state.bar && ![...document.querySelectorAll(selectFilters)].some(select => multiIds.has(select.id))) toolbar(state);
    } else {
      // Client-side lists already contain ALL rows: derive complete choices.
      const build = () => {
        const specs = [];
        api.columns().every(function(index) {
          const header = this.header()?.textContent.trim();
          if (!header || /^(#|id|n[°º]|actions?|total|montant|prix|date)/i.test(header)) return;
          const values = [...new Set(this.cache('search').toArray().map(clean))].filter(Boolean);
          if (!values.length || values.length > 100) return;
          specs.push({key:String(index),label:header,options:values.sort((a,b)=>a.localeCompare(b,'fr')).map(v=>({value:v,label:v}))});
        });
        const existingFilters = nativeFilters || [...document.querySelectorAll(selectFilters)].some(select => multiIds.has(select.id));
        if (!existingFilters) facets(state, specs.slice(0,4));
      };
      build(); api.on('xhr.dt.wfFilters', () => setTimeout(build, 0));
      window.jQuery.fn.dataTable.ext.search.push((config, data) => config.nTable !== node || Object.entries(state.values).every(([key, value]) => value === '*' || value.includes(clean(data[Number(key)]))));
    }
    placeSearch(state);
    return state;
  }

  function boot() {
    normalizeResets();
    const $ = window.jQuery;
    if (!$?.fn.dataTable) return;
    $(document).on('preXhr.dt.wfFilters', (_event, settings, data) => {
      const state = tables.get(settings.nTable);
      if (state) data.wfFilters = JSON.stringify(state.values);
    });
    $(document).on('xhr.dt.wfFilters', (_event, settings, json) => {
      if (!json?.filters) return;
      const state = register(new $.fn.dataTable.Api(settings));
      if (state) facets(state, json.filters);
    });
    let queued = false;
    const scan = () => {
      queued = false;
      normalizeResets();
      if (!document.querySelector('table.dataTable')) return;
      // IDs also occur on dashboards, calendars and learner/OF/super-admin pages.
      // Only these list endpoints accept the JSON selection transport. Unknown
      // endpoints retain their native selects, including their single-value API.
      const endpointFilters = {
        prospection: 'statusFilter sourceFilter activeFilter nextFilter',
        formation: 'categorieFilter sousCategorieFilter niveauFilter',
        utilisateur: 'rolesFilter verifiedFilter lockedFilter',
        entreprise: 'lockedFilter',
        session: 'sessionStatusFilter sessionFormationFilter dossierFilter',
        formateur: 'enginFilter siteFilter',
        'formateurs/contrats': 'statusFilter formateurFilter sessionFilter',
        devis: 'devisStatusFilter',
        facture: 'yearFilter monthFilter quarterFilter factureStatusFilter',
        paiement: 'yearFilter monthFilter quarterFilter payModeFilter',
        depense: 'periodYear periodMonth periodQuarter tvaOnly',
        reservation: 'reservationStatusFilter',
        inscription: 'inscriptionStatusFilter',
        qcm: 'qcmActiveFilter',
        'qcm/assignments': 'sessionFilter phaseFilter statusFilter',
        'positionnements/questionnaires': 'publishedFilter',
        'positionnements/assignments': 'requiredFilter submittedFilter',
        'satisfaction/templates': 'activeFilter',
        'satisfaction/assignments': 'sessionFilter requiredFilter statusFilter',
        'formateur-satisfaction/templates': 'activeFilter',
        'formateur-satisfaction/assignments': 'sessionFilter requiredFilter statusFilter',
        'elearning/course/inscriptions': 'filter-state',
      };
      const allowedIds = new Set();
      $.fn.dataTable.tables({api:true}).tables().every(function() {
        const settings = this.settings()[0];
        const ajaxUrl = typeof settings.ajax === 'string' ? settings.ajax : settings.ajax?.url || settings.sAjaxSource;
        if (typeof ajaxUrl !== 'string') return;
        let endpoint;
        try { endpoint = new URL(ajaxUrl, document.baseURI).pathname.match(/\/administrateur\/\d+\/(.+)\/ajax\/?$/)?.[1]; }
        catch (_) { return; }
        endpoint = endpoint?.replace(/^elearning\/\d+\/inscriptions$/, 'elearning/course/inscriptions');
        (endpointFilters[endpoint] || '').split(' ').filter(Boolean).forEach(id => allowedIds.add(id));
      });
      if (allowedIds.size) document.querySelectorAll(roots).forEach(root => root.classList.add('wf-filters-theme'));
      document.querySelectorAll(selectFilters).forEach(select => bridge(select, allowedIds));
      normalizePanels();
      $.fn.dataTable.tables({api:true}).tables().every(function(){ register(this); });
      bridges.forEach((widget, select) => {
        widget.shell.hidden = (select.classList.contains('d-none') || select.style.display === 'none');
        widget.trigger.disabled = select.disabled; widget.sync();
      });
    };
    $(document).on('init.dt.wfFilters', scan);
    document.addEventListener('click', e => {
      if (e.target.closest('[id*="Reset"], [id*="reset"], .resetbox')) setTimeout(() => bridges.forEach(widget => widget.sync()), 0);
    });
    new MutationObserver(records => {
      if (!queued && records.some(r => [...r.addedNodes].some(n => n.nodeType === 1 && !n.closest?.('.wf-filter, .wf-list-filterbar')))) {
        queued = true; requestAnimationFrame(scan);
      }
    }).observe(document.body, {childList:true,subtree:true});
    scan();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, {once:true}); else boot();
})();
