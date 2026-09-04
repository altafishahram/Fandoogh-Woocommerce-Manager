// @ts-check
const { test, expect } = require('@playwright/test');

// Exercise the production UI's HTTP flow with intercepted responses. This is
// a browser contract test, not a live WordPress/WooCommerce integration test.
async function bootCoupons(page, { failRefresh = false, failSave = false } = {}) {
  const rows = Array.from({ length: 25 }, (_, index) => ({
    id: index + 1, code: `LEGACY${String(index + 1).padStart(2, '0')}`,
    amount: '10', discount_type: 'percent', status: 'publish',
  }));
  const reads = [];
  let created = false;
  await page.route('**/wp-json/fandoogh-manager/v1/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const api = url.origin + '/wp-json/fandoogh-manager/v1/';
    if (url.pathname.endsWith('/config')) {
      return route.fulfill({ json: { api: { me: api + 'auth/me', csrf: api + 'auth/csrf', coupons: api + 'coupons' } } });
    }
    if (url.pathname.endsWith('/auth/me')) {
      return route.fulfill({ json: { data: { user: { display_name: 'مدیر آزمایشی' }, scopes: { coupons: { read: true, write: true } } } } });
    }
    if (url.pathname.endsWith('/auth/csrf')) {
      return route.fulfill({ json: { data: { csrf_token: 'coupon-browser-test' } } });
    }
    if (url.pathname.endsWith('/coupons') && request.method() === 'POST') {
      expect(request.headers()['x-fandoogh-csrf']).toBe('coupon-browser-test');
      if (failSave) return route.fulfill({ status: 409, json: { message: 'این کد کوپن قبلاً وجود دارد.' } });
      const row = { ...request.postDataJSON(), id: 100, status: 'publish' };
      rows.unshift(row);
      created = true;
      return route.fulfill({ status: 201, json: { data: row } });
    }
    if (url.pathname.endsWith('/coupons')) {
      const currentPage = Number(url.searchParams.get('page') || '1');
      const perPage = Number(url.searchParams.get('per_page') || '20');
      const search = url.searchParams.get('search') || '';
      reads.push({ page: currentPage, search, afterSave: created });
      if (created && failRefresh) return route.fulfill({ status: 500, json: { message: 'خواندن فهرست کوپن‌ها انجام نشد.' } });
      const filtered = rows.filter((row) => row.code.toLowerCase().includes(search.toLowerCase()));
      return route.fulfill({ json: {
        data: filtered.slice((currentPage - 1) * perPage, currentPage * perPage),
        meta: { page: currentPage, per_page: perPage, total: filtered.length, total_pages: Math.ceil(filtered.length / perPage) },
      } });
    }
    return route.fulfill({ status: 404, json: { message: 'Unexpected test endpoint' } });
  });
  await page.goto('/#coupons');
  await expect(page.locator('#couponsGrid')).toBeVisible();
  await expect(page.locator('#couponsGrid .coupon-card').first()).toContainText('LEGACY۰۱');
  return reads;
}

async function submitNewCoupon(page) {
  await page.locator('#newCouponButton').click();
  await page.locator('#couponCode').fill('NEWDISCOUNT');
  await page.locator('#couponType').selectOption('percent');
  await page.locator('#couponAmount').fill('15');
  await page.locator('#saveCouponButton').click();
}

test('shows a newly saved coupon even after searching and moving to another page', async ({ page }) => {
  const reads = await bootCoupons(page);
  await page.locator('#couponSearch').fill('LEGACY');
  await expect.poll(() => reads.some((read) => read.search === 'legacy')).toBe(true);
  await expect(page.locator('#couponsNextPage')).toBeEnabled();
  await page.locator('#couponsNextPage').click();
  await expect(page.locator('#couponsGrid .coupon-card').first()).toContainText('LEGACY۲۱');
  await submitNewCoupon(page);
  await expect(page.locator('#couponEditor')).toBeHidden();
  await expect(page.locator('#couponSearch')).toHaveValue('');
  await expect(page.locator('#couponsGrid .coupon-card').first()).toContainText('NEWDISCOUNT');
  expect(reads.filter((read) => read.afterSave)).toEqual([{ page: 1, search: '', afterSave: true }]);
});

test('shows a list-refresh failure after saving instead of claiming there are no coupons', async ({ page }) => {
  await bootCoupons(page, { failRefresh: true });
  await submitNewCoupon(page);
  await expect(page.locator('#couponEditor')).toBeHidden();
  await expect(page.locator('#couponsErrorState')).toBeVisible();
  await expect(page.locator('#couponsErrorText')).toContainText('خواندن فهرست کوپن‌ها انجام نشد.');
  await expect(page.locator('#couponsEmptyState')).toBeHidden();
});

test('keeps duplicate-code errors visible in the editor', async ({ page }) => {
  const reads = await bootCoupons(page, { failSave: true });
  await submitNewCoupon(page);
  await expect(page.locator('#couponEditor')).toBeVisible();
  await expect(page.locator('#couponEditorMessage')).toContainText('این کد کوپن قبلاً وجود دارد.');
  await expect(page.locator('#saveCouponButton')).toBeEnabled();
  expect(reads.some((read) => read.afterSave)).toBe(false);
});
