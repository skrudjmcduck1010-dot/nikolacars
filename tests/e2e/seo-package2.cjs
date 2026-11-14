const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('C:/Users/user/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');

// Usage: node tests/e2e/seo-package2.cjs <fixtures.json>. All network is intercepted.
(async () => {
  const fixturesPath = path.resolve(process.argv[2]);
  const fixtures = JSON.parse(fs.readFileSync(fixturesPath, 'utf8'));
  const root = path.resolve(__dirname, '../..');
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  const results = [];
  try {
    for (const f of fixtures) for (const width of [390, 1440]) {
      const context = await browser.newContext({viewport: {width, height: 900}});
      const page = await context.newPage();
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      await page.route('**/*', async route => {
        const request = route.request(), url = new URL(request.url());
        if (request.method() !== 'GET') throw new Error('Mutation forbidden in SEO fixture');
        if (url.origin === 'https://nikolacars.kiev.ua' && url.pathname === f.path) return route.fulfill({status: 200, contentType: 'text/html; charset=utf-8', body: fs.readFileSync(f.html)});
        if (url.origin === 'https://nikolacars.kiev.ua' && /\/parts\/api\/catalog\/?$/.test(url.pathname)) {
          const payload = structuredClone(f.payload);
          payload.pagination.page = Number(url.searchParams.get('page') || 1);
          return route.fulfill({status: 200, contentType: 'application/json', body: JSON.stringify(payload)});
        }
        if (url.origin === 'https://nikolacars.kiev.ua' && url.pathname.startsWith('/assets/')) {
          const file = path.resolve(root, 'public', '.' + url.pathname);
          if (file.startsWith(path.join(root, 'public') + path.sep) && fs.existsSync(file)) {
            const ext = path.extname(file);
            return route.fulfill({status: 200, contentType: ({'.js':'application/javascript','.css':'text/css','.svg':'image/svg+xml','.png':'image/png','.jpg':'image/jpeg','.webp':'image/webp','.woff2':'font/woff2'})[ext] || 'application/octet-stream', body: fs.readFileSync(file)});
          }
        }
        if (url.pathname === '/__cart_e2e/part.svg') return route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="360" height="300"><rect width="360" height="300" fill="#ddd"/><text x="40" y="140">Synthetic fixture</text></svg>'});
        return route.fulfill({status: 200, body: ''});
      });
      await page.goto('https://nikolacars.kiev.ua' + f.path, {waitUntil: 'networkidle'});
      if (await page.locator('h1').count() !== 1 || (await page.locator('h1').innerText()).trim() !== f.h1) throw new Error(`${f.id} ${width}: H1 after JS`);
      if (!(await page.title()).includes(f.h1)) throw new Error(`${f.id}: title after JS`);
      if (await page.locator('[data-model-intro], [data-current-context]').count()) throw new Error(`${f.id}: generic intro/tagline still rendered`);
      if (await page.locator('.parts-sidebar [data-categories]').count()!==1) throw new Error(`${f.id}: tree absent`);
      if (await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 2)) throw new Error(`${f.id}: horizontal overflow at ${width}`);
      if (width === 390 && ['0-uk','3-ru'].includes(f.id)) await page.screenshot({path:path.join(path.dirname(fixturesPath),`${f.id}-mobile.png`),fullPage:true});
      await Promise.all([page.waitForResponse(response=>response.url().includes('/parts/api/catalog/')),page.locator('[data-sort]').selectOption('price_asc')]);
      await page.waitForFunction(() => !document.querySelector('[data-products]').classList.contains('loading'));
      if (await page.locator('[data-model-intro], [data-current-context]').count()) throw new Error(`${f.id}: generic intro/tagline restored after AJAX`);
      if (await page.locator('.parts-sidebar [data-categories]').count()!==1) throw new Error(`${f.id}: tree absent after AJAX`);
      if ((await page.locator('h1').innerText()).trim() !== f.h1 || !(await page.title()).includes(f.h1)) throw new Error(`${f.id}: category heading lost after AJAX`);
      await page.locator('[data-add-cart="1001"]').click();
      await page.waitForFunction(() => JSON.parse(localStorage.getItem('nikolacars-parts-cart-v1') || '[]').some(item => item.id === 1001 && item.quantity === 1));
      if (!(await page.locator('[data-cart-drawer]').isVisible())) throw new Error(`${f.id}: cart drawer did not open`);
      if (errors.length) throw new Error(`${f.id}: ${errors.join('; ')}`);
      results.push({id:f.id,width,status:'PASS',checks:['heading/title after JS','no generic SSR/AJAX guidance','tree retained','viewport','AJAX sort','cart count','no page errors']});
      await context.close();
    }
    fs.writeFileSync(path.join(path.dirname(fixturesPath),'browser-result.json'),JSON.stringify({status:'PASS',cases:results.length,results},null,2));
    console.log(`PASS ${results.length} browser cases: ${fixtures.length} localized landings × mobile/desktop; no guidance, AJAX and cart retained.`);
  } finally { await browser.close(); }
})().catch(error => { console.error(error.stack); process.exit(1); });
