(function () {
  'use strict';
  function init() {
    document.querySelectorAll('.module-admin-form select').forEach(function (select) {
      if (select.tomselect || !window.TomSelect) return;
      new TomSelect(select, {
        create: false,
        maxOptions: null,
        plugins: ['dropdown_input'],
        render: {
          no_results: function () { return '<div class="no-results">Aucun résultat trouvé</div>'; }
        },
        onInitialize: function () {
          this.control_input.setAttribute('placeholder', 'Rechercher…');
        }
      });
    });
    document.querySelectorAll('.module-admin-form input[type="date"]').forEach(function (input) {
      if (input._flatpickr || !window.flatpickr) return;
      window.flatpickr(input, {
        dateFormat: 'Y-m-d', altInput: true, altFormat: 'd/m/Y',
        disableMobile: true, allowInput: false,
        locale: {
          firstDayOfWeek: 1,
          weekdays: { shorthand: ['Dim','Lun','Mar','Mer','Jeu','Ven','Sam'], longhand: ['Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi'] },
          months: { shorthand: ['Janv','Févr','Mars','Avr','Mai','Juin','Juil','Août','Sept','Oct','Nov','Déc'], longhand: ['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'] },
          rangeSeparator: ' au ', scrollTitle: 'Défiler pour augmenter', toggleTitle: 'Cliquer pour basculer', yearAriaLabel: 'Année', monthAriaLabel: 'Mois'
        },
        onReady: function (dates, value, picker) {
          picker.altInput.placeholder = 'Choisir une date';
          picker.calendarContainer.classList.add('module-calendar');
          var actions = document.createElement('div');
          actions.className = 'module-calendar-actions';
          [['Aujourd’hui', function () { picker.setDate(new Date(), true); picker.close(); }], ['Effacer', function () { picker.clear(); picker.close(); }]].forEach(function (action) {
            var button = document.createElement('button');
            button.type = 'button'; button.textContent = action[0];
            button.addEventListener('click', action[1]); actions.appendChild(button);
          });
          picker.calendarContainer.appendChild(actions);
        }
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
  document.addEventListener('turbo:load', init);
})();
