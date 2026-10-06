(() => {
  const form = document.querySelector('[data-attendance-calendar]');
  if (!form || !window.flatpickr) return;
  const select = form.querySelector('select[name="date"]');
  const dates = Array.from(select.options, option => option.value);
  if (!dates.length) return;
  const states = JSON.parse(form.dataset.calendarStates || "{}");
  const input = document.createElement('input');
  input.type = 'text';
  input.setAttribute('aria-label', 'Calendrier des journées de formation');
  form.querySelector('[data-calendar-host]').append(input);
  const french = {
    firstDayOfWeek: 1,
    weekdays: { shorthand: ['Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'], longhand: ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'] },
    months: { shorthand: ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'], longhand: ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'] },
    yearAriaLabel: 'Année', monthAriaLabel: 'Mois'
  };
  flatpickr(input, {
    inline: true, locale: french, dateFormat: 'Y-m-d', ariaDateFormat: 'l j F Y',
    enable: dates, minDate: dates[0], maxDate: dates[dates.length - 1],
    defaultDate: select.value, monthSelectorType: 'static', disableMobile: true,
    onDayCreate: (_, __, picker, day) => {
      if (dates.includes(picker.formatDate(day.dateObj, 'Y-m-d'))) {
        const state = states[picker.formatDate(day.dateObj, 'Y-m-d')];
        day.classList.add('training-day', state?.complete ? 'attendance-complete' : 'attendance-pending');
        const label = state ? `${state.signed}/${state.expected} signatures — ${state.complete ? 'Journée émargée' : 'À compléter'}` : 'À compléter';
        day.setAttribute('aria-label', day.getAttribute('aria-label') + ' — ' + label);
        day.title = label;
        const marker = document.createElement('span');
        marker.className = 'calendar-day-marker';
        marker.textContent = state?.complete ? '✓' : '•';
        marker.setAttribute('aria-hidden', 'true');
        day.append(marker);
      }
    },
    onChange: (_, date) => {
      if (!dates.includes(date) || date === select.value) return;
      select.value = date;
      form.requestSubmit();
    }
  });
  input.hidden = true;
  select.hidden = true;
  form.querySelectorAll('[data-calendar-fallback]').forEach(element => { element.hidden = true; });
  form.querySelector('[data-calendar-legend]').hidden = false;
})();
