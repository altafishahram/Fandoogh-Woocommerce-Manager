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

### ورود و آماده‌سازی داشبورد (۱٫۳٫۷)

ساخت نشست و بارگذاری داده‌های داشبورد دو مرحلهٔ مستقل‌اند. `loadDashboardData()` خطاهای هر بارگذار را جدا می‌کند؛ خطای نمایش یا دریافت اطلاعات نباید نشست تأییدشده را در رابط باطل کند. پاسخ واقعی ۴۰۱ از API همچنان کاربر را از نمای احراز‌شده خارج می‌کند.

اگر پاسخ POST جفت‌سازی قطع، ناقص یا ۵xx شود، ممکن است نشست و کوکی از قبل ساخته شده باشند. تنها یک بار با `/auth/me` و `/auth/csrf` بررسی انجام می‌شود؛ POST و کد یک‌بارمصرف تکرار نمی‌شوند و بدون هویت و CSRF معتبر داشبورد باز نمی‌شود. پاسخ صریح رد ورود (۴xx) به این بازیابی خودکار وارد نمی‌شود. تست `e2e/login-workflow.spec.js` این حالت‌ها و حفظ نشست در خطای نمایش را با API و کوکی آزمایشی بررسی می‌کند، نه ورود واقعی به فروشگاه.

هشدار ایمیلی نشست نیز بخشی از پاسخ ورود نیست. بعد از ثبت نشست، فقط شناسهٔ کاربر، شناسهٔ نشست و برچسب محدودشدهٔ دستگاه در `wp_schedule_single_event()` صف می‌شوند؛ رمز، کد جفت‌سازی، کوکی و CSRF هرگز وارد صف نمی‌شوند. خطای SMTP یا شکست WP-Cron نباید نشست ساخته‌شده را نامعتبر کند. هنگام غیرفعال‌سازی افزونه، رویدادهای هشدار و پاک‌سازی حذف می‌شوند.

- همهٔ routeهای خصوصی REST باید capability، scope نشست و CSRF را قبل از خواندن
  یا نوشتن داده بررسی کنند.
- پاسخ‌های خصوصی نباید cache شوند. service worker فقط shell و assetهای عمومی را
  cache می‌کند و هرگز `/wp-json/` را cache نمی‌کند.
- token خام، رمز عبور و دادهٔ حساس مشتری در log، localStorage یا analytics ذخیره
  نمی‌شود.
- هر عملیات حساس باید audit trail داشته باشد و داده‌های منقضی‌شده طبق retention
  policy پاک شوند.

## وب‌اپ و نصب PWA

### تحویل فایل‌های پوسته (۱٫۳٫۶)

