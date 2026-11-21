const { test, expect } = require('@playwright/test');

test('callback snapshots the cart and keeps it on retry', async ({ page, context, baseURL }) => {
  const requests = [];
  await context.route('**/*', route => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.origin !== new URL(baseURL).origin) return route.abort();
    if (request.method() === 'GET') return route.continue();
    if (url.pathname !== '/parts/api/callback') return route.abort();
    requests.push(request.postDataJSON());
    return route.fulfill({ status: requests.length === 1 ? 503 : 200, json: { ok: requests.length > 1 } });
  });
  await page.goto('/ru/parts/checkout/');
  await page.evaluate(() => localStorage.setItem('nikolacars-parts-cart-v1', JSON.stringify([
    { id: 100001, quantity: 2, name: 'Synthetic part' },
    { id: 100002, quantity: 0 },
  ])));
  await page.locator('[data-parts-contact-toggle]').click();
  await page.locator('[data-parts-callback-open]').click();
  await page.locator('#partsCallbackPhone').fill('500000000');
  const submit = page.locator('.callback-submit');
  const status = page.locator('#partsCallbackStatus');
  await submit.click();
  await expect(status).toHaveAttribute('data-error', '');
  expect(requests[0]).toMatchObject({
    page: baseURL + '/ru/parts/checkout/',
    cart: [{ product_id: 100001, quantity: 2 }],
  });

  await page.evaluate(() => localStorage.removeItem('nikolacars-parts-cart-v1'));
  await submit.click();
  await expect(status).toBeVisible();
  await expect(status).not.toHaveAttribute('data-error', '');
  expect(requests[1]).toEqual(requests[0]);

  await page.locator('#partsCallbackPhone').fill('500000000');
  await submit.click();
  await expect.poll(() => requests.length).toBe(3);
  expect(requests[2].cart).toEqual([]);
  expect(requests[2].request_id).not.toBe(requests[0].request_id);
});
