/* Keep scrolling table headers aligned when the sidebar changes the card width. */
(() => {
  // Keep page-specific handlers on their original controls while sharing the layout.
  document.querySelectorAll('.catalogue-filters').forEach(panel => {
    panel.setAttribute('role', 'region');
    if (!panel.hasAttribute('aria-label')) panel.setAttribute('aria-label', 'Filtres de la liste');
    const heading = panel.querySelector('.qcm-filter-heading');
    const grid = panel.querySelector('.qcm-filter-grid');
    if (heading && grid) {
      const reset = heading.querySelector('button');
      if (reset) grid.append(reset);
      heading.hidden = true;
    }
  });
  const $ = window.jQuery;
  if (!$?.fn?.dataTable || !window.ResizeObserver) return;
  const observed = new WeakSet();

  function observe(settings) {
    const table = settings.nTable;
    const card = table.closest('.premium-list');
    if (!card || !table.classList.contains('premium-table') || observed.has(table)) return;
    observed.add(table);

    const api = new $.fn.dataTable.Api(settings);
    let previousWidth = 0;
    let frame = 0;
    const observer = new ResizeObserver(entries => {
      const width = Math.round(entries[0].contentRect.width);
      if (!width || width === previousWidth) return;
      previousWidth = width;
      cancelAnimationFrame(frame);
      frame = requestAnimationFrame(() => {
        if (table.isConnected) api.columns.adjust();
      });
    });
    observer.observe(card);
    $(table).one('destroy.dt.premiumList', () => {
      observer.disconnect();
      cancelAnimationFrame(frame);
      observed.delete(table);
    });
  }

  $(document).on('init.dt.premiumList', (_event, settings) => observe(settings));
  $.fn.dataTable.tables({api: true}).settings().toArray().forEach(observe);
})();
