# Fandoogh Manager

فاز اجرایی فعلی یک افزونهٔ مستقل WordPress و پوستهٔ PWA هم‌مبدأ است. افزونه مسیر وب‌اپ، Session اختصاصی، Pairing یک‌بارمصرف، خواندن/ساخت/ویرایش محدود محصولات ساده و متغیر، ویژگی‌های والد و variation، خواندن/تغییر وضعیت سفارش، یادداشت و بازپرداخت کنترل‌شده، جزئیات مشتری و روش‌های ارسال سفارش، رهگیری دستی و جزئیِ ارسال، کوپن، دیدگاه، موجودی، Media WebP، گالری محصول، برندینگ محلی، چرخهٔ نصب/به‌روزرسانی PWA و UI مدیریت Audit/Devices را فراهم می‌کند.

## نصب افزونه

1. پوشهٔ `fandoogh-manager` را در `wp-content/plugins/` کپی کنید، یا آن را به‌صورت ZIP از بخش افزونه‌های WordPress بارگذاری کنید.
2. افزونهٔ **Fandoogh Manager** را فعال کنید.
3. از منوی `Fandoogh Manager` وضعیت افزونه و پشتیبانی WebP را بررسی کنید.
4. در `Fandoogh Manager → Settings` رنگ‌ها، مسیر وب‌اپ، حدود پردازش تصویر، فونت محلی و کد Pairing را مدیریت کنید.
5. لینک `<site-origin>/manager/` را باز کنید.

برای قراردادن لینک ورود در یک برگه یا منو می‌توانید از شورت‌کد زیر استفاده کنید؛ این شورت‌کد فقط Launcher هم‌مبدأ می‌سازد و پنل `wp-admin` را Embed نمی‌کند:

```text
[fandoogh_manager]
[fandoogh_manager label="مدیریت فروشگاه" new_tab="1"]
```

مسیر `/manager/` مسیر پایدار و اصلی PWA است. مسیر تنظیم‌شده در بخش تنظیمات فقط یک Alias است تا نصب PWA با تغییر تنظیمات از بین نرود.

## مسیرهای Vertical Slice

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

فایل‌های PWA از داخل خود افزونه سرو می‌شوند؛ در زمان اجرا CDN، فونت خارجی، Analytics یا سرویس پردازش تصویر خارجی استفاده نمی‌شود.

## وضعیت فعلی

