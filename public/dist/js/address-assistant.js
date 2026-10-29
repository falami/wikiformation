(() => {
  'use strict';
  document.addEventListener('wiki:enable-google-maps', () => {
  const boot = document.querySelector('[data-address-assistant-key]');
  if (!boot) return;
  let loading;
  function googleReady() {
    if (window.google?.maps?.importLibrary) return Promise.resolve();
    if (loading) return loading;
    loading = new Promise((resolve, reject) => {
      const timeout = setTimeout(() => { clearInterval(poll); reject(new Error('Google unavailable')); }, 15000);
      const poll = setInterval(() => {
        if (window.google?.maps?.importLibrary) { clearInterval(poll); clearTimeout(timeout); resolve(); }
      }, 100);
      if (document.querySelector('script[src*="maps.googleapis.com/maps/api/js"]')) return;
      if (!boot.dataset.addressAssistantKey) { clearInterval(poll); clearTimeout(timeout); reject(new Error('Missing key')); return; }
      const script = document.createElement('script');
      window.initAddressAssistant = () => {};
      script.src = 'https://maps.googleapis.com/maps/api/js?' + new URLSearchParams({key: boot.dataset.addressAssistantKey, loading: 'async', callback: 'initAddressAssistant', v: 'weekly', language: 'fr', region: 'FR'});
      script.async = true;
      script.referrerPolicy = 'strict-origin';
      script.onerror = () => { clearInterval(poll); clearTimeout(timeout); reject(new Error('Network')); };
      document.head.append(script);
    });
    loading = loading.catch(error => { loading = undefined; throw error; });
    return loading;
  }
  function enhance(address) {
    if (address.dataset.addressEnhanced || address.closest('[data-client-address]') || address.readOnly || address.disabled) return;
    const form = address.form;
    if (!form) return;
    const prefix = address.name.replace(/\[adresse\]$/, '');
    const field = name => Array.from(form.elements).find(el => el.name === `${prefix}[${name}]`);
    const postal = field('codePostal'), city = field('ville');
    if (!postal || !city) return;
    address.dataset.addressEnhanced = '1';
    if (address.dataset.googleInline !== undefined) {
      const status = document.createElement('p');
      status.className = 'small text-muted mt-2';
      status.setAttribute('role', 'status');
      address.parentElement.append(status);
      status.textContent = 'Chargement des suggestions Google…';
      googleReady().then(async () => {
        const {Autocomplete} = await google.maps.importLibrary('places');
        if (!address.isConnected) return;
        const autocomplete = new Autocomplete(address, {
          types: ['address'],
          fields: ['address_components', 'formatted_address', 'geometry', 'place_id']
        });
        address.setAttribute('autocomplete', 'off');
        status.textContent = 'Saisissez une adresse puis choisissez une proposition Google.';
        // Keep Enter in the suggestions from submitting the company form.
        address.addEventListener('keydown', event => {
          if (event.key === 'Enter') event.preventDefault();
        });
        autocomplete.addListener('place_changed', () => {
          const place = autocomplete.getPlace();
          if (!place.address_components) return;
          const component = type => place.address_components.find(c => c.types.includes(type));
          const value = type => component(type)?.long_name || '';
          const assign = (name, text) => {
            const input = field(name);
            if (!input) return;
            if (input.tagName === 'SELECT') {
              const option = Array.from(input.options).find(o => o.value === text || o.textContent.trim() === text || o.value === component('country')?.short_name);
              text = option?.value || '';
              input.tomselect?.setValue(text, true);
            }
            input.value = text;
            input.dispatchEvent(new Event('input', {bubbles: true}));
            input.dispatchEvent(new Event('change', {bubbles: true}));
          };
          assign('adresse', [value('street_number'), value('route')].filter(Boolean).join(' ') || place.formatted_address || '');
          assign('codePostal', value('postal_code'));
          assign('ville', value('locality') || value('postal_town') || value('administrative_area_level_3'));
          assign('pays', value('country'));
          status.textContent = 'Adresse sélectionnée. Le code postal et la ville ont été complétés.';
        });
      }).catch(() => { status.textContent = 'Suggestions Google indisponibles. Vous pouvez saisir l’adresse manuellement.'; });
      return;
    }
    const panel = document.createElement('section');
    panel.className = 'client-address-search my-3 address-assistant';
    panel.innerHTML = '<div class="fw-semibold mb-2"><i class="bi bi-geo-alt me-2" aria-hidden="true"></i>Choisir une adresse précise</div><div data-search></div><p class="small text-muted mt-2 mb-2" role="status" data-status>Chargement des suggestions Google…</p><details><summary class="small">Vérifier sur le plan</summary><div class="client-address-map mt-2" data-map hidden></div><p class="small mt-2 mb-0" data-caption>Sélectionnez une suggestion pour afficher sa localisation.</p></details>';
    address.parentElement.insertBefore(panel, address);
    const status = panel.querySelector('[data-status]'), canvas = panel.querySelector('[data-map]'), caption = panel.querySelector('[data-caption]');
    let version = 0, filling = false, searchReady = false, initializing = false, map, point;
    const retry = document.createElement('button');
    retry.type = 'button';
    retry.className = 'btn btn-outline-primary btn-sm mt-2';
    retry.textContent = 'Réessayer les suggestions';
    retry.hidden = true;
    panel.append(retry);
    retry.addEventListener('click', initialize);
    const notify = el => { el.dispatchEvent(new Event('input', {bubbles: true})); el.dispatchEvent(new Event('change', {bubbles: true})); };
    function assign(el, value, short = '') {
      if (!el) return;
      if (el.tagName === 'SELECT') {
        const option = Array.from(el.options).find(o => (short && o.value === short) || o.value === value || o.textContent.trim().toLowerCase() === value.toLowerCase());
        value = option?.value || '';
        if (el.tomselect) el.tomselect.setValue(value, true);
      }
      el.value = value;
      notify(el);
    }
    function invalidate() {
      if (filling) return;
      ++version;
      canvas.hidden = true;
      if (searchReady) status.textContent = 'Adresse modifiée manuellement : utilisez le champ de recherche ci-dessus pour sélectionner une suggestion.';
      caption.textContent = 'La position précédente ne correspond plus nécessairement à cette adresse.';
      ['googlePlaceId', 'formattedAddress', 'latitude', 'longitude'].forEach(name => assign(field(name), ''));
      if (form.name === 'site_administrateur') document.getElementById('btn-clear-marker')?.click();
    }
    [address, postal, city, field('pays')].filter(Boolean).forEach(el => el.addEventListener('input', invalidate));
    function initialize() {
    if (initializing || searchReady) return;
    initializing = true;
    retry.hidden = true;
    status.textContent = 'Chargement des suggestions Google…';
    googleReady().then(async () => {
      const {PlaceAutocompleteElement} = await google.maps.importLibrary('places');
      if (!address.isConnected) return;
      const search = new PlaceAutocompleteElement();
      search.setAttribute('placeholder', 'Commencez à saisir une adresse…');
      search.setAttribute('aria-label', 'Rechercher une adresse avec Google');
      panel.querySelector('[data-search]').append(search);
      searchReady = true;
      status.textContent = 'Sélectionnez une proposition, puis vérifiez le numéro et le repère sur le plan.';
      search.addEventListener('gmp-error', () => { status.textContent = 'Google est indisponible. La saisie manuelle reste possible.'; });
      search.addEventListener('gmp-select', async ({placePrediction}) => {
        const current = ++version;
        try {
          const place = placePrediction.toPlace();
          await place.fetchFields({fields: ['addressComponents', 'formattedAddress', 'location']});
          if (current !== version || !address.isConnected) return;
          const component = type => place.addressComponents?.find(c => c.types.includes(type));
          const text = type => component(type)?.longText || '';
          if (!text('route') || !place.location) { status.textContent = 'Choisissez une adresse de rue précise, avec une localisation.'; return; }
          filling = true;
          assign(address, [text('street_number'), text('route')].filter(Boolean).join(' '));
          assign(postal, text('postal_code'));
          assign(city, text('locality') || text('postal_town') || text('administrative_area_level_3'));
          assign(field('departement'), text('administrative_area_level_2'), component('administrative_area_level_2')?.shortText);
          assign(field('region'), text('administrative_area_level_1'), component('administrative_area_level_1')?.shortText);
          assign(field('pays'), text('country'), component('country')?.shortText);
          assign(field('googlePlaceId'), place.id || '');
          assign(field('formattedAddress'), place.formattedAddress || '');
          assign(field('latitude'), String(place.location.lat()));
          assign(field('longitude'), String(place.location.lng()));
          if (form.name === 'site_administrateur') window.siteMapSetLatLng?.(place.location.lat(), place.location.lng(), {source: 'auto', pan: true});
          filling = false;
          await google.maps.importLibrary('maps');
          if (current !== version || !address.isConnected) return;
          canvas.hidden = false;
          panel.querySelector('details').open = true;
          if (!map) {
            map = new google.maps.Map(canvas, {center: place.location, zoom: 17, mapTypeControl: false, streetViewControl: false, gestureHandling: 'cooperative'});
            point = new google.maps.Circle({map, center: place.location, radius: 10, fillColor: '#233342', fillOpacity: .9, strokeColor: '#ffc107', strokeWeight: 3});
          } else { map.setCenter(place.location); point.setCenter(place.location); }
          caption.textContent = place.formattedAddress || address.value;
          status.textContent = text('street_number') ? 'Adresse sélectionnée. Vérifiez le repère et précisez le complément si nécessaire.' : 'Rue sélectionnée sans numéro : précisez le numéro si nécessaire, puis vérifiez la position.';
        } catch (_) { if (current === version) status.textContent = 'Impossible de récupérer cette adresse. Réessayez ou saisissez-la manuellement.'; }
        finally { filling = false; }
      });
    }).catch(() => {
      status.textContent = 'Suggestions Google indisponibles. Réessayez ou saisissez l’adresse manuellement.';
      retry.hidden = false;
    }).finally(() => { initializing = false; });
    }
    initialize();
  }
  function scan() { document.querySelectorAll('input[name$="[adresse]"]').forEach(enhance); }
  scan();
  new MutationObserver(scan).observe(document.body, {childList: true, subtree: true});
  }, {once: true});
})();
