// Actual PHP-rendered administrator views with a minimal WP layout fixture.
// Does not claim to run a real WordPress install or exercise production nonces.
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const adminCss = fs.readFileSync(path.join(__dirname, '../assets/css/admin.css'), 'utf8');
const tabs = ['overview', 'appearance', 'media', 'analytics', 'access', 'sessions', 'connection', 'tracking'];
const markup = new Map(tabs.map(tab => [tab, execFileSync('php', ['tests/admin-panel-contract.php', 'html', tab], { cwd: path.join(__dirname, '..'), encoding: 'utf8' })]));

async function installAdmin(page) {
  await page.route('**/wp-admin/admin.php*', route => {
    const tab = new URL(route.request().url()).searchParams.get('tab') || 'overview';
    return route.fulfill({ contentType: 'text/html; charset=utf-8', body: `<!doctype html><html lang="fa" dir="rtl"><meta name="viewport" content="width=device-width, initial-scale=1"><style>
      body{margin:0;font:13px Tahoma;background:#f0f0f1}#wpcontent{margin-right:160px;padding:0 20px} .wrap{margin:10px 0 0 20px}table{border-collapse:collapse;width:100%}.form-table th{width:200px;text-align:right;padding:20px 10px}.form-table td{padding:15px 10px}.regular-text{width:25em}.button{display:inline-block;border:1px solid #ccc;text-decoration:none;font:inherit;cursor:pointer}.screen-reader-text{position:absolute;clip-path:inset(50%);width:1px;height:1px;overflow:hidden}input,textarea,select{font:inherit;padding:4px}input[type=color]{width:50px} @media(max-width:782px){#wpcontent{margin:0;padding:0 10px}.wrap{margin:10px 0}}
      ${adminCss}</style><body class="toplevel_page_fandoogh-manager"><div id="wpcontent">${markup.get(tab) || markup.get('overview')}</div></body></html>` });
  });
  await page.goto('/wp-admin/admin.php?page=fandoogh-manager');
}

test('administrator navigation, isolated forms and responsive layout', async ({ page }, testInfo) => {
  await installAdmin(page);
  await expect(page.locator('.fandoogh-admin-summary')).toHaveCount(3);
  await page.screenshot({ path: testInfo.outputPath('admin-overview.png'), fullPage: true });
  for (const tab of tabs) {
    await page.locator(`.fandoogh-admin-tab[href$="tab=${tab}"]`).click();
    await expect(page.locator(`[data-admin-tab="${tab}"]`)).toBeVisible();
    await expect(page.locator('[aria-current="page"]')).toHaveCount(1);
    expect(await page.locator('form form').count()).toBe(0);
    const data = await page.evaluate(() => ({ width: innerWidth, scroll: document.documentElement.scrollWidth }));
    expect(data.scroll, `page overflow on ${tab}`).toBeLessThanOrEqual(data.width + 1);
    if (!['overview', 'access'].includes(tab)) {
      const fields = await page.locator('.fandoogh-admin-settings-form').evaluate(form => [...new FormData(form).keys()]);
      expect(fields).toContain('fandoogh_manager_settings[_tab]');
      expect(fields).toContain('_wpnonce');
      if (tab !== 'analytics') expect(fields).not.toContain('fandoogh_manager_settings[analytics_enabled]');
    }
  }
  for (const width of [320, 420, 660, 980, 1440]) {
    await page.setViewportSize({ width, height: 900 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width + 1);
  }
});

test('tab navigation and settings still work without JavaScript', async ({ browser, baseURL }) => {
  const context = await browser.newContext({ javaScriptEnabled: false, baseURL });
  const page = await context.newPage();
  await installAdmin(page);
  await page.locator('.fandoogh-admin-tab[href$="tab=analytics"]').click();
  await expect(page.locator('#fandoogh-manager-analytics-enabled')).toBeVisible();
  await page.locator('#fandoogh-manager-analytics-enabled').check();
  await expect(page.locator('#fandoogh-manager-analytics-enabled')).toBeChecked();
  await context.close();
});
