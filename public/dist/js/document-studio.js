(() => {
  const root = document.getElementById('document-studio');
  if (!root || root.dataset.ready) return;
  root.dataset.ready = '1';
  const initial = JSON.parse(document.getElementById('studio-state').textContent);
  let config = initial.config, version = initial.version, dirty = false, serial = 0, savedSerial = 0;
  let lastEditor = null, range = null, previewUrl = null, saveTimer = null, queue = Promise.resolve(), previewRequest = null;
  const $ = id => document.getElementById('studio-' + id);
  const el = (tag, attrs = {}, text) => {
    const n = document.createElement(tag);
    Object.entries(attrs).forEach(([k, v]) => { if (k === 'class') n.className = v; else n.setAttribute(k, v); });
    if (text !== undefined) n.textContent = text;
    return n;
  };
  const message = text => { $('message').textContent = text; $('message').hidden = !text; };
  const mark = () => {
    dirty = true; serial++; $('status').textContent = 'Modifications en cours…';
    clearTimeout(saveTimer); saveTimer = setTimeout(() => save().catch(() => {}), 1800);
  };
  const collect = () => {
    [...$('blocks').children].forEach((node, i) => {
      config.blocks[i].title = node.querySelector('[data-title]').value;
      config.blocks[i].html = node.querySelector('.studio-richtext').innerHTML;
      config.blocks[i].pageBreak = node.querySelector('[data-break]').checked;
      const position = node.querySelector('[data-position]');
      if (position) config.blocks[i].position = position.value;
    });
    return structuredClone(config);
  };
  async function api(url, data, signal) {
    const response = await fetch(url, { method: 'POST', credentials: 'same-origin', signal,
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': root.dataset.token }, body: JSON.stringify(data) });
    if (!response.ok) {
      let text = 'La demande a échoué. Votre texte reste dans l’éditeur.';
      try { text = (await response.json()).error || text; } catch {}
      throw new Error(text);
    }
    return response;
  }
  function save() {
    clearTimeout(saveTimer);
    const task = queue.catch(() => {}).then(async () => {
      if (!dirty) return;
      const stamp = serial, payload = collect();
      $('status').textContent = 'Enregistrement…';
      try {
        const result = await (await api(root.dataset.save, { name: $('name').value, version, config: payload })).json();
        version = result.version; root.querySelectorAll('input[name="version"]').forEach(input => input.value = version); savedSerial = stamp; dirty = serial !== savedSerial;
        $('status').textContent = dirty ? 'Nouvelles modifications…' : 'Brouillon enregistré';
        message('');
      } catch (e) { $('status').textContent = 'Non enregistré'; message(e.message); throw e; }
    });
    queue = task; return task;
  }
  function remember() {
    const selection = window.getSelection();
    if (selection.rangeCount && lastEditor?.contains(selection.anchorNode)) range = selection.getRangeAt(0).cloneRange();
  }
  function insertField(key) {
    if (!lastEditor || !document.contains(lastEditor)) { message('Cliquez d’abord dans le texte d’un chapitre pour insérer ce champ.'); return; }
    lastEditor.focus(); const sel = window.getSelection();
    if (range && lastEditor.contains(range.commonAncestorContainer)) { sel.removeAllRanges(); sel.addRange(range); }
    else { const r = document.createRange(); r.selectNodeContents(lastEditor); r.collapse(false); sel.removeAllRanges(); sel.addRange(r); }
    document.execCommand('insertText', false, '[[' + key + ']]'); remember(); mark();
  }
  function fieldList() {
    $('fields').replaceChildren();
    const fields = { ...initial.fields };
    config.fields.forEach(f => fields['custom.' + f.key] = 'Personnel · ' + f.label);
    const search = $('field-search').value.toLocaleLowerCase();
    Object.entries(fields).filter(([key, label]) => (label + key).toLocaleLowerCase().includes(search)).forEach(([key, label]) => {
      const button = el('button', { type: 'button', class: 'studio-field', title: '[[' + key + ']]' }, label);
      button.addEventListener('mousedown', e => e.preventDefault()); button.onclick = () => insertField(key);
      $('fields').append(button);
    });
  }
  function customFields() {
    $('custom-fields').replaceChildren();
    config.fields.forEach((f, i) => {
      const box = el('div', { class: 'studio-custom-item' });
      const label = el('input', { class: 'studio-input', 'aria-label': 'Libellé du champ', maxlength: '100', placeholder: 'Nom du champ' }); label.value = f.label;
      const value = el('textarea', { class: 'studio-input', 'aria-label': 'Valeur du champ', maxlength: '4000', rows: '3', placeholder: 'Valeur à insérer' }); value.value = f.value;
      label.oninput = () => { f.label = label.value; fieldList(); mark(); };
      value.oninput = () => { f.value = value.value; mark(); };
      const remove = el('button', { type: 'button', class: 'studio-link-btn' }, 'Supprimer ce champ');
      remove.onclick = () => {
        collect();
        if (config.blocks.some(b => (b.html + b.title).includes('[[custom.' + f.key + ']]'))) { message('Ce champ est utilisé dans un bloc. Retirez-le du texte avant de le supprimer.'); return; }
        config.fields.splice(i, 1); customFields(); fieldList(); mark();
      };
      box.append(label, value, el('small', { class: 'studio-muted' }, '[[' + 'custom.' + f.key + ']]'), remove);
      $('custom-fields').append(box);
    });
  }
  function renderBlocks() {
    $('blocks').replaceChildren(); lastEditor = null; range = null;
    $('empty').hidden = config.blocks.length > 0;
    $('block-count').textContent = config.blocks.length + ' bloc' + (config.blocks.length > 1 ? 's' : '');
    config.blocks.forEach((block, index) => {
      const article = el('article', { class: 'studio-block-editor' });
      const head = el('div', { class: 'studio-block-head' });
      head.append(el('span', {}, block.position === 'section' ? 'Rubrique · ' + block.section : 'Chapitre ' + (index + 1)));
      const actions = el('div', { class: 'd-flex gap-1' });
      [['↑', 'Monter', -1], ['↓', 'Descendre', 1], ['×', 'Supprimer le bloc', 0]].forEach(([symbol, title, move]) => {
        const b = el('button', { type: 'button', class: 'studio-icon-btn', title, 'aria-label': title }, symbol);
        b.disabled = move === -1 && index === 0 || move === 1 && index === config.blocks.length - 1;
        b.onclick = () => {
          collect();
          if (move) [config.blocks[index], config.blocks[index + move]] = [config.blocks[index + move], config.blocks[index]];
          else { if (!window.confirm('Supprimer ce bloc de votre brouillon ?')) return; config.blocks.splice(index, 1); }
          renderBlocks(); mark();
        }; actions.append(b);
      });
      head.append(actions);
      const settings = el('div', { class: 'studio-block-settings' });
      const title = el('input', { class: 'studio-input', 'data-title': '', maxlength: '160', placeholder: 'Titre du chapitre (facultatif)', 'aria-label': 'Titre du chapitre' }); title.value = block.title;
      settings.append(title);
      if (block.position !== 'section') {
        const position = el('select', { class: 'studio-input', 'data-position': '', 'aria-label': 'Emplacement du chapitre' });
        position.append(el('option', { value: 'start' }, 'Avant le document d’origine'), el('option', { value: 'end' }, config.base === 'blank' ? 'À la suite des chapitres' : 'En fin de document · Avant les signatures'));
        position.value = block.position; settings.append(position);
      }
      const breakLabel = el('label', { class: 'small studio-muted d-flex gap-2' });
      const pageBreak = el('input', { type: 'checkbox', 'data-break': '' }); pageBreak.checked = block.pageBreak;
      breakLabel.append(pageBreak, document.createTextNode('Commencer sur une nouvelle page')); settings.append(breakLabel);
      const toolbar = el('div', { class: 'studio-toolbar', role: 'toolbar', 'aria-label': 'Mise en forme' });
      const editor = el('div', { class: 'studio-richtext', contenteditable: 'true', role: 'textbox', 'aria-multiline': 'true', 'aria-label': 'Texte du chapitre', spellcheck: 'true' });
      // Only sanitized server content or content produced by this editor reaches this node.
      editor.innerHTML = block.html;
      [['bold', 'B', 'Gras'], ['italic', 'I', 'Italique'], ['underline', 'U', 'Souligné'], ['insertUnorderedList', '•', 'Liste à puces'], ['insertOrderedList', '1.', 'Liste numérotée'], ['justifyLeft', '≡', 'Aligner à gauche'], ['justifyCenter', '↔', 'Centrer'], ['removeFormat', 'Tx', 'Retirer la mise en forme']].forEach(([cmd, text, title]) => {
        const button = el('button', { type: 'button', title, 'aria-label': title }, text);
        button.onmousedown = e => e.preventDefault();
        button.onclick = () => { editor.focus(); document.execCommand(cmd, false); remember(); mark(); }; toolbar.append(button);
      });
      const format = el('select', { 'aria-label': 'Style du texte' });
      [['p', 'Paragraphe'], ['h2', 'Chapitre'], ['h3', 'Sous-chapitre']].forEach(([value, name]) => format.append(el('option', { value }, name)));
      format.onchange = () => { editor.focus(); if (range) { const s = getSelection(); s.removeAllRanges(); s.addRange(range); } document.execCommand('formatBlock', false, format.value); mark(); };
      const color = el('input', { type: 'color', value: '#203746', title: 'Couleur du texte', 'aria-label': 'Couleur du texte', style: 'width:29px;height:29px;border:0' });
      color.oninput = () => { editor.focus(); if (range) { const s = getSelection(); s.removeAllRanges(); s.addRange(range); } document.execCommand('styleWithCSS', false, true); document.execCommand('foreColor', false, color.value); remember(); mark(); };
      toolbar.append(format, color);
      editor.onfocus = () => { lastEditor = editor; };
      editor.onkeyup = editor.onmouseup = remember;
      editor.onpaste = e => { e.preventDefault(); document.execCommand('insertText', false, e.clipboardData.getData('text/plain')); remember(); mark(); };
      editor.ondrop = e => e.preventDefault();
      article.append(head, settings, toolbar, editor);
      article.addEventListener('input', mark); article.addEventListener('change', mark);
      $('blocks').append(article);
    });
  }
  function addBlock(section = '') {
    collect();
    if (section && config.blocks.some(b => b.section === section)) { message('Cette rubrique est déjà présente dans votre brouillon.'); return; }
    config.blocks.push({ title: section ? '' : 'Nouveau chapitre', html: '<p></p>', position: section ? 'section' : 'end', section, pageBreak: false });
    renderBlocks(); mark(); $('blocks').lastElementChild.querySelector('.studio-richtext').focus();
  }
  async function preview() {
    previewRequest?.abort(); previewRequest = new AbortController();
    $('preview-empty').textContent = 'Génération de votre aperçu…'; $('preview-empty').hidden = false; $('frame').hidden = true;
    try {
      const response = await api(root.dataset.preview, { config: collect(), source: Number($('source').value) }, previewRequest.signal);
      const blob = await response.blob();
      if (!blob.type.includes('application/pdf')) throw new Error('La prévisualisation n’a pas retourné de PDF.');
      if (previewUrl) URL.revokeObjectURL(previewUrl); previewUrl = URL.createObjectURL(blob);
      $('frame').src = previewUrl; $('frame').hidden = false; $('preview-empty').hidden = true;
      $('download').href = previewUrl; $('download').hidden = false; message('');
    } catch (e) { if (e.name !== 'AbortError') { $('preview-empty').textContent = e.message; message(e.message); } }
  }
  $('name').oninput = mark;
  $('add').onclick = $('first-block').onclick = () => addBlock();
  if ($('section')) $('section').onchange = () => { if ($('section').value) addBlock($('section').value); $('section').value = ''; };
  $('primary').oninput = () => { config.primary = $('primary').value; mark(); };
  $('accent').oninput = () => { config.accent = $('accent').value; mark(); };
  $('font').value = config.font; $('font').onchange = () => { config.font = $('font').value; mark(); };
  $('reset-style').onclick = () => { config.primary = config.accent = ''; config.font = 'original'; $('primary').value = '#233342'; $('accent').value = '#d8ad4a'; $('font').value = 'original'; mark(); };
  $('field-search').oninput = fieldList;
  $('add-field').onclick = () => { config.fields.push({ key: 'champ_' + Math.random().toString(36).slice(2, 10), label: 'Mon nouveau champ', value: '' }); customFields(); fieldList(); mark(); };
  $('save').onclick = () => save().catch(() => {});
  $('refresh').onclick = preview; $('source').onchange = preview;
  $('publish').onclick = () => $('publish-dialog').showModal();
  $('publish-cancel').onclick = () => $('publish-dialog').close();
  $('publish-confirm').onclick = async () => {
    $('publish-confirm').disabled = true;
    try {
      await save(); if (dirty) await save();
      const result = await (await api(root.dataset.publish, { version })).json();
      version = result.version; $('publish-dialog').close(); $('status').textContent = 'Version activée'; message(result.message + '. L’historique sera visible au rechargement.');
    } catch (e) { $('publish-dialog').close(); message(e.message); }
    finally { $('publish-confirm').disabled = false; }
  };
  window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  root.addEventListener('keydown', e => { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); save().catch(() => {}); } });
  renderBlocks(); customFields(); fieldList();
})();
