// @ts-check
const { test, expect } = require('@playwright/test');

test('serves the PWA asset contract and semantic UI hooks', async ({ page, request }) => {
  await page.goto('/?preview=1', { waitUntil: 'domcontentloaded' });

  await expect(page.locator('.app-shell')).toHaveClass(/is-authenticated/);
  expect(await page.locator('button.fm-button').count()).toBeGreaterThan(0);
  expect(await page.locator('[data-state].fm-state').count()).toBeGreaterThan(0);
  expect(await page.locator('[role="alert"].fm-alert').count()).toBeGreaterThan(0);
  await expect(page.locator('#installAppButton')).toBeHidden();

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

test('supports browser install prompts and the manual iOS guide', async ({ browser }, testInfo) => {
  // A desktop browser with an iPhone user agent exercises the code path. The
  // narrow Chromium projects emulate layout, not Safari's installation API.
  test.skip(!['desktop-1280', 'webkit-desktop'].includes(testInfo.project.name), 'Synthetic iOS install test runs on desktop browser projects.');

  const desktopContext = await browser.newContext();
  const desktopPage = await desktopContext.newPage();
  await desktopPage.goto('/?preview=1', { waitUntil: 'domcontentloaded' });
  await expect(desktopPage.locator('#installAppButton')).toBeHidden();
  await desktopPage.evaluate(() => {
    window.__installPromptCalls = 0;
    const event = new Event('beforeinstallprompt', { cancelable: true });
    event.prompt = () => { window.__installPromptCalls += 1; };
    event.userChoice = Promise.resolve({ outcome: 'accepted' });
    window.dispatchEvent(event);
  });
  await expect(desktopPage.locator('#installAppButton')).toBeVisible();
  await desktopPage.locator('#installAppButton').click();
  await expect.poll(() => desktopPage.evaluate(() => window.__installPromptCalls)).toBe(1);
  await expect(desktopPage.locator('#installAppButton')).toBeHidden();
  await desktopContext.close();

  const iosContext = await browser.newContext({
    userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1',
  });
  const iosPage = await iosContext.newPage();
  await iosPage.goto('/?preview=1', { waitUntil: 'domcontentloaded' });
  await expect(iosPage.locator('#installAppButton')).toBeVisible();
  await iosContext.close();
});
