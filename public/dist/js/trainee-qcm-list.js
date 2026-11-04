(() => {
  const init = () => {
    const element = document.getElementById('table-qcm');
    const $ = window.jQuery;
    if (!element || !$?.fn?.DataTable) return;
    const date = new Date();
    document.getElementById('hero-last-refresh').textContent = 'Maj : ' + date.toLocaleDateString('fr-FR') + ' ' + date.toLocaleTimeString('fr-FR', {hour: '2-digit', minute: '2-digit'});
    const filter = document.getElementById('qcmStatusFilter');
    const table = $(element).DataTable({
      dom: '<"catalogue-search-source"f>rt<"dt-bottom-internal d-flex flex-wrap gap-2 align-items-center justify-content-between"lip>',
      order: [],
      columnDefs: [{targets: 6, orderable: false, searchable: false}],
      language: {
        url: 'https://cdn.datatables.net/plug-ins/2.2.2/i18n/fr-FR.json',
        emptyTable: 'Aucun QCM assigné pour le moment.',
        zeroRecords: 'Aucun QCM ne correspond à votre recherche.',
      },
    });
    table.search.fixed('qcm-status', (_search, _data, index) => {
      const row = table.row(index).node();
      if (filter.value === 'all') return true;
      if (filter.value === 'todo') return row.dataset.done === '0';
      if (filter.value === 'done') return row.dataset.done === '1';
      return row.dataset.status === filter.value;
    });
    filter.addEventListener('change', () => table.draw());
    document.getElementById('qcmReset').addEventListener('click', () => {
      filter.value = 'all';
      table.search('').columns().search('');
      table.order([]).draw();
    });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
