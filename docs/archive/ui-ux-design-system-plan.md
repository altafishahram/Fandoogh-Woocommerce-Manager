# برنامهٔ Design System و ممیزی UI/UX پنل Fandoogh Manager

وضعیت: سند اجرایی برای تحویل به تیم طراحی و توسعه

دامنه: طراحی و تجربهٔ کاربری پنل RTL وب‌اپ/افزونهٔ مدیریت WooCommerce. این سند هیچ تغییری در backend، PHP، فایل اجرایی، HTML، JavaScript یا CSS ایجاد نمی‌کند و فقط مبنای پیاده‌سازی بعدی است.

## ۱. خلاصهٔ اجرایی

UI فعلی یک پوستهٔ محلی، فارسی و نسبتاً منسجم دارد و بخش مهمی از زیرساخت تجربهٔ کاربری را آماده کرده است: `lang="fa"` و `dir="rtl"` در ریشهٔ HTML، landmarkهای معنایی، حالت‌های امنیت/بارگذاری/خالی/خطا، فرم‌های پایهٔ محصول، drawer موبایل، bottom navigation، lazy-load تصاویر و پشتیبانی از فونت محلی.

بااین‌حال، خروجی فعلی هنوز یک «پنل WooCommerce کامل» نیست. فاصلهٔ اصلی در چهار محور است:

1. Dashboard بیشتر shell و تحلیل مقدماتی است؛ KPIهای استاندارد، نمودار وضعیت سفارش‌ها، tooltip و خلاصهٔ قابل‌دسترسیٔ نمودار کامل نیست.
2. Products و Orders به کارت‌های محدود تبدیل شده‌اند؛ فیلترهای واقعی، جدول دسکتاپ، ستون‌های استاندارد، sort، pagination و responsive table-to-card وجود ندارد.
3. Product editor یک فرم بلند و تک‌صفحه‌ای است؛ تب‌ها و بخش‌های استاندارد WooCommerce، dirty state، خطای فیلدی و پوشش کامل inventory، tax، shipping و related products کم است.
4. RTL در سطح سند و محتوای اصلی درست است، اما shell با `direction: ltr` و drawer چپ‌گرا پیاده شده و با یک RTL-native system یکدست نیست.

اولویت پیشنهادی: ابتدا Foundation و shell مشترک، سپس Dashboard برای تثبیت الگوهای KPI/chart/state، بعد Products برای تثبیت table/filter/card، سپس Orders برای workflow عملیاتی، و در پایان Product editor برای فرم‌ها، تب‌ها، اعتبارسنجی و unsaved changes.

## ۲. ورودی‌ها و روش بررسی

منابع بررسی‌شده:

- `docs/woocommerce_admin_ui_ux_design.md`، ۱۲۹۵ خط؛ مبنای محصول، پالت، responsive، accessibility و WooCommerce-native بودن.
- `fandoogh-manager/app/index.html`، ۶۴۳ خط؛ ساختار DOM، landmarkها، navigation، Dashboard، Products، Orders و editor.
- `fandoogh-manager/app/app.js`، ۴۵۶۹ خط؛ state machine، renderها، رفتار navigation، API state، فرم‌ها و عملیات UI.
- `fandoogh-manager/app/styles.css`، ۳۱۷۳ خط؛ tokenهای فعلی، layout، component style و media queryها.
- تصاویر موجود در `C:\Users\Dolphin\Downloads`؛ نام، ابعاد بصری و محتوای قابل‌مشاهده بررسی شد.

محدودیت‌های آگاهانه:

- هیچ فایل Figma یا node-specific URL در ورودی وجود ندارد؛ بنابراین این سند فقط برنامهٔ Figma را تعریف می‌کند.
- هیچ dependency یا asset خارجی پیشنهاد نمی‌شود.
- فونت باید محلی، از `fonts.php`/مسیر same-origin یا از طریق upload مدیریتی WordPress باشد.
- وضعیت API و permission در این سند به‌عنوان قرارداد تجربهٔ کاربری فرض شده است؛ منطق backend در دامنهٔ این کار نیست.

## ۳. بررسی تصاویر مرجع

### ۳.۱ تصاویر مرتبط با UI/UX

| فایل | برداشت طراحی | کاربرد در تصمیم طراحی |
|---|---|---|
| `09f03731-67d7-4b00-8846-55d17404378b.png` | Dashboard دسکتاپ فارسی با sidebar سبز، KPI card، نمودار خطی، donut وضعیت سفارش، quick actions، آخرین سفارش‌ها و پرفروش‌ترین محصولات | مرجع مستقیم برای hierarchy داشبورد، نسبت فضای سفید و teal/amber |
| `500e3fda-ed7b-4346-8761-645bdf9d68d1.png` | مجموعهٔ mobile از drawer، Dashboard، Products، Orders، Order detail و Product editor | مرجع اصلی برای drawer، bottom nav، card layout و sticky action |
| `ChatGPT Image ۳۱ مرداد ۱۴۰۵، ۱۹_۵۹_۵۹ (1).png` | سه screen موبایل با Dashboard، فهرست محصولات و جزئیات سفارش | مرجع برای mobile-first، status badge و bottom navigation |
| `Untitled design.png`، `Untitled design (1).png`، `Untitled design (2).png` | لوگو/نشان فندوق با زمینهٔ روشن و تیره | فقط مرجع برند؛ به‌عنوان icon system یا UI asset جدید وارد نشود |

### ۳.۲ تصاویر نامرتبط با UI پنل

`1784150008576.png` تصویر تبلیغاتی/illustration، `45644.jpg` تصویر شخصی، `Screenshot_2026-07-29_171652 (1).png` screenshot ابزار شبکه، و `درب فومیزه (1).webp`/`درب فومیزه (2).webp`/`chili-pepper.png` تصاویر محصول یا متفرقه‌اند. `global.svg` و `Untitled design.svg` بیشتر asset برند هستند. این موارد مبنای component یا dependency خارجی قرار نمی‌گیرند.

نتیجه: مرجع قابل‌اعتماد برای layout همین سه تصویر UI فارسی است. در آن‌ها teal تیره برای navigation، teal روشن برای active/success، amber برای توجه و کارت‌های سفید با border بسیار نرم تکرار می‌شود. در پیاده‌سازی باید از تزریق تصویر screenshot به محصول خودداری شود و فقط الگوی layout استخراج شود.

## ۴. ممیزی UI فعلی

### ۴.۱ نقاط قوت

- `index.html` ریشهٔ فارسی و RTL دارد و viewport موبایل، safe-area و theme-color را تنظیم کرده است.
- ساختار معنایی `header`، `aside`، `nav`، `main`، `section`، `article` و `footer` وجود دارد.
- برای inputهای اصلی label واقعی یا `sr-only` وجود دارد؛ تصویر محصول با `alt` ساخته می‌شود و loading آن lazy است.
- `app.js` برای وضعیت‌های `secure`، `loading`، `empty`، `error` و `ready` الگوی نسبتاً تکرارشونده دارد.
- خطاهای API تا حدی به متن کاربرپسند تبدیل می‌شوند و متن فنی مستقیماً در UI نمایش داده نمی‌شود.
- در Product editor، ساخت simple/variable، attribute، media، variation، category، اعتبارسنجی پایه و focus پس از بازشدن وجود دارد.
- Order detail شامل صورتحساب، ارسال، اقلام، مجموع و shipment workflow است و تغییر وضعیت سفارش بعد از ارسال با refresh سرور تأیید می‌شود.
- استفاده از `textContent` و nodeهای DOM در renderهای اصلی، ریسک تزریق HTML ناخواسته را کاهش می‌دهد.
- CSS tokenهای برند فعلی با رنگ‌های سند مرجع هم‌راستا هستند: `#0f766e`، `#115e59`، `#f59e0b`، `#1f2937` و `#e5e7eb`.
- focus-visible پایه، `prefers-reduced-motion`، drawer موبایل، scrim، bottom navigation و safe-area در CSS دیده شده‌اند.
- هیچ dependency یا font CDN خارجی در فایل‌های بررسی‌شده وجود ندارد.

