# مستند طراحی UI/UX وب‌اپ مدیریت ووکامرس

## 1. هدف پروژه

این وب‌اپ یک پنل مدیریتی مدرن، سریع و Responsive برای مدیریت کامل فروشگاه WooCommerce است.

اصل مهم پروژه:

> پنل نباید قابلیت تجاری جدیدی خارج از WooCommerce ایجاد کند. تمام بخش‌ها، اطلاعات، وضعیت‌ها و عملیات باید بر پایه داده‌ها و قابلیت‌های موجود در WordPress + WooCommerce طراحی شوند.

هدف اصلی، ارائه یک رابط کاربری مدرن و ساده‌تر برای مدیریت اطلاعات موجود ووکامرس است؛ نه ساخت یک سیستم فروشگاهی مستقل.

---

# 2. پالت رنگ

| نام | مقدار | کاربرد |
|---|---|---|
| Primary | `#0f766e` | دکمه اصلی، Navigation فعال، لینک‌های مهم، وضعیت‌های مثبت |
| Secondary | `#115e59` | Header، Sidebar، Hover و عناصر تأکیدی |
| Accent | `#f59e0b` | هشدار، سفارش‌های در انتظار، CTAهای فرعی، Badge |
| Background | `#ffffff` | پس‌زمینه اصلی |
| Text | `#1f2937` | متن اصلی |
| Muted Text | `#6b7280` | متن فرعی |
| Border | `#e5e7eb` | مرز کارت‌ها و فیلدها |
| Success | `#10b981` | تکمیل‌شده / موجود / موفق |
| Warning | `#f59e0b` | در انتظار / نیازمند بررسی |
| Danger | `#ef4444` | لغو / حذف / خطا |
| Info | `#3b82f6` | اطلاعات و وضعیت‌های اطلاع‌رسانی |

### قواعد رنگ

- رنگ Primary برای CTA اصلی و وضعیت فعال استفاده شود.
- Accent فقط برای جلب توجه استفاده شود و نباید بیش از حد در UI دیده شود.
- پس‌زمینه عمدتاً سفید باقی بماند.
- از Gradientهای سنگین استفاده نشود.
- وضعیت‌ها همیشه علاوه بر رنگ، با متن یا آیکون مشخص شوند تا Accessibility حفظ شود.

---

# 3. اصول کلی UI

## سبک بصری

- Modern SaaS Dashboard
- RTL First
- Clean
- Minimal
- Professional
- Card Based
- مناسب مدیریت فروشگاه
- بدون شلوغی بصری

## Radius

- Card: `16px`
- Input: `10px`
- Button: `10px`
- Badge: `999px`
- Modal: `20px`

## Shadow

از Shadow بسیار نرم استفاده شود:

```css
box-shadow: 0 4px 20px rgba(15, 118, 110, 0.06);
```

Shadow نباید باعث سنگین شدن UI شود.

## Spacing

سیستم فاصله‌گذاری بر پایه 4 و 8 پیکسل:

- 4px
- 8px
- 12px
- 16px
- 24px
- 32px
- 40px

---

# 4. ساختار اصلی پنل

ساختار Desktop:

```text
┌──────────────────────────────────────────────────────────┐
│ Header                                                   │
├───────────────┬──────────────────────────────────────────┤
│               │                                          │
│ Sidebar       │              Main Content                │
│               │                                          │
│ Dashboard     │                                          │
│ Orders        │                                          │
│ Products      │                                          │
│ Customers     │                                          │
│ Analytics     │                                          │
│ Marketing     │                                          │
│ Coupons       │                                          │
│ Content       │                                          │
│ Woo Settings  │                                          │
│ Plugins       │                                          │
│ Users         │                                          │
│ Settings      │                                          │
│               │                                          │
└───────────────┴──────────────────────────────────────────┘
```

---

# 5. Navigation

## Sidebar Desktop

منوی اصلی:

