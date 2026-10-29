const { test: base, expect } = require('@playwright/test');

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
    expect(writes, 'Compatibility reads must never submit callbacks, orders or events.').toEqual([]);
    expect(errors, 'Real page initialization must have no uncaught JavaScript errors.').toEqual([]);
  },
});

for (const locale of ['uk', 'ru']) {
  const prefix = locale === 'ru' ? '/ru' : '';
  test.describe('runtime compatibility ' + locale, () => {
    for (const pagePath of ['/', '/services/tesla-service/']) {
      test('renders actual HTTP ' + pagePath + ' with localized navigation', async ({ page }) => {
        const response = await page.goto(prefix + pagePath);
        expect(response.status()).toBe(200);
        await expect(page.locator('h1')).toHaveCount(1);
        await expect(page.locator('h1')).toContainText('Tesla');
        await expect(page.locator('[data-header-cart]')).toHaveAttribute('href', prefix + '/parts/?cart=open');
        await expect(page.locator('.lang .pill[aria-current="page"]')).toHaveText(locale === 'ru' ? 'RU' : 'UA');
        await expect(page.locator('form[action$="/lead/callback"]').first()).toBeAttached();
        await expect(page.locator('body')).not.toContainText('Stack trace');
      });
    }

    test('renders a routed product, valid JSON-LD and local basket edits', async ({ page }) => {
      const response = await page.goto(prefix + '/parts/test-001-100001/');
      expect(response.status()).toBe(200);
      await expect(page.locator('h1')).toHaveCount(1);
      await expect(page.locator('h1')).toContainText(locale === 'ru' ? 'Совместимая тестовая запчасть' : 'Сумісна тестова запчастина');
      await expect(page.locator('#delivery-and-returns')).toBeVisible();
      const schemas = await page.locator('script[type="application/ld+json"]').evaluateAll(nodes => nodes.map(node => JSON.parse(node.textContent)));
      expect(schemas.some(schema => schema['@type'] === 'Product' || schema['@graph']?.some(node => node['@type'] === 'Product'))).toBe(true);
      await page.locator('[data-product-add]').click();
      const modal = page.locator('[data-basket-modal]');
      await expect(modal).toBeVisible();
      await expect(modal.locator('.basket-item')).toHaveCount(1);
      await expect(page.locator('[data-header-cart-count]')).toHaveText('1');
      await page.keyboard.press('Escape');
      await page.locator('[data-header-cart]').click();
      await expect(modal).toBeVisible();
    });

    test('missing product goes through Laravel and remains HTTP404', async ({ page }) => {
      const response = await page.goto(prefix + '/parts/missing-fixture-999999/');
      expect(response.status()).toBe(404);
      await expect(page.locator('[data-product-add]')).toHaveCount(0);
      await expect(page.locator('body')).not.toContainText('Stack trace');
    });
  });
}
