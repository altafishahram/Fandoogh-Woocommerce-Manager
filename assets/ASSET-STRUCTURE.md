# Fandoogh Manager frontend assets

این ساختار فقط assetهای قابل‌انتشارِ فرانت‌اند را نگه می‌دارد:

| مسیر | مسئولیت |
| --- | --- |
| `app/index.html` | shell، metadata نصب و قرارداد id/data |
| `app/styles.css` | قواعد قدیمی/کامپوننتی که رفتار صفحه به آن‌ها متکی است |
| `app/ui.css` | لایهٔ نهایی برند، کلاس‌های semantic و responsive safeguards |
| `app/fonts.css` | تعریف فونت وزیر؛ وردپرس مسیر فونت را در CSS تولیدشده به نشانی افزونه تبدیل می‌کند |
| `assets/fonts/vazir/` | فونت متغیر وزیر همراه مجوز SIL OFL 1.1 |
| `assets/brand/fandoogh-mark.svg` | نشان پیش‌فرض داخل رابط؛ آیکون نصب و مرورگر از آیکون سایت خوانده می‌شوند |
| `assets/icon/fandoogh-*.svg` | iconهای محلی navigation/action که PHP در config عمومی map می‌کند |

## قرارداد icon

کلید icon همان `data-icon-name` در `app/index.html` است و filename با پیشوند
`fandoogh-` شروع می‌شود. map قابل ردیابی در `get_public_icon_urls()` فایل
`fandoogh-manager.php` قرار دارد. iconهای `refresh` و iconهای کوچک داخل کارت‌ها
عمداً inline هستند و asset مستقل ندارند.

در audit مورخ ۱۴۰۵/۰۶/۱۳، ۱۴ icon از ۲۶ فایل قدیمی با جست‌وجوی هم‌زمان در
`data-icon-name` و map PHP مصرف می‌شدند. ۱۲ فایل Iconsax بدون ارجاع از source
حذف شدند؛ نام ۱۴ فایل باقی‌مانده به قرارداد `fandoogh-<role>.svg` تغییر کرد.
آرشیوهای ZIP ورودی انتشار نیستند و عمداً در این audit لحاظ نمی‌شوند.

برای تکرار بررسی:

```text
node tools/audit-assets.js
```

اگر icon جدیدی اضافه شد، ابتدا فایل را در `assets/icon/` با نام نقش UI بسازید،
سپس map PHP و `data-icon-name` را در همان تغییر ثبت کنید.