1. داشبورد
2. سفارش‌ها
3. محصولات
4. دسته‌بندی‌ها
5. برچسب‌ها
6. ویژگی‌ها
7. مشتریان
8. گزارش‌ها / Analytics
9. کوپن‌ها
10. حمل‌ونقل
11. مالیات
12. پرداخت‌ها
13. WooCommerce
14. تنظیمات فروشگاه
15. کاربران
16. رسانه‌ها
17. تنظیمات

### نکته

تمام موارد بالا باید صرفاً نمایی از قابلیت‌ها و اطلاعات موجود WordPress/WooCommerce باشند.

هیچ ماژول مستقل جدیدی که منطق جداگانه از WooCommerce داشته باشد ایجاد نشود.

---

# 6. گروه‌بندی Navigation

برای جلوگیری از شلوغی Sidebar، منوها به صورت منطقی گروه‌بندی شوند.

## فروش

- داشبورد
- سفارش‌ها
- محصولات
- دسته‌بندی‌ها
- برچسب‌ها
- ویژگی‌ها

## مشتریان

- مشتریان
- کاربران

## بازاریابی

- کوپن‌ها
- گزارش‌های مرتبط با فروش

## فروشگاه

- حمل‌ونقل
- مالیات
- پرداخت‌ها
- تنظیمات WooCommerce

## سیستم

- رسانه‌ها
- تنظیمات
- افزونه‌ها

---

# 7. Header

Header شامل:

- Logo / نام فروشگاه
- جستجوی سراسری
- اعلان‌ها
- مشاهده فروشگاه
- پروفایل کاربر
- Toggle منوی موبایل

### جستجو

جستجو باید امکان دسترسی سریع به اطلاعات موجود WooCommerce را فراهم کند.

نمونه:

```text
جستجو در محصولات، سفارش‌ها، مشتریان...
```

نتایج باید دسته‌بندی شوند:

```text
محصولات
سفارش‌ها
مشتریان
```

---

# 8. Dashboard

داشبورد باید خلاصه‌ای از اطلاعات واقعی WooCommerce را نمایش دهد.

## KPI Cards

کارت‌های اصلی:

### فروش

- فروش ناخالص
- فروش خالص
- تعداد سفارش‌ها

### سفارش‌ها

- سفارش‌های در حال پردازش
- سفارش‌های تکمیل‌شده
- سفارش‌های لغوشده
- سفارش‌های در انتظار پرداخت

### محصولات

- تعداد محصولات
- محصولات موجود
- محصولات کم‌موجود
- محصولات ناموجود

### مشتریان

- تعداد مشتریان
- مشتریان جدید

> مقادیر باید مستقیماً از WooCommerce دریافت شوند.

---

# 9. Sales Chart

نمودار فروش:

- امروز
- 7 روز
- 30 روز
- ماه جاری
- بازه سفارشی

اطلاعات:

- درآمد
- تعداد سفارش‌ها

نمودار باید قابلیت Tooltip داشته باشد.

---

# 10. Order Status Chart

نمایش وضعیت سفارش‌ها:

- Pending payment
- Processing
- On hold
- Completed
- Cancelled
- Failed
- Refunded

در UI از ترجمه فارسی استفاده شود ولی مقدار Status داخلی WooCommerce حفظ شود.

---

# 11. Recent Orders

جدول آخرین سفارش‌ها:

| ستون | توضیح |
|---|---|
| شماره سفارش | Order ID |
| مشتری | Customer |
| محصولات | تعداد آیتم |
| مبلغ | Total |
| وضعیت | Status |
| تاریخ | Date |
| عملیات | View / Edit |

در موبایل جدول به Card تبدیل شود.

---

# 12. Products

صفحه محصولات باید اطلاعات استاندارد WooCommerce را نمایش دهد.

## اطلاعات محصول

- تصویر
- نام
- SKU
- قیمت
- قیمت فروش ویژه
- موجودی
- وضعیت موجودی
- دسته‌بندی
- نوع محصول
- وضعیت انتشار
- تاریخ ایجاد
- تاریخ ویرایش

## Product Types

- Simple
- Variable
- Grouped
- External/Affiliate

---

# 13. Product List UI

Desktop:

