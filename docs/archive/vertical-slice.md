# قرارداد vertical slice فندوق

وضعیت این سند: فاز Pairing/Session، Products Read/Write محدود، Categories، Variations، مدیریت Orders با فیلتر/وضعیت/جزئیات/یادداشت/refund/fulfillment جزئی، Customers Read، جزئیات billing/shipping سفارش، کوپن، دیدگاه، مرکز موجودی، رهگیری دستی ارسال، Media WebP، گالری محصول، فونت محلی، چرخهٔ نصب/به‌روزرسانی PWA، UI/قرارداد Devices/Audit، طراحی یکپارچهٔ PWA و wp-admin، نمایش تومان و تحلیل اختیاری WooCommerce پیاده‌سازی شده‌اند. اجرای واقعی روی WordPress/WooCommerce و تست PHP/HTTPS باید در محیط آزمایشی انجام شود.

## معماری

یک vertical slice کوچک باید مسیر کامل زیر را پوشش دهد:

```text
کاربر
  → PWA در مرورگر
  → قرارداد HTTP/JSON هم‌مبدأ
  → افزونهٔ WordPress
  → احراز هویت، nonce و capability
  → منبع دادهٔ WordPress
  → پاسخ قراردادی و نمایش/ذخیرهٔ محدود در PWA
```

مرزهای پیشنهادی اجزا:

1. **میزبان WordPress و افزونه:** ثبت route یا handler، بررسی نشست کاربر، nonce و capability، اعتبارسنجی ورودی و تبدیل دادهٔ داخلی به پاسخ قراردادی.
2. **قرارداد API:** نام عملیات، ورودی/خروجی JSON، کدهای خطا، وضعیت احراز هویت و قواعد نسخه‌بندی. این لایه نباید جزئیات جدول یا مدل داخلی را به PWA تحمیل کند.
3. **PWA:** shell، نمایش وضعیت loading/error/empty، ارسال درخواست به مبدأ مورد اعتماد و نگهداری حداقلی دادهٔ cache‌شده. PWA نباید secret یا مجوز WordPress را ذخیره کند.
4. **مرز مرورگر:** manifest و service worker فقط از مبدأ مورد اعتماد اجرا شوند؛ cache باید نسخه‌دار و قابل پاک‌سازی باشد.

مسیرهای پیاده‌سازی‌شده:

```text
/manager/
/manager/app.js
/manager/styles.css
/manager/sw.js
/manager/manifest.webmanifest
/wp-json/fandoogh-manager/v1/health
/wp-json/fandoogh-manager/v1/config
/wp-json/fandoogh-manager/v1/auth/pair
/wp-json/fandoogh-manager/v1/auth/me
/wp-json/fandoogh-manager/v1/auth/csrf
/wp-json/fandoogh-manager/v1/auth/logout
/wp-json/fandoogh-manager/v1/auth/devices
/wp-json/fandoogh-manager/v1/auth/devices/{id}/revoke
/wp-json/fandoogh-manager/v1/auth/devices/revoke-all
/wp-json/fandoogh-manager/v1/auth/audit
/wp-json/fandoogh-manager/v1/products
/wp-json/fandoogh-manager/v1/products/{id}
/wp-json/fandoogh-manager/v1/product-attributes
/wp-json/fandoogh-manager/v1/products/{id}/variations
/wp-json/fandoogh-manager/v1/products/{id}/variations/{variation_id}
/wp-json/fandoogh-manager/v1/product-categories
/wp-json/fandoogh-manager/v1/product-categories/{id}
/wp-json/fandoogh-manager/v1/customers
/wp-json/fandoogh-manager/v1/customers/{id}
/wp-json/fandoogh-manager/v1/customers/{id}/orders
/wp-json/fandoogh-manager/v1/orders
/wp-json/fandoogh-manager/v1/orders/{id}
/wp-json/fandoogh-manager/v1/orders/{id}/status
/wp-json/fandoogh-manager/v1/orders/{id}/shipment  (GET/POST)
/wp-json/fandoogh-manager/v1/orders/{id}/notes (POST)
/wp-json/fandoogh-manager/v1/orders/{id}/refund (POST)
/wp-json/fandoogh-manager/v1/inventory
/wp-json/fandoogh-manager/v1/inventory/{id}
/wp-json/fandoogh-manager/v1/coupons
/wp-json/fandoogh-manager/v1/coupons/{id}
/wp-json/fandoogh-manager/v1/reviews
/wp-json/fandoogh-manager/v1/reviews/{id}
/wp-json/fandoogh-manager/v1/analytics/summary  (اختیاری)
/wp-json/fandoogh-manager/v1/media
```

