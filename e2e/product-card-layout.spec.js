// @ts-check
const { test, expect } = require('@playwright/test');

async function expectCardLayout(page) {
  const cards = await page.locator('#productsGrid .product-card').evaluateAll((nodes) => nodes.map((card) => {
    const box = (selector) => {
      const rect = card.querySelector(selector).getBoundingClientRect();
      return { top: rect.top, right: rect.right, bottom: rect.bottom, left: rect.left, width: rect.width, height: rect.height };
    };
    return {
      bounds: { top: card.getBoundingClientRect().top, bottom: card.getBoundingClientRect().bottom, height: card.getBoundingClientRect().height },
      image: box('.product-card-image-wrap'),
      name: box('.product-card-name'),
      nameStyle: { whiteSpace: getComputedStyle(card.querySelector('.product-card-name')).whiteSpace, textOverflow: getComputedStyle(card.querySelector('.product-card-name')).textOverflow },
      title: box('.product-card-topline'),
      category: box('.product-card-category-hint'),
      stock: box('.product-card-stock-line'),
      footer: box('.product-card-price-row'),
      price: box('.product-card-price'),
      actions: box('.product-card-actions'),
      view: box('.product-view-button'),
      edit: box('.product-edit-button'),
      border: parseFloat(getComputedStyle(card.querySelector('.product-card-actions')).borderTopWidth),
      overflow: card.scrollWidth - card.clientWidth,
      verticalOverflow: card.scrollHeight - card.clientHeight,
      skuCount: card.querySelectorAll('.product-card-sku').length,
    };
  }));
  expect(cards.length).toBeGreaterThan(0);
  for (const card of cards) {
    expect(card.overflow).toBeLessThanOrEqual(1);
    expect(card.verticalOverflow).toBeLessThanOrEqual(1);
    expect(card.bounds.height).toBeLessThanOrEqual(120);
    expect(card.skuCount).toBe(0);
    expect(card.nameStyle).toEqual({ whiteSpace: 'nowrap', textOverflow: 'ellipsis' });
    expect(card.name.height).toBeLessThanOrEqual(22);
    expect(card.image.left).toBeGreaterThanOrEqual(card.title.right - 1);
    expect(card.image.width).toBe(100);
    expect(card.image.height).toBe(100);
    expect(card.image.top).toBeGreaterThanOrEqual(card.bounds.top + 8);
    expect(card.image.bottom).toBeLessThanOrEqual(card.bounds.bottom - 8);
    expect(card.category.top).toBeGreaterThanOrEqual(card.title.bottom - 1);
    expect(Math.abs(card.category.top - card.stock.top)).toBeLessThanOrEqual(1);
    expect(card.category.left).toBeGreaterThanOrEqual(card.stock.left - 1);
    expect(card.category.right).toBeLessThanOrEqual(card.stock.right + 1);
    expect(card.footer.top).toBeGreaterThanOrEqual(card.stock.bottom - 1);
    expect(card.border).toBeGreaterThanOrEqual(1);
    expect(card.actions.top).toBeGreaterThanOrEqual(card.price.bottom);
    expect(Math.abs(card.view.top - card.edit.top)).toBeLessThanOrEqual(1);
    expect(Math.abs(card.price.left - card.footer.left)).toBeLessThanOrEqual(1);
    expect(Math.abs(card.edit.left - card.footer.left)).toBeLessThanOrEqual(1);
    expect(card.view.left).toBeGreaterThan(card.edit.left);
    expect(card.actions.left).toBeGreaterThanOrEqual(card.footer.left - 1);
    expect(card.actions.right).toBeLessThanOrEqual(card.footer.right + 1);
  }
  expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(1);
}

