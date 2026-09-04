# معماری و قراردادهای نگه‌داری Fandoogh Manager

این سند منبع تصمیم‌های فنی افزونه است. هدف آن این است که تغییرهای بعدی
بدون حدس‌زدن محل داده‌ها، مرزهای امنیتی یا وابستگی‌های WooCommerce انجام شوند.

## اصل داده‌های WooCommerce

- سفارش، محصول، مشتری، کوپن، variation و موجودی فقط با APIهای CRUD خود
  WooCommerce خوانده یا تغییر داده می‌شوند (`WC_*`، `wc_get_*` و `save`).
- برای داده‌های هستهٔ WooCommerce از SQL مستقیم، `WP_Query` وابسته به post
  storage یا نوشتن مستقیم post meta استفاده نمی‌شود. این قرارداد سازگاری با
  High-Performance Order Storage (HPOS) را حفظ می‌کند.
- دادهٔ اختصاصی افزونه باید یا در یک ماژول مستقل با schema/version روشن باشد
  یا، اگر به سفارش وابسته است، از `WC_Order` CRUD و یک meta key مستند استفاده کند.

## مرزهای امنیتی

- همهٔ routeهای خصوصی REST باید capability، scope نشست و CSRF را قبل از خواندن
  یا نوشتن داده بررسی کنند.
- پاسخ‌های خصوصی نباید cache شوند. service worker فقط shell و assetهای عمومی را
  cache می‌کند و هرگز `/wp-json/` را cache نمی‌کند.
- token خام، رمز عبور و دادهٔ حساس مشتری در log، localStorage یا analytics ذخیره
  نمی‌شود.
- هر عملیات حساس باید audit trail داشته باشد و داده‌های منقضی‌شده طبق retention
  policy پاک شوند.

## وب‌اپ، iOS و TWA

- وب‌اپ یک shell responsive است؛ viewportهای کوچک، چرخش صفحه و تغییر دستی عرض
  مرورگر باید بدون overflow افقی و با touch target حداقل 44px کار کنند.
- تجربهٔ «نصب» در UI فقط برای iOS نشان داده می‌شود. استانداردهای پایهٔ manifest و
  service worker همچنان برای Android نگه داشته می‌شوند، چون TWA به آن‌ها نیاز دارد.
- TWA یک wrapper Android است، نه جایگزین PWA. قبل از انتشار Android باید
  package id، SHA-256 گواهی امضا و فایل `/.well-known/assetlinks.json` برای دامنهٔ
  production پیکربندی و اعتبارسنجی شوند.
- تنظیمات مربوط به TWA نباید در frontend به‌صورت secret ذخیره شوند.

## چاپ و اشتراک‌گذاری (آماده‌سازی، نه قابلیت فعال)

- این قابلیت تا زمان تایید محصول فعال نمی‌شود.
- قرارداد پیشنهادی: یک snapshot فقط-خواندنی از سفارش از طریق `WC_Order` ساخته
  شود؛ سپس rendererهای PDF، تصویر و print browser همان snapshot را مصرف کنند.
- URLهای اشتراک‌گذاری باید زمان‌دار، قابل ابطال و بدون افشای اطلاعات مشتری در
  query string باشند. استفاده از سرویس ثالث یا نگه‌داری فایل جدید نیازمند تایید
  جداگانه است.

## کد رهگیری ارسال

کد رهگیری که مالک افزونه ارائه می‌کند، پیش از ادغام باید از نظر schema، meta key،
hookهای WooCommerce، قابلیت دسترسی و سازگاری HPOS بررسی شود. پس از آن، یک منبع
دادهٔ واحد برای صفحهٔ سفارش WooCommerce و API وب‌اپ ایجاد می‌شود؛ هیچ نسخهٔ دوم
یا متادیتای موازی ساخته نمی‌شود.

## سیاست تغییر

- قابلیت‌هایی که صریحاً در درخواست محصول نیستند، پیش از اجرا با دلیل، اثر داده‌ای
  و اثر امنیتی اعلام می‌شوند.
- هر تغییر باید شامل migration یا compatibility note، تست مناسب و به‌روزرسانی این
  سند در صورت تغییر قرارداد باشد.
- فایل build/package تولیدی نباید منبع حقیقت باشد؛ بستهٔ ZIP باید از سورس قابل
  تکرار ساخته شود.

## منابع رسمی

- https://developer.woocommerce.com/docs/best-practices/data-management/crud-objects/
- https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/recipe-book/
- https://developer.woocommerce.com/docs/best-practices/security/security-best-practices/
- https://developer.android.com/develop/ui/views/layout/webapps/trusted-web-activities