در vertical slice نخست، یک use case خواندنی و کوچک کافی است. قبل از افزودن قابلیت نوشتن، قرارداد خطا، مجوز و رفتار آفلاین همان use case باید روشن باشد.

## تهدیدهای فعلی

| تهدید | پیامد | وضعیت/قاعدهٔ فعلی |
| --- | --- | --- |
| endpoint یا schema هنوز کامل نشده | اختلاف بین PWA و افزونه و شکست‌های مبهم | Products، Categories، Variations، Customers، Orders، notes/refund، fulfillment، coupons، reviews، inventory، shipment، analytics و Devices/Audit قرارداد پایه و UI دارند؛ runtime واقعی هنوز باز است |
| نبود تست runtime برای nonce و capability | درخواست جعلی یا دسترسی بیش از حد | مرزها در سورس جدا شده‌اند؛ اجرای WordPress/HTTPS واقعی لازم است |
| تزریق XSS از دادهٔ WordPress | اجرای کد در مرورگر کاربر | داده‌ها escape شوند و HTML خام از API پذیرفته نشود مگر با قرارداد صریح |
| service worker و cache قدیمی | نمایش دادهٔ منقضی یا رفتار ناسازگار پس از deploy | cache نسخه‌دار، محدود و قابل invalidation باشد |
| URL یا asset برون‌سازمانی | نشت داده، وابستگی پنهان و شکست در محیط بسته | در این baseline ممنوع و با تست ایستا بررسی می‌شود |
| CORS یا مبدأ اشتباه | نشت نشست یا خطای دسترسی مرورگر | ارتباط پیش‌فرض هم‌مبدأ؛ مبدأهای مجاز باید صریح و محدود باشند |
| ورودی بدون اعتبارسنجی و محدودسازی | خطا، abuse یا فشار روی WordPress | Products/Customers/Orders/notes/refund/shipment/coupons/reviews/inventory/analytics allowlist و محدودیت طول/تاریخ/بازه دارند؛ تست runtime لازم است |
| نگهداری secret در frontend | افشای اعتبارنامه در bundle یا storage | secret فقط سمت سرور؛ PWA صرفاً از نشست/توکن قراردادی استفاده کند |

## مرزهای فعلی

- افزونه در `fandoogh-manager/fandoogh-manager.php` و پوستهٔ PWA در `fandoogh-manager/app/` قرار دارد.
- هیچ package، library، سرویس بیرونی یا مرحلهٔ build اضافه نمی‌شود.
- تست فعلی ایستا/قراردادی است: sourceهای پایه، routeهای فعلی، نبود storage/token در frontend و نبود URL برون‌سازمانی را بررسی می‌کند.
- اجرای WordPress واقعی، بررسی Cookie/HTTPS و اتصال واقعی به داده‌های WooCommerce باید در محیط آزمایشی تکمیل شود.
- Pairing، Session، Products Read/Write محدود و فیلدهای پیشرفته، Categories، Variations، Orders Read/Status/Filters، یادداشت و refund allowlist‌شده، fulfillment جزئی، Customers Read و تاریخچهٔ سفارش مشتری، کوپن، دیدگاه، موجودی، جزئیات روش‌های ارسال سفارش، رهگیری دستی shipment، Media WebP، گالری محصول، فونت محلی، UI Devices/Audit، تحلیل اختیاری WooCommerce و توکن‌های طراحی مشترک در سورس وجود دارند؛ runtime واقعی هنوز باز است.

