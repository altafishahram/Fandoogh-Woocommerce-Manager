# چک‌لیست اجرایی تست Runtime نسخهٔ ۱.۱۷.۰ روی WordPress/WooCommerce Staging

این سند برای پذیرش عملی نسخهٔ `1.20.0` افزونهٔ Fandoogh Manager روی یک سایت staging واقعی است. تست‌ها باید روی HTTPS و با WooCommerce واقعی اجرا شوند؛ تست‌های static داخل مخزن به‌تنهایی جای این سند را نمی‌گیرند.

## ۰) اطلاعات اجرای تست

قبل از شروع این مقادیر را ثبت کنید:

| مورد | مقدار تست |
|---|---|
| دامنهٔ staging | `<STAGING_ORIGIN>` با scheme امن HTTPS |
| WordPress | `________________` |
| WooCommerce | `________________` |
| Fandoogh Manager | `1.20.0` |
| PHP | `________________` |
| وب‌سرور/Proxy/CDN | `________________` |
| مرورگر و نسخه | `________________` |
| شناسهٔ سفارش آزمایشی HPOS روشن | `________________` |
| شناسهٔ سفارش آزمایشی HPOS خاموش | `________________` |
| نام تست‌کننده/تاریخ | `________________` |

برای هر ردیف، شواهد را با زمان، URL، status code، response headers و screenshot یا export شبکه ثبت کنید. مقدار واقعی Cookie، CSRF، رمز عبور و pairing code را در گزارش، screenshot یا ticket ذخیره نکنید.

---

## ۱) پیش‌نیازها و ایزوله‌سازی staging

- [ ] سایت از production جداست و هیچ درگاه پرداخت زنده، پیامک زنده، webhook ارسال واقعی یا cron عملیاتی به آن متصل نیست.
- [ ] دامنهٔ staging، دیتابیس، `wp-content/uploads` و تنظیمات وب‌سرور قبل از تست backup قابل‌بازگشت دارند.
- [ ] یک order آزمایشی با محصول ساده، حداقل یک line item، یک shipping line و دادهٔ billing/shipping محدود ساخته شده است.
- [ ] order آزمایشی با درگاه test/offline ساخته شده است؛ تست نباید refund یا تراکنش واقعی ایجاد کند.
- [ ] شناسهٔ order و وضعیت اولیهٔ آن ثبت شده است؛ حداقل یک order موجود و یک شناسهٔ قطعاً ناموجود در اختیار تست‌کننده است.
- [ ] افزونهٔ Fandoogh Manager نسخهٔ `1.20.0` نصب و فعال است و صفحهٔ وضعیت افزونه نسخهٔ درست را نشان می‌دهد.
- [ ] WooCommerce فعال است و صفحهٔ وضعیت WooCommerce خطای بحرانی ندارد.
- [ ] افزونه‌های امنیتی، CDN، reverse proxy و cache طوری تنظیم شده‌اند که Cookie، `Origin`، `Referer`، `X-Fandoogh-CSRF` و `Cache-Control` را حذف یا بازنویسی نکنند.
- [ ] در صورت وجود basic auth یا WAF روی staging، ruleهای مربوط به REST و `application/json` مستند شده‌اند و موقتاً مانع تست نیستند.
- [ ] PHP log، WordPress debug log، وب‌سرور access/error log و کنسول مرورگر در طول تست قابل مشاهده‌اند؛ مقدار secret در logها نباید ثبت شود.
- [ ] تست با یک مرورگر عادی و یک پنجرهٔ Private/Incognito قابل تکرار است.

### مسیرهای اصلی نسخهٔ ۱.۱۲.۰

```text
App Shell: /manager/
API base:  /wp-json/fandoogh-manager/v1
GET:       /orders/{id}/shipment
POST:      /orders/{id}/shipment
Audit:     /auth/audit
CSRF:      /auth/csrf
Session:   /auth/me
```

در این فاز فقط `GET` و `POST` shipment وجود دارد. `PUT`، `PATCH`، `DELETE`، provider API، webhook، `tracking_url` و تغییر خودکار وضعیت WooCommerce جزو قرارداد نیستند.

---

## ۲) HTTPS، Origin و Cookie نشست

### ۲.۱ بررسی HTTPS

- [ ] با بازکردن `<STAGING_ORIGIN>/manager/` گواهی معتبر، chain کامل و نام دامنه صحیح است؛ مقدار `STAGING_ORIGIN` باید HTTPS باشد.
- [ ] نسخهٔ HTTP یا به HTTPS redirect می‌شود و بعد از redirect، آدرس نهایی HTTPS است.
- [ ] در HTTP هیچ session مدیریتی ایجاد نمی‌شود. اگر endpoint به‌جای redirect مستقیماً پاسخ دهد، پاسخ باید خطای امنیتی `403` باشد؛ redirect به‌تنهایی قبولی امنیتی محسوب نمی‌شود.
- [ ] در DevTools > Network برای pairing/session، درخواست نهایی `https` و بدون mixed content است.
- [ ] در Console هیچ خطای mixed content، certificate یا blocked insecure request وجود ندارد.
- [ ] اگر سایت پشت proxy است، proxy مبدأ امن را درست به WordPress منتقل می‌کند؛ `is_ssl()`، `X-Forwarded-Proto` و تنظیمات WordPress باید با معماری واقعی هماهنگ باشند.

### ۲.۲ بررسی Origin

- [ ] pairing و همهٔ mutationها از origin دقیق `<STAGING_ORIGIN>` ارسال می‌شوند.
- [ ] یک POST معتبر با `Origin` دقیق سایت، Cookie معتبر، CSRF معتبر و JSON معتبر `2xx` می‌گیرد.
- [ ] همان POST با `Origin: <ATTACKER_ORIGIN>` رد می‌شود و `403` می‌گیرد.
- [ ] همان POST با scheme، host یا port متفاوت رد می‌شود و `403` می‌گیرد.
- [ ] POST بدون `Origin` و بدون `Referer` رد می‌شود و `403` می‌گیرد؛ وجود CSRF به‌تنهایی جای Origin را نمی‌گیرد.
- [ ] GET احراز‌شدهٔ shipment به CSRF نیاز ندارد، اما همچنان Session، scope و capability را بررسی می‌کند. برای trace یکپارچه، ارسال Origin صحیح در GET نیز توصیه می‌شود.

