// @ts-check
const { defineConfig } = require('@playwright/test');

/**
 * Responsive browser checks for the PWA shell.
 *
 * The app is served from a tiny dependency-free static server (tools/
 * static-server.js). Layout checks use `?preview=1` sample data; coupon-flow
 * checks intercept HTTP responses to exercise the production UI. Neither
 * suite is a live WordPress/WooCommerce integration test.
 */
module.exports = defineConfig({
  testDir: './e2e',
  timeout: 30000,
  expect: { timeout: 8000 },
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: process.env.CI ? 2 : undefined,
  reporter: process.env.CI ? [['github'], ['html']] : [['list']],
  use: {
    baseURL: 'http://127.0.0.1:8124',
    locale: 'fa-IR',
    colorScheme: 'light',
    reducedMotion: 'reduce',
    serviceWorkers: 'block',
    trace: process.env.CI ? 'retain-on-failure' : 'on-first-retry',
    screenshot: 'only-on-failure',
  },
  projects: [
    {
      name: 'mobile-320',
      use: { browserName: 'chromium', viewport: { width: 320, height: 720 } },
    },
    {
      name: 'tablet-768',
      use: { browserName: 'chromium', viewport: { width: 768, height: 900 } },
    },
    {
      name: 'desktop-1280',
      use: { browserName: 'chromium', viewport: { width: 1280, height: 900 } },
    },
    {
      name: 'webkit-desktop',
      use: { browserName: 'webkit', viewport: { width: 1280, height: 900 } },
    },
  ],
  webServer: {
    command: 'node tools/static-server.js',
    port: 8124,
    // Never point a test run at a preview server from another worktree.
    reuseExistingServer: false,
    timeout: 15000,
  },
});