## چیزهایی که تست ایستای فعلی ثابت نمی‌کند

تست `tests/verify-baseline.ps1` فقط متن و ساختار فایل‌ها را می‌بیند. تا پیش از اجرای WordPress واقعی، این موارد تست‌شده محسوب نمی‌شوند:

- parse و اجرای PHP، ثبت rewrite rule و پاسخ واقعی REST؛
- ساخته‌شدن cookie با `HttpOnly`، `Secure` و `SameSite` و انقضای واقعی آن؛
- یک‌بارمصرف‌بودن Pairing، TTL، revoke، rate limit و جلوگیری از replay؛
- اعتبارسنجی nonce/CSRF، احراز هویت، capability و جداسازی نقش‌ها؛
- query واقعی WooCommerce، سازگاری HPOS، pagination، status filter و allowlist فیلدهای محصول/مشتری/سفارش؛
- رفتار خطا و status code در مرورگر، HTTPS واقعی، cache header و Service Worker در چند نسخهٔ deploy؛
- migration جدول audit، ابطال واقعی نشست از دستگاه دیگر، revoke-all و سطح دسترسی audit؛
- ثبت و خواندن snapshot رهگیری ارسال از order meta با `WC_Order` CRUD، allowlist وضعیت/فیلد، no-store، CSRF، scope/capability و Audit؛
- محاسبهٔ تحلیل فروش از WooCommerce CRUD، کنترل تنظیم `analytics_enabled`، scope `analytics.read`، capability مدیریتی، محدودیت تعداد سفارش و پاسخ aggregate بدون دادهٔ حساس؛
- نگاشت نمایشی واحد پول `IRT` به «تومان» بدون تغییر مقدار ذخیره‌شده در WooCommerce و نمایش اعداد فارسی/تاریخ جلالی در PWA؛
- هم‌راستایی پنل افزونه با PWA از طریق CSS محلی، توکن‌های رنگ، کارت، فاصله و دکمهٔ مشترک بدون وابستگی خارجی؛
- تبدیل تصویر به WebP، فونت محلی، عملکرد سرور و سازگاری با نسخه‌های واقعی WordPress/WooCommerce.

برای پذیرش فاز Pairing/Session، Products read، Customers read و Orders detail باید یک سایت آزمایشی WordPress با HTTPS، WooCommerce، کاربرهای نقش‌دار، دادهٔ محصول/مشتری/سفارش و CI دارای PHP/JavaScript اضافه شود.

## فازهای انجام‌شده: احراز هویت، کاتالوگ، فروش و رسانه