### ۴.۲ شکاف‌های عمومی و معماری UI

- دو لایهٔ CSS متوالی وجود دارد: بخش اول shell عمومی را تعریف می‌کند و از خط ۲۲۵۷ به بعد shell دسکتاپ/موبایل دوباره تعریف می‌شود. این کار cascade را شکننده و تشخیص breakpoint مؤثر را سخت می‌کند.
- breakpointهای فعلی `820/620` و `1100/880` هستند، اما سند مرجع `767/768/1023/1024/1440` را پیشنهاد می‌کند. قرارداد واحد وجود ندارد.
- `fonts.css` در `index.html` و `sw.js` ارجاع داده شده، اما در `fandoogh-manager/app` فایل موجود نیست. `styles.css` فقط `FandooghLocal` و fallback تعریف می‌کند و `fonts.php` سرویس تولید CSS فونت محلی دارد. تا زمان اتصال واقعی این سرویس، typography ممکن است روی Tahoma یا Segoe UI بیفتد.
- tokenهای spacing، shadow، z-index و semantic state کامل نیستند و مقادیر hard-code مانند `#056b6b`، `#f8fafc`، `#fbfcff`، `#f2f4f8` و shadowهای متعدد پخش شده‌اند.
- جست‌وجوی global در DOM وجود دارد، اما در `bindEvents` رفتار search، نتیجهٔ گروه‌بندی‌شده، keyboard navigation و clear واقعی تعریف نشده است؛ در navigation فقط value آن پاک می‌شود.
- navigation با buttonهای `data-nav-target` انجام می‌شود و URL/history/deep link ندارد؛ refresh یا bookmark وضعیت کاربر را حفظ نمی‌کند.
- `aria-current="page"` روی همهٔ آیتم‌های دارای `data-nav-target` از جمله quick actionها اعمال می‌شود؛ این semantic برای action داخل Dashboard مناسب نیست.
- `analyticsPanel` تا فعال‌شدن config مخفی است؛ در preview یا config ناقص، Dashboard به‌جای دادهٔ واقعی بیشتر پیام secure/coming soon نشان می‌دهد.
- skeleton واقعی برای table/card/form وجود ندارد؛ loading بیشتر یک spinner و پیام است و layout هنگام دریافت داده پایدار نمی‌ماند.
- toast region مرکزی، stack اعلان‌ها، undo و الگوی confirmation dialog قابل‌مشاهده در UI فعلی وجود ندارد.

### ۴.۳ RTL و زبان بصری

نقاط درست:

- سند HTML RTL است و متن فارسی به‌صورت پیش‌فرض راست‌چین می‌شود.
- برای URL در Dashboard، `dir="ltr"` استفاده شده و برای برخی tracking/date inputها LTR در CSS تعریف شده است.
- در `formatDisplayText` و formatterها اعداد/تاریخ‌ها برای نمایش فارسی‌سازی می‌شوند.

ناسازگاری‌ها:

- `.app-shell` در خط ۲۲۶۴ `direction: ltr` دارد، `.topbar` نیز ltr است و فقط sidebar/workspace دوباره rtl می‌شوند. این workaround ممکن است order بصری، ترتیب tab و ترتیب screen reader را با هم ناهماهنگ کند.
- sidebar دسکتاپ در سمت چپ با `grid-template-columns: 260px ...` قرار گرفته و drawer موبایل با `inset: 0 auto 0 0` از چپ باز می‌شود. برای RTL-native، قرارداد پیشنهادی sidebar سمت راست و `inset-inline-end: 0` است. اگر تصمیم برند عمداً sidebar چپ باشد، باید به‌عنوان استثنای محصول ثبت و برای همهٔ صفحات ثابت بماند.
- SKU، Order ID، email، URL و JSON در تمام renderهای dynamic با wrapper عمومی و کلاس واحد LTR کنترل نمی‌شوند. نتیجه می‌تواند شکست خط، ترتیب نامناسب اعداد یا خوانایی پایین باشد.
- آیکون‌ها با کاراکترهایی مانند `⌂`، `▱`، `◇`، `♙`، `▥`، `⚙` و `♧` ساخته شده‌اند؛ این‌ها نه یک icon language پایدارند و نه برای کاربران screen reader معنای قابل‌اتکا دارند.
- بعضی نوشته‌ها ترکیبی از فارسی و انگلیسی‌اند: `variationها`، `slug`، `Audit append-only` و `JSON ساده`. این موارد باید با label فارسی و مقدار فنی LTR تفکیک شوند.

### ۴.۴ Responsive

نقاط قوت:

- در عرض کم، sidebar به drawer، topbar به sticky header و navigation به پنج آیتم bottom تبدیل می‌شود.
- کارت محصول در mobile به layout دو ستونهٔ تصویر/محتوا تبدیل می‌شود و actionهای فرم تمام‌عرض می‌شوند.
- safe-area برای موبایل و reduced motion در نظر گرفته شده است.

شکاف‌ها:

- در عرض ۶۲۱ تا ۸۸۰ پیکسل، `orders-grid` می‌تواند همچنان چهار ستون داشته باشد؛ این برای tablet و متن فارسی فشرده است.
- در mobile، global search، connection status، help و install پنهان می‌شوند و جایگزین discoverable ندارند.
- `page-heading-actions` در mobile مخفی می‌شود؛ logout و بازگشت به pairing ممکن است از مسیر عادی کاربر خارج شوند.
- bottom navigation در چند نقطه به `products` متصل است و دکمهٔ `+` نیز به‌جای «افزودن محصول»، صرفاً به بخش محصولات می‌رود.
- جدول واقعی یا الگوی scroll افقی وجود ندارد؛ بنابراین رفتار responsive برای دادهٔ ستونی استاندارد WooCommerce هنوز طراحی نشده است.
- حداقل عرض ۳۲۰ پیکسل تعریف شده، اما کنترل‌های متعدد، فیلترها و editor در این عرض نیازمند تست فشرده و جلوگیری از overflow هستند.

### ۴.۵ Accessibility، focus و contrast

نقاط قوت:

- label، `aria-label`، `aria-controls`، `aria-expanded`، `aria-current`، `role="status"` و `role="alert"` در نقاط مهم وجود دارند.
- touch target پایهٔ دکمه‌های اصلی ۴۸ پیکسل است و focus-visible برای button/input/link تعریف شده است.
- وضعیت‌ها فقط با رنگ نشان داده نمی‌شوند و در بیشتر موارد متن فارسی دارند.

ریسک‌ها و اصلاحات لازم:

