/* Resolve both foregrounds from the actual tenant colours, including live previews. */
(function () {
  function luminance(rgb) {
    return rgb.map(v => { v /= 255; return v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4; })
      .reduce((sum, v, i) => sum + v * [.2126, .7152, .0722][i], 0);
  }
  function ink(rgb) { return luminance(rgb) > .179 ? '#000000' : '#ffffff'; }
  // Complete legacy headers without duplicating their existing section labels.
  function completeBadges() {
    const sections = {
      qualite: ['Suivi qualité', 'bi-shield-check'],
      bpf: ['Déclaration annuelle', 'bi-bar-chart'],
      qcm: ['Questionnaires de niveau', 'bi-ui-checks-grid'],
      formation: ['Catalogue de formation', 'bi-mortarboard-fill'],
      session: ['Gestion des sessions', 'bi-calendar3'],
      inscription: ['Inscriptions', 'bi-person-check'],
      facture: ['Facturation', 'bi-receipt'],
      devis: ['Devis & propositions', 'bi-file-earmark-text'],
      convention: ['Conventions de formation', 'bi-file-earmark-check'],
      conventions: ['Conventions de formation', 'bi-file-earmark-check'],
      formateur: ['Espace formateur', 'bi-person-workspace'],
      utilisateur: ['Relations & contacts', 'bi-people'],
      entreprise: ['Entreprises partenaires', 'bi-buildings'],
      finance: ['Analyse financière', 'bi-graph-up-arrow'],
      paiement: ['Suivi des paiements', 'bi-credit-card'],
      depense: ['Gestion des dépenses', 'bi-wallet2'],
      avoir: ['Gestion des avoirs', 'bi-arrow-counterclockwise'],
      positionnements: ['Questionnaires de positionnement', 'bi-ui-checks'],
      satisfaction: ['Qualité & satisfaction', 'bi-chat-square-heart'],
      caces: ['Certification CACES®', 'bi-shield-check'],
      entite: ['Votre organisme de formation', 'bi-building'],
      planning: ['Organisation & planning', 'bi-calendar-week'],
      site: ['Lieux de formation', 'bi-geo-alt'],
      prospect: ['Développement commercial', 'bi-person-plus'],
      dashboard: ['Pilotage de votre activité', 'bi-speedometer2']
    };
    const route = location.pathname.split('/').filter(Boolean);
    const section = [...route].reverse().find(part => sections[part]);
    document.querySelectorAll('.entity-hero').forEach(hero => {
      const heroTitle = hero.querySelector('h1, h2, .hero-title');
      if (heroTitle) {
        let titleIcon = heroTitle.querySelector('i, svg, img');
        if (!titleIcon) {
          const badgeIcon = hero.querySelector('[class*="eyebrow"] i, [class*="kicker"] i, .entity-hero-label i');
          titleIcon = document.createElement('i');
          titleIcon.className = badgeIcon ? badgeIcon.className : 'bi ' + (sections[section]?.[1] || 'bi-grid');
          titleIcon.setAttribute('aria-hidden', 'true');
          heroTitle.prepend(titleIcon, document.createTextNode(' '));
        }
        titleIcon.classList.add('entity-hero-title-icon');
      }
      const existing = hero.querySelector('[class*="eyebrow"], [class*="kicker"], .entity-hero-label');
      if (existing) {
        if (!existing.querySelector('i, svg, img, .label')) {
          const icon = document.createElement('i');
          icon.className = 'bi ' + (sections[section]?.[1] || 'bi-grid');
          icon.setAttribute('aria-hidden', 'true');
          existing.prepend(icon, document.createTextNode(' '));
        }
        return;
      }
      const title = hero.querySelector('h1, h2, .hero-title');
      if (!title) return;
      const label = document.createElement('div');
      label.className = 'entity-hero-label';
      const icon = document.createElement('i');
      icon.className = 'bi ' + (sections[section]?.[1] || 'bi-grid');
      icon.setAttribute('aria-hidden', 'true');
      label.append(icon, document.createTextNode(hero.dataset.heroLabel || sections[section]?.[0] || title.textContent.trim()));
      // A wrapper keeps the badge above the heading even in flex/grid headers.
      const heading = document.createElement('div');
      heading.className = 'entity-hero-heading';
      title.before(heading);
      heading.append(label, title);
    });
  }
  // Move existing nodes (never clone controls): listeners, values, dropdowns and
  // form ownership must survive the shared layout, including Turbo revisits.
  function arrangeActions() {
    const actionSelector = 'a.btn, button.btn, input.btn, .rp-button, .hab-btn, .pp-btn, .pp-btn-hero, .crm-primary, .crm-button, .mk-button, .wf-button, .ev-btn, .ev-btn-primary, .btn-hero, .wf-accent-button, .iw-btn, .studio-btn, .cv-button, .of-hero__refresh, .curriculum-add, .cx-btn, .btn-prime, .btn-outline, .rec-button, .sub-btn, .pf-button';
    document.querySelectorAll('.entity-hero:not(.entity-hero-arranged)').forEach(hero => {
      const title = hero.querySelector('h1, h2, .hero-title');
      if (!title) return;
      const actions = [...hero.querySelectorAll(actionSelector)].filter(action =>
        !action.closest('.dropdown-menu, .modal, .offcanvas') &&
        !action.matches('.btn-close, .rounded-circle') && action.closest('.entity-hero') === hero);
      const side = document.createElement('div');
      side.className = 'entity-hero-side';
      const emblem = document.createElement('div');
      emblem.className = 'entity-hero-emblem';
      emblem.setAttribute('aria-hidden', 'true');
      const existingIcon = hero.querySelector('.se-hero-icon');
      const sourceIcon = existingIcon?.querySelector('i, svg') || title.querySelector('i, svg');
      if (sourceIcon) {
        const icon = sourceIcon.cloneNode(true);
        icon.removeAttribute('id');
        icon.removeAttribute('style');
        icon.classList.remove('entity-hero-title-icon');
        icon.querySelectorAll('[id]').forEach(node => node.removeAttribute('id'));
        emblem.append(icon);
      }
      if (existingIcon) existingIcon.remove();
      side.append(emblem);
      const controls = document.createElement('div');
      controls.className = 'entity-hero-controls';
      const roots = new Set();
      actions.forEach(action => {
        let root = action;
        // Keep each complete action group, form and dropdown together. Stop
        // before wrappers containing headings, metadata or unrelated content.
        while (root.parentElement && root.parentElement !== hero) {
          const parent = root.parentElement;
          if (parent.contains(title) || parent.matches('.entity-hero')) break;
          const copy = parent.cloneNode(true);
          copy.querySelectorAll(actionSelector + ', .dropdown-menu, input[type="hidden"], .se-hero-caption').forEach(node => node.remove());
          if (copy.textContent.trim() || copy.querySelector('input, select, textarea, img, svg')) break;
          root = parent;
        }
        roots.add(root);
      });
      [...roots].filter(root => ![...roots].some(other => other !== root && other.contains(root))).forEach(root => {
        root.querySelectorAll('button, input, select, textarea').forEach(control => {
          if (control.form && !root.contains(control.form) && !control.hasAttribute('form')) {
            if (!control.form.id) control.form.id = 'entity-hero-form-' + (++arrangeActions.formId);
            control.setAttribute('form', control.form.id);
          }
        });
        // A standalone submit button can also belong to an enclosing form.
        if (root.form && !root.hasAttribute('form')) {
          if (!root.form.id) root.form.id = 'entity-hero-form-' + (++arrangeActions.formId);
          root.setAttribute('form', root.form.id);
        }
        root.classList.add('entity-hero-action-group');
        controls.append(root);
      });
      side.append(controls);
      const caption = hero.querySelector('.se-hero-caption') || controls.querySelector('.se-hero-caption');
      if (caption) side.append(caption);
      const main = document.createElement('div');
      main.className = 'entity-hero-content';
      while (hero.firstChild) main.append(hero.firstChild);
      hero.append(main, side);
      hero.classList.add('entity-hero-arranged');
    });
  }
  arrangeActions.formId = 0;

  function update() {
    completeBadges();
    arrangeActions();
    const probe = document.createElement('span');
    probe.style.cssText = 'position:absolute;visibility:hidden';
    document.body.append(probe);
    function resolve(variable, fallback) {
      probe.style.color = 'var(' + variable + ',' + fallback + ')';
      const values = getComputedStyle(probe).color.match(/[\d.]+/g);
      return values ? values.slice(0, 3).map(Number) : [35, 51, 66];
    }
    const primary = resolve('--theme-color-principal', '#233342');
    const secondary = resolve('--theme-color-secondaire', '#ffc107');
    probe.remove();
    const style = document.documentElement.style;
    style.setProperty('--wf-hero-ink', ink(primary));
    style.setProperty('--wf-accent-ink', ink(secondary));
    const a = luminance(primary), b = luminance(secondary);
    const contrast = (Math.max(a, b) + .05) / (Math.min(a, b) + .05);
    style.setProperty('--wf-hero-accent', contrast >= 4.5 ? 'var(--theme-color-secondaire,#ffc107)' : ink(primary));
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', update);
  else update();
  document.addEventListener('theme:change', update);
  document.addEventListener('turbo:load', update);
})();
