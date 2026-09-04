// @ts-check
const { defineConfig } = require('@playwright/test');

/**
 * Responsive overflow checks for the PWA shell.
 *
 * The app is served from a tiny dependency-free static server (tools/
 * static-server.js) and loaded in `?preview=1` mode, which renders the full
 * authenticated dashboard from client-side sample data — no WordPress needed.
 * Tests run against the system-installed Chrome so no browser download is
 * required (PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 during `npm install`).
 */
module.exports = defineConfig({
  testDir: './e2e',
  timeout: 30000,
  expect: { timeout: 8000 },
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [['github'], ['html']] : [['list']],
  use: {
    channel: 'chrome',
    baseURL: 'http://127.0.0.1:8124',
    trace: process.env.CI ? 'retain-on-failure' : 'on-first-retry',
    screenshot: process.env.CI ? 'only-on-failure' : 'only-on-failure',
  },
  projects: [
    {
      name: 'mobile-320',
      use: { viewport: { width: 320, height: 720 } },
    },
    {
      name: 'tablet-768',
      use: { viewport: { width: 768, height: 900 } },
    },
    {
      name: 'desktop-1280',
      use: { viewport: { width: 1280, height: 900 } },
      use: { channel: 'chrome' },
    },
    {
      name: 'webkit-desktop',
      use: { viewport: { width: 1280, height: 900 } },
    },
  ],
  webServer: {
    command: 'node tools/static-server.js',
    port: 8124,
    reuseExistingServer: true,
    timeout: 15000,
  },
});
