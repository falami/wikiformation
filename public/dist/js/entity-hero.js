/* Resolve both foregrounds from the actual tenant colours, including live previews. */
(function () {
  function luminance(rgb) {
    return rgb.map(v => { v /= 255; return v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4; })
      .reduce((sum, v, i) => sum + v * [.2126, .7152, .0722][i], 0);
  }
  function ink(rgb) { return luminance(rgb) > .179 ? '#000000' : '#ffffff'; }
  function update() {
    const probe = document.createElement('span');
    probe.style.cssText = 'position:absolute;visibility:hidden';
    document.body.append(probe);
    function resolve(variable, fallback) {
      probe.style.color = 'var(' + variable + ',' + fallback + ')';
      const values = getComputedStyle(probe).color.match(/[\d.]+/g);
      return values ? values.slice(0, 3).map(Number) : [35, 51, 66];
    }
    const primary = resolve('--theme-color-principal', '#233342');
    const secondary = resolve('--theme-color-secondaire', '#ffc107');
    probe.remove();
    const style = document.documentElement.style;
    style.setProperty('--wf-hero-ink', ink(primary));
    style.setProperty('--wf-accent-ink', ink(secondary));
    const a = luminance(primary), b = luminance(secondary);
    const contrast = (Math.max(a, b) + .05) / (Math.min(a, b) + .05);
    style.setProperty('--wf-hero-accent', contrast >= 4.5 ? 'var(--theme-color-secondaire,#ffc107)' : ink(primary));
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', update);
  else update();
  document.addEventListener('theme:change', update);
  document.addEventListener('turbo:load', update);
})();
