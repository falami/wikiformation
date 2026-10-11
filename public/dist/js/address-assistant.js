(() => {
  'use strict';
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
      script.onerror = () => { clearInterval(poll); clearTimeout(timeout); reject(new Error('Network')); };
      document.head.append(script);
    });
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
    const panel = document.createElement('section');
    panel.className = 'client-address-search my-3 address-assistant';
    panel.innerHTML = '<div class="fw-semibold mb-2"><i class="bi bi-geo-alt me-2" aria-hidden="true"></i>Choisir une adresse précise</div><div data-search></div><p class="small text-muted mt-2 mb-2" role="status" data-status>Chargement des suggestions Google…</p><details><summary class="small">Vérifier sur le plan</summary><div class="client-address-map mt-2" data-map hidden></div><p class="small mt-2 mb-0" data-caption>Sélectionnez une suggestion pour afficher sa localisation.</p></details>';
    address.parentElement.insertBefore(panel, address);
    const status = panel.querySelector('[data-status]'), canvas = panel.querySelector('[data-map]'), caption = panel.querySelector('[data-caption]');
    let version = 0, filling = false, map, point;
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
      status.textContent = 'Adresse modifiée manuellement : sélectionnez une suggestion pour vérifier sa position.';
      caption.textContent = 'La position précédente ne correspond plus nécessairement à cette adresse.';
      ['googlePlaceId', 'formattedAddress', 'latitude', 'longitude'].forEach(name => assign(field(name), ''));
      if (form.name === 'site_administrateur') document.getElementById('btn-clear-marker')?.click();
    }
    [address, postal, city, field('pays')].filter(Boolean).forEach(el => el.addEventListener('input', invalidate));
    googleReady().then(async () => {
      const {PlaceAutocompleteElement} = await google.maps.importLibrary('places');
      await google.maps.importLibrary('maps');
      if (!address.isConnected) return;
      const search = new PlaceAutocompleteElement();
      search.setAttribute('aria-label', 'Rechercher une adresse avec Google');
      panel.querySelector('[data-search]').append(search);
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
    }).catch(() => { status.textContent = 'Suggestions Google indisponibles. Vous pouvez saisir l’adresse manuellement.'; });
  }
  function scan() { document.querySelectorAll('input[name$="[adresse]"]').forEach(enhance); }
  scan();
  new MutationObserver(scan).observe(document.body, {childList: true, subtree: true});
})();
