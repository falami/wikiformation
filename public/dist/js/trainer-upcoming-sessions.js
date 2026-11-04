(() => {
  const dateFormat = options => new Intl.DateTimeFormat('fr-FR', {timeZone: 'Europe/Paris', ...options});
  const element = (tag, className, text) => {
    const node = document.createElement(tag);
    node.className = className;
    if (text != null) node.textContent = text;
    return node;
  };
  const meta = (icon, text) => {
    const node = element('span', 'trainer-upcoming-meta');
    const glyph = element('i', `bi bi-${icon}`);
    glyph.setAttribute('aria-hidden', 'true');
    node.append(glyph, document.createTextNode(text));
    return node;
  };
  window.renderTrainerUpcomingSessions = (list, rows, limit = 6, options = {}) => {
    if (!list) return;
    const now = new Date();
    const upcoming = rows.map(row => ({row, start: new Date(row.firstStartIso || ''), end: new Date(row.lastEndIso || '')}))
      .filter(item => !Number.isNaN(item.start.getTime()) && (item.start > now || (options.includeOngoing && item.end > now)))
      .sort((a, b) => a.start - b.start);
    list.replaceChildren();
    list.setAttribute('aria-busy', 'false');
    const count = document.getElementById('upcoming-count');
    if (count) count.textContent = `${upcoming.length} session${upcoming.length > 1 ? 's' : ''} ${options.includeOngoing ? 'à suivre' : 'à venir'}`;
    if (!upcoming.length) {
      const empty = element('li', 'trainer-upcoming-empty');
      const icon = element('i', 'bi bi-calendar2-check');
      icon.setAttribute('aria-hidden', 'true');
      const copy = element('div', '');
      copy.append(element('strong', '', 'Votre agenda est à jour'), element('p', '', options.includeOngoing ? 'Aucune session en cours ou à venir pour le moment.' : 'Aucune prochaine session planifiée pour le moment.'));
      empty.append(icon, copy);
      list.append(empty);
      return;
    }
    upcoming.slice(0, limit).forEach(({row, start, end}, index) => {
      const isLive = start <= now && end > now;
      const card = element('li', `trainer-upcoming-card${index === 0 ? ' is-next' : ''}`);
      const date = element('time', 'trainer-upcoming-date');
      date.dateTime = start.toISOString();
      date.setAttribute('aria-label', dateFormat({dateStyle: 'full'}).format(start));
      date.append(element('span', '', dateFormat({month: 'short'}).format(start)), element('strong', '', dateFormat({day: '2-digit'}).format(start)), element('small', '', dateFormat({year: 'numeric'}).format(start)));
      const body = element('div', 'trainer-upcoming-body');
      const top = element('div', 'trainer-upcoming-top');
      if (index === 0) top.append(element('span', 'trainer-upcoming-next', isLive ? 'Session en cours' : 'Prochain rendez-vous'));
      if (row.code) top.append(element('span', 'trainer-upcoming-code', row.code));
      body.append(top, element('h3', '', row.formationTitle || 'Formation'));
      const details = element('div', 'trainer-upcoming-details');
      details.append(meta('clock', `${dateFormat({weekday: 'long'}).format(start)} à ${dateFormat({hour: '2-digit', minute: '2-digit'}).format(start)}`));
      if (row.organisationName) details.append(meta('building', row.organisationName));
      if (row.siteName) details.append(meta('geo-alt', row.siteName));
      if (row.trainerName) details.append(meta('person', row.trainerName));
      if (options.showTrainees !== false) {
        const trainees = Math.max(0, parseInt(row.inscrits, 10) || 0);
        details.append(meta('people', `${trainees} stagiaire${trainees > 1 ? 's' : ''}`));
      }
      body.append(details);
      const actions = element('div', 'trainer-upcoming-actions');
      if (isLive || row.statusLabel) actions.append(element('span', 'trainer-upcoming-status', isLive ? 'En cours' : row.statusLabel));
      if (row.sessionUrl) {
        const url = new URL(row.sessionUrl, window.location.origin);
        if (url.origin === window.location.origin && ['http:', 'https:'].includes(url.protocol)) {
          const link = element('a', 'trainer-upcoming-link', 'Voir la session');
          link.href = url.href;
          link.setAttribute('aria-label', `Voir la session ${row.formationTitle || row.code || ''}`);
          const arrow = element('i', 'bi bi-arrow-up-right'); arrow.setAttribute('aria-hidden', 'true');
          link.append(arrow); actions.append(link);
        }
      }
      card.append(date, body, actions);
      list.append(card);
    });
  };
})();
