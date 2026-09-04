// @ts-check
const { test, expect } = require('@playwright/test');

async function expectCardLayout(page) {
  const cards = await page.locator('#productsGrid .product-card').evaluateAll((nodes) => nodes.map((card) => {
    const box = (selector) => {
      const rect = card.querySelector(selector).getBoundingClientRect();
      return { top: rect.top, right: rect.right, bottom: rect.bottom, left: rect.left, width: rect.width };
    };
    return {
      image: box('.product-card-image-wrap'),
      title: box('.product-card-topline'),
      category: box('.product-card-category-hint'),
      stock: box('.product-card-stock-line'),
      footer: box('.product-card-price-row'),
      price: box('.product-card-prices'),
      actions: box('.product-card-actions'),
      view: box('.product-view-button'),
      edit: box('.product-edit-button'),
      border: parseFloat(getComputedStyle(card.querySelector('.product-card-price-row')).borderTopWidth),
      overflow: card.scrollWidth - card.clientWidth,
    };
  }));
  expect(cards.length).toBeGreaterThan(0);
  for (const card of cards) {
    expect(card.overflow).toBeLessThanOrEqual(1);
    expect(card.image.left).toBeGreaterThanOrEqual(card.title.right - 1);
    expect(Math.abs(card.title.width - card.image.width * 2)).toBeLessThanOrEqual(2);
    expect(Math.abs(card.category.width - card.image.width)).toBeLessThanOrEqual(2);
    expect(Math.abs(card.stock.width - card.image.width)).toBeLessThanOrEqual(2);
    expect(Math.abs(card.image.top - card.title.top)).toBeLessThanOrEqual(1);
    expect(Math.abs(card.image.bottom - card.footer.bottom)).toBeLessThanOrEqual(1);
    expect(card.category.top).toBeGreaterThanOrEqual(card.title.bottom - 1);
    expect(Math.abs(card.category.top - card.stock.top)).toBeLessThanOrEqual(1);
    expect(card.category.left).toBeGreaterThanOrEqual(card.stock.right - 1);
    expect(card.footer.top).toBeGreaterThanOrEqual(card.stock.bottom - 1);
    expect(card.border).toBeGreaterThanOrEqual(1);
    expect(card.actions.top).toBeGreaterThanOrEqual(card.price.bottom);
    expect(Math.abs(card.view.top - card.edit.top)).toBeLessThanOrEqual(1);
    expect(card.actions.left).toBeGreaterThanOrEqual(card.footer.left - 1);
    expect(card.actions.right).toBeLessThanOrEqual(card.footer.right + 1);
  }
  expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(1);
}

test('keeps the three-column card and actions below price during manual resize', async ({ page }, testInfo) => {
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

  // Long real-world text must wrap inside its cell without changing the grid.
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
