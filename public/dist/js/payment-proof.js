(() => {
  'use strict';
  const root = document.querySelector('[data-payment-proof]');
  if (!root) return;
  const get = name => root.querySelector('[data-proof-' + name + ']');
  const input = get('input'), remove = get('remove-control').querySelector('input');
  const drop = get('drop'), status = get('status');
  let objectUrl = null, depth = 0;
  const allowed = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'];
  const mime = file => file.type || ({pdf:'application/pdf', jpg:'image/jpeg', jpeg:'image/jpeg', png:'image/png', webp:'image/webp', gif:'image/gif'}[file.name.split('.').pop().toLowerCase()] || '');
  function notify(message = '', error = false) {
    status.textContent = message;
    status.classList.toggle('payment-proof-status-error', error);
  }
  function render() {
    if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
    const file = input.files?.[0];
    const existing = !remove.checked && root.dataset.currentName;
    const name = file?.name || (existing ? root.dataset.currentName : '');
    const url = file ? (objectUrl = URL.createObjectURL(file)) : (existing ? root.dataset.currentUrl : '');
    get('empty').hidden = !!name;
    get('preview').hidden = !name;
    get('remove').hidden = !name;
    get('pick-label').textContent = name ? 'Remplacer' : 'Choisir un fichier';
    get('media').replaceChildren();
    get('name').textContent = name;
    get('size').textContent = file ? (file.size / 1000000).toLocaleString('fr-FR', {maximumFractionDigits:2}) + ' Mo' : 'Justificatif enregistré';
    get('link').removeAttribute('href');
    if (!url) return;
    get('link').href = url;
    const type = file ? mime(file) : mime({type:'', name});
    if (type === 'application/pdf') {
      const frame = document.createElement('iframe');
      frame.title = 'Aperçu du justificatif PDF';
      frame.src = url;
      get('media').append(frame);
    } else if (allowed.includes(type)) {
      const image = document.createElement('img');
      image.alt = 'Aperçu du justificatif';
      image.src = url;
      get('media').append(image);
    } else get('media').textContent = 'Aperçu indisponible';
  }
  function validate(file) {
    if (!allowed.includes(mime(file))) return 'Choisissez un PDF, JPG, PNG, WEBP ou GIF.';
    if (file.size > 10000000) return 'Le fichier dépasse la limite de 10 Mo.';
    if (!file.size) return 'Ce fichier est vide.';
    return '';
  }
  get('empty').addEventListener('click', () => input.click());
  get('pick').addEventListener('click', () => input.click());
  input.addEventListener('change', () => {
    const file = input.files?.[0];
    const error = file ? validate(file) : '';
    if (error) input.value = '';
    else if (file) remove.checked = false;
    notify(error, !!error);
    render();
  });
  get('remove').addEventListener('click', () => {
    input.value = '';
    remove.checked = !!root.dataset.currentName;
    input.dispatchEvent(new Event('change', {bubbles:true}));
    notify(remove.checked ? 'Le justificatif sera supprimé du paiement à l’enregistrement.' : 'Le fichier a été retiré.');
    get('pick').focus();
  });
  ['dragenter', 'dragover'].forEach(type => drop.addEventListener(type, event => {
    event.preventDefault();
    if (type === 'dragenter') depth++;
    drop.classList.add('is-dragover');
  }));
  drop.addEventListener('dragleave', event => {
    event.preventDefault();
    if (--depth <= 0) drop.classList.remove('is-dragover');
  });
  drop.addEventListener('drop', event => {
    event.preventDefault();
    depth = 0;
    drop.classList.remove('is-dragover');
    const files = event.dataTransfer?.files;
    if (!files?.length) return;
    if (files.length !== 1) { notify('Déposez un seul justificatif à la fois.', true); return; }
    const error = validate(files[0]);
    if (error) { notify(error, true); return; }
    try {
      const transfer = new DataTransfer();
      transfer.items.add(files[0]);
      input.files = transfer.files;
      input.dispatchEvent(new Event('change', {bubbles:true}));
    } catch (_) { notify('Utilisez le bouton « Choisir un fichier » pour ajouter ce justificatif.', true); }
  });
  input.form?.addEventListener('reset', () => setTimeout(() => {
    notify(); render(); input.dispatchEvent(new Event('change', {bubbles:true}));
  }, 0));
  root.classList.add('is-enhanced');
  render();
})();
