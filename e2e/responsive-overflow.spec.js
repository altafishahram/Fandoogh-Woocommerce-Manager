// @ts-check
const { test, expect } = require('@playwright/test');

/**
 * These are preview-mode browser/UI checks. The application is booted with
 * client-side sample data, so the suite deliberately does not stand in for a
 * WordPress, REST, authentication, or WooCommerce integration suite.
 */

const SECTIONS = [
  'dashboard',
  'orders',
  'products',
  'bulk-price',
  'inventory',
  'customers',
  'coupons',
  'reviews',
  'analytics',
  'categories',
  'security',
];

function measureScript() {
  return () => {
    const viewportWidth = window.innerWidth;
    const documentWidth = Math.max(
      document.documentElement.scrollWidth,
      document.body.scrollWidth,
    );
    const overflow = documentWidth - viewportWidth;
    const offenders = [];

    if (overflow > 1) {
      document.querySelectorAll('body *').forEach((element) => {
        const style = getComputedStyle(element);
        if (style.display === 'none' || style.visibility === 'hidden') return;
        const rect = element.getBoundingClientRect();
        if (rect.width > 0 && (rect.right > viewportWidth + 1 || rect.left < -1)) {
          const id = element.id ? `#${element.id}` : '';
          const className = String(element.className || '')
            .trim()
            .split(/\s+/)
            .slice(0, 2)
            .join('.');
          offenders.push(
            `${element.tagName.toLowerCase()}${id}.${className} ` +
              `[L${Math.round(rect.left)} R${Math.round(rect.right)}]`,
          );
        }
      });
    }

    return {
      viewportWidth,
      documentWidth,
      overflow,
      offenders: offenders.slice(0, 6),
    };
  };
}

const measure = (page) => page.evaluate(measureScript());

function assertNoHorizontalOverflow(measurement) {
  expect(
    measurement.overflow,
    `horizontal overflow of ${measurement.overflow}px ` +
      `(document ${measurement.documentWidth} vs viewport ${measurement.viewportWidth})` +
      (measurement.offenders.length ? `; offenders: ${measurement.offenders.join(', ')}` : ''),
  ).toBeLessThanOrEqual(1);
}

function assertBoxFits(rect, label) {
  expect(rect.left, `${label} should not start off-screen`).toBeGreaterThanOrEqual(-1);
  expect(rect.right, `${label} should not end past the viewport`).toBeLessThanOrEqual(rect.vw + 1);
}

