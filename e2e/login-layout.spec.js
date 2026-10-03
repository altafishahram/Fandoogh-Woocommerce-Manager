// @ts-check
const { test, expect } = require('@playwright/test');

test('fresh unauthenticated login stays readable across viewport sizes', async ({ page }, testInfo) => {
  await page.route('**/wp-json/fandoogh-manager/v1/**', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.endsWith('/config')) {
      const base = url.origin + '/wp-json/fandoogh-manager/v1/';
      return route.fulfill({ json: { api: { me: base + 'auth/me', csrf: base + 'auth/csrf', pair: base + 'auth/pair' } } });
    }
    return route.fulfill({ status: 401, json: { code: 'unauthenticated', message: 'ابتدا وارد شوید.' } });
  });
  await page.goto('/manager/');
  await expect(page.locator('#pairingView')).toBeVisible();
  await expect(page.locator('#appBootView')).toBeHidden();
  await expect(page.locator('#dashboardView')).toBeHidden();
  await page.screenshot({ path: testInfo.outputPath('login.png'), fullPage: true });
  const original = page.viewportSize();
  const widths = testInfo.project.name.includes('desktop') ? [original.width, 320, 600, 768, 880, 1024, 1440] : [original.width];
  for (const width of widths) {
    await page.setViewportSize({ width, height: original.height });
    const issues = await page.evaluate(() => {
      const errors = [];
      const rect = (selector) => document.querySelector(selector).getBoundingClientRect();
      const card = rect('.auth-card');
      const form = rect('#pairingForm');
      const hero = rect('.hero-copy-block');
      if (Math.min(card.right, hero.right) - Math.max(card.left, hero.left) > 1 && Math.min(card.bottom, hero.bottom) - Math.max(card.top, hero.top) > 1) errors.push('intro overlaps form');
      for (const selector of ['.auth-card', '.hero-copy-block', '#pairingSubmit', '#previewDashboard', '#pairingUsername', '#pairingPassword', '#pairingCode']) {
        const bounds = rect(selector);
        if (bounds.left < -1 || bounds.right > innerWidth + 1 || bounds.width < 40) errors.push(selector + ' escapes viewport');
      }
      for (const input of document.querySelectorAll('#pairingForm input')) {
        const bounds = input.getBoundingClientRect();
        if (bounds.left < form.left - 1 || bounds.right > form.right + 1 || bounds.height < 44) errors.push('input escapes form or unstyled');
        const mark = getComputedStyle(input.parentElement, '::before');
        const markWidth = parseFloat(mark.width);
        const markInset = parseFloat(mark.right);
        const textInset = parseFloat(getComputedStyle(input).paddingRight);
        if (mark.boxShadow !== 'none') errors.push('dots must not paint outside their bounded box');
        if (markWidth < 30 || markInset < 8 || markWidth + markInset >= bounds.width) errors.push('dots escape the input');
        if (textInset < markWidth + markInset + 4) errors.push('input text collides with dots');
      }
      if (rect('.topbar').bottom > rect('#pairingView').top + 1) errors.push('header overlaps login');
      return errors;
    });
    expect(issues, `login at ${width}px`).toEqual([]);
  }
});