1. Pairing یک‌بارمصرف، TTL، revoke و rate limit در افزونه پیاده شده است.
2. Session Cookie اختصاصی PWA، logout و idle/absolute expiry پیاده شده است.
3. CSRF و capability برای Products و Media مستقل شده‌اند.
4. قرارداد `GET /wp-json/fandoogh-manager/v1/products` با pagination، search و status filter اجرا شده است.
5. Products read با API/CRUD رسمی WooCommerce و بدون SQL خام متصل شده است.
6. Media upload با محدودیت ورودی، تبدیل WebP، ابعاد/کیفیت/حجم خروجی و حفظ اختیاری اصل فایل اجرا شده است.
7. فونت محلی با امضای فایل، مسیر جدا و CSS هم‌مبدأ اجرا شده است.
8. Products Create/Update محدود به نوع Simple/Variable با ویژگی‌های والد allowlist‌شده، scope، CSRF، validation و WooCommerce CRUD اضافه شده است.
9. Orders Read با pagination، جست‌وجو، فیلتر وضعیت/تاریخ/مبلغ/مشتری/روش‌های پرداخت و ارسال و serializer allowlist اضافه شده است.
10. تغییر وضعیت سفارش با scope مستقل، CSRF و `update_status()` رسمی WooCommerce اضافه شده است؛ پرداخت و refund خارج از scope این slice هستند.
11. Categories با scope مستقل و `get_terms()`/`wp_insert_term()`/`wp_update_term()` رسمی WordPress اضافه شده است.
12. Variations با parent `variable`، allowlist ویژگی‌ها و `WC_Product_Variation` CRUD اضافه شده است.
13. Customers Read با `WC_Customer_Data_Store`، scope مستقل، capability مدیریتی و serializer allowlist اضافه شده است.
14. Order detail با billing/shipping، line items و shipping lines از getterهای رسمی WooCommerce به UI متصل شده است.
15. جدول append-only Audit برای رویدادهای نشست و mutationهای حساس و routeهای `auth/audit` اضافه شده است.
16. routeهای `auth/devices`، revoke تکی و revoke-all دیگر نشست‌های همان کاربر را بدون افشای hash/cookie فراهم می‌کنند.
17. UI مدیریت Devices/Audit با نمایش نشست فعلی، revoke نشست‌های دیگر، فیلتر رویداد و pagination اضافه شده است.
18. گالری محصول به فیلدهای رسمی `image_id` و `gallery_ids`، آپلود Media WebP و انتخاب تصویر شاخص متصل شده است.
19. Administrator می‌تواند در Settings کاربر هدف Pairing را انتخاب کند؛ کد به همان کاربر متصل می‌ماند، احراز هویت با رمز کاربر هدف انجام می‌شود و scopeها هنگام مصرف کد با capability فعلی تقاطع داده می‌شوند. تحلیل اختیاری نیز فقط پس از فعال‌سازی تنظیم آن و Pairing دوباره با scope `analytics.read` در دسترس است.
20. تغییر وضعیت سفارش پس از CRUD با getter رسمی WooCommerce تأیید می‌شود؛ پاسخ mutation در UI اعمال می‌شود و refreshهای دیرهنگام با شناسهٔ درخواست و retry کنترل می‌شوند تا ذخیرهٔ موفق به‌اشتباه خطا نمایش داده نشود.
21. چرخهٔ نصب PWA، راهنمای iOS، اعلان update worker و جلوگیری از mutation در حالت offline در پوستهٔ هم‌مبدأ اضافه شده است؛ دادهٔ خصوصی و پاسخ API وارد Cache نمی‌شود.

## قرارداد اجرایی رهگیری ارسال provider-neutral

این قرارداد در نسخهٔ 1.7.0 اجرا و در نسخهٔ جاری 1.15.0 حفظ شده است. هدف، ثبت و نمایش وضعیت دستی ارسال در همان سایت است؛ PWA نباید به provider حمل‌ونقل وصل شود و افزونه نیز در این فاز هیچ API، webhook، polling، credential یا سرویس tracking بیرونی ندارد.

### Routeها

```text
GET  /wp-json/fandoogh-manager/v1/orders/{id}/shipment
POST /wp-json/fandoogh-manager/v1/orders/{id}/shipment
```

`GET` پاسخ موفق را در envelope قراردادی `data` و با snapshot allowlist‌شدهٔ همان سفارش برمی‌گرداند. اگر snapshot وجود نداشته باشد، پاسخ قراردادی می‌تواند مقدار پیش‌فرض `status: pending` و بقیهٔ فیلدهای nullable خالی را بدون نوشتن ضمنی ایجاد کند. `POST` یک snapshot را ایجاد یا به‌روزرسانی می‌کند و فقط روی همان سفارش عمل می‌کند؛ این route نباید وضعیت سفارش WooCommerce، payment، refund یا shipping line را تغییر دهد.

### مدل داخلی site-local