```text
[Search] [Category] [Stock] [Type] [Status]

┌─────────────────────────────────────────────┐
│ Image | Product | SKU | Price | Stock | ... │
└─────────────────────────────────────────────┘
```

Mobile:

هر محصول به صورت Card:

```text
┌───────────────────────────────┐
│ Image       Product Name      │
│             SKU               │
│             Price             │
│             Stock             │
│             Status            │
│                 ⋮             │
└───────────────────────────────┘
```

---

# 14. Product Details / Edit

فرم محصول باید ساختار WooCommerce را حفظ کند.

## Tabs

### اطلاعات اصلی

- نام محصول
- توضیحات
- توضیحات کوتاه
- SKU
- قیمت عادی
- قیمت فروش ویژه
- وضعیت مالیات
- وضعیت مالیات محصول

### موجودی

- SKU
- مدیریت موجودی
- تعداد موجودی
- وضعیت موجودی
- فروش تکی
- Allow backorders

### حمل‌ونقل

- وزن
- طول
- عرض
- ارتفاع
- کلاس حمل‌ونقل

### محصولات مرتبط

- Upsells
- Cross-sells
- محصولات مشابه موجود در WooCommerce

### ویژگی‌ها

- Attributes
- Visible on product page
- Used for variations

### Variations

برای محصولات Variable:

- Variation
- SKU
- Price
- Sale Price
- Stock
- Weight
- Dimensions
- Shipping class
- Image
- Attributes

---

# 15. Orders

صفحه سفارش‌ها یکی از مهم‌ترین بخش‌های پنل است.

## Filters

- وضعیت
- تاریخ
- مشتری
- مبلغ
- روش پرداخت
- روش ارسال

## Order Status

تمام Statusهای واقعی WooCommerce نمایش داده شوند:

- در انتظار پرداخت
- در حال پردازش
- در انتظار
- تکمیل‌شده
- لغوشده
- ناموفق
- مستردشده

---

# 16. Order Details

صفحه جزئیات سفارش:

## Header

```text
سفارش #12345
تاریخ
وضعیت
```

## Customer

- نام
- ایمیل
- شماره تلفن
- آدرس صورتحساب
- آدرس ارسال

## Order Items

- محصول
- Variation
- تعداد
- قیمت
- تخفیف
- مالیات
- مجموع

## Totals

- Subtotal
- Discount
- Shipping
- Tax
- Total
- Refund

## Payment

- روش پرداخت
- Transaction ID
- وضعیت پرداخت

## Shipping

- روش ارسال
- Tracking information در صورت وجود داده مربوط به WooCommerce / افزونه حمل‌ونقل

## Order Notes

نمایش یادداشت‌های سفارش مطابق WooCommerce.

---

# 17. Customers

صفحه مشتریان باید بر اساس داده‌های WooCommerce و WordPress طراحی شود.

اطلاعات:

- نام
- ایمیل
- تلفن
- تعداد سفارش‌ها
- مجموع خرید
- آخرین سفارش
- تاریخ عضویت

صفحه جزئیات مشتری:

- اطلاعات حساب
- سفارش‌ها
- آدرس صورتحساب
- آدرس ارسال
- مجموع خرید
- سفارش‌های قبلی

---

# 18. Categories

مدیریت دسته‌بندی محصولات:

- نام
- Slug
- توضیح
- تصویر
- دسته والد
- تعداد محصولات

عملیات:

- ایجاد
- ویرایش
- حذف
- جستجو

---

# 19. Tags

مدیریت Product Tags:

- نام
- Slug
- توضیح
- تعداد محصولات

---

# 20. Attributes

مدیریت Attributes ووکامرس:

- نام ویژگی
- Slug
- نوع نمایش
- ترتیب
- Terms

نمونه:

```text
رنگ
  ├─ قرمز
  ├─ آبی
  └─ مشکی

سایز
  ├─ S
  ├─ M
  └─ L
```

---

# 21. Coupons

صفحه کوپن باید از ساختار WooCommerce استفاده کند.

اطلاعات:

