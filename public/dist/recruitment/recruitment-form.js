document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-upload]').forEach(zone => {
    const input = zone.querySelector('input'), status = zone.querySelector('[role=status]');
    const preview = zone.querySelector('img'), remove = zone.querySelector('button');
    let url;
    function render() {
      if (url) URL.revokeObjectURL(url);
      preview.hidden = true; preview.removeAttribute('src');
      const file = input.files[0];
      remove.hidden = !file;
      zone.classList.toggle('has-file', !!file);
      status.textContent = file ? `${file.name} · ${(file.size / 1048576).toFixed(2)} Mo` : 'Aucun fichier sélectionné';
      if (file && input.name === 'photo') { url = URL.createObjectURL(file); preview.src = url; preview.hidden = false; }
    }
    function validate() {
      const file = input.files[0];
      const types = input.accept.split(',');
      if (file && (!types.includes(file.type) || file.size > Number(zone.dataset.maxSize) * 1048576)) {
        input.value = ''; render();
        status.textContent = `Fichier refusé : choisissez ${input.accept === 'application/pdf' ? 'un PDF' : 'une image JPG ou PNG'} de ${zone.dataset.maxSize} Mo maximum.`;
        return;
      }
      render();
    }
    input.addEventListener('change', validate);
    remove.addEventListener('click', () => { input.value = ''; render(); input.focus(); });
    ['dragenter', 'dragover'].forEach(type => zone.addEventListener(type, e => { e.preventDefault(); zone.classList.add('is-dragging'); }));
    zone.addEventListener('dragleave', e => { if (!zone.contains(e.relatedTarget)) zone.classList.remove('is-dragging'); });
    zone.addEventListener('drop', e => {
      e.preventDefault(); zone.classList.remove('is-dragging');
      if (e.dataTransfer.files.length !== 1) { status.textContent = 'Déposez un seul fichier à la fois.'; return; }
      input.files = e.dataTransfer.files; validate();
    });
  });
  const nda = document.getElementById('rec-has-nda');
  const qualiopi = document.getElementById('rec-qualiopi-status');
  function toggleFields(selector, active) {
    document.querySelectorAll(selector).forEach(section => {
      section.hidden = !active;
      section.querySelectorAll('input').forEach(input => { input.disabled = !active; });
    });
  }
  function updateReferences() {
    toggleFields('[data-nda-fields]', !!nda?.checked);
    const number = document.querySelector('[name=nda]');
    if (number) number.required = !!nda?.checked;
    toggleFields('[data-qualiopi-fields]', qualiopi?.value === 'yes');
  }
  nda?.addEventListener('change', updateReferences);
  qualiopi?.addEventListener('change', updateReferences);
  updateReferences();
  const address = document.getElementById('rec-address');
  if (!address) return;
  const results = document.getElementById('rec-address-results'), status = document.getElementById('rec-address-status');
  const canvas = document.getElementById('rec-address-map');
  const postal = document.querySelector('[name=codePostal]'), city = document.querySelector('[name=ville]');
  let timer, controller, generation = 0, map, marker;
  function clear() { results.replaceChildren(); results.hidden = true; }
  function invalidate() {
    generation++; clearTimeout(timer); controller?.abort(); clear(); canvas.hidden = true;
    status.textContent = 'Sélectionnez une proposition pour localiser cette adresse.';
  }
  function choose(feature) {
    invalidate();
    const p = feature.properties, [lon, lat] = feature.geometry.coordinates;
    address.value = p.name || p.label; postal.value = p.postcode || ''; city.value = p.city || '';
    status.textContent = `Adresse sélectionnée : ${p.label}. Vérifiez sa localisation sur la carte.`;
    if (window.L) {
      canvas.hidden = false;
      if (!map) {
        map = L.map(canvas, {scrollWheelZoom:false});
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
          maxZoom:19,
          referrerPolicy:'strict-origin',
          attribution:'© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);
      }
      map.invalidateSize(); map.setView([lat, lon], 16);
      if (marker) marker.remove();
      marker = L.circleMarker([lat, lon], {radius:9, color:'#fff', weight:3, fillColor:'#b85734', fillOpacity:1}).addTo(map);
      const label = document.createElement('span'); label.textContent = p.label; marker.bindPopup(label).openPopup();
    }
    address.focus();
  }
  address.addEventListener('input', () => {
    invalidate();
    const q = address.value.trim(), request = generation;
    if (q.length < 4) return;
    timer = setTimeout(async () => {
      controller = new AbortController();
      const activeController = controller;
      const timeout = setTimeout(() => activeController.abort(), 8000);
      status.textContent = 'Recherche d’adresses…';
      try {
        const response = await fetch('https://data.geopf.fr/geocodage/search?' + new URLSearchParams({q, limit:6, index:'address'}), {signal:activeController.signal});
        if (!response.ok) throw new Error('Service indisponible');
        const data = await response.json();
        if (request !== generation) return;
        const features = (data.features || []).filter(f => f.properties?.label && f.geometry?.type === 'Point');
        clear();
        features.forEach(feature => {
          const button = document.createElement('button'); button.type = 'button'; button.textContent = feature.properties.label;
          button.addEventListener('click', () => choose(feature)); results.append(button);
        });
        results.hidden = !features.length;
        status.textContent = features.length ? 'Choisissez votre adresse dans les propositions.' : 'Aucune adresse trouvée. Précisez votre saisie ou renseignez les champs manuellement.';
      } catch (error) {
        if (request === generation) status.textContent = 'Recherche indisponible. Vous pouvez renseigner votre adresse manuellement.';
      } finally { clearTimeout(timeout); }
    }, 350);
  });
  [postal, city].forEach(input => input.addEventListener('input', invalidate));
  address.addEventListener('keydown', e => { if (e.key === 'ArrowDown' && !results.hidden) { e.preventDefault(); results.firstElementChild?.focus(); } if (e.key === 'Escape') clear(); });
  results.addEventListener('keydown', e => {
    if (e.key === 'Escape') { clear(); address.focus(); }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); (e.key === 'ArrowDown' ? e.target.nextElementSibling : e.target.previousElementSibling)?.focus(); }
  });
});
