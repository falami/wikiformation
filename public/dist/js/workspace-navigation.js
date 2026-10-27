(function (root, factory) {
  'use strict';
  var api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root && root.document) {
    root.WikiWorkspaceNavigation = api;
    var boot = function () { api.init(root.document, root); };
    if (root.document.readyState === 'loading') root.document.addEventListener('DOMContentLoaded', boot, { once: true });
    else boot();
    root.document.addEventListener('turbo:load', boot);
  }
})(typeof window !== 'undefined' ? window : null, function () {
  'use strict';

  function normalize(value) {
    return String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();
  }

  function luminance(rgb) {
    return rgb.map(function (v) { v /= 255; return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); })
      .reduce(function (sum, value, i) { return sum + value * [0.2126, 0.7152, 0.0722][i]; }, 0);
  }

  function contrast(a, b) {
    var first = luminance(a), second = luminance(b);
    return (Math.max(first, second) + 0.05) / (Math.min(first, second) + 0.05);
  }

  function readableInk(background) {
    var light = [255, 255, 255], dark = [18, 31, 43];
    var ink = contrast(background, light) >= contrast(background, dark) ? light : dark;
    return contrast(background, ink) >= 4.5 ? ink : [0, 0, 0];
  }

  function readableAccent(background, accent) {
    if (contrast(background, accent) >= 4.5) return accent;
    var ink = readableInk(background), result = accent;
    for (var amount = 0.04; amount <= 1.04; amount += 0.04) {
      var weight = Math.min(1, amount);
      result = accent.map(function (channel, i) { return Math.round(channel * (1 - weight) + ink[i] * weight); });
      if (contrast(background, result) >= 4.5) return result;
    }
    return ink;
  }

  function init(doc, win) {
    var sidebar = doc.getElementById('workspaceSidebar');
    if (!sidebar || sidebar.dataset.navigationReady === 'true') return;
    sidebar.dataset.navigationReady = 'true';
    var tree = doc.getElementById('workspaceNavTree');
    if (!tree) return;
    var scroll = doc.getElementById('workspaceNavScroll') || sidebar;
    var search = doc.getElementById('workspaceNavSearch');
    var clear = sidebar.querySelector('[data-navigation-clear]');
    var empty = doc.getElementById('workspaceNavEmpty');
    var status = doc.getElementById('workspaceNavStatus');
    var overlay = doc.getElementById('workspaceNavOverlay');
    var pageTitle = doc.getElementById('workspacePageTitle');
    var html = doc.documentElement;
    var desktop = win.matchMedia('(min-width: 992px)');
    var storageKey = sidebar.dataset.storageKey || 'workspace-navigation';
    var returnFocus = null, drawerOpen = false, previousOverflow = null, inertElements = [];
    var originalRole = sidebar.getAttribute('role');
    var searchSnapshot = null;
    var listeners = [];

    function listen(target, event, callback, options) {
      if (!target) return;
      target.addEventListener(event, callback, options);
      listeners.push(function () { target.removeEventListener(event, callback, options); });
    }
    function read(storage, key) {
      try { return win[storage].getItem(storageKey + ':' + key); } catch (_) { return null; }
    }
    function write(storage, key, value) {
      try { win[storage].setItem(storageKey + ':' + key, value); } catch (_) { /* Storage may be blocked in private browsing. */ }
    }
    var collapsed = read('localStorage', 'collapsed') === 'true';
    var groupState = {};
    try { var savedGroups = JSON.parse(read('localStorage', 'groups') || '{}'); if (savedGroups && typeof savedGroups === 'object' && !Array.isArray(savedGroups)) groupState = savedGroups; } catch (_) { /* Use the server-rendered groups. */ }

    var groups = Array.from(tree.querySelectorAll('button[data-bs-toggle="collapse"]')).map(function (button) {
      var target = button.getAttribute('data-bs-target') || '';
      var panel = target.charAt(0) === '#' ? doc.getElementById(target.substring(1)) : null;
      if (!panel || !tree.contains(panel)) return null;
      button.setAttribute('type', 'button');
      button.setAttribute('aria-controls', panel.id);
      return { button: button, panel: panel, item: button.closest('li'), label: button.textContent.trim() };
    }).filter(Boolean);

    function setGroup(group, open, persist) {
      group.panel.classList.remove('collapsing');
      group.panel.classList.add('collapse');
      group.panel.classList.toggle('show', open);
      group.panel.style.removeProperty('height');
      group.button.classList.toggle('collapsed', !open);
      group.button.setAttribute('aria-expanded', String(open));
      if (persist) { groupState[group.panel.id] = open; write('localStorage', 'groups', JSON.stringify(groupState)); }
    }
    groups.forEach(function (group) {
      setGroup(group, Object.prototype.hasOwnProperty.call(groupState, group.panel.id) ? groupState[group.panel.id] === true : group.panel.classList.contains('show'), false);
    });

    function markCurrentPage() {
      var currentPath = win.location.pathname.replace(/\/+$/, '') || '/';
      var links = Array.from(tree.querySelectorAll('a[href]'));
      var best = null, score = -1;
      links.forEach(function (link) {
        if ((link.getAttribute('href') || '').charAt(0) === '#') return;
        var url;
        try { url = new URL(link.href, win.location.href); } catch (_) { return; }
        if (url.origin !== win.location.origin) return;
        var path = url.pathname.replace(/\/+$/, '') || '/';
        var match = path === currentPath ? 100000 + path.length : (path !== '/' && currentPath.indexOf(path + '/') === 0 ? path.length : -1);
        if (match > score) { best = link; score = match; }
      });
      if (!best) best = links.find(function (link) { return link.classList.contains('text-theme-secondaire') || link.querySelector('.text-theme-secondaire'); }) || null;
      links.forEach(function (link) { link.classList.remove('wn-current'); link.removeAttribute('aria-current'); });
      groups.forEach(function (group) { group.item.classList.remove('wn-current-group'); });
      var currentGroup = null;
      if (best) {
        best.classList.add('wn-current');
        best.setAttribute('aria-current', 'page');
        currentGroup = groups.find(function (group) { return group.panel.contains(best); }) || null;
      } else {
        currentGroup = groups.find(function (group) { return group.button.classList.contains('text-theme-secondaire'); }) || null;
      }
      if (currentGroup) { currentGroup.item.classList.add('wn-current-group'); setGroup(currentGroup, true, false); }
      if (pageTitle) {
        var label = best ? best.textContent.replace(/\s+/g, ' ').trim() : '';
        if (currentGroup) {
          var generic = /^(lister|liste|ajouter|nouveau|nouvelle|modifier|list|add|new|edit)$/i.test(label);
          pageTitle.textContent = label && !generic ? currentGroup.label + ' · ' + label : currentGroup.label;
        } else if (label) pageTitle.textContent = label;
      }
      return best;
    }
    var activeLink = markCurrentPage();

    function restoreScroll() {
      var saved = read('sessionStorage', 'scroll');
      var value = saved === null ? NaN : Number(saved);
      if (Number.isFinite(value) && value >= 0) scroll.scrollTop = value;
      else if (activeLink) scroll.scrollTop = Math.max(0, scroll.scrollTop + activeLink.getBoundingClientRect().top - scroll.getBoundingClientRect().top - scroll.clientHeight / 3);
    }
    function saveScroll() { if (!searchSnapshot) write('sessionStorage', 'scroll', String(scroll.scrollTop)); }
    listen(scroll, 'scroll', saveScroll, { passive: true });
    listen(tree, 'click', saveScroll, true);
    listen(win, 'pagehide', saveScroll);
    listen(win, 'pageshow', restoreScroll);
    win.requestAnimationFrame(restoreScroll);

    function focusables() {
      return Array.from(sidebar.querySelectorAll('a[href],button,input,select,textarea,[tabindex]')).filter(function (el) {
        return !el.disabled && el.getAttribute('tabindex') !== '-1' && !el.closest('[hidden]') && el.getClientRects().length > 0;
      });
    }
    function setBackgroundInert(open) {
      if (open) {
        inertElements = Array.from(doc.querySelectorAll('.adminTopNav, .content, .footer')).filter(function (el) { return !el.contains(sidebar) && !sidebar.contains(el); }).map(function (el) { var record = { el: el, value: el.inert }; el.inert = true; return record; });
      } else {
        inertElements.forEach(function (record) { record.el.inert = record.value; });
        inertElements = [];
      }
    }
    function updateVisibility() {
      var visible = desktop.matches ? !collapsed : drawerOpen;
      html.dataset.navigationCollapsed = String(collapsed);
      html.dataset.navigationOpen = String(drawerOpen && !desktop.matches);
      sidebar.inert = !visible;
      sidebar.setAttribute('aria-hidden', String(!visible));
      doc.querySelectorAll('[data-navigation-toggle]').forEach(function (button) {
        button.setAttribute('aria-expanded', String(visible));
        button.setAttribute('aria-label', visible ? 'Fermer le menu de navigation' : 'Ouvrir le menu de navigation');
      });
      if (overlay) overlay.hidden = !(drawerOpen && !desktop.matches);
      if (drawerOpen && !desktop.matches) {
        sidebar.setAttribute('role', 'dialog'); sidebar.setAttribute('aria-modal', 'true');
      } else {
        sidebar.removeAttribute('aria-modal');
        if (originalRole === null) sidebar.removeAttribute('role'); else sidebar.setAttribute('role', originalRole);
      }
    }
    function closeDrawer(restoreFocus) {
      if (!drawerOpen) { updateVisibility(); return; }
      drawerOpen = false;
      setBackgroundInert(false);
      if (previousOverflow !== null) { doc.body.style.overflow = previousOverflow; previousOverflow = null; }
      updateVisibility();
      if (restoreFocus && returnFocus && returnFocus.isConnected) returnFocus.focus({ preventScroll: true });
      returnFocus = null;
    }
    function openDrawer(trigger) {
      if (drawerOpen) return;
      returnFocus = trigger || doc.activeElement;
      drawerOpen = true;
      previousOverflow = doc.body.style.overflow;
      doc.body.style.overflow = 'hidden';
      updateVisibility();
      setBackgroundInert(true);
      win.requestAnimationFrame(function () {
        if (!drawerOpen) return;
        var target = sidebar.querySelector('[data-navigation-dismiss]') || search || focusables()[0];
        if (target) target.focus({ preventScroll: true });
      });
    }
    function toggleNavigation(trigger) {
      if (desktop.matches) {
        collapsed = !collapsed;
        write('localStorage', 'collapsed', String(collapsed));
        updateVisibility();
        if (!collapsed) win.requestAnimationFrame(restoreScroll);
      } else if (drawerOpen) closeDrawer(true);
      else openDrawer(trigger);
    }
    listen(doc, 'click', function (event) {
      var toggle = event.target.closest('[data-navigation-toggle]');
      if (toggle) { event.preventDefault(); toggleNavigation(toggle); return; }
      if (event.target.closest('[data-navigation-dismiss]') || (overlay && event.target === overlay)) {
        event.preventDefault();
        if (desktop.matches) { collapsed = true; write('localStorage', 'collapsed', 'true'); updateVisibility(); var opener = doc.querySelector('[data-navigation-toggle]'); if (opener) opener.focus({ preventScroll: true }); }
        else closeDrawer(true);
      }
    });
    listen(doc, 'keydown', function (event) {
      if (!drawerOpen || desktop.matches) return;
      if (event.key === 'Escape') { event.preventDefault(); closeDrawer(true); return; }
      if (event.key !== 'Tab') return;
      var items = focusables(), first = items[0], last = items[items.length - 1];
      if (!first) { event.preventDefault(); return; }
      if (event.shiftKey && (doc.activeElement === first || !sidebar.contains(doc.activeElement))) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && (doc.activeElement === last || !sidebar.contains(doc.activeElement))) { event.preventDefault(); first.focus(); }
    });
    listen(doc, 'focusin', function (event) {
      if (drawerOpen && !desktop.matches && !sidebar.contains(event.target)) { var target = focusables()[0]; if (target) target.focus(); }
    });
    function viewportChanged() { closeDrawer(true); updateVisibility(); }
    if (desktop.addEventListener) listen(desktop, 'change', viewportChanged);
    else { desktop.addListener(viewportChanged); listeners.push(function () { desktop.removeListener(viewportChanged); }); }
    updateVisibility();

    var entries = [], section = null;
    Array.from(tree.children).forEach(function (item) {
      if (item.classList.contains('wn-section-label')) { section = item; return; }
      var group = groups.find(function (candidate) { return candidate.item === item; }) || null;
      var links = Array.from(item.querySelectorAll('a[href]'));
      entries.push({ item: item, group: group, section: section, sectionLabel: section ? section.textContent.trim() : '', links: links });
    });
    function filterNavigation() {
      var query = normalize(search ? search.value : '');
      if (clear) clear.hidden = !query;
      if (!query) {
        entries.forEach(function (entry) { entry.item.hidden = false; entry.links.forEach(function (link) { var li = link.closest('li'); if (li) li.hidden = false; }); });
        tree.querySelectorAll('.wn-section-label').forEach(function (label) { label.hidden = false; });
        if (searchSnapshot) { groups.forEach(function (group) { setGroup(group, searchSnapshot[group.panel.id], false); }); searchSnapshot = null; }
        if (empty) empty.hidden = true;
        if (status) status.textContent = '';
        restoreScroll();
        return;
      }
      if (!searchSnapshot) {
        saveScroll();
        searchSnapshot = {};
        groups.forEach(function (group) { searchSnapshot[group.panel.id] = group.panel.classList.contains('show'); });
      }
      var words = query.split(' '), count = 0;
      tree.querySelectorAll('.wn-section-label').forEach(function (label) { label.hidden = true; });
      entries.forEach(function (entry) {
        var found = 0;
        entry.links.forEach(function (link) {
          var text = normalize(entry.sectionLabel + ' ' + (entry.group ? entry.group.label : '') + ' ' + link.textContent);
          var match = words.every(function (word) { return text.indexOf(word) !== -1; });
          var li = link.closest('li');
          if (li && li !== entry.item) li.hidden = !match;
          if (match) { found++; count++; }
        });
        entry.item.hidden = found === 0;
        if (found && entry.section) entry.section.hidden = false;
        if (entry.group) setGroup(entry.group, found > 0, false);
      });
      if (empty) empty.hidden = count !== 0;
      if (status) status.textContent = count + (count === 1 ? ' accès trouvé' : ' accès trouvés');
      scroll.scrollTop = 0;
    }
    listen(search, 'input', filterNavigation);
    listen(clear, 'click', function () { if (search) { search.value = ''; filterNavigation(); search.focus(); } });
    // Bootstrap delegates collapse clicks on document in capture phase. Intercept
    // our own groups on window first, so a click never toggles the panel twice.
    listen(win, 'click', function (event) {
      var button = event.target.closest('button[data-bs-toggle="collapse"]');
      if (button && tree.contains(button)) {
        var group = groups.find(function (candidate) { return candidate.button === button; });
        if (!group) return;
        event.preventDefault(); event.stopPropagation();
        setGroup(group, !group.panel.classList.contains('show'), !searchSnapshot);
      }
    }, true);
    listen(tree, 'click', function (event) {
      var link = event.target.closest('a[href]');
      if (link && drawerOpen && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey && link.target !== '_blank') closeDrawer(false);
    });

    function resolveColor(value, fallback) {
      var probe = doc.createElement('span');
      probe.style.color = value;
      probe.style.display = 'none';
      sidebar.appendChild(probe);
      var resolved = win.getComputedStyle(probe).color;
      probe.remove();
      var match = resolved.match(/^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)/);
      return match ? [Number(match[1]), Number(match[2]), Number(match[3])] : fallback;
    }
    function applyContrast() {
      var style = win.getComputedStyle(sidebar);
      var primary = resolveColor(style.getPropertyValue('--theme-color-principal').trim() || '#233342', [35, 51, 66]);
      var secondary = resolveColor(style.getPropertyValue('--theme-color-secondaire').trim() || '#ffc107', [255, 193, 7]);
      var ink = readableInk(primary), accentInk = readableAccent(primary, secondary);
      var mutedAlpha = 0.72;
      while (mutedAlpha < 1 && contrast(primary, ink.map(function (channel, i) { return channel * mutedAlpha + primary[i] * (1 - mutedAlpha); })) < 4.5) mutedAlpha = Math.min(1, mutedAlpha + 0.04);
      sidebar.style.setProperty('--wn-ink', 'rgb(' + ink.join(',') + ')');
      sidebar.style.setProperty('--wn-muted', 'rgba(' + ink.join(',') + ',' + mutedAlpha.toFixed(2) + ')');
      sidebar.style.setProperty('--wn-accent-ink', 'rgb(' + accentInk.join(',') + ')');
      sidebar.style.setProperty('--wn-line', 'rgba(' + ink.join(',') + ',.13)');
      sidebar.style.setProperty('--wn-hover', 'rgba(' + ink.join(',') + ',.065)');
    }
    applyContrast();
    listen(doc, 'theme:change', applyContrast);
    listen(doc, 'turbo:before-cache', function () {
      closeDrawer(false);
      listeners.forEach(function (remove) { remove(); });
      delete sidebar.dataset.navigationReady;
    });
    return { applyContrast: applyContrast, filter: filterNavigation, close: closeDrawer };
  }

  return { init: init, normalize: normalize, contrast: contrast, readableInk: readableInk, readableAccent: readableAccent };
});