- Code
- Discount type
- Amount
- Expiry
- Usage limit
- Usage count
- Minimum spend
- Maximum spend
- Individual use
- Free shipping
- Excluded products/categories

---

# 22. Reports / Analytics

این بخش باید داده‌های موجود WooCommerce Analytics را نمایش دهد.

موارد:

- Sales
- Orders
- Products
- Customers
- Revenue
- Coupons
- Taxes
- Downloads در صورت فعال بودن قابلیت مربوطه

فیلتر زمانی:

- امروز
- هفته
- ماه
- سال
- Custom range

---

# 23. Settings

تنظیمات باید به بخش‌های استاندارد WooCommerce متصل باشند.

## General

- Store address
- Currency
- General options

## Products

- General
- Inventory
- Downloadable products

## Tax

- Tax options
- Tax rates

## Shipping

- Shipping zones
- Shipping methods
- Shipping options

## Payments

- Payment gateways
- Gateway settings

## Accounts & Privacy

- Customer accounts
- Privacy
- Personal data

## Emails

- Email notifications
- Email templates

## Advanced

- Page setup
- REST API
- Webhooks
- Advanced options

---

# 24. Responsive Design

طراحی باید Mobile First باشد.

## Breakpoints پیشنهادی

```text
Mobile: 0 - 767px
Tablet: 768 - 1023px
Desktop: 1024px+
Large Desktop: 1440px+
```

---

# 25. Mobile Navigation

در موبایل Sidebar به Drawer تبدیل شود.

```text
┌─────────────────────────────┐
│ ☰       StoreManager    🔔  │
├─────────────────────────────┤
│                             │
│         Content             │
│                             │
├─────────────────────────────┤
│ Dashboard Orders Products   │
│ Customers      More         │
└─────────────────────────────┘
```

Bottom Navigation حداکثر 5 آیتم داشته باشد:

1. داشبورد
2. سفارش‌ها
3. محصولات
4. مشتریان
5. بیشتر

گزینه «بیشتر» Drawer اصلی را باز کند.

---

# 26. Mobile Dashboard

KPIها در Grid دو ستونه:

```text
┌──────────────┐ ┌──────────────┐
│ فروش         │ │ سفارش‌ها      │
│ 24M          │ │ 126          │
└──────────────┘ └──────────────┘

┌──────────────┐ ┌──────────────┐
│ محصولات      │ │ مشتریان       │
│ 324          │ │ 58           │
└──────────────┘ └──────────────┘
```

نمودارها Full Width باشند.

---

# 27. Mobile Product List

- Search در بالای صفحه
- Filter Button
- Sort Button
- Product Cards
- Sticky Bottom Navigation

هر Card شامل:

- تصویر
- نام
- قیمت
- موجودی
- وضعیت
- منوی سه‌نقطه

---

# 28. Mobile Order List

هر سفارش به صورت Card:

```text
#12345
محمد رضایی

2,450,000 تومان

[در حال پردازش]

1405/03/31
```

Tap روی Card → Order Details.

---

# 29. Mobile Order Details

ساختار:

1. Order Header
2. Status
3. Customer
4. Products
5. Totals
6. Payment
7. Shipping
8. Order Notes
9. Actions

دکمه‌های اصلی پایین صفحه به صورت Sticky قرار بگیرند.

---

# 30. Loading States

برای تمام صفحات:

- Skeleton Loading
- Button Loading
- Table Loading
- Card Loading

در زمان دریافت اطلاعات از API، UI نباید ناگهان خالی شود.

---

# 31. Empty States

اگر داده‌ای وجود ندارد:

```text
آیتمی پیدا نشد

در حال حاضر اطلاعاتی برای نمایش وجود ندارد.
```

در صورت امکان، CTA مرتبط با همان قابلیت WooCommerce نمایش داده شود.

---

# 32. Error States

خطاها باید واضح باشند.

مثال:

```text
دریافت اطلاعات انجام نشد.

لطفاً دوباره تلاش کنید.
[تلاش مجدد]
```

خطاهای API نباید مستقیماً با متن فنی به کاربر نمایش داده شوند.

