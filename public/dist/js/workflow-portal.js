(() => {
  const form = document.getElementById('participants-form');
  if (!form) return;
  const rows = document.getElementById('participant-rows');
  const add = document.getElementById('add-participant');
  const max = Number(form.dataset.max || 0);
  const initialiseDate = (root) => {
    if (!window.flatpickr) return;
    root.querySelectorAll('.birth-date:not([readonly])').forEach(input => {
      if (!input._flatpickr) window.flatpickr(input, {dateFormat: 'Y-m-d', altInput: true, altFormat: 'd/m/Y', maxDate: 'today', allowInput: false, disableMobile: false,
        locale: {firstDayOfWeek: 1, weekdays: {shorthand: ['dim','lun','mar','mer','jeu','ven','sam'], longhand: ['dimanche','lundi','mardi','mercredi','jeudi','vendredi','samedi']}, months: {shorthand: ['janv','févr','mars','avr','mai','juin','juil','août','sept','oct','nov','déc'], longhand: ['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre']}}});
    });
  };
  const refresh = () => { add.disabled = rows.children.length >= max; };
  const append = () => {
    if (rows.children.length >= max) return;
    const used = new Set([...rows.children].map(row => Number(row.dataset.position)));
    let index = 0; while (used.has(index)) index++;
    if (index >= 200) return;
    const row = document.getElementById('participant-template').content.firstElementChild.cloneNode(true);
    row.dataset.position = index;
    row.querySelector('.participant-number').textContent = index + 1;
    row.querySelectorAll('[data-field]').forEach(input => { input.name = `participants[${index}][${input.dataset.field}]`; });
    rows.append(row); initialiseDate(row); refresh();
  };
  add.addEventListener('click', () => { append(); rows.lastElementChild?.querySelector('input')?.focus(); });
  rows.addEventListener('click', event => {
    const button = event.target.closest('.remove-participant');
    if (!button) return;
    const row = button.closest('.participant');
    row.querySelectorAll('.birth-date').forEach(input => input._flatpickr?.destroy());
    row.remove(); refresh();
  });
  initialiseDate(rows);
  if (!rows.children.length && max > 0) append();
  refresh();
})();
