// @ts-check
const { test, expect } = require('@playwright/test');

const report = {
  range: { label: '۳۰ روز اخیر' },
  currency: { code: 'IRT', label: 'تومان' },
  sales: { gross: '1250000', average_order: '625000' },
  orders: { total: 2, successful: 2, statuses: [{ key: 'completed', label: 'تکمیل‌شده', count: 2 }] },
  products: { total: 3, top: [{ name: 'محصول آزمایشی', quantity: 2, total: '1250000' }] },
  customers: { unique: 2, guest_orders: 0 },
  meta: { truncated: false },
};

async function bootAnalytics(page, { enabled = true, analyticsScope = true, analyticsFailure = null } = {}) {
  let reportReads = 0;
  await page.route('**/wp-json/fandoogh-manager/v1/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const api = url.origin + '/wp-json/fandoogh-manager/v1/';
    if (url.pathname.endsWith('/config')) {
      return route.fulfill({ json: { analytics: { enabled }, api: { me: api + 'auth/me', csrf: api + 'auth/csrf', analytics: api + 'analytics/summary' } } });
    }
    if (url.pathname.endsWith('/auth/me')) {
      return route.fulfill({ json: { data: { user: { display_name: 'مدیر آزمایشی' }, scopes: analyticsScope ? { analytics: { read: true } } : {} } } });
    }
    if (url.pathname.endsWith('/auth/csrf')) {
      return route.fulfill({ json: { data: { csrf_token: 'analytics-browser-test' } } });
    }
    if (url.pathname.endsWith('/analytics/summary')) {
      reportReads += 1;
      if (analyticsFailure) return route.fulfill(analyticsFailure);
      return route.fulfill({ json: { data: report } });
    }
    return route.fulfill({ status: 404, json: { message: 'Unexpected test endpoint' } });
  });
  await page.goto('/#analytics');
  return { reportReads: () => reportReads };
}

test('renders enabled reports for an authenticated analytics session', async ({ page }) => {
  const requests = await bootAnalytics(page);
  await expect(page.locator('#analyticsPanel')).toBeVisible();
  await expect(page.locator('#analyticsKpis')).toBeVisible();
  await expect(page.locator('#analyticsGrossSales')).toContainText('۱٬۲۵۰٬۰۰۰');
  await expect(page.locator('#analyticsProductList')).toContainText('محصول آزمایشی');
  expect(requests.reportReads()).toBe(1);
});

test('keeps enabled reports discoverable and explains a denied session', async ({ page }) => {
  const requests = await bootAnalytics(page, { analyticsScope: false });
  await expect(page.locator('[data-nav-target="analytics"]')).toBeVisible();
  await expect(page.locator('#analyticsPanel')).toBeVisible();
  await expect(page.locator('#analyticsErrorState')).toBeVisible();
  await expect(page.locator('#analyticsErrorText')).toContainText('اتصال امن جدید');
  await expect(page.locator('#analyticsKpis')).toBeHidden();
  expect(requests.reportReads()).toBe(0);
});

test('hides disabled reports', async ({ page }) => {
  await bootAnalytics(page, { enabled: false });
  await expect(page.locator('[data-nav-target="analytics"]')).toBeHidden();
  await expect(page.locator('#analyticsPanel')).toBeHidden();
});

test('renders a server report failure as an error', async ({ page }) => {
  await bootAnalytics(page, { analyticsFailure: { status: 503, json: { message: 'WooCommerce برای تحلیل فروش فعال نیست.' } } });
  await expect(page.locator('#analyticsPanel')).toBeVisible();
  await expect(page.locator('#analyticsErrorState')).toBeVisible();
  await expect(page.locator('#analyticsErrorText')).toContainText('WooCommerce');
});