ذخیرهٔ فعلی، یک snapshot JSON کنترل‌شدهٔ site-local در order meta از طریق `WC_Order` CRUD است؛ کلید داخلی `_fandoogh_shipment` است. خواندن/نوشتن باید از getter/setterهای رسمی سفارش انجام شود و به SQL، `update_post_meta()` مستقیم یا ساختار جدول داخلی WooCommerce وابسته نباشد تا HPOS و حالت legacy هر دو قابل آزمون بمانند. این سند بخشی از دادهٔ همان سایت است و با حذف/انتقال سفارش باید سیاست lifecycle مشخص داشته باشد؛ schema version داخلی در migrationهای بعدی قابل اضافه‌شدن است.

snapshot فقط این فیلدها را دارد:

| فیلد | نوع و قاعده | خروجی/ورودی |
| --- | --- | --- |
| `status` | رشتهٔ اجباری از allowlist بسته | ورودی و خروجی |
| `tracking_code` | رشتهٔ دستی، حداکثر 100 نویسه، بدون HTML/URL اجباری؛ تهی‌کردن با مقدار خالی یا `null` مجاز | اختیاری و خروجی |
| `carrier_label` | برچسب متنی نمایشی، حداکثر 80 نویسه، بدون شناسهٔ provider | اختیاری و خروجی |
| `shipped_at` | تاریخ‌زمان ISO-8601 معتبر با timezone یا `null` | اختیاری و خروجی |
| `estimated_delivery_date` | تاریخ `YYYY-MM-DD` یا `null` | اختیاری و خروجی |
| `delivered_at` | تاریخ‌زمان ISO-8601 معتبر با timezone یا `null` | اختیاری و خروجی |
| `note` | متن کوتاه حداکثر 500 نویسه، بدون HTML | اختیاری و خروجی |
| `updated_at` | زمان تولیدشده توسط سرور | فقط خروجی |

allowlist وضعیت داخلی عبارت است از `pending`, `ready`, `shipped`, `in_transit`, `out_for_delivery`, `delivered`, `failed`, `returned` و `cancelled`. وضعیت ناشناخته، وضعیت provider-specific یا هر کلید ناشناخته باید با `422` رد شود. حذف فیلد اختیاری از body به‌معنای حفظ مقدار فعلی است؛ پاک‌کردن مقدار فقط با قاعدهٔ صریح نوع همان فیلد انجام می‌شود. فیلدهای `provider`, `provider_id`, `tracking_url`, `webhook`, `raw_payload`, `events` و meta دلخواه در قرارداد نیستند.

`tracking_code` فقط دادهٔ دستی است: افزونه آن را با provider تطبیق نمی‌دهد، به لینک خارجی تبدیل نمی‌کند، از آن استعلام نمی‌گیرد و صحت آن را تضمین نمی‌کند. `carrier_label` نیز صرفاً یک label است، نه اتصال به شرکت حمل‌ونقل.

### دسترسی، پاسخ و Audit

- `GET` به Session معتبر، scope `orders.read` و capability واقعی WordPress یعنی `manage_woocommerce` یا `manage_options` نیاز دارد.
- `POST` به Session معتبر، scope مستقل `orders.update_shipment`، همان capability، بدنهٔ `application/json`، header `X-Fandoogh-CSRF` و Origin هم‌سایت نیاز دارد. ورود به PWA به‌تنهایی مجوز تغییر ارسال نیست.
- در ارتقا از نسخهٔ قبل از 1.7.0، برای دریافت scope جدید باید نشست قبلی logout و Pairing دوباره انجام شود؛ scope نشست‌های قبلی عمداً خودکار تغییر نمی‌کند. در نسخهٔ 1.15.0 برای صدور کد برای کاربر دیگر، Administrator باید کاربر هدف را در Settings انتخاب کند؛ refresh سفارش‌ها با شناسهٔ درخواست کنترل می‌شود، Service Worker نیز update را با انتخاب کاربر فعال می‌کند و تحلیل جدید پس از فعال‌سازی به Pairing دوباره نیاز دارد. پوستهٔ جدید قبل از نمایش ورود، نشست امن موجود را بررسی می‌کند.

