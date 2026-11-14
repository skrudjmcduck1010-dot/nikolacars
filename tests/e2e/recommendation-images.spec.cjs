const { test, expect } = require('@playwright/test');

for (const locale of ['uk', 'ru']) {
  for (const profile of ['available', 'sold']) {
    test(`recommendation image candidates ${locale} ${profile}`, async ({ page, context, baseURL }, testInfo) => {
      const errors = [];
      const images = [];
      page.on('pageerror', error => errors.push(error.message));
      page.on('response', response => {
        if (response.request().resourceType() === 'image') images.push(response);
      });
      await context.setExtraHTTPHeaders({ 'X-Recommendation-E2E': profile });
      await context.route('**/*', route => {
        const request = route.request();
        if (request.method() !== 'GET' || new URL(request.url()).origin !== new URL(baseURL).origin) return route.abort('blockedbyclient');
        return route.continue();
      });
      const prefix = locale === 'ru' ? '/ru' : '';
      const response = await page.goto(prefix + '/parts/test-001-100001/');
      expect(response.status()).toBe(200);
      const recommendations = page.locator('.product-recommendations');
      const photoLink = recommendations.locator('.part-image').first();
      await photoLink.scrollIntoViewIfNeeded();
      await page.screenshot({ path: testInfo.outputPath(process.env.NC_RECOMMENDATION_BEFORE ? 'before.png' : 'after.png') });
      if (process.env.NC_RECOMMENDATION_BEFORE) {
        expect(await recommendations.locator('img').count()).toBeGreaterThan(0);
        return;
      }

      await expect(recommendations.locator('img, picture, source')).toHaveCount(0);
      await expect(photoLink).toHaveAccessibleName(locale === 'ru' ? 'Рекомендуемая деталь' : 'Рекомендована деталь');
      await expect(photoLink).toHaveAttribute('href', prefix + '/parts/recommendation-100002/');
      const style = await photoLink.evaluate(node => {
        const css = getComputedStyle(node);
        return { image: css.backgroundImage, size: css.backgroundSize, position: css.backgroundPosition, width: node.clientWidth, height: node.clientHeight };
      });
      expect(style.image).toContain('part.svg?recommendation=1');
      expect(style.size).toBe('cover');
      expect(style.position).toBe('50% 50%');
      expect(style.width).toBeGreaterThan(50);
      expect(style.height).toBeGreaterThan(50);
      const escaped = recommendations.locator('.part-image[aria-label="Escaped image URL"]');
      const safeStyle = await escaped.evaluate(node => ({ image: getComputedStyle(node).backgroundImage, injectedColor: node.style.color, declarations: node.style.length }));
      expect(safeStyle.image).toContain('part.svg?test=');
      expect(safeStyle.injectedColor).toBe('');
      expect(safeStyle.declarations).toBe(1);
      await expect(recommendations.locator('.part-image[aria-label="No photo"]')).toHaveClass(/no-image/);

      const schemas = await page.locator('script[type="application/ld+json"]').evaluateAll(nodes => nodes.map(node => JSON.parse(node.textContent)));
      const product = schemas.find(schema => schema['@type'] === 'Product');
      const webpage = schemas.find(schema => schema['@type'] === 'WebPage');
      expect(product.image[0]).toBe('/__cart_e2e/part.svg');
      expect(webpage.primaryImageOfPage).toBe(product.image[0]);
      await expect(page.locator('meta[property="og:image"]')).toHaveAttribute('content', product.image[0]);
      await expect(page.locator('[data-product-main-image]')).toHaveAttribute('src', product.image[0]);
      await page.locator('[data-gallery-open]').click();
      await expect(page.locator('[data-product-lightbox]')).toBeVisible();
      if (profile === 'available') {
        await page.locator('[data-lightbox-next]').click();
        await expect(page.locator('[data-lightbox-image]')).toHaveAttribute('src', /gallery=2/);
      }
      await page.keyboard.press('Escape');
      await expect(page.locator('[data-product-lightbox]')).toBeHidden();
      if (profile === 'available') {
        await recommendations.locator('[data-recommendation-add]').first().click();
        await expect(page.locator('[data-basket-modal]')).toBeVisible();
        await expect(page.locator('.basket-item')).toHaveCount(1);
        await page.keyboard.press('Escape');
      }
      await photoLink.click();
      await expect(page).toHaveURL(baseURL + prefix + '/parts/recommendation-100002/');
      await page.goto(prefix + '/parts/');
      await expect(page.locator('.parts-products .part-image img').first()).toBeVisible();
      expect(errors).toEqual([]);
      const imageReads = await Promise.all(images.map(async image => ({ url: image.url(), status: image.status(), bytes: (await image.body()).length })));
      expect(imageReads.filter(image => image.url.includes('recommendation=1')).every(image => image.status === 200)).toBe(true);
      await testInfo.attach('image-reads.json', { body: JSON.stringify(imageReads, null, 2), contentType: 'application/json' });
    });
  }
}
