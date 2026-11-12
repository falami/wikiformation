// Run with Playwright available on NODE_PATH; CHROME_PATH may select system Chrome.
const {test} = require('node:test');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const {mkdtempSync, rmSync} = require('node:fs');
const {tmpdir} = require('node:os');
const path = require('node:path');
const {pathToFileURL} = require('node:url');
const {chromium} = require('playwright');

test('CACES exports two complete A5 faces for single, all-category and long-name cards', async () => {
  const root = path.resolve(__dirname, '../..');
  const temp = mkdtempSync(path.join(tmpdir(), 'caces-print-'));
  const browser = await chromium.launch({
    headless: true, ...(process.env.CHROME_PATH ? {executablePath: process.env.CHROME_PATH} : {})
  });
  try {
    const page = await browser.newPage({viewport: {width: 1200, height: 900}});
    for (const scenario of ['single', 'all', 'long']) {
      const file = path.join(temp, scenario + '.html');
      execFileSync('php', [path.join(root, 'tests/Fixtures/render-caces-card.php'), file, scenario]);
      await page.goto(pathToFileURL(file).href);
      await page.evaluate(() => document.fonts.ready);
      await page.emulateMedia({media: 'screen'});
      for (const width of [320, 375, 427, 768, 1200]) {
        await page.setViewportSize({width, height: 952});
        const layout = await page.evaluate(() => ({
          width: document.documentElement.clientWidth,
          scrollWidth: document.documentElement.scrollWidth,
          faces: [...document.querySelectorAll('.cc-page')].map(el => {
            const r = el.getBoundingClientRect(); return {left: r.left, right: r.right};
          })
        }));
        assert.ok(layout.scrollWidth <= layout.width, scenario + ': no horizontal page scroll at ' + width);
        for (const face of layout.faces) {
          assert.ok(face.left >= 0 && face.right <= width, 'entire face visible at ' + width);
        }
      }
      await page.emulateMedia({media: 'print'});
      assert.equal(await page.locator('.cc-page').count(), 2);
      const overflow = await page.locator('.cc-page').evaluateAll(pages => pages.flatMap(face =>
        [...face.querySelectorAll('*')].filter(el => !el.classList.contains('cc-sr-only') &&
          el.clientHeight > 0 && el.scrollHeight > el.clientHeight + 2).map(el => el.className)
      ));
      assert.deepEqual(overflow, [], scenario + ': no clipped or overflowing contents');
      const dimensions = await page.locator('.cc-page').evaluateAll(pages => pages.map(p => {
        const r = p.getBoundingClientRect(); return [r.width, r.height];
      }));
      for (const [width, height] of dimensions) {
        assert.ok(Math.abs(width - 198 * 96 / 25.4) < 1);
        assert.ok(Math.abs(height - 135 * 96 / 25.4) < 1);
      }
      const pdf = await page.pdf({preferCSSPageSize: true, printBackground: true, displayHeaderFooter: false});
      assert.equal((pdf.toString('latin1').match(/\/Type\s*\/Page\b/g) || []).length, 2, scenario + ': exactly two PDF page objects');
      assert.equal(await page.locator('img').evaluateAll(images => images.every(i => i.complete && i.naturalWidth > 0)), true);
    }
  } finally {
    await browser.close();
    rmSync(temp, {recursive: true, force: true});
  }
});
