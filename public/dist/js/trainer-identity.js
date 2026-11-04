document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-trainer-identity]');
  if (!root) return;
  const field = name => root.querySelector(`[name$="[${name}]"]`);
  const email = field('email');
  const reuse = field('reuse');
  const status = root.querySelector('[data-identity-status]');
  let found = null;
  let sequence = 0;
  let timer;
  let original = null;
  const apply = values => Object.entries(values).forEach(([name, value]) => {
    const input = field(name);
    if (!input) return;
    if (input.tomselect) input.tomselect.setValue(value ?? '', true);
    else if (input._flatpickr) input._flatpickr.setDate(value || null, false, 'Y-m-d');
    else input.value = value ?? '';
  });
  const restore = () => {
    if (original) apply(original);
    original = null;
    root.querySelectorAll('input, select').forEach(input => { input.disabled = false;
      input.tomselect?.enable();
      if (input._flatpickr?.altInput) input._flatpickr.altInput.disabled = false; });
  };
  const fill = () => {
    if (!reuse.checked || !found) { restore(); return; }
    original ??= Object.fromEntries(Object.keys(found).map(name => [name, field(name)?.value ?? '']));
    apply(found);
    Object.keys(found).forEach(name => {
      const input = field(name);
      if (!input) return;
      input.disabled = true;
      input.tomselect?.disable();
      if (input._flatpickr?.altInput) input._flatpickr.altInput.disabled = true;
    });
  };
  reuse.addEventListener('change', fill);
  async function lookup() {
    const current = ++sequence;
    if (!email.value.trim() || !email.validity.valid) return;
    status.textContent = 'Recherche en cours…';
    try {
      const url = new URL(root.dataset.lookupUrl, window.location.origin);
      url.searchParams.set('email', email.value.trim());
      const response = await fetch(url, {headers: {'Accept': 'application/json'}, cache: 'no-store'});
      if (!response.ok) throw new Error();
      const data = await response.json();
      if (current !== sequence) return;
      found = data.found ? data.fields : null;
      status.textContent = found
        ? 'Cet utilisateur existe. Cochez la case pour reprendre ses informations. Les coordonnées du compte existant sont conservées.'
        : 'Complétez les coordonnées ci-dessous. Le compte utilisateur sera créé à l’enregistrement.';
      if (reuse.checked && found) fill();
    } catch {
      if (current === sequence) status.textContent = 'Recherche indisponible. Vous pouvez réessayer en quittant le champ e-mail ; une vérification sera également faite à l’enregistrement.';
    }
  }
  email.addEventListener('input', () => {
    ++sequence;
    found = null;
    reuse.checked = false;
    restore();
    clearTimeout(timer);
    timer = setTimeout(lookup, 350);
  });
  email.addEventListener('blur', () => { clearTimeout(timer); lookup(); });
  if (email.value) lookup();
});
