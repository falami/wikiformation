/* Pick readable foreground text for both light and dark custom accents. */
(() => {
  function updateContrast(variable, target) {
    const probe = document.createElement('span');
    probe.style.cssText = `position:absolute;visibility:hidden;color:var(${variable})`;
    document.body.append(probe);
    const channels = getComputedStyle(probe).color.match(/[\d.]+/g);
    probe.remove();
    if (!channels || channels.length < 3) return;
    const rgb = channels.slice(0, 3).map(value => {
      const c = Number(value) / 255;
      return c <= .04045 ? c / 12.92 : ((c + .055) / 1.055) ** 2.4;
    });
    const luminance = .2126 * rgb[0] + .7152 * rgb[1] + .0722 * rgb[2];
    document.documentElement.style.setProperty(target, luminance > .179 ? '#000000' : '#ffffff');
  }
  function updateAccentContrast() {
    updateContrast('--theme-color-secondaire', '--wf-accent-ink');
    updateContrast('--theme-color-principal', '--wf-primary-ink');
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', updateAccentContrast);
  else updateAccentContrast();
  document.addEventListener('theme:change', updateAccentContrast);
})();
