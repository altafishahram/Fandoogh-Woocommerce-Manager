# معیارهای پذیرش فاز پایه و Pairing/Session

## معیارهای Vertical Slice

- [x] `README.md` به فارسی و کوتاه است و نصب افزونه، مسیر PWA و بررسی پایه را توضیح می‌دهد.
- [x] `docs/vertical-slice.md` معماری، تهدیدهای فعلی، مرزهای فعلی و فاز بعد را پوشش می‌دهد.
- [x] افزونه و فایل‌های PWA در مسیرهای مستندشده وجود دارند.
- [x] مسیر `/manager/` App Shell را از داخل افزونه سرو می‌کند.
- [x] شورت‌کد `[fandoogh_manager]` فقط Launcher هم‌مبدأ PWA را تولید می‌کند و `wp-admin` را Embed نمی‌کند.
- [x] Manifest، JavaScript، CSS و Service Worker مسیرهای هم‌مبدأ دارند.
- [x] نام سایت و لوگوی هم‌مبدأ WordPress در پوسته و Manifest قابل استفاده است.
- [x] Service Worker پاسخ‌های `/wp-json/` را Cache نمی‌کند.
- [x] دکمهٔ نصب PWA در مرورگرهای دارای `beforeinstallprompt` از prompt بومی استفاده می‌کند و در iOS راهنمای Safari/Add to Home Screen دارد.
- [x] Service Worker نسخهٔ جدید را تا انتخاب کاربر در حالت waiting نگه می‌دارد و با پیام کنترل‌شده فعال می‌کند؛ cache فقط App Shell نسخه‌دار است.
- [x] وضعیت `online`/`offline` به کاربر اعلام می‌شود و mutationهای POST/PUT/PATCH/DELETE در حالت آفلاین قبل از ارسال متوقف می‌شوند.
- [ ] نصب، update و بازگشت از حالت offline روی Chrome/Edge دسکتاپ، Android و Safari iOS در WordPress واقعی اجرا و ثبت می‌شود.

## معیارهای قرارداد و امنیت پایه

