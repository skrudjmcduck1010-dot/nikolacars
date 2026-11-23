const { test, expect } = require('@playwright/test');

async function setup(page, context, baseURL, respond) {
  const requests = [];
  page.on('dialog', dialog => dialog.dismiss());
  await context.route('**/*', route => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.origin !== new URL(baseURL).origin) return route.abort('blockedbyclient');
    if (request.method() === 'GET') return route.continue();
    if (url.pathname !== '/lead/callback') return route.abort();
    const id = /name="request_id"\r\n\r\n([^\r\n]+)/.exec(request.postData() || '')?.[1];
    requests.push({ id, body: request.postData() });
    return respond(route, requests.length, id);
  });
  return requests;
}
async function fill(page, name = 'Synthetic visitor') {
  await page.locator('#callForm input[name="name"]').fill(name);
  await page.locator('#phoneInput').fill('500000000');
}
const events = page => page.evaluate(() => window.dataLayer.filter(x => x[0] === 'event').map(x => ({ name: x[1], params: x[2] })));

for (const locale of ['uk', 'ru']) {
  test('STO retry after reload keeps identity and emits each event once ' + locale, async ({ page, context, baseURL }) => {
    const requests = await setup(page, context, baseURL, (route, count, id) => count === 1 ? route.abort()
      : route.fulfill({ json: { ok: true, request_id: id, crm_saved: true, business_unit: 'sto' } }));
    const path = (locale === 'ru' ? '/ru' : '') + '/services/tesla-service/';
    await page.goto(path); await fill(page);
    await page.locator('#callForm button[type="submit"]').click();
    await expect.poll(() => requests.length).toBe(1);
    await expect(page.locator('#callForm button[type="submit"]')).toBeEnabled();
    await page.reload(); await fill(page);
    await page.locator('#callForm button[type="submit"]').click();
    await expect(page.locator('#callForm')).toHaveClass(/is-success/);
    expect(requests[0].id).toMatch(/^[a-f0-9-]{36}$/);
    expect(requests[1].id).toBe(requests[0].id);
    expect((await events(page)).map(x => x.name)).toEqual(['generate_lead', 'sto_generate_lead']);
    await page.reload();
    await page.evaluate(id => window.ncAnalytics.lead('service_callback', { request_id: id, crm_saved: true, business_unit: 'sto' }), requests[0].id);
    expect(await events(page)).toEqual([]);
    const stored = await page.evaluate(() => JSON.stringify({ ...sessionStorage }));
    expect(stored).not.toContain('Synthetic visitor');
    expect(stored).not.toContain('500000000');
  });
}

test('fallback success retains legacy goal without CRM-only STO event', async ({ page, context, baseURL }) => {
  await setup(page, context, baseURL, (route, count, id) => route.fulfill({ json: { ok: true, request_id: id, crm_saved: false, business_unit: 'sto' } }));
  await page.goto('/services/tesla-service/'); await fill(page);
  await page.locator('#callForm button[type="submit"]').click();
  await expect(page.locator('#callForm')).toHaveClass(/is-success/);
  expect((await events(page)).map(x => x.name)).toEqual(['generate_lead']);
});

test('edited payload after validation error receives new identity', async ({ page, context, baseURL }) => {
  const requests = await setup(page, context, baseURL, (route, count, id) => route.fulfill({ status: count === 1 ? 422 : 200,
    json: count === 1 ? { ok: false } : { ok: true, request_id: id, crm_saved: true, business_unit: 'sto' } }));
  await page.goto('/services/tesla-service/'); await fill(page);
  await page.locator('#callForm button[type="submit"]').click();
  await expect(page.locator('#callForm button[type="submit"]')).toBeEnabled();
  await fill(page, 'Changed synthetic visitor');
  await page.locator('#callForm button[type="submit"]').click();
  await expect(page.locator('#callForm')).toHaveClass(/is-success/);
  expect(requests[1].id).not.toBe(requests[0].id);
});

test('parts, import and general never emit STO goal; cart edits form new intent', async ({ page, context, baseURL }) => {
  await setup(page, context, baseURL, route => route.abort());
  await page.goto('/services/tesla-service/');
  const ids = await page.evaluate(async () => {
    const data = { items: [{ product_id: 100001, quantity: 1 }], phone: 'synthetic' };
    const first = await window.ncLeadRequest.requestId('parts_order', data);
    const same = await window.ncLeadRequest.requestId('parts_order', data);
    data.items[0].quantity = 2;
    const changed = await window.ncLeadRequest.requestId('parts_order', data);
    window.ncAnalytics.lead('parts_order', { request_id: first, crm_saved: true });
    window.ncAnalytics.lead('service_callback', { request_id: crypto.randomUUID(), crm_saved: true, business_unit: 'import' });
    window.ncAnalytics.lead('home_callback', { request_id: crypto.randomUUID(), crm_saved: true, business_unit: 'general' });
    return { first, same, changed };
  });
  expect(ids.same).toBe(ids.first); expect(ids.changed).not.toBe(ids.first);
  expect((await events(page)).map(x => x.name)).toEqual(['generate_lead', 'parts_generate_lead', 'generate_lead', 'generate_lead']);
});

test('missing helper preserves native submission for cached mixed assets', async ({ page, context, baseURL }) => {
  const requests = await setup(page, context, baseURL, (route, count, id) => route.fulfill({ json: { ok: true, request_id: '11111111-1111-4111-a111-111111111111', crm_saved: true, business_unit: 'sto' } }));
  await page.goto('/services/tesla-service/'); await fill(page);
  await page.evaluate(() => { delete window.ncLeadRequest; });
  await page.locator('#callForm button[type="submit"]').click();
  await expect(page.locator('#callForm')).toHaveClass(/is-success/);
  expect(requests[0].id).toBeUndefined();
});
