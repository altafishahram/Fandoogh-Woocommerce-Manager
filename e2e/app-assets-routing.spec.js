// @ts-check
const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const { phpResponse } = require('../tests/app-assets-contract');

// Real PHP shell/asset output with WP doubles. The test transport simulates
// Nginx rejecting virtual static paths; it is not a live WordPress install.
for (const sitePath of ['', '/shop']) {
  test(`login loads through query assets when virtual CSS paths 404 (${sitePath || 'root'})`, async ({ page, baseURL }, testInfo) => {
    const siteBase = baseURL + sitePath;
    const managerPath = sitePath + '/manager/';
    const responses = new Map();
    const assetsRequested = new Set();
    const legacyRequests = [];
    const scriptErrors = [];
    page.on('pageerror', (error) => scriptErrors.push(error.message));
    const responseFor = (mode, asset = '') => {
      const key = mode + ':' + asset;
      if (!responses.has(key)) responses.set(key, phpResponse(mode, siteBase, asset));
      return responses.get(key);
    };
    await page.route('**/*', async (route) => {
      const url = new URL(route.request().url());
      if (url.origin !== baseURL) return route.abort();
      if (url.pathname.startsWith(managerPath) && url.pathname !== managerPath) {
        legacyRequests.push(url.pathname);
        return route.fulfill({ status: 404, contentType: 'text/html', body: '<h1>404 Not Found</h1>nginx' });
      }
      if (url.pathname === managerPath) {
        const asset = url.searchParams.get('fandoogh_manager_asset');
        if (asset) assetsRequested.add(asset);
        const response = responseFor(asset ? 'asset' : 'shell', asset || '');
        return route.fulfill({ status: response.status, headers: response.headers, body: response.body });
      }
      if (url.pathname.startsWith(sitePath + '/wp-json/')) {
        if (url.pathname.endsWith('/config')) {
          const base = siteBase + '/wp-json/fandoogh-manager/v1/';
          return route.fulfill({ json: { api: { me: base + 'auth/me', csrf: base + 'auth/csrf', pair: base + 'auth/pair' } } });
        }
        return route.fulfill({ status: 401, json: { code: 'unauthenticated' } });
      }
      if (url.pathname.endsWith('/assets/brand/fandoogh-mark.svg')) {
        return route.fulfill({ contentType: 'image/svg+xml', body: fs.readFileSync(path.join(__dirname, '../assets/brand/fandoogh-mark.svg')) });
      }
      return route.continue();
    });
    await page.goto(managerPath);
    await expect(page.locator('#pairingView')).toBeVisible();
    await expect(page.locator('#appBootView')).toBeHidden();
    const sheets = await page.locator('link[rel="stylesheet"]').evaluateAll((links) => links.map((link) => Boolean(link.sheet)));
    expect(sheets).toEqual([true, true, true, true, true]);
    expect([...assetsRequested]).toEqual(expect.arrayContaining(['app.js', 'styles.css', 'ui.css', 'fonts.css', 'pos.css', 'invoice.css']));
    expect(legacyRequests).toEqual([]);
    expect(scriptErrors).toEqual([]);
    await expect(page.locator('#pairingUsername')).toBeVisible();
    expect(await page.locator('#pairingUsername').evaluate((input) => parseFloat(getComputedStyle(input).borderRadius))).toBeGreaterThanOrEqual(8);
    const workerUrl = await page.locator('meta[name="fandoogh-service-worker-url"]').getAttribute('content');
    expect(new URL('./', workerUrl).pathname).toBe(managerPath);
    await page.locator('.auth-card').screenshot({ path: testInfo.outputPath('login-query-assets.png') });
  });
}