- focus-visible برای `select` و `textarea` به‌صراحت در rule عمومی نیست؛ focus باید برای تمام کنترل‌ها یکسان باشد.
- drawer نقش `dialog`، `aria-modal`، focus trap، بستن با Escape و بازگرداندن focus به trigger ندارد.
- errorهای فیلدی `aria-invalid` و `aria-describedby` ندارند و پیام خطا کنار کنترل مربوط به خود قرار نمی‌گیرد.
- `text-link-button`، topbar icon button و mobile menu حدود ۳۸ پیکسل‌اند و به حداقل ۴۴×۴۴ پیکسل نمی‌رسند.
- رنگ amber `#f59e0b` برای متن روی سفید contrast کافی ندارد؛ در CSS برای badge/status text استفاده شده است. برای متن باید amber تیره مثل `#b45309` یا زمینهٔ تیره‌تر استفاده شود.
- `#6b7280` روی سفید در مرز contrast متن کوچک است؛ متن‌های ۱۲ پیکسل نباید با این رنگ اصلی ارائه شوند.
- `#5eead4` به‌عنوان focus outline روی زمینهٔ روشن کنتراست کافی و پایدار ندارد؛ focus باید با رنگ تیره و حلقهٔ دو لایهٔ قابل‌تشخیص باشد.
- وضعیت اتصال با `status-orb` که `aria-hidden` است نمایش داده می‌شود؛ label باید وضعیت نهایی را صریح و نزدیک کنترل اعلام کند.
- live region روی gridهای بزرگ می‌تواند پس از هر refresh محتوای زیادی را برای screen reader بخواند؛ یک summary کوتاه و تعداد نتیجه بهتر است.
- آیکون‌های بدون متن tooltip ندارند و برخی glyphها مانند `♧` برای اعلان معنای واضحی ندارند.

### ۴.۶ Typography

- سند مرجع Vazirmatn را پیشنهاد می‌کند، اما محصول نباید به CDN وابسته شود. قرارداد صحیح: `FandooghLocal` یا فونت admin-uploaded با fallback محلی.
- در CSS وزن‌های ۷۰۰ و ۸۰۰ زیاد استفاده شده، اما CSS تولیدشده در `fonts.php` وزن را normal اعلام می‌کند؛ مرورگر ممکن است synthetic bold بسازد.
- اندازه‌های ۰٫۵۶ تا ۰٫۸۸rem در badge، metadata و متن کارت‌ها زیاد است و برای فارسی خوانایی را کاهش می‌دهد. متن اصلی نباید کمتر از ۱۴px و متن کمکی نباید کمتر از ۱۲px باشد.
- `letter-spacing` منفی شدید مانند `-0.07em` برای فارسی مناسب نیست و می‌تواند اتصال حروف و خوانایی heading را بدتر کند.
- اعداد، مبلغ، ID و SKU به font variant tabular و wrapper LTR نیاز دارند تا در جدول‌ها هم‌تراز بمانند.

### ۴.۷ رنگ، spacing، radius و elevation

- رنگ برند و سطح سفید با تصویر مرجع هم‌راستا است و teal برای primary انتخاب مناسبی است.
- semantic stateها هنوز با primitiveها قاطی‌اند: connected به accent نگاشت شده، درحالی‌که connected/success باید سبز semantic باشد؛ بعضی backgroundها نیز `rgba(16,185,129)` هستند، در حالی که accent اصلی amber است.
- gradient readiness bar با اصل «بدون gradient سنگین» تضاد شدید ندارد، اما برای progress و state بهتر است solid/semantic باشد.
- spacing عمدتاً remهای نزدیک به ۴ و ۸ پیکسل دارد، اما token واحد ندارد؛ مقادیر ۰٫۱۵، ۰٫۲۵، ۰٫۳۵، ۰٫۴۵، ۰٫۶۵ و ۰٫۸ پراکنده‌اند.
- radius کارت ۱۶، input/button حدود ۱۰ و badge کامل با brief سازگار است؛ برای componentهای کوچک باید scale رسمی ۶/۸/۱۰/۱۴/۱۶/۲۰ تعیین شود.
- z-index عددی و پراکنده است: popoverها ۳/۴، bottom nav هشت، header ده، sidebar دوازده/۳۰ و scrim بیست. این باید tokenized شود تا modal/drawer همیشه ترتیب قطعی داشته باشد.

## ۵. ممیزی صفحه‌ها

### ۵.۱ Navigation و shell

وضعیت فعلی در `index.html` شامل Dashboard، Orders، Products، Customers، Analytics، دسته‌بندی و Security است. سند مرجع اما گروه‌های فروش، مشتریان، بازاریابی، فروشگاه و سیستم را با محصولات، دسته‌ها، برچسب‌ها، ویژگی‌ها، کوپن، حمل‌ونقل، مالیات، پرداخت، تنظیمات، رسانه و کاربران تعریف می‌کند.

مواردی که باید اصلاح شوند:

- گروه‌بندی navigation را به‌صورت واقعی و collapsible تعریف کنید؛ آیتم‌هایی که endpoint یا permission ندارند disabled با توضیح باشند، نه اینکه silent حذف شوند.
- دکمهٔ دسته‌بندی فعلی `data-nav-target="products"` دارد؛ این باعث می‌شود active state آن با Products اشتباه شود. target مستقل `categories` لازم است.
- صفحهٔ فعال باید با heading و breadcrumb/URL قابل‌تشخیص باشد و navigation با history API یا hash قابل بازگشت شود.
- quick action «سفارش جدید» و «مشتری جدید» فقط در صورتی نمایش داده شوند که workflow واقعی WooCommerce برای آن موجود باشد؛ در غیر این صورت به لیست با CTA درست هدایت شوند.
- در mobile، bottom nav فقط Dashboard، Orders، Products، Customers و More باشد؛ `+` باید action context-aware داشته باشد یا حذف شود، نه اینکه label و رفتار متفاوت داشته باشد.

### ۵.۲ Dashboard

موجود:

- page heading، وضعیت اتصال، quick actions، SVG line chart، recent orders و top products وجود دارد.
- `loadAnalytics`، `renderAnalytics` و `renderDashboardSummary` برای دادهٔ API طراحی شده‌اند.
- حالت secure/loading/error برای analytics پیش‌بینی شده است.

شکاف نسبت به brief و تصویر مرجع:

- KPI grid چهارگانهٔ Revenue، Orders، Products، Customers با مقدار، واحد، period comparison و link به صفحهٔ مربوطه وجود ندارد.
- `readiness-meter` مقدار پیش‌فرض ۷۲٪ دارد؛ هر مقدار شبه‌واقعی در preview باید با برچسب Demo یا حذف شود تا با دادهٔ WooCommerce اشتباه نشود.
- line chart داده‌نمایی محدود دارد؛ tooltip، hover/focus point، محور خوانا، empty chart و summary متنی کامل نیستند.
- Order Status chart/donut وجود ندارد؛ فقط analytics status list ساخته می‌شود.
- date range فقط selectهای محدود دارد و custom range در brief پیاده نشده است.
- در mobile اولویت Dashboard باید KPIهای دو ستونه، chart full-width و recent orders کوتاه باشد؛ وضعیت‌های اتصال نباید بخش اصلی viewport را اشغال کنند.

معیار طراحی Dashboard: کاربر در ۵ ثانیه باید فروش، تعداد سفارش، مشتری و موجودی/محصول را بفهمد؛ هر KPI باید منبع داده، بازه، unit و link داشته باشد و در خطا stale data را با زمان آخرین موفقیت نشان دهد.

### ۵.۳ Products

موجود:

- search محصول، refresh، ساخت محصول، کارت محصول، category card و editor وجود دارد.
- product card تصویر، نام، قیمت، stock و edit را نشان می‌دهد.
- create/edit برای simple و variable، category multi-select، media upload و variation manager پشتیبانی می‌شود.

شکاف‌ها:

- brief برای دسکتاپ table با Image، Product، SKU، Price، Stock، Status، Category، Type و Date است؛ UI فعلی فقط card سه/دو/یک ستونه دارد.
- فیلتر Category، Stock، Type، Status، sort و pagination وجود ندارد؛ search فقط روی نام محصول اعمال می‌شود.
- SKU، sale price، status، type، تاریخ ایجاد/ویرایش و دسته در list دیده نمی‌شوند.
- دادهٔ بزرگ بدون pagination واقعی و بدون server-side filtering تجربهٔ کند ایجاد می‌کند.
- empty search، empty catalog و permission state باید از هم جدا باشند و CTA مرتبط داشته باشند.
- actionهای mobile باید در More menu قرار بگیرند و کارت با کلید/Enter قابل بازکردن باشد؛ اکنون card خود interactive نیست و فقط buttonهای پایین دارد.

معیار طراحی Products: در دسکتاپ جدول sortable با فیلترهای قابل‌پاک‌کردن؛ در mobile card با همان اطلاعات اولویت‌دار و menu سه‌نقطه؛ هر دو از یک data model و state contract استفاده کنند.

### ۵.۴ Orders

موجود:

- search بر اساس ID/نام مشتری/status، grid کارت سفارش، select وضعیت، تغییر وضعیت، detail و shipment workflow وجود دارد.
- تغییر وضعیت با disable button و تأیید مجدد از server انجام می‌شود.
- detail شامل billing، shipping، line items، totals و shipment است.

شکاف‌ها:

- فیلتر status/date/customer/amount/payment/shipping در brief وجود دارد ولی UI فعلی فقط search دارد.
- دسکتاپ به‌جای جدول با ستون‌های Order ID، Customer، Items، Total، Status، Date و Actions، grid چهارستونهٔ card است.
- pagination، count result، page size و حفظ فیلترها وجود ندارد.
- statusها به keyهای API وابسته‌اند و stateهای visual class اختصاصی برای pending/processing/on-hold/completed/cancelled/failed/refunded ندارند.
- تغییر وضعیت عملیات حساس است ولی confirmation یا undo مشخص ندارد؛ حداقل برای cancelled/refunded باید confirm یا undo ارائه شود.
- در tablet، چهار ستون سفارش خوانایی ضعیفی دارد؛ در mobile باید card با ترتیب ID، مشتری، مبلغ، وضعیت و تاریخ باشد.

معیار طراحی Orders: کاربر بتواند با یک فیلتر و یک search سفارش هدف را پیدا کند، وضعیت را با label فارسی و key داخلی ببیند، قبل/بعد از mutation وضعیت pending/success/error را بفهمد و با back به همان scroll/filter برگردد.

### ۵.۵ Product editor

موجود:

- عنوان ساخت/ویرایش، نوع محصول، نام، SKU، regular/sale price، status، stock، category، short description، attributes، media و variable/variation وجود دارد.
- هنگام بازشدن editor، focus روی نام محصول قرار می‌گیرد.
- برای variable حداقل یک attribute و فعال‌بودن variation اعتبارسنجی می‌شود.

شکاف‌ها:

- فرم تک‌بلند به‌جای تب/sectionهای «اطلاعات اصلی»، «موجودی»، «حمل‌ونقل»، «محصولات مرتبط»، «ویژگی‌ها» و «Variations» است.
- full description، tax status/class، backorders، sold individually، وزن، طول/عرض/ارتفاع، shipping class، upsell و cross-sell وجود ندارد.
- JSON ساده برای variation attributes برای کاربر غیرتوسعه‌دهنده قابل‌اعتماد نیست؛ باید controlهای attribute/value با summary خوانا داشته باشد.
- تغییرات unsaved تشخیص داده نمی‌شود؛ close/cancel بدون confirmation است و reload ممکن است دادهٔ فرم را از بین ببرد.
- خطای فیلدی با border، `aria-invalid`، `aria-describedby` و focus خودکار به اولین خطا نشان داده نمی‌شود.
- بعد از ذخیره، editor بسته و list refresh می‌شود؛ toast موفقیت یا لینک به محصول ذخیره‌شده وجود ندارد.
- action bar sticky نیست؛ در فرم بلند، Save/Cancel از viewport خارج می‌شود.
- media manager توضیح خوبی دارد، اما drag/reorder، progress هر فایل، خطای فایل و alt text قابل‌ویرایش نیازمند قرارداد مشخص است.

معیار طراحی Product editor: کاربر بتواند بدون اسکرول طولانی بین tabها حرکت کند، تغییرات را ببیند، خطای هر فیلد را اصلاح کند، هنگام خروج هشدار بگیرد، و نتیجهٔ ذخیره را در همان context دریافت کند.

## ۶. Design System پیشنهادی

### ۶.۱ اصول محصول

1. WooCommerce-native: UI فقط نمای بهتری از داده و capability واقعی WordPress/WooCommerce است.
2. RTL-native: جهت layout، drawer، spacing و keyboard order با RTL طراحی می‌شود؛ فقط مقدارهای فنی LTR هستند.
3. Data-first: state، freshness، loading، empty، permission و error بخشی از طراحی‌اند، نه edge case.
4. Progressive disclosure: هر صفحه یک هدف اصلی دارد و actionهای فرعی در menu/section ثانویه می‌روند.
5. Local-only: بدون CDN، icon library خارجی یا font خارجی؛ asset و font از همان origin یا upload ادمین.
6. Consistency over novelty: یک component با variantهای مشخص در همهٔ صفحه‌ها تکرار می‌شود.

### ۶.۲ Primitive color tokens

این‌ها رنگ خام‌اند؛ component نباید مستقیماً از primitive استفاده کند مگر در تعریف semantic token.

```css
:root {
  --teal-50: #f0fdfa;
  --teal-100: #ccfbf1;
  --teal-200: #99f6e4;
  --teal-300: #5eead4;
  --teal-400: #2dd4bf;
  --teal-500: #14b8a6;
  --teal-600: #0d9488;
  --teal-700: #0f766e;
  --teal-800: #115e59;
  --teal-900: #134e4a;

  --amber-50: #fffbeb;
  --amber-100: #fef3c7;
  --amber-200: #fde68a;
  --amber-500: #f59e0b;
  --amber-700: #b45309;
  --amber-800: #92400e;

  --red-50: #fef2f2;
  --red-100: #fee2e2;
  --red-600: #dc2626;
  --red-700: #b91c1c;

  --blue-50: #eff6ff;
  --blue-100: #dbeafe;
  --blue-700: #1d4ed8;

  --green-50: #f0fdf4;
  --green-100: #dcfce7;
  --green-700: #15803d;

  --slate-0: #ffffff;
  --slate-50: #f8fafc;
  --slate-100: #f1f5f9;
  --slate-200: #e2e8f0;
  --slate-300: #cbd5e1;
  --slate-500: #64748b;
  --slate-600: #475569;
  --slate-700: #334155;
  --slate-800: #1e293b;
  --slate-900: #0f172a;
}
```

### ۶.۳ Semantic color tokens