- [x] vertical slice نخست یک مسیر روشن از PWA تا افزونه و endpointهای عمومی config/health دارد.
- [x] احراز هویت، nonce، capability، validation و escape به‌عنوان الزام قرارداد ثبت شده‌اند.
- [x] secret در frontend یا storage مرورگر قرار نمی‌گیرد.
- [x] URL و asset برون‌سازمانی در baseline مجاز نیست؛ loopback محلی برای اجرای PWA مجاز است.
- [x] service worker و cache نسخه‌دار و محدود به App Shell هستند.
- [x] Pairing یک‌بارمصرف با TTL ده‌دقیقه‌ای، مصرف اتمیک و ابطال پس از موفقیت پیاده شده است.
- [x] Pairing فقط روی HTTPS/loopback توسعه، با JSON، Origin هم‌سایت و rate limit انجام می‌شود؛ secret در URL دائمی یا bundle قرار نمی‌گیرد.
- [x] Session اختصاصی PWA با cookie `HttpOnly`، `Secure` در HTTPS و `SameSite=Strict` ایجاد می‌شود؛ session در `localStorage` یا `sessionStorage` نگهداری نمی‌شود.
- [x] routeهای عملیاتی بدون Session معتبر `401` یا `403` می‌دهند و به `wp-admin` redirect نمی‌کنند.
- [x] logout با CSRF اختصاصی و بررسی Origin محافظت شده و انقضای absolute/idle برای Session وجود دارد.
- [x] Capability برای Products read در هر درخواست با Scope نشست و capability واقعی WordPress بررسی می‌شود.
- [x] Products read با route نسخه‌دار `GET /wp-json/fandoogh-manager/v1/products`، pagination محدود، search اختیاری و فیلتر status قرارداد دارد.
- [x] پاسخ Products read فقط فیلدهای allowlist‌شدهٔ محصول را برمی‌گرداند و `postmeta` دلخواه، SQL خام، password و token را افشا نمی‌کند.
- [x] Products read برای WooCommerce فعال/غیرفعال، محصول ناموجود، پارامتر نامعتبر و خطای مجوز پاسخ قراردادی دارد.
- [x] Products read از `wc_get_products()`/`wc_get_product()` و لایهٔ رسمی WooCommerce استفاده می‌کند و مستقیماً به جدول‌های WordPress/WooCommerce وابسته نیست.
- [x] پاسخ Products read شامل `data` و `meta.page`, `meta.per_page`, `meta.total`, `meta.total_pages` است.
- [x] Products Create/Update فقط با Session + CSRF + `products.write` و capability واقعی WordPress فعال است.
- [x] Products Write فقط فیلدهای allowlist‌شده، محصول Simple/Variable، ویژگی‌های والد محدود، وضعیت‌های محدود و setterهای رسمی WooCommerce CRUD را می‌پذیرد؛ SQL، meta دلخواه و HTML خام پذیرفته نمی‌شود.
- [x] Orders Read با Session + `orders.read` و `manage_woocommerce`/`manage_options`، pagination، search و فیلترهای محدود status/date/total/customer/payment/shipping و serializer allowlist اجرا می‌شود.
- [x] پاسخ Orders شامل مشتری عملیاتی، آدرس‌های محدود، totals، line-item summary و projection محدود payment/notes است و payment token، raw meta و comment خام را افشا نمی‌کند.
- [x] تغییر وضعیت سفارش فقط با JSON، Session + CSRF + `orders.update_status` و capability مدیریتی، از طریق `update_status()` رسمی WooCommerce انجام می‌شود.
- [x] پس از mutation وضعیت سفارش، وضعیت نهایی با getter رسمی دوباره تأیید می‌شود؛ PWA پاسخ mutation را اعمال می‌کند، refreshهای دیرهنگام را با request guard کنترل می‌کند و در صورت تأخیر، پیام موفقیت/خطای دقیق نشان می‌دهد.
- [x] Categories Read/Create/Update با Session، scopeهای `categories.read/write`، capability taxonomy محصول و API رسمی `get_terms()`/`wp_insert_term()`/`wp_update_term()` اجرا می‌شود.
- [x] افزایش گروهی قیمت با انتخاب یک/چند دسته، احتساب اختیاری زیردسته‌ها، روش درصدی/مبلغ ثابت و پیش‌نمایش قبل از اجرا در دسترس است.
- [x] اجرای افزایش گروهی فقط با Session + CSRF + `products.write` + capability واقعی و confirmation token کوتاه‌عمرِ وابسته به همان نشست و پارامترها انجام می‌شود.
- [x] قیمت عادی و قیمت فروش ویژهٔ موجود از طریق CRUD رسمی WooCommerce افزایش می‌یابند؛ محصول بدون قیمت عادی رد و نتیجهٔ جزئی گزارش/audit می‌شود.
- [x] پاسخ Categories فقط `id`, `name`, `slug`, `parent`, `description`, `count` و meta صفحه‌بندی را برمی‌گرداند و term meta دلخواه افشا نمی‌کند.
- [x] Variations فقط زیر محصول `variable` با Session، scope `products.read/write`، CSRF در mutation و `WC_Product_Variation` CRUD اجرا می‌شود.
- [x] attributes variation فقط از ویژگی‌های تعریف‌شده در محصول والد پذیرفته می‌شوند و فیلد ناشناخته، SQL خام و meta دلخواه پذیرفته نیست.
- [x] Customers Read فقط با Session معتبر، scope مستقل `customers.read` و capability واقعی WordPress برای مشاهدهٔ مشتریان (مانند `manage_woocommerce` یا capability اختصاصی محدودشده) در دسترس است؛ ورود به وب‌اپ به‌تنهایی مجوز خواندن مشتری نیست.
- [x] پاسخ Customers فقط فیلدهای allowlist‌شدهٔ عملیاتی مانند `id`, `display_name`, `username`, `email`, `date_created` و خلاصهٔ مجاز سفارش/آدرس را برمی‌گرداند و هرگونه `password`، hash/reset token، user meta خام، payment token، اطلاعات کارت یا دادهٔ احراز هویت را حذف می‌کند.
- [x] جزئیات `billing` و `shipping` سفارش فقط از متدها و CRUD رسمی WooCommerce مانند `wc_get_order()`/`WC_Order` و getterهای رسمی آدرس خوانده می‌شود؛ وابستگی مستقیم به جدول، SQL خام یا `postmeta` برای این داده‌ها مجاز نیست.
- [x] Customer Write فعلاً بخشی از قرارداد نیست: هیچ endpoint یا mutation برای ساخت، ویرایش، حذف، تغییر password، آدرس، نقش یا اطلاعات پرداخت مشتری ارائه نمی‌شود و scope مشتری فقط read-only است.
- [x] جدول Audit append-only برای نشست و mutationهای حساس وجود دارد؛ context آن allowlist‌شده است و secret، password، pairing code، CSRF و hash را نگه نمی‌دارد.
- [x] Devices Read فقط نشست‌های متعلق به کاربر جاری را با `devices.read` برمی‌گرداند و hash نشست، hash دستگاه، cookie و CSRF را افشا نمی‌کند.
- [x] Devices Revoke و Revoke-All فقط با CSRF، scope `devices.revoke` و capability مدیریتی اجرا می‌شوند؛ Revoke-All نشست جاری را حفظ می‌کند.
- [x] Audit Read فقط با scope `audit.read` و `manage_options`، pagination محدود و event filter از allowlist در دسترس است؛ route حذف/ویرایش audit وجود ندارد.
- [x] Media upload با `upload_files`، CSRF، rate limit، بررسی MIME/ابعاد و تبدیل WebP محدود شده است.
- [x] فونت محلی فقط با فرمت و امضای معتبر در مسیر جدا ذخیره و با CSS هم‌مبدأ سرو می‌شود.
- [x] UI Devices نشست فعلی و نشست‌های دیگر را با revoke تکی و revoke-all مدیریت می‌کند و نشست فعلی را از داخل رابط عمداً قابل ابطال نمی‌کند.
- [x] UI Audit فیلتر رویداد و pagination دارد و فقط context محدود allowlist‌شده را نمایش می‌دهد.
- [x] UI گالری محصول آپلود چندتصویری را به‌صورت ترتیبی انجام می‌دهد، تصویر شاخص را از gallery جدا نگه می‌دارد و حذف تصویر فقط اتصال آن به محصول را حذف می‌کند.
- [x] توکن‌های رنگ، سطح، حاشیه، radius و shadow در PWA و صفحات اختصاصی پنل افزونه یکپارچه و محلی هستند؛ فونت/Asset خارجی برای این طراحی لازم نیست.
- [x] صفحات Settings و Status افزونه با کارت، hero، فرم و دکمه‌های همان زبان بصری PWA ارائه می‌شوند و wp-admin را Embed نمی‌کنند.
- [x] واحد پول `IRT` فقط در لایهٔ نمایش به «تومان» نگاشت می‌شود و کد/مقدار ذخیره‌شدهٔ WooCommerce تغییر نمی‌کند؛ اعداد و تاریخ‌های PWA فارسی/جلالی هستند.
- [x] تحلیل اختیاری با `analytics_enabled`، route `GET /analytics/summary`، scope `analytics.read`، capability مدیریتی، پاسخ aggregate و سقف خواندن امن سفارش‌ها تعریف شده است.
- [ ] فاز Pairing/Session، Products read و Media در محیط WordPress واقعی با کاربر، نقش، WooCommerce، image engine و HTTPS تست می‌شود.

