// @ts-check
const { test, expect } = require('@playwright/test');

// HTTP doubles exercise the production renderer; this is not a live WooCommerce test.
async function bootInventory(page) {
  const writes = [];
  const items = [
    { id: 101, name: 'محصول با نام بسیار طولانی برای بررسی شکستن متن و جلوگیری از تداخل کارت‌های موجودی', sku: 'LONG-SKU-'.repeat(10), stock_status: 'instock', stock_status_label: 'موجود', manage_stock: true, stock_quantity: 7, low_stock: true, low_stock_amount: 10, backorders: 'no', updated_at: '2026-09-05T08:00:00' },
    { id: 102, name: 'محصول ناموجود', sku: 'FB-102', stock_status: 'outofstock', stock_status_label: 'ناموجود', manage_stock: false, stock_quantity: null, backorders: 'no' },
    { id: 103, name: 'محصول پیش‌فروش', stock_status: 'onbackorder', stock_status_label: 'پیش‌فروش', manage_stock: true, stock_quantity: 0, backorders: 'notify' },
  ];
  await page.route('**/wp-json/fandoogh-manager/v1/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const base = url.origin + '/wp-json/fandoogh-manager/v1/';
    if (url.pathname.endsWith('/config')) {
      return route.fulfill({ json: { api: { me: base + 'auth/me', csrf: base + 'auth/csrf', inventory: base + 'inventory' } } });
    }
    if (url.pathname.endsWith('/auth/me')) {
      return route.fulfill({ json: { data: { user: { display_name: 'مدیر' }, scopes: { inventory: { read: true, write: true, update: true } } } } });
    }
    if (url.pathname.endsWith('/auth/csrf')) {
      return route.fulfill({ json: { data: { csrf_token: 'inventory-ui-test' } } });
    }
    if (request.method() === 'PATCH' && /\/inventory\/101\/?$/.test(url.pathname)) {
      const body = request.postDataJSON();
      writes.push({ body, csrf: request.headers()['x-fandoogh-csrf'] });
      Object.assign(items[0], body);
      return route.fulfill({ json: { data: items[0] } });
    }
    if (url.pathname.endsWith('/inventory')) {
      return route.fulfill({ json: { data: items, summary: { total: 3, low_stock: 1, out_of_stock: 1, on_backorder: 1 }, meta: { page: 1, total: 3, total_pages: 1 } } });
    }
    return route.fulfill({ status: 404, json: {} });
  });
  await page.goto('/#inventory');
  await expect(page.locator('#inventoryGrid')).toBeVisible();
  await expect(page.locator('.inventory-card')).toHaveCount(3);
  return writes;
}

test('inventory controls stay styled and separated when resized', async ({ page }, testInfo) => {
  await bootInventory(page);
  await page.locator('.inventory-panel').screenshot({ path: testInfo.outputPath('inventory.png') });
  const original = page.viewportSize();
  const widths = testInfo.project.name.includes('desktop') ? [original.width, 320, 600, 768, 880, 1024, 1280, 1440, original.width] : [original.width];
  for (const width of widths) {
    await page.setViewportSize({ width, height: original.height });
    const errors = await page.locator('.inventory-card').evaluateAll((cards) => {
      const failures = [];
      const intersects = (a, b) => Math.min(a.right, b.right) - Math.max(a.left, b.left) > 1 && Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top) > 1;
      cards.forEach((card, index) => {
        const bounds = card.getBoundingClientRect();
        if (bounds.left < -1 || bounds.right > innerWidth + 1 || card.scrollWidth > card.clientWidth + 1) failures.push(`card ${index} overflows`);
        const controls = [...card.querySelectorAll('input:not([type="checkbox"]), select')];
        controls.forEach((input) => {
          const rect = input.getBoundingClientRect();
          const label = input.closest('label').getBoundingClientRect();
          const style = getComputedStyle(input);
          if (rect.left < label.left - 1 || rect.right > label.right + 1 || rect.bottom > label.bottom + 1) failures.push('control escapes label');
          if (rect.height < 44 || parseFloat(style.borderRadius) < 8) failures.push('control missing styling');
        });
        const children = [...card.children];
        children.forEach((child, i) => children.slice(i + 1).forEach((next) => {
          if (intersects(child.getBoundingClientRect(), next.getBoundingClientRect())) failures.push('card sections overlap');
        }));
        const labels = [...card.querySelectorAll('.inventory-card-controls > label')];
        labels.forEach((label, i) => labels.slice(i + 1).forEach((next) => {
          if (intersects(label.getBoundingClientRect(), next.getBoundingClientRect())) failures.push('fields overlap');
        }));
        cards.slice(index + 1).forEach((next) => {
          if (intersects(bounds, next.getBoundingClientRect())) failures.push('cards overlap');
        });
      });
      return failures;
    });
    expect(errors, `inventory layout at ${width}px`).toEqual([]);
  }
});

test('stock editing keeps accessible controls and the existing PATCH contract', async ({ page }) => {
  const writes = await bootInventory(page);
  const first = page.locator('.inventory-card').first();
  const quantity = first.getByLabel('تعداد', { exact: true });
  await first.getByLabel('مدیریت موجودی').uncheck();
  await expect(quantity).toBeDisabled();
  await first.getByLabel('مدیریت موجودی').check();
  await expect(quantity).toBeEnabled();
  await quantity.fill('۲۵');
  await first.getByLabel('وضعیت', { exact: true }).selectOption('instock');
  await first.getByLabel('پیش‌فروش', { exact: true }).selectOption('notify');
  await first.getByLabel('آستانهٔ کمبود').fill('۵');
  await first.getByRole('button', { name: 'ذخیرهٔ موجودی' }).click();
  await expect(page.locator('#inventoryMessage')).toContainText('موجودی محصول ذخیره شد.');
  expect(writes).toEqual([{ csrf: 'inventory-ui-test', body: { manage_stock: true, stock_quantity: '25', stock_status: 'instock', backorders: 'notify', low_stock_amount: '5', expected_updated_at: '2026-09-05T08:00:00' } }]);
  await expect(quantity).toHaveValue('۲۵');
  await expect(page.locator('.inventory-card').nth(1).getByLabel('تعداد', { exact: true })).toBeDisabled();
});