- PWA راست‌به‌چپ و Responsive برای موبایل و دسکتاپ
- تنظیم رنگ‌های عمومی از پنل افزونه
- دریافت نام سایت، لوگو و آیکون هم‌مبدأ WordPress در PWA و Manifest
- Manifest و Service Worker محلی
- نصب وب‌اپ روی مرورگرهای سازگار، راهنمای Add to Home Screen برای iOS و اعلان به‌روزرسانی App Shell
- تشخیص آنلاین/آفلاین و جلوگیری از عملیات نوشتنی هنگام قطع اینترنت، بدون Cache کردن پاسخ API
- تشخیص فعال‌بودن WooCommerce
- تشخیص پشتیبانی WebP روی سرور
- تنظیم ابعاد، کیفیت و سقف حجم خروجی تصویر
- تبدیل آپلودهای تصویری مجاز به WebP روی سرور WordPress
- Pairing یک‌بارمصرف با TTL، rate limit و Session Cookie اختصاصی PWA
- فهرست و جزئیات خواندنی محصولات با pagination، search و status filter
- ساخت و ویرایش محدود محصول ساده/متغیر با WooCommerce CRUD و CSRF
- خواندن ویژگی‌های سراسری ووکامرس و تعریف ویژگی‌های والد محصول متغیر با allowlist صریح
- فهرست، ساخت و ویرایش دسته‌بندی‌های `product_cat` با scope و capability مستقل
- فهرست، ساخت و ویرایش variationهای محصول variable با WooCommerce CRUD
- فهرست و جزئیات سفارش‌ها با capability مستقل و allowlist داده
- فهرست read-only مشتریان با search/pagination محدود و capability مستقل
- جزئیات سفارش شامل صورتحساب، نشانی ارسال، اقلام و روش‌های ارسال با getterهای رسمی WooCommerce
- تغییر وضعیت سفارش با allowlist وضعیت‌ها و CRUD رسمی WooCommerce
- ثبت یادداشت داخلی/نمایش‌پذیر مشتری و بازپرداخت کنترل‌شده با idempotency و audit
- ثبت اقلام ارسال‌شده برای fulfillment جزئی در کنار رهگیری دستی، بدون provider خارجی
- مدیریت کوپن‌های درصدی و مبلغ ثابت، محدودیت مصرف، تاریخ انقضا و حذف recoverable
- مدیریت دیدگاه محصولات با فیلتر، moderation و پاسخ فروشگاه
- مرکز موجودی برای مقدار، وضعیت، پیش‌فروش و آستانهٔ موجودی با WooCommerce CRUD
- ویرایشگر محصول با backorders، ابعاد/وزن، وضعیت مالیاتی و پرچم‌های فروش/دانلود
- آپلود فونت محلی با بررسی امضای فایل و CSS هم‌مبدأ
- بدون ذخیرهٔ Access/Refresh Token یا Secret در مرورگر؛ CSRF فقط در حافظهٔ JavaScript نگه داشته می‌شود
- مدیریت پایهٔ نشست‌های دستگاهی: مشاهدهٔ نشست‌های خود، ابطال یک دستگاه و ابطال همهٔ دستگاه‌های دیگر
- جدول Audit append-only برای رویدادهای نشست و عملیات حساس؛ کد، رمز، CSRF و hash در گزارش ثبت نمی‌شود
- UI مدیریت دستگاه‌ها با نمایش نشست فعلی، ابطال نشست منفرد و ابطال همهٔ نشست‌های دیگر
- UI گزارش Audit با فیلتر رویداد، pagination و نمایش محدود context عملیاتی
- گالری محصول با آپلود چندتصویری، تبدیل WebP هم‌مبدأ، انتخاب تصویر شاخص و حذف اتصال تصویر از محصول
- رهگیری دستی ارسال با snapshot کنترل‌شدهٔ order meta از طریق `WC_Order` CRUD، بدون provider API یا webhook خارجی
- داشبورد تحلیلی اختیاری فروش و وضعیت سفارش‌ها بر پایهٔ CRUD رسمی WooCommerce، بدون سرویس یا API خارجی
- نمایش واحد پول `IRT` به‌صورت «تومان» در PWA، بدون تغییر کد واحد پول ذخیره‌شده در WooCommerce
- پنل صفحات افزونه در wp-admin با همان توکن‌های رنگ، کارت، فاصله و دکمهٔ رابط کاربری PWA

Pairing برای استفادهٔ واقعی به HTTPS و کاربر WordPress دارای capability نیاز دارد. مدیر از تنظیمات افزونه کد را می‌سازد و کاربر همان نام کاربری/رمز WordPress را همراه کد وارد می‌کند. Cookie نشست `HttpOnly` است و به Cookie ورود عادی WordPress وابسته نیست.

اگر از نسخهٔ قبل از 1.7.0 ارتقا می‌دهید، برای دریافت scope جدید `orders.update_shipment` یک‌بار از داخل وب‌اپ logout کنید و Pairing را دوباره انجام دهید؛ نشست‌های قبلی عمداً scope جدید را خودکار دریافت نمی‌کنند.

در نسخهٔ 1.21.0، داشبورد با ترتیب عملیاتی دسترسی سریع، روند فروش، تحلیل فروشگاه، آخرین فعالیت‌ها و کاتالوگ بازطراحی شده است. کارت تحلیل و نمودار روند فقط برای نشست دارای scope `analytics.read` نمایش داده می‌شوند؛ دسترسی سریع نیز میان سفارش‌ها، محصولات، افزودن محصول و مشتریان تفکیک رنگی دارد. تعداد سفارش‌های pending بدون اقدام از API ووکامرس خوانده می‌شود و به‌صورت شمارندهٔ فارسی روی دسترسی سریع و ناوبری دسکتاپ/موبایل اعلام می‌شود؛ این تغییر با یک ناحیهٔ live متنی و برچسب دسترس‌پذیر همراه است.