test('keeps reference cards at 120px with square images and left-aligned actions during resize', async ({ page }, testInfo) => {
  await page.goto('/?preview=1#products');
  await expect(page.locator('#productsGrid .product-card')).toHaveCount(6);
  const mobileProducts = page.locator('.mobile-nav-item[data-nav-target="products"]');
  if (await mobileProducts.isVisible()) {
    await mobileProducts.click();
  } else {
    await page.locator('#appSidebar [data-nav-target="products"]').click();
  }
  await expect(page.locator('#productsGrid')).toBeVisible();
  await expectCardLayout(page);
  await page.locator('#productsGrid').screenshot({ path: testInfo.outputPath('product-cards.png') });

  // Long text must truncate to one line without increasing the card height.
  await page.locator('#productsGrid .product-card').first().evaluate((card) => {
    card.querySelector('.product-card-name').textContent = 'بسته ویژه کره بادام‌زمینی طبیعی بدون شکر با نام بسیار طولانی و مشخصات کامل محصول';
    card.querySelector('.product-card-category-hint').textContent = 'دسته‌بندی‌محصولات‌ارگانیک‌ویژه‌و‌خوراکی‌های‌سالم';
  });
  const original = page.viewportSize();
  const widths = testInfo.project.name.includes('desktop') ? [360, 600, 1024, 1440, 320, original.width] : [original.width];
  for (const width of widths) {
    await page.setViewportSize({ width, height: original.height });
    await expectCardLayout(page);
  }
  await page.locator('#productsGrid').screenshot({ path: testInfo.outputPath('product-cards-long-text.png') });
});

test('renders only root categories and real square images from the product response', async ({ page }, testInfo) => {
  const longName = 'محصول با نام بسیار طولانی برای بررسی نمایش تک‌خطی و سه‌نقطه در کارت محصولات فروشگاه';
  await page.route('**/wp-json/fandoogh-manager/v1/**', async (route) => {
    const url = new URL(route.request().url());
    const base = url.origin + '/wp-json/fandoogh-manager/v1/';
    if (url.pathname.endsWith('/config')) {
      return route.fulfill({ json: { api: { me: base + 'auth/me', csrf: base + 'auth/csrf', products: base + 'products' } } });
    }
    if (url.pathname.endsWith('/auth/me')) {
      return route.fulfill({ json: { data: { user: { display_name: 'مدیر' }, scopes: { products: { read: true } } } } });
    }
    if (url.pathname.endsWith('/auth/csrf')) {
      return route.fulfill({ json: { data: { csrf_token: 'product-layout-test' } } });
    }
    if (url.pathname.endsWith('/products')) {
      const categorySets = [
        [{ id: 3, name: 'زیردسته', parent: 2, parent_name: 'والد میانی', root_category: { id: 1, name: 'مادر اصلی', slug: 'root' } }],
        [{ id: 1, name: 'مادر مستقل', parent: 0, root_category: { id: 1, name: 'مادر مستقل' } }],
        [{ id: 3, name: 'زیردسته خراب', parent: 2, root_category: null }],
        [],
      ];
      return route.fulfill({ json: { data: categorySets.map((categories, index) => ({
        id: index + 1, name: index === 0 ? longName : 'نام محصول', type: 'simple', status: 'publish',
        price: '1500000', regular_price: '1500000', sku: 'SKU-MUST-NOT-APPEAR',
        manage_stock: true, stock_quantity: 1, stock_status: 'instock', categories,
        images: [{ id: 10, src: url.origin + '/assets/brand/fandoogh-mark.svg' }],
      })) } });
    }
    return route.fulfill({ status: 404, json: {} });
  });
  await page.goto('/#products');
  await expect(page.locator('#productsGrid')).toBeVisible();
  const cards = page.locator('#productsGrid .product-card');
  await expect(cards).toHaveCount(4);
  const first = page.locator('[data-product-id="1"]');
  await expect(first.locator('.product-card-name')).toHaveAttribute('title', longName);
  await expect(first.locator('.product-card-category-hint')).toHaveText('مادر اصلی');
  await expect(page.locator('[data-product-id="2"] .product-card-category-hint')).toHaveText('مادر مستقل');
  await expect(page.locator('[data-product-id="3"] .product-card-category-hint')).toHaveText('دسته‌بندی مادر نامشخص');
  await expect(page.locator('[data-product-id="4"] .product-card-category-hint')).toHaveText('بدون دسته‌بندی');
  await expect(cards.first()).not.toContainText('SKU-MUST-NOT-APPEAR');
  await expect.poll(() => first.locator('img').evaluate((img) => img.complete && img.naturalWidth > 0)).toBe(true);
  expect(await first.locator('img').evaluate((img) => getComputedStyle(img).objectFit)).toBe('contain');
  expect(await first.locator('.product-card-name').evaluate((name) => name.scrollWidth > name.clientWidth)).toBe(true);
  await expectCardLayout(page);
  await page.locator('#productsGrid').screenshot({ path: testInfo.outputPath('product-cards-api.png') });
});