## معیارهای پذیرش فاز مشتری/ارسال

- [x] `GET /wp-json/fandoogh-manager/v1/customers` با pagination و search محدود، فقط پس از Session معتبر، scope `customers.read` و capability مجاز WordPress پاسخ می‌دهد.
- [x] endpoint مشتریان read-only است و پاسخ list/detail فقط serializer allowlist‌شده دارد؛ password، hash، reset token، user meta دلخواه، payment token، اطلاعات کارت و secretهای نشست هرگز در پاسخ قرار نمی‌گیرند.
- [x] هر customer item شناسه و اطلاعات عملیاتی لازم برای پشتیبانی/فروش را با حداقل دادهٔ لازم برمی‌گرداند؛ فیلدهای حساس یا خارج از allowlist با تغییر query قابل استخراج نیستند.
- [x] `GET /wp-json/fandoogh-manager/v1/customers/{id}/orders` تاریخچهٔ سفارش را با تقاطع scopeهای `customers.read` و `orders.read`، pagination و همان serializer محدود سفارش برمی‌گرداند.
- [x] پروندهٔ مشتری در PWA تلفن، تاریخ عضویت، نشانی‌ها و تاریخچهٔ سفارش‌های همان مشتری را نمایش می‌دهد و از customer write مستقل استفاده نمی‌کند.
- [x] `GET /wp-json/fandoogh-manager/v1/orders/{id}` جزئیات billing/shipping، totals و line items را از `wc_get_order()` و API رسمی `WC_Order` می‌سازد و دادهٔ خام جدول یا `postmeta` را افشا نمی‌کند.
- [x] جزئیات سفارش علاوه بر آدرس‌ها، روش‌های ارسال و مبلغ/مالیات هر shipping line را با allowlist محدود برمی‌گرداند؛ token درگاه و meta آیتم ارسال افشا نمی‌شود.
- [x] billing/shipping سفارش در صورت نبودن یا ناقص‌بودن، به‌صورت مقدار خالی/ساختار قراردادی پاسخ داده می‌شود و باعث افشای دادهٔ مشتری دیگر یا خطای uncaught نمی‌شود.
- [x] هیچ route برای `POST`, `PUT`, `PATCH` یا `DELETE` روی customers وجود ندارد؛ ایجاد/ویرایش/حذف مشتری، تغییر password یا آدرس و هر عملیات payment در این فاز صراحتاً خارج از محدوده است.

