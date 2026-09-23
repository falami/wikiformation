(() => {
  'use strict';

  const slotSelector = '[data-training-slot]';
  const states = new WeakMap();
  const pad = value => String(value).padStart(2, '0');
  const formatMinutes = value => {
    const hours = Math.floor(value / 60);
    const minutes = value % 60;
    return hours ? `${hours} h${minutes ? ` ${String(minutes).padStart(2, '0')}` : ''}` : `${minutes} min`;
  };

  function readDate(input) {
    if (!input?.value) return null;
    const value = input.value.trim();
    const fr = value.match(/^(\d{2})\/(\d{2})\/(\d{4})\s+(?:à\s+)?(\d{2}):(\d{2})$/);
    const iso = value.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::\d{2})?$/);
    const parts = fr ? [fr[3], fr[2], fr[1], fr[4], fr[5]] : iso?.slice(1);
    if (!parts) return null;
    const [year, month, day, hour, minute] = parts.map(Number);
    const date = new Date(year, month - 1, day, hour, minute);
    return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day && date.getHours() === hour && date.getMinutes() === minute ? date : null;
  }

  function intervals(start, end) {
    const result = [];
    const startClock = start.getHours() * 60 + start.getMinutes();
    const endClock = end.getHours() * 60 + end.getMinutes();
    // Les anciens créneaux de plusieurs jours répètent leurs horaires chaque jour.
    if (start.toDateString() !== end.toDateString() && endClock > startClock) {
      for (let day = new Date(start.getFullYear(), start.getMonth(), start.getDate()); day <= end; day.setDate(day.getDate() + 1)) {
        const from = new Date(day); from.setHours(start.getHours(), start.getMinutes());
        const to = new Date(day); to.setHours(end.getHours(), end.getMinutes());
        result.push([from, to]);
      }
    } else {
      for (let cursor = new Date(start); cursor < end;) {
        const midnight = new Date(cursor.getFullYear(), cursor.getMonth(), cursor.getDate() + 1);
        const next = midnight < end ? midnight : end;
        result.push([cursor, next]);
        cursor = next;
      }
    }
    return result;
  }

  function automaticPause(start, end) {
    return intervals(start, end).reduce((total, [from, to]) => {
      const lunch = new Date(from.getFullYear(), from.getMonth(), from.getDate(), 13);
      return total + ((to - from) >= 360 * 60000 && from < lunch && to > lunch ? 90 : 0);
    }, 0);
  }

  function readSlot(slot) {
    const start = readDate(slot.querySelector('input[name$="[dateDebut]"]'));
    const end = readDate(slot.querySelector('input[name$="[dateFin]"]'));
    if (!start || !end || end <= start) return null;
    const pauseField = slot.querySelector('[data-training-pause]');
    const gross = intervals(start, end).reduce((total, [from, to]) => total + Math.floor((to - from) / 60000), 0);
    const automatic = !pauseField?.value.trim();
    const pause = automatic ? automaticPause(start, end) : Number(pauseField.value);
    const valid = Number.isInteger(pause) && pause >= 0 && pause < gross;
    return { gross, pause, automatic, minutes: valid ? gross - pause : null };
  }

  function proposedEnd(start, targetMinutes, pauseValue = '') {
    const end = new Date(start);
    end.setMinutes(end.getMinutes() + targetMinutes);
    const pause = pauseValue.trim() === '' ? automaticPause(start, end) : Number(pauseValue);
    if (Number.isInteger(pause) && pause >= 0) end.setMinutes(end.getMinutes() + pause);
    return end;
  }

  const dateKey = input => readDate(input)?.getTime().toString() || input.value;

  function stateFor(slot) {
    if (states.has(slot)) return states.get(slot);
    const startInput = slot.querySelector('input[name$="[dateDebut]"]');
    const endInput = slot.querySelector('input[name$="[dateFin]"]');
    if (!startInput || !endInput) return null;
    const pauseInput = slot.querySelector('[data-training-pause]');
    const start = readDate(startInput), end = readDate(endInput);
    const target = start && end && (end - start) === 210 * 60000 ? 210 : 420;
    const state = {
      startInput, endInput, pauseInput, target,
      linked: !end || (!!start && +proposedEnd(start, target, pauseInput?.value || '') === +end),
      lastStart: dateKey(startInput), lastEnd: dateKey(endInput), lastPause: pauseInput?.value || '', applying: false,
    };
    states.set(slot, state);
    return state;
  }

  function setDate(input, date) {
    if (input._flatpickr) input._flatpickr.setDate(date, false);
    else if (input.matches('[data-datepicker]')) input.value = `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
    else input.value = `${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear()} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
  }

  function applyEnd(slot, state) {
    const start = readDate(state.startInput);
    if (!start) return;
    state.applying = true;
    setDate(state.endInput, proposedEnd(start, state.target, state.pauseInput?.value || ''));
    state.lastEnd = dateKey(state.endInput);
    // Les résumés de la session/convention doivent voir les horaires calculés.
    state.endInput.dispatchEvent(new Event('change', { bubbles: true }));
    state.applying = false;
  }

  function sync(slot) {
    const state = stateFor(slot);
    if (!state || state.applying) return;
    const startChanged = dateKey(state.startInput) !== state.lastStart;
    const endChanged = dateKey(state.endInput) !== state.lastEnd;
    const pauseChanged = (state.pauseInput?.value || '') !== state.lastPause;
    if (endChanged) state.linked = false;
    if ((startChanged || pauseChanged) && (state.linked || !state.endInput.value)) {
      state.linked = true;
      applyEnd(slot, state);
    }
    state.lastStart = dateKey(state.startInput);
    state.lastEnd = dateKey(state.endInput);
    state.lastPause = state.pauseInput?.value || '';
    update(slot);
  }

  function preset(slot, kind = 'day', base = new Date()) {
    const state = stateFor(slot);
    if (!state) return;
    const start = new Date(base);
    start.setHours(kind === 'pm' ? 13 : kind === 'am' ? 9 : 8, kind === 'am' ? 0 : 30, 0, 0);
    state.applying = true;
    setDate(state.startInput, start);
    state.target = kind === 'day' ? 420 : 210;
    state.linked = true;
    if (state.pauseInput) state.pauseInput.value = '';
    state.lastStart = dateKey(state.startInput);
    state.lastPause = '';
    state.applying = false;
    applyEnd(slot, state);
    update(slot);
  }

  function initSlot(slot) {
    if (!stateFor(slot)) return;
    if (!slot.querySelector('[data-training-presets]')) {
      const controls = document.createElement('div');
      controls.className = 'col-12 d-flex flex-wrap align-items-center gap-2 mb-2';
      controls.dataset.trainingPresets = '';
      const label = document.createElement('span');
      label.className = 'small text-muted'; label.textContent = 'Proposer une durée :';
      controls.append(label);
      [[420, 'Journée · 7 h'], [210, 'Demi-journée · 3 h 30']].forEach(([minutes, text]) => {
        const button = document.createElement('button');
        button.type = 'button'; button.className = 'btn btn-sm btn-outline-primary';
        button.textContent = text; button.dataset.trainingTarget = String(minutes);
        button.title = 'Recalculer l’heure de fin à partir de l’heure de début et de la pause automatique';
        controls.append(button);
      });
      slot.append(controls);
    }
    update(slot);
  }

  function update(slot) {
    let output = slot.querySelector('[data-training-duration]');
    if (!output) {
      output = document.createElement('p');
      output.className = 'small text-muted mb-2';
      output.dataset.trainingDuration = '';
      output.setAttribute('aria-live', 'polite');
      slot.append(output);
    }
    const data = readSlot(slot);
    let text = 'Renseignez les horaires pour connaître la durée de formation hors pause.';
    if (data?.minutes !== null && data?.minutes !== undefined) {
      text = `${formatMinutes(data.minutes)} de formation · ${data.pause ? `${formatMinutes(data.pause)} de pause déduite${data.automatic ? ' automatiquement' : ''}` : 'aucune pause déduite'}`;
      slot.dataset.trainingMinutes = String(data.minutes);
    } else {
      delete slot.dataset.trainingMinutes;
      if (data) text = 'La pause doit être un nombre entier positif ou nul, inférieur à la durée du créneau.';
    }
    if (output.textContent !== text) output.textContent = text;
  }

  window.WikiFormationDuration = {
    getMinutes: slot => readSlot(slot)?.minutes ?? null,
    formatMinutes,
    init,
    preset,
    readDate,
    proposedEnd,
    handleDateChange: input => { const slot = input.closest?.(slotSelector); if (slot) sync(slot); },
  };

  function init(scope) {
    if (scope.matches?.(slotSelector)) initSlot(scope);
    scope.querySelectorAll?.(slotSelector).forEach(initSlot);
  }

  function boot() {
    init(document);
    ['input', 'change'].forEach(eventName => document.addEventListener(eventName, event => {
      const slot = event.target.closest?.(slotSelector);
      if (slot) sync(slot);
    }));
    document.addEventListener('click', event => {
      const button = event.target.closest?.('[data-training-target]');
      const slot = button?.closest(slotSelector);
      if (!slot) return;
      const state = stateFor(slot);
      state.target = Number(button.dataset.trainingTarget);
      state.linked = true;
      if (state.pauseInput) state.pauseInput.value = '';
      state.lastPause = '';
      applyEnd(slot, state);
      update(slot);
    });
    // Les créneaux ajoutés et les formulaires chargés dans une modale utilisent le même aperçu.
    new MutationObserver(records => records.forEach(record => record.addedNodes.forEach(node => {
      if (node.nodeType === Node.ELEMENT_NODE && !node.matches('[data-training-duration]')) init(node);
    }))).observe(document.body, { childList: true, subtree: true });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
  else boot();
})();