در نسخهٔ 1.20.0، پنل افزونه یک نمای مدیریتی سراسری برای دستگاه‌ها و نشست‌ها دارد: مدیر می‌تواند اتصال هر کاربر را ببیند، یک نشست یا همهٔ نشست‌های کاربر را حذف کند، دسترسی وب‌اپ را قطع/فعال کند و مجوزهای معرفی محصول، اصلاح محصول، مشاهدهٔ گردش مالی و تغییرات اساسی را جداگانه محدود کند. این سیاست‌ها در هر درخواست با scope و capability واقعی بررسی می‌شوند و با ذخیرهٔ تغییرات، نشست‌های قبلی برای جلوگیری از ماندگاری دسترسی باطل می‌شوند؛ administrator اصلی برای جلوگیری از قفل‌شدن، محافظت‌شده است. عنوان بخش یادداشت جزئیات سفارش نیز به «گزارشات سفارش» تغییر کرده است.

در نسخهٔ 1.19.0، کنترل‌های روش و مقدار افزایش قیمت گروهی با ظاهر یکپارچه و اندازهٔ مناسب لمس بازطراحی شده‌اند و جزئیات سفارش در یک overlay مرحله‌ای مشابه ویزارد محصول، با کارت‌های مناسب موبایل، نمایش داده می‌شود. نسخهٔ 1.18.0 تمام نمایش‌ها و ورودی‌های تاریخ در وب‌اپ را به تقویم جلالی یکپارچه مجهز کرده است؛ تقویم سفارشی فارسی برای فیلتر سفارش، تاریخ انقضای کوپن و تاریخ‌های ارسال استفاده می‌شود و مقدار ارسالی به API همچنان با قرارداد میلادی WooCommerce نگه داشته می‌شود. در نسخهٔ 1.17.0، فرم ایجاد و ویرایش محصول به یک ویزارد مرحله‌ای در پنجرهٔ داخلی تبدیل شده است. این جریان روی دسکتاپ به‌صورت dialog مرکزی و روی موبایل به‌صورت صفحهٔ تمام‌قد با مراحل اطلاعات اصلی، موجودی و مالیات، ارسال و تحویل، محتوا و تصاویر، و ویژگی و تنوع نمایش داده می‌شود؛ خروج با Escape، فوکوس کیبورد و تأیید تغییرات ذخیره‌نشده نیز کنترل می‌شود. نسخهٔ 1.16.0 نیز قابلیت انتخاب کاربر هدف برای Pairing، مدیریت سفارش و مشتری، اعداد فارسی/تاریخ جلالی، آپلود رسانه و چرخهٔ نصب وب‌اپ را ارائه می‌کند.

در همین نسخه، رنگ‌ها و اجزای اصلی PWA و صفحات اختصاصی افزونه با توکن‌های محلی سبز/کهربری، کارت سفید، حاشیهٔ خنثی و گوشه‌های یکسان هماهنگ شده‌اند. بخش تحلیل ووکامرس در Settings به‌صورت پیش‌فرض خاموش است؛ پس از فعال‌سازی، برای دریافت scope جدید `analytics.read` باید Pairing قبلی logout و دوباره انجام شود. endpoint تحلیل فقط خلاصهٔ فروش، وضعیت سفارش‌ها، مشتریان یکتا و محصولات پرفروش را از WooCommerce همان سایت می‌خواند و دادهٔ مشتری حساس یا سرویس بیرونی وارد قرارداد نمی‌کند. عملیات کوپن، دیدگاه، موجودی، یادداشت، refund و fulfillment نیز فقط با session/CSRF/capability و projectionهای allowlistشده انجام می‌شوند. کد ذخیره‌شدهٔ WooCommerce تغییر نمی‌کند و `IRT` فقط در نمایش به «تومان» تبدیل می‌شود.

## بررسی‌های محلی

```powershell
powershell -ExecutionPolicy Bypass -File .\tests\verify-baseline.ps1
```

برای بررسی مستقل بستهٔ انتشار و ZIP نسخهٔ فعلی نیز اجرا کنید:

```powershell
powershell -ExecutionPolicy Bypass -File .\tests\verify-release.ps1 -VerboseOutput
```