```css
:root {
  --color-bg-canvas: var(--slate-50);
  --color-bg-surface: var(--slate-0);
  --color-bg-subtle: var(--slate-100);
  --color-bg-brand: var(--teal-800);
  --color-bg-brand-soft: var(--teal-50);

  --color-text-primary: var(--slate-900);
  --color-text-secondary: var(--slate-600);
  --color-text-muted: var(--slate-500);
  --color-text-inverse: var(--slate-0);
  --color-text-brand: var(--teal-800);

  --color-border-subtle: var(--slate-200);
  --color-border-default: var(--slate-300);
  --color-border-strong: var(--slate-500);
  --color-border-focus: var(--teal-700);

  --color-action-primary: var(--teal-700);
  --color-action-primary-hover: var(--teal-800);
  --color-action-primary-soft: var(--teal-50);
  --color-action-secondary: var(--slate-0);

  --color-success-text: var(--green-700);
  --color-success-bg: var(--green-50);
  --color-success-border: #86efac;
  --color-warning-text: var(--amber-700);
  --color-warning-bg: var(--amber-50);
  --color-warning-border: var(--amber-200);
  --color-danger-text: var(--red-700);
  --color-danger-bg: var(--red-50);
  --color-danger-border: #fecaca;
  --color-info-text: var(--blue-700);
  --color-info-bg: var(--blue-50);
  --color-info-border: var(--blue-100);

  --color-focus-ring: var(--teal-700);
  --color-overlay: rgb(15 23 42 / 0.48);
}
```

قاعده: رنگ status همیشه با label، icon یا متن همراه است. amber روشن فقط برای زمینه/نشان استفاده شود؛ متن amber از `--color-warning-text` باشد. Success سبز، Warning کهربایی، Danger قرمز و Info آبی semantic مستقل هستند.

### ۶.۴ Typography محلی

```css
:root {
  --font-family-ui: "FandooghLocal", "Vazirmatn", Tahoma, "Segoe UI", Arial, sans-serif;
  --font-size-xs: 0.75rem;   /* 12px */
  --font-size-sm: 0.8125rem; /* 13px */
  --font-size-md: 0.875rem;  /* 14px */
  --font-size-lg: 1rem;      /* 16px */
  --font-size-xl: 1.125rem;  /* 18px */
  --font-size-2xl: 1.5rem;   /* 24px */
  --font-size-3xl: 1.875rem; /* 30px */
  --font-weight-regular: 400;
  --font-weight-medium: 500;
  --font-weight-semibold: 600;
  --font-weight-bold: 700;
  --line-height-tight: 1.35;
  --line-height-body: 1.75;
  --line-height-label: 1.5;
}
```

قواعد اجرایی:

- `font-family` فقط از فونت محلی same-origin یا فونت upload‌شدهٔ ادمین استفاده کند؛ هیچ `@import` و CDN اضافه نشود.
- فایل تولیدی فونت باید برای وزن‌های واقعی ۴۰۰/۵۰۰/۶۰۰/۷۰۰ mapping داشته باشد؛ اگر یک فایل فونت است فقط weight ۴۰۰ اعلام شود و bold synthetic محدود بماند.
- متن body حداقل ۱۴px، caption حداقل ۱۲px، control label حداقل ۱۳px و heading صفحه ۲۴px دسکتاپ/۲۰px موبایل باشد.
- `letter-spacing` برای فارسی پیش‌فرض صفر باشد.
- مبلغ، شماره سفارش، SKU، email، URL، JSON و کد با `.u-ltr` و `font-variant-numeric: tabular-nums` نمایش داده شوند.

### ۶.۵ Spacing، radius، border، shadow و z-index

```css
:root {
  --space-0: 0;
  --space-1: 0.25rem; /* 4 */
  --space-2: 0.5rem;  /* 8 */
  --space-3: 0.75rem; /* 12 */
  --space-4: 1rem;    /* 16 */
  --space-5: 1.25rem; /* 20 */
  --space-6: 1.5rem;  /* 24 */
  --space-8: 2rem;    /* 32 */
  --space-10: 2.5rem; /* 40 */
  --space-12: 3rem;   /* 48 */
  --space-16: 4rem;   /* 64 */

  --radius-sm: 0.375rem; /* 6 */
  --radius-md: 0.625rem; /* 10 */
  --radius-lg: 0.875rem; /* 14 */
  --radius-xl: 1rem;     /* 16 */
  --radius-2xl: 1.25rem; /* 20 */
  --radius-pill: 999px;

  --border-width: 1px;
  --shadow-1: 0 1px 2px rgb(15 23 42 / 0.05);
  --shadow-2: 0 4px 16px rgb(15 118 110 / 0.08);
  --shadow-3: 0 12px 32px rgb(15 23 42 / 0.14);

  --z-base: 0;
  --z-sticky: 10;
  --z-header: 20;
  --z-scrim: 30;
  --z-drawer: 40;
  --z-popover: 50;
  --z-modal: 60;
  --z-toast: 70;
}
```

قانون spacing: page gutter دسکتاپ ۳۲px، tablet ۲۴px، mobile ۱۶px؛ فاصلهٔ بین sectionها ۲۴px، بین fieldها ۱۶px، بین label و control هشت پیکسل؛ padding کارت ۲۰/۲۴px و mobile ۱۶px.

### ۶.۶ Grid و breakpoint

قرارداد واحد پیشنهادی:

| نام | بازه | رفتار |
|---|---:|---|
| base | ۰ تا ۴۷۹ | یک ستون، bottom nav، drawer، card list |
| sm | ۴۸۰ تا ۷۶۷ | یک/دو ستون بسته به component، card list |
| md | ۷۶۸ تا ۱۰۲۳ | sidebar collapse یا compact، دو ستون، جدول با scroll در صورت نیاز |
| lg | ۱۰۲۴ تا ۱۲۷۹ | sidebar ثابت، جدول کامل، grid چهار/دوازده‌ستونه |
| xl | ۱۲۸۰ تا ۱۴۳۹ | content max-width بزرگ، chart و table راحت‌تر |
| 2xl | ۱۴۴۰ به بالا | max-width حدود ۱۴۴۰ و فضای تنفس بیشتر |

Layout دسکتاپ پیشنهادی در RTL: sidebar سمت راست با عرض ۲۶۴px، header در ناحیهٔ content با ارتفاع ۷۲px، main با max-width ۱۴۴۰px. در mobile sidebar به drawer سمت راست تبدیل شود و bottom nav ارتفاع حداقل ۶۴px به‌اضافهٔ safe-area داشته باشد.

## ۷. Component inventory و قرارداد رفتار

