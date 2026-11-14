const { test: base, expect } = require('@playwright/test');

const key = 'nikolacars-parts-cart-v1';
async function visit(page, url) {
  const response = await page.goto(url);
  expect(response.status(), 'The real Blade page must render successfully.').toBe(200);
}
const test = base.extend({
  page: async ({ page, context, baseURL }, use) => {
    const errors = [];
    const writes = [];
    page.on('pageerror', error => errors.push(error.message));
    await context.route('**/*', route => {
      const request = route.request();
      if (request.method() !== 'GET') {
        writes.push(request.method() + ' ' + new URL(request.url()).pathname);
        return route.abort('blockedbyclient');
      }
      if (new URL(request.url()).origin !== new URL(baseURL).origin) return route.abort('blockedbyclient');
      return route.continue();
    });
    await use(page);
    expect(writes, 'Cart editing/navigation must never submit a real order or external event.').toEqual([]);
    expect(errors, 'Browser startup and cart edits must have no uncaught JavaScript errors.').toEqual([]);
  },
});

test('isolated server refuses order submissions, private files and other hosts', async ({ request }) => {
  const write = await request.post('/parts/api/orders/', { data: { request_id: 'synthetic-never-submitted' } });
  expect(write.status()).toBe(405);
  const privateFile = await request.get('/.env');
  expect(privateFile.status()).toBe(404);
  const otherHost = await request.get('/__cart_e2e/health', { headers: { Host: 'not-the-test-host.invalid' } });
  expect(otherHost.status()).toBe(403);
});

for (const locale of ['uk', 'ru']) {
  const catalog = locale === 'ru' ? '/ru/parts/' : '/parts/';
  for (const markup of ['header-only', 'legacy']) {
    test.describe(`${locale} ${markup}`, () => {
      test.use({ extraHTTPHeaders: { 'X-Cart-E2E-Markup': markup } });

      test('opens and closes the empty basket from the header', async ({ page }) => {
        await visit(page, catalog);
        await expect(page.locator('[data-cart-count]')).toHaveCount(markup === 'legacy' ? 1 : 0);
        await expect(page.locator('[data-header-cart-count]')).toBeHidden();
        await expect(page.locator('[data-header-cart]')).toBeVisible();
        await page.locator('[data-header-cart]').click();
        const modal = page.locator('[data-basket-modal]');
        await expect(modal).toBeVisible();
        await expect(modal.locator('[data-basket-empty]')).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(modal).not.toBeVisible();
        await expect(page.locator('[data-header-cart]')).toBeFocused();
      });

      test('adds once, reopens and removes the product', async ({ page }) => {
        await visit(page, catalog);
        await expect(page.locator('[data-add-cart="1002"],[data-add-cart="1003"]')).toHaveCount(0);
        await page.locator('[data-add-cart="1001"]').click();
        const modal = page.locator('[data-basket-modal]');
        await expect(modal).toBeVisible();
        await expect(modal.locator('.basket-item')).toHaveCount(1);
        await expect(page.locator('[data-header-cart-count]')).toHaveText('1');
        await page.keyboard.press('Escape');
        await page.locator('[data-add-cart="1001"]').click();
        await expect(modal.locator('.basket-item')).toHaveCount(1);
        await modal.locator('[data-basket-remove="1001"]').click();
        await expect(modal.locator('[data-basket-empty]')).toBeVisible();
        await expect(page.locator('[data-header-cart-count]')).toBeHidden();
        await expect.poll(() => page.evaluate(k => JSON.parse(localStorage.getItem(k)), key)).toEqual([]);
        await expect(page.locator('[data-add-cart="1001"]')).not.toHaveClass(/added/);
      });

      test('limits quantity to stock and keeps totals and storage in sync after reload', async ({ page }, testInfo) => {
        await visit(page, catalog);
        await page.locator('[data-add-cart="1001"]').click();
        const modal = page.locator('[data-basket-modal]');
        const plus = modal.locator('[data-basket-plus="1001"]');
        const minus = modal.locator('[data-basket-minus="1001"]');
        await expect(minus).toBeDisabled();
        await plus.click();
        await plus.click();
        await expect(plus).toBeDisabled();
        await expect(modal.locator('output')).toHaveText('3');
        await expect(modal.locator('[data-basket-total]')).toHaveText('375 грн');
        await expect(page.locator('[data-header-cart-count]')).toHaveText('3');
        if (markup === 'legacy') await expect(page.locator('[data-cart-count]')).toHaveText('3');
        await expect.poll(() => page.evaluate(k => JSON.parse(localStorage.getItem(k))[0].quantity, key)).toBe(3);
        if (locale === 'uk' && markup === 'header-only') {
          await page.screenshot({ path: testInfo.outputPath('basket-quantity.png') });
        }
        await minus.click();
        await expect(modal.locator('[data-basket-total]')).toHaveText('250 грн');
        await page.reload();
        await page.locator('[data-header-cart]').click();
        await expect(modal.locator('output')).toHaveText('2');
        await expect(page.locator('[data-header-cart-count]')).toHaveText('2');
      });

      test('keeps the header link fallback and reaches checkout without submitting', async ({ page }) => {
        await visit(page, `/__cart_e2e/no-modal/?locale=${locale}`);
        await page.locator('[data-header-cart]').click();
        await expect(page).toHaveURL(new RegExp(`${catalog}#cart$`));
        const modal = page.locator('[data-basket-modal]');
        await expect(modal).toBeVisible();
        await page.keyboard.press('Escape');
        await page.locator('[data-add-cart="1001"]').click();
        await modal.locator('[data-basket-checkout]').click();
        await expect(page).toHaveURL(new RegExp(`${catalog}checkout/$`));
        await expect(page.locator('[data-checkout-items]')).toContainText('125');
        await expect(page.locator('[data-page-checkout]')).toBeVisible();
        await page.locator('[data-header-cart]').click();
        await expect(modal).toBeVisible();
        await modal.locator('[data-basket-plus="1001"]').click();
        await expect(page.locator('[data-checkout-total]')).toHaveText('250 грн');
        await modal.locator('[data-basket-checkout]').click();
        await expect(modal).not.toBeVisible();
        await expect(page).toHaveURL(new RegExp(`${catalog}checkout/$`));
      });
    });
  }
}