---

# 33. Toast / Notification

برای عملیات موفق:

```text
محصول با موفقیت ذخیره شد.
```

برای خطا:

```text
ذخیره محصول انجام نشد.
```

Toast باید کوتاه، غیرمزاحم و قابل بستن باشد.

---

# 34. Confirmation Dialog

برای عملیات خطرناک:

- حذف محصول
- حذف دسته‌بندی
- حذف کوپن
- حذف سفارش در صورت مجاز بودن

نمونه:

```text
حذف محصول

آیا از حذف این محصول مطمئن هستید؟

[انصراف] [حذف]
```

عملیات مخرب همیشه نیازمند تأیید باشد.

---

# 35. Accessibility

الزامات:

- Contrast مناسب
- Keyboard Navigation
- Focus State
- Label برای Inputها
- Alt برای تصاویر
- Tooltip برای آیکون‌های بدون متن
- رنگ به تنهایی نشانه وضعیت نباشد
- Touch Target حداقل حدود 44×44px

---

# 36. RTL

کل UI باید RTL Native باشد.

```css
html {
    direction: rtl;
}

body {
    text-align: right;
}
```

اما:

- اعداد
- SKU
- Order ID
- URL
- Email
- برخی کدهای فنی

در صورت نیاز باید LTR نمایش داده شوند.

---

# 37. Typography

فونت فارسی پیشنهادی:

- Vazirmatn

وزن‌ها:

- Regular: 400
- Medium: 500
- SemiBold: 600
- Bold: 700

Hierarchy:

```text
Page Title: 24px / 700
Section Title: 18px / 600
Card Title: 16px / 600
Body: 14px / 400
Caption: 12px / 400
```

Mobile:

```text
Page Title: 20px
Section Title: 16px
Body: 14px
Caption: 12px
```

---

# 38. Icons

استایل آیکون:

- Outline
- ساده
- Consistent

برای هر مفهوم یک آیکون ثابت استفاده شود.

نمونه:

- Dashboard → Home
- Orders → Shopping Cart
- Products → Package
- Customers → Users
- Analytics → Chart
- Coupons → Ticket
- Settings → Settings

از ترکیب چند Icon Set مختلف خودداری شود.

---

# 39. Performance UX

- Pagination واقعی
- Server-side filtering
- Debounce برای Search
- Lazy loading تصاویر
- Skeleton loading
- جلوگیری از درخواست API غیرضروری
- Cache اطلاعاتی که امکان Cache شدن دارند
- Optimistic UI فقط در عملیات کم‌خطر

---

# 40. API / Data Principle

Frontend نباید منطق اصلی WooCommerce را دوباره پیاده‌سازی کند.

معماری پیشنهادی:

```text
Mobile / Web UI
       ↓
API Layer
       ↓
Authentication
       ↓
WooCommerce / WordPress
       ↓
WooCommerce Database
```

تمام عملیات باید از API معتبر انجام شوند.

---

# 41. امنیت UI

در UI:

- Token در محل امن نگهداری شود.
- اطلاعات حساس در Log نمایش داده نشود.
- Permissionها از Backend کنترل شوند.
- Frontend نباید صرفاً با مخفی کردن Button دسترسی را محدود کند.
- عملیات حساس باید در Backend مجدداً Permission Check شوند.
- Nonce / Authentication / Authorization در Backend رعایت شود.
- خطاهای داخلی PHP/API به کاربر نمایش داده نشوند.

---

# 42. Permission Based UI

کاربر فقط قابلیت‌هایی را ببیند که مجوز انجام آن‌ها را دارد.

مثال:

```text
Administrator
├── همه امکانات
│
Shop Manager
├── محصولات
├── سفارش‌ها
├── مشتریان
├── گزارش‌ها
└── برخی تنظیمات

Customer
└── اطلاعات حساب و سفارش‌های خودش
```

این ساختار باید با Role و Capability واقعی WordPress/WooCommerce هماهنگ باشد.

---

# 43. Desktop Responsive Behavior

در Desktop:

- Sidebar ثابت
- Header ثابت
- Content با max-width مناسب
- Grid چند ستونه
- Tables کامل

در Tablet:

- Sidebar قابل Collapse
- Grid دو ستونه
- Tables با Scroll افقی در صورت نیاز

در Mobile:

- Sidebar → Drawer
- Bottom Navigation
- Tables → Cards
- Grid → یک یا دو ستون
- Actions → Sticky

---

# 44. Design System Components

کامپوننت‌های مشترک:

```text
Button
IconButton
Input
Select
Search
DatePicker
Dropdown
Badge
Card
Modal
Drawer
Toast
Tooltip
Tabs
Table
Pagination
Avatar
Skeleton
EmptyState
ErrorState
StatCard
ChartCard
ProductCard
OrderCard
CustomerCard
```

تمام صفحات باید از همین Design System استفاده کنند.

---

# 45. Dashboard Layout

Desktop:

```text
Header

Page Header
├── Welcome
├── Date Range
└── Actions

KPI Grid
├── Revenue
├── Orders
├── Customers
└── Products

Main Analytics
├── Sales Chart
└── Order Status

Secondary
├── Recent Orders
└── Top Products
```

---

# 46. صفحه‌بندی

تمام لیست‌های بزرگ باید Pagination داشته باشند.

گزینه‌ها:

```text
10
20
50
100
```

نمایش:

```text
نمایش 1 تا 20 از 324
```

---

# 47. Search UX

Search باید:

- سریع
- قابل Clear
- دارای Loading
- دارای Empty State
- دارای Keyboard Navigation
- دارای Result Grouping

باشد.

---

# 48. Filter UX

در Desktop:

```text
Search | Category | Status | Stock | Date
```

در Mobile:

```text
[فیلتر]
```

با کلیک:

```text
┌───────────────────────────┐
│ فیلترها                   │
├───────────────────────────┤
│ دسته‌بندی                 │
│ وضعیت                     │
│ موجودی                    │
│ تاریخ                     │
├───────────────────────────┤
│ پاک کردن | اعمال فیلتر    │
└───────────────────────────┘
```

---

# 49. اصول جلوگیری از شلوغی

- هر صفحه یک هدف اصلی داشته باشد.
- بیش از حد Card استفاده نشود.
- Actionهای فرعی داخل More Menu قرار بگیرند.
- CTA اصلی فقط یک مورد باشد.
- اطلاعات کم‌اهمیت در سطح دوم نمایش داده شوند.
- Modal فقط برای عملیات کوتاه استفاده شود.
- فرم‌های طولانی به Section/Tab تقسیم شوند.

---

# 50. اصل مهم پروژه

این پنل باید:

> «یک رابط کاربری مدرن برای WooCommerce باشد، نه یک سیستم مدیریت فروشگاه جدید.»

بنابراین:

- Order جدید خارج از WooCommerce ایجاد نمی‌شود.
- Product جدید خارج از WooCommerce ایجاد نمی‌شود.
- Customer مستقل ایجاد نمی‌شود.
- Inventory مستقل ایجاد نمی‌شود.
- Coupon مستقل ایجاد نمی‌شود.
- Analytics مستقل با منطق جداگانه ساخته نمی‌شود.
- Statusهای سفارشی بدون نیاز واقعی اضافه نمی‌شوند.
- اطلاعات اصلی از WooCommerce دریافت می‌شوند.
- عملیات به WooCommerce متصل هستند.

---

# 51. نتیجه نهایی UI/UX

طراحی نهایی باید این ویژگی‌ها را داشته باشد:

- Modern
- Professional
- RTL
- Responsive
- Mobile First
- Fast
- Accessible
- WooCommerce Native
- API Driven
- Secure
- Scalable
- Component Based
- Consistent

رنگ اصلی برند:

`#0f766e`

رنگ ثانویه:

`#115e59`

Accent:

`#f59e0b`

پس‌زمینه:

`#ffffff`

متن:

`#1f2937`

این Design System باید مبنای تمام صفحات Web App قرار گیرد.