| component | variant | state | رفتار لازم |
|---|---|---|---|
| App shell | desktop/tablet/mobile | authenticated/pairing/offline | حفظ focus، عنوان صفحه، drawer و مسیر فعال |
| Sidebar nav | expanded/collapsed/drawer | default/hover/active/disabled | group heading، active واقعی، Escape و focus trap در drawer |
| Topbar | full/compact | online/offline/loading | global search، notifications، profile، actionهای قابل‌دسترسی |
| Button | primary/secondary/ghost/danger | default/hover/focus/pressed/disabled/loading | label فعل‌محور، min-height 44، spinner بدون تغییر layout |
| IconButton | standard/quiet/danger | default/hover/focus/disabled | `aria-label`، tooltip، ۴۴×۴۴، icon محلی/یکدست |
| Input | text/search/number/date | default/focus/error/disabled/read-only | label، hint، error، clear، LTR برای مقدار فنی |
| Select | native/combobox | default/open/error/disabled | label و keyboard استاندارد، گزینهٔ clear در filter |
| Filter bar | desktop row/mobile drawer | clean/active/loading | تعداد filter فعال، پاک‌کردن همه، اعمال/لغو در mobile |
| Badge | neutral/success/warning/danger/info | visible/disabled | متن فارسی + در صورت نیاز icon؛ رنگ تنها نشانه نباشد |
| Card | surface/outlined/interactive | default/hover/focus/selected | border و shadow محدود؛ action اصلی واضح |
| Stat card | revenue/orders/products/customers | loaded/loading/empty/error | value، unit، period، delta، source و link |
| Chart card | line/donut/bar | loaded/loading/empty/error | legend، tooltip keyboard، summary متنی و data table fallback |
| Data table | dense/comfortable | loading/empty/error/selected | header sortable، pagination، sticky header، mobile card transform |
| Product card | list/grid/mobile | default/selected/disabled | تصویر، نام، SKU، قیمت، stock، status، menu |
| Order card | mobile/detail | default/updating/error | ID، customer، total، status، date، action و نتیجهٔ mutation |
| Tabs | horizontal/scrollable | active/disabled/loading | `tablist/tab/tabpanel`، حفظ tab در URL یا state |
| Form section | card/accordion | default/error/saved/dirty | summary خطا، sticky action bar، dirty confirmation |
| Drawer | filter/navigation | closed/open/loading | focus trap، scrim، Escape، restore focus |
| Dialog | confirm/destructive | closed/open/submitting | عنوان، شرح، cancel، action danger، focus trap |
| Toast | success/info/warning/error | entering/visible/dismissed | live region، timeout مناسب، دکمهٔ بستن |
| Skeleton | text/card/table/form | visible/hidden | ابعاد هم‌اندازهٔ محتوای واقعی، بدون پرش layout |
| Empty/Error state | no-data/no-result/permission/network | visible/retry | پیام غیر فنی، CTA مرتبط، retry و حفظ query |
| Pagination | compact/full | first/middle/last/loading | تعداد، page size ۱۰/۲۰/۵۰/۱۰۰، keyboard و URL state |

رفتار مشترک تمام componentها:

- state باید در DOM قابل‌فهم باشد و صرفاً با class رنگی منتقل نشود.
- actionهای خطرناک confirmation یا undo داشته باشند.
- loading روی همان component باقی بماند و محتوای قبلی ناگهان حذف نشود.
- focus بعد از mutation به heading یا summary موفقیت برگردد، نه به ابتدای صفحه.

## ۸. الگوهای Dashboard، chart، table، form و error

### ۸.۱ Dashboard

ترتیب دسکتاپ:

1. Page header: عنوان، store context، date range و یک action اصلی.
2. KPI grid: Revenue، Orders، Customers، Products؛ هر card دارای value، unit، delta، period و link.
3. Main analytics: Sales line chart با Order count و Revenue؛ Order status donut/bar در کنار آن.
4. Secondary: Recent orders و Top products.

ترتیب mobile: heading و date، KPI دو ستونه، Sales chart تمام‌عرض، status chart، recent orders و top products. secure/error باید در همان slot component نمایش داده شوند.

### ۸.۲ نمودار

- پیاده‌سازی با SVG محلی مجاز است؛ dependency chart خارجی اضافه نشود.
- line chart حداقل دو series با legend متنی و تفاوت line style داشته باشد؛ رنگ به‌تنهایی کافی نیست.
- tooltip با pointer و keyboard focus روی data point کار کند و متن شامل تاریخ، مبلغ، سفارش و unit باشد.
- برای screen reader یک summary متنی مثل «در این بازه درآمد ۱۲٪ افزایش یافته است» و جدول دادهٔ قابل‌دسترسی یا دکمهٔ «نمایش داده‌ها» فراهم شود.
- donut برای ۷ status اصلی با label، count و percentage کنار chart و در جدول fallback نمایش داده شود.
- no-data با محور خالی و پیام کوتاه نشان داده شود؛ loading skeleton اندازهٔ chart را حفظ کند.

### ۸.۳ جدول و mobile card

- دسکتاپ: header sticky، row hover، sort با `aria-sort`، checkbox فقط اگر bulk action واقعی وجود دارد، و action menu در ستون آخر.
- اعداد و مبلغ‌ها tabular و LTR؛ نام‌ها و status فارسی و RTL.
- ستون‌های اصلی همیشه دیده شوند؛ ستون‌های کم‌اولویت در overflow/menu یا responsive hide قرار گیرند.
- mobile: هر row به card semantic تبدیل شود، label/value جفتی داشته باشد و ترتیب اطلاعات مطابق brief حفظ شود؛ از جدول افقی فقط برای داده‌ای استفاده شود که card شدن آن باعث از دست رفتن context می‌شود.
- pagination باید server-side باشد، تعداد کل و range مثل «نمایش ۱ تا ۲۰ از ۳۲۴» را نشان دهد و بعد از refresh فیلتر/صفحهٔ فعلی را حفظ کند.

### ۸.۴ فرم و Product editor

Tabهای پیشنهادی:

1. اطلاعات اصلی: نام، slug، توضیحات کامل، توضیح کوتاه، SKU، قیمت‌ها، tax.
2. موجودی: manage stock، quantity، stock status، backorders، sold individually.
3. حمل‌ونقل: weight، dimensions، shipping class.
4. محصولات مرتبط: upsell و cross-sell با search/combobox.
5. ویژگی‌ها: global/local attribute، options، visible، used for variations.
6. Variations: filter، list، bulk actions، detail editor.
7. رسانه: featured، gallery، ترتیب، alt، upload progress.

در موبایل tabها horizontal scroll قابل‌دسترسی یا accordion شوند. action bar پایین editor sticky باشد و با safe-area فاصله داشته باشد. هنگام dirty شدن فرم، نشان «ذخیره‌نشده» در heading، cancel confirmation و before-unload هشدار ارائه شود. پس از save، toast موفقیت با لینک «مشاهده محصول» و focus روی آن یا heading editor قرار گیرد.

### ۸.۵ Error، empty، loading و permission

قالب استاندارد:

- Error: «دریافت محصولات انجام نشد.» + توضیح کوتاه + «تلاش دوباره»؛ متن exception/API نمایش داده نشود.
- Empty: «محصولی پیدا نشد.»؛ اگر query فعال است «فیلترها را پاک کنید»، اگر catalog خالی است CTA «محصول جدید».
- Permission: «مجوز مشاهدهٔ این بخش را ندارید.»؛ دکمه‌ای که کاربر مجوز آن را ندارد render نشود.
- Loading: skeleton هم‌اندازهٔ table/card؛ button به «در حال ذخیره…» تغییر کند و disabled شود.
- Offline: banner non-blocking، آخرین زمان sync و action retry؛ دادهٔ stale با label واضح نمایش داده شود.

## ۹. قرارداد Accessibility و keyboard

- هدف WCAG AA: متن معمول حداقل ۴٫۵:۱، متن بزرگ حداقل ۳:۱، focus indicator حداقل ۳:۱ نسبت به سطح مجاور.
- تمام interactiveها حداقل ۴۴×۴۴px؛ فاصلهٔ touch targetهای مجاور حداقل ۸px.
- ترتیب tab مطابق ترتیب بصری RTL باشد؛ از `direction:ltr` در shell برای حل layout استفاده نشود.
- `Tab` برای حرکت، `Shift+Tab` برای بازگشت، `Enter/Space` برای action، `Escape` برای drawer/dialog/popover، `Arrow` برای tabs/menu و `Home/End` برای list/pagination تعریف شود.
- Drawer و dialog باید focus trap، `role="dialog"`، `aria-modal="true"`، عنوان و restore focus داشته باشند.
- هر input دارای label، hint اختیاری و error متصل با `aria-describedby` باشد؛ در خطا `aria-invalid="true"` ست شود.
- status badge با متن و در صورت نیاز icon نمایش داده شود؛ رنگ status به‌تنهایی کافی نیست.
- تصویر decorative `alt=""`، تصویر محصول alt meaningful و avatar decorative با label مجزا باشد.
- `:focus-visible` برای `button,a,input,select,textarea,[tabindex]` با outline tokenized اعمال شود.
- `prefers-reduced-motion` باید transition، smooth scroll و animation را کاهش دهد.
- screen reader هنگام تغییر صفحه، heading فعال یا page title را دریافت کند؛ navigation buttonهای داخلی page نباید `aria-current=page` بگیرند.