### ۲.۳ Cookie و نشست

- [ ] pairing از داخل PWA یا flow رسمی `/auth/pair` انجام می‌شود؛ رمز عبور و pairing code در URL، localStorage، sessionStorage یا bundle قرار نمی‌گیرد.
- [ ] پاسخ pairing `Set-Cookie` با نام `fandoogh_manager_session` ایجاد می‌کند.
- [ ] Cookie در HTTPS دارای `HttpOnly`، `Secure` و `SameSite=Strict` است و مسیر/دامنهٔ آن با نصب staging سازگار است.
- [ ] مقدار Cookie در JavaScript با `document.cookie` قابل خواندن نیست.
- [ ] `/auth/me` کاربر و scopeهای محدودشده را نشان می‌دهد، اما raw session، hash، password یا token داخلی را برنمی‌گرداند.
- [ ] `/auth/csrf` یک CSRF معتبر می‌دهد و UI آن را فقط در حافظهٔ runtime نگه می‌دارد.
- [ ] با Logout یا revoke شدن نشست، درخواست بعدی به shipment دیگر `2xx` نمی‌گیرد.

---

## ۳) WooCommerce و HPOS؛ هر دو حالت الزامی

تمام تست‌های بخش‌های ۵ تا ۱۰ باید حداقل یک‌بار در هر دو حالت زیر اجرا شوند.

### ۳.۱ HPOS روشن

- [ ] از مسیر WooCommerce > Settings > Advanced > Features، High-Performance Order Storage روشن است؛ نام منو ممکن است با نسخهٔ WooCommerce کمی تفاوت داشته باشد.
- [ ] اگر WooCommerce صفحهٔ synchronization یا pending orders دارد، قبل از تست sync کامل و بدون خطا انجام شده است.
- [ ] از order آزمایشی HPOS یک snapshot اولیه گرفته شده و شناسهٔ آن ثبت شده است.
- [ ] خواندن جزئیات order در wp-admin و PWA هر دو موفق است.
- [ ] GET/POST shipment، ماندگاری meta و Audit در این حالت بررسی شده است.

### ۳.۲ HPOS خاموش / storage قدیمی

- [ ] قبل از تغییر، backup و وضعیت HPOS ثبت شده است.
- [ ] HPOS خاموش شده و order storage قدیمی/compatibility mode بدون خطای migration فعال است.
- [ ] یک order آزمایشی معتبر در این حالت انتخاب و شناسه‌اش ثبت شده است؛ اگر شناسه بین دو حالت قابل اتکا نیست، از order جداگانه استفاده کنید.
- [ ] همان مجموعه تست GET/POST، ماندگاری، side effect و Audit در این حالت موفق است.
- [ ] پس از پایان، تصمیم نهایی HPOS و نتیجهٔ sync ثبت شده و setting ناخواسته تغییر نکرده است.

### ۳.۳ WooCommerce خاموش برای تست `503`

- [ ] فقط پس از اتمام تست‌های حالت فعال، backup و snapshot گرفته شده است.
- [ ] WooCommerce موقتاً deactivate شده، اما Fandoogh Manager فعال باقی مانده است.
- [ ] با Session معتبر، GET shipment یک order موجود یا POST با body معتبر، `503` و پیام عدم دسترسی API WooCommerce می‌دهد؛ پاسخ نباید fatal error، HTML صفحهٔ wp-admin یا stack trace باشد.
- [ ] پس از فعال‌سازی WooCommerce، همان endpoint به رفتار عادی برمی‌گردد و order/meta قبلی آسیب ندیده است.

---

## ۴) نقش‌های WordPress و scopeها

Scopeها در زمان pairing ساخته می‌شوند و capability کاربر در هر درخواست دوباره بررسی می‌شود. نقش را فقط از نام role حدس نزنید؛ `/auth/me` را ملاک ثبت کنید.

### ۴.۰ صدور Pairing توسط Administrator برای کاربر هدف

- [ ] Administrator از صفحهٔ Settings افزونه، کاربر هدف را از فهرست کاربران واجد شرایط انتخاب می‌کند؛ فهرست فقط کاربرانی را نشان می‌دهد که حداقل capability مدیریت محصولات/فروش را دارند.
- [ ] صدور کد برای کاربر هدف موفق است و کد خام فقط در همان پاسخ HTML نمایش داده می‌شود؛ کد در URL، log، Audit، دیتابیس یا frontend ذخیره نمی‌شود.
- [ ] کاربر هدف با نام کاربری و رمز خودش وارد PWA می‌شود؛ ورود Administrator با کدی که برای کاربر دیگر صادر شده باید `401` باشد.
- [ ] در Audit رویداد `pairing_issued` برای Administrator ثبت می‌شود، اما خود کد یا رمز عبور در context رویداد وجود ندارد.
- [ ] اگر capability کاربر هدف بعد از صدور کد تغییر کند، هنگام مصرف کد scopeها با capability فعلی intersect می‌شوند و privilege قدیمی باقی نمی‌ماند.
- [ ] کاربر WordPress فاقد capability مدیریتی در فهرست هدف قرار نمی‌گیرد و حتی با ارسال دستی ID نیز کد برای او صادر نمی‌شود.

### ۴.۱ ماتریس نقش

| کاربر آزمایشی | حداقل capability مورد انتظار | scope مورد انتظار برای shipment | انتظار GET | انتظار POST |
|---|---|---|---|---|
| Administrator | `manage_options` و معمولاً `manage_woocommerce` | `orders.read`, `orders.update_shipment` | مجاز | مجاز با CSRF/Origin |
| Shop Manager | `manage_woocommerce` | `orders.read`, `orders.update_shipment` | مجاز | مجاز با CSRF/Origin |
| کاربر محدود با `edit_products` ولی بدون `manage_woocommerce`/`manage_options` | فقط `edit_products` | scopeهای order حذف می‌شوند | `403` | `403` |
| کاربر بدون `edit_products`، `manage_woocommerce` یا `manage_options` | هیچ scope مدیریتی معتبر | pairing باید رد شود (`403`) | قابل اجرا نیست | قابل اجرا نیست |

