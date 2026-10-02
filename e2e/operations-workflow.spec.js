// @ts-check
const { test, expect } = require('@playwright/test');

async function phoneDoubles(page) {
  await page.addInitScript(() => {
    window.__cameraStops = 0;
    window.__detectedCode = '';
    window.__speech = null;
    window.SpeechRecognition = class {
      constructor() { window.__speech = this; }
      start() { this.started = true; }
      abort() { this.aborted = true; }
    };
    window.BarcodeDetector = class {
      static getSupportedFormats() { return Promise.resolve(['ean_13', 'code_128']); }
      detect() { return Promise.resolve(window.__detectedCode ? [{ rawValue: window.__detectedCode }] : []); }
    };
    Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: {
      getUserMedia: async () => ({ getTracks: () => [{ stop: () => { window.__cameraStops += 1; } }] })
    } });
    Object.defineProperty(HTMLMediaElement.prototype, 'srcObject', { configurable: true, get() { return this.__stream; }, set(value) { this.__stream = value; } });
    HTMLMediaElement.prototype.play = async function () {};
    HTMLMediaElement.prototype.pause = function () {};
  });
}
async function bootStore(page, extras = {}) {
  const requests = [];
  let subscribed = false;
  let preferences = { new_order: true, low_stock: true, delayed_order: true, quiet_start: '', quiet_end: '' };
  await page.route('**/wp-json/fandoogh-manager/v1/**', async route => {
    const request = route.request(), url = new URL(request.url());
    requests.push({ path: url.pathname, query: url.searchParams.toString(), method: request.method(), body: request.postData(), csrf: request.headers()['x-fandoogh-csrf'] });
    const scopes = extras.scopes || { products: { read: true }, orders: { read: true }, inventory: { read: true } };
    if (url.pathname.endsWith('/auth/me')) return route.fulfill({ json: { data: { user: { id: 7, display_name: 'مدیر تست' }, scopes } } });
    if (url.pathname.endsWith('/auth/csrf')) return route.fulfill({ json: { data: { csrf_token: 'operations-test-csrf' } } });
    if (url.pathname.endsWith('/operations/today')) {
      if (extras.todayError) return route.fulfill({ status: 503, json: { message: 'کارها موقتاً در دسترس نیستند' } });
      return route.fulfill({ json: { data: { groups: [
        { kind: 'shipping', title: 'آمادهٔ ارسال', count: 1, paged: true, page: Number(url.searchParams.get('order_page') || 1), pages: 2, items: [{ id: 91, title: 'سفارش #91', section: 'orders', late: true }] },
        { kind: 'stock', title: 'نیازمند تأمین موجودی', count: 1, items: [{ id: extras.outsideProduct ? 777 : 101, title: 'کالای کم‌موجودی', section: 'products', quantity: 1 }] }
      ] } } });
    }
    if (url.pathname.endsWith('/operations/barcode')) return route.fulfill({ json: { data: { id: 101, parent_id: 0, name: 'کالای تست', search: '00123', sku: '00123' } } });
    if (url.pathname.endsWith('/operations/push-test')) return route.fulfill({ json: { data: { sent: true } } });
    if (url.pathname.endsWith('/operations/push')) {
      if (request.method() === 'POST') { subscribed = true; preferences = request.postDataJSON().preferences; }
      if (request.method() === 'DELETE') subscribed = false;
      return route.fulfill({ json: { data: { available: true, public_key: 'BA'.repeat(43) + 'A', subscribed, preferences, timezone: 'Asia/Tehran' } } });
    }
    if (url.pathname.endsWith('/products')) return route.fulfill({ json: { data: [{ id: 101, name: 'کالای تست', sku: '00123', type: 'simple', status: 'publish', price: '200', stock_status: 'instock', stock_quantity: 1 }], meta: { page: 1, total: 1, total_pages: 1 } } });
    if (/\/products\/777\/?$/.test(url.pathname)) return route.fulfill({ json: { data: { id: 777, name: 'محصول خارج از صفحهٔ اول', sku: '777', type: 'simple', status: 'publish', price: '500', stock_status: 'instock', stock_quantity: 1 } } });
    return route.fulfill({ status: 404, json: {} });
  });
  await page.goto('/');
  await expect(page.locator('#dashboardView')).toBeVisible();
  return requests;
}