## ۱۰. نگاشت دقیق به CSS فعلی

این بخش فقط برنامهٔ refactor است؛ `styles.css` در این کار تغییر نمی‌کند.

### ۱۰.۱ selectorهای قابل حفظ

| selector فعلی | تصمیم | توضیح |
|---|---|---|
| `:root` | حفظ با refactor token | نام‌های فعلی برای migration نگه داشته شوند و به semantic token map شوند |
| `*`, `[hidden]`, `.sr-only` | حفظ | reset پایه و hidden/accessibility مفید است |
| `.card-surface` | حفظ | به `--color-bg-surface`, `--radius-xl`, `--shadow-2` متصل شود |
| `.primary-button`, `.secondary-button`, `.compact-button` | حفظ با variant | min-height compact به ۴۴ برسد؛ loading/focus/danger اضافه شود |
| `.field-label`, `.field-hint` | حفظ | error و required marker به آن اضافه شود |
| `.products-state`, `.products-error-state`, `.products-spinner` | حفظ با state contract | skeleton و retry استاندارد به آن اضافه شود |
| `.products-grid`, `.product-card`, `.product-card-image` | حفظ برای mobile card | دسکتاپ باید table sibling داشته باشد |
| `.product-editor`, `.product-editor-heading`, `.product-editor-actions` | حفظ | به section/tab و sticky action bar تبدیل شود |
| `.order-card`, `.order-card-status`, `.order-card-actions` | حفظ برای mobile | desktop table و status variant اضافه شود |
| `.mobile-bottom-nav`, `.mobile-nav-item` | حفظ | target و touch target اصلاح شود |
| `.sales-chart`, `.chart-legend`, `.sales-chart-empty` | حفظ | tooltip/focus/data table و semantic status اضافه شود |

### ۱۰.۲ selectorهای نیازمند refactor

| selector | مسئله | refactor اجرایی |
|---|---|---|
| `.app-shell` | `direction:ltr` و sidebar چپ‌گرا | RTL grid واقعی؛ sidebar در inline-end یا exception مستند |
| `.topbar` و `.app-workspace` | جهت و cascade دوگانه | یک source of truth برای direction، order و grid-area |
| `.app-sidebar`, `.sidebar-scrim` | drawer از چپ، z-index hard-code | `inset-inline-end`, `--z-drawer`, `role=dialog` contract |
| `.topbar-search-wrap` و `.topbar-search` | global search بدون behavior و اندازهٔ کوچک | component search با clear, loading, result group و min-height 44 |
| `.topbar-icon-button`, `.mobile-menu-button` | ۳۸px و icon glyph | ۴۴/۴۸px، icon محلی یکدست، tooltip و focus |
| `.dashboard-grid`, `.dashboard-overview-grid` | gridهای پراکنده و cascade duplicate | tokenized 12-column grid و slotهای KPI/chart/list |
| `.state-badge`, `.products-state-badge` | amber/green semantics مخلوط | `[data-state]` با success/warning/danger/info token |
| `.products-toolbar` | در mobile همهٔ کنترل‌ها عمودی و بدون filter drawer | desktop filter row، mobile filter button/drawer |
| `.products-grid` | desktop table ندارد | `product-table` برای lg+، card برای base/md |
| `.orders-grid` | چهارستونه در tablet و بدون table | order table در lg، card در base/md، breakpoint واحد |
| `.product-editor-grid` و `.variation-editor-grid` | فرم بلند و fieldهای کوچک | tab section، grid 2/3 ستونه، error row و sticky actions |
| `.product-media-grid` | no progress/alt/reorder | media item state، drag/reorder keyboard، upload progress |
| `.order-detail-panel`, `.customer-detail-panel` | panel inline بدون dialog semantics | page subroute یا drawer detail با breadcrumb و restore focus |
| `.app-footer` | footer روی mobile مخفی و اطلاعات مسیر کم | اطلاعات runtime در about/help؛ حذف footer layout-dependent |
| media queryهای `820/620/1100/880` | قرارداد چندگانه | فقط base/sm/md/lg/xl/2xl باقی بماند |

### ۱۰.۳ selectorهای پیشنهادی برای اضافه‌شدن

```text
.app-shell__sidebar
.app-shell__workspace
.page-shell
.page-header
.breadcrumb
.filter-bar
.filter-bar__active-count
.filter-drawer
.data-table
.data-table__sort
.data-table__mobile-card
.pagination
.stat-card
.stat-card__delta
.chart-card
.chart-card__data-table
.field
.field__control
.field__hint
.field__error
.field.is-invalid
.tabs
.tablist
.tabpanel
.editor-action-bar
.editor-dirty-indicator
.skeleton
.empty-state
.error-state
.permission-state
.toast-region
.toast
.dialog-backdrop
.dialog
.u-ltr
.u-tabular-nums
```

برای migration، ابتدا aliasهای token فعلی مثل `--brand-primary` به semantic جدید map شوند؛ سپس selectorها به‌تدریج از رنگ raw و remهای پراکنده جدا شوند. تا پایان migration از ترکیب rule قدیمی و overrideهای انتهای فایل خودداری شود؛ shell باید یک‌بار و در یک بخش مشخص تعریف شود.

## ۱۱. اولویت‌بندی اجرای فازها و معیار پذیرش

### فاز ۰ — Foundation و قرارداد مشترک

کارها:

- نهایی‌کردن tokenها، direction، typography محلی، icon contract و breakpoint.
- حذف cascade دوگانه در برنامهٔ پیاده‌سازی آینده و تعریف shell واحد.
- ساخت state contract برای loading/empty/error/permission/offline.
- تعریف table/card responsive، pagination، toast، dialog و drawer.
- رفع اتصال `fonts.css` به سازوکار واقعی فونت same-origin یا admin-uploaded؛ بدون font خارجی.

معیار پذیرش:

- تمام componentهای پایه در ۳ viewport mobile/tablet/desktop بدون overflow نمایش داده شوند.
- keyboard و screen reader برای drawer/dialog/form fieldها قابل استفاده باشد.
- رنگ‌های status و focus در تست contrast به AA برسند.

### فاز ۱ — Dashboard، بالاترین اولویت برای تثبیت زبان محصول

کارها:

- page header و date range استاندارد.
- KPI چهارگانه با source، unit، delta، period و link.
- Sales chart با tooltip، focus point، summary و data table fallback.
- Order status chart با ۷ status واقعی WooCommerce.
- recent orders و top products با table/card مشترک.
- loading skeleton، empty، API error، stale data و permission state.

معیار پذیرش:

- همهٔ عددها از payload واقعی یا state صریح Demo می‌آیند؛ مقدار hard-code بدون label حذف شود.
- در ۳۲۰، ۷۶۸، ۱۰۲۴ و ۱۴۴۰ پیکسل layout بدون scroll ناخواسته کار کند.
- کاربر با keyboard تمام KPI، range، chart summary و لینک‌های لیست را طی کند.
- در خطای analytics، retry همان slot را به‌روزرسانی کند و layout نپرد.