async function bootPreview(page) {
  const apiRequests = [];
  page.on('request', (request) => {
    if (new URL(request.url()).pathname.startsWith('/wp-json/')) {
      apiRequests.push(request.url());
    }
  });

  await page.goto('/?preview=1', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.app-shell')).toHaveClass(/is-authenticated/);
  await expect(page.locator('.app-shell')).toHaveClass(/is-preview-mode/);
  await expect(page.locator('#dashboardView')).toBeVisible();
  await expect(page.locator('#runtimeNote')).toContainText('پیش‌نمایش');
  expect(apiRequests, 'preview mode must not call the WordPress REST API').toEqual([]);
}

async function navigateToSection(page, section) {
  const mobileMenu = page.locator('#mobileMenuToggle');
  const isMobileLayout = await mobileMenu.isVisible();
  const mobileTarget = page.locator(`.mobile-nav-item[data-nav-target="${section}"]`);

  if (isMobileLayout && await mobileTarget.isVisible()) {
    await mobileTarget.click();
  } else {
    if (isMobileLayout && !(await page.locator('#appSidebar').evaluate((node) => node.classList.contains('is-open')))) {
      await mobileMenu.click();
      await expect(page.locator('#appSidebar')).toHaveClass(/is-open/);
    }
    const sidebarTarget = page.locator(`#appSidebar .sidebar-nav-item[data-nav-target="${section}"]`);
    await expect(sidebarTarget).toBeVisible();
    // The drawer is a fixed layer and its off-canvas transform can make a
    // legitimate target fail Playwright's viewport hit-test. Dispatching the
    // native click still exercises the real navigation handler.
    await sidebarTarget.evaluate((button) => button.click());
  }

  await expect(page.locator(`[data-nav-target="${section}"].is-active`).first()).toHaveClass(/is-active/);
  await expect(page.locator(`[data-nav-section~="${section}"]:visible`).first()).toBeVisible();
}

async function expectFocusInside(page, containerSelector, message) {
  const result = await page.evaluate((selector) => {
    const container = document.querySelector(selector);
    return Boolean(container && container.contains(document.activeElement));
  }, containerSelector);
  expect(result, message).toBe(true);
}

async function tabThrough(page, containerSelector, count) {
  for (let index = 0; index < count; index += 1) {
    await page.keyboard.press('Tab');
    await expectFocusInside(
      page,
      containerSelector,
      `Tab ${index + 1} should keep focus inside ${containerSelector}`,
    );
  }
}

async function expectOverlayFits(page, selector, label, checkInternalOverflow = false) {
  const rect = await page.locator(selector).evaluate((element) => {
    const box = element.getBoundingClientRect();
    return {
      left: box.left,
      right: box.right,
      vw: window.innerWidth,
      internalOverflow: element.scrollWidth > element.clientWidth + 1,
    };
  });
  assertBoxFits(rect, label);
  if (checkInternalOverflow) {
    expect(rect.internalOverflow, `${label} must not overflow internally`).toBe(false);
  }
}

test.describe('preview-mode browser checks', () => {
  test('boots sample data and navigates every section without horizontal overflow', async ({ page }, testInfo) => {
    await bootPreview(page);

    const sectionMetrics = [];
    for (const section of SECTIONS) {
      await navigateToSection(page, section);
      const measurement = await measure(page);
      assertNoHorizontalOverflow(measurement);
      sectionMetrics.push({ section, overflow: measurement.overflow });
    }

    testInfo.annotations.push({
      type: 'preview-sections',
      description: JSON.stringify(sectionMetrics),
    });

    await navigateToSection(page, 'dashboard');
    assertNoHorizontalOverflow(await measure(page));
  });

  test('renders representative preview records and makes product controls usable', async ({ page }) => {
    await bootPreview(page);

    await navigateToSection(page, 'products');
    const productCards = page.locator('#productsGrid .product-card');
    await expect(productCards).toHaveCount(6);
    await expect(productCards.first()).toContainText('کره بادام‌زمینی طبیعی');

    await page.locator('#toggleProductFilters').click();
    await expect(page.locator('#productFilterPanel')).toBeVisible();
    await expect(page.locator('#productTypeFilter')).toBeFocused();
    await page.locator('#productTypeFilter').selectOption('variable');
    await page.locator('#applyProductFilters').click();
    await expect(page.locator('#productFilterPanel')).toBeHidden();
    await expect(productCards).toHaveCount(1);
    await expect(productCards.first()).toContainText('بسته هدیه شب یلدا');

    await page.locator('#toggleProductFilters').click();
    await page.locator('#resetProductFilters').click();
    await expect(productCards).toHaveCount(6);

    const productViewTrigger = productCards.first().locator('.product-view-button');
    await productViewTrigger.click();
    await expect(page.locator('#productViewDialog')).toBeVisible();
    await expect(page.locator('#productViewTitle')).toContainText('کره بادام‌زمینی طبیعی');
    await expectFocusInside(page, '#productViewOverlay', 'product view should receive focus on open');
    await tabThrough(page, '#productViewOverlay', 4);

    const activeTab = page.locator('#productViewTabs [data-product-view-tab].is-active');
    await expect(activeTab).toHaveAttribute('aria-selected', 'true');
    await page.locator('#productViewTabs [data-product-view-tab="attributes"]').click();
    await expect(page.locator('#productViewTabs [data-product-view-tab="attributes"]')).toHaveAttribute('aria-selected', 'true');
    await page.keyboard.press('Escape');
    await expect(page.locator('#productViewDialog')).toBeHidden();
    await expect(productViewTrigger).toBeFocused();
  });

  test('validates product, category, coupon, and manual-order workflows', async ({ page }) => {
    await bootPreview(page);

    await navigateToSection(page, 'products');
    const newProduct = page.locator('#newProductButton');
    await newProduct.click();
    await expect(page.locator('#productEditorOverlay')).toBeVisible();
    await expect(page.locator('#productName')).toBeFocused();
    await tabThrough(page, '#productEditorOverlay', 4);
    await page.locator('#productEditorNext').click();
    await expect(page.locator('#productEditorMessage')).toContainText('نام محصول');
    await page.locator('#productName').fill('محصول آزمایشی');
    await page.locator('#productEditorNext').click();
    await expect(page.locator('.product-editor-step-button[data-product-editor-step-index="1"]')).toHaveClass(/is-active/);
    page.once('dialog', (dialog) => dialog.accept());
    await page.locator('#cancelProductEdit').click();
    await expect(page.locator('#productEditorOverlay')).toBeHidden();

    await navigateToSection(page, 'categories');
    const newCategory = page.locator('#newCategoryButton');
    await newCategory.click();
    await expect(page.locator('#categoryEditor')).toBeVisible();
    await expect(page.locator('#categoryName')).toBeFocused();
    await page.locator('#categoryName').fill('دسته‌بندی آزمایشی');
    await page.locator('#saveCategoryButton').click();
    await expect(page.locator('#categoryEditorMessage')).toContainText('نشست امن معتبر نیست');
    await page.locator('#cancelCategoryEdit').click();
    await expect(page.locator('#categoryEditor')).toBeHidden();

    await navigateToSection(page, 'coupons');
    const newCoupon = page.locator('#newCouponButton');
    await newCoupon.click();
    await expect(page.locator('#couponEditor')).toBeVisible();
    await expect(page.locator('#couponCode')).toBeFocused();
    await page.locator('#couponCode').fill('PREVIEW10');
    await page.locator('#couponAmount').fill('10');
    await page.locator('#saveCouponButton').click();
    await expect(page.locator('#couponEditorMessage')).toContainText('نشست امن معتبر نیست');
    await page.locator('#cancelCouponEdit').click();
    await expect(page.locator('#couponEditor')).toBeHidden();

    await navigateToSection(page, 'orders');
    const newOrder = page.locator('#newOrderButton');
    await newOrder.click();
    await expect(page.locator('#manualOrderOverlay')).toBeVisible();
    await expect(page.locator('.manual-order-step-button[data-manual-order-step-index="0"]')).toHaveClass(/is-active/);
    await expect(page.locator('#closeManualOrder')).toBeFocused();
    await expectFocusInside(page, '#manualOrderOverlay', 'manual-order wizard should receive focus on open');
    await page.locator('#manualOrderNext').click();
    await expect(page.locator('.manual-order-step-button[data-manual-order-step-index="1"]')).toHaveClass(/is-active/);
    await page.locator('#manualOrderNext').click();
    await expect(page.locator('#manualOrderMessage')).toContainText('حداقل یک قلم');
    await page.locator('.manual-order-add-item').click();
    await expect(page.locator('#manualOrderQuantity0')).toBeVisible();
    await page.locator('#manualOrderNext').click();
    await expect(page.locator('.manual-order-step-button[data-manual-order-step-index="2"]')).toHaveClass(/is-active/);
    await page.locator('#manualOrderNext').click();
    await expect(page.locator('.manual-order-step-button[data-manual-order-step-index="3"]')).toHaveClass(/is-active/);
    await expect(page.locator('#submitManualOrder')).toBeVisible();
    await tabThrough(page, '#manualOrderOverlay', 5);
    await page.keyboard.press('Escape');
    await expect(page.locator('#manualOrderOverlay')).toBeHidden();
    await expect(newOrder).toBeFocused();
  });

  test('keeps preview overlay shells inside the viewport', async ({ page }) => {
    await bootPreview(page);

    await navigateToSection(page, 'products');
    await page.locator('#newProductButton').click();
    await expect(page.locator('#productEditorDialog')).toBeVisible();
    assertNoHorizontalOverflow(await measure(page));
    await expectOverlayFits(page, '#productEditorDialog', 'product editor dialog');
    await page.locator('#cancelProductEdit').click();

    const productViewTrigger = page.locator('#productsGrid .product-view-button').first();
    await productViewTrigger.click();
    await expect(page.locator('#productViewDialog')).toBeVisible();
    assertNoHorizontalOverflow(await measure(page));
    await expectOverlayFits(page, '#productViewDialog', 'product view dialog', true);
    await page.locator('#closeProductView').click();

    await navigateToSection(page, 'coupons');
    await page.locator('#newCouponButton').click();
    await expect(page.locator('#couponEditor')).toBeVisible();
    assertNoHorizontalOverflow(await measure(page));
    await expectOverlayFits(page, '#couponEditor', 'coupon editor form');
    await page.locator('#cancelCouponEdit').click();

    await navigateToSection(page, 'orders');
    await page.locator('#newOrderButton').click();
    await expect(page.locator('#manualOrderForm')).toBeVisible();
    assertNoHorizontalOverflow(await measure(page));
    await expectOverlayFits(page, '#manualOrderForm', 'manual-order wizard dialog');
    await page.locator('#closeManualOrder').click();

    // Preview intentionally has no detail endpoint. Toggle these two static
    // shells directly so their responsive CSS is checked without pretending
    // this is an API/integration test.
    await page.evaluate(() => {
      document.getElementById('orderDetailPanel').hidden = false;
      document.body.classList.add('order-detail-open');
    });
    await expect(page.locator('#orderDetailDialog')).toBeVisible();
    assertNoHorizontalOverflow(await measure(page));
    await expectOverlayFits(page, '#orderDetailDialog', 'order detail dialog', true);
    await page.evaluate(() => {
      document.getElementById('orderDetailPanel').hidden = true;
      document.body.classList.remove('order-detail-open');
    });

    await navigateToSection(page, 'customers');
    await page.evaluate(() => {
      document.getElementById('customerDetailPanel').hidden = false;
    });
    await expect(page.locator('#customerDetailPanel')).toBeVisible();
    assertNoHorizontalOverflow(await measure(page));
    await expectOverlayFits(page, '#customerDetailPanel', 'customer detail panel');
    await page.evaluate(() => {
      document.getElementById('customerDetailPanel').hidden = true;
    });
  });

  test('opens and closes the mobile drawer through its real controls', async ({ page }) => {
    await bootPreview(page);
    const menuButton = page.locator('#mobileMenuToggle');

    if (await menuButton.isVisible()) {
      await menuButton.click();
      await expect(page.locator('#appSidebar')).toHaveClass(/is-open/);
      await expect(page.locator('#sidebarScrim')).toBeVisible();
      await expect(page.locator('#appSidebar a, #appSidebar button, #appSidebar input, #appSidebar select, #appSidebar textarea').first()).toBeFocused();
      assertNoHorizontalOverflow(await measure(page));
      await tabThrough(page, '#appSidebar', 4);
      // On a narrow RTL viewport the scrim center is behind the open drawer.
      // Click the exposed left edge, without bypassing pointer hit-testing.
      await page.locator('#sidebarScrim').click({ position: { x: 8, y: 8 } });
      await expect(page.locator('#appSidebar')).not.toHaveClass(/is-open/);
      await expect(menuButton).toBeFocused();
    }
  });
});
