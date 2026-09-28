// @ts-check
const { test, expect } = require('@playwright/test');

const grids = {
  products: '#productsGrid',
  orders: '#ordersGrid',
  customers: '#customersGrid',
};

function expectedColumns(width) {
  if (width <= 720) return 1;
  if (width <= 1100) return 2;
  return 3;
}

test('product and order grids use the customer card column contract', async ({ page }) => {
  await page.goto('/?preview=1#customers');
  await expect(page.locator('.app-shell')).toHaveClass(/is-authenticated/);

  for (const width of [1440, 1100, 721, 720, 390]) {
    await page.setViewportSize({ width, height: 900 });
    const counts = {};

    for (const [section, selector] of Object.entries(grids)) {
      await page.locator(`[data-nav-target="${section}"]`).first().evaluate((button) => button.click());
      await expect(page.locator(selector)).toBeVisible();
      counts[section] = await page.locator(selector).evaluate((grid) =>
        getComputedStyle(grid).gridTemplateColumns.split(' ').filter(Boolean).length,
      );
    }

    expect(counts, `card columns at ${width}px`).toEqual({
      products: expectedColumns(width),
      orders: expectedColumns(width),
      customers: expectedColumns(width),
    });
  }
});