test('voice tools cover every search, trigger existing filtering, and recover from errors', async ({ page }) => {
  await phoneDoubles(page);
  await page.goto('/?preview=1');
  const searches = page.locator('input[type="search"]');
  expect(await page.locator('.search-tools .search-tool-button[aria-pressed]').count()).toBe(await searches.count());
  await page.evaluate(() => { location.hash = '#products'; });
  await expect(page.locator('#productSearch')).toBeVisible();
  const voice = page.locator('#productSearch').locator('..').getByRole('button', { name: /جست‌وجوی صوتی/ });
  await voice.click();
  expect(await page.evaluate(() => window.__speech.lang)).toBe('fa-IR');
  await page.evaluate(() => window.__speech.onresult({ resultIndex: 0, results: [[{ transcript: 'محصولی که وجود ندارد' }]] }));
  await expect(page.locator('#productSearch')).toHaveValue('محصولی که وجود ندارد');
  await expect(page.locator('#productsEmptyState')).toBeVisible();
  await page.evaluate(() => window.__speech.onerror({ error: 'not-allowed' }));
  await expect(page.locator('#searchAssistStatus')).toContainText('دسترسی میکروفن رد شده');
  await expect(voice).toHaveAttribute('aria-pressed', 'false');
  await page.evaluate(() => { const input = document.createElement('input'); input.type = 'search'; input.id = 'dynamicSearch'; document.querySelector('.products-panel').appendChild(input); });
  await expect(page.locator('#dynamicSearch').locator('..').getByRole('button', { name: /جست‌وجوی صوتی/ })).toHaveCount(1);
});

test('camera scan preserves barcode zeros, applies lookup, and stops tracks on close', async ({ page }) => {
  await phoneDoubles(page);
  const requests = await bootStore(page);
  await page.evaluate(() => { location.hash = '#products'; });
  const scan = page.locator('#productSearch').locator('..').getByRole('button', { name: /اسکن بارکد/ });
  await scan.click();
  await expect(page.locator('#barcodeDialog')).toBeVisible();
  await page.evaluate(() => { window.__detectedCode = '00123'; });
  await expect(page.locator('#barcodeDialog')).not.toBeVisible();
  await expect(page.locator('#productSearch')).toHaveValue('00123');
  expect(requests.some(request => request.path.endsWith('/operations/barcode') && request.query === 'code=00123')).toBeTruthy();
  expect(await page.evaluate(() => window.__cameraStops)).toBeGreaterThan(0);
  await page.evaluate(() => { window.__detectedCode = ''; });
  await scan.click();
  await expect(page.locator('#barcodeStatus')).toContainText('بارکد را مقابل دوربین');
  const stopped = await page.evaluate(() => window.__cameraStops);
  await page.locator('#barcodeClose').click();
  expect(await page.evaluate(() => window.__cameraStops)).toBeGreaterThan(stopped);
});

test('camera denial leaves a manual barcode lookup available', async ({ page }) => {
  await phoneDoubles(page);
  await page.addInitScript(() => { navigator.mediaDevices.getUserMedia = async () => { throw new DOMException('denied', 'NotAllowedError'); }; });
  await bootStore(page);
  await page.evaluate(() => { location.hash = '#inventory'; });
  await page.locator('#inventorySearch').locator('..').getByRole('button', { name: /اسکن بارکد/ }).click();
  await expect(page.locator('#barcodeStatus')).toContainText('دسترسی دوربین رد شده');
  await page.locator('#barcodeManualCode').fill('۰۰۱۲۳');
  await page.locator('#barcodeLookup').click();
  await expect(page.locator('#inventorySearch')).toHaveValue('00123');
});

