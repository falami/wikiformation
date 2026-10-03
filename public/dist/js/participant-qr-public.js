(() => {
  'use strict';
  const form = document.querySelector('[data-attendance-form]');
  if (form) {
    const canvas = form.querySelector('[data-signature-canvas]');
    const context = canvas.getContext('2d');
    const error = form.querySelector('[data-signature-error]');
    let drawing = false;
    let strokes = 0;
    const point = event => {
      const rect = canvas.getBoundingClientRect();
      return [(event.clientX - rect.left) * canvas.width / rect.width, (event.clientY - rect.top) * canvas.height / rect.height];
    };
    context.lineWidth = 3;
    context.lineCap = 'round';
    context.lineJoin = 'round';
    context.strokeStyle = '#223342';
    canvas.addEventListener('pointerdown', event => {
      if (event.button !== 0 && event.pointerType === 'mouse') return;
      drawing = true;
      canvas.setPointerCapture(event.pointerId);
      context.beginPath();
      context.moveTo(...point(event));
      error.hidden = true;
    });
    canvas.addEventListener('pointermove', event => {
      if (!drawing) return;
      context.lineTo(...point(event));
      context.stroke();
      strokes++;
    });
    canvas.addEventListener('pointerup', () => { drawing = false; });
    canvas.addEventListener('pointercancel', () => { drawing = false; });
    form.querySelector('[data-clear-signature]').addEventListener('click', () => {
      context.clearRect(0, 0, canvas.width, canvas.height);
      strokes = 0;
      form.querySelector('[data-signature-value]').value = '';
    });
    const choices = form.querySelectorAll('[name="periode"]');
    if (choices.length === 1) choices[0].checked = true;
    form.addEventListener('submit', event => {
      if (strokes < 2) {
        event.preventDefault();
        error.hidden = false;
        canvas.scrollIntoView({block:'center'});
        return;
      }
      form.querySelector('[data-signature-value]').value = canvas.toDataURL('image/png');
      form.querySelector('[type="submit"]').disabled = true;
    });
  }
  document.querySelector('[data-satisfaction-form]')?.addEventListener('submit', event => {
    event.currentTarget.querySelector('[type="submit"]').disabled = true;
  });
})();
