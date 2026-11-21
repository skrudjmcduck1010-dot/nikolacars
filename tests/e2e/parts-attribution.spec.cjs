const { test, expect } = require('@playwright/test');

test('carries a landing click into checkout and callback after navigation', async ({ page, context, baseURL }) => {
  const submissions = {};
  const attribution = { gclid: 'test-GCLID', gbraid: 'test_GBRAID', wbraid: 'test-WBRAID' };
  await context.route('**/*', async route => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.origin !== new URL(baseURL).origin) return route.abort();
    if (request.method() === 'GET') return route.continue();
    if (!['/parts/api/orders', '/parts/api/callback'].includes(url.pathname)) return route.abort();
    submissions[url.pathname] = request.postDataJSON();
    return route.fulfill({ json: { ok: true, number: 'TEST' } });
  });
  await page.goto('/parts/old-slug-100001/?' + new URLSearchParams({ ...attribution, unrelated: 'ignored' }));
  await expect(page).toHaveURL(/\/parts\/test-001-100001\/\?/);
  await page.evaluate(() => localStorage.setItem('nikolacars-parts-cart-v1', JSON.stringify([
    { id: 100001, quantity: 1, name: 'Synthetic part', price_uah: 100 },
  ])));
  await page.goto('/parts/checkout/');
  await page.locator('[name="client_first_name"]').fill('Test');
  await page.locator('[name="client_last_name"]').fill('Customer');
  await page.locator('[name="client_phone"]').fill('+380500000000');
  await page.locator('.checkout-submit').click();
  await expect.poll(() => submissions['/parts/api/orders']?.attribution).toEqual(attribution);

  await page.locator('[data-parts-contact-toggle]').click();
  await page.locator('[data-parts-callback-open]').click();
  await page.locator('#partsCallbackPhone').fill('500000000');
  await page.locator('.callback-submit').click();
  await expect.poll(() => submissions['/parts/api/callback']?.attribution).toEqual(attribution);
  expect(submissions['/parts/api/callback'].page).toBe(baseURL + '/parts/checkout/');
});