test('daily work marks late shipping, paginates and reports retrieval failures', async ({ page }) => {
  const requests = await bootStore(page);
  await expect(page.locator('#todayGroups')).toContainText('تأخیر بیش از ۴۸ ساعت');
  await page.locator('#todayGroups').getByRole('button', { name: 'صفحهٔ بعد' }).click();
  await expect.poll(() => requests.some(request => request.query.includes('order_page=2'))).toBeTruthy();
  await page.route('**/operations/today?**', route => route.fulfill({ status: 503, json: { message: 'کارها موقتاً در دسترس نیستند' } }));
  await page.locator('#todayRefresh').click();
  await expect(page.locator('#todayStatus')).toContainText('کارها موقتاً');
  await expect(page.locator('#todayGroups')).toBeEmpty();
  await expect(page.locator('.app-shell')).toHaveClass(/is-authenticated/);
});

test('phone push registers preferences with CSRF, tests and unsubscribes', async ({ page }) => {
  await page.addInitScript(() => {
    window.__unsubscribed = 0;
    let existing = null;
    const subscription = { toJSON: () => ({ endpoint: 'https://fcm.googleapis.com/fcm/send/test', keys: { p256dh: 'test', auth: 'test' } }), unsubscribe: async () => { existing = null; window.__unsubscribed += 1; return true; } };
    window.Notification = { permission: 'default', requestPermission: async () => { window.Notification.permission = 'granted'; return 'granted'; } };
    window.PushManager = function () {};
    const registration = { pushManager: { getSubscription: async () => existing, subscribe: async () => { existing = subscription; return subscription; } }, addEventListener() {} };
    Object.defineProperty(navigator, 'serviceWorker', { value: { ready: Promise.resolve(registration), register: async () => registration, addEventListener() {} } });
  });
  const requests = await bootStore(page);
  await page.locator('#todayPanel [data-open-push-settings]').click();
  await expect(page.locator('#pushEnable')).toBeEnabled();
  await page.locator('#pushLowStock').uncheck();
  await page.locator('#pushQuietStart').fill('22:00');
  await page.locator('#pushQuietEnd').fill('07:00');
  await page.locator('#pushEnable').click();
  await expect(page.locator('#pushSettingsStatus')).toContainText('فعال است');
  const write = requests.find(request => request.path.endsWith('/operations/push') && request.method === 'POST');
  expect(write.csrf).toBe('operations-test-csrf');
  expect(JSON.parse(write.body).preferences).toMatchObject({ low_stock: false, quiet_start: '22:00', quiet_end: '07:00' });
  await page.locator('#pushTest').click();
  await expect(page.locator('#pushSettingsStatus')).toContainText('آزمایشی');
  await page.locator('#pushDisable').click();
  await expect(page.locator('#pushSettingsStatus')).toContainText('خاموش شد');
  expect(await page.evaluate(() => window.__unsubscribed)).toBe(1);
  expect(requests.some(request => request.path.endsWith('/operations/push') && request.method === 'DELETE' && request.csrf === 'operations-test-csrf')).toBeTruthy();
});

test('daily work opens a product beyond the currently loaded catalogue page', async ({ page }) => {
  await bootStore(page, { outsideProduct: true });
  await page.locator('#todayGroups').getByRole('button', { name: /کالای کم‌موجودی/ }).click();
  await expect(page.locator('#productViewDialog')).toBeVisible();
  await expect(page.locator('#productViewTitle')).toContainText('محصول خارج از صفحهٔ اول');
});

test('preview does not register phone notifications or overflow search tools', async ({ page }, testInfo) => {
  const requests = [];
  page.on('request', request => { if (request.url().includes('/wp-json/')) requests.push(request.url()); });
  await page.goto('/?preview=1');
  await expect(page.locator('#todayGroups .today-task-button')).toHaveCount(6);
  await page.locator('#todayPanel').screenshot({ path: testInfo.outputPath('operations-today.png') });
  await page.locator('#todayPanel [data-open-push-settings]').click();
  await expect(page.locator('#pushSettingsStatus')).toContainText('پیش‌نمایش');
  await expect(page.locator('#pushEnable')).toBeDisabled();
  await page.locator('#pushSettingsClose').click();
  expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(1);
  expect(requests).toEqual([]);
});
