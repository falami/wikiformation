/* Uses the shared checkbox filter bridge; date fields keep their ISO Ajax values. */
(() => {
  function boot() {
    document.querySelectorAll('.trainer-filters').forEach(panel => {
      const summary = panel.querySelector('.trainer-filter-summary');
      const dates = [...panel.querySelectorAll('input[type="date"]')];
      const labels = new Map(dates.map(input => [input,input.labels?.[0]?.textContent || 'Date']));
      function summarize() {
        if (!summary) return;
        const active = [...panel.querySelectorAll('select[data-wf-multi]')].filter(s => s.value && s.value !== 'all').map(s => `${s.labels?.[0]?.textContent || 'Filtre'} : ${s.selectedOptions[0]?.textContent || 'Aucun'}`);
        dates.forEach(input => { if(input.value) active.push(`${labels.get(input)} : ${input.value.split('-').reverse().join('/')}`); });
        summary.hidden = active.length === 0;
        summary.textContent = active.length ? 'Filtres actifs : ' + active.join(' · ') : 'Aucun filtre actif · Tous les résultats';
      }
      if (window.flatpickr) dates.forEach(input => {
        const picker = flatpickr(input,{locale:'fr',dateFormat:'Y-m-d',altInput:true,altFormat:'d/m/Y',disableMobile:true,allowInput:true,
          onReady: (_,__,fp) => {
            fp.calendarContainer.classList.add('trainer-filter-calendar');
            fp.altInput.placeholder='Choisir une date';
            fp.altInput.setAttribute('aria-label',labels.get(input));
            const label = panel.querySelector(`label[for="${input.id}"]`);
            fp.altInput.id=input.id+'-display'; if(label) label.htmlFor=fp.altInput.id;
            const clear=document.createElement('button'); clear.type='button'; clear.className='btn btn-light w-100';clear.textContent='Effacer la date';clear.addEventListener('click',()=>{fp.clear();fp.close();});fp.calendarContainer.append(clear);
          },onChange:summarize});
        input.addEventListener('change',summarize);
      });
      panel.addEventListener('change',summarize);
      panel.querySelector('#btnReset')?.addEventListener('click',()=>setTimeout(()=>{dates.forEach(input=>input._flatpickr?.clear(false));summarize();},0));
      summarize();
    });
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',boot,{once:true}); else boot();
})();