## معیارهای پذیرش رهگیری ارسال provider-neutral

در این بخش، موارد `[x]` فقط نشان می‌دهند قرارداد در مستندات بسته شده است؛ موارد `[ ]` تا زمان اجرای واقعی روی WordPress/WooCommerce نباید به‌عنوان قابلیت پیاده‌سازی‌شده گزارش شوند.

### قرارداد مستندشده

- [x] مسیرهای اجرایی دقیقاً `GET /wp-json/fandoogh-manager/v1/orders/{id}/shipment` و `POST /wp-json/fandoogh-manager/v1/orders/{id}/shipment` هستند؛ routeها در نسخهٔ 1.7.0 به REST ثبت شده‌اند.
- [x] مدل داخلی site-local یک snapshot JSON کنترل‌شده در order meta با کلید `_fandoogh_shipment` است که فقط با `WC_Order` CRUD خوانده/نوشته می‌شود؛ SQL خام، `update_post_meta()` مستقیم و وابستگی به جدول داخلی WooCommerce مجاز نیستند. schema version داخلی فعلاً در قرارداد عمومی وجود ندارد و در migration بعدی قابل اضافه‌شدن است.
- [x] allowlist وضعیت‌ها بسته است: `pending`, `ready`, `shipped`, `in_transit`, `out_for_delivery`, `delivered`, `failed`, `returned`, `cancelled`؛ status ناشناخته یا provider-specific باید `422` شود.
- [x] allowlist فیلدها بسته است: `status`, `tracking_code`, `carrier_label`, `shipped_at`, `estimated_delivery_date`, `delivered_at`, `note` و `updated_at`؛ `order_id` فقط از path می‌آید و `updated_at` فقط توسط سرور ساخته می‌شود.
- [x] محدودیت فیلدها ثبت شده است: tracking code حداکثر 100 نویسه، carrier label حداکثر 80 نویسه، note حداکثر 500 نویسه، تاریخ‌زمان‌ها ISO-8601 دارای timezone، تاریخ تحویل مورد انتظار `YYYY-MM-DD` و همهٔ متن‌ها بدون HTML هستند.
- [x] فیلدهای `provider`, `provider_id`, `tracking_url`, `webhook`, `raw_payload`, `events` و meta دلخواه خارج از قراردادند و نباید در request یا response پذیرفته شوند.
- [x] `tracking_code` صرفاً دادهٔ دستی site-local است؛ افزونه آن را به provider، URL، webhook، polling یا API بیرونی متصل نمی‌کند و صحت آن را تضمین نمی‌کند.
- [x] `GET` به Session معتبر، scope `orders.read` و capability `manage_woocommerce` یا `manage_options` نیاز دارد؛ `POST` علاوه بر آن scope مستقل `orders.update_shipment`، capability، JSON، header `X-Fandoogh-CSRF` و Origin هم‌سایت لازم دارد.
- [x] پاسخ موفق و خطای هر دو route باید `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`، `Pragma: no-cache` و `X-Content-Type-Options: nosniff` داشته باشند؛ Service Worker نباید دادهٔ shipment را cache کند.
- [x] هر POST موفق باید event بستهٔ `shipment_updated` را در Audit append-only ثبت کند؛ Audit فقط context محدود مانند `order_id`, `status` و `outcome` را نگه می‌دارد و tracking code، note، body خام، CSRF، cookie، token و secret در آن ثبت نمی‌شود.
- [x] رهگیری ارسال نباید وضعیت سفارش WooCommerce، payment، refund یا shipping line را به‌صورت ضمنی تغییر دهد.

