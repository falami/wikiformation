(() => {
  function init() {
    const root = document.querySelector('[data-company-contact]');
    if (!root) return;
    const form = root.closest('form');
    const field = name => root.querySelector(`[name="entreprise[newContact][${name}]"]`);
    const email = field('email');
    const reuse = field('reuse');
    const status = root.querySelector('[data-contact-status]');
    const reusePanel = root.querySelector('[data-contact-reuse]');
    const representative = document.getElementById('entreprise_representant');
    const users = document.querySelector('[data-company-users]');
    const mode = () => root.querySelector('[name="entreprise[contactMode]"]:checked')?.value || 'later';
    let match = null;
    let original = null;
    let sequence = 0;
    let timer;
    let abort;
    const names = ['civilite', 'prenom', 'nom', 'telephone'];

    if (users && !users.tomselect && typeof TomSelect !== 'undefined') {
      new TomSelect(users, {
        plugins: ['remove_button'], maxItems: null, maxOptions: 2000,
        placeholder: 'Rechercher un salarié ou un stagiaire…', closeAfterSelect: true,
      });
    }

    function summary() {
      const label = mode() === 'later' ? 'À compléter plus tard'
        : mode() === 'existing' ? (representative?.selectedOptions[0]?.textContent || 'À sélectionner')
          : [field('prenom').value.trim(), field('nom').value.trim()].filter(Boolean).join(' ') || email.value.trim() || 'Nouveau contact';
      document.getElementById('resume-contact').textContent = label;
      document.getElementById('resume-users').textContent = String(users?.selectedOptions.length || 0);
    }

    function restore() {
      if (original) names.forEach(name => { field(name).value = original[name]; });
      original = null;
      names.forEach(name => { field(name).disabled = false; });
    }

    function applyMatch() {
      if (match && reuse.checked) {
        original ??= Object.fromEntries(names.map(name => [name, field(name).value]));
        names.forEach(name => {
          field(name).value = match[name] || '';
          field(name).disabled = true;
        });
      } else restore();
      summary();
      form.dispatchEvent(new Event('change', {bubbles: true}));
    }

    async function lookup() {
      if (mode() !== 'new') return;
      const current = ++sequence;
      abort?.abort();
      if (!email.value.trim() || !email.validity.valid) {
        status.textContent = 'Saisissez une adresse e-mail valide pour rechercher un compte.';
        status.dataset.state = '';
        return;
      }
      abort = new AbortController();
      status.textContent = 'Recherche du compte…';
      status.dataset.state = '';
      try {
        const url = new URL(root.dataset.lookupUrl, window.location.origin);
        url.searchParams.set('email', email.value.trim());
        const response = await fetch(url, {signal: abort.signal, cache: 'no-store', headers: {'Accept': 'application/json'}});
        if (!response.ok) throw new Error('lookup');
        const data = await response.json();
        if (current !== sequence) return;
        match = data.found ? data.contact : null;
        reusePanel.hidden = !match;
        status.dataset.state = match ? 'found' : '';
        status.textContent = match
          ? `${[match.prenom, match.nom].filter(Boolean).join(' ')} possède déjà un compte. Confirmez ci-dessous pour reprendre ses coordonnées, sans créer de doublon.`
          : 'Complétez son prénom et son nom. Son compte sera créé lors de l’enregistrement.';
        if (match && reuse.checked) applyMatch();
      } catch (error) {
        if (error.name === 'AbortError' || current !== sequence) return;
        status.dataset.state = 'error';
        status.textContent = 'La recherche est momentanément indisponible. Réessayez en quittant le champ e-mail. Une vérification sera aussi effectuée à l’enregistrement.';
      }
    }

    function updateMode() {
      ++sequence;
      abort?.abort();
      clearTimeout(timer);
      root.querySelectorAll('[data-contact-panel]').forEach(panel => {
        const active = panel.dataset.contactPanel === mode();
        panel.hidden = !active;
        if (panel.tagName === 'FIELDSET') panel.disabled = !active;
      });
      summary();
      if (mode() === 'new' && email.value.trim()) lookup();
    }
    root.querySelectorAll('[name="entreprise[contactMode]"]').forEach(input => input.addEventListener('change', updateMode));
    email.addEventListener('input', () => {
      ++sequence;
      abort?.abort();
      clearTimeout(timer);
      restore();
      match = null;
      reuse.checked = false;
      reusePanel.hidden = true;
      status.dataset.state = '';
      status.textContent = 'Saisissez son e-mail : nous vérifions d’abord si son compte existe déjà.';
      timer = setTimeout(lookup, 350);
    });
    email.addEventListener('blur', () => { clearTimeout(timer); lookup(); });
    reuse.addEventListener('change', applyMatch);
    form.addEventListener('input', summary);
    form.addEventListener('change', summary);
    document.querySelectorAll('.se-nav a').forEach(link => link.addEventListener('click', () => {
      const target = document.querySelector(link.getAttribute('href'));
      if (target?.tagName === 'DETAILS') target.open = true;
    }));
    reusePanel.hidden = !reuse.checked;
    updateMode();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
