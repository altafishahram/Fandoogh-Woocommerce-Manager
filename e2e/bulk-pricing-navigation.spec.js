// @ts-check
const { test, expect } = require('@playwright/test');

async function openBulkPricing(page) {
  const menu = page.locator('#mobileMenuToggle');
  if (await menu.isVisible()) await menu.click();
  await page.locator('#appSidebar #openBulkPrice').click();
  await expect(page).toHaveURL(/#bulk-price$/);
  await expect(page.locator('#bulkPricePanel')).toBeVisible();
  await expect(page.locator('#openBulkPrice')).toHaveAttribute('aria-current', 'page');
  await expect(page.locator('.products-panel')).toBeHidden();
}

async function openProducts(page) {
  const mobileProducts = page.locator('#mobileBottomNav [data-nav-target="products"]');
  if (await mobileProducts.isVisible()) await mobileProducts.click();
  else await page.locator('#appSidebar [data-nav-target="products"]').click();
}

test('bulk pricing has its own tab, deep link and browser history', async ({ page }) => {
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('/?preview=1#products');
  await expect(page.locator('.products-panel')).toBeVisible();
  await expect(page.locator('.products-panel #bulkPricePanel, .products-panel #openBulkPrice')).toHaveCount(0);
  await openBulkPricing(page);
  await expect(page.locator('#closeBulkPrice')).toHaveCount(0);
  await expect(page.locator('#appSidebar')).not.toHaveClass(/is-open/);
  const categoryId = await page.locator('#bulkPriceCategories option').first().getAttribute('value');
  await page.locator(`#bulkPriceCategoryOptions input[value="${categoryId}"]`).check();
  await page.locator('#bulkPriceNext').click();
  await page.locator('#bulkPriceAmount').fill('۱۰');
  await openProducts(page);
  await expect(page).toHaveURL(/#products$/);
  await expect(page.locator('#bulkPricePanel')).toBeHidden();
  await page.goBack();
  await expect(page.locator('#bulkPricePanel')).toBeVisible();
  await expect(page.locator('#bulkPriceCategories')).toHaveValues([categoryId]);
  await expect(page.locator('#bulkPriceAmount')).toHaveValue('۱۰');
  await expect(page.locator('[data-bulk-price-stage="2"]')).toBeVisible();
  await page.goBack();
  await expect(page.locator('.products-panel')).toBeVisible();
  await page.goForward();
  await expect(page.locator('#bulkPricePanel')).toBeVisible();
  await page.reload();
  await expect(page.locator('#bulkPricePanel')).toBeVisible();
  await expect(page.locator('#openBulkPrice')).toHaveAttribute('aria-current', 'page');
  expect(errors).toEqual([]);
});

test('the standalone form preserves preview, invalidation and confirmed API payloads', async ({ page }) => {
  // Intercept every store request: this test never updates a live product.
  const writes = [];
  await page.route('**/wp-json/fandoogh-manager/v1/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const api = url.origin + '/wp-json/fandoogh-manager/v1/';
    if (url.pathname.endsWith('/config')) {
      return route.fulfill({ json: {
        api: { me: api + 'auth/me', csrf: api + 'auth/csrf', products: api + 'products', categories: api + 'product-categories' },
        icons: { filter: url.origin + '/assets/icon/fandoogh-filter.svg' },
      } });
    }
    if (url.pathname.endsWith('/auth/me')) {
      return route.fulfill({ json: { data: { user: { display_name: 'مدیر آزمایشی' }, scopes: { products: { read: true, write: true, update: true } } } } });
    }
    if (url.pathname.endsWith('/auth/csrf')) {
      return route.fulfill({ json: { data: { csrf_token: 'bulk-price-test' } } });
    }
    if (url.pathname.endsWith('/product-categories')) {
      return route.fulfill({ json: { data: [
        { id: 11, name: 'دسته اصلی', parent: 0, count: 1 },
        { id: 12, name: 'زیردسته', parent: 11, count: 1 },
      ] } });
    }
    if (/\/products\/bulk-price\/$/.test(url.pathname) && request.method() === 'POST') {
      const body = request.postDataJSON();
      writes.push({ body, csrf: request.headers()['x-fandoogh-csrf'] });
      return route.fulfill({ json: { data: {
        mode: body.execute ? 'execute' : 'preview',
        matched_products: 1, price_records: 1, changed: body.execute ? 1 : 0,
        confirmation_token: body.execute ? '' : 'preview-token-' + writes.length,
        sample: [{ id: 101, name: 'محصول نمونه', old_regular_price: '100000', new_regular_price: '110000' }],
      } } });
    }
    if (url.pathname.endsWith('/products')) return route.fulfill({ json: { data: [] } });
    return route.fulfill({ status: 404, json: {} });
  });
  await page.goto('/#bulk-price');
  await expect(page.locator('#bulkPricePanel')).toBeVisible();
  await expect(page.locator('#bulkPriceCategories option')).toHaveCount(2);
  await page.locator('#bulkPriceCategoryOptions input[value="11"]').check();
  await page.locator('#bulkPriceCategoryOptions input[value="12"]').check();
  await page.locator('#bulkPriceNext').click();
  await expect(page.locator('#bulkPriceScheduleDate')).toHaveAttribute('type', 'text');
  await page.locator('input[name="bulkPriceSchedule"][value="later"]').check();
  await expect(page.locator('#bulkPriceScheduleDatetime')).toBeVisible();
  await page.locator('input[name="bulkPriceSchedule"][value="now"]').check();
  await page.locator('#bulkPriceAmount').fill('۱۰');
  await expect(page.locator('#executeBulkPrice')).toBeDisabled();
  await page.locator('#previewBulkPrice').click();
  await expect(page.locator('#executeBulkPrice')).toBeEnabled();
  const originalBody = {
    selection_method: 'category', category_ids: [11, 12], product_ids: [], include_children: true,
    apply_to_variations: true, adjustment_type: 'percent', amount: '10', price_overrides: {},
    schedule_mode: 'now', scheduled_at: '', execute: false, confirmation_token: '',
  };
  expect(writes).toEqual([{ body: originalBody, csrf: 'bulk-price-test' }]);
  await expect(page.locator('#bulkPricePreview')).toContainText('محصول نمونه');
  await page.locator('#bulkPriceBack').click();
  await page.locator('#bulkPriceAmount').fill('۲۰');
  await expect(page.locator('#executeBulkPrice')).toBeDisabled();
  await expect(page.locator('[data-bulk-price-stage="2"]')).toBeVisible();
  await page.locator('#bulkPriceAmount').fill('۱۰');
  await page.locator('#previewBulkPrice').click();
  await expect(page.locator('#executeBulkPrice')).toBeEnabled();
  await openProducts(page);
  const icon = page.locator('#toggleProductFilters .ui-icon-asset');
  await expect(icon).toBeVisible();
  expect(await icon.evaluate((node) => getComputedStyle(node).getPropertyValue('--ui-icon-url'))).toContain('/assets/icon/fandoogh-filter.svg');
  await openBulkPricing(page);
  await expect(page.locator('#executeBulkPrice')).toBeEnabled();
  await page.locator('#executeBulkPrice').click();
  await expect(page.locator('#bulkPriceConfirmModal')).toBeVisible();
  await page.locator('#bulkPriceModalConfirm').click();
  await expect(page.locator('#bulkPriceMessage')).toContainText('با موفقیت ذخیره شد');
  await expect(page.locator('#executeBulkPrice')).toBeDisabled();
  expect(writes).toEqual([
    { body: originalBody, csrf: 'bulk-price-test' },
    { body: originalBody, csrf: 'bulk-price-test' },
    { body: { ...originalBody, execute: true, confirmation_token: 'preview-token-2' }, csrf: 'bulk-price-test' },
  ]);
});

test('product and bulk-price lists append every API page without duplicate products', async ({ page }) => {
  const products = Array.from({ length: 75 }, (_, index) => ({
    id: index + 1,
    name: `محصول ${index + 1}`,
    type: 'simple',
    status: 'publish',
    price: String((index + 1) * 1000),
    regular_price: String((index + 1) * 1000),
  }));

  await page.route('**/wp-json/fandoogh-manager/v1/**', async (route) => {
    const url = new URL(route.request().url());
    const api = url.origin + '/wp-json/fandoogh-manager/v1/';
    if (url.pathname.endsWith('/config')) {
      return route.fulfill({ json: { api: { me: api + 'auth/me', csrf: api + 'auth/csrf', products: api + 'products', categories: api + 'product-categories' } } });
    }
    if (url.pathname.endsWith('/auth/me')) {
      return route.fulfill({ json: { data: { user: { display_name: 'مدیر آزمایشی' }, scopes: { products: { read: true, write: true, update: true } } } } });
    }
    if (url.pathname.endsWith('/auth/csrf')) return route.fulfill({ json: { data: { csrf_token: 'pagination-test' } } });
    if (url.pathname.endsWith('/product-categories')) return route.fulfill({ json: { data: [], meta: { page: 1, total_pages: 0, total: 0 } } });
    if (url.pathname.endsWith('/products')) {
      const requestedPage = Number(url.searchParams.get('page') || 1);
      const data = requestedPage === 1 ? products.slice(0, 50) : [{ ...products[49] }, ...products.slice(50)];
      return route.fulfill({ json: { data, meta: { page: requestedPage, per_page: 50, total: 75, total_pages: 2 } } });
    }
    return route.fulfill({ status: 404, json: {} });
  });

  await page.goto('/#products');
  await expect(page.locator('#productsGrid .product-card')).toHaveCount(50);
  await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
  await expect(page.locator('#productsGrid .product-card')).toHaveCount(75);
  const productIds = await page.locator('#productsGrid .product-card').evaluateAll(nodes => nodes.map(node => node.dataset.productId));
  expect(new Set(productIds).size).toBe(75);

  await openBulkPricing(page);
  await page.locator('[data-bulk-price-method="product"]').click();
  await expect(page.locator('#bulkPriceProductOptions input[data-bulk-price-select="product"]')).toHaveCount(50);
  await page.locator('#bulkPriceProductOptions').evaluate(node => { node.scrollTop = node.scrollHeight; node.dispatchEvent(new Event('scroll')); });
  await expect(page.locator('#bulkPriceProductOptions input[data-bulk-price-select="product"]')).toHaveCount(75);
  const bulkIds = await page.locator('#bulkPriceProductOptions input[data-bulk-price-select="product"]').evaluateAll(nodes => nodes.map(node => node.value));
  expect(new Set(bulkIds).size).toBe(75);
});