در پیاده‌سازی فعلی، وجود `orders.update_shipment` به‌تنهایی کافی نیست؛ POST هم‌زمان به `orders.read`، capability `manage_woocommerce` یا `manage_options`، Session معتبر، CSRF معتبر و Origin هم‌مبدأ نیاز دارد.

### ۴.۲ اجرای ماتریس

- [ ] برای Administrator pairing انجام دهید و در `/auth/me` وجود هر دو scope را ثبت کنید.
- [ ] برای Shop Manager pairing جدا انجام دهید و وجود/عدم وجود scopeها و capability واقعی را ثبت کنید.
- [ ] برای کاربر محدود pairing انجام دهید؛ نباید صرفاً به‌دلیل ورود موفق، scope سفارش دریافت کند.
- [ ] با کاربر محدود GET و POST shipment را اجرا کنید؛ هر دو باید `403` باشند و دادهٔ سفارش برنگردد.
- [ ] بعد از ایجاد Session، capability کاربر را موقتاً حذف/اضافه کنید و یک درخواست جدید بفرستید؛ نتیجه باید بر اساس capability فعلی تغییر کند، نه فقط scope قدیمی.
- [ ] پس از هر تغییر نقش، نشست‌های قبلی را revoke یا دوباره pair کنید تا نتیجهٔ تست قابل ردیابی باشد.
- [ ] فقط Administrator دارای `audit.read` و دسترسی صفحهٔ Audit را به‌عنوان مشاهده‌گر نهایی Audit استفاده کنید.

### ۴.۳ تحلیل اختیاری WooCommerce و واحد پول

- [ ] در Settings، گزینهٔ تحلیل فروش به‌صورت پیش‌فرض خاموش است و با خاموش‌بودن آن بخش تحلیل در PWA و scope `analytics.read` صادر نمی‌شود.
- [ ] گزینه را روشن کنید، نشست قبلی را logout/revoke کنید و Pairing را دوباره انجام دهید؛ Administrator یا Shop Manager دارای capability مدیریتی باید scope `analytics.read` را دریافت کند.
- [ ] با نشست معتبر، `GET /wp-json/fandoogh-manager/v1/analytics/summary?range=30d` پاسخ `200` و envelope `data` برگرداند؛ پاسخ فقط aggregate فروش، سفارش، وضعیت، مشتری یکتا و محصول پرفروش باشد.
- [ ] با کاربر محدود یا بدون `analytics.read`، همین route `403` بدهد و با خاموش‌کردن تنظیم، route نیز `403` بدهد.
- [ ] تعداد فروش/سفارش/میانگین و وضعیت‌ها با دادهٔ همان بازه در WooCommerce تطبیق داده شود؛ دادهٔ مشتری حساس، raw meta و URL خارجی در پاسخ نباشد.
- [ ] اگر واحد پول WooCommerce برابر `IRT` است، کد در تنظیمات/دادهٔ داخلی تغییر نکند اما PWA و تحلیل برچسب «تومان» را نمایش دهند؛ اعداد نمایشی فارسی و تاریخ‌های PWA جلالی باشند.
- [ ] پس از تغییر بازه و reload، تحلیل از API دوباره خوانده شود و در Service Worker، localStorage یا sessionStorage ذخیره نشود.

---

## ۵) قرارداد و تست مثبت GET/POST shipment

### ۵.۰ تغییر وضعیت سفارش و همگام‌سازی فهرست

- [ ] با سفارش آزمایشی، وضعیت را از کارت سفارش تغییر دهید؛ درخواست POST وضعیت با CSRF و capability معتبر موفق شود.
- [ ] پس از ذخیره، UI باید وضعیت را از پاسخ mutation یا بازخوانی فهرست نمایش دهد؛ پیام خطای کاذب ناشی از refresh دیرهنگام نباید جای موفقیت mutation را بگیرد.
- [ ] اگر پاسخ فهرست قدیمی یا دیرهنگام رسید، request جدیدتر باید برنده بماند و status قبلی را روی کارت برنگرداند.
- [ ] بعد از بستن و بازکردن جزئیات سفارش، وضعیت جدید و Audit رویداد `order_status_updated` باقی بماند.
- [ ] تغییر وضعیت سفارش نباید اطلاعات shipment snapshot را پاک یا تغییر دهد.

### ۵.۱ قرارداد داده

مسیر کامل:

```text
GET  <STAGING_ORIGIN>/wp-json/fandoogh-manager/v1/orders/<ORDER_ID>/shipment
POST <STAGING_ORIGIN>/wp-json/fandoogh-manager/v1/orders/<ORDER_ID>/shipment
```

Body مجاز POST فقط این فیلدهاست:

```json
{
  "status": "shipped",
  "tracking_code": "TEST-1700-001",
  "carrier_label": "حمل آزمایشی",
  "shipped_at": "2026-08-22T10:30:00Z",
  "estimated_delivery_date": "2026-08-25",
  "delivered_at": null,
  "note": "تست runtime روی staging"
}
```

وضعیت‌های مجاز:

```text
pending, ready, shipped, in_transit, out_for_delivery,
delivered, failed, returned, cancelled
```

محدودیت‌ها: `tracking_code` حداکثر ۱۰۰ نویسه، `carrier_label` حداکثر ۸۰ نویسه و `note` حداکثر ۵۰۰ نویسه. زمان‌ها باید RFC3339 معتبر با timezone باشند؛ تاریخ تخمینی باید `YYYY-MM-DD` معتبر باشد. `updated_at` و `order_id` توسط client قابل ارسال نیستند؛ `order_id` فقط از path گرفته می‌شود.

### ۵.۲ دریافت snapshot اولیه

