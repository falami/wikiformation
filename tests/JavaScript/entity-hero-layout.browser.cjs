// Run with Playwright available via NODE_PATH and optionally CHROME_PATH.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const root = path.join(__dirname, '../..');

test('hero layout preserves controls, forms, dropdowns and repeated initialization', async () => {
  const browser = await chromium.launch({headless: true, ...(process.env.CHROME_PATH ? {executablePath: process.env.CHROME_PATH} : {})});
  try {
    const page = await browser.newPage();
    await page.setContent(`<html class="workspace-shell"><style>*{box-sizing:border-box}.btn{border:1px solid}.d-none{display:none!important}</style><div class="content">
      <form id="editor"><header class="entity-hero"><div><h1><i class="bi bi-pencil"></i>Modifier</h1><p>Description</p></div>
      <div id="actions"><button id="save" class="btn btn-sm" name="intent" value="save">Enregistrer</button>
      <div class="dropdown"><button id="menu" type="button" class="btn" data-bs-toggle="dropdown">Exporter</button><div class="dropdown-menu"><a class="dropdown-item" href="#pdf">PDF</a></div></div>
      <button type="button" class="btn d-none" id="hidden">Masqué</button></div></header><input name="title" value="Original"></form>
      <header class="entity-hero"><h1>Actions</h1><form id="delete"><input type="hidden" name="token" value="csrf"><button class="btn" type="submit">Supprimer</button></form></header>
      <header class="entity-hero"><h1>Lecture seule</h1><p>Sans action</p></header></div></html>`);
    await page.evaluate(() => {
      window.savedButton = document.querySelector('#save'); window.clicks = 0;
      document.querySelector('#actions').addEventListener('click', () => window.clicks++);
      document.querySelector('#editor').addEventListener('submit', e => { e.preventDefault(); window.submitted = e.submitter.value; });
    });
    for (const file of ['entity-hero.css', 'workspace-buttons.css']) await page.addStyleTag({content: fs.readFileSync(path.join(root, 'public/dist/css', file), 'utf8')});
    await page.addScriptTag({content: fs.readFileSync(path.join(root, 'public/dist/js/entity-hero.js'), 'utf8')});
    await page.evaluate(() => { document.dispatchEvent(new Event('turbo:load')); document.dispatchEvent(new Event('theme:change')); });
    assert.equal(await page.locator('.entity-hero-emblem').count(), 3);
    assert.equal(await page.evaluate(() => window.savedButton === document.querySelector('#save')), true);
    assert.equal(await page.locator('#save').evaluate(e => e.form.id), 'editor');
    assert.equal(await page.locator('#delete button').evaluate(e => e.form.id), 'delete');
    assert.equal(await page.locator('#delete input').inputValue(), 'csrf');
    assert.equal(await page.locator('#menu').evaluate(e => !!e.parentElement.querySelector('.dropdown-menu')), true);
    assert.equal(await page.locator('#hidden').isVisible(), false);
    await page.locator('#save').click();
    assert.deepEqual(await page.evaluate(() => [window.clicks, window.submitted]), [1, 'save']);
    for (const width of [1440, 390]) {
      await page.setViewportSize({width, height: 1000});
      const boxes = await page.locator('#save,#menu').evaluateAll(es => es.map(e => ({height:e.getBoundingClientRect().height,right:e.getBoundingClientRect().right})));
      assert.ok(boxes.every(b => b.height === 36 && b.right <= width));
    }
  } finally { await browser.close(); }
});
