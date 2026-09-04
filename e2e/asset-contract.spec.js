// @ts-check
const { test, expect } = require('@playwright/test');

test('serves the PWA asset contract and semantic UI hooks', async ({ page, request }) => {
  await page.goto('/?preview=1', { waitUntil: 'domcontentloaded' });

  await expect(page.locator('.app-shell')).toHaveClass(/is-authenticated/);
  expect(await page.locator('button.fm-button').count()).toBeGreaterThan(0);
  expect(await page.locator('[data-state].fm-state').count()).toBeGreaterThan(0);
  expect(await page.locator('[role="alert"].fm-alert').count()).toBeGreaterThan(0);

  for (const assetPath of [
    '/ui.css',
    '/fonts.css',
    '/assets/brand/fandoogh-mark.svg',
    '/assets/icon/fandoogh-dashboard.svg',
  ]) {
    const response = await request.get(assetPath);
    expect(response.ok(), assetPath).toBeTruthy();
  }

  const workerResponse = await request.get('/sw.js');
  expect(workerResponse.ok()).toBeTruthy();
  expect(await workerResponse.text()).toContain('ui.css');

  const manifestResponse = await request.get('/manager/manifest.webmanifest');
  expect(manifestResponse.ok()).toBeTruthy();
  const manifest = await manifestResponse.json();
  expect(manifest.display).toBe('standalone');
  expect(manifest.display_override).toContain('standalone');
  expect(manifest.launch_handler.client_mode).toBe('navigate-existing');
  expect(manifest.icons).toEqual(expect.arrayContaining([
    expect.objectContaining({
      src: '/assets/brand/fandoogh-mark.svg',
      type: 'image/svg+xml',
    }),
  ]));
});
