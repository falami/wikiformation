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
      if (hero.querySelector('[class*="eyebrow"], [class*="kicker"], .entity-hero-label')) return;
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
  function update() {
    completeBadges();
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