### قرارداد افزایش گروهی قیمت

- Preview و Execute روی `POST /products/bulk-price` انجام می‌شوند و اجرای نهایی به confirmation token یک‌بارمصرف و کوتاه‌عمرِ همان نشست و همان پارامترها نیاز دارد.
- ورودی فقط شامل `category_ids`, `include_children`, `adjustment_type`, `amount`, `execute`, `confirmation_token` است و هر فیلد ناشناخته رد می‌شود.
- دسته‌ها با taxonomy رسمی WordPress و محصولات/variationها با CRUD رسمی WooCommerce خوانده و ذخیره می‌شوند؛ SQL و postmeta مستقیم ممنوع است.
- برای جلوگیری از عملیات بیش از ظرفیت، هر درخواست حداکثر ۵۰۰ محصول والد و ۳۰۰۰ رکورد قیمت را پردازش می‌کند.
- هر دو route باید پاسخ موفق و خطا را با `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`، `Pragma: no-cache` و `X-Content-Type-Options: nosniff` برگردانند؛ Service Worker نباید پاسخ آن‌ها را cache کند و خطای احراز هویت نباید به `wp-admin` redirect شود.
- سفارش ناموجود یا غیرقابل‌خواندن باید پاسخ قراردادی `404` بدهد و نباید دادهٔ سفارش دیگر را افشا کند. WooCommerce غیرفعال یا API سفارش در دسترس‌نباشد باید `503` بدهد.
- هر POST موفق باید event بستهٔ `shipment_updated` را در Audit append-only ثبت کند؛ context Audit فقط `order_id`، status نهایی و outcome محدود را نگه دارد و tracking code، note، body خام، CSRF، cookie، token یا secret را ذخیره نکند. شکست Audit نباید به نوشتن دوم یا گزارش موفقیت جعلی منجر شود.

### runtime لازم قبل از پذیرش

این قرارداد تا زمانی پذیرفته‌شده محسوب نمی‌شود که روی WordPress/WooCommerce واقعی با HTTPS، PHP و دادهٔ واقعی سفارش آزموده شود. حداقل ماتریس آزمون شامل مدیر اصلی با `manage_options`، مدیر فروشگاه با `manage_woocommerce`، کاربر دارای دسترسی محصول ولی بدون مجوز سفارش، و کاربر عادی است؛ scope نشست و capability باید در هر درخواست جداگانه بررسی شوند. باید HPOS روشن و خاموش، سفارش موجود/ناموجود، WooCommerce غیرفعال، پاسخ‌های no-store، CSRF و Origin نامعتبر، JSON ناقص، status/field ناشناخته، طول بیش از حد، تاریخ نامعتبر، ماندگاری snapshot پس از reload و ثبت Audit بدون raw tracking code بررسی شود.

## فاز بعد: اجرای runtime و انتشار امن

1. چک‌لیست اجرایی [docs/runtime-staging-checklist.md](runtime-staging-checklist.md) را روی WordPress/WooCommerce واقعی با HTTPS، HPOS، نقش‌های مختلف، image engine و مرورگرهای موبایل اجرا کنید و معیارهای shipment را تیک بزنید.
2. قبل از نصب روی staging، تست release مستقل `tests/verify-release.ps1` و baseline را اجرا کنید؛ سپس تست‌های واقعی WordPress/WooCommerce، HPOS، HTTPS، migration جدول audit و CI دارای PHP را ثبت کنید.
3. Customer Write را فقط پس از تعیین نقش، audit و مجوز فیلدبه‌فیلد طراحی کنید؛ در این فاز endpoint مشتری همچنان read-only است.

معیار پذیرش baseline در `tests/acceptance-criteria.md` ثبت شده است.
