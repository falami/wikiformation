(function (root) {
  'use strict';
  root.WikiExpenseReceipt = function (config) {
    const utils = root.WikiExpenseInputs;
    const get = id => document.getElementById(id);
    const fileInput = get('scanFile'), camera = get('scanCamera');
    const scan = get('btnScan'), clear = get('btnScanClear'), apply = get('btnApplyProposal');
    const status = get('scanStatus'), box = get('scanProposal');
    const dropzone = get('scanDropzone'), preview = get('scanPreview');
    if (!utils || !fileInput || !scan || !status || !box || !apply) return;
    const state = utils.createScanState();
    let controller = null, objectUrl = null, busy = false;
    const money = cents => cents === null ? 'Non détecté' : (cents / 100).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';

    function render() {
      if (objectUrl) URL.revokeObjectURL(objectUrl);
      objectUrl = null;
      const file = state.file;
      clear.disabled = !file;
      scan.disabled = !file || busy;
      apply.disabled = !state.proposal || busy;
      get('scanFileBadge').textContent = file ? file.name : 'Aucun fichier';
      get('scanFileMeta').textContent = file ? `${(file.size / 1024 / 1024).toFixed(2)} Mo • ${file.type || 'fichier'}` : '';
      preview.replaceChildren();
      preview.style.display = file ? '' : 'none';
      if (!file) return;
      objectUrl = URL.createObjectURL(file);
      const isPdf = file.type === 'application/pdf' || /\.pdf$/i.test(file.name);
      const frame = document.createElement(isPdf ? 'iframe' : 'img');
      frame.src = objectUrl;
      if (isPdf) frame.title = 'Aperçu du justificatif';
      else frame.alt = 'Aperçu du justificatif';
      const wrapper = document.createElement('div');
      wrapper.className = 'scan-thumb';
      wrapper.append(frame);
      preview.append(wrapper);
    }

    function select(file) {
      controller?.abort();
      controller = null;
      busy = false;
      state.select(null);
      box.style.display = 'none';
      status.textContent = '';
      if (file) {
        const typeAllowed = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'].includes(file.type);
        const extensionAllowed = /\.(pdf|jpe?g|png|webp)$/i.test(file.name);
        if ((!typeAllowed && (file.type || !extensionAllowed)) || file.size <= 0 || file.size > 8 * 1024 * 1024) {
          status.textContent = 'Choisissez un PDF, JPG, PNG ou WebP non vide de 8 Mo maximum.';
          fileInput.value = ''; camera.value = '';
        } else state.select(file);
      }
      render();
    }

    function pickFile() { fileInput.value = ''; fileInput.click(); }
    get('btnScanPick')?.addEventListener('click', pickFile);
    get('btnScanCamera')?.addEventListener('click', () => { camera.value = ''; camera.click(); });
    clear?.addEventListener('click', () => {
      fileInput.value = ''; camera.value = ''; select(null);
    });
    fileInput.addEventListener('change', () => select(fileInput.files?.[0] || null));
    camera?.addEventListener('change', () => select(camera.files?.[0] || null));
    dropzone?.addEventListener('click', event => {
      if (event.target.closest('iframe') || (root.getSelection?.().toString() || '').length) return;
      pickFile();
    });
    dropzone?.addEventListener('keydown', event => {
      if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); pickFile(); }
    });
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(type => dropzone?.addEventListener(type, event => {
      event.preventDefault(); event.stopPropagation();
      dropzone.classList.toggle('is-dragover', type === 'dragenter' || type === 'dragover');
      if (type === 'drop') select(event.dataTransfer?.files?.[0] || null);
    }));

    scan.addEventListener('click', async () => {
      if (!state.file || busy) return;
      const request = state.begin();
      controller = new AbortController();
      const requestController = controller;
      const timeout = setTimeout(() => requestController.abort(), 90000);
      busy = true; box.style.display = 'none'; render();
      status.textContent = 'Analyse en cours…';
      try {
        const data = new FormData();
        data.append('file', request.file);
        data.append('_token', config.token);
        const response = await fetch(config.url, { method: 'POST', body: data, headers: { Accept: 'application/json' }, signal: requestController.signal });
        const json = await response.json().catch(() => null);
        if (!state.current(request)) return;
        if (!response.ok || !json?.ok || !json.proposal || typeof json.proposal !== 'object') {
          throw new Error(json?.message || 'L’analyse a échoué. Réessayez ou saisissez les informations manuellement.');
        }
        if (!state.complete(request, json.proposal)) return;
        const amounts = utils.receiptAmounts(json.proposal), rate = utils.receiptRate(json.proposal);
        get('scanConfidence').textContent = 'Lecture automatique : vérifiez les valeurs avec le justificatif avant de les appliquer.';
        get('pDate').textContent = utils.validIsoDate(json.proposal.date) ? json.proposal.date : 'Non détectée';
        get('pTtc').textContent = money(amounts.ttc);
        get('pHt').textContent = money(amounts.ht);
        get('pTva').textContent = rate === null ? 'À vérifier / plusieurs taux' : rate.toLocaleString('fr-FR') + ' %';
        box.style.display = '';
        const warnings = Array.isArray(json.proposal.warnings) ? json.proposal.warnings.filter(value => typeof value === 'string') : [];
        status.textContent = 'Proposition prête. Les valeurs non détectées ne remplaceront pas votre saisie.' + (warnings.length ? ' ' + warnings.join(' ') : '');
      } catch (error) {
        if (state.current(request)) status.textContent = error.name === 'AbortError'
          ? 'L’analyse a pris trop de temps. Vous pouvez réessayer ou saisir la dépense manuellement.'
          : (error.message || 'Impossible de joindre le service d’analyse. Réessayez.');
      } finally {
        clearTimeout(timeout);
        if (state.current(request)) { controller = null; busy = false; render(); }
      }
    });

    apply.addEventListener('click', () => {
      const proposal = state.proposal, receipt = state.file;
      if (!proposal || !receipt || busy) return;
      const fields = Object.fromEntries(Object.entries(config.fields).map(([key, id]) => [key, get(id)]));
      const amounts = utils.receiptAmounts(proposal), rate = utils.receiptRate(proposal);
      const changes = [];
      if (utils.validIsoDate(proposal.date) && fields.date) changes.push([fields.date, proposal.date.split('-').reverse().join('/')]);
      for (const key of ['ht', 'tva', 'ttc']) {
        if (amounts[key] !== null && fields[key]) changes.push([fields[key], (amounts[key] / 100).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })]);
      }
      if (rate !== null && fields.rate) {
        const option = Array.from(fields.rate.options).find(option => utils.parseNumber(option.value) === rate);
        if (option) changes.push([fields.rate, option.value]);
      }
      const replacingAmounts = changes.some(([field, value]) => ['ht', 'tva', 'ttc'].some(key => field === fields[key]) && utils.parseNumber(field.value) > 0 && utils.parseNumber(field.value) !== utils.parseNumber(value));
      const proofWrap = get('proofPreviewWrap');
      const replacingProof = (fields.proof?.files?.[0] && fields.proof.files[0] !== receipt) || proofWrap?.dataset.existing === '1';
      if ((replacingAmounts || replacingProof) && !root.confirm('Appliquer les valeurs détectées et remplacer le justificatif déjà renseigné ? Les autres informations seront conservées.')) return;
      // Attach first: do not claim success or alter amounts if this browser cannot attach the file.
      try {
        const transfer = new DataTransfer(); transfer.items.add(receipt);
        if (!fields.proof) throw new Error('missing proof input');
        fields.proof.files = transfer.files;
      } catch (_) {
        status.textContent = 'Ce navigateur ne permet pas de joindre la photo automatiquement. Ajoutez-la dans « Justificatif » puis renseignez les montants proposés.';
        return;
      }
      const form = fields.proof.form;
      if (form) form.dataset.applyingReceipt = '1';
      try {
        if (fields.manual && Object.values(amounts).some(value => value !== null)) {
          fields.manual.checked = true;
          fields.manual.dispatchEvent(new Event('change', { bubbles: true }));
        }
        changes.forEach(([field, value]) => { field.value = value; });
        if (fields.date?._flatpickr && utils.validIsoDate(proposal.date)) fields.date._flatpickr.setDate(fields.date.value, false, 'd/m/Y');
        changes.forEach(([field]) => { field.dispatchEvent(new Event('input', { bubbles: true })); field.dispatchEvent(new Event('change', { bubbles: true })); });
        fields.proof.dispatchEvent(new Event('change', { bubbles: true }));
      } finally { if (form) delete form.dataset.applyingReceipt; }
      state.applied();
      apply.disabled = true;
      status.textContent = 'Proposition appliquée et justificatif joint. Vérifiez les montants et la TVA déductible avant d’enregistrer.' + (rate === null ? ' Le taux de TVA reste à vérifier ; les montants détectés sont conservés.' : '');
    });
    render();
  };
})(window);
