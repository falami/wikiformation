/* Shared row actions for every DataTable, including Ajax draws and future pages. */
(() => {
  'use strict';
  const actionCell = '.wf-table-actions';
  let active = null;

  function labelFor(button) {
    const explicit = button.getAttribute('aria-label') || button.getAttribute('data-bs-title') || button.title || button.textContent.trim();
    if (explicit) return explicit;
    const icon = button.querySelector('i')?.className || '';
    for (const [pattern, label] of [
      [/eye/, 'Consulter'], [/pencil|edit/, 'Modifier'], [/trash/, 'Supprimer'],
      [/pdf|download/, 'Télécharger le PDF'], [/envelope|send/, 'Envoyer'],
      [/check/, 'Valider'], [/plus/, 'Ajouter'], [/calendar/, 'Planning'], [/archive/, 'Archiver'],
    ]) if (pattern.test(icon)) return label;
    return 'Ouvrir';
  }

  function menuItem(button) {
    const label = labelFor(button);
    const danger = button.matches('.btn-danger, .btn-outline-danger, .text-danger');
    // Move existing nodes, never clone: CSRF inputs, listeners and row context survive.
    const form = button.closest('form');
    const item = document.createElement('li');
    const content = form && button.closest(actionCell)?.contains(form) ? form : button;
    if (button.getAttribute('data-bs-toggle') === 'tooltip') {
      window.bootstrap?.Tooltip.getInstance(button)?.dispose();
      button.removeAttribute('data-bs-toggle');
    }
    [...button.classList].filter(c => c === 'btn' || c.startsWith('btn-')).forEach(c => button.classList.remove(c));
    button.classList.add('dropdown-item');
    if (danger) button.classList.add('text-danger');
    if (!button.textContent.trim()) button.append(document.createTextNode(` ${label}`));
    item.append(content);
    return item;
  }

  function compact(cell) {
    if (cell.tagName === 'TH' || cell.dataset.wfCompact) return;
    const buttons = [...cell.querySelectorAll('a.btn, button.btn, input[type="submit"]')]
      .filter(b => !b.closest('.dropdown-menu'));
    const existing = cell.querySelector('[data-bs-toggle="dropdown"]');
    if (existing) {
      const group = existing.closest('.btn-group, .dropdown') || existing.parentElement;
      group.classList.add('wf-row-actions');
      existing.setAttribute('aria-label', existing.getAttribute('aria-label') || 'Actions');
      const menu = group.querySelector('.dropdown-menu');
      if (menu) {
        const extras = buttons.filter(b => b !== existing);
        // Keep the primary action; secondary actions belong to the same menu.
        extras.slice(1).reverse().forEach(button => menu.prepend(menuItem(button)));
      }
    } else if (buttons.length) {
      const group = document.createElement('div');
      group.className = 'btn-group btn-group-sm wf-row-actions';
      group.setAttribute('role', 'group');
      group.setAttribute('aria-label', 'Actions');
      const primary = buttons.find(b => b.querySelector('.bi-eye, .bi-eye-fill, .fa-eye')) || buttons[0];
      const parent = primary.closest('form') || primary;
      // Insert before moving a form so submit buttons retain their form association.
      parent.parentElement.insertBefore(group, parent);
      group.append(parent);
      primary.setAttribute('aria-label', labelFor(primary));
      if (buttons.length > 1) {
        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'btn btn-light dropdown-toggle dropdown-toggle-split';
        toggle.dataset.bsToggle = 'dropdown';
        toggle.setAttribute('aria-label', 'Actions');
        toggle.setAttribute('aria-expanded', 'false');
        const menu = document.createElement('ul');
        menu.className = 'dropdown-menu dropdown-menu-end';
        buttons.filter(b => b !== primary).forEach(b => menu.append(menuItem(b)));
        group.append(toggle, menu);
      }
    }
    cell.querySelectorAll('.dropdown-menu').forEach(menu => menu.classList.add('wf-action-menu'));
    cell.dataset.wfCompact = 'true';
  }

  function enhance(table) {
    const headers = [...(table.tHead?.rows[table.tHead.rows.length - 1]?.cells || [])];
    const indices = headers.flatMap((th, index) => /^(actions?|opérations?)$/i.test(th.textContent.trim()) || th.matches('.action-column, .actions-column, [data-table-actions]') ? [index] : []);
    indices.forEach(index => {
      for (const row of table.rows) {
        const cell = row.cells[index];
        if (cell && cell.colSpan === 1) cell.classList.add('wf-table-actions');
      }
    });
    table.querySelectorAll('td.action-column, th.action-column, td.actions-column, th.actions-column, [data-table-actions]').forEach(cell => cell.classList.add('wf-table-actions'));
    const cells = table.querySelectorAll(actionCell);
    if (!cells.length) return;
    cells.forEach(compact);
    // DataTables scrollX already supplies a scroll container. Other tables get a
    // wrapper around the table only, leaving search and pagination accessible.
    if (table.tBodies.length && !table.closest('.dt-scroll-body, .dt-scroll-head, .dataTables_scrollBody, .dataTables_scrollHead, .table-responsive, .wf-table-scroll')) {
      const wrapper = document.createElement('div');
      wrapper.className = 'wf-table-scroll';
      table.before(wrapper);
      wrapper.append(table);
    }
  }

  function positionMenu(toggle, menu) {
    const rect = toggle.getBoundingClientRect();
    const box = menu.getBoundingClientRect();
    const left = Math.max(12, Math.min(rect.right - box.width, innerWidth - box.width - 12));
    const below = rect.bottom + 6;
    const top = below + box.height <= innerHeight - 12 ? below : Math.max(12, rect.top - box.height - 6);
    menu.style.setProperty('--wf-menu-left', `${left}px`);
    menu.style.setProperty('--wf-menu-top', `${top}px`);
  }

  function close() {
    if (!active) return;
    window.bootstrap?.Dropdown.getInstance(active.toggle)?.hide();
    // A draw can remove the toggle before Bootstrap gets the hide event.
    cleanup();
  }
  function cleanup() {
    if (!active) return;
    const { menu } = active;
    if (menu.matches(':popover-open')) menu.hidePopover();
    menu.classList.remove('wf-menu-open');
    active = null;
  }

  document.addEventListener('show.bs.dropdown', event => {
    const toggle = event.target;
    if (!toggle.closest?.(actionCell)) return;
    if (active && active.toggle !== toggle) close();
  }, true);
  document.addEventListener('shown.bs.dropdown', event => {
    const toggle = event.target;
    if (!toggle.closest?.(actionCell)) return;
    const menu = toggle.closest('.btn-group, .dropdown')?.querySelector('.dropdown-menu');
    if (!menu) return;
    active = { toggle, menu };
    menu.classList.add('wf-action-menu', 'wf-menu-open');
    // The native top layer escapes scrolling/overflow without moving the menu
    // out of its row. Delegated handlers and keyboard navigation keep working.
    if (typeof menu.showPopover === 'function') {
      menu.setAttribute('popover', 'manual');
      menu.showPopover();
    }
    positionMenu(toggle, menu);
  }, true);
  document.addEventListener('hidden.bs.dropdown', event => {
    if (active?.toggle === event.target) cleanup();
  }, true);
  window.addEventListener('resize', close);
  window.addEventListener('scroll', event => {
    if (active && !active.menu.contains(event.target)) close();
  }, true);

  function boot() {
    let queued = false;
    const scan = () => {
      queued = false;
      if (active && !active.toggle.isConnected) cleanup();
      document.querySelectorAll('table.dataTable').forEach(enhance);
    };
    new MutationObserver(records => {
      if (queued || !records.some(r => [...r.addedNodes].some(n => n.nodeType === 1))) return;
      queued = true;
      requestAnimationFrame(scan);
    }).observe(document.body, { childList: true, subtree: true });
    scan();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
  else boot();
})();