در محیطی که PHP CLI نصب نیست، بررسی Syntax و اجرای PHP باید روی یک نصب WordPress آزمایشی یا CI دارای PHP اجرا شود. چک‌لیست اجرای واقعی روی staging در [docs/runtime-staging-checklist.md](docs/runtime-staging-checklist.md) ثبت شده است؛ تا وقتی آن روی WordPress/WooCommerce واقعی اجرا نشده، پذیرش runtime نهایی محسوب نمی‌شود. Syntax JavaScript با Node.js قابل بررسی است:

```powershell
node --check .\fandoogh-manager\app\app.js
node --check .\fandoogh-manager\app\sw.js
```

## مرزهای امنیتی فعلی

- API عمومی فقط پیکربندی عمومی و وضعیت غیرحساس را برمی‌گرداند.
- API عمومی برای سفارش، کاربر، `postmeta`، SQL یا اجرای دلخواه وجود ندارد.
- لوگوی خارجی و Asset خارجی برای PWA پذیرفته نمی‌شود.
- Service Worker پاسخ‌های `/wp-json/` و داده‌های Session/Products/Categories/Variations/Customers/Orders/Media را Cache نمی‌کند.
- تنظیمات افزونه با `manage_options` و Settings API محافظت می‌شوند.
- Products Read فقط با `wc_get_products()` و `wc_get_product()` اجرا می‌شود؛ SQL خام و proxy عمومی وجود ندارد.
- Products Create/Update فقط با setterهای CRUD محصول انجام می‌شود؛ نوع محصول فقط Simple یا Variable است، ویژگی‌های والد allowlist می‌شوند و HTML خام/فیلد ناشناخته پذیرفته نمی‌شود.
- Orders Read فقط با `wc_get_orders()` و `wc_get_order()` اجرا می‌شود و token پرداخت، meta دلخواه و دادهٔ خام comment را برنمی‌گرداند؛ جزئیات سفارش فقط projection محدود پرداخت، یادداشت و اطلاعات عملیاتی را ارائه می‌کند.
- Customers Read فقط با `WC_Customer_Data_Store` و `WC_Customer` و serializer allowlist اجرا می‌شود؛ password، meta خام و payment token برنمی‌گردد.
- اطلاعات billing/shipping سفارش و shipping lines فقط از getterهای رسمی `WC_Order` خوانده می‌شوند.
- تغییر وضعیت سفارش فقط از allowlist وضعیت‌های WooCommerce و با `orders.update_status` انجام می‌شود؛ یادداشت و refund مسیرهای مستقل، CSRFدار، manager-only و auditشده دارند.
- بازپرداخت فقط از `wc_create_refund()` و مبلغ قابل‌بازپرداخت سفارش استفاده می‌کند؛ idempotency key از replay تکراری جلوگیری می‌کند و اطلاعات gateway خام برنمی‌گردد.
- کوپن، دیدگاه و موجودی فقط با REST routeهای خصوصی، scope مستقل، capability و allowlist فیلدها اجرا می‌شوند؛ حذف کوپن به‌صورت recoverable به Trash منتقل می‌شود.
- رهگیری ارسال دستی از وضعیت‌های داخلی allowlist‌شده استفاده می‌کند و نباید وضعیت سفارش WooCommerce، shipping line یا payment را به‌صورت ضمنی تغییر دهد.
- `tracking_code` در فاز رهگیری فقط رشتهٔ دستی ذخیره‌شده در سایت است؛ `carrier_label` صرفاً برچسب نمایشی است و هیچ provider ID، tracking URL، webhook یا API بیرونی در قرارداد وجود ندارد.
- Devices فقط نشست‌های متعلق به همان کاربر را برمی‌گرداند؛ hash نشست، hash دستگاه، cookie و CSRF هرگز در API دستگاه‌ها نیست.
- Audit فقط برای `manage_options` و با scope مستقل خواندنی است؛ context آن allowlist و محدود به شناسه/وضعیت عملیاتی است و route حذف/ویرایش ندارد.
- Categories فقط با taxonomy API رسمی WordPress و scope `categories.read/write` مدیریت می‌شوند؛ term meta و taxonomy دلخواه در قرارداد نیست.
- Variations فقط زیر محصول `variable` و با `WC_Product_Variation` CRUD مدیریت می‌شوند؛ attributes باید در محصول والد تعریف شده باشند.
- Media فقط با Session + CSRF + `upload_files` و محدودیت نرخ فعال است.