### runtime هنوز لازم

- [x] routeهای GET و POST در source افزونه ثبت شده‌اند و response/errorهای JSON قراردادی، `401`/`403`/`404`/`422`/`503` و headerهای no-store برای آن‌ها تعریف شده است؛ خطای احراز هویت redirect به `wp-admin` نیست.
- [ ] GET برای سفارش موجود snapshot allowlist‌شده را می‌خواند و اگر snapshot وجود نداشته باشد مقدار پیش‌فرض `pending` را بدون نوشتن ضمنی برمی‌گرداند؛ سفارش ناموجود یا غیرقابل‌خواندن دادهٔ سفارش دیگری را افشا نمی‌کند.
- [ ] POST معتبر snapshot را از طریق `WC_Order` CRUD ایجاد/به‌روزرسانی می‌کند، مقدارهای اختیاریِ حذف‌شده از body را ناخواسته پاک نمی‌کند، و پس از reload همان دادهٔ site-local را برمی‌گرداند.
- [ ] POST رهگیری هیچ تغییر ناخواسته‌ای در status سفارش، payment، refund یا shipping lines ایجاد نمی‌کند و هیچ درخواست شبکه‌ای به دامنه یا سرویس provider ارسال نمی‌شود.
- [ ] بدنهٔ غیر JSON، JSON ناقص، کلید ناشناخته، status ناشناخته، رشتهٔ بیش از حد مجاز، HTML و تاریخ نامعتبر با `415`/`422` رد می‌شوند و mutation یا Audit موفق کاذب ایجاد نمی‌کنند.
- [ ] نبودن یا اشتباه‌بودن CSRF، Origin نامعتبر، Session منقضی، scope حذف‌شده و capability ناکافی به‌ترتیب با خطای قراردادی و بدون تغییر داده پاسخ می‌گیرند؛ دسترسی فقط با ورود به PWA اعطا نمی‌شود.
- [ ] role matrix واقعی با مدیر اصلی دارای `manage_options`، مدیر فروشگاه دارای `manage_woocommerce`، کاربر دارای دسترسی محصول ولی فاقد مجوز سفارش و کاربر عادی اجرا می‌شود؛ scope و capability در هر request جداگانه آزموده می‌شوند.
- [ ] سناریوهای WooCommerce فعال/غیرفعال، سفارش موجود/ناموجود، HPOS روشن/خاموش، PHP واقعی، migration/خواندن order meta و خطاهای CRUD اجرا و ثبت می‌شوند.
- [ ] Audit پس از POST موفق append-only است، event `shipment_updated` در allowlist ثبت می‌شود، و هیچ tracking code یا note خامی در رکورد Audit یا endpoint گزارش Audit دیده نمی‌شود.
- [ ] تست مرورگر روی HTTPS در موبایل و دسکتاپ تأیید می‌کند که responseهای shipment در Service Worker، Cache Storage، localStorage یا sessionStorage ذخیره نمی‌شوند.

