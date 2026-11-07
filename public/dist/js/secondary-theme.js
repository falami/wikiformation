/* Pick readable foreground text for both light and dark custom accents. */
(() => {
  function updateAccentContrast() {
    const probe = document.createElement('span');
    probe.style.cssText = 'position:absolute;visibility:hidden;color:var(--theme-color-secondaire,#ffc107)';
    document.body.append(probe);
    const channels = getComputedStyle(probe).color.match(/[\d.]+/g);
    probe.remove();
    if (!channels || channels.length < 3) return;
    const rgb = channels.slice(0, 3).map(value => {
      const c = Number(value) / 255;
      return c <= .04045 ? c / 12.92 : ((c + .055) / 1.055) ** 2.4;
    });
    const luminance = .2126 * rgb[0] + .7152 * rgb[1] + .0722 * rgb[2];
    document.documentElement.style.setProperty('--wf-accent-ink', luminance > .179 ? '#000000' : '#ffffff');
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', updateAccentContrast);
  else updateAccentContrast();
  document.addEventListener('theme:change', updateAccentContrast);
})();