## قرارداد اجرایی رهگیری ارسال provider-neutral

این قرارداد در نسخهٔ 1.7.0 اضافه و در نسخهٔ جاری 1.15.0 حفظ شده است. هدف، ثبت وضعیت دستی ارسال در همان سایت و نمایش آن در PWA است؛ هیچ تماس مستقیم یا غیرمستقیم با سرویس حمل‌ونقل، webhook، polling، API provider یا سرویس tracking خارجی انجام نمی‌شود.

## فاز 1.15.0: افزایش گروهی قیمت محصولات

- مسیر `POST /wp-json/fandoogh-manager/v1/products/bulk-price` فقط با نشست معتبر، CSRF، scope `products.write` و capability واقعی ویرایش محصول در دسترس است.
- انتخاب یک یا چند دستهٔ `product_cat` ممکن است و گزینهٔ `include_children` تمام زیردسته‌های آن‌ها را نیز وارد محاسبه می‌کند.
- روش افزایش `percent` یا `fixed` است. فقط مقدار مثبت پذیرفته می‌شود؛ قیمت عادی و قیمت فروش ویژهٔ موجود با همان روش افزایش می‌یابند و محصول بدون قیمت عادی رد می‌شود.
- عملیات دو مرحله‌ای است: درخواست اول فقط پیش‌نمایش محدود و توکن یک‌بارمصرف ده‌دقیقه‌ای وابسته به نشست/پارامترها می‌دهد؛ اجرای نهایی بدون همان توکن پذیرفته نمی‌شود و replay آن رد می‌شود.
- پردازش فقط از CRUD و query رسمی WooCommerce استفاده می‌کند، برای جلوگیری از timeout سقف محصول/قیمت دارد و نتیجه در audit ثبت می‌شود.

## فاز 1.16.0: تکمیل عملیات فروشگاه

- سفارش: افزودن یادداشت داخلی/مشتری، بازپرداخت کامل یا مبلغی با idempotency و اقلام ارسال‌شده برای fulfillment جزئی.
- محصول: فیلدهای backorders، وزن/ابعاد، وضعیت مالیاتی، فروش تکی، مجازی و دانلودی به allowlist و UI محصول اضافه شده‌اند.
- کوپن: CRUD محدود کد، نوع تخفیف، مبلغ، تاریخ انقضا، حداقل خرید، سقف مصرف و گزینه‌های ارسال رایگان/استفادهٔ مستقل.
- موجودی: جست‌وجو، فیلتر وضعیت، مقدار، مدیریت موجودی، پیش‌فروش و آستانهٔ موجودی.
- دیدگاه: فهرست server-side، فیلتر، تأیید/انتظار/اسپم و پاسخ فروشگاه.
- همهٔ مسیرهای جدید response خصوصی no-store، کنترل Session/CSRF/capability و رویداد Audit محدود دارند. برای دریافت scopeهای جدید، نشست قبلی باید logout و Pairing دوباره انجام شود.

مسیرهای قرارداد:

```text
GET  /wp-json/fandoogh-manager/v1/orders/{id}/shipment
POST /wp-json/fandoogh-manager/v1/orders/{id}/shipment
```

مدل داخلی، یک snapshot JSON کنترل‌شدهٔ site-local در order meta از طریق `WC_Order` CRUD است؛ کلید `_fandoogh_shipment` است و نباید با SQL، `update_post_meta()` مستقیم یا جدول اختصاصی وابسته به ساختار داخلی WooCommerce نوشته شود تا با HPOS سازگار بماند. snapshot فقط این فیلدها را دارد: `status`, `tracking_code`, `carrier_label`, `shipped_at`, `estimated_delivery_date`, `delivered_at`, `note` و `updated_at`. شناسهٔ سفارش از مسیر می‌آید و `updated_at` را سرور تولید می‌کند؛ در migrationهای بعدی می‌توان schema version داخلی اضافه کرد بدون تغییر قرارداد عمومی.

