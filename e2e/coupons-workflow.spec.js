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
    if (/\/coupons\/\d+\/?$/.test(url.pathname) && request.method() === 'DELETE') {
      const id = Number(url.pathname.match(/(\d+)\/?$/)[1]);
      const index = rows.findIndex((row) => row.id === id);
      if (index >= 0) rows.splice(index, 1);
      return route.fulfill({ json: { data: { id, deleted: true } } });
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
  await expect(page.getByRole('dialog', { name: 'کوپن جدید' })).toBeVisible();
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

test('coupon modal fits the viewport, traps focus and restores its trigger', async ({ page }, testInfo) => {
  await bootCoupons(page);
  await page.locator('#newCouponButton').click();
  const dialog = page.locator('#couponEditor');
  await expect(page.locator('#couponCode')).toBeFocused();
  await expect(page.locator('.app-shell')).toHaveAttribute('inert', '');
  await expect.poll(() => dialog.evaluate((form) => getComputedStyle(form).opacity)).toBe('1');
  const errors = await dialog.evaluate((form) => {
    const failures = [];
    const rect = form.getBoundingClientRect();
    if (rect.left < 0 || rect.right > innerWidth || rect.top < 0 || rect.bottom > innerHeight) failures.push('dialog outside viewport');
    if (form.scrollWidth > form.clientWidth) failures.push('horizontal overflow');
    for (const input of form.querySelectorAll('input:not([type="checkbox"]), select')) {
      const bounds = input.getBoundingClientRect();
      if (bounds.width < 80 || bounds.height < 43.5 || parseFloat(getComputedStyle(input).borderRadius) < 8) failures.push(`${input.id}: ${bounds.width} x ${bounds.height}, radius ${getComputedStyle(input).borderRadius}`);
    }
    const save = form.querySelector('#saveCouponButton').getBoundingClientRect();
    if (save.bottom > innerHeight || save.top < 0) failures.push('save button outside viewport');
    return failures;
  });
  expect(errors).toEqual([]);
  await dialog.screenshot({ path: testInfo.outputPath('coupon-modal.png') });
  const cancel = dialog.getByRole('button', { name: 'انصراف', exact: true });
  await cancel.focus();
  await page.keyboard.press('Tab');
  await expect(page.locator('#cancelCouponEdit')).toBeFocused();
  await page.keyboard.press('Shift+Tab');
  await expect(cancel).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await expect(page.locator('#newCouponButton')).toBeFocused();
  await expect(page.locator('.app-shell')).not.toHaveAttribute('inert', '');
  await page.locator('#couponsGrid .coupon-card').first().getByRole('button', { name: /ویرایش/ }).click();
  await expect(dialog).toBeVisible();
  await expect(page.locator('#couponCode')).toHaveValue('LEGACY01');
  await cancel.click();
  await expect(dialog).toBeHidden();
});

test('coupon calendar works inside the modal and Escape closes the calendar first', async ({ page }) => {
  await bootCoupons(page);
  await page.locator('#newCouponButton').click();
  await page.locator('#couponExpiry').click();
  const calendar = page.locator('#couponEditor .persian-calendar-popover');
  await expect(calendar).toBeVisible();
  await calendar.getByRole('button', { name: 'امروز', exact: true }).click();
  await expect(page.locator('#couponExpiry')).not.toHaveValue('');
  await page.locator('#couponExpiry').click();
  await page.keyboard.press('Escape');
  await expect(calendar).toBeHidden();
  await expect(page.locator('#couponEditor')).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(page.locator('#couponEditor')).toBeHidden();
});

test('coupon cards stay compact and view modal supports edit and delete actions', async ({ page }) => {
  await bootCoupons(page);
  const cards = await page.locator('#couponsGrid .coupon-card').evaluateAll((nodes) => nodes.map((card) => ({
    height: card.getBoundingClientRect().height,
    overflow: card.scrollHeight - card.clientHeight,
    buttons: card.querySelectorAll('.order-card-icon-button.product-card-action').length,
    text: card.textContent,
  })));
  for (const card of cards) {
    expect(card.height).toBeLessThanOrEqual(120);
    expect(card.overflow).toBeLessThanOrEqual(1);
    expect(card.buttons).toBe(2);
    expect(card.text).toContain('مصرف');
    expect(card.text).toContain('انقضا');
  }

  await page.locator('.coupon-card-view-button').first().click();
  const detail = page.locator('#couponDetailDialog');
  await expect(detail).toBeVisible();
  // Display digits are Persian; the editable identifier remains exact ASCII.
  await expect(detail).toContainText('LEGACY۰۱');
  await expect(detail).toContainText('میزان مصرف');
  await expect(detail).toContainText('تاریخ انقضا');
  await page.locator('#editCouponFromDetail').click();
  await expect(page.locator('#couponEditor')).toBeVisible();
  await expect(page.locator('#couponCode')).toHaveValue('LEGACY01');
  await page.locator('#cancelCouponEdit').click();
  await expect(page.locator('#couponEditor')).toBeHidden();

  await page.locator('.coupon-card-view-button').first().click();
  await page.once('dialog', (dialog) => dialog.accept());
  await Promise.all([
    page.waitForRequest((request) => request.method() === 'DELETE' && /\/coupons\/1\/?$/.test(request.url())),
    page.locator('#deleteCouponFromDetail').click(),
  ]);
  await expect(page.locator('#couponDetailOverlay')).toBeHidden();
  await expect(page.locator('#couponsGrid .coupon-card')).toHaveCount(20);
  await expect(page.locator('#couponsGrid')).not.toContainText('LEGACY۰۱');
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
