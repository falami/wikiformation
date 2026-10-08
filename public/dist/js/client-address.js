(() => {
  'use strict';
  const root = document.querySelector('[data-client-address]');
  if (!root) return;
  const address = document.getElementById(root.dataset.addressId);
  const postal = document.getElementById(root.dataset.postalId);
  const city = document.getElementById(root.dataset.cityId);
  const status = root.querySelector('[data-address-status]');
  const canvas = root.querySelector('[data-address-map]');
  const empty = root.querySelector('[data-address-empty]');
  const caption = root.querySelector('[data-address-caption]');
  const locate = root.querySelector('[data-address-locate]');
  let map, point, geocoder, timer, revision = 0;
  const unavailable = () => {
    status.textContent = 'Les suggestions Google sont indisponibles. Vous pouvez saisir les coordonnées manuellement.';
    locate.disabled = true;
  };
  const clearMap = () => { canvas.hidden = true; empty.hidden = false; };
  const display = (location, text) => {
    canvas.hidden = false;
    empty.hidden = true;
    if (!map) {
      map = new google.maps.Map(canvas, {center: location, zoom: 16, mapTypeControl: false, streetViewControl: false, gestureHandling: 'cooperative'});
      point = new google.maps.Circle({map, center: location, radius: 14, fillColor: '#233342', fillOpacity: 0.9, strokeColor: '#ffc107', strokeWeight: 3});
    } else {
      map.setCenter(location);
      map.setZoom(16);
      point.setCenter(location);
    }
    caption.textContent = text;
  };
  const geocode = async () => {
    clearTimeout(timer);
    const version = ++revision;
    const query = [address.value.trim(), postal.value.trim(), city.value.trim()].filter(Boolean).join(', ');
    if (!address.value.trim() || (!postal.value.trim() && !city.value.trim())) {
      clearMap();
      caption.textContent = 'Renseignez une adresse et une ville ou un code postal pour afficher le plan.';
      return;
    }
    if (!geocoder) return;
    caption.textContent = 'Recherche de la localisation…';
    try {
      const result = await geocoder.geocode({address: query, region: 'fr'});
      if (version !== revision) return;
      if (!result.results.length) throw new Error('No result');
      display(result.results[0].geometry.location, result.results[0].formatted_address);
    } catch (_) {
      if (version !== revision) return;
      clearMap();
      caption.textContent = 'Adresse non localisée. Vérifiez les coordonnées ou sélectionnez une suggestion Google.';
    }
  };
  async function initialize() {
    try {
      const {PlaceAutocompleteElement} = await google.maps.importLibrary('places');
      await google.maps.importLibrary('maps');
      const {Geocoder} = await google.maps.importLibrary('geocoding');
      geocoder = new Geocoder();
      const search = new PlaceAutocompleteElement();
      search.setAttribute('placeholder', 'Commencez à saisir une adresse…');
      search.setAttribute('aria-label', 'Rechercher une adresse avec Google');
      root.querySelector('[data-address-search]').append(search);
      status.textContent = 'Choisissez une suggestion pour compléter l’adresse, le code postal et la ville.';
      search.addEventListener('gmp-error', unavailable);
      search.addEventListener('gmp-select', async ({placePrediction}) => {
        clearTimeout(timer);
        const version = ++revision;
        try {
          const place = placePrediction.toPlace();
          await place.fetchFields({fields: ['addressComponents', 'formattedAddress', 'location']});
          if (version !== revision) return;
          const component = type => place.addressComponents?.find(item => item.types.includes(type))?.longText || '';
          address.value = [component('street_number'), component('route')].filter(Boolean).join(' ') || place.formattedAddress || '';
          postal.value = component('postal_code');
          city.value = component('locality') || component('postal_town') || component('administrative_area_level_3');
          [address, postal, city].forEach(field => {
            field.dispatchEvent(new Event('input', {bubbles: true}));
            field.dispatchEvent(new Event('change', {bubbles: true}));
          });
          clearTimeout(timer);
          if (place.location) display(place.location, place.formattedAddress || address.value);
          else { clearMap(); caption.textContent = 'Cette adresse ne dispose pas de localisation sur le plan.'; }
          status.textContent = 'Adresse sélectionnée. Vous pouvez ajouter un complément ou ajuster les coordonnées.';
        } catch (_) {
          if (version === revision) status.textContent = 'Impossible de récupérer cette adresse. Réessayez ou renseignez les champs manuellement.';
        }
      });
      locate.disabled = false;
      geocode();
    } catch (_) { unavailable(); }
  }
  [address, postal, city].forEach(field => field.addEventListener('input', () => {
    ++revision;
    clearTimeout(timer);
    clearMap();
    timer = setTimeout(geocode, 900);
  }));
  locate.addEventListener('click', geocode);
  locate.disabled = true;
  if (!root.dataset.googleKey) { unavailable(); return; }
  if (window.google?.maps?.importLibrary) { initialize(); return; }
  window.initClientAddressGoogle = initialize;
  const priorAuthFailure = window.gm_authFailure;
  window.gm_authFailure = () => { unavailable(); priorAuthFailure?.(); };
  const script = document.createElement('script');
  const params = new URLSearchParams({key: root.dataset.googleKey, loading: 'async', callback: 'initClientAddressGoogle', language: 'fr', region: 'FR', v: 'weekly'});
  script.src = 'https://maps.googleapis.com/maps/api/js?' + params;
  script.async = true;
  script.onerror = unavailable;
  document.head.append(script);
})();
