(() => {
  'use strict';
  document.addEventListener('DOMContentLoaded', () => {
    if (typeof window.flatpickr !== 'function') return;
    const start = document.getElementById('tp-from');
    const end = document.getElementById('tp-to');
    if (!start || !end) return;
    const locale = {
      firstDayOfWeek: 1,
      weekdays: {shorthand: ['dim','lun','mar','mer','jeu','ven','sam'], longhand: ['dimanche','lundi','mardi','mercredi','jeudi','vendredi','samedi']},
      months: {shorthand: ['janv','févr','mars','avr','mai','juin','juil','août','sept','oct','nov','déc'], longhand: ['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre']},
      rangeSeparator: ' au ', scrollTitle: 'Défiler pour augmenter la valeur', toggleTitle: 'Cliquer pour basculer', yearAriaLabel: 'Année', monthAriaLabel: 'Mois'
    };
    const options = {
      locale, dateFormat: 'Y-m-d', altInput: true, altFormat: 'd/m/Y',
      disableMobile: true, ariaDateFormat: 'l j F Y',
      onReady: (_, __, picker) => {
        picker.calendarContainer.classList.add('profile-datepicker');
        picker.altInput.id = picker.input.id + '-display';
        picker.altInput.placeholder = 'Choisir une date';
        picker.altInput.setAttribute('aria-label', picker.input === start ? 'Début de l’indisponibilité' : 'Fin de l’indisponibilité, incluse');
        document.querySelector(`label[for="${picker.input.id}"]`).htmlFor = picker.altInput.id;
        const footer = document.createElement('div');
        footer.className = 'profile-datepicker-footer';
        const clear = document.createElement('button');
        clear.type = 'button'; clear.textContent = 'Effacer';
        clear.addEventListener('click', () => { picker.clear(); picker.close(); });
        const close = document.createElement('button');
        close.type = 'button'; close.textContent = 'Fermer';
        close.addEventListener('click', () => picker.close());
        footer.append(clear, close); picker.calendarContainer.append(footer);
      }
    };
    const to = flatpickr(end, options);
    const from = flatpickr(start, {...options, onChange: dates => {
      const date = dates[0];
      const previous = to.selectedDates[0];
      to.set('minDate', date || null);
      if (date && (!previous || previous < date)) to.setDate(date, true);
    }});
    if (from.selectedDates[0]) to.set('minDate', from.selectedDates[0]);
    const message = document.createElement('p');
    message.className = 'tp-help'; message.setAttribute('role', 'status');
    start.closest('.row').after(message);
    const update = () => {
      message.textContent = start.value && end.value ? `Indisponibilité du ${from.altInput.value} au ${to.altInput.value} inclus.` : 'Choisissez le début et la fin de votre indisponibilité.';
    };
    from.config.onChange.push(update); to.config.onChange.push(update); update();
    start.form.addEventListener('submit', event => {
      if (!start.value || !end.value || start.value > end.value) {
        event.preventDefault();
        message.textContent = 'Choisissez une date de début et une date de fin égale ou postérieure.';
        (!start.value ? from : to).open();
      }
    });
  });
})();