## معیارهای پذیرش فاز عملیاتی ۱.۱۶.۰

- [x] Orders برای یادداشت، بازپرداخت با `wc_create_refund()`، idempotency و projection محدود قرارداد مستقل دارد.
- [x] Shipment snapshot امکان ثبت اقلام ارسال‌شده برای fulfillment جزئی را دارد و quantity بالاتر از order را رد می‌کند.
- [x] Products فیلدهای backorders، ابعاد/وزن، tax status، sold individually، virtual و downloadable را با WooCommerce CRUD مدیریت می‌کند.
- [x] Coupons، Reviews و Inventory routeهای خصوصی با scope، capability، CSRF در mutation، no-store و Audit محدود اضافه شده‌اند.
- [x] UI برای عملیات جدید حالت‌های secure/loading/empty/error، pagination، جست‌وجو و فرم‌های واکنش‌گرا دارد و وابستگی خارجی اضافه نشده است.
- [ ] روی staging واقعی، HPOS روشن/خاموش، role matrix، CSRF/Origin، WooCommerce خاموش، gateway test، replay refund، دیدگاه، کوپن و موجودی طبق `docs/runtime-staging-checklist.md` اجرا و evidence ثبت می‌شود.
- [ ] تحلیل اختیاری روی WordPress/WooCommerce واقعی با تنظیم خاموش/روشن، Pairing دوباره برای `analytics.read`، role matrix، بازه‌های زمانی، تطبیق aggregateها و خطاهای `401`/`403`/`503` تست می‌شود.

## قرارداد اجرایی Pairing/Session

- Pairing admin: Administrator از صفحهٔ تنظیمات افزونه کاربر هدف واجد شرایط را انتخاب و کد یک‌بارمصرف را می‌سازد؛ کد خام فقط در همان پاسخ HTML ادمین نمایش داده می‌شود و در Audit رویداد `pairing_issued` بدون خود کد ثبت می‌شود.
- Pairing target: کاربر هدف باید capability مدیریت معتبر داشته باشد؛ کد به شناسهٔ همان کاربر متصل است و Administrator نمی‌تواند با کد صادرشده به‌جای او وارد PWA شود.
- Pairing scope freshness: scope ذخیره‌شدهٔ کد هنگام مصرف با capability فعلی کاربر تقاطع داده می‌شود تا کاهش نقش بعد از صدور کد، مجوز قدیمی ایجاد نکند.
- Pairing redeem: `POST /wp-json/fandoogh-manager/v1/auth/pair` با JSON شامل `username`, `password`, `pairing_code` و شناسهٔ موقت دستگاه؛ پاسخ موفق فقط metadata نشست و CSRF را برمی‌گرداند و cookie session را با header امن set می‌کند.
- Session check: `GET /wp-json/fandoogh-manager/v1/auth/me`؛ پاسخ شامل کاربر محدود، Scope و زمان انقضا، بدون secret نشست.
- CSRF: `GET /wp-json/fandoogh-manager/v1/auth/csrf`؛ مقدار فقط در حافظه JavaScript نگهداری می‌شود.
- Logout: `POST /wp-json/fandoogh-manager/v1/auth/logout`؛ با header `X-Fandoogh-CSRF` نشست جاری را revoke می‌کند و cache پاسخ را ممنوع می‌کند.
- خطاها JSON و نسخه‌دارند: `invalid_pairing`, `pairing_expired`, `pairing_used`, `unauthorized`, `forbidden`, `csrf_failed`, `rate_limited`.

## قرارداد پیشنهادی Products read

