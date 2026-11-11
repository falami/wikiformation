/* Provisional practical scoring. The server remains authoritative on submission. */
(function () {
  function grade(schema, answers, duration) {
    const engines = {}, reasons = [];
    let answered = 0, count = 0;
    const themes = schema.themes.map(theme => {
      const id = theme.assessment || 'main';
      const engine = engines[id] ||= {title: theme.assessmentLabel || id, score: 0};
      let score = 0, max = 0, complete = true;
      theme.questions.forEach(q => {
        count++; max += q.points;
        const raw = answers[q.id];
        if (raw === '' || raw == null || !/^\d+$/.test(String(raw)) || Number(raw) > q.points) {
          complete = false; return;
        }
        const value = Number(raw);
        answered++; score += value;
        if (value === 0) reasons.push('Critère à zéro : ' + q.label);
      });
      engine.score += score;
      const threshold = Math.ceil(max / 2);
      if (complete && score < threshold) reasons.push('Seuil non atteint : ' + theme.title);
      return {title: (theme.assessment ? engine.title + ' · ' : '') + theme.title, score, max, threshold, complete};
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
      const value = theme.score + ' / ' + theme.max + ' · minimum ' + theme.threshold + (theme.complete ? (theme.score >= theme.threshold ? ' · Seuil atteint' : ' · Seuil non atteint') : ' · À compléter');
      form.querySelector('[data-score-theme="' + index + '"] [data-theme-score]').textContent = value;
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
