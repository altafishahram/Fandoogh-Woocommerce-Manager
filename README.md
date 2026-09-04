# Fandoogh Manager

افزونهٔ پایهٔ مدیریت فروشگاه فندوق برای WordPress و WooCommerce است. رابط مدیریت به‌صورت یک PWA بدون وابستگی runtime ارائه می‌شود و اتصال واقعی آن از API داخلی افزونه انجام می‌شود.

## توسعه و بررسی

پیش‌نیازها: Node.js 20 یا جدیدتر و PHP برای بررسی syntax فایل‌های backend.

```bash
npm ci
npx playwright install chromium webkit
npm run lint
npm run test:e2e:chromium
npm run test:e2e:webkit
```

`npm run test:e2e:preview` (و aliasهای `test:e2e` و `test:e2e:all`) همهٔ پروژه‌ها را اجرا می‌کند. تست‌ها برنامه را از سرور استاتیک `tools/static-server.js` و با `?preview=1` باز می‌کنند. این‌ها تست‌های browser/UI برای دادهٔ نمونهٔ داخل حافظه‌اند؛ تست integration وردپرس، REST API، احراز هویت یا WooCommerce نیستند و هیچ درخواستی به `/wp-json/` ارسال نمی‌کنند.

## ساخت بستهٔ نصب

```bash
npm run package
npm run package:check
```

خروجی در `dist/fandoogh-manager.zip` ساخته می‌شود. سازندهٔ بسته فهرست مجاز مشخصی دارد، فایل‌ها را به‌ترتیب پایدار می‌نویسد و timestamp و permission ثابت استفاده می‌کند؛ بنابراین با سورس یکسان، hash فایل ZIP نیز یکسان است. `package:check` هم فهرست و محتوای هر فایل را با سورس مقایسه می‌کند.

در بسته فقط فایل‌های runtime افزونه، `assets/`، `app/`، `languages/` و راهنمای نصب قرار می‌گیرند. تست‌ها، workflow، ابزارهای توسعه، `mock-api.php`، `package.json`، ZIPهای قبلی و پوشه‌های build عمداً خارج هستند.

برای نصب، ZIP را از مسیر `dist/` در WordPress از بخش «افزونه‌ها → افزودن → بارگذاری افزونه» بارگذاری و سپس فعال کنید. جزئیات نصب کوتاه داخل `package-readme.md` نیز همراه بسته قرار می‌گیرد.

## CI

Workflow موجود در `.github/workflows/playwright.yml` Node 20 را نصب می‌کند، browserهای Chromium و WebKit را با dependencyهای لینوکس نصب می‌کند، lint، تست‌های preview و بررسی بستهٔ نصب را اجرا می‌کند. برای اجرای تست واقعی API یا WordPress باید محیط integration جداگانه تعریف شود؛ preview این نقش را ندارد.
