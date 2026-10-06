(() => {
  const canvas = document.getElementById('hab-signature');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  const byId = id => document.getElementById(id);
  const hidden = byId('hab-signature-data'), preview = byId('hab-signature-preview');
  const error = byId('hab-signature-error'), input = byId('hab-signature-file');
  const drop = byId('hab-signature-drop'), result = byId('hab-upload-result');
  const empty = byId('hab-upload-empty'), status = byId('hab-upload-status');
  let drawing = false, ink = false, generation = 0, dragDepth = 0;
  ctx.lineWidth = 3; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#182c40';

  function resetUpload() {
    generation++;
    input.value = '';
    preview.hidden = true;
    preview.removeAttribute('src');
    result.hidden = true;
    empty.hidden = false;
    status.textContent = '';
    drop.removeAttribute('aria-busy');
  }
  function clear() {
    resetUpload();
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ink = false; drawing = false; hidden.value = ''; error.textContent = '';
  }
  const point = e => {
    const r = canvas.getBoundingClientRect();
    return [(e.clientX - r.left) * canvas.width / r.width, (e.clientY - r.top) * canvas.height / r.height];
  };
  canvas.addEventListener('pointerdown', e => {
    if (e.button !== 0) return;
    resetUpload();
    drawing = true; canvas.setPointerCapture(e.pointerId);
    ctx.beginPath(); ctx.moveTo(...point(e)); hidden.value = ''; error.textContent = '';
  });
  canvas.addEventListener('pointermove', e => {
    if (!drawing) return;
    ctx.lineTo(...point(e)); ctx.stroke(); ink = true;
  });
  const end = () => {
    if (!drawing) return;
    drawing = false;
    if (ink) hidden.value = canvas.toDataURL('image/png');
  };
  canvas.addEventListener('pointerup', end);
  canvas.addEventListener('pointercancel', end);
  byId('hab-clear').addEventListener('click', clear);
  byId('hab-upload-remove').addEventListener('click', () => {
    clear(); status.textContent = 'Image retirée.'; byId('hab-upload-choose').focus();
  });
  ['hab-upload-choose', 'hab-upload-replace'].forEach(id => byId(id).addEventListener('click', () => input.click()));

  function importFiles(files) {
    const current = ++generation;
    error.textContent = '';
    drop.removeAttribute('aria-busy');
    if (files.length !== 1) { error.textContent = 'Déposez une seule image de signature.'; return; }
    const file = files[0];
    if (!['image/png', 'image/jpeg'].includes(file.type) || file.size > 512000 || !file.size) {
      error.textContent = 'Choisissez une image PNG ou JPEG de 500 Ko maximum.';
      input.value = ''; return;
    }
    drop.setAttribute('aria-busy', 'true');
    const fail = () => {
      if (current !== generation) return;
      drop.removeAttribute('aria-busy');
      error.textContent = 'Cette image ne peut pas être lue. Choisissez un autre fichier.';
      input.value = '';
    };
    const reader = new FileReader();
    reader.onerror = fail;
    reader.onload = () => {
      if (current !== generation) return;
      const image = new Image();
      image.onerror = fail;
      image.onload = () => {
        if (current !== generation) return;
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ink = false; drawing = false;
        hidden.value = reader.result;
        preview.src = reader.result; preview.hidden = false;
        empty.hidden = true; result.hidden = false;
        byId('hab-upload-name').textContent = file.name;
        byId('hab-upload-size').textContent = Math.max(1, Math.ceil(file.size / 1024)) + ' Ko · ' + image.naturalWidth + ' × ' + image.naturalHeight + ' px';
        drop.removeAttribute('aria-busy');
        status.textContent = 'Signature importée : ' + file.name;
        input.value = '';
      };
      image.src = reader.result;
    };
    reader.readAsDataURL(file);
  }
  input.addEventListener('change', () => { if (input.files.length) importFiles(input.files); });
  drop.addEventListener('dragenter', e => { e.preventDefault(); dragDepth++; drop.classList.add('is-dragging'); });
  drop.addEventListener('dragover', e => { e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; });
  drop.addEventListener('dragleave', e => { e.preventDefault(); if (--dragDepth <= 0) drop.classList.remove('is-dragging'); });
  drop.addEventListener('drop', e => {
    e.preventDefault(); dragDepth = 0; drop.classList.remove('is-dragging');
    importFiles(e.dataTransfer.files);
  });
  byId('hab-evaluation').addEventListener('submit', e => {
    if (e.submitter?.value !== 'sign') return;
    if (drop.getAttribute('aria-busy') === 'true' || !hidden.value || !e.currentTarget.querySelector('[name=confirm]').checked) {
      e.preventDefault();
      error.textContent = drop.getAttribute('aria-busy') === 'true'
        ? 'Patientez pendant la lecture de votre image.'
        : 'Ajoutez votre signature et confirmez la validation du document.';
      error.scrollIntoView({block: 'center', behavior: 'smooth'});
    }
  });
})();