- [ ] با کاربر مجاز و order موجود، GET را بزنید.
- [ ] پاسخ `200`، JSON و دارای `data` است.
- [ ] اگر shipment قبلاً ذخیره نشده، status برابر `pending`، رشته‌های متنی خالی و تاریخ‌ها `null` یا مقدار خالی قراردادی هستند.
- [ ] پاسخ فقط فیلدهای قرارداد مانند `status`, `tracking_code`, `carrier_label`, `shipped_at`, `estimated_delivery_date`, `delivered_at`, `note`, `updated_at`, `order_id`, `has_tracking` را دارد؛ raw meta، token، payment data یا URL provider برنمی‌گردد.
- [ ] GET هیچ Audit mutation برای order ایجاد نمی‌کند.

### ۵.۳ ذخیرهٔ snapshot

- [ ] با POST معتبر حداقل `{"status":"ready"}`، پاسخ `200` یا `201` قراردادی و JSON دریافت کنید.
- [ ] با body کامل نمونه، پاسخ موفق و snapshot نرمال‌شده دریافت کنید.
- [ ] status ارسال `shipped`، `in_transit`، `out_for_delivery` یا `delivered` در صورت خالی‌بودن `shipped_at`، timestamp سروری معتبر می‌گیرد.
- [ ] status `delivered` در صورت خالی‌بودن `delivered_at`، timestamp سروری معتبر می‌گیرد.
- [ ] `updated_at` توسط سرور ساخته می‌شود و با ساعت UTC معتبر است؛ مقدار ساختگی client پذیرفته نمی‌شود.
- [ ] POST دوباره با همان body، بدون ایجاد field ناشناخته یا رکورد موازی، snapshot کنترل‌شده را به‌روزرسانی می‌کند.
- [ ] پس از POST، GET مجدد دقیقاً مقدار ذخیره‌شدهٔ قرارداد را برمی‌گرداند.
- [ ] `tracking_code` در پاسخ فقط متن است و هیچ لینک یا redirect خارجی ساخته نمی‌شود.
- [ ] تغییر shipment status مانند `delivered` باعث تغییر WooCommerce order status نمی‌شود.

### ۵.۴ نمونهٔ درخواست از DevTools یا curl

برای جلوگیری از افشای secret، pairing و Cookie را ترجیحاً از خود PWA انجام دهید و در Network یک request سالم را با مقادیر حساس redacted بررسی کنید. نمونهٔ زیر فقط placeholder دارد:

```powershell
$origin = '<STAGING_ORIGIN>'
$base = "$origin/wp-json/fandoogh-manager/v1"
$orderId = 12345
$cookie = 'fandoogh_manager_session=<REDACTED>'
$csrf = '<REDACTED>'

curl.exe -i "$base/orders/$orderId/shipment" `
  -H "Origin: $origin" `
  -H "Cookie: $cookie"

curl.exe -i -X POST "$base/orders/$orderId/shipment" `
  -H "Origin: $origin" `
  -H "Cookie: $cookie" `
  -H "Content-Type: application/json" `
  -H "X-Fandoogh-CSRF: $csrf" `
  --data '{"status":"shipped","tracking_code":"TEST-1700-001","carrier_label":"حمل آزمایشی","estimated_delivery_date":"2026-08-25","note":"staging"}'