- Route: `GET /wp-json/fandoogh-manager/v1/products` و فقط پس از Session معتبر و capability مشخص مانند `manage_woocommerce`.
- Queryها: `page` از 1، `per_page` در بازهٔ محدود مثلاً 1 تا 100، `search` با طول محدود، و `status` از allowlist وضعیت‌های WooCommerce.
- هر item در list حداقل `id`, `name`, `status`, `sku`, `price`, `regular_price`, `sale_price`, `stock_status`, `stock_quantity`, `permalink`, `categories` و `images` را برمی‌گرداند؛ detail فیلدهای توضیحات و زمان‌های ایجاد/ویرایش را نیز اضافه می‌کند. مقدارهای حساس یا فیلدهای دلخواه meta ممنوع‌اند.
- هیچ تغییر محصول، حذف، upload، اجرای hook دلخواه یا proxy عمومی در این use case وجود ندارد.

## قرارداد اجرایی Products write و Orders read

- `POST /wp-json/fandoogh-manager/v1/products` محصول Simple یا Variable را می‌سازد و به‌صورت پیش‌فرض `draft` است؛ محصول Variable باید حداقل یک ویژگی با `variation: true` داشته باشد و انتشار فقط با capability انتشار مجاز است.
- `PUT/PATCH /wp-json/fandoogh-manager/v1/products/{id}` فقط فیلدهای محدود نام، نوع ثابت محصول، وضعیت، SKU، قیمت، توضیح متنی، موجودی، دسته‌بندی، ویژگی‌های والد و attachmentهای محلی را می‌پذیرد؛ تغییر نوع محصول موجود رد می‌شود.
- `GET /wp-json/fandoogh-manager/v1/product-attributes` ویژگی‌های سراسری ووکامرس و termهای آن‌ها را با Session، scope `products.read` و capability خواندن محصولات برمی‌گرداند.
- هر mutation محصول JSON، Session، scope `products.write`، CSRF و capability واقعی WordPress لازم دارد؛ پاسخ موفق `data` و headerهای no-store دارد.
- `GET /wp-json/fandoogh-manager/v1/product-categories` فهرست صفحه‌بندی‌شدهٔ `product_cat` را می‌دهد و `POST` و `PUT/PATCH /{id}` فقط name/slug/parent/description محدود را می‌پذیرند.
- `GET /wp-json/fandoogh-manager/v1/products/{id}/variations` فقط برای parent نوع `variable` فهرست variationها را می‌دهد و `POST` و `PUT/PATCH /{variation_id}` فقط فیلدهای variation allowlist را می‌پذیرند.
- `GET /wp-json/fandoogh-manager/v1/orders` و `GET /wp-json/fandoogh-manager/v1/orders/{id}` فقط با scope `orders.read` و `manage_woocommerce`/`manage_options` در دسترس‌اند.
- جست‌وجوی سفارش محدود است و خواندن از `wc_get_orders()`/`wc_get_order()` انجام می‌شود؛ هیچ endpoint raw order/meta یا payment action وجود ندارد.

## تست قابل اجرا

از ریشهٔ مخزن اجرا کنید:

```powershell
powershell -ExecutionPolicy Bypass -File .\tests\verify-baseline.ps1
```

برای بررسی release candidate و فایل ZIP نسخهٔ فعلی:

```powershell
powershell -ExecutionPolicy Bypass -File .\tests\verify-release.ps1 -VerboseOutput
```

این تست فقط از قابلیت‌های داخلی PowerShell استفاده می‌کند و علاوه بر اسناد، وجود sourceهای فعلی، routeهای قرارداد، نبود storage/Access Token در frontend و نبود URL خارجی را بررسی می‌کند. این تست ایستا نمی‌تواند PHP را parse کند، route را از WordPress اجرا کند، cookie attribute را از HTTP واقعی ببیند، nonce/CSRF را validate کند، replay/rate limit را به‌صورت runtime ثابت کند، یا اتصال واقعی به WooCommerce/HPOS آزمایش کند. بررسی Syntax PHP باید روی یک نصب WordPress آزمایشی یا CI دارای PHP انجام شود؛ Syntax JavaScript با Node.js قابل بررسی است.
