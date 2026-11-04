(() => {
  'use strict';
  const feedback = message => {
    let el = document.getElementById('crmFeedback');
    if (!el) { el = document.createElement('div'); el.id = 'crmFeedback'; el.className = 'alert alert-danger'; el.setAttribute('role', 'alert'); document.getElementById('emailModalHost')?.before(el); }
    el.textContent = message;
  };
  const followup = document.getElementById('crmFollowup');
  const action = document.getElementById('crmAction');
  document.querySelectorAll('[data-crm-select]').forEach(select => {
    if (!window.TomSelect || select.tomselect) return;
    const control = new TomSelect(select, {
      create: false, allowEmptyOption: true, maxOptions: null,
      searchField: ['text'], placeholder: 'Rechercher une étape…',
      render: { no_results: () => '<div class="no-results">Aucune étape trouvée</div>' },
      onDropdownOpen(dropdown) {
        if (!select.closest('.modal')) return;
        const rect = this.control.getBoundingClientRect();
        Object.assign(dropdown.style, {position:'fixed', width:rect.width+'px', left:rect.left+'px', top:Math.max(8, rect.bottom + 200 > window.innerHeight ? rect.top - 200 : rect.bottom + 4)+'px', zIndex:'1100'});
      },
      onInitialize() { this.wrapper.classList.add('crm-premium-select'); this.control_input.setAttribute('aria-label', select.getAttribute('aria-label') || 'Nouvelle étape du prospect'); }
    });
    select.closest('.modal-body')?.addEventListener('scroll', () => control.close());
    select.closest('.modal')?.addEventListener('hide.bs.modal', () => control.close());
    window.addEventListener('resize', () => control.close());
  });
  const dateInput = document.getElementById('crmDate');
  let datePicker = null;
  if (dateInput && window.flatpickr) {
    datePicker = flatpickr(dateInput, {
      dateFormat: 'Y-m-d', altInput: true, altFormat: 'd/m/Y',
      altInputClass: 'form-control crm-premium-date', disableMobile: true,
      allowInput: false, monthSelectorType: 'static',
      appendTo: document.getElementById('crmFollowupForm'), // Inside the dialog and outside its scrolling body.
      locale: {
        firstDayOfWeek: 1,
        weekdays: {shorthand:['Dim','Lun','Mar','Mer','Jeu','Ven','Sam'], longhand:['Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi']},
        months: {shorthand:['Jan','Fév','Mar','Avr','Mai','Juin','Juil','Août','Sep','Oct','Nov','Déc'], longhand:['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre']},
        scrollTitle: 'Défiler pour augmenter', toggleTitle: 'Cliquer pour changer', yearAriaLabel: 'Année'
      },
      onReady(_, __, picker) {
        picker.calendarContainer.classList.add('crm-premium-calendar');
        picker.altInput.id = 'crmDateDisplay';
        picker.altInput.placeholder = 'Choisir une date';
        picker.altInput.setAttribute('aria-describedby', 'crmDateHelp');
        document.querySelector('label[for="crmDate"]').htmlFor = 'crmDateDisplay';
        const footer = document.createElement('div'); footer.className = 'crm-calendar-footer';
        const clear = document.createElement('button'); clear.type = 'button'; clear.textContent = 'Effacer';
        clear.addEventListener('click', () => { picker.clear(); picker.close(); });
        const today = document.createElement('button'); today.type = 'button'; today.textContent = 'Aujourd’hui';
        today.addEventListener('click', () => { picker.setDate(new Intl.DateTimeFormat('en-CA', {timeZone:'Europe/Paris'}).format(new Date()), true, 'Y-m-d'); picker.close(); });
        footer.append(clear, today); picker.calendarContainer.append(footer);
      },
      onOpen(_, __, picker) {
        requestAnimationFrame(() => {
        if (!picker.isOpen) return;
        const rect = picker.altInput.getBoundingClientRect();
        const calendar = picker.calendarContainer;
        calendar.style.position = 'fixed';
        calendar.style.top = Math.max(8, rect.bottom + calendar.offsetHeight + 8 > window.innerHeight ? rect.top - calendar.offsetHeight - 8 : rect.bottom + 8) + 'px';
        calendar.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - calendar.offsetWidth - 8)) + 'px';
        calendar.style.right = 'auto';
        });
      }
    });
    followup.querySelector('.modal-body').addEventListener('scroll', () => datePicker.close());
    followup.addEventListener('hide.bs.modal', () => datePicker.close());
    window.addEventListener('resize', () => datePicker.close());
    document.getElementById('crmFollowupForm').addEventListener('submit', event => {
      if (action.value === 'schedule' && !dateInput.value) {
        event.preventDefault(); datePicker.altInput.focus(); datePicker.open();
      }
    });
  }

  function updateAction() {
    const dateVisible = ['schedule', 'reply'].includes(action.value);
    document.getElementById('crmDateGroup').hidden = !dateVisible;
    document.getElementById('crmDate').disabled = !dateVisible;
    document.getElementById('crmDate').required = !datePicker && action.value === 'schedule';
    if (datePicker) { datePicker.close(); datePicker.altInput.disabled = !dateVisible; datePicker.altInput.setAttribute('aria-required', String(action.value === 'schedule')); }
    document.getElementById('crmStageGroup').hidden = action.value !== 'status';
    document.getElementById('crmNote').required = action.value === 'note';
    followup.querySelectorAll('[data-followup-action]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.followupAction === action.value)));
    document.getElementById('crmDateOptional').textContent = action.value === 'reply' ? 'Facultatif' : 'Date requise';
    document.getElementById('crmNoteOptional').textContent = action.value === 'note' ? 'Note requise' : 'Facultatif';
    document.getElementById('crmFollowupSubmitLabel').textContent = {schedule:'Planifier la relance', reply:'Enregistrer la réponse', status:'Mettre à jour l’étape', note:'Enregistrer la note'}[action.value];
    document.getElementById('crmDateHelp').textContent = action.value === 'reply' ? 'Date facultative. Sans date, la relance précédente est retirée.' : 'Un rappel dans votre liste de priorités, sans envoi automatique.';
  }
  action?.addEventListener('change', updateAction);
  followup?.querySelectorAll('[data-followup-action]').forEach(button => button.addEventListener('click', () => {
    action.value = button.dataset.followupAction;
    updateAction();
  }));
  followup?.querySelectorAll('[data-followup-days]').forEach(button => button.addEventListener('click', () => {
    const parisDate = new Intl.DateTimeFormat('en-CA', {timeZone:'Europe/Paris', year:'numeric', month:'2-digit', day:'2-digit'}).format(new Date());
    const date = new Date(parisDate + 'T12:00:00Z');
    date.setUTCDate(date.getUTCDate() + Number(button.dataset.followupDays));
    if (datePicker) datePicker.setDate(date.toISOString().slice(0, 10), true, 'Y-m-d');
    else document.getElementById('crmDate').value = date.toISOString().slice(0, 10);
  }));
  followup?.addEventListener('show.bs.modal', event => {
    const button = event.relatedTarget;
    if (!button) return;
    const form = document.getElementById('crmFollowupForm');
    form.reset(); action.value = 'schedule'; form.action = button.dataset.followupUrl;
    document.getElementById('crmFollowupToken').value = button.dataset.token;
    document.getElementById('crmFollowupName').textContent = button.dataset.name;
    document.getElementById('crmFollowupAvatar').textContent = button.dataset.name.trim().split(/\s+/).slice(0,2).map(part => part[0]).join('').toUpperCase();
    document.getElementById('crmFollowupCompany').textContent = button.dataset.company || 'Contact prospect';
    document.getElementById('crmFollowupCurrent').textContent = button.dataset.stageLabel || '';
    followup.querySelector('[data-followup-action="schedule"]').disabled = ['won', 'lost', 'converted'].includes(button.dataset.stage);
    followup.querySelector('[data-followup-action="status"]').disabled = button.dataset.stage === 'converted';
    if (datePicker) datePicker.setDate(button.dataset.date || '', false, 'Y-m-d');
    else document.getElementById('crmDate').value = button.dataset.date;
    const stageSelect = document.getElementById('crmStage');
    if (stageSelect.tomselect) stageSelect.tomselect.setValue(button.dataset.stage, true);
    else stageSelect.value = button.dataset.stage;
    if (['won', 'lost', 'converted'].includes(button.dataset.stage)) action.value = 'note';
    updateAction();
  });
  let opening = false;
  window.openProspectEmail = async url => {
    if (opening) return;
    opening = true;
    try {
      const response = await fetch(url, {headers:{'X-Requested-With':'XMLHttpRequest'}});
      if (!response.ok || response.redirected) throw new Error('Impossible d’ouvrir la messagerie. Vérifiez vos droits ou reconnectez-vous.');
      const host = document.getElementById('emailModalHost');
      host.innerHTML = await response.text();
      const modal = host.querySelector('#emailModal');
      if (!modal) throw new Error('La session a expiré. Actualisez la page.');
      const instance = new bootstrap.Modal(modal);
      const input = suffix => modal.querySelector(`[name$="[${suffix}]"]`);
      const subject = input('subject'), message = input('message'), template = input('template');
      const emailSelects = [];
      modal.querySelectorAll('select').forEach(select => {
        if (!window.TomSelect || select.tomselect) return;
        emailSelects.push(new TomSelect(select, {
          create:false, allowEmptyOption:true,
          onInitialize() { this.wrapper.classList.add('crm-premium-select'); },
          render:{no_results:()=>'<div class="no-results">Aucun résultat</div>'}
        }));
      });
      const updateCount = () => {
        const count = message.value.length;
        modal.querySelector('#crmComposeCount').textContent = count.toLocaleString('fr-FR') + (count > 1 ? ' caractères' : ' caractère');
      };
      message.addEventListener('input', updateCount); updateCount();
      const status = modal.querySelector('#emailSendStatus');
      const send = modal.querySelector('#btnSend');
      const preview = modal.querySelector('#btnPreview');
      let sending = false, sent = false, previewVersion = 0;
      let dirty = false;
      let previewTimer = null, previewRequest = null, previewClosed = false;
      const previewState = modal.querySelector('#crmPreviewState');
      const previewZone = modal.querySelector('#emailPreviewZone');
      modal.querySelector('#sendEmailForm').addEventListener('submit', e => e.preventDefault());
      modal.addEventListener('input', () => { dirty = true; });
      const starters = {
        intro: ['Les besoins en formation de ' + modal.dataset.company, 'Je me permets de vous contacter au nom de ' + modal.dataset.sender + ' pour échanger sur les besoins en formation de vos équipes.\n\nQuels sont vos projets et vos priorités pour les prochains mois ? Je serais ravi de vous proposer un accompagnement adapté.\n\nÊtes-vous la bonne personne pour en discuter ? Sinon, pourriez-vous m’indiquer votre interlocuteur formation ou RH ?\n\nBien cordialement,\n' + modal.dataset.sender],
        followup: ['Suite à mon précédent message — formation de vos équipes', 'Je reviens vers vous à la suite de mon précédent message concernant les besoins en formation de vos équipes.\n\nLe sujet est-il d’actualité pour vous ? Un court échange nous permettrait de voir si nous pouvons vous aider. Si le moment n’est pas opportun, n’hésitez pas à me l’indiquer.\n\nAu plaisir de vous lire,\n' + modal.dataset.sender],
        meeting: ['Un échange sur vos projets de formation ?', 'Je vous propose un échange de 15 minutes pour comprendre vos objectifs, les profils à former et vos contraintes de calendrier.\n\nQuelles seraient vos disponibilités la semaine prochaine ?\n\nBien cordialement,\n' + modal.dataset.sender],
        proposal: ['Votre projet de formation — point sur notre proposition', 'Avez-vous pu prendre connaissance de notre proposition de formation ?\n\nJe reste disponible pour répondre à vos questions ou adapter le contenu, le calendrier et les modalités à vos besoins.\n\nQuelle serait la prochaine étape pour avancer ensemble ?\n\nBien cordialement,\n' + modal.dataset.sender]
      };
      modal.querySelector('#crmEmailStarter')?.addEventListener('change', event => {
        const values = starters[event.target.value];
        if (!values) return;
        if (message.value.trim() && !window.confirm('Remplacer le message en cours par ce modèle de départ ?')) { if (event.target.tomselect) event.target.tomselect.setValue('', true); else event.target.value = ''; return; }
        if (template.tomselect) template.tomselect.setValue('', true); else template.value = '';
        subject.value = values[0]; message.value = values[1]; dirty = true; updateCount();
        schedulePreview(0);
      });
      const payload = () => new URLSearchParams({templateId: template.value || '0', subject: subject.value, message: message.value, to: input('toEmail').value, idemKey: input('idemKey').value, _token: modal.querySelector('input[name="_token"]').value, followupDays: modal.querySelector('#crmEmailFollowup')?.value || '0'});
      async function post(url, body, signal) {
        const response = await fetch(url, {method:'POST', body, signal, headers:{'X-Requested-With':'XMLHttpRequest'}});
        const result = await response.json().catch(() => null);
        if (!response.ok || !result?.ok) throw new Error(result?.error || 'La requête a échoué. Vérifiez votre connexion et votre session.');
        return result;
      }
      function cancelPreview() {
        clearTimeout(previewTimer);
        previewRequest?.abort();
        ++previewVersion;
      }
      function schedulePreview(delay = 450) {
        cancelPreview();
        if (previewClosed || sending || sent) return;
        previewState.textContent = 'Actualisation…';
        previewZone.setAttribute('aria-busy', 'true');
        preview.hidden = true;
        const version = previewVersion;
        previewTimer = setTimeout(async () => {
          previewRequest = new AbortController();
          try {
            const result = await post(modal.dataset.previewUrl, payload(), previewRequest.signal);
            if (version !== previewVersion || previewClosed) return;
            previewZone.innerHTML = result.html;
            previewState.textContent = 'Aperçu à jour';
          } catch (error) {
            if (version !== previewVersion || previewClosed || error.name === 'AbortError') return;
            previewState.textContent = 'Aperçu indisponible · réessayez';
            preview.hidden = false;
            preview.title = error.message;
          } finally {
            if (version === previewVersion) previewZone.setAttribute('aria-busy', 'false');
          }
        }, delay);
      }
      [subject, message, input('toEmail')].forEach(field => {
        field.addEventListener('input', () => schedulePreview());
        field.addEventListener('change', () => schedulePreview());
      });
      template.addEventListener('change', () => { dirty = true; schedulePreview(0); });
      preview.addEventListener('click', () => schedulePreview(0));
      send.addEventListener('click', async () => {
        if (sending || sent) return;
        if (!input('toEmail').checkValidity() || !subject.value.trim() || (!template.value && !message.value.trim())) { status.textContent = 'Renseignez un destinataire valide, un objet et un message ou un modèle.'; return; }
        sending = true; send.disabled = true; preview.disabled = true;
        cancelPreview(); previewZone.setAttribute('aria-busy', 'false');
        status.textContent = 'Envoi en cours…';
        // Freeze the composed content until the transport returns.
        const body = payload();
        const fields = [...modal.querySelectorAll('input, textarea, select')];
        fields.forEach(field => { field.disabled = true; });
        emailSelects.forEach(select => select.disable());
        try {
          await post(modal.dataset.sendUrl, body);
          sent = true; dirty = false; status.textContent = 'E-mail envoyé. L’historique et la prochaine relance sont à jour.';
          send.textContent = 'Envoyé';
        } catch(error) { status.textContent = error.message; fields.forEach(field => { field.disabled = false; }); emailSelects.forEach(select => select.enable()); }
        finally { sending = false; send.disabled = sent; preview.disabled = false; if (!sent) schedulePreview(0); }
      });
      modal.addEventListener('hide.bs.modal', event => {
        if (sending || (dirty && !sent && !window.confirm('Fermer et abandonner ce message non envoyé ?'))) { event.preventDefault(); return; }
        previewClosed = true; cancelPreview();
      });
      modal.addEventListener('hidden.bs.modal', () => { emailSelects.forEach(select => select.destroy()); instance.dispose(); host.innerHTML = ''; if (sent) window.location.reload(); }, {once:true});
      instance.show();
      schedulePreview(0);
    } catch (error) { feedback(error.message); }
    finally { opening = false; }
  };
  document.addEventListener('click', event => {
    const button = event.target.closest('[data-email-url]');
    if (button && !button.disabled) window.openProspectEmail(button.dataset.emailUrl);
  });
})();