```

در گزارش نهایی، headerها و body را با redaction ثبت کنید؛ Cookie و CSRF واقعی نباید در shell history یا repository بمانند.

---

## ۶) ماتریس پاسخ‌های خطا

تمام سناریوهای منفی را با order و کاربر staging اجرا کنید. برای رسیدن به خطای body، ابتدا باید Session، scope، capability، CSRF و Origin معتبر باشند؛ permission callback ممکن است قبل از رسیدن به body، خطای `401` یا `403` بدهد.

| وضعیت | سناریوی اجرایی | انتظار |
|---:|---|---|
| `401` | Cookie `fandoogh_manager_session` حذف، منقضی یا ساختگی؛ GET یا POST | JSON خطای احراز هویت؛ بدون redirect به wp-admin و بدون دادهٔ order |
| `403` | scope `orders.read` یا `orders.update_shipment` وجود ندارد | JSON forbidden؛ برای role محدود هر دو روش رد شوند |
| `403` | capability مدیریتی حذف شده یا کاربر `manage_woocommerce`/`manage_options` ندارد | JSON forbidden؛ capability فعلی بررسی شود |
| `403` | POST بدون `X-Fandoogh-CSRF`، token اشتباه، کوتاه، تغییرکرده یا مربوط به نشست دیگر | کد خطای CSRF؛ هیچ save و Audit موفقی رخ ندهد |
| `403` | POST با Origin مهاجم، scheme/host/port متفاوت، یا بدون Origin و Referer | کد خطای Origin؛ هیچ save و Audit موفقی رخ ندهد |
| `403` | دسترسی روی HTTP ناامن | خطای transport/security؛ Session مدیریتی ساخته یا استفاده نشود |
| `404` | `ORDER_ID` عددی اما ناموجود | order وجود ندارد؛ عدم افشای اینکه چه داده‌ای در meta بوده است |
| `404` | order متعلق به وضعیت/نوعی است که `orders_is_readable_order` رد می‌کند، مانند order حذف‌شده/غیرقابل‌خواندن | همان پاسخ عمومی 404؛ بدون افشای raw storage |
| `415` | POST با `application/x-www-form-urlencoded`، `text/plain` یا multipart به‌جای JSON | خطای JSON required؛ هیچ mutation رخ ندهد |
| `422` | JSON خراب، body غیرآرایه، نبود `status` یا status ناشناخته | خطای input؛ هیچ mutation رخ ندهد |
| `422` | field ناشناخته مانند `tracking_url`, `updated_at`, `payment_token` یا `order_id` در body | رد کامل body؛ field ناشناخته ذخیره نشود |
| `422` | متن بالاتر از سقف، HTML، NUL، control character یا نوع غیررشته‌ای | رد کامل body؛ متن partially sanitized و ذخیره نشود |
| `422` | تاریخ نامعتبر، تاریخ جعلی، timezone ناقص یا قالب غیر-RFC3339 برای datetime | رد کامل body؛ snapshot قبلی حفظ شود |
| `503` | WooCommerce غیرفعال یا `WC_Order`/`wc_get_order()` در دسترس نیست | خطای سرویس وابسته؛ fatal error، HTML یا ذخیرهٔ ناقص نباشد |

برای هر خطا این موارد را ثبت کنید: status code، `code` JSON، وجود/عدم وجود `data`، headerهای cache، تغییر نکردن order، ایجاد نشدن Audit موفق و نبودن secret در response. خطای `500` ناشی از storage یا save را نیز اگر رخ داد گزارش کنید؛ آن خطا قبولی نسخه نیست و باید بررسی شود.

---

## ۷) CSRF و replay/نشست

- [ ] بعد از pairing، مقدار CSRF از `/auth/csrf` یا پاسخ pairing گرفته شده و در UI فقط memory است.
- [ ] POST با CSRF معتبر موفق است.
- [ ] حذف header CSRF، `403` می‌دهد.
- [ ] تغییر یک نویسهٔ CSRF، `403` می‌دهد.
- [ ] استفاده از CSRF نشست دستگاه A همراه Cookie نشست دستگاه B، `403` می‌دهد.
- [ ] rotate کردن CSRF با `/auth/csrf` باعث می‌شود مقدار قبلی برای POST بعدی نامعتبر شود و مقدار جدید معتبر باشد.
- [ ] بعد از Logout/revoke، Cookie قبلی دیگر برای GET و POST قابل استفاده نیست.
- [ ] Refresh صفحه یا بازکردن تب جدید secret را در localStorage/sessionStorage/IndexedDB ایجاد نمی‌کند.
- [ ] درخواست POST ناموفق به‌علت CSRF یا Origin در Audit به‌عنوان `shipment_updated` موفق ثبت نمی‌شود.

---

## ۸) اثبات ماندگاری `WC_Order CRUD` و نبود side effect

این بخش باید در هر دو وضعیت HPOS روشن و خاموش اجرا شود. قبل از POST، snapshot زیر را از طریق getterهای رسمی WooCommerce بگیرید؛ مستقیم از جدول‌های دیتابیس برای اثبات عملکرد افزونه استفاده نکنید.

### ۸.۱ snapshot قبل و بعد

برای order آزمایشی این موارد را قبل از POST و بعد از GET/POST ثبت و مقایسه کنید:

- [ ] `get_id()` و شناسهٔ order ثابت است.
- [ ] `get_status()` دقیقاً ثابت است؛ shipment status مستقل است.
- [ ] `get_payment_method()`, `get_payment_method_title()`, `get_transaction_id()` و `get_date_paid()` ثابت‌اند.
- [ ] `get_total()`, `get_total_tax()`, `get_total_refunded()` و تعداد/شناسهٔ refundها ثابت‌اند.
- [ ] customer، billing، shipping address، currency و totals ثابت‌اند.
- [ ] تعداد، شناسه، method، title، total و tax مربوط به `get_items('shipping')` ثابت‌اند.
- [ ] line itemهای محصول، quantity، قیمت و tax ثابت‌اند.
- [ ] فقط snapshot کنترل‌شدهٔ `_fandoogh_shipment` تغییر می‌کند و با `$order->get_meta('_fandoogh_shipment', true)` از API رسمی قابل خواندن است.
- [ ] `get_date_modified()` ممکن است به‌دلیل save شدن meta تغییر کند؛ این تغییر متادیتای فنی قابل‌انتظار است و نباید به‌عنوان تغییر محتوای order تفسیر شود.
- [ ] در storage فعال، هیچ جدول یا meta دلخواه دیگری از طرف افزونه برای shipment ساخته نمی‌شود.

یک نمونهٔ WP-CLI برای staging، با جایگزینی ID، فقط برای مشاهدهٔ کنترل‌شده:

```powershell
wp eval '$o=wc_get_order(12345); echo wp_json_encode(array("id"=>$o->get_id(),"status"=>$o->get_status(),"payment_method"=>$o->get_payment_method(),"transaction_id"=>$o->get_transaction_id(),"total_refunded"=>$o->get_total_refunded(),"shipment"=>$o->get_meta("_fandoogh_shipment",true),"shipping_items"=>array_map(function($i){return array("id"=>$i->get_id(),"method_id"=>$i->get_method_id(),"total"=>$i->get_total(),"total_tax"=>$i->get_total_tax());},$o->get_items("shipping"))),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);'
```

این فرمان را فقط روی staging اجرا کنید و خروجی را قبل/بعد با حذف دادهٔ مشتری و tokenها ذخیره کنید. عدم تغییر status، payment، refund و shipping line شرط قبولی است.

### ۸.۲ آزمون عدم mutation ناخواسته

- [ ] POST فقط با status ارسال `delivered` انجام دهید؛ پس از آن WooCommerce order status مثلاً `processing` یا `completed` را به‌صورت خودکار تغییر نداده است.
- [ ] با order پرداخت‌نشده نیز همین تست را انجام دهید؛ shipment update نباید payment را capture، mark paid یا cancel کند.
- [ ] وجود refund قبلی در order آزمایشی را ثبت کنید؛ POST نباید refund بسازد، حذف کند یا مبلغ refund را تغییر دهد.
- [ ] shipping line با نام و مبلغ مشخص بسازید؛ POST نباید method، مبلغ، tax، quantity یا شناسهٔ آن را تغییر دهد.
- [ ] خطاهای `415`، `422` و `403` را بین دو snapshot اجرا کنید؛ در هیچ‌کدام حتی shipment snapshot ناقص هم نباید ذخیره شود.

---

## ۹) Audit و قابلیت ردیابی

- [ ] پس از هر POST موفق، در `/auth/audit` یا UI Audit یک رویداد `shipment_updated` دیده می‌شود.
- [ ] `resource_type` برابر `order` و `resource_id` برابر order ID است.
- [ ] context فقط داده‌های عملیاتی محدود مانند `outcome=saved`، `order_id` و `status` دارد.
- [ ] tracking code، note، CSRF، Cookie، password، pairing code، hash و raw request در Audit ثبت نشده‌اند.
- [ ] تغییر دوبارهٔ shipment یک رویداد جدید با زمان و device/session درست می‌سازد؛ Audit قبلی overwrite نمی‌شود.
- [ ] GET shipment، خطای 401/403/404/415/422/503 و request ناموفق، رویداد `shipment_updated` موفق تولید نمی‌کنند.
- [ ] Audit فقط برای کاربر مجاز با `audit.read` و capability مدیریتی قابل مشاهده است؛ کاربر محدود `403` می‌گیرد.
- [ ] پس از revoke دستگاه یا Logout، نشست revoked قادر به خواندن Audit یا shipment نیست.
- [ ] timestamp Audit و `updated_at` قابل مقایسه و در timezone مستند هستند؛ در صورت نمایش محلی، timezone مرورگر ثبت شود.

---

## ۱۰) no-store، Service Worker و Storage مرورگر

### ۱۰.۱ HTTP headers

برای GET موفق، POST موفق و همهٔ خطاهای shipment در Network بررسی کنید:

- [ ] `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`
- [ ] `Pragma: no-cache`
- [ ] `X-Content-Type-Options: nosniff`
- [ ] response JSON است و HTML wp-admin یا debug trace نیست.
- [ ] CDN، proxy و browser headerهای no-store را حذف نکرده‌اند.

### ۱۰.۲ Service Worker

- [ ] Service Worker فعال نسخهٔ cache فعلی افزونه، یعنی `fandoogh-manager-shell-v25`، را دریافت کرده است؛ اگر cache قدیمی دیده می‌شود، reload سخت و unregister کنترل‌شده انجام دهید.
- [ ] در Application > Service Workers، scope همان `/manager/` یا مسیر PWA نصب‌شده است.
- [ ] در Application > Cache Storage هیچ request دارای `/wp-json/`، به‌خصوص `/orders/<id>/shipment`، ذخیره نشده است.
- [ ] با offline کردن شبکه، App Shell ممکن است نمایش داده شود، اما دادهٔ shipment از cache قبلی نشان داده نمی‌شود.
- [ ] یک GET بعد از تغییر shipment از network مقدار تازه را می‌گیرد؛ response قدیمی Service Worker برنمی‌گردد.
- [ ] تغییر نسخهٔ Service Worker cacheهای قدیمی App Shell را پاک کرده و cache API خصوصی ایجاد نکرده است.

### ۱۰.۳ Storage و secretها

- [ ] localStorage، sessionStorage و IndexedDB فاقد session cookie، CSRF، pairing code، password، raw order و shipment payload هستند.
- [ ] تنها محل session، Cookie HttpOnly مرورگر است؛ session در state JavaScript فقط تا زمان reload در حافظه است.
- [ ] CSRF در refresh یا close/reopen تب به‌صورت دائمی بازیابی نمی‌شود؛ در صورت نیاز دوباره از endpoint امن گرفته می‌شود.
- [ ] DevTools > Application > Cookies فقط attributeهای مورد انتظار را نشان می‌دهد و مقدار Cookie از script قابل خواندن نیست.
- [ ] پس از Logout، cache App Shell باقی‌مانده هم حاوی پاسخ API یا دادهٔ order نیست.

---

## ۱۱) WebP و فونت محلی

این بخش مستقل از shipment است، اما چون نسخهٔ ۱.۷.۰ پنل محصول و تنظیمات برندینگ را نیز حمل می‌کند باید روی همان staging قبول شود.

### ۱۱.۱ WebP و تنظیمات تصویر

- [ ] در Fandoogh Manager > Settings مقدارهای تست ثبت شود: حداکثر عرض، حداکثر ارتفاع، quality، حداکثر bytes و `Keep original`.
- [ ] با یک JPEG و یک PNG معتبر، از گالری محصول upload انجام دهید؛ request دارای Session، `upload_files`، CSRF و Origin درست است.
- [ ] پاسخ موفق `mime_type=image/webp`، URL هم‌مبدأ، ابعاد و output bytes را نشان می‌دهد.
- [ ] کارت‌های محصول، سفارش، مشتری و رسانه مبلغ/تعداد/شناسه را با اعداد فارسی نشان می‌دهند؛ تاریخ‌های سفارش، رهگیری و نشست با تقویم جلالی نمایش داده می‌شوند.
- [ ] تصویر معتبر با نام فارسی و پسوند JPG/PNG/WebP/GIF بدون خطای نام فایل آپلود می‌شود؛ پیام خطای ۴۰۰/۴۱۳/۴۱۵/۴۲۲/۵۰۰/۵۰۳ نیز علت امن و قابل اقدام دارد.
- [ ] ابعاد خروجی از سقف width/height تنظیم‌شده بیشتر نیست؛ نسبت تصویر بی‌دلیل خراب نشده است.
- [ ] `Content-Type` فایل خروجی `image/webp` است و فایل روی سایت خارجی نیست.
- [ ] وقتی `Keep original` خاموش است، فایل source طبق قرارداد حذف می‌شود؛ وقتی روشن است، source در مسیر مورد انتظار باقی می‌ماند.
- [ ] تصویر WebP در Media Library و گالری محصول قابل مشاهده است و تصویر شاخص از gallery جدا باقی می‌ماند.
- [ ] سقف حجم بسیار پایین را به‌صورت کنترل‌شده تست کنید؛ اگر WebP حتی با کیفیت حداقلی از سقف بزرگ‌تر باشد، پاسخ `422` است و attachment ناقص یا orphan ایجاد نمی‌شود.
- [ ] فایل جعلی با پسوند تصویری، MIME اشتباه، فایل بیش‌ازحد بزرگ و تصویر خراب رد می‌شود؛ پاسخ HTML یا stack trace نیست.
- [ ] در صورت نبود GD/Imagick یا image editor، upload با خطای قراردادی `503` برمی‌گردد و فایل موقت پاک‌سازی می‌شود.
- [ ] خطا یا upload تصویر، shipment، status سفارش، payment، refund و shipping lines را تغییر نمی‌دهد.

### ۱۱.۲ فونت محلی

- [ ] یک فونت واقعی `woff2` یا `woff` حداکثر ۱۰ مگابایت از wp-admin افزونه upload شود.
- [ ] فونت در مسیر مدیریت‌شدهٔ uploads/fandoogh-manager/fonts ذخیره و در فهرست تنظیمات ثبت شود.
- [ ] `/fonts.css` با `Content-Type: text/css`، `nosniff` و URLهای هم‌مبدأ پاسخ دهد؛ CSS یا JavaScript کاربر به‌عنوان فونت پذیرفته نشود.
- [ ] PWA بدون CDN، Google Fonts یا URL خارجی، فونت `FandooghLocal` را اعمال کند؛ در صورت نبود فونت، fallbackهای محلی/سیستمی کار کنند.
- [ ] فایل با پسوند مجاز ولی signature اشتباه، پسوند غیرمجاز، CSS، JS یا فایل بیش‌ازحد بزرگ رد شود.
- [ ] حذف فونت از تنظیمات، فایل و فهرست عمومی را حذف کند و پس از reload، UI از fallback استفاده کند.
- [ ] font URL، CSS، logo و icon همگی same-origin باشند و CSP صفحه آن‌ها را محدود نکند.

---

## ۱۲) تست UI، نصب و رفتار کاربر

- [ ] PWA در `/manager/` بدون embed کردن wp-admin باز می‌شود.
- [ ] در صفحهٔ سفارش، بخش «رهگیری ارسال» فقط برای Session مجاز نمایش داده می‌شود.
- [ ] بارگذاری جزئیات order، GET shipment را انجام می‌دهد و خطاهای 401/403/404/503 را به پیام قابل‌فهم تبدیل می‌کند؛ secret یا stack trace به کاربر نشان داده نمی‌شود.
- [ ] Save shipment با date/datetime محلی، مقدار RFC3339 معتبر به سرور می‌فرستد و بعد از reload همان زمان درست دیده می‌شود.
- [ ] فرم با statusهای allowlist کار می‌کند و `delivered` را با وضعیت WooCommerce اشتباه نمی‌گیرد.
- [ ] دکمهٔ Save در زمان درخواست دوباره submit نمی‌شود؛ خطای شبکه، timeout و پاسخ غیر `2xx` state قبلی را از بین نمی‌برد.
- [ ] نصب PWA در Chrome/Edge دسکتاپ، Android و Safari iOS یا مسیر معادل آن در staging آزمایش شده است.
- [ ] در رزولوشن موبایل، تبلت و desktop، فرم shipment و پیام خطا قابل استفاده و RTL است.
- [ ] بستن order detail، state و دادهٔ shipment قبلی را به order بعدی نشت نمی‌دهد.

### ۱۲.۱ تست افزایش گروهی قیمت نسخهٔ ۱.۱۵.۰

- [ ] از قیمت محصولات تستیِ یک دستهٔ والد و حداقل یک زیردسته snapshot/backup گرفته شده است.
- [ ] پیش‌نمایش درصدی بدون mutation، تعداد محصول/قیمت و نمونهٔ قبل/بعد را درست نشان می‌دهد.
- [ ] با خاموش‌کردن «زیردسته‌ها» فقط خود دسته و با روشن‌کردن آن دسته‌های فرزند نیز محاسبه می‌شوند.
- [ ] افزایش مبلغ ثابت روی محصول ساده و variation اجرا و قیمت عادی/فروش ویژه با همان مقدار افزایش پیدا می‌کند.
- [ ] تغییر پارامتر پس از preview، token قبلی را نامعتبر می‌کند و اجرای مستقیم بدون preview پاسخ `409` می‌گیرد.
- [ ] درخواست بدون CSRF، بدون `products.write` یا بدون capability ویرایش محصول mutation ندارد و `403` می‌گیرد.
- [ ] event محدود `product_bulk_price_updated` با تعداد تغییرها ثبت و هیچ token یا فهرست کامل قیمت‌ها وارد Audit نمی‌شود.
- [ ] پس از reload و پاک‌سازی cache، قیمت‌های جدید از WooCommerce CRUD خوانده می‌شوند و قیمت محصول خارج از دسته تغییر نکرده است.

### ۱۲.۲ تست فازهای عملیاتی نسخهٔ ۱.۱۷.۰

- [ ] برای نشست‌های Pairing قبلی، logout و Pairing دوباره انجام شده و scopeهای `orders.add_note`, `orders.refund`, `inventory.read/write`, `coupons.read/write` و `reviews.read/write` مطابق capability صادر شده‌اند.
- [ ] یادداشت داخلی و یادداشت مشتری از جزئیات سفارش ثبت می‌شوند؛ note خام در Audit ذخیره نمی‌شود و کاربر بدون capability پاسخ `403` می‌گیرد.
- [ ] بازپرداخت مبلغی و بازپرداخت کامل باقی‌مانده با درگاه test/offline آزموده می‌شوند؛ مقدار بیشتر از مانده `422` می‌گیرد، idempotency تکراری refund دوم نمی‌سازد و restore موجودی طبق انتخاب تست می‌شود.
- [ ] fulfillment جزئی: برای order چندقلمی، تعداد ارسال‌شدهٔ هر قلم ذخیره می‌شود، بیشتر از quantity رد می‌شود و snapshot از طریق `WC_Order` CRUD/HPOS بعد از reload باقی می‌ماند.
- [ ] ساخت/ویرایش/حذف کوپن درصدی و مبلغ ثابت، تاریخ انقضا، حداقل خرید، سقف مصرف و جلوگیری از کد تکراری تست می‌شود؛ حذف فقط به Trash می‌رود.
- [ ] فهرست دیدگاه‌ها با status/search، تغییر به approve/hold/spam و پاسخ فروشگاه تست می‌شود؛ HTML خام، تغییر دیدگاه محصول دیگر و کاربر بدون capability رد می‌شوند.
- [ ] مرکز موجودی با search/filter، مقدار، stock status، backorders و low-stock روی محصول ساده تست می‌شود؛ variation مستقل بدون قرارداد inventory عمومی تغییر نمی‌کند.
- [ ] فیلدهای محصول شامل وزن/ابعاد، backorders، tax status، sold individually، virtual و downloadable ساخته/ویرایش و بعد از reload با CRUD رسمی تطبیق داده می‌شوند.
- [ ] همهٔ routeهای جدید در Network headerهای no-store/nosniff دارند و هیچ پاسخ خصوصی در Service Worker، Cache Storage، localStorage یا sessionStorage دیده نمی‌شود.
- [ ] UI جدید در ۳۷۵، ۷۶۸، ۱۰۲۴ و ۱۴۴۰ پیکسل، RTL، keyboard focus، حالت loading/empty/error و prefers-reduced-motion بررسی می‌شود؛ هیچ وابستگی خارجی یا فونت CDN در Network وجود ندارد.

---

## ۱۳) rollback و پاک‌سازی امن

### ۱۳.۱ قبل از rollback

- [ ] اگر هر معیار امنیتی یا side effect شکست خورد، فوراً write تست را متوقف کنید و orderهای staging را علامت‌گذاری کنید.
- [ ] access log، error log، Network export redacted، Audit و snapshot قبل/بعد را حفظ کنید.
- [ ] نسخهٔ فعال، zip نسخهٔ قبلی، تنظیم HPOS، optionهای افزونه، uploads و وضعیت sessionها ثبت شده است.
- [ ] قبل از هر restore، یک backup از وضعیت فعلی شکست‌خورده بگیرید تا forensic evidence از بین نرود.

### ۱۳.۲ روش rollback

- [ ] pairing codeهای استفاده‌نشده را باطل کنید و نشست‌های staging را revoke-all کنید؛ روی production این کار انجام نشود.
- [ ] در صورت مشکل فقط در کد ۱.۷.۰ و نبود migration مخرب، افزونه را deactivate و ZIP نسخهٔ قبلیِ تأییدشده را نصب کنید؛ از `git reset` یا حذف دستی uploads استفاده نکنید.
- [ ] meta کنترل‌شدهٔ `_fandoogh_shipment` را بدون تصمیم صریح مالک داده حذف نکنید؛ نسخهٔ قبلی می‌تواند آن را نادیده بگیرد و حذف آن قابل‌بازگشت نیست.
- [ ] اگر side effect یا corruption در order مشاهده شد، backup دیتابیس/فایل و snapshot order را طبق runbook سایت restore کنید؛ restore باید در staging rehearsal شود.
- [ ] HPOS را به setting ثبت‌شدهٔ قبل برگردانید و sync را کامل کنید؛ در صورت pending sync، قبل از تغییر بعدی وضعیت را مستند کنید.
- [ ] Service Worker را unregister و Cache Storage را فقط در مرورگر staging پاک کنید؛ این کار جایگزین پاک‌سازی server cache نیست.
- [ ] بعد از rollback، `/manager/`، login/session، orders read و سلامت WooCommerce را smoke test کنید.
- [ ] نتیجهٔ rollback، زمان بازگشت، نسخهٔ نهایی و هر دادهٔ باقی‌ماندهٔ `_fandoogh_shipment` ثبت شود.

---

## ۱۴) معیار قبولی نهایی

نسخهٔ ۱.۱۵.۰ فقط زمانی برای انتشار بعدی پذیرفته است که همهٔ موارد زیر برقرار باشد:

- [ ] تمام smoke testهای نصب، `/manager/`، pairing، Session و CSRF روی HTTPS موفق‌اند.
- [ ] GET و POST shipment در HPOS روشن و HPOS خاموش با order واقعی staging موفق‌اند.
- [ ] ماتریس role/scope اجرا شده و کاربر بدون `orders.read` یا `orders.update_shipment`، و کاربر بدون capability مدیریتی، `403` می‌گیرند.
- [ ] `401`, `403`, `404`, `415`, `422` و `503` با سناریوی واقعی مشاهده و evidence آن‌ها ثبت شده است.
- [ ] CSRF اشتباه/قدیمی و Origin اشتباه هر دو قبل از mutation رد می‌شوند.
- [ ] snapshot shipment بعد از POST با `WC_Order` CRUD باقی می‌ماند و بعد از reload/GET و restart PHP-FPM یا cache purge قابل خواندن است.
- [ ] WooCommerce order status، payment، refund، customer data، product lines و shipping lines هیچ تغییر ناخواسته‌ای ندارند.
- [ ] برای هر POST موفق Audit محدود `shipment_updated` ثبت می‌شود و برای خطاها event موفق جعلی ایجاد نمی‌شود.
- [ ] همهٔ پاسخ‌های shipment و خطاهای آن no-store هستند و هیچ API response در Service Worker، Cache Storage، localStorage، sessionStorage یا IndexedDB باقی نمی‌ماند.
- [ ] WebP با ابعاد/حجم تنظیم‌شده و فونت محلی معتبر بدون URL خارجی کار می‌کنند و فایل‌های نامعتبر رد می‌شوند.
- [ ] پیش‌نمایش و اجرای افزایش گروهی قیمت برای درصد، مبلغ ثابت، دسته و زیردسته موفق است و محصولات خارج از انتخاب تغییر نکرده‌اند.
- [ ] rollback rehearsal روی staging موفق است و مسیر بازگشت به نسخهٔ قبلی و restore داده‌ها مستند و قابل اجراست.
- [ ] هیچ fatal error، warning حساس، token، password، raw meta یا دادهٔ مشتری خارج از allowlist در response، log، Audit یا cache مشاهده نشده است.

### نتیجه

```text
نتیجه:       PASS / CONDITIONAL / FAIL
تست‌کننده:   __________________
تاریخ:       __________________
Build/ZIP:   fandoogh-manager-1.20.0.zip
توضیح:       _________________________________________________
امضا/تأیید:  __________________
```

`CONDITIONAL` فقط برای ایراد مستند غیرامنیتی و با تأیید مالک محصول مجاز است. هر شکست در احراز هویت، CSRF/Origin، افشای داده، no-store، side effect روی سفارش، ماندگاری CRUD یا rollback باید نتیجه را `FAIL` کند و انتشار را متوقف سازد.
