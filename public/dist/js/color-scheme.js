/* Adapt legacy fixed palettes without changing their light-mode declarations.
 * Native dark pages opt out. Images, signatures, QR codes and embedded documents
 * retain their original pixels. No inversion filter is applied to the page.
 */
(() => {
  'use strict';
  const root = document.documentElement;
  const dark = matchMedia('(prefers-color-scheme: dark)');
  const forced = matchMedia('(forced-colors: active)');
  const print = matchMedia('print');
  const excluded = 'script,style,link,meta,img,video,iframe,canvas,.leaflet-container,.participant-qr-code,[data-theme-preserve],.signature-pad,.wn-brand-logo';
  let frame = 0;
  const parse = value => {
    const srgb = value.match(/^color\(srgb\s+([^)]+)\)$/);
    if (srgb) {
      const v = srgb[1].split(/[\s/]+/).map(Number);
      return [v[0] * 255, v[1] * 255, v[2] * 255, v[3] ?? 1];
    }
    const m = value.match(/^rgba?\(([^)]+)\)$/);
    if (!m) return null;
    const v = m[1].split(/[,\s/]+/).map(Number);
    return [v[0], v[1], v[2], v[3] ?? 1];
  };
  const rgb = c => `rgba(${c.slice(0, 3).map(Math.round).join(',')},${c[3] ?? 1})`;
  const luminance = c => c.slice(0, 3).reduce((sum, n, i) => {
    n /= 255;
    return sum + (n <= .04045 ? n / 12.92 : ((n + .055) / 1.055) ** 2.4) * [.2126, .7152, .0722][i];
  }, 0);
  const contrast = (a, b) => (Math.max(luminance(a), luminance(b)) + .05) / (Math.min(luminance(a), luminance(b)) + .05);
  const blend = (a, b) => a.slice(0, 3).map((v, i) => v * a[3] + b[i] * (1 - a[3])).concat(1);
  const surface = c => {
    if (!c || c[3] === 0 || luminance(c) < .3) return c;
    const max = Math.max(...c.slice(0, 3)), min = Math.min(...c.slice(0, 3));
    // Keep saturated action/status colours; soften pale surfaces with their tint.
    if (max - min > 95 && min < 160) return c;
    const elevation = min > 250 ? 6 : 0;
    return [23 + elevation + (c[0] - min) * .16, 34 + elevation + (c[1] - min) * .16, 46 + elevation + (c[2] - min) * .16, c[3]];
  };
  const readable = (c, bg) => {
    if (!c || c[3] === 0 || contrast(blend(c, bg), bg) >= 7) return c;
    const light = [237, 243, 249, 1], ink = [18, 28, 38, 1];
    const target = contrast(light, bg) >= contrast(ink, bg) ? light : ink;
    for (let t = .1; t <= 1.01; t += .1) {
      const next = c.slice(0, 3).map((n, i) => n + (target[i] - n) * t).concat(1);
      if (contrast(next, bg) >= 7) return next;
    }
    return target;
  };
  function refresh() {
    frame = 0;
    observer.disconnect();
    root.removeAttribute('data-wf-dark');
    if (!dark.matches || forced.matches || print.matches) {
      observe();
      return;
    }
    const records = [];
    const backgrounds = new WeakMap();
    // Read the original cascade in one pass before writing overrides. The root
    // switch is restored synchronously, so the browser never paints a light frame.
    for (const el of document.querySelectorAll('body,body *')) {
      if (el.closest(excluded)) continue;
      const s = getComputedStyle(el);
      if (s.display === 'none') continue;
      const parent = backgrounds.get(el.parentElement) || [17, 27, 38, 1];
      const bg = el === document.body ? [17,27,38,1] : surface(parse(s.backgroundColor));
      const painted = bg ? blend(bg, parent) : parent;
      backgrounds.set(el, painted);
      const values = {};
      if (bg) values.bg = rgb(bg);
      const color = readable(parse(s.color), painted);
      if (color) values.ink = rgb(color);
      if (s.backgroundImage.includes('gradient(') && !s.backgroundImage.includes('url(')) {
        values.image = s.backgroundImage.replace(/(?:rgba?|color)\([^)]+\)/g, v => { const c = surface(parse(v)); return c ? rgb(c) : v; });
        // Dark gradient headers retain their already-light text.
        const stops = [...s.backgroundImage.matchAll(/(?:rgba?|color)\([^)]+\)/g)].map(m => surface(parse(m[0]))).filter(Boolean);
        if (stops.length && color) values.ink = rgb(readable(parse(s.color), stops.reduce((a,b)=>luminance(a)>luminance(b)?a:b)));
      }
      for (const side of ['Top','Right','Bottom','Left']) {
        const c = parse(s[`border${side}Color`]);
        if (c && c[3] && luminance(c) > .3) values[`border-${side.toLowerCase()}`] = 'rgba(128,151,173,.38)';
      }
      if (el instanceof SVGElement) {
        for (const prop of ['fill','stroke']) {
          const c = parse(s[prop]);
          if (c) values[prop] = rgb(readable(c, painted));
        }
      }
      records.push([el, values]);
    }
    for (const [el, values] of records) {
      // Only custom properties are owned here; the application's inline styles survive.
      for (const key of ['bg','ink','image','border-top','border-right','border-bottom','border-left','fill','stroke']) {
        const property = `--wf-dark-${key}`;
        if (values[key] && el.style.getPropertyValue(property) !== values[key]) el.style.setProperty(property, values[key]);
        else if (!values[key] && el.style.getPropertyValue(property)) el.style.removeProperty(property);
      }
      if (!el.hasAttribute('data-wf-palette')) el.setAttribute('data-wf-palette', '');
    }
    root.setAttribute('data-wf-dark', '');
    observe();
  }
  const schedule = () => { if (!frame) frame = requestAnimationFrame(refresh); };
  const observer = new MutationObserver(schedule);
  function observe() {
    if (!dark.matches || forced.matches || print.matches) return;
    observer.observe(document.body, {subtree:true,childList:true,attributes:true,attributeFilter:['class','style','hidden','open','aria-expanded']});
  }
  for (const media of [dark, forced, print]) media.addEventListener('change', schedule);
  window.addEventListener('load', schedule);
  window.addEventListener('resize', schedule);
  document.addEventListener('shown.bs.modal', schedule);
  document.addEventListener('shown.bs.dropdown', schedule);
  document.addEventListener('toggle', schedule, true);
  document.addEventListener('change', schedule, true);
  refresh();
})();
