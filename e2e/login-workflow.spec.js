// @ts-check
const { test, expect } = require('@playwright/test');

// Synthetic credentials and HTTP doubles only; never signs in to a live site.
async function bootLogin(page, mode) {
  const calls = { pair: 0, me: 0, csrf: 0, committed: false, cookieWrites: [], meCookies: [] };
  const user = { display_name: 'مدیر آزمایشی' };
  const scopes = { products: { read: true } };
  if (mode === 'render-error') {
    await page.addInitScript(() => {
      const original = Element.prototype.setAttribute;
      let thrown = false;
      Element.prototype.setAttribute = function (name, value) {
        if (!thrown && this.id === 'productsStateBadge' && name === 'data-state' && value === 'loading') {
          thrown = true;
          throw new Error('Synthetic dashboard render failure');
        }
        return original.call(this, name, value);
      };
    });
  }
  await page.route('**/wp-json/fandoogh-manager/v1/**', async (route) => {
    const url = new URL(route.request().url());
    const base = url.origin + '/wp-json/fandoogh-manager/v1/';
    if (url.pathname.endsWith('/config')) return route.fulfill({ json: { api: { pair: base + 'auth/pair', me: base + 'auth/me', csrf: base + 'auth/csrf', products: base + 'products' } } });
    if (url.pathname.endsWith('/auth/me')) {
      calls.me++;
      // WebKit does not expose Cookie in intercepted request headers. The
      // browser cookie jar for this exact request URL is the fixture's
      // transport-independent equivalent of what the mock server receives.
      const cookies = await page.context().cookies([route.request().url()]);
      calls.meCookies.push({ origin: url.origin, url: route.request().url(), cookies });
      const hasCookie = cookies.some((cookie) => cookie.name === 'fandoogh_manager_session' && cookie.value === 'synthetic-session');
      return route.fulfill(calls.committed && hasCookie && mode !== 'unverified'
        ? { json: { data: { user, scopes } } }
        : { status: 401, json: { message: 'نشست معتبر نیست.' } });
    }
    if (url.pathname.endsWith('/auth/csrf')) {
      calls.csrf++;
      return route.fulfill({ json: { data: mode === 'invalid-csrf' ? {} : { csrf_token: 'test-recovered-csrf' } } });
    }
    if (url.pathname.endsWith('/auth/pair')) {
      calls.pair++;
      expect(route.request().method()).toBe('POST');
      if (mode === 'rejected') return route.fulfill({ status: 401, json: { message: 'اطلاعات ورود معتبر نیست.' } });
      calls.committed = true;
      // The cookie is seeded after the initial unauthenticated probe below.
      // WebKit does not consistently persist Set-Cookie headers from an
      // intercepted response, so this models the already-committed browser
      // cookie without changing the production transport implementation.
      calls.cookieWrites.push({
        origin: url.origin,
        cookies: await page.context().cookies([`${url.origin}/`]),
      });
      if (mode === 'lost-response') {
        // Simulate headers having committed the cookie before the body is lost.
        return route.abort('failed');
      }
      if (['server-error', 'unverified', 'invalid-csrf'].includes(mode)) return route.fulfill({ status: 500, json: { message: 'خطای پاسخ پس از ساخت نشست' } });
      if (mode === 'malformed') return route.fulfill({ contentType: 'application/json', body: 'broken JSON' });
      return route.fulfill({ json: { data: { user, scopes, csrf_token: 'test-pair-csrf' } } });
    }
    if (url.pathname.endsWith('/products')) {
      if (mode === 'expired') return route.fulfill({ status: 401, json: {} });
      return route.fulfill({ status: 500, json: { message: 'خطای فهرست محصولات' } });
    }
    return route.fulfill({ status: 404, json: {} });
  });
  await page.goto('/');
  await expect(page.locator('#pairingView')).toBeVisible();
  // Seed a transport-independent HttpOnly cookie after the initial /auth/me
  // probe, but before the pairing request. The mock still rejects /auth/me
  // until the pairing route commits, so fail-closed behavior remains tested.
  await page.context().addCookies([{
    name: 'fandoogh_manager_session',
    value: 'synthetic-session',
    domain: new URL(page.url()).hostname,
    path: '/',
    httpOnly: true,
    sameSite: 'Strict',
  }]);
  await page.locator('#pairingUsername').fill('test-admin');
  await page.locator('#pairingPassword').fill('synthetic-password');
  await page.locator('#pairingCode').fill('123456789012');
  await page.locator('#pairingSubmit').click();
  await expect(page.locator('#pairingSubmit')).toBeEnabled();
  return calls;
}

for (const mode of ['normal', 'server-error', 'malformed', 'lost-response', 'render-error']) {
  test(`login reaches dashboard without reopening: ${mode}`, async ({ page }) => {
    const calls = await bootLogin(page, mode);
    expect(calls.cookieWrites).toEqual([expect.objectContaining({
      cookies: expect.arrayContaining([expect.objectContaining({
        name: 'fandoogh_manager_session', value: 'synthetic-session', domain: '127.0.0.1', path: '/',
      })]),
    })]);
    await expect(page.locator('.app-shell')).toHaveClass(/is-authenticated/);
    await expect(page.locator('#dashboardView')).toBeVisible();
    await expect(page.locator('#pairingView')).toBeHidden();
    await expect(page.locator('#pairingPassword')).toHaveValue('');
    await expect(page.locator('#pairingCode')).toHaveValue('');
    expect(calls.pair).toBe(1);
    expect(calls.me).toBe(['server-error', 'malformed', 'lost-response'].includes(mode) ? 2 : 1);
    if (mode === 'render-error') await expect(page.locator('#connectionLabel')).toContainText('بارگذاری کامل نیست');
    await page.reload();
    await expect(page.locator('.app-shell')).toHaveClass(/is-authenticated/);
    expect(calls.meCookies.at(-1)).toEqual(expect.objectContaining({
      origin: 'http://127.0.0.1:8124',
      url: 'http://127.0.0.1:8124/wp-json/fandoogh-manager/v1/auth/me',
      cookies: expect.arrayContaining([expect.objectContaining({
        name: 'fandoogh_manager_session', value: 'synthetic-session', domain: '127.0.0.1', path: '/',
      })]),
    }));
    expect(calls.pair).toBe(1);
  });
}

for (const mode of ['rejected', 'unverified', 'invalid-csrf', 'expired']) {
  test(`login stays fail-closed: ${mode}`, async ({ page }) => {
    const calls = await bootLogin(page, mode);
    await expect(page.locator('.app-shell')).not.toHaveClass(/is-authenticated/);
    await expect(page.locator('#pairingView')).toBeVisible();
    await expect(page.locator('#pairingMessage')).toBeVisible();
    expect(calls.pair).toBe(1);
    if (mode === 'rejected') {
      expect(calls.me).toBe(1);
      expect(calls.csrf).toBe(0);
      await expect(page.locator('#pairingMessage')).toContainText('اطلاعات ورود معتبر نیست.');
    }
  });
}