فایل‌های CSS، JavaScript، فونت پویا، manifest و service worker با نشانی‌هایی مانند `/manager/?fandoogh_manager_asset=styles.css&ver=…` دریافت می‌شوند. علت این تصمیم، ۴۰۴ واقعی فایل `/manager/styles.css` در Nginx است: مسیر مجازی فایل استاتیک ممکن است پیش از رسیدن درخواست به WordPress متوقف شود، درحالی‌که مسیر خود `/manager/` کار می‌کند. `app_asset_urls()` منبع واحد این URLها است و `maybe_serve_app_asset()` همان فهرست مجاز محدود قبلی را اعمال می‌کند؛ هیچ مسیر دلخواه یا فایل PHP خصوصی تحویل داده نمی‌شود. قالب URL از query var عمومیِ ازقبل ثبت‌شدهٔ افزونه استفاده می‌کند؛ این روش با [قرارداد query_vars وردپرس](https://developer.wordpress.org/reference/hooks/query_vars/) سازگار است.

مسیر برنامه، مبدأ، سیاست CSP و نوع محتوای فایل‌ها تغییر نکرده‌اند. آدرس script سرویس‌ورکر همچنان در دایرکتوری `/manager/` است، بنابراین محدودهٔ نصب گسترده‌تر نمی‌شود ([قواعد scope سرویس‌ورکر](https://developer.mozilla.org/en-US/docs/Web/API/ServiceWorkerContainer/register)). worker هم روش query و هم آدرس‌های قدیمی را می‌شناسد. فقط shell و فایل‌های عمومی مشخص کش می‌شوند؛ پارامترهای ناشناخته/تکراری، `rest_route`، مسیرهای REST زیرپوشه‌ها و همهٔ درخواست‌های غیر GET کنار گذاشته می‌شوند. مسیرهای rewrite قبلی برای سازگاری حذف نشده‌اند.

تست‌های `app-assets-contract` و `app-assets-routing` از توابع واقعی PHP با بدل‌های WordPress استفاده می‌کنند، نه از یک WordPress نصب‌شده. تست دوم روی ریشه و `/shop/`، پاسخ ۴۰۴ مسیرهای قدیمی و بارگذاری موفق ظاهر/اجرای ورود را بررسی می‌کند. بعد از نصب ZIP روی میزبان مقصد، بررسی مجدد صفحهٔ ورود همچنان لازم است.

- وب‌اپ یک shell responsive است؛ viewportهای کوچک، چرخش صفحه و تغییر دستی عرض
  مرورگر باید بدون overflow افقی و با touch target حداقل 44px کار کنند.
- دکمهٔ نصب در مرورگرهایی که رویداد استاندارد نصب PWA را ارائه می‌کنند نمایش داده
  می‌شود و در iOS راهنمای دستی Add to Home Screen در دسترس است. manifest و service
  worker برای اجرای standalone وب‌اپ، cache محدود پوسته و چرخهٔ به‌روزرسانی خود PWA
  نگه داشته می‌شوند.

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

### بازآرایی مرحله‌ای و حفظ قرارداد (فازهای ۱ و ۲)

بازآرایی بدون تغییر قرارداد عمومی انجام می‌شود. فایل‌های قدیمی همچنان مالک
نام تابع‌های callback و هوک‌ها هستند؛ کلاس‌های جدید فقط در composition root
ساخته می‌شوند و وابستگی‌های WordPress/WooCommerce را از منطق ساخت payload جدا
می‌کنند. برای هر ماژول، baseline نسخهٔ قبل و مقایسهٔ خروجی، خطا، هدر و ترتیب
فراخوانی API نگه‌داری می‌شود.

در فاز نخست، inventory قراردادهای موجود در
`tests/fixtures/refactor/contracts.json` ثبت شده است. این فایل فقط با گزینهٔ
صریح `--capture` ساخته می‌شود و بعد از ایجاد baseline قابل overwrite نیست.
فاز دوم با `app/src/Catalog`، `app/src/Infrastructure` و
`app/composition/product-attributes.php` برای ویژگی‌های محصول شروع شده است؛
endpoint قدیمی `list_product_attributes` و مسیر `/product-attributes` عمداً
بدون تغییر باقی مانده‌اند.

در فاز سوم، parser بدنهٔ کوپن در `Catalog/CouponRequestParser.php` و adapter
دادهٔ کوپن در `Infrastructure/WooCommerceCouponRepository.php` قرار گرفته‌اند.
`app/composition/coupons.php` تنها محل wiring factoryهای `WC_Coupon` و
`WP_Query` است؛ بدین ترتیب callbackهای کوپن به نام‌های قبلی خود باقی می‌مانند
و ساخت آبجکت‌های زیرساختی از منطق endpoint جدا می‌شود.

در فاز چهارم، خواندن و ساخت محصولات در
`Infrastructure/WooCommerceProductRepository.php` قرار گرفته و factory نوع
محصول در `app/composition/products.php` wire شده است. مسیرهای لیست، جزئیات،
ایجاد و ویرایش محصول همچنان همان callbackها و payloadهای قبلی را مصرف و تولید
می‌کنند؛ فقط وابستگی‌های CRUD از orchestration آن‌ها جدا شده‌اند.
خواندن محصول والد/variation و آیتم موجودی نیز از همین repository استفاده
می‌کند تا یک مسیر واحد برای دسترسی CRUD باقی بماند. ساخت variation و
`WC_Product_Attribute` و lookup SKU نیز در همین composition root و adapter
متمرکز است؛ callbackهای قدیمی فقط use case و orchestration را نگه می‌دارند.

### پوستهٔ مدیریت تب‌بندی‌شده (۱٫۳٫۸)

`app/admin.php` مالک نقشهٔ تب‌ها، مسیریابی allowlist، فرم‌های مستقل و `sanitize_admin_settings()` است. تابع پایهٔ `sanitize_settings()` همچنان قرارداد گزینهٔ کامل و خواندن عمومی را اعتبارسنجی می‌کند. تنها callback ثبت‌شده در Settings API، نشانگر موقت `_tab` را می‌خواند؛ کلیدهای مجاز همان تب را با آخرین `get_settings()` ادغام و سپس اعتبارسنجی می‌کند. نشانگر ذخیره نمی‌شود؛ تب ناشناخته هیچ تغییری نمی‌دهد. چک‌باکس حذف‌شده از POST فقط در تب مالک خودش false می‌شود. اجرای دوبارهٔ sanitizer روی خروجی کامل بی‌اثر است.

پاک‌سازی هشدارهای نشست هنگام غیرفعال‌سازی با [wp_unschedule_hook](https://developer.wordpress.org/reference/functions/wp_unschedule_hook/) انجام می‌شود تا همهٔ ترکیب‌های آرگومان شناسهٔ کاربر/نشست حذف شوند؛ حذف hook با آرگومان خالی، رویدادهای دارای آرگومان را پاک نمی‌کند.

ذخیره از `options.php` با گروه گزینه، nonce و `manage_options` قبلی انجام می‌شود؛ فرم‌های جفت‌سازی، فونت، دسترسی و ابطال نشست جدا هستند و درون فرم تنظیمات قرار نمی‌گیرند. ناوبری لینک واقعی با `aria-current` است و بدون JavaScript کار می‌کند. استایل‌ها در `assets/css/admin.css` و زیر کلاس ریشهٔ اختصاصی افزونه محدود هستند. برای افزودن تب ابتدا نقشهٔ کلیدها/sectionها و تست حفظ گزینه‌های دیگر را به‌روز کنید. [Settings API](https://developer.wordpress.org/reference/functions/register_setting/) و [رندر فیلدهای یک section](https://developer.wordpress.org/reference/functions/do_settings_fields/) مرجع این قرارداد هستند.

فعال‌بودن عمومی گزارش مالی با scope نشست یکسان نیست: scopeهای زمان صدور و مجوزهای فعلی اشتراک گرفته می‌شوند. وقتی analytics پس از صدور کد/نشست فعال شود، صدور کد جدید و ورود مجدد لازم است. رابط اکنون گزارش فعال را حتی بدون scope قابل پیدا کردن نگه می‌دارد، ولی هیچ دادهٔ مالی نمی‌خواند و راهنمای دسترسی نشان می‌دهد. کنترل backend و no-store تغییر نکرده است. پاسخ نامعتبر به پرس‌وجوی صفحه‌بندی‌شدهٔ WooCommerce خطای 503 می‌دهد، نه گزارش ظاهراً خالی.

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
