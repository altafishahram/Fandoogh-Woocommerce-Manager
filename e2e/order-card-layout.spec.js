// @ts-check
const { test, expect } = require('@playwright/test');

test('keeps order cards compact and shares product action/filter controls', async ({ page }) => {
  await page.goto('/?preview=1#orders');
  await expect(page.locator('#ordersGrid .order-card')).toHaveCount(6);
  await expect(page.locator('#ordersGrid')).toBeVisible();

  const cards = await page.locator('#ordersGrid .order-card').evaluateAll((nodes) => nodes.map((card) => {
    const button = card.querySelector('.order-card-view-button');
    const style = button ? getComputedStyle(button) : null;
    return {
      height: card.getBoundingClientRect().height,
      overflow: card.scrollWidth - card.clientWidth,
      verticalOverflow: card.scrollHeight - card.clientHeight,
      buttons: card.querySelectorAll('.order-card-icon-button.product-card-action').length,
      buttonSize: button ? { width: button.getBoundingClientRect().width, height: button.getBoundingClientRect().height } : null,
      buttonStyle: style ? { background: style.backgroundColor, radius: style.borderRadius } : null,
    };
  }));

  for (const card of cards) {
    expect(card.height).toBeLessThanOrEqual(120);
    expect(card.overflow).toBeLessThanOrEqual(1);
    expect(card.verticalOverflow).toBeLessThanOrEqual(1);
    expect(card.buttons).toBe(2);
    expect(card.buttonSize).toEqual({ width: 30, height: 30 });
    expect(card.buttonStyle).toEqual({ background: 'rgb(228, 248, 237)', radius: '8px' });
  }

  const controls = await page.evaluate(() => {
    const orderSearch = document.querySelector('#orderSearch');
    const productSearch = document.querySelector('#productSearch');
    const orderToggle = document.querySelector('#toggleOrderFilters');
    const productToggle = document.querySelector('#toggleProductFilters');
    const computed = (element) => ({ zIndex: getComputedStyle(element).zIndex, background: getComputedStyle(element).backgroundColor });
    return {
      orderSearch: computed(orderSearch),
      productSearch: computed(productSearch),
      orderToggle: computed(orderToggle),
      productToggle: computed(productToggle),
      sharedFilterClass: orderToggle.classList.contains('filter-toggle-button') && productToggle.classList.contains('filter-toggle-button'),
    };
  });
  expect(controls.orderSearch.zIndex).toBe('auto');
  expect(controls.productSearch.zIndex).toBe('auto');
  expect(controls.orderToggle.background).toBe(controls.productToggle.background);
  expect(controls.sharedFilterClass).toBe(true);
});

test('mobile drawer stays above the order and product search rows', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/?preview=1#orders');
  await expect(page.locator('#ordersGrid .order-card')).toHaveCount(6);
  await page.locator('#mobileMenuToggle').click();
  await expect(page.locator('#appSidebar')).toHaveClass(/is-open/);
  const layering = await page.evaluate(() => ({
    sidebar: getComputedStyle(document.querySelector('#appSidebar')).zIndex,
    scrim: getComputedStyle(document.querySelector('#sidebarScrim')).zIndex,
    orderSearch: getComputedStyle(document.querySelector('#orderSearch')).zIndex,
    productSearch: getComputedStyle(document.querySelector('#productSearch')).zIndex,
  }));
  expect(Number(layering.sidebar)).toBeGreaterThan(Number(layering.orderSearch) || 0);
  expect(Number(layering.sidebar)).toBeGreaterThan(Number(layering.productSearch) || 0);
  expect(Number(layering.scrim)).toBeGreaterThan(Number(layering.orderSearch) || 0);
});