allowlist وضعیت داخلی عبارت است از `pending`, `ready`, `shipped`, `in_transit`, `out_for_delivery`, `delivered`, `failed`, `returned` و `cancelled`. وضعیت ناشناخته، provider-specific status یا مقدار دلخواه باید با `422` رد شود. فیلدهای ورودی نیز بسته‌اند: `status` اجباری، و `tracking_code`، `carrier_label`، `shipped_at`، `estimated_delivery_date`، `delivered_at` و `note` اختیاری و محدود به متن/تاریخ معتبر، طول مشخص و بدون HTML هستند؛ فیلدهای `provider`, `provider_id`, `tracking_url`, `webhook`, `raw_payload` و هر meta دلخواه مجاز نیستند.

`GET` فقط با Session معتبر، scope `orders.read` و capability واقعی `manage_woocommerce` یا `manage_options` snapshot allowlist‌شده را برمی‌گرداند. `POST` علاوه بر آن به scope مستقل `orders.update_shipment`، بدنهٔ `application/json`، header `X-Fandoogh-CSRF`، بررسی Origin هم‌سایت و همان capability نیاز دارد. پاسخ موفق و خطای هر دو route باید `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`، `Pragma: no-cache` و `X-Content-Type-Options: nosniff` داشته باشند و Service Worker نباید آن‌ها را cache کند.

هر POST موفق باید یک رویداد append-only مانند `shipment_updated` در Audit ثبت کند؛ context فقط `order_id`، وضعیت نهایی و outcome محدود باشد و tracking code، note، بدنهٔ خام، CSRF، cookie یا هر secret در Audit ذخیره نشود. `tracking_code` صرفاً دادهٔ دستی است و در این فاز نه اعتبارسنجی provider دارد، نه به URL تبدیل می‌شود و نه برای استعلام بیرونی استفاده می‌شود.

## فاز 1.14.0: تکمیل مدیریت سفارش و مشتری

- فهرست سفارش‌ها با جست‌وجوی server-side، وضعیت، بازهٔ تاریخ، حداقل/حداکثر مبلغ، شناسهٔ مشتری و روش پرداخت/ارسال فیلتر می‌شود.
- فهرست سفارش‌ها و مشتریان صفحه‌بندی server-side دارد و فیلترهای صفحهٔ جاری در refresh حفظ می‌شوند.
- جزئیات سفارش شمارهٔ نمایشی، مشتری و راه ارتباطی، پرداخت، جمع سفارش، یادداشت مشتری و projection محدود یادداشت‌های WooCommerce را نمایش می‌دهد؛ token و meta خام هرگز به PWA نمی‌آید.
- تاریخچهٔ سفارش‌های مشتری از route مستقل و با تقاطع scopeهای `customers.read` و `orders.read` در پروندهٔ مشتری نمایش داده می‌شود.
- Customers همچنان read-only است؛ ایجاد/ویرایش مشتری، پرداخت و refund خارج از این فاز است.

## فاز 1.13.0: کاتالوگ محصول متغیر و یکپارچه‌سازی طراحی/تحلیل PWA