### فاز ۲ — Products

کارها:

- دسکتاپ `data-table` با ستون‌های استاندارد و mobile `product-card`.
- search با debounce، فیلتر category/stock/type/status، sort و pagination server-side.
- نمایش SKU، price/sale price، stock/status، category/type و تاریخ.
- More menu برای actionهای فرعی؛ focus و action keyboard.
- empty/no-result/permission/error مستقل.

معیار پذیرش:

- فیلترها قابل پاک‌کردن و در URL/state حفظ شوند.
- range pagination درست باشد و با refresh filter/page حفظ شود.
- در mobile هر کارت تصویر، نام، SKU، قیمت، موجودی، status و menu داشته باشد.
- تصویر missing alt مناسب یا placeholder decorative داشته باشد.

### فاز ۳ — Orders

کارها:

- جدول دسکتاپ و card موبایل با ID، مشتری، اقلام، مبلغ، وضعیت، تاریخ و action.
- filter drawer و desktop filter row برای status/date/customer/amount/payment/shipping.
- status mapping فارسی با حفظ key داخلی؛ badge و icon semantic.
- mutation state، confirm برای statusهای خطرناک، undo یا refresh confirmation.
- detail به‌صورت subroute/drawer با breadcrumb، sticky action و preserve context.

معیار پذیرش:

- سفارش هدف با ترکیب search و filter در یک تعامل پیدا شود.
- تغییر وضعیت success/error/pending به‌صورت قابل‌فهم اعلام شود و state نهایی از server تأیید شود.
- back از detail به همان page، filter و scroll قبلی برگردد.
- statusها بدون اتکا به رنگ قابل تشخیص باشند.

### فاز ۴ — Product editor

کارها:

- تب‌ها/sectionهای استاندارد و sticky editor action bar.
- پوشش full description، tax، inventory، shipping، related products، attributes، variations و media.
- کنترل‌های قابل‌فهم برای attribute/variation به‌جای JSON خام تا حد امکان.
- field validation، error summary، `aria-invalid`، `aria-describedby` و focus اولین خطا.
- dirty state، cancel confirmation، upload progress، alt/reorder و toast save.

معیار پذیرش:

- کاربر بدون از دست دادن داده بین tabها جابه‌جا شود.
- خروج با فرم dirty هشدار بدهد و cancel بدون تغییر فرم آزاد باشد.
- save موفقیت، خطای API و permission پیام قابل‌فهم داشته باشند و button loading layout را تغییر ندهد.
- در mobile action اصلی همیشه در دسترس و بالای safe-area باشد.

### فاز ۵ — Hardening و تحویل

- audit کامل keyboard/contrast/RTL، تست viewport و regression component.
- تست با دادهٔ بزرگ، status ناشناخته، تصویر خراب، API کند، offline و permission محدود.
- بررسی performance: pagination، debounce، lazy image، جلوگیری از درخواست تکراری و cache امن.
- ثبت changelog طراحی، component usage و acceptance evidence.

## ۱۲. برنامهٔ Figma و missing input

### وضعیت ورودی

در ورودی فعلی هیچ Figma file URL، file key یا node-specific URL وجود ندارد. بنابراین ساخت object، component، variable، library یا dependency در Figma در این مرحله ممکن نیست و نباید انجام شود. این مورد به‌عنوان **Missing input — Figma file/node URL** ثبت می‌شود.

برای شروع Figma فقط این ورودی لازم است: لینک file و ترجیحاً nodeهای Dashboard، Products، Orders و Product editor در desktop و mobile، همراه با permission مشاهده/ویرایش.

### Workflow پیشنهادی Phase 0 تا Phase 4

| فاز | خروجی Figma | شرط ورود/خروج |
|---|---|---|
| Phase 0 — Input & audit | ثبت file/node، بررسی library/variables موجود، mapping screenهای موجود | ورود: URL معتبر؛ خروج: inventory و gap list |
| Phase 1 — Foundations | color variables، typography styles محلی، spacing/radius/shadow، RTL grid و breakpoint frames | خروج: tokenها با این سند هم‌نام و قابل export باشند |
| Phase 2 — Components | Button، Input، Select، Badge، Card، Table، Tabs، Drawer، Dialog، Toast، Skeleton و variant/stateها | خروج: component property و state matrix کامل |
| Phase 3 — Product flows | Dashboard، Products، Orders، Product editor در desktop/tablet/mobile و stateهای loading/empty/error/permission | خروج: flowها با acceptance criteria این سند منطبق باشند |
| Phase 4 — Handoff & validation | prototype keyboard/interaction، redline، annotation RTL/LTR، asset/font rule و dev handoff | خروج: توسعه‌دهنده بتواند بدون حدس‌زدن component را پیاده کند |

در Figma از فونت یا icon خارجی استفاده نشود. اگر Figma به font موجود در سیستم نیاز داشت، نام آن باید به فونت محلی قابل‌بارگذاری توسط ادمین map شود و در handoff جایگزین fallback ثبت گردد.

## ۱۳. فهرست کارهای اجرایی کوتاه‌مدت

1. تصمیم رسمی دربارهٔ سمت sidebar در RTL؛ پیشنهاد این سند: inline-end/right.
2. تعریف semantic tokenها و یکپارچه‌سازی cascade CSS در یک لایه.
3. رفع قرارداد `fonts.css` مفقود با همان منبع محلی؛ بدون افزودن asset خارجی.
4. تبدیل Products و Orders به table/card responsive با pagination و filter contract.
5. تکمیل Dashboard با KPI و chartهای واقعی و قابل‌دسترسی.
6. تبدیل Product editor به tabbed form با dirty/error/submit states.
7. اجرای audit دستی با keyboard، contrast، ۳۲۰/۳۹۰/۷۶۸/۱۰۲۴/۱۴۴۰ پیکسل و دادهٔ واقعی WooCommerce.

## خلاصهٔ تغییرات و گام بعدی

در این کار فقط این سند ایجاد/تکمیل شده است: `docs/ui-ux-design-system-plan.md`. هیچ فایل PHP، HTML، JavaScript، CSS، dependency، asset یا Figma object تغییر نکرده و هیچ dependency خارجی پیشنهاد نشده است.

گام بعدی تیم: تصویب فاز ۰، ارائهٔ Figma file/node URL در صورت نیاز، تصمیم sidebar RTL و سپس اجرای Foundation tokenها و shell مشترک قبل از ورود به Dashboard.

### بررسی‌های انجام‌شده

- سند `docs/woocommerce_admin_ui_ux_design.md` کامل خوانده و با یافته‌های کد تطبیق داده شد.
- `index.html`، `app.js` و `styles.css` از نظر ساختار، selector، state، navigation، responsive و accessibility بررسی شدند.
- تصاویر مرجع UI و تصاویر نامرتبط Downloads فهرست و دسته‌بندی شدند.
- بررسی ایستا نشان داد duplicate id در `index.html` وجود ندارد.
- `node --check fandoogh-manager/app/app.js` با موفقیت انجام شد.
- ارجاع `fonts.css` بررسی شد؛ فایل محلی در `fandoogh-manager/app` موجود نیست و این موضوع به‌عنوان gap ثبت شد.
- کنترل نهایی انجام شد: فایل وجود دارد، حجم آن ۵۹٬۵۱۹ بایت، تعداد خطوط ۷۶۳ و headingهای اصلی آن ثبت و بررسی شدند.
