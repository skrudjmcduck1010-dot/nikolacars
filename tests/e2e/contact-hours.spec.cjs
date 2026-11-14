const { test, expect } = require('@playwright/test');

// Visitors outside Ukraine must still see the department's Kyiv working hours.
test.use({ timezoneId: 'America/Los_Angeles' });

for (const locale of ['uk', 'ru']) {
  test('contact online hours and open-page transitions ' + locale, async ({ page, context, baseURL }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await context.route('**/*', route => {
      const request = route.request();
      if (request.method() !== 'GET' || new URL(request.url()).origin !== new URL(baseURL).origin) return route.abort('blockedbyclient');
      return route.continue();
    });
    await page.clock.install({ time: new Date('2026-10-30T07:59:59.500Z') });
    await page.clock.pauseAt(new Date('2026-10-30T07:59:59.500Z'));
    expect((await page.goto((locale === 'ru' ? '/ru' : '') + '/parts/')).status()).toBe(200);
    const online = page.locator('#partsContactOnline');
    const toggle = page.locator('[data-parts-contact-toggle]');
    await expect(online).toBeHidden();
    await expect(toggle).not.toHaveAttribute('aria-describedby', 'partsContactOnline');
    await page.clock.fastForward(1000);
    await expect(online).toBeVisible();
    await expect(online).toHaveText(locale === 'ru' ? 'Мы онлайн' : 'Ми онлайн');
    await expect(toggle).toHaveAttribute('aria-describedby', 'partsContactOnline');

    const cases = [
      ['2026-10-30T15:59:59Z', true], // Friday 17:59 Kyiv, winter time
      ['2026-10-30T16:00:00Z', false],
      ['2026-10-31T10:00:00Z', false], // Saturday
      ['2026-11-01T10:00:00Z', false], // Sunday
      ['2026-11-02T08:00:00Z', true],  // Monday 10:00
      ['2026-07-03T06:59:59Z', false], // Summer time (UTC+3)
      ['2026-07-03T07:00:00Z', true],
      ['2026-07-03T15:00:00Z', false],
    ];
    for (const [time, visible] of cases) {
      await page.clock.setSystemTime(new Date(time));
      await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
      await expect(online).toBeVisible({ visible });
    }
    await page.clock.setSystemTime(new Date('2026-10-30T15:59:59.500Z'));
    await page.evaluate(() => window.dispatchEvent(new Event('pageshow')));
    await expect(online).toBeVisible();
    await page.clock.fastForward(61000);
    await expect(online).toBeHidden();
    await toggle.click();
    await expect(page.locator('#partsContactPanel')).toBeVisible();
    await page.locator('[data-parts-callback-open]').click();
    await expect(page.locator('#partsCallbackPanel')).toBeVisible();
    expect(errors).toEqual([]);
  });
}
