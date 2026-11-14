(() => {
  'use strict';
  const previews = new WeakMap();
  const selector = 'textarea[data-document-description]';

  function parse(value) {
    const lines = value.split(/\r\n|\r|\n/).map(line => line.trim()).filter(Boolean);
    const main = lines.shift() || '';
    const details = lines.map(line => line.replace(/^(?:(?:[-–—*][\t ]+|[•●▪◦][\t ]*))+/u, '').trim())
      .filter(line => line && !/^[-–—*•●▪◦]+$/u.test(line));
    return {main, details};
  }

  function render(input) {
    const body = previews.get(input);
    if (!body) return;
    const description = parse(input.value);
    body.replaceChildren();
    if (!description.main) {
      const empty = document.createElement('p');
      empty.className = 'document-description-empty';
      empty.textContent = 'Saisissez un titre, puis les détails sur les lignes suivantes.';
      body.append(empty);
      return;
    }
    const title = document.createElement('strong');
    title.textContent = description.main;
    body.append(title);
    if (description.details.length) {
      const list = document.createElement('ul');
      description.details.forEach(text => {
        const item = document.createElement('li');
        item.textContent = text;
        list.append(item);
      });
      body.append(list);
    }
  }

  function attach(input) {
    if (previews.has(input) || input.disabled) return;
    const preview = document.createElement('details');
    preview.className = 'document-description-preview';
    const summary = document.createElement('summary');
    summary.textContent = 'Aperçu de la description sur le PDF';
    const body = document.createElement('div');
    body.className = 'document-description-body';
    preview.append(summary, body);
    input.insertAdjacentElement('afterend', preview);
    previews.set(input, body);
    input.addEventListener('input', () => render(input));
    input.addEventListener('change', () => render(input));
    // Values filled automatically by the formation selector are reflected
    // when opening the preview as well.
    preview.addEventListener('toggle', () => { if (preview.open) render(input); });
    render(input);
  }

  function scan(root) {
    if (root.matches?.(selector)) attach(root);
    root.querySelectorAll?.(selector).forEach(attach);
  }

  function init() {
    scan(document);
    new MutationObserver(records => {
      records.forEach(record => record.addedNodes.forEach(node => {
        if (node.nodeType === 1) scan(node);
      }));
    }).observe(document.body, {childList: true, subtree: true});
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once: true});
  else init();
})();