- دکمهٔ نصب در مرورگرهای دارای `beforeinstallprompt` از prompt بومی استفاده می‌کند؛ در iOS راهنمای Safari و Add to Home Screen نمایش داده می‌شود.
- Service Worker نسخهٔ جدید را تا انتخاب کاربر در حالت waiting نگه می‌دارد و پس از کلیک کاربر با پیام کنترل‌شده فعال می‌کند.
- در حالت آفلاین App Shell می‌تواند باز بماند، اما POST/PUT/PATCH/DELETE قبل از ارسال متوقف و پیام فارسی قابل‌فهم نمایش داده می‌شود.
- هیچ دادهٔ خصوصی، Session، CSRF یا پاسخ API به Cache App Shell اضافه نمی‌شود.
- پنل مدیریت افزونه از CSS محلی و همان توکن‌های UI وب‌اپ استفاده می‌کند و به فونت/Asset خارجی وابسته نیست.
- تحلیل فروش با کلید تنظیماتی `analytics_enabled` خاموش/روشن می‌شود؛ route تحلیل با Session، scope `analytics.read` و capability مدیریتی محافظت شده است.
- تحلیل به‌صورت خلاصهٔ محدود از دادهٔ WooCommerce همان سایت محاسبه می‌شود؛ در صورت غیرفعال‌بودن WooCommerce یا نبود دسترسی، بخش تحلیل داده‌ای نمایش نمی‌دهد.
- کد واحد پول ذخیره‌شده دست‌نخورده می‌ماند و برچسب `IRT` در خروجی نمایشی PWA به «تومان» تبدیل می‌شود.
- فرم محصول نوع Simple/Variable را مشخص می‌کند و برای محصول متغیر، ویژگی‌های والد را از ویژگی‌های سراسری ووکامرس یا ورودی سفارشی می‌گیرد.
- تغییر نوع محصول موجود از مسیر مدیریت وب‌اپ عمداً بسته است؛ این کار از orphan شدن variationها جلوگیری می‌کند.
- ویژگی‌های والد فقط با taxonomy/term موجود یا متن سفارشی محدود ذخیره می‌شوند و variation فقط به ویژگی‌های تعریف‌شده در والد دسترسی دارد.

## گام بعدی: تست runtime و آماده‌سازی انتشار

قرارداد پذیرش فازهای فعلی در [tests/acceptance-criteria.md](tests/acceptance-criteria.md) آمده است. خلاصهٔ آن:

- Pairing یک‌بارمصرف با TTL، مصرف یک‌باره، revoke و rate limit؛
- Session Cookie امن برای PWA، logout و پاسخ قراردادی برای `401`/`403`؛
- CSRF و Capability مستقل برای عملیات؛
- Products read/write نسخه‌دار با pagination محدود، فیلتر status، allowlist فیلدها و سازگاری با API/CRUD رسمی WooCommerce؛
- Orders read نسخه‌دار با فیلتر وضعیت، جست‌وجو و دادهٔ عملیاتی محدود؛
- Customers read-only با `customers.read`، جست‌وجوی محدود، pagination و جزئیات آدرس؛
- جزئیات سفارش با billing/shipping، line items، shipping lines، پرداخت، جمع سفارش و یادداشت محدود؛
- تاریخچهٔ سفارش‌های هر مشتری از مسیر `customers/{id}/orders`؛
- مدیریت پایهٔ دستگاه‌ها و audit رویدادهای نشست/عملیات حساس با scope و capability مستقل؛
- Media upload فقط با CSRF، capability، rate limit و تبدیل WebP محدود شده است؛ timeout تبدیل مستقل است، نام فایل‌های فارسی fallback امن دارند و خطای نبود GD/Imagick یا پشتیبانی WebP به‌صورت قراردادی گزارش می‌شود؛ فونت محلی نیز با بررسی امضای فایل پذیرفته می‌شود.
- عدم ذخیرهٔ Token/Secret در frontend و عدم استفاده از SQL خام یا proxy عمومی.

UI مدیریت دستگاه/Audit، گالری محصول، رهگیری دستی ارسال و چرخهٔ نصب/به‌روزرسانی PWA در فاز فعلی تکمیل شده‌اند. گام بعدی، تست runtime روی WordPress/WooCommerce واقعی با HTTPS، HPOS و نقش‌های مختلف است. Customer Write عمداً فعال نشده است و فقط پس از تعیین نقش، audit و مجوز فیلدبه‌فیلد باید بررسی شود.

تست ایستای فعلی فقط وجود source و routeهای قرارداد، نبود storage/Access Token در frontend و نبود URL خارجی را بررسی می‌کند. این تست تا قبل از اجرای WordPress واقعی، parse PHP، cookie attribute، replay/rate limit، CSRF، capability، HPOS، query واقعی WooCommerce، WebP engine و رفتار مرورگر را اثبات نمی‌کند. برای پذیرش نهایی باید محیط آزمایشی WordPress/WooCommerce با HTTPS و CI دارای PHP/JavaScript راه‌اندازی شود.
