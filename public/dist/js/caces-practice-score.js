/* Provisional practical scoring. The server remains authoritative on submission. */
(function () {
  function grade(schema, answers, duration) {
    const engines = {}, reasons = [];
    let answered = 0, count = 0;
    const themes = schema.themes.map(theme => {
      const id = theme.assessment || 'main';
      const engine = engines[id] ||= {title: theme.assessmentLabel || id, score: 0};
      let score = 0, max = 0, complete = true, hasZero = false;
      theme.questions.forEach(q => {
        count++; max += q.points;
        const raw = answers[q.id];
        if (raw === '' || raw == null || !/^\d+$/.test(String(raw)) || Number(raw) > q.points) {
          complete = false; return;
        }
        const value = Number(raw);
        answered++; score += value;
        if (value === 0) { hasZero = true; reasons.push('Critère à zéro : ' + q.label); }
      });
      engine.score += score;
      const threshold = Math.ceil(max / 2);
      if (complete && score < threshold) reasons.push('Seuil non atteint : ' + theme.title);
      return {title: (theme.assessment ? engine.title + ' · ' : '') + theme.title, score, max, threshold, complete, remediationEligible: complete && (score < threshold || hasZero)};
    });
    const assessments = Object.values(engines);
    if (answered === count) assessments.forEach(e => {
      if (e.score < 70) reasons.push('Note inférieure à 70/100' + (e.title === 'main' ? '' : ' : ' + e.title));
    });
    if (Number(duration) > schema.durationMinutes * 60) reasons.push('Durée maximale dépassée');
    const complete = answered === count && Number(duration) >= 1 && Number.isInteger(Number(duration)) && Number(duration) <= 86400;
    return {themes, assessments, answered, count, reasons, complete,
      score: Math.round(assessments.reduce((sum, e) => sum + e.score, 0) / Math.max(1, assessments.length) * 100) / 100};
  }
  if (typeof module !== 'undefined' && module.exports) module.exports = grade;
  if (typeof document === 'undefined') return;
  const form = document.querySelector('[data-practice-schema]');
  if (!form) return;
  const schema = JSON.parse(form.dataset.practiceSchema);
  const panel = form.querySelector('.cx-live-score');
  const detail = form.querySelector('[data-live-details]');
  // Follow the actual visible header, including layouts where it scrolls away.
  const header = document.querySelector('.wn-topbar');
  let positionFrame = 0;
  function positionScore() {
    positionFrame = 0;
    const rect = header ? header.getBoundingClientRect() : null;
    const top = rect && rect.top <= 1 ? Math.max(0, rect.bottom) : 0;
    panel.style.setProperty('--cx-score-top', top + 'px');
  }
  function schedulePosition() {
    if (!positionFrame) positionFrame = requestAnimationFrame(positionScore);
  }
  window.addEventListener('scroll', schedulePosition, {passive: true});
  window.addEventListener('resize', schedulePosition, {passive: true});
  if (header && typeof ResizeObserver !== 'undefined') new ResizeObserver(schedulePosition).observe(header);
  positionScore();
  let previous = '';
  function update() {
    const answers = {};
    form.querySelectorAll('select[name^="scores["]').forEach(select => {
      answers[select.name.slice(7, -1)] = select.value;
    });
    const result = grade(schema, answers, form.querySelector('[name="durationSeconds"]').value);
    const signature = JSON.stringify(result);
    if (signature === previous) return;
    previous = signature;
    form.querySelector('[data-live-total]').textContent = result.score.toLocaleString('fr-FR') + ' / 100';
    form.querySelector('[data-live-progress]').textContent = result.answered + ' / ' + result.count + ' critères notés' + (result.assessments.length > 1 ? ' · Moyenne des engins' : '');
    const status = result.reasons.length ? 'Conditions non remplies' : result.complete ? 'Seuils de réussite atteints' : 'Évaluation à compléter';
    form.querySelector('[data-live-status]').textContent = status;
    panel.dataset.state = result.reasons.length ? 'danger' : result.complete ? 'success' : 'pending';
    detail.replaceChildren();
    result.themes.forEach((theme, index) => {
      const checkbox = form.querySelector('[data-remediation-theme="' + index + '"]');
      if (checkbox) {
        checkbox.disabled = !theme.remediationEligible;
        if (checkbox.disabled) checkbox.checked = false;
        checkbox.closest('label').querySelector('[data-remediation-status]').textContent = !theme.complete ? 'Notation à compléter' : theme.remediationEligible ? 'Thème à retravailler · sélection possible' : 'Thème réussi';
      }
      const value = theme.score + ' / ' + theme.max + ' · minimum ' + theme.threshold + (theme.complete ? (theme.score >= theme.threshold ? ' · Seuil atteint' : ' · Seuil non atteint') : ' · À compléter');
      form.querySelectorAll('[data-score-theme="' + index + '"] [data-theme-score]').forEach(output => { output.textContent = value; });
      const card = form.querySelector('[data-score-theme="' + index + '"] [data-chapter-result]');
      if (card) {
        const reached = theme.score >= theme.threshold;
        card.dataset.state = !theme.complete ? 'pending' : reached ? 'success' : 'danger';
        card.querySelector('[data-chapter-points]').textContent = theme.score;
        card.querySelector('[data-chapter-status]').textContent = !theme.complete ? 'À compléter' : reached ? 'Seuil atteint' : 'Seuil non atteint';
        card.querySelector('[data-chapter-meter]').setAttribute('aria-valuenow', theme.score);
        card.querySelector('[data-chapter-fill]').style.width = (theme.max ? theme.score / theme.max * 100 : 0) + '%';
        card.querySelector('[data-chapter-message]').textContent = !theme.complete ? 'Notation en cours' : theme.remediationEligible && reached ? 'Critère à zéro · thème à revoir' : reached ? 'Minimum du chapitre atteint' : (theme.threshold - theme.score) + ' point(s) sous le minimum';
      }
      const row = document.createElement('p');
      row.textContent = theme.title + ' — ' + value;
      detail.append(row);
    });
    if (result.assessments.length > 1) result.assessments.forEach(engine => {
      const row = document.createElement('p');
      row.textContent = engine.title + ' : ' + engine.score + ' / 100 · minimum 70';
      detail.append(row);
    });
    result.reasons.forEach(reason => {
      const row = document.createElement('p'); row.className = 'cx-score-reason';
      row.textContent = reason; detail.append(row);
    });
    if (!result.complete && result.answered === result.count) {
      const row = document.createElement('p'); row.textContent = 'Durée du test à renseigner ou à corriger.'; detail.append(row);
    }
  }
  form.addEventListener('change', update);
  form.addEventListener('input', update);
  // The stopwatch updates the duration programmatically, including after confirmation.
  setInterval(update, 1000);
  update();
})();
