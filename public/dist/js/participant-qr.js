(() => {
  function init() {
    document.querySelectorAll('[data-participant-qr]').forEach(node => {
      if (node.dataset.qrReady || typeof QRCode === 'undefined') return;
      new QRCode(node, {text:node.dataset.participantQr, width:180, height:180, colorDark:'#182c3b', colorLight:'#ffffff', correctLevel:QRCode.CorrectLevel.M});
      node.dataset.qrReady = '1';
    });
    document.querySelector('[data-qr-search]')?.addEventListener('input', event => {
      const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase();
      const query = normalize(event.target.value.trim());
      const cards = Array.from(document.querySelectorAll('[data-qr-card]'));
      cards.forEach(card => card.hidden = !normalize(card.dataset.name).includes(query));
      const empty = document.querySelector('[data-qr-no-result]');
      if (empty) empty.hidden = !cards.length || cards.some(card => !card.hidden);
    });
    document.querySelector('[data-qr-print]')?.addEventListener('click', () => window.print());
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
