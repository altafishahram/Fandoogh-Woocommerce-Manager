(function () {
  "use strict";

  /*
   * UI CONTRACT — قرارداد اتصال ظاهر و رفتار
   *
   * class        = قلاب‌های ظاهر و چیدمان در styles.css
   * id           = قلاب‌های رفتاری که در getElements و bindEvents استفاده می‌شوند
   * data-nav-*   = اتصال ناوبری به بخش‌ها؛ data-action-* و data-*-tab هم رفتارهای جزئی‌ترند
   * hidden/aria-* = وضعیت قابل‌مشاهده و دسترس‌پذیری که app.js مدیریت می‌کند
   * data-state   = حالت‌های بصری مثل secure، loading، error و connected
   *
   * اگر فقط ظاهر را تغییر می‌دهی، classها و CSS را ویرایش کن. اگر id یا data-* را
   * عوض کردی، ابتدا قلاب متناظر را در getElements، bindEvents و renderهای همان بخش
   * پیدا و هماهنگ کن. کارت‌ها و لیست‌های تولیدشدهٔ پویا در توابع render هر بخش ساخته می‌شوند.
   */

  function metaContent(name) {
    var node = document.querySelector('meta[name="' + name + '"]');
    return node && typeof node.content === "string" ? node.content : "";
  }

  var CONFIG_URL = metaContent("fandoogh-config-url") || new URL("../wp-json/fandoogh-manager/v1/config", window.location.href).toString();
  var DEFAULT_API_ROOT = new URL("../wp-json/fandoogh-manager/v1/", window.location.href).toString();
  var MANAGER_SCOPE = metaContent("fandoogh-app-scope") || new URL("./", window.location.href).pathname;
  var SERVICE_WORKER_URL = metaContent("fandoogh-service-worker-url") || new URL("sw.js", window.location.href).toString();
  var APP_BUILD_VERSION = metaContent("fandoogh-app-version");
  var APP_VERSION_STORAGE_KEY = "fandoogh-manager-app-version";
  var CONFIG_TIMEOUT_MS = 4500;
  var REQUEST_TIMEOUT_MS = 30000;
  var MEDIA_UPLOAD_TIMEOUT_MS = 60000;
  var PERSIAN_DIGITS = "۰۱۲۳۴۵۶۷۸۹";
  var PERSIAN_DATE_LOCALE = "fa-IR-u-ca-persian-nu-arabext";
  var PERSIAN_NUMBER_LOCALE = "fa-IR-u-nu-arabext";

  var DEFAULT_CONFIG = {
    brand: {
      name: "فندوق",
      primary: "#0f766e",
      primaryStrong: "#115e59",
      accent: "#f59e0b",
      background: "#ffffff",
      surface: "#ffffff",
      ink: "#1f2937"
    },
    analytics: {
      enabled: false
    },
    tracking: {
      providers: [
        { id: "post", label: "پست ایران" },
        { id: "chapar", label: "چاپار" },
        { id: "tipax", label: "تیپاکس" }
      ]
    },
    currency: {
      code: "IRT",
      label: "تومان"
    },
    api: {
      pair: DEFAULT_API_ROOT + "auth/pair",
      me: DEFAULT_API_ROOT + "auth/me",
      csrf: DEFAULT_API_ROOT + "auth/csrf",
      logout: DEFAULT_API_ROOT + "auth/logout",
      devices: DEFAULT_API_ROOT + "auth/devices",
      acknowledge: DEFAULT_API_ROOT + "auth/acknowledge-new-sessions",
      audit: DEFAULT_API_ROOT + "auth/audit",
      products: DEFAULT_API_ROOT + "products",
      product_attributes: DEFAULT_API_ROOT + "product-attributes",
      shipping_classes: DEFAULT_API_ROOT + "product-shipping-classes",
      categories: DEFAULT_API_ROOT + "product-categories",
      customers: DEFAULT_API_ROOT + "customers",
      orders: DEFAULT_API_ROOT + "orders",
      analytics: DEFAULT_API_ROOT + "analytics/summary",
      coupons: DEFAULT_API_ROOT + "coupons",
      reviews: DEFAULT_API_ROOT + "reviews",
      inventory: DEFAULT_API_ROOT + "inventory",
      media: DEFAULT_API_ROOT + "media"
    }
  };

  /*
   * [PREVIEW DATA] داده‌های ساختگیِ قابل ویرایش برای دیدن ظاهر پنل بدون اتصال واقعی.
   * این داده‌ها فقط داخل حافظهٔ مرورگر هستند و هیچ درخواست یا تغییری روی فروشگاه ایجاد نمی‌کنند.
   * برای شخصی‌سازی پیش‌نمایش، نام فروشگاه، محصولات و اعداد همین بلوک را تغییر بده.
   */
  var PREVIEW_STORE_DATA = {
    brand: {
      name: "فروشگاه فندوق",
      primary: "#0f766e",
      primaryStrong: "#115e59",
      accent: "#f59e0b",
      background: "#f7fbfa",
      surface: "#ffffff",
      ink: "#172033"
    },
    user: {
      display_name: "نگار احمدی",
      role_label: "مدیر فروشگاه"
    },
    categories: [
      { id: "1", name: "خوراکی سالم", slug: "healthy-food", description: "محصولات سالم و روزمره", count: "14" },
      { id: "2", name: "نوشیدنی‌ها", slug: "drinks", description: "چای، قهوه و نوشیدنی‌های گرم", count: "9" },
      { id: "3", name: "بسته‌های هدیه", slug: "gift-boxes", description: "پیشنهادهای آماده برای هدیه", count: "6" },
      { id: "4", name: "پیشنهاد ویژه", slug: "special-offers", description: "محصولات تخفیف‌دار فروشگاه", count: "8" }
    ],
    products: [
      {
        id: "101",
        name: "کره بادام‌زمینی طبیعی",
        slug: "natural-peanut-butter",
        type: "simple",
        product_kind: "physical",
        price: "485000",
        regular_price: "540000",
        sale_price: "485000",
        discount_percent: "10",
        stock_status: "instock",
        stock_quantity: "42",
        manage_stock: true,
        sku: "FB-101",
        status: "publish",
        total_sales: "86",
        categories: [{ id: "1", name: "خوراکی سالم", slug: "healthy-food" }]
      },
      {
        id: "102",
        name: "عسل کوهستان ۵۰۰ گرمی",
        slug: "mountain-honey-500",
        type: "simple",
        product_kind: "physical",
        price: "390000",
        regular_price: "390000",
        stock_status: "instock",
        stock_quantity: "18",
        manage_stock: true,
        sku: "FB-102",
        status: "publish",
        total_sales: "54",
        categories: [{ id: "1", name: "خوراکی سالم", slug: "healthy-food" }]
      },
      {
        id: "103",
        name: "چای سبز لاهیجان",
        slug: "lahijan-green-tea",
        type: "simple",
        product_kind: "physical",
        price: "275000",
        regular_price: "300000",
        sale_price: "275000",
        discount_percent: "8",
        stock_status: "instock",
        stock_quantity: "7",
        manage_stock: true,
        sku: "FB-103",
        status: "publish",
        total_sales: "41",
        categories: [{ id: "2", name: "نوشیدنی‌ها", slug: "drinks" }]
      },
      {
        id: "104",
        name: "شکلات دست‌ساز تلخ",
        slug: "dark-handmade-chocolate",
        type: "simple",
        product_kind: "physical",
        price: "320000",
        regular_price: "320000",
        stock_status: "outofstock",
        stock_quantity: "0",
        manage_stock: true,
        sku: "FB-104",
        status: "publish",
        total_sales: "29",
        categories: [{ id: "1", name: "خوراکی سالم", slug: "healthy-food" }]
      },
      {
        id: "105",
        name: "بسته هدیه شب یلدا",
        slug: "yalda-gift-box",
        type: "variable",
        product_kind: "physical",
        price: "980000",
        regular_price: "1200000",
        sale_price: "980000",
        discount_percent: "18",
        stock_status: "instock",
        stock_quantity: "12",
        manage_stock: true,
        sku: "FB-105",
        status: "publish",
        total_sales: "63",
        categories: [{ id: "3", name: "بسته‌های هدیه", slug: "gift-boxes" }]
      },
      {
        id: "106",
        name: "قهوه اسپرسو ۲۵۰ گرمی",
        slug: "espresso-coffee-250",
        type: "simple",
        product_kind: "physical",
        price: "560000",
        regular_price: "560000",
        stock_status: "instock",
        stock_quantity: "26",
        manage_stock: true,
        sku: "FB-106",
        status: "publish",
        total_sales: "72",
        categories: [{ id: "2", name: "نوشیدنی‌ها", slug: "drinks" }]
      }
    ],
    orders: [
      {
        id: "5001",
        number: "5001",
        status: "processing",
        status_label: "در حال پردازش",
        created_at: "2026-09-02T10:20:00+03:30",
        total: "2450000",
        currency: "IRT",
        customer: { id: "201", display: "مریم رضایی", email: "maryam@example.com", phone: "09121234567" },
        items: { count: "4" },
        payment: { method_title: "پرداخت آنلاین", status: "paid" }
      },
      {
        id: "5002",
        number: "5002",
        status: "pending",
        status_label: "در انتظار پرداخت",
        created_at: "2026-09-02T09:05:00+03:30",
        total: "890000",
        currency: "IRT",
        customer: { id: "202", display: "علی کریمی", email: "ali@example.com", phone: "09129876543" },
        items: { count: "2" },
        payment: { method_title: "پرداخت در محل", status: "pending" }
      },
      {
        id: "5003",
        number: "5003",
        status: "completed",
        status_label: "تکمیل‌شده",
        created_at: "2026-09-01T17:40:00+03:30",
        total: "1340000",
        currency: "IRT",
        customer: { id: "203", display: "سارا محمدی", email: "sara@example.com", phone: "09351234567" },
        items: { count: "3" },
        payment: { method_title: "پرداخت آنلاین", status: "paid" }
      },
      {
        id: "5004",
        number: "5004",
        status: "on-hold",
        status_label: "در انتظار بررسی",
        created_at: "2026-08-31T13:15:00+03:30",
        total: "620000",
        currency: "IRT",
        customer: { id: "204", display: "رضا نادری", email: "reza@example.com", phone: "09105554433" },
        items: { count: "1" },
        payment: { method_title: "کارت‌به‌کارت", status: "on-hold" }
      },
      {
        id: "5005",
        number: "5005",
        status: "completed",
        status_label: "تکمیل‌شده",
        created_at: "2026-08-30T11:30:00+03:30",
        total: "3180000",
        currency: "IRT",
        customer: { id: "205", display: "الهام حسینی", email: "elham@example.com", phone: "09361239876" },
        items: { count: "6" },
        payment: { method_title: "پرداخت آنلاین", status: "paid" }
      },
      {
        id: "5006",
        number: "5006",
        status: "processing",
        status_label: "در حال پردازش",
        created_at: "2026-08-29T15:10:00+03:30",
        total: "760000",
        currency: "IRT",
        customer: { id: "206", display: "امیرحسین اکبری", email: "amir@example.com", phone: "09197776655" },
        items: { count: "2" },
        payment: { method_title: "پرداخت آنلاین", status: "paid" }
      }
    ],
    customers: [
      {
        id: "201",
        display_name: "مریم رضایی",
        username: "maryam.r",
        email: "maryam@example.com",
        phone: "09121234567",
        is_paying_customer: true,
        order_count: "8",
        total_spent: "12450000",
        date_created: "2026-05-12T10:00:00+03:30",
        billing: { city: "تهران", state: "تهران", address_1: "خیابان ولیعصر" },
        shipping: { city: "تهران", state: "تهران", address_1: "خیابان ولیعصر" }
      },
      {
        id: "202",
        display_name: "علی کریمی",
        username: "ali.k",
        email: "ali@example.com",
        phone: "09129876543",
        is_paying_customer: true,
        order_count: "5",
        total_spent: "6380000",
        date_created: "2026-06-03T12:20:00+03:30"
      },
      {
        id: "203",
        display_name: "سارا محمدی",
        username: "sara.m",
        email: "sara@example.com",
        phone: "09351234567",
        is_paying_customer: true,
        order_count: "4",
        total_spent: "4850000",
        date_created: "2026-06-18T09:45:00+03:30"
      },
      {
        id: "204",
        display_name: "رضا نادری",
        username: "reza.n",
        email: "reza@example.com",
        phone: "09105554433",
        is_paying_customer: true,
        order_count: "3",
        total_spent: "2790000",
        date_created: "2026-07-01T14:05:00+03:30"
      },
      {
        id: "205",
        display_name: "الهام حسینی",
        username: "elham.h",
        email: "elham@example.com",
        phone: "09361239876",
        is_paying_customer: true,
        order_count: "11",
        total_spent: "19850000",
        date_created: "2026-04-22T16:10:00+03:30"
      }
    ],
    inventory: [
      { id: "101", name: "کره بادام‌زمینی طبیعی", sku: "FB-101", type: "simple", stock_status: "instock", stock_status_label: "موجود", stock_quantity: "42", manage_stock: true, low_stock_amount: "10", low_stock: false, backorders: "no", updated_at: "2026-09-02T08:30:00+03:30" },
      { id: "102", name: "عسل کوهستان ۵۰۰ گرمی", sku: "FB-102", type: "simple", stock_status: "instock", stock_status_label: "موجود", stock_quantity: "18", manage_stock: true, low_stock_amount: "8", low_stock: false, backorders: "no", updated_at: "2026-09-01T12:10:00+03:30" },
      { id: "103", name: "چای سبز لاهیجان", sku: "FB-103", type: "simple", stock_status: "instock", stock_status_label: "موجود", stock_quantity: "7", manage_stock: true, low_stock_amount: "10", low_stock: true, backorders: "notify", updated_at: "2026-08-30T09:15:00+03:30" },
      { id: "104", name: "شکلات دست‌ساز تلخ", sku: "FB-104", type: "simple", stock_status: "outofstock", stock_status_label: "ناموجود", stock_quantity: "0", manage_stock: true, low_stock_amount: "5", low_stock: true, backorders: "no", updated_at: "2026-08-29T18:20:00+03:30" },
      { id: "105", name: "بسته هدیه شب یلدا", sku: "FB-105", type: "variable", stock_status: "onbackorder", stock_status_label: "پیش‌فروش", stock_quantity: "12", manage_stock: true, low_stock_amount: "6", low_stock: false, backorders: "yes", updated_at: "2026-08-28T15:45:00+03:30" }
    ],
    coupons: [
      { id: "301", code: "YALDA10", description: "تخفیف ویژهٔ خریدهای یلدایی", discount_type: "percent", amount: "10", date_expires: "2026-09-30T23:59:00+03:30", usage_limit: "100", usage_limit_per_user: "1", usage_count: "36", free_shipping: false, individual_use: true, exclude_sale_items: true, status: "publish" },
      { id: "302", code: "WELCOME150", description: "اولین خرید مشتریان جدید", discount_type: "fixed_cart", amount: "150000", date_expires: "", usage_limit: "0", usage_limit_per_user: "1", usage_count: "18", minimum_amount: "800000", free_shipping: false, individual_use: true, exclude_sale_items: false, status: "publish" },
      { id: "303", code: "COFFEE20", description: "پیشنهاد خرید قهوه و نوشیدنی", discount_type: "percent", amount: "20", date_expires: "2026-10-15T23:59:00+03:30", usage_limit: "50", usage_limit_per_user: "2", usage_count: "12", free_shipping: true, individual_use: false, exclude_sale_items: false, status: "publish" }
    ],
    reviews: [
      { id: "401", product_id: "101", product_name: "کره بادام‌زمینی طبیعی", author: "مریم رضایی", content: "بافت خیلی خوب و طعم طبیعی داشت؛ دوباره سفارش می‌دهم.", rating: 5, verified: true, status: "approve", date: "2026-09-02T08:45:00+03:30" },
      { id: "402", product_id: "106", product_name: "قهوه اسپرسو ۲۵۰ گرمی", author: "علی کریمی", content: "عطر قهوه عالی بود، فقط بسته‌بندی می‌توانست محکم‌تر باشد.", rating: 4, verified: true, status: "hold", date: "2026-09-01T15:25:00+03:30" },
      { id: "403", product_id: "102", product_name: "عسل کوهستان ۵۰۰ گرمی", author: "سارا محمدی", content: "ارسال سریع و محصول باکیفیت.", rating: 5, verified: true, status: "approve", date: "2026-08-30T10:10:00+03:30" },
      { id: "404", product_id: "103", product_name: "چای سبز لاهیجان", author: "کاربر مهمان", content: "برای مصرف روزانه انتخاب خوبی است.", rating: 4, verified: false, status: "approve", date: "2026-08-28T19:00:00+03:30" }
    ],
    analytics: {
      range: { label: "۳۰ روز اخیر", start: "2026-08-04", end: "2026-09-03" },
      currency: { code: "IRT", label: "تومان" },
      sales: { gross: "125600000", average_order: "4186667" },
      orders: {
        total: 30,
        successful: 25,
        statuses: [
          { label: "تکمیل‌شده", count: 19 },
          { label: "در حال پردازش", count: 5 },
          { label: "در انتظار پرداخت", count: 4 },
          { label: "در انتظار بررسی", count: 2 }
        ]
      },
      products: {
        total: 38,
        top: [
          { name: "کره بادام‌زمینی طبیعی", quantity: 86, total: "41610000" },
          { name: "قهوه اسپرسو ۲۵۰ گرمی", quantity: 72, total: "40320000" },
          { name: "بسته هدیه شب یلدا", quantity: 63, total: "61740000" },
          { name: "عسل کوهستان ۵۰۰ گرمی", quantity: 54, total: "21060000" }
        ]
      },
      customers: { unique: 18, guest_orders: 3 },
      meta: { truncated: false }
    },
    devices: [
      { id: "1", device_label: "Chrome روی Windows", status: "active", created_at: "2026-08-12T09:20:00+03:30", last_seen_at: "2026-09-03T11:10:00+03:30", expires_at: "2026-11-10T09:20:00+03:30", current: true },
      { id: "2", device_label: "Safari روی iPhone", status: "active", created_at: "2026-08-21T18:05:00+03:30", last_seen_at: "2026-09-02T22:40:00+03:30", expires_at: "2026-11-19T18:05:00+03:30", current: false }
    ],
    audit: [
      { id: "601", event_type: "product_updated", resource_type: "product", resource_id: "101", device_label: "Chrome روی Windows", created_at: "2026-09-03T09:15:00+03:30", context: { product_id: "101", outcome: "success" } },
      { id: "602", event_type: "order_status_updated", resource_type: "order", resource_id: "5001", device_label: "Chrome روی Windows", created_at: "2026-09-02T10:35:00+03:30", context: { order_id: "5001", status: "processing" } },
      { id: "603", event_type: "session_created", resource_type: "session", resource_id: "1", device_label: "Safari روی iPhone", created_at: "2026-08-21T18:05:00+03:30", context: { outcome: "success" } }
    ]
  };

  var SHIPMENT_STATUS_LABELS = Object.freeze({
    pending: "در انتظار ارسال",
    ready: "آمادهٔ ارسال",
    shipped: "ارسال شده",
    in_transit: "در حال انتقال",
    out_for_delivery: "در حال توزیع",
    delivered: "تحویل شده",
    failed: "ناموفق",
    returned: "مرجوع شده",
    cancelled: "لغو شده"
  });

  // [APP: STATE] وضعیت نشست، ناوبری، داده‌ها و مودال‌های برنامه.
  var state = {
    view: "pairing",
    networkOnline: typeof navigator === "undefined" || navigator.onLine !== false,
    configSource: "fallback",
    authenticated: false,
    previewMode: false,
    authBusy: false,
    csrfToken: "",
    activeSection: "dashboard",
    globalSearch: {
      results: [],
      activeIndex: -1
    },
    sidebarTrigger: null,
    user: {
      displayName: "مدیر فروشگاه",
      role: "مدیریت فروشگاه",
      scopes: {}
    },
    pwa: {
      deferredInstallPrompt: null,
      installed: false,
      registration: null,
      updateReady: false,
      updateReloading: false
    },
    config: null,
    products: {
      status: "secure",
      items: [],
      filteredItems: [],
      searchTimer: null,
      loadRequestId: 0,
      filters: {
        query: "",
        type: "any",
        status: "any",
        sort: "updated"
      },
      editorId: null,
      editorImages: [],
      editorAttributes: [],
      editorCategoryIds: [],
      editorDirty: false,
      editorStep: 0,
      editorTrigger: null,
      editorLoadRequestId: 0,
      viewId: null,
      viewProduct: null,
      viewVariations: [],
      viewStep: "summary",
      viewImageIndex: 0,
      viewTrigger: null,
      viewLoadRequestId: 0,
      shippingClasses: []
    },
    bulkPrice: {
      confirmationToken: "",
      preview: null,
      busy: false
    },
    attributeCatalog: {
      status: "secure",
      items: []
    },
    categories: {
      status: "secure",
      items: [],
      filteredItems: [],
      editorId: null
    },
    customers: {
      status: "secure",
      items: [],
      filteredItems: [],
      detailId: null,
      search: "",
      page: 1,
      total: 0,
      totalPages: 0,
      searchTimer: null,
      loadRequestId: 0,
      detailOrders: [],
      detailOrdersStatus: "idle",
      detailOrdersPage: 1,
      detailOrdersTotal: 0,
      detailOrdersTotalPages: 0,
      detailOrdersRequestId: 0
    },
    inventory: {
      status: "secure",
      items: [],
      filters: { search: "", stockStatus: "" },
      page: 1,
      total: 0,
      totalPages: 0,
      summary: null,
      searchTimer: null,
      loadRequestId: 0
    },
    coupons: {
      status: "secure",
      items: [],
      search: "",
      page: 1,
      total: 0,
      totalPages: 0,
      editorId: null,
      editorDirty: false,
      searchTimer: null,
      loadRequestId: 0
    },
    reviews: {
      status: "secure",
      items: [],
      search: "",
      statusFilter: "all",
      page: 1,
      total: 0,
      totalPages: 0,
      searchTimer: null,
      loadRequestId: 0
    },
    variations: {
      parentId: null,
      items: [],
      editorId: null
    },
    orders: {
      status: "secure",
      items: [],
      filteredItems: [],
      statuses: {},
      loadRequestId: 0,
      pendingCount: null,
      pendingItems: [],
      pendingCountRequestId: 0,
      pendingCountAnnouncement: null,
      page: 1,
      pageSize: 0,
      total: 0,
      totalPages: 0,
      searchTimer: null,
      filters: {
        search: "",
        status: "any",
        dateFrom: "",
        dateTo: "",
        minTotal: "",
        maxTotal: "",
        customerId: "",
        paymentMethod: "",
        shippingMethod: ""
      },
      detailId: null,
      detail: null,
      detailTrigger: null,
      viewTab: "overview",
      editTab: "status",
      editTrigger: null,
      editReturnToDetail: false,
      editControls: null,
      shipment: null,
      shipmentStatus: "idle"
    },
    manualOrder: {
      step: 0,
      busy: false,
      trigger: null,
      items: [],
      customerType: "guest",
      customerId: "",
      customer: {
        firstName: "",
        lastName: "",
        email: "",
        phone: ""
      },
      shippingMethod: "",
      paymentMethod: "",
      status: "pending",
      paymentComplete: false
    },
    analytics: {
      enabled: false,
      status: "disabled",
      range: "30d",
      data: null
    },
    security: {
      devicesStatus: "secure",
      devices: [],
      newSessionsCount: 0,
      auditStatus: "secure",
      auditItems: [],
      auditPage: 1,
      auditTotalPages: 0,
      auditEvent: ""
    }
  };

  // [UI: DOM HOOKS] تمام idهای HTML که رفتار برنامه مستقیماً به آن‌ها متصل است.
  var root = document.documentElement;
  var elements = {};

  // [UI: DOM CONTRACT] نام‌های این آبجکت باید با idهای index.html هماهنگ بمانند.
  function getElements() {
    elements.appShell = document.querySelector(".app-shell");
    elements.appMain = document.getElementById("appMain");
    elements.appBootView = document.getElementById("appBootView");
    elements.appSidebar = document.getElementById("appSidebar");
    elements.sidebarScrim = document.getElementById("sidebarScrim");
    elements.mobileMenuToggle = document.getElementById("mobileMenuToggle");
    elements.globalSearch = document.getElementById("globalSearch");
    elements.globalSearchResults = document.getElementById("globalSearchResults");
    elements.topbarUserName = document.getElementById("topbarUserName");
    elements.topbarUserRole = document.getElementById("topbarUserRole");
    elements.topbarUserAvatar = document.getElementById("topbarUserAvatar");
    elements.sidebarStoreUrl = document.getElementById("sidebarStoreUrl");
    elements.openStoreButton = document.getElementById("openStoreButton");
    elements.pairingView = document.getElementById("pairingView");
    elements.dashboardView = document.getElementById("dashboardView");
    elements.pairingForm = document.getElementById("pairingForm");
    elements.pairingUsername = document.getElementById("pairingUsername");
    elements.pairingPassword = document.getElementById("pairingPassword");
    elements.pairingCode = document.getElementById("pairingCode");
    elements.pairingSubmit = document.getElementById("pairingSubmit");
    elements.pairingMessage = document.getElementById("pairingMessage");
    elements.previewDashboard = document.getElementById("previewDashboard");
    elements.backToPairing = document.getElementById("backToPairing");
    elements.logoutButton = document.getElementById("logoutButton");
    elements.connectionControl = document.getElementById("connectionControl");
    elements.connectionIndicator = document.getElementById("connectionIndicator");
    elements.connectionLabel = document.getElementById("connectionLabel");
    elements.connectionPopover = document.getElementById("connectionPopover");
    elements.connectionPopoverTitle = document.getElementById("connectionPopoverTitle");
    elements.connectionPopoverText = document.getElementById("connectionPopoverText");
    elements.installAppButton = document.getElementById("installAppButton");
    elements.notificationsButton = document.getElementById("notificationsButton");
    elements.notificationsBadge = document.getElementById("notificationsBadge");
    elements.notificationsPanel = document.getElementById("notificationsPanel");
    elements.notificationsPanelTitle = document.getElementById("notificationsPanelTitle");
    elements.notificationsPanelText = document.getElementById("notificationsPanelText");
    elements.notificationsPendingList = document.getElementById("notificationsPendingList");
    elements.notificationsOpenOrders = document.getElementById("notificationsOpenOrders");
    elements.installPopover = document.getElementById("installPopover");
    elements.installPopoverText = document.getElementById("installPopoverText");
    elements.closeInstallGuide = document.getElementById("closeInstallGuide");
    elements.appUpdateBanner = document.getElementById("appUpdateBanner");
    elements.applyAppUpdate = document.getElementById("applyAppUpdate");
    elements.runtimeNote = document.getElementById("runtimeNote");
    elements.themeColor = document.getElementById("themeColor");
    elements.analyticsPanel = document.getElementById("analyticsPanel");
    elements.analyticsStateBadge = document.getElementById("analyticsStateBadge");
    elements.analyticsRange = document.getElementById("analyticsRange");
    elements.refreshAnalytics = document.getElementById("refreshAnalytics");
    elements.analyticsSecureState = document.getElementById("analyticsSecureState");
    elements.analyticsLoadingState = document.getElementById("analyticsLoadingState");
    elements.analyticsErrorState = document.getElementById("analyticsErrorState");
    elements.analyticsErrorText = document.getElementById("analyticsErrorText");
    elements.analyticsKpis = document.getElementById("analyticsKpis");
    elements.analyticsGrossSales = document.getElementById("analyticsGrossSales");
    elements.analyticsRangeLabel = document.getElementById("analyticsRangeLabel");
    elements.analyticsOrdersCount = document.getElementById("analyticsOrdersCount");
    elements.analyticsAverageOrder = document.getElementById("analyticsAverageOrder");
    elements.analyticsCustomersCount = document.getElementById("analyticsCustomersCount");
    elements.analyticsContent = document.getElementById("analyticsContent");
    elements.analyticsStatusList = document.getElementById("analyticsStatusList");
    elements.analyticsStatusDonut = document.getElementById("analyticsStatusDonut");
    elements.analyticsStatusDonutTotal = document.getElementById("analyticsStatusDonutTotal");
    elements.analyticsProductList = document.getElementById("analyticsProductList");
    elements.analyticsNote = document.getElementById("analyticsNote");
    elements.dashboardRange = document.getElementById("dashboardRange");
    elements.dashboardKpiGrid = document.getElementById("dashboardKpiGrid");
    elements.dashboardKpiRevenue = document.getElementById("dashboardKpiRevenue");
    elements.dashboardKpiRevenuePeriod = document.getElementById("dashboardKpiRevenuePeriod");
    elements.dashboardKpiOrders = document.getElementById("dashboardKpiOrders");
    elements.dashboardKpiOrdersPeriod = document.getElementById("dashboardKpiOrdersPeriod");
    elements.dashboardKpiCustomers = document.getElementById("dashboardKpiCustomers");
    elements.dashboardKpiCustomersPeriod = document.getElementById("dashboardKpiCustomersPeriod");
    elements.dashboardKpiProducts = document.getElementById("dashboardKpiProducts");
    elements.dashboardKpiProductsPeriod = document.getElementById("dashboardKpiProductsPeriod");
    elements.dashboardSalesPanel = document.getElementById("dashboardSalesPanel");
    elements.dashboardOrderStatusPanel = document.getElementById("dashboardOrderStatusPanel");
    elements.dashboardStatusList = document.getElementById("dashboardStatusList");
    elements.dashboardStatusDonut = document.getElementById("dashboardStatusDonut");
    elements.dashboardStatusDonutTotal = document.getElementById("dashboardStatusDonutTotal");
    elements.dashboardRecentOrders = document.getElementById("dashboardRecentOrders");
    elements.dashboardTopProducts = document.getElementById("dashboardTopProducts");
    elements.dashboardSalesChart = document.getElementById("dashboardSalesChart");
    elements.dashboardSalesOrdersLine = document.getElementById("dashboardSalesOrdersLine");
    elements.dashboardSalesLine = document.getElementById("dashboardSalesLine");
    elements.dashboardSalesChartEmpty = document.getElementById("dashboardSalesChartEmpty");
    elements.dashboardChartData = document.getElementById("dashboardChartData");
    elements.quickOrdersBadge = document.getElementById("quickOrdersBadge");
    elements.sidebarOrdersBadge = document.getElementById("sidebarOrdersBadge");
    elements.mobileOrdersBadge = document.getElementById("mobileOrdersBadge");
    elements.pendingOrdersAnnouncement = document.getElementById("pendingOrdersAnnouncement");
    elements.productSearch = document.getElementById("productSearch");
    elements.productFilterToggle = document.getElementById("toggleProductFilters");
    elements.productFilterBackdrop = document.getElementById("productFilterBackdrop");
    elements.productFilterPanel = document.getElementById("productFilterPanel");
    elements.closeProductFilters = document.getElementById("closeProductFilters");
    elements.applyProductFilters = document.getElementById("applyProductFilters");
    elements.resetProductFilters = document.getElementById("resetProductFilters");
    elements.productTypeFilter = document.getElementById("productTypeFilter");
    elements.productStatusFilter = document.getElementById("productStatusFilter");
    elements.productSort = document.getElementById("productSort");
    elements.newProductButton = document.getElementById("newProductButton");
    elements.refreshProducts = document.getElementById("refreshProducts");
    elements.openBulkPrice = document.getElementById("openBulkPrice");
    elements.bulkPricePanel = document.getElementById("bulkPricePanel");
    elements.closeBulkPrice = document.getElementById("closeBulkPrice");
    elements.bulkPriceForm = document.getElementById("bulkPriceForm");
    elements.bulkPriceCategories = document.getElementById("bulkPriceCategories");
    elements.bulkPriceType = document.getElementById("bulkPriceType");
    elements.bulkPriceAmount = document.getElementById("bulkPriceAmount");
    elements.bulkPriceAmountLabel = document.getElementById("bulkPriceAmountLabel");
    elements.bulkPriceIncludeChildren = document.getElementById("bulkPriceIncludeChildren");
    elements.previewBulkPrice = document.getElementById("previewBulkPrice");
    elements.executeBulkPrice = document.getElementById("executeBulkPrice");
    elements.resetBulkPrice = document.getElementById("resetBulkPrice");
    elements.bulkPriceMessage = document.getElementById("bulkPriceMessage");
    elements.bulkPricePreview = document.getElementById("bulkPricePreview");
    elements.bulkPricePreviewBadge = document.getElementById("bulkPricePreviewBadge");
    elements.bulkPriceSummary = document.getElementById("bulkPriceSummary");
    elements.bulkPriceSamples = document.getElementById("bulkPriceSamples");
    elements.productEditorOverlay = document.getElementById("productEditorOverlay");
    elements.productEditorDialog = document.getElementById("productEditorDialog");
    elements.productEditor = document.getElementById("productEditor");
    elements.productEditorTitle = document.getElementById("productEditorTitle");
    elements.cancelProductEdit = document.getElementById("cancelProductEdit");
    elements.productName = document.getElementById("productName");
    elements.productSku = document.getElementById("productSku");
    elements.productRegularPrice = document.getElementById("productRegularPrice");
    elements.productSalePrice = document.getElementById("productSalePrice");
    elements.productStatus = document.getElementById("productStatus");
    elements.productManageStock = document.getElementById("productManageStock");
    elements.productStockQuantity = document.getElementById("productStockQuantity");
    elements.productBackorders = document.getElementById("productBackorders");
    elements.productWeight = document.getElementById("productWeight");
    elements.productLength = document.getElementById("productLength");
    elements.productWidth = document.getElementById("productWidth");
    elements.productHeight = document.getElementById("productHeight");
    elements.productTaxStatus = document.getElementById("productTaxStatus");
    elements.productSoldIndividually = document.getElementById("productSoldIndividually");
    elements.productVirtual = document.getElementById("productVirtual");
    elements.productDownloadable = document.getElementById("productDownloadable");
    elements.productShortDescription = document.getElementById("productShortDescription");
    elements.productDescription = document.getElementById("productDescription");
    elements.productCategories = document.getElementById("productCategories");
    elements.productType = document.getElementById("productType");
    elements.productAttributesManager = document.getElementById("productAttributesManager");
    elements.productAttributesList = document.getElementById("productAttributesList");
    elements.addProductAttribute = document.getElementById("addProductAttribute");
    elements.productAttributesMessage = document.getElementById("productAttributesMessage");
    elements.productMediaInput = document.getElementById("productMediaInput");
    elements.uploadProductMedia = document.getElementById("uploadProductMedia");
    elements.productMediaMessage = document.getElementById("productMediaMessage");
    elements.productMediaGrid = document.getElementById("productMediaGrid");
    elements.saveProductButton = document.getElementById("saveProductButton");
    elements.productEditorMessage = document.getElementById("productEditorMessage");
    elements.productSlug = document.getElementById("productSlug");
    elements.productKind = document.getElementById("productKind");
    elements.productCategory = document.getElementById("productCategory");
    elements.productSubcategory = document.getElementById("productSubcategory");
    elements.productAdditionalCategories = document.getElementById("productCategories");
    elements.productCatalogVisibility = document.getElementById("productCatalogVisibility");
    elements.productReviewsAllowed = document.getElementById("productReviewsAllowed");
    elements.productShippingClass = document.getElementById("productShippingClass");
    elements.productTaxClass = document.getElementById("productTaxClass");
    elements.productDownloadLimit = document.getElementById("productDownloadLimit");
    elements.productDownloadExpiry = document.getElementById("productDownloadExpiry");
    elements.productPurchaseNote = document.getElementById("productPurchaseNote");
    elements.productUpsells = document.getElementById("productUpsells");
    elements.productCrossSells = document.getElementById("productCrossSells");
    elements.productAdvancedFields = document.getElementById("productAdvancedFields");
    elements.productViewOverlay = document.getElementById("productViewOverlay");
    elements.productViewDialog = document.getElementById("productViewDialog");
    elements.productViewTitle = document.getElementById("productViewTitle");
    elements.closeProductView = document.getElementById("closeProductView");
    elements.productViewCloseFooter = document.getElementById("productViewCloseFooter");
    elements.productViewTabs = document.getElementById("productViewTabs");
    elements.productViewTabContent = document.getElementById("productViewTabContent");
    elements.productViewLoadingState = document.getElementById("productViewLoadingState");
    elements.productViewErrorState = document.getElementById("productViewErrorState");
    elements.productViewErrorText = document.getElementById("productViewErrorText");
    elements.productViewEdit = document.getElementById("productViewEdit");
    elements.variationManager = document.getElementById("variationManager");
    elements.refreshVariations = document.getElementById("refreshVariations");
    elements.variationSku = document.getElementById("variationSku");
    elements.variationRegularPrice = document.getElementById("variationRegularPrice");
    elements.variationSalePrice = document.getElementById("variationSalePrice");
    elements.variationStatus = document.getElementById("variationStatus");
    elements.variationManageStock = document.getElementById("variationManageStock");
    elements.variationStockQuantity = document.getElementById("variationStockQuantity");
    elements.variationAttributes = document.getElementById("variationAttributes");
    elements.saveVariationButton = document.getElementById("saveVariationButton");
    elements.cancelVariationEdit = document.getElementById("cancelVariationEdit");
    elements.variationEditorMessage = document.getElementById("variationEditorMessage");
    elements.variationsLoadingState = document.getElementById("variationsLoadingState");
    elements.variationsErrorState = document.getElementById("variationsErrorState");
    elements.variationsErrorText = document.getElementById("variationsErrorText");
    elements.variationsGrid = document.getElementById("variationsGrid");
    elements.productsStateBadge = document.getElementById("productsStateBadge");
    elements.productsSecureState = document.getElementById("productsSecureState");
    elements.productsLoadingState = document.getElementById("productsLoadingState");
    elements.productsEmptyState = document.getElementById("productsEmptyState");
    elements.productsEmptyText = document.getElementById("productsEmptyText");
    elements.productsErrorState = document.getElementById("productsErrorState");
    elements.productsErrorText = document.getElementById("productsErrorText");
    elements.productsGrid = document.getElementById("productsGrid");
    elements.categorySearch = document.getElementById("categorySearch");
    elements.newCategoryButton = document.getElementById("newCategoryButton");
    elements.refreshCategories = document.getElementById("refreshCategories");
    elements.categoryEditor = document.getElementById("categoryEditor");
    elements.categoryEditorTitle = document.getElementById("categoryEditorTitle");
    elements.cancelCategoryEdit = document.getElementById("cancelCategoryEdit");
    elements.categoryName = document.getElementById("categoryName");
    elements.categorySlug = document.getElementById("categorySlug");
    elements.categoryParent = document.getElementById("categoryParent");
    elements.categoryDescription = document.getElementById("categoryDescription");
    elements.saveCategoryButton = document.getElementById("saveCategoryButton");
    elements.categoryEditorMessage = document.getElementById("categoryEditorMessage");
    elements.categoriesStateBadge = document.getElementById("categoriesStateBadge");
    elements.categoriesSecureState = document.getElementById("categoriesSecureState");
    elements.categoriesLoadingState = document.getElementById("categoriesLoadingState");
    elements.categoriesEmptyState = document.getElementById("categoriesEmptyState");
    elements.categoriesEmptyText = document.getElementById("categoriesEmptyText");
    elements.categoriesErrorState = document.getElementById("categoriesErrorState");
    elements.categoriesErrorText = document.getElementById("categoriesErrorText");
    elements.categoriesGrid = document.getElementById("categoriesGrid");
    elements.orderSearch = document.getElementById("orderSearch");
    elements.orderStatusFilter = document.getElementById("orderStatusFilter");
    elements.orderStatusChips = document.getElementById("orderStatusChips");
    elements.orderDateFrom = document.getElementById("orderDateFrom");
    elements.orderDateTo = document.getElementById("orderDateTo");
    elements.orderMinTotal = document.getElementById("orderMinTotal");
    elements.orderMaxTotal = document.getElementById("orderMaxTotal");
    elements.orderCustomerId = document.getElementById("orderCustomerId");
    elements.orderPaymentMethod = document.getElementById("orderPaymentMethod");
    elements.orderShippingMethod = document.getElementById("orderShippingMethod");
    elements.orderFilterToggle = document.getElementById("toggleOrderFilters");
    elements.orderFilterBackdrop = document.getElementById("orderFilterBackdrop");
    elements.orderFilterPanel = document.getElementById("orderFilterPanel");
    elements.closeOrderFilters = document.getElementById("closeOrderFilters");
    elements.applyOrderFilters = document.getElementById("applyOrderFilters");
    elements.resetOrderFilters = document.getElementById("resetOrderFilters");
    elements.ordersPagination = document.getElementById("ordersPagination");
    elements.ordersPreviousPage = document.getElementById("ordersPreviousPage");
    elements.ordersNextPage = document.getElementById("ordersNextPage");
    elements.ordersPageLabel = document.getElementById("ordersPageLabel");
    elements.refreshOrders = document.getElementById("refreshOrders");
    elements.ordersStateBadge = document.getElementById("ordersStateBadge");
    elements.ordersSecureState = document.getElementById("ordersSecureState");
    elements.ordersLoadingState = document.getElementById("ordersLoadingState");
    elements.ordersEmptyState = document.getElementById("ordersEmptyState");
    elements.ordersEmptyText = document.getElementById("ordersEmptyText");
    elements.ordersErrorState = document.getElementById("ordersErrorState");
    elements.ordersErrorText = document.getElementById("ordersErrorText");
    elements.ordersMutationMessage = document.getElementById("ordersMutationMessage");
    elements.ordersGrid = document.getElementById("ordersGrid");
    elements.newOrderButton = document.getElementById("newOrderButton");
    elements.orderDetailPanel = document.getElementById("orderDetailPanel");
    elements.orderDetailDialog = document.getElementById("orderDetailDialog");
    elements.orderDetailTitle = document.getElementById("orderDetailTitle");
    elements.closeOrderDetail = document.getElementById("closeOrderDetail");
    elements.orderViewTabs = document.getElementById("orderViewTabs");
    elements.orderDetailTabStatus = document.getElementById("orderDetailTabStatus");
    elements.orderDetailEdit = document.getElementById("orderDetailEdit");
    elements.orderDetailCloseFooter = document.getElementById("orderDetailCloseFooter");
    elements.orderDetailLoadingState = document.getElementById("orderDetailLoadingState");
    elements.orderDetailErrorState = document.getElementById("orderDetailErrorState");
    elements.orderDetailErrorText = document.getElementById("orderDetailErrorText");
    elements.orderDetailContent = document.getElementById("orderDetailContent");
    elements.orderEditPanel = document.getElementById("orderEditPanel");
    elements.orderEditDialog = document.getElementById("orderEditDialog");
    elements.orderEditTitle = document.getElementById("orderEditTitle");
    elements.closeOrderEdit = document.getElementById("closeOrderEdit");
    elements.orderEditTabs = document.getElementById("orderEditTabs");
    elements.orderEditTabStatus = document.getElementById("orderEditTabStatus");
    elements.orderEditTabContent = document.getElementById("orderEditTabContent");
    elements.orderEditMessage = document.getElementById("orderEditMessage");
    elements.saveOrderEdit = document.getElementById("saveOrderEdit");
    elements.closeOrderEditFooter = document.getElementById("closeOrderEditFooter");
    elements.orderShipmentContainer = document.getElementById("orderShipmentContainer");
    elements.manualOrderOverlay = document.getElementById("manualOrderOverlay");
    elements.manualOrderDialog = document.getElementById("manualOrderForm");
    elements.closeManualOrder = document.getElementById("closeManualOrder");
    elements.manualOrderStepper = document.getElementById("manualOrderStepper");
    elements.manualOrderStepStatus = document.getElementById("manualOrderStepStatus");
    elements.manualOrderStepContent = document.getElementById("manualOrderStepContent");
    elements.manualOrderPrevious = document.getElementById("manualOrderPrevious");
    elements.manualOrderNext = document.getElementById("manualOrderNext");
    elements.submitManualOrder = document.getElementById("submitManualOrder");
    elements.manualOrderMessage = document.getElementById("manualOrderMessage");
    elements.customerSearch = document.getElementById("customerSearch");
    elements.refreshCustomers = document.getElementById("refreshCustomers");
    elements.customersStateBadge = document.getElementById("customersStateBadge");
    elements.customersSecureState = document.getElementById("customersSecureState");
    elements.customersLoadingState = document.getElementById("customersLoadingState");
    elements.customersEmptyState = document.getElementById("customersEmptyState");
    elements.customersEmptyText = document.getElementById("customersEmptyText");
    elements.customersErrorState = document.getElementById("customersErrorState");
    elements.customersErrorText = document.getElementById("customersErrorText");
    elements.customersGrid = document.getElementById("customersGrid");
    elements.customersPagination = document.getElementById("customersPagination");
    elements.customersPreviousPage = document.getElementById("customersPreviousPage");
    elements.customersNextPage = document.getElementById("customersNextPage");
    elements.customersPageLabel = document.getElementById("customersPageLabel");
    elements.customerDetailPanel = document.getElementById("customerDetailPanel");
    elements.customerDetailTitle = document.getElementById("customerDetailTitle");
    elements.closeCustomerDetail = document.getElementById("closeCustomerDetail");
    elements.customerDetailContent = document.getElementById("customerDetailContent");
    elements.inventorySearch = document.getElementById("inventorySearch");
    elements.inventoryStatusFilter = document.getElementById("inventoryStatusFilter");
    elements.refreshInventory = document.getElementById("refreshInventory");
    elements.inventoryMessage = document.getElementById("inventoryMessage");
    elements.inventoryStateBadge = document.getElementById("inventoryStateBadge");
    elements.inventorySecureState = document.getElementById("inventorySecureState");
    elements.inventoryLoadingState = document.getElementById("inventoryLoadingState");
    elements.inventoryEmptyState = document.getElementById("inventoryEmptyState");
    elements.inventoryErrorState = document.getElementById("inventoryErrorState");
    elements.inventoryErrorText = document.getElementById("inventoryErrorText");
    elements.inventoryGrid = document.getElementById("inventoryGrid");
    elements.inventorySummary = document.getElementById("inventorySummary");
    elements.inventoryPagination = document.getElementById("inventoryPagination");
    elements.inventoryPreviousPage = document.getElementById("inventoryPreviousPage");
    elements.inventoryNextPage = document.getElementById("inventoryNextPage");
    elements.inventoryPageLabel = document.getElementById("inventoryPageLabel");
    elements.couponSearch = document.getElementById("couponSearch");
    elements.newCouponButton = document.getElementById("newCouponButton");
    elements.refreshCoupons = document.getElementById("refreshCoupons");
    elements.couponEditor = document.getElementById("couponEditor");
    elements.couponEditorTitle = document.getElementById("couponEditorTitle");
    elements.cancelCouponEdit = document.getElementById("cancelCouponEdit");
    elements.couponCode = document.getElementById("couponCode");
    elements.couponType = document.getElementById("couponType");
    elements.couponAmount = document.getElementById("couponAmount");
    elements.couponExpiry = document.getElementById("couponExpiry");
    elements.couponMinAmount = document.getElementById("couponMinAmount");
    elements.couponMaxAmount = document.getElementById("couponMaxAmount");
    elements.couponUsageLimit = document.getElementById("couponUsageLimit");
    elements.couponUsagePerUser = document.getElementById("couponUsagePerUser");
    elements.couponFreeShipping = document.getElementById("couponFreeShipping");
    elements.couponIndividualUse = document.getElementById("couponIndividualUse");
    elements.couponExcludeSaleItems = document.getElementById("couponExcludeSaleItems");
    elements.saveCouponButton = document.getElementById("saveCouponButton");
    elements.couponEditorMessage = document.getElementById("couponEditorMessage");
    elements.couponsStateBadge = document.getElementById("couponsStateBadge");
    elements.couponsSecureState = document.getElementById("couponsSecureState");
    elements.couponsLoadingState = document.getElementById("couponsLoadingState");
    elements.couponsEmptyState = document.getElementById("couponsEmptyState");
    elements.couponsErrorState = document.getElementById("couponsErrorState");
    elements.couponsErrorText = document.getElementById("couponsErrorText");
    elements.couponsGrid = document.getElementById("couponsGrid");
    elements.couponsPagination = document.getElementById("couponsPagination");
    elements.couponsPreviousPage = document.getElementById("couponsPreviousPage");
    elements.couponsNextPage = document.getElementById("couponsNextPage");
    elements.couponsPageLabel = document.getElementById("couponsPageLabel");
    elements.reviewSearch = document.getElementById("reviewSearch");
    elements.reviewStatusFilter = document.getElementById("reviewStatusFilter");
    elements.refreshReviews = document.getElementById("refreshReviews");
    elements.reviewsStateBadge = document.getElementById("reviewsStateBadge");
    elements.reviewsSecureState = document.getElementById("reviewsSecureState");
    elements.reviewsLoadingState = document.getElementById("reviewsLoadingState");
    elements.reviewsEmptyState = document.getElementById("reviewsEmptyState");
    elements.reviewsErrorState = document.getElementById("reviewsErrorState");
    elements.reviewsErrorText = document.getElementById("reviewsErrorText");
    elements.reviewsGrid = document.getElementById("reviewsGrid");
    elements.reviewsPagination = document.getElementById("reviewsPagination");
    elements.reviewsPreviousPage = document.getElementById("reviewsPreviousPage");
    elements.reviewsNextPage = document.getElementById("reviewsNextPage");
    elements.reviewsPageLabel = document.getElementById("reviewsPageLabel");
    elements.securityStateBadge = document.getElementById("securityStateBadge");
    elements.refreshDevices = document.getElementById("refreshDevices");
    elements.revokeOtherDevices = document.getElementById("revokeOtherDevices");
    elements.devicesSecureState = document.getElementById("devicesSecureState");
    elements.devicesLoadingState = document.getElementById("devicesLoadingState");
    elements.devicesEmptyState = document.getElementById("devicesEmptyState");
    elements.devicesEmptyText = document.getElementById("devicesEmptyText");
    elements.devicesErrorState = document.getElementById("devicesErrorState");
    elements.devicesErrorText = document.getElementById("devicesErrorText");
    elements.devicesGrid = document.getElementById("devicesGrid");
    elements.sessionAlertPanel = document.getElementById("sessionAlertPanel");
    elements.auditStateBadge = document.getElementById("auditStateBadge");
    elements.auditEventFilter = document.getElementById("auditEventFilter");
    elements.refreshAudit = document.getElementById("refreshAudit");
    elements.auditPreviousPage = document.getElementById("auditPreviousPage");
    elements.auditNextPage = document.getElementById("auditNextPage");
    elements.auditSecureState = document.getElementById("auditSecureState");
    elements.auditLoadingState = document.getElementById("auditLoadingState");
    elements.auditEmptyState = document.getElementById("auditEmptyState");
    elements.auditEmptyText = document.getElementById("auditEmptyText");
    elements.auditErrorState = document.getElementById("auditErrorState");
    elements.auditErrorText = document.getElementById("auditErrorText");
    elements.auditGrid = document.getElementById("auditGrid");

    [
      elements.productRegularPrice,
      elements.productSalePrice,
      elements.productStockQuantity,
      elements.bulkPriceAmount,
      elements.variationRegularPrice,
      elements.variationSalePrice,
      elements.variationStockQuantity,
      elements.productWeight,
      elements.productLength,
      elements.productWidth,
      elements.productHeight
    ].forEach(bindNumericInput);
  }

  // [UI: DISPLAY HELPERS] قالب‌بندی متن، عدد، تاریخ و ورودی‌های قابل‌نمایش.
  function isHexColor(value) {
    return typeof value === "string" && /^#[0-9a-f]{3,8}$/i.test(value.trim());
  }

  function safeColor(value, fallback) {
    return isHexColor(value) ? value.trim() : fallback;
  }

  function safeText(value, fallback, maxLength) {
    if (typeof value !== "string") {
      return fallback;
    }

    var normalized = value.trim().replace(/[\u0000-\u001f\u007f]/g, "");
    if (!normalized || normalized.length > maxLength) {
      return fallback;
    }

    return normalized;
  }

  // Search values can come from Persian/Arabic keyboards, copied text, or
  // WooCommerce fields with invisible formatting marks. Normalize the value
  // once so every search box treats equivalent text and digits identically.
  function normalizeSearchText(value) {
    var normalized = String(value === null || typeof value === "undefined" ? "" : value);
    if (typeof normalized.normalize === "function") {
      normalized = normalized.normalize("NFKC");
    }

    return toEnglishDigits(normalized)
      .replace(/[يى]/g, "ی")
      .replace(/ك/g, "ک")
      .replace(/[ۀة]/g, "ه")
      .replace(/[\u064B-\u065F\u0670\u06D6-\u06ED]/g, "")
      .replace(/[\u0640\u200C\u200D\u200E\u200F\uFEFF]/g, "")
      .replace(/\s+/g, " ")
      .trim()
      .replace(/^#+/, "")
      .toLocaleLowerCase();
  }

  function searchTextIncludes(values, query) {
    var normalizedQuery = normalizeSearchText(query);
    if (!normalizedQuery) {
      return true;
    }

    var list = Array.isArray(values) ? values : [values];
    var haystack = list.map(function (value) {
      return normalizeSearchText(value);
    }).filter(Boolean).join(" ");
    return haystack.indexOf(normalizedQuery) !== -1;
  }

  function toPersianDigits(value) {
    return String(value === null || typeof value === "undefined" ? "" : value).replace(/[0-9]/g, function (digit) {
      return PERSIAN_DIGITS[Number(digit)];
    });
  }

  function toEnglishDigits(value) {
    return String(value === null || typeof value === "undefined" ? "" : value)
      .replace(/[۰-۹]/g, function (digit) {
        return String("۰۱۲۳۴۵۶۷۸۹".indexOf(digit));
      })
      .replace(/[٠-٩]/g, function (digit) {
        return String("٠١٢٣٤٥٦٧٨٩".indexOf(digit));
      });
  }

  function formatDisplayNumber(value, options) {
    var raw = toEnglishDigits(value).trim();
    var normalized = raw.replace(/[٬,\s]/g, "").replace(/٫/g, ".");
    var settings = options && typeof options === "object" ? options : {};
    var fraction = normalized.indexOf(".") === -1 ? 0 : Math.min(6, normalized.split(".")[1].length);

    if (!/^[+-]?(?:\d+|\d*\.\d+)$/.test(normalized)) {
      return toPersianDigits(value);
    }

    var number = Number(normalized);
    if (!isFinite(number)) {
      return toPersianDigits(value);
    }

    if (typeof Intl !== "undefined" && Intl.NumberFormat) {
      try {
        return new Intl.NumberFormat(PERSIAN_NUMBER_LOCALE, {
          useGrouping: settings.group !== false,
          maximumFractionDigits: fraction
        }).format(number);
      } catch (error) {
        // Fall back to digit translation on older embedded browsers.
      }
    }

    return toPersianDigits(raw);
  }

  function formatDisplayId(value) {
    return toPersianDigits(value);
  }

  function formatOrderNumber(value) {
    var raw = String(value === null || typeof value === "undefined" ? "" : value).trim().replace(/^#+/, "");
    return raw ? "#" + formatDisplayId(raw) : "—";
  }

  function formatCurrencyLabel(value) {
    var raw = String(value === null || typeof value === "undefined" ? "" : value).trim();
    var normalized = raw.toUpperCase();

    if (normalized === "IRT") {
      return "تومان";
    }
    if (normalized === "IRR") {
      return "ریال";
    }

    return toPersianDigits(raw);
  }

  function formatDisplayAmount(value) {
    var raw = String(value === null || typeof value === "undefined" ? "" : value).trim();
    var match = toEnglishDigits(raw).match(/^([+-]?(?:\d[\d٬,]*)(?:\.\d+)?)(.*)$/);
    if (!match) {
      return toPersianDigits(raw);
    }

    var suffix = formatCurrencyLabel(match[2]);
    return formatDisplayNumber(match[1]) + (suffix ? " " + suffix : "");
  }

  function formatStoreAmount(value) {
    var currency = state.config && state.config.currency ? state.config.currency.label : "تومان";
    var raw = String(value === null || typeof value === "undefined" ? "" : value).trim();
    return formatDisplayAmount(raw + (currency && raw ? " " + currency : ""));
  }

  function normalizeNumericInput(value) {
    return toEnglishDigits(value).replace(/[٬,\s]/g, "").replace(/٫/g, ".").replace(/[^0-9.+-]/g, "");
  }

  function formatNumericInput(value) {
    var normalized = normalizeNumericInput(value);
    return normalized ? formatDisplayNumber(normalized) : "";
  }

  function bindNumericInput(input) {
    if (!input || input.dataset.persianDigitsBound === "1") {
      return;
    }

    input.dataset.persianDigitsBound = "1";
    input.addEventListener("blur", function () {
      input.value = formatNumericInput(input.value);
    });
  }

  var JALALI_MONTH_NAMES = [
    "فروردین", "اردیبهشت", "خرداد", "تیر", "مرداد", "شهریور",
    "مهر", "آبان", "آذر", "دی", "بهمن", "اسفند"
  ];
  var JALALI_WEEKDAY_NAMES = ["ش", "ی", "د", "س", "چ", "پ", "ج"];
  var activePersianDatePicker = null;
  var persianDatePickerDocumentBound = false;

  function padDatePart(value) {
    return ("0" + String(value)).slice(-2);
  }

  function gregorianToJalali(gy, gm, gd) {
    var gregorianDays = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    var gy2 = gy - 1600;
    var gm2 = gm - 1;
    var gd2 = gd - 1;
    var dayNumber = 365 * gy2 + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100) + Math.floor((gy2 + 399) / 400);
    var index;

    for (index = 0; index < gm2; index += 1) {
      dayNumber += gregorianDays[index];
    }
    if (gm > 2 && ((gy % 4 === 0 && gy % 100 !== 0) || gy % 400 === 0)) {
      dayNumber += 1;
    }
    dayNumber += gd2;

    var jalaliDayNumber = dayNumber - 79;
    var jalaliYear = 979 + 33 * Math.floor(jalaliDayNumber / 12053);
    jalaliDayNumber %= 12053;
    jalaliYear += 4 * Math.floor(jalaliDayNumber / 1461);
    jalaliDayNumber %= 1461;
    if (jalaliDayNumber >= 366) {
      jalaliYear += Math.floor((jalaliDayNumber - 1) / 365);
      jalaliDayNumber = (jalaliDayNumber - 1) % 365;
    }

    var jalaliMonth = jalaliDayNumber < 186 ? 1 + Math.floor(jalaliDayNumber / 31) : 7 + Math.floor((jalaliDayNumber - 186) / 30);
    var jalaliDate = 1 + (jalaliDayNumber < 186 ? jalaliDayNumber % 31 : (jalaliDayNumber - 186) % 30);
    return [jalaliYear, jalaliMonth, jalaliDate];
  }

  function jalaliToGregorian(jy, jm, jd) {
    var jalaliYear = jy - 979;
    var dayNumber = 365 * jalaliYear + Math.floor(jalaliYear / 33) * 8 + Math.floor((jalaliYear % 33 + 3) / 4);
    var index;

    for (index = 1; index < jm; index += 1) {
      dayNumber += index <= 6 ? 31 : 30;
    }
    dayNumber += jd - 1;

    var gregorianDayNumber = dayNumber + 79;
    var gy = 1600 + 400 * Math.floor(gregorianDayNumber / 146097);
    gregorianDayNumber %= 146097;
    var leap = true;
    if (gregorianDayNumber >= 36525) {
      gregorianDayNumber -= 1;
      gy += 100 * Math.floor(gregorianDayNumber / 36524);
      gregorianDayNumber %= 36524;
      if (gregorianDayNumber >= 365) {
        gregorianDayNumber += 1;
      } else {
        leap = false;
      }
    }
    gy += 4 * Math.floor(gregorianDayNumber / 1461);
    gregorianDayNumber %= 1461;
    if (gregorianDayNumber >= 366) {
      leap = false;
      gregorianDayNumber -= 1;
      gy += Math.floor(gregorianDayNumber / 365);
      gregorianDayNumber %= 365;
    }

    var gregorianDays = [0, 31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    var gm = 0;
    while (gm < 13 && gregorianDayNumber >= gregorianDays[gm]) {
      gregorianDayNumber -= gregorianDays[gm];
      gm += 1;
    }
    return [gy, gm, gregorianDayNumber + 1];
  }

  function jalaliYearLength(jy) {
    var start = jalaliToGregorian(jy, 1, 1);
    var next = jalaliToGregorian(jy + 1, 1, 1);
    return Math.round((Date.UTC(next[0], next[1] - 1, next[2]) - Date.UTC(start[0], start[1] - 1, start[2])) / 86400000);
  }

  function jalaliMonthLength(jy, jm) {
    if (jm <= 6) return 31;
    if (jm <= 11) return 30;
    return jalaliYearLength(jy) === 366 ? 30 : 29;
  }

  function parseDateParts(value) {
    var raw = String(value === null || typeof value === "undefined" ? "" : value).trim();
    var match = raw.match(/^(\d{4})-(\d{2})-(\d{2})(?:[T\s](\d{2}):(\d{2})(?::\d{2}(?:\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?$/);
    var date;

    if (!raw) {
      return null;
    }
    if (match && match[6]) {
      date = new Date(raw.replace(" ", "T"));
      if (isNaN(date.getTime())) return null;
      return {
        year: date.getFullYear(),
        month: date.getMonth() + 1,
        day: date.getDate(),
        hour: date.getHours(),
        minute: date.getMinutes()
      };
    }
    if (match) {
      return {
        year: Number(match[1]),
        month: Number(match[2]),
        day: Number(match[3]),
        hour: typeof match[4] === "string" ? Number(match[4]) : null,
        minute: typeof match[5] === "string" ? Number(match[5]) : null
      };
    }

    date = new Date(raw.replace(" ", "T"));
    if (isNaN(date.getTime())) return null;
    return {
      year: date.getFullYear(),
      month: date.getMonth() + 1,
      day: date.getDate(),
      hour: date.getHours(),
      minute: date.getMinutes()
    };
  }

  function formatDisplayDate(value, includeTime) {
    var parts = parseDateParts(value);
    if (!parts) {
      return toPersianDigits(value);
    }

    var jalali = gregorianToJalali(parts.year, parts.month, parts.day);
    var formatted = toPersianDigits(String(jalali[2])) + " " + JALALI_MONTH_NAMES[jalali[1] - 1] + " " + toPersianDigits(String(jalali[0]));
    if (includeTime && parts.hour !== null && parts.minute !== null) {
      formatted += "، " + toPersianDigits(padDatePart(parts.hour) + ":" + padDatePart(parts.minute));
    }
    return formatted;
  }

  function formatDisplayText(value) {
    var raw = String(value === null || typeof value === "undefined" ? "" : value);
    var datePattern = /\b\d{4}-\d{2}-\d{2}(?:[T\s]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?\b/g;
    return raw.replace(datePattern, function (candidate) {
      var hasTime = /[T\s]\d{2}:\d{2}/.test(candidate);
      var formatted = formatDisplayDate(candidate, hasTime);
      return formatted === candidate ? candidate : formatted;
    }).replace(/[0-9]/g, function (digit) {
      return PERSIAN_DIGITS[Number(digit)];
    });
  }

  function datePickerParts(value, type) {
    var raw = toEnglishDigits(String(value === null || typeof value === "undefined" ? "" : value)).trim();
    var jalaliMatch = raw.match(/^(\d{4})\/(\d{1,2})\/(\d{1,2})(?:[\s،]+(\d{1,2}):(\d{1,2}))?$/);
    var isoMatch = raw.match(/^(\d{4})-(\d{1,2})-(\d{1,2})(?:T(\d{1,2}):(\d{1,2})(?::\d{2})?(Z|[+-]\d{2}:?\d{2})?)?$/);
    var gregorian;

    if (!raw) {
      return null;
    }
    if (jalaliMatch) {
      if (Number(jalaliMatch[2]) < 1 || Number(jalaliMatch[2]) > 12 || Number(jalaliMatch[3]) < 1 || Number(jalaliMatch[3]) > jalaliMonthLength(Number(jalaliMatch[1]), Number(jalaliMatch[2]))) {
        return null;
      }
      gregorian = jalaliToGregorian(Number(jalaliMatch[1]), Number(jalaliMatch[2]), Number(jalaliMatch[3]));
      return {
        year: gregorian[0],
        month: gregorian[1],
        day: gregorian[2],
        hour: jalaliMatch[4] ? Math.min(23, Math.max(0, Number(jalaliMatch[4]))) : 0,
        minute: jalaliMatch[5] ? Math.min(59, Math.max(0, Number(jalaliMatch[5]))) : 0
      };
    }
    if (isoMatch) {
      if (isoMatch[6]) {
        var absolute = new Date(raw);
        if (isNaN(absolute.getTime())) return null;
        return {
          year: absolute.getFullYear(),
          month: absolute.getMonth() + 1,
          day: absolute.getDate(),
          hour: absolute.getHours(),
          minute: absolute.getMinutes()
        };
      }
      return {
        year: Number(isoMatch[1]),
        month: Number(isoMatch[2]),
        day: Number(isoMatch[3]),
        hour: isoMatch[4] ? Math.min(23, Math.max(0, Number(isoMatch[4]))) : 0,
        minute: isoMatch[5] ? Math.min(59, Math.max(0, Number(isoMatch[5]))) : 0
      };
    }

    var parsed = parseDateParts(raw);
    if (!parsed) return null;
    return {
      year: parsed.year,
      month: parsed.month,
      day: parsed.day,
      hour: parsed.hour === null ? 0 : parsed.hour,
      minute: parsed.minute === null ? 0 : parsed.minute
    };
  }

  function datePickerIsoValue(parts, type) {
    if (!parts) return "";
    var date = String(parts.year) + "-" + padDatePart(parts.month) + "-" + padDatePart(parts.day);
    if (type === "datetime-local") {
      return date + "T" + padDatePart(parts.hour || 0) + ":" + padDatePart(parts.minute || 0);
    }
    return date;
  }

  function datePickerVisibleValue(value, type) {
    var parts = datePickerParts(value, type);
    if (!parts) return "";
    var jalali = gregorianToJalali(parts.year, parts.month, parts.day);
    var visible = toPersianDigits(String(jalali[0]) + "/" + padDatePart(jalali[1]) + "/" + padDatePart(jalali[2]));
    if (type === "datetime-local") {
      visible += "، " + toPersianDigits(padDatePart(parts.hour || 0) + ":" + padDatePart(parts.minute || 0));
    }
    return visible;
  }

  function getPersianDateInputValue(input) {
    if (!input) return "";
    if (input.dataset && input.dataset.isoValue) {
      return input.dataset.isoValue;
    }
    return typeof input.value === "string" ? input.value.trim() : "";
  }

  function setPersianDateInputValue(input, value) {
    if (!input) return;
    if (input._persianDatePicker && typeof input._persianDatePicker.setValue === "function") {
      input._persianDatePicker.setValue(value || "");
      return;
    }
    var type = input.dataset && input.dataset.persianDateType ? input.dataset.persianDateType : input.type;
    var parsed = datePickerParts(value, type);
    input.dataset.isoValue = parsed ? datePickerIsoValue(parsed, type) : "";
    input.value = parsed ? datePickerVisibleValue(input.dataset.isoValue, type) : "";
  }

  function setupPersianDateInput(input) {
    if (!input || !input.dataset || input.dataset.persianDateBound === "1") {
      return input;
    }

    var type = input.dataset.persianDateType || (input.type === "datetime-local" ? "datetime-local" : "date");
    if (type !== "date" && type !== "datetime-local") {
      return input;
    }

    var initialValue = input.dataset.isoValue || input.value || "";
    input.dataset.persianDateType = type;
    input.dataset.persianDateBound = "1";
    input.type = "text";
    input.readOnly = true;
    input.autocomplete = "off";
    input.inputMode = "none";
    input.setAttribute("aria-haspopup", "dialog");
    input.setAttribute("aria-expanded", "false");
    input.setAttribute("aria-label", type === "datetime-local" ? "انتخاب تاریخ و زمان شمسی" : "انتخاب تاریخ شمسی");

    var wrapper = document.createElement("div");
    wrapper.className = "persian-date-picker";
    if (input.parentNode) {
      input.parentNode.insertBefore(wrapper, input);
    }
    wrapper.appendChild(input);

    var trigger = document.createElement("button");
    trigger.type = "button";
    trigger.className = "persian-date-trigger";
    trigger.setAttribute("aria-label", type === "datetime-local" ? "باز کردن تقویم شمسی و انتخاب زمان" : "باز کردن تقویم شمسی");
    trigger.setAttribute("aria-haspopup", "dialog");
    trigger.innerHTML = '<svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="5" width="16" height="15" rx="2"></rect><path d="M8 3v4M16 3v4M4 10h16"></path></svg>';
    wrapper.appendChild(trigger);

    var popover = document.createElement("div");
    var popoverId = "persianCalendar-" + String(Date.now()) + String(Math.floor(Math.random() * 10000));
    popover.className = "persian-calendar-popover";
    popover.id = popoverId;
    popover.hidden = true;
    popover.setAttribute("role", "dialog");
    popover.setAttribute("aria-label", type === "datetime-local" ? "تقویم شمسی و انتخاب زمان" : "تقویم شمسی");
    input.setAttribute("aria-controls", popoverId);

    var header = document.createElement("div");
    header.className = "persian-calendar-header";
    var previousMonth = document.createElement("button");
    previousMonth.type = "button";
    previousMonth.className = "persian-calendar-nav";
    previousMonth.textContent = "‹";
    previousMonth.setAttribute("aria-label", "ماه قبل");
    var monthTitle = document.createElement("strong");
    monthTitle.className = "persian-calendar-month-title";
    var nextMonth = document.createElement("button");
    nextMonth.type = "button";
    nextMonth.className = "persian-calendar-nav";
    nextMonth.textContent = "›";
    nextMonth.setAttribute("aria-label", "ماه بعد");
    header.appendChild(previousMonth);
    header.appendChild(monthTitle);
    header.appendChild(nextMonth);
    popover.appendChild(header);

    var weekdays = document.createElement("div");
    weekdays.className = "persian-calendar-weekdays";
    weekdays.setAttribute("aria-hidden", "true");
    JALALI_WEEKDAY_NAMES.forEach(function (weekday) {
      var item = document.createElement("span");
      item.textContent = weekday;
      weekdays.appendChild(item);
    });
    popover.appendChild(weekdays);

    var grid = document.createElement("div");
    grid.className = "persian-calendar-grid";
    grid.setAttribute("role", "grid");
    popover.appendChild(grid);

    var timeControls = null;
    var hourInput = null;
    var minuteInput = null;
    if (type === "datetime-local") {
      timeControls = document.createElement("div");
      timeControls.className = "persian-calendar-time";
      var hourLabel = document.createElement("label");
      hourLabel.textContent = "ساعت";
      hourInput = document.createElement("input");
      hourInput.type = "text";
      hourInput.inputMode = "numeric";
      hourInput.maxLength = 2;
      hourInput.dir = "ltr";
      hourInput.setAttribute("aria-label", "ساعت");
      hourLabel.appendChild(hourInput);
      var minuteLabel = document.createElement("label");
      minuteLabel.textContent = "دقیقه";
      minuteInput = document.createElement("input");
      minuteInput.type = "text";
      minuteInput.inputMode = "numeric";
      minuteInput.maxLength = 2;
      minuteInput.dir = "ltr";
      minuteInput.setAttribute("aria-label", "دقیقه");
      minuteLabel.appendChild(minuteInput);
      timeControls.appendChild(hourLabel);
      timeControls.appendChild(minuteLabel);
      popover.appendChild(timeControls);
    }

    var actions = document.createElement("div");
    actions.className = "persian-calendar-actions";
    var todayButton = document.createElement("button");
    todayButton.type = "button";
    todayButton.className = "secondary-button compact-button";
    todayButton.textContent = "امروز";
    var clearButton = document.createElement("button");
    clearButton.type = "button";
    clearButton.className = "secondary-button compact-button";
    clearButton.textContent = "پاک‌کردن";
    var doneButton = null;
    if (type === "datetime-local") {
      doneButton = document.createElement("button");
      doneButton.type = "button";
      doneButton.className = "primary-button compact-button";
      doneButton.textContent = "تأیید";
    }
    actions.appendChild(todayButton);
    actions.appendChild(clearButton);
    if (doneButton) actions.appendChild(doneButton);
    popover.appendChild(actions);
    wrapper.appendChild(popover);

    var picker = {
      type: type,
      input: input,
      wrapper: wrapper,
      popover: popover,
      viewYear: 0,
      viewMonth: 0,
      selected: null,
      isoValue: "",
      setValue: function (value) {
        var parsed = datePickerParts(value, type);
        if (!parsed) {
          picker.isoValue = "";
          picker.selected = null;
          input.dataset.isoValue = "";
          input.value = "";
          if (!popover.hidden) picker.render();
          return;
        }
        picker.isoValue = datePickerIsoValue(parsed, type);
        picker.selected = parsed;
        var jalali = gregorianToJalali(parsed.year, parsed.month, parsed.day);
        picker.viewYear = jalali[0];
        picker.viewMonth = jalali[1];
        input.dataset.isoValue = picker.isoValue;
        input.value = datePickerVisibleValue(picker.isoValue, type);
        if (!popover.hidden) picker.render();
      },
      render: function () {
        if (!picker.viewYear || !picker.viewMonth) {
          var today = new Date();
          var todayJalali = gregorianToJalali(today.getFullYear(), today.getMonth() + 1, today.getDate());
          picker.viewYear = todayJalali[0];
          picker.viewMonth = todayJalali[1];
        }
        monthTitle.textContent = JALALI_MONTH_NAMES[picker.viewMonth - 1] + " " + toPersianDigits(String(picker.viewYear));
        while (grid.firstChild) grid.removeChild(grid.firstChild);
        var firstGregorian = jalaliToGregorian(picker.viewYear, picker.viewMonth, 1);
        var firstColumn = (new Date(firstGregorian[0], firstGregorian[1] - 1, firstGregorian[2]).getDay() + 1) % 7;
        var blankIndex;
        for (blankIndex = 0; blankIndex < firstColumn; blankIndex += 1) {
          var blank = document.createElement("span");
          blank.className = "persian-calendar-empty";
          blank.setAttribute("aria-hidden", "true");
          grid.appendChild(blank);
        }
        var day;
        var monthDays = jalaliMonthLength(picker.viewYear, picker.viewMonth);
        for (day = 1; day <= monthDays; day += 1) {
          var dayButton = document.createElement("button");
          var dayGregorian = jalaliToGregorian(picker.viewYear, picker.viewMonth, day);
          dayButton.type = "button";
          dayButton.className = "persian-calendar-day";
          dayButton.textContent = toPersianDigits(String(day));
          dayButton.setAttribute("role", "gridcell");
          dayButton.setAttribute("aria-label", toPersianDigits(String(day)) + " " + JALALI_MONTH_NAMES[picker.viewMonth - 1] + " " + toPersianDigits(String(picker.viewYear)));
          if (picker.selected && picker.selected.year === dayGregorian[0] && picker.selected.month === dayGregorian[1] && picker.selected.day === dayGregorian[2]) {
            dayButton.classList.add("is-selected");
            dayButton.setAttribute("aria-current", "date");
          }
          dayButton.addEventListener("click", function (event) {
            var selectedDay = Number(toEnglishDigits(event.currentTarget.textContent));
            var selectedGregorian = jalaliToGregorian(picker.viewYear, picker.viewMonth, selectedDay);
            var hour = picker.selected ? picker.selected.hour : 0;
            var minute = picker.selected ? picker.selected.minute : 0;
            picker.setValue(datePickerIsoValue({ year: selectedGregorian[0], month: selectedGregorian[1], day: selectedGregorian[2], hour: hour, minute: minute }, type));
            if (type === "date") {
              picker.close(true);
            } else if (hourInput) {
              hourInput.focus();
            }
          });
          grid.appendChild(dayButton);
        }
        if (type === "datetime-local" && picker.selected) {
          hourInput.value = toPersianDigits(padDatePart(picker.selected.hour || 0));
          minuteInput.value = toPersianDigits(padDatePart(picker.selected.minute || 0));
        }
      },
      open: function () {
        if (activePersianDatePicker && activePersianDatePicker !== picker) {
          activePersianDatePicker.close(false);
        }
        if (!picker.selected) {
          var now = new Date();
          var nowJalali = gregorianToJalali(now.getFullYear(), now.getMonth() + 1, now.getDate());
          picker.viewYear = nowJalali[0];
          picker.viewMonth = nowJalali[1];
        }
        picker.render();
        popover.hidden = false;
        activePersianDatePicker = picker;
        input.setAttribute("aria-expanded", "true");
        trigger.setAttribute("aria-expanded", "true");
      },
      close: function (restoreFocus) {
        popover.hidden = true;
        if (activePersianDatePicker === picker) activePersianDatePicker = null;
        input.setAttribute("aria-expanded", "false");
        trigger.setAttribute("aria-expanded", "false");
        if (restoreFocus) input.focus();
      },
      clear: function () {
        picker.setValue("");
        picker.close(true);
      },
      chooseToday: function () {
        var now = new Date();
        picker.setValue(datePickerIsoValue({ year: now.getFullYear(), month: now.getMonth() + 1, day: now.getDate(), hour: now.getHours(), minute: now.getMinutes() }, type));
        if (type === "date") picker.close(true);
      },
      applyTime: function () {
        if (type !== "datetime-local" || !picker.selected) return;
        var hour = Math.min(23, Math.max(0, Number(toEnglishDigits(hourInput.value)) || 0));
        var minute = Math.min(59, Math.max(0, Number(toEnglishDigits(minuteInput.value)) || 0));
        picker.setValue(datePickerIsoValue({ year: picker.selected.year, month: picker.selected.month, day: picker.selected.day, hour: hour, minute: minute }, type));
        picker.close(true);
      }
    };

    input._persianDatePicker = picker;
    previousMonth.addEventListener("click", function () {
      picker.viewMonth -= 1;
      if (picker.viewMonth < 1) { picker.viewMonth = 12; picker.viewYear -= 1; }
      picker.render();
    });
    nextMonth.addEventListener("click", function () {
      picker.viewMonth += 1;
      if (picker.viewMonth > 12) { picker.viewMonth = 1; picker.viewYear += 1; }
      picker.render();
    });
    input.addEventListener("click", function () { picker.open(); });
    input.addEventListener("keydown", function (event) {
      if (event.key === "Enter" || event.key === " " || event.key === "ArrowDown") {
        event.preventDefault();
        picker.open();
      } else if (event.key === "Escape") {
        picker.close(false);
      }
    });
    trigger.addEventListener("click", function () { picker.open(); });
    todayButton.addEventListener("click", picker.chooseToday);
    clearButton.addEventListener("click", picker.clear);
    if (doneButton) doneButton.addEventListener("click", picker.applyTime);
    if (hourInput) {
      hourInput.addEventListener("blur", function () { hourInput.value = toPersianDigits(padDatePart(Math.min(23, Math.max(0, Number(toEnglishDigits(hourInput.value)) || 0)))); });
      minuteInput.addEventListener("blur", function () { minuteInput.value = toPersianDigits(padDatePart(Math.min(59, Math.max(0, Number(toEnglishDigits(minuteInput.value)) || 0)))); });
    }
    if (!persianDatePickerDocumentBound) {
      document.addEventListener("mousedown", function (event) {
        if (activePersianDatePicker && !activePersianDatePicker.wrapper.contains(event.target)) {
          activePersianDatePicker.close(false);
        }
      });
      persianDatePickerDocumentBound = true;
    }

    picker.setValue(initialValue);
    return input;
  }

  function initializePersianDatePickers(rootElement) {
    var rootNode = rootElement || document;
    Array.prototype.forEach.call(rootNode.querySelectorAll("[data-persian-date-type]"), setupPersianDateInput);
  }

  // [AUTH/CONFIG] نرمال‌سازی کاربر و تنظیمات برند/API قبل از اعمال روی UI.
  function normalizeUserScopes(value) {
    var source = value && typeof value === "object" ? value : {};
    var normalized = {};

    Object.keys(source).forEach(function (group) {
      if (!/^[a-z][a-z0-9_]{0,40}$/i.test(group) || !source[group] || typeof source[group] !== "object") {
        return;
      }
      normalized[group] = {};
      Object.keys(source[group]).forEach(function (scope) {
        if (/^[a-z][a-z0-9_]{0,40}$/i.test(scope) && source[group][scope]) {
          normalized[group][scope] = true;
        }
      });
    });

    return normalized;
  }

  function userHasScope(scope) {
    var parts = String(scope || "").split(".");
    return state.authenticated && parts.length === 2 && state.user.scopes && state.user.scopes[parts[0]] && Boolean(state.user.scopes[parts[0]][parts[1]]);
  }

  function hasAnalyticsAccess() {
    return Boolean(state.config && state.config.analytics && state.config.analytics.enabled) && userHasScope("analytics.read");
  }

  function setCurrentUser(value, scopes) {
    var source = value && typeof value === "object" ? value : {};
    var name = safeText(source.display_name || source.login, "مدیر فروشگاه", 80);
    state.user.displayName = name;
    state.user.role = safeText(source.role_label, "مدیریت فروشگاه", 60);
    state.user.scopes = normalizeUserScopes(scopes || source.scopes);

    if (elements.topbarUserName) {
      elements.topbarUserName.textContent = formatDisplayText(name);
    }
    if (elements.topbarUserRole) {
      elements.topbarUserRole.textContent = formatDisplayText(state.user.role);
    }
    if (elements.topbarUserAvatar) {
      elements.topbarUserAvatar.textContent = formatDisplayText(name.charAt(0) || "م");
    }
  }

  function siteLabel(value) {
    try {
      return new URL(value).host || "سایت فروشگاه";
    } catch (error) {
      return "سایت فروشگاه";
    }
  }

  function safeAssetUrl(value) {
    if (typeof value !== "string" || !value.trim()) {
      return "";
    }

    try {
      var url = new URL(value.trim(), window.location.origin);
      return url.origin === window.location.origin ? url.toString() : "";
    } catch (error) {
      return "";
    }
  }

  // [UI: SEMANTIC HOOKS] کلاس‌های قابل‌ردیابی برای audit و theme مستقل از
  // کلاس‌های legacy اعمال می‌شوند؛ id، data-state و نقش‌های accessibility دست‌نخورده می‌مانند.
  function applySemanticUiHooks(scope) {
    var rootNode = scope && (scope.nodeType === 1 || scope.nodeType === 9) ? scope : document;
    var buttons = [];
    var stateNodes = [];
    var alertNodes = [];

    if (rootNode.nodeType === 1 && rootNode.matches) {
      if (rootNode.matches("button")) {
        buttons.push(rootNode);
      }
      if (rootNode.matches("[data-state]")) {
        stateNodes.push(rootNode);
      }
      if (rootNode.matches("[role=alert], [data-alert], .secure-note, .app-update-banner")) {
        alertNodes.push(rootNode);
      }
    }

    Array.prototype.forEach.call(rootNode.querySelectorAll("button"), function (button) {
      buttons.push(button);
    });
    Array.prototype.forEach.call(rootNode.querySelectorAll("[data-state]"), function (node) {
      stateNodes.push(node);
    });
    Array.prototype.forEach.call(rootNode.querySelectorAll("[role=alert], [data-alert], .secure-note, .app-update-banner"), function (node) {
      alertNodes.push(node);
    });

    buttons.forEach(function (button) {
      button.classList.add("fm-button");
      if (button.classList.contains("primary-button")) {
        button.classList.add("fm-button--primary");
      } else if (button.classList.contains("danger-button")) {
        button.classList.add("fm-button--danger");
      } else if (button.classList.contains("danger-outline-button")) {
        button.classList.add("fm-button--danger-quiet");
      } else if (button.classList.contains("quick-action")) {
        button.classList.add("fm-button--quick-action");
      } else if (button.classList.contains("text-link-button")) {
        button.classList.add("fm-button--link");
      } else if (button.classList.contains("secondary-button") || button.classList.contains("connection-control")) {
        button.classList.add("fm-button--secondary");
      }

      if (button.classList.contains("topbar-icon-button") || button.classList.contains("mobile-menu-button") || button.classList.contains("icon-button") || button.classList.contains("icon-refresh-button")) {
        button.classList.add("fm-button--icon");
      }
    });

    function syncModifier(node, prefix, value) {
      Array.prototype.forEach.call(node.classList, function (className) {
        if (className.indexOf(prefix) === 0) {
          node.classList.remove(className);
        }
      });
      if (value) {
        node.classList.add(prefix + value);
      }
    }

    stateNodes.forEach(function (node) {
      var stateName = String(node.getAttribute("data-state") || "").toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "");
      node.classList.add("fm-state");
      syncModifier(node, "fm-state--", stateName);
    });

    alertNodes.forEach(function (node) {
      var stateName = String(node.getAttribute("data-state") || "").toLowerCase();
      var modifier = "info";
      node.classList.add("fm-alert");
      if (node.classList.contains("products-error-state") || node.classList.contains("product-view-error-state") || node.classList.contains("error-state") || stateName === "error") {
        modifier = "error";
      } else if (node.classList.contains("session-alert-panel") || node.classList.contains("inventory-warning-text") || node.classList.contains("warning-state") || stateName === "warning") {
        modifier = "warning";
      } else if (node.classList.contains("shipment-success-note") || node.classList.contains("success-state") || stateName === "ready" || stateName === "connected") {
        modifier = "success";
      }
      syncModifier(node, "fm-alert--", modifier);
    });
  }

  function setupSemanticUiHooks() {
    applySemanticUiHooks(document);
    if (!elements.appMain || !window.MutationObserver) {
      return;
    }

    var observer = new MutationObserver(function (records) {
      var shouldSync = records.some(function (record) {
        return record.type === "childList" || record.type === "attributes";
      });
      if (shouldSync) {
        applySemanticUiHooks(elements.appMain);
      }
    });
    observer.observe(elements.appMain, {
      childList: true,
      subtree: true,
      attributes: true,
      attributeFilter: ["data-state", "aria-invalid"]
    });
  }

  function safeApiUrl(value) {
    var normalized = safeAssetUrl(value);
    return normalized && /^https?:/i.test(normalized) ? normalized : "";
  }

  function safeExternalHttpsUrl(value) {
    if (typeof value !== "string" || !value.trim()) {
      return "";
    }

    try {
      var url = new URL(value.trim());
      return url.protocol === "https:" && !url.username && !url.password ? url.toString() : "";
    } catch (error) {
      return "";
    }
  }

  function normalizeConfig(payload) {
    var source = payload && typeof payload === "object" ? payload : {};
    var sourceBrand = source.brand && typeof source.brand === "object" ? source.brand : {};
    var themeConfig = source.theme_config && typeof source.theme_config === "object" ? source.theme_config : {};
    var sourceColors = themeConfig.colors && typeof themeConfig.colors === "object" ? themeConfig.colors : (source.colors && typeof source.colors === "object" ? source.colors : {});
    var sourceBranding = source.branding && typeof source.branding === "object" ? source.branding : {};
    var sourceApi = source.api && typeof source.api === "object" ? source.api : {};
    var sourceEndpoints = source.endpoints && typeof source.endpoints === "object" ? source.endpoints : {};
    var sourceAnalytics = source.analytics && typeof source.analytics === "object" ? source.analytics : {};
    var sourceTracking = source.tracking && typeof source.tracking === "object" ? source.tracking : {};
    var sourceCurrency = source.currency && typeof source.currency === "object" ? source.currency : {};
    var sourceIcons = source.icons && typeof source.icons === "object" ? source.icons : {};
    var defaultBrand = DEFAULT_CONFIG.brand;

    return {
      appVersion: safeText(source.app_version || source.appVersion, "", 80),
      siteUrl: safeAssetUrl(source.site_url || source.siteUrl),
      brand: {
        name: safeText(sourceBrand.name || source.brand_name || sourceBranding.site_name, defaultBrand.name, 48),
        primary: safeColor(sourceBrand.primary || sourceBrand.primary_color || sourceColors.primary || source.primary_color, defaultBrand.primary),
        primaryStrong: safeColor(sourceBrand.primaryStrong || sourceBrand.primary_strong || sourceColors.primaryStrong || sourceColors.primary_strong || sourceColors.secondary, defaultBrand.primaryStrong),
        accent: safeColor(sourceBrand.accent || sourceBrand.accent_color || sourceColors.accent, defaultBrand.accent),
        background: safeColor(sourceBrand.background || sourceColors.background, defaultBrand.background),
        surface: safeColor(sourceBrand.surface || sourceColors.surface, defaultBrand.surface),
        ink: safeColor(sourceBrand.ink || sourceBrand.text || sourceColors.ink || sourceColors.text, defaultBrand.ink),
        logoUrl: safeAssetUrl(sourceBrand.logoUrl || sourceBrand.logo_url || sourceBranding.logo_url || sourceBranding.logoUrl)
      },
      analytics: {
        enabled: Boolean(sourceAnalytics.enabled)
      },
      tracking: {
        providers: normalizeTrackingProviders(sourceTracking.providers)
      },
      currency: {
        code: safeText(sourceCurrency.code, DEFAULT_CONFIG.currency.code, 12).toUpperCase(),
        label: safeText(sourceCurrency.label, DEFAULT_CONFIG.currency.label, 32)
      },
      icons: normalizeIconMap(sourceIcons),
      api: {
        pairUrl: safeApiUrl(sourceApi.pair || sourceApi.pair_url || sourceEndpoints.pair || source.pair_url),
        meUrl: safeApiUrl(sourceApi.me || sourceApi.me_url || sourceEndpoints.me || source.me_url),
        csrfUrl: safeApiUrl(sourceApi.csrf || sourceApi.csrf_url || sourceEndpoints.csrf || source.csrf_url),
        logoutUrl: safeApiUrl(sourceApi.logout || sourceApi.logout_url || sourceEndpoints.logout || source.logout_url),
        productsUrl: safeApiUrl(sourceApi.products || sourceApi.products_url || sourceEndpoints.products || sourceEndpoints.products_url || source.products_url || source.productsUrl),
        productAttributesUrl: safeApiUrl(sourceApi.product_attributes || sourceApi.productAttributes || sourceApi.product_attributes_url || sourceEndpoints.product_attributes || sourceEndpoints.productAttributes || sourceEndpoints.product_attributes_url || source.product_attributes || source.productAttributes || source.product_attributes_url || source.productAttributesUrl),
        shippingClassesUrl: safeApiUrl(sourceApi.shipping_classes || sourceApi.shippingClasses || sourceApi.shipping_classes_url || sourceEndpoints.shipping_classes || sourceEndpoints.shippingClasses || sourceEndpoints.shipping_classes_url || source.shipping_classes || source.shippingClasses || source.shipping_classes_url || source.shippingClassesUrl),
        categoriesUrl: safeApiUrl(sourceApi.categories || sourceApi.categories_url || sourceEndpoints.categories || sourceEndpoints.categories_url || source.categories_url || source.categoriesUrl),
        customersUrl: safeApiUrl(sourceApi.customers || sourceApi.customers_url || sourceEndpoints.customers || sourceEndpoints.customers_url || source.customers_url || source.customersUrl),
        ordersUrl: safeApiUrl(sourceApi.orders || sourceApi.orders_url || sourceEndpoints.orders || sourceEndpoints.orders_url || source.orders_url || source.ordersUrl),
        analyticsUrl: safeApiUrl(sourceApi.analytics || sourceApi.analytics_url || sourceEndpoints.analytics || sourceEndpoints.analytics_url || source.analytics_url || source.analyticsUrl),
        couponsUrl: safeApiUrl(sourceApi.coupons || sourceApi.coupons_url || sourceEndpoints.coupons || sourceEndpoints.coupons_url || source.coupons_url || source.couponsUrl),
        reviewsUrl: safeApiUrl(sourceApi.reviews || sourceApi.reviews_url || sourceEndpoints.reviews || sourceEndpoints.reviews_url || source.reviews_url || source.reviewsUrl),
        inventoryUrl: safeApiUrl(sourceApi.inventory || sourceApi.inventory_url || sourceEndpoints.inventory || sourceEndpoints.inventory_url || source.inventory_url || source.inventoryUrl),
        devicesUrl: safeApiUrl(sourceApi.devices || sourceApi.devices_url || sourceEndpoints.devices || sourceEndpoints.devices_url || source.devices_url || source.devicesUrl),
        acknowledgeUrl: safeApiUrl(sourceApi.acknowledge || sourceApi.acknowledge_url || sourceEndpoints.acknowledge || sourceEndpoints.acknowledge_url || source.acknowledge_url || source.acknowledgeUrl),
        auditUrl: safeApiUrl(sourceApi.audit || sourceApi.audit_url || sourceEndpoints.audit || sourceEndpoints.audit_url || source.audit_url || source.auditUrl),
        mediaUrl: safeApiUrl(sourceApi.media || sourceApi.media_url || sourceEndpoints.media || sourceEndpoints.media_url || source.media_url || source.mediaUrl)
      }
    };
  }

  function normalizeIconMap(source) {
    var normalized = {};
    if (!source || typeof source !== "object") {
      return normalized;
    }

    Object.keys(source).forEach(function (key) {
      if (!/^[a-z][a-z0-9-]{1,39}$/.test(key)) {
        return;
      }

      var url = safeAssetUrl(source[key]);
      if (url) {
        normalized[key] = url;
      }
    });

    return normalized;
  }

  function normalizeTrackingProviders(source) {
    var normalized = [];
    var used = {};
    if (!Array.isArray(source)) {
      return normalized;
    }

    source.forEach(function (provider) {
      var row = provider && typeof provider === "object" ? provider : {};
      var id = safeText(row.id, "", 32).toLowerCase();
      var label = safeText(row.label, "", 80);
      if (!/^[a-z][a-z0-9_-]{0,31}$/.test(id) || !label || used[id]) {
        return;
      }
      used[id] = true;
      normalized.push({ id: id, label: label });
    });

    return normalized;
  }

  function trackingProviders() {
    var tracking = state.config && state.config.tracking && typeof state.config.tracking === "object" ? state.config.tracking : {};
    return Array.isArray(tracking.providers) ? tracking.providers : [];
  }

  function trackingProviderForId(value) {
    var id = safeText(value, "", 32).toLowerCase();
    return trackingProviders().filter(function (provider) {
      return provider.id === id;
    })[0] || null;
  }

  function trackingProviderForLabel(value) {
    var label = safeText(value, "", 80).trim();
    return trackingProviders().filter(function (provider) {
      return provider.label === label;
    })[0] || null;
  }

  function applyConfiguredIcons() {
    var iconMap = state.config && state.config.icons && typeof state.config.icons === "object" ? state.config.icons : {};
    if (!Object.keys(iconMap).length) {
      return;
    }

    Array.prototype.forEach.call(document.querySelectorAll("svg[data-icon-name]"), function (svg) {
      var iconName = svg.getAttribute("data-icon-name") || "";
      var iconUrl = iconMap[iconName];
      if (!iconUrl) {
        return;
      }

      var icon = document.createElement("span");
      icon.className = (svg.getAttribute("class") || "ui-icon") + " ui-icon-asset";
      icon.setAttribute("aria-hidden", "true");
      icon.dataset.iconName = iconName;
      icon.style.setProperty("--ui-icon-url", "url(\"" + iconUrl + "\")");
      svg.replaceWith(icon);
    });
  }

  function applyConfig(config) {
    var brand = config.brand;
    state.config = config;
    state.analytics.enabled = Boolean(config.analytics && config.analytics.enabled);
    applyConfiguredIcons();

    root.style.setProperty("--brand-primary", brand.primary);
    root.style.setProperty("--brand-primary-strong", brand.primaryStrong);
    root.style.setProperty("--brand-accent", brand.accent);
    root.style.setProperty("--brand-bg", brand.background);
    root.style.setProperty("--brand-surface", brand.surface);
    root.style.setProperty("--brand-ink", brand.ink);

    Array.prototype.forEach.call(document.querySelectorAll("[data-brand-name]"), function (node) {
      node.textContent = formatDisplayText(brand.name);
    });

    Array.prototype.forEach.call(document.querySelectorAll("[data-brand-logo]"), function (node) {
      var defaultLogoUrl = safeAssetUrl(node.getAttribute("data-default-logo"));
      var logoUrl = brand.logoUrl || defaultLogoUrl;
      var hasLogo = Boolean(logoUrl);
      node.hidden = !hasLogo;

      if (hasLogo) {
        node.onerror = function () {
          node.hidden = true;
          node.removeAttribute("src");
          Array.prototype.forEach.call(document.querySelectorAll("[data-brand-mark-fallback]"), function (fallback) {
            fallback.hidden = false;
          });
        };
        node.src = logoUrl;
      } else {
        node.removeAttribute("src");
      }
    });

    Array.prototype.forEach.call(document.querySelectorAll("[data-brand-mark-fallback]"), function (node) {
      var brandLogo = document.querySelector("[data-brand-logo]");
      var defaultLogoUrl = brandLogo ? safeAssetUrl(brandLogo.getAttribute("data-default-logo")) : "";
      node.hidden = Boolean(brand.logoUrl || defaultLogoUrl);
    });

    document.title = formatDisplayText(brand.name + " | مدیریت");
    if (elements.sidebarStoreUrl) {
      elements.sidebarStoreUrl.textContent = formatDisplayText(config.siteUrl ? siteLabel(config.siteUrl) : "سایت فروشگاه");
    }
    if (elements.themeColor) {
      elements.themeColor.setAttribute("content", brand.primary);
    }
    setAnalyticsState(state.analytics.enabled ? "secure" : "disabled");
  }

  function setConnectionState(stateName, label, detail) {
    elements.connectionIndicator.setAttribute("data-state", stateName);
    elements.connectionLabel.textContent = formatDisplayText(label);
    elements.connectionPopoverTitle.textContent = formatDisplayText(label);
    elements.connectionPopoverText.textContent = formatDisplayText(detail);
  }

  function setConfigSource(sourceName) {
    state.configSource = sourceName;
    if (elements.runtimeNote) {
      elements.runtimeNote.textContent = formatDisplayText(sourceName === "preview" ? "حالت پیش‌نمایش؛ داده‌ها واقعی نیستند" : (sourceName === "server" ? "پیکربندی سایت فعال است" : "پوستهٔ محلی؛ اتصال بعداً تکمیل می‌شود"));
    }
  }

  function shouldStartPreviewMode() {
    try {
      var previewValue = new URL(window.location.href).searchParams.get("preview");
      return previewValue === "1" || previewValue === "true" || previewValue === "demo";
    } catch (error) {
      return false;
    }
  }

  function setPreviewMode(enabled) {
    state.previewMode = Boolean(enabled);
    if (elements.appShell) {
      elements.appShell.classList.toggle("is-preview-mode", state.previewMode);
    }
    document.body.classList.toggle("preview-mode", state.previewMode);
  }

  function isStandaloneMode() {
    var mediaStandalone = false;

    if (window.matchMedia) {
      mediaStandalone = window.matchMedia("(display-mode: standalone)").matches;
    }

    return mediaStandalone || window.navigator.standalone === true;
  }

  function isIosDevice() {
    var userAgent = window.navigator.userAgent || "";
    var platform = window.navigator.platform || "";
    return /iphone|ipad|ipod/i.test(userAgent) || (platform === "MacIntel" && window.navigator.maxTouchPoints > 1);
  }

  // [UI: PWA/UPDATE] وضعیت نصب وب‌اپ و اعلان نسخهٔ جدید.
  function setInstallButtonVisibility() {
    if (!elements.installAppButton) {
      return;
    }

    // Browser-install UX is intentionally limited to iOS. Android is kept
    // manifest/service-worker ready for the native Trusted Web Activity shell,
    // not offered as a competing browser PWA installation.
    elements.installAppButton.hidden = !isIosDevice() || state.pwa.installed || isStandaloneMode();
  }

  function openInstallGuide() {
    if (!elements.installPopover || !isIosDevice()) {
	  return;
	}

    elements.installPopoverText.textContent = "در Safari روی Share بزنید و گزینهٔ Add to Home Screen را انتخاب کنید.";

    elements.installPopover.hidden = false;
  }

  function closeInstallGuide() {
    if (elements.installPopover) {
      elements.installPopover.hidden = true;
    }
  }

  function handleInstallApp() {
	if (!isIosDevice()) {
	  return;
	}

    var installPrompt = state.pwa.deferredInstallPrompt;

    if (!installPrompt) {
      openInstallGuide();
      return;
    }

    state.pwa.deferredInstallPrompt = null;
    setInstallButtonVisibility();
    installPrompt.prompt();

    Promise.resolve(installPrompt.userChoice).then(function (choice) {
      if (choice && choice.outcome === "accepted") {
        state.pwa.installed = true;
        closeInstallGuide();
      }
      setInstallButtonVisibility();
    }).catch(function () {
      setInstallButtonVisibility();
    });
  }

  function showUpdateBanner() {
    state.pwa.updateReady = true;
    if (elements.appUpdateBanner) {
      elements.appUpdateBanner.hidden = false;
    }
  }

  function rememberAppVersion(serverVersion) {
    var currentVersion = safeText(serverVersion || APP_BUILD_VERSION, "", 80);
    if (!currentVersion) {
      return;
    }

    try {
      if (!window.localStorage) {
        return;
      }
      var previousVersion = window.localStorage.getItem(APP_VERSION_STORAGE_KEY) || "";
      if (previousVersion && previousVersion !== currentVersion) {
        showUpdateBanner();
      }
      window.localStorage.setItem(APP_VERSION_STORAGE_KEY, currentVersion);
    } catch (error) {
      // Version memory is optional; authentication never depends on it.
    }
  }

  function applyAppUpdate() {
    var registration = state.pwa.registration;

    if (!registration || !registration.waiting) {
      window.location.reload();
      return;
    }

    state.pwa.updateReloading = true;
    if (elements.applyAppUpdate) {
      elements.applyAppUpdate.disabled = true;
      elements.applyAppUpdate.textContent = "در حال به‌روزرسانی...";
    }
    registration.waiting.postMessage({ type: "SKIP_WAITING" });
  }

  function setNetworkState(isOnline) {
    state.networkOnline = Boolean(isOnline);

    if (state.previewMode) {
      setConnectionState("connected", "حالت پیش‌نمایش فعال است", "داده‌های نمونه محلی هستند و به وضعیت اینترنت وابسته نیستند.");
      return;
    }

    if (!state.networkOnline) {
      setConnectionState("offline", "اتصال اینترنت قطع است", "وب‌اپ باز است، اما ذخیره‌سازی و عملیات مدیریتی تا بازگشت اینترنت انجام نمی‌شود.");
      return;
    }

    if (state.configSource === "server") {
      setConnectionState("connected", "اتصال برقرار است", "پیکربندی عمومی از سایت دریافت شد؛ آمادهٔ ادامهٔ کار هستید.");
    } else {
      setConnectionState("fallback", "اتصال سایت برقرار نیست", "رابط با پیکربندی محلی در دسترس است؛ برای عملیات مدیریتی اتصال را بررسی کنید.");
    }
  }

  function setBootState(isBooting) {
    if (elements.appBootView) {
      elements.appBootView.hidden = !isBooting;
    }
    if (elements.appShell) {
      elements.appShell.classList.toggle("is-booting", Boolean(isBooting));
    }
  }

  // [UI: NAVIGATION & SEARCH] ناوبری سایدبار، ناوبری موبایل و جست‌وجوی کلی.
  function sectionIncludes(panel, sectionName) {
    var sections = String(panel.getAttribute("data-nav-section") || "").split(/\s+/);
    return sections.indexOf(sectionName) !== -1;
  }

  var NAV_SECTIONS = ["dashboard", "orders", "products", "inventory", "categories", "customers", "coupons", "reviews", "analytics", "security"];

  function normalizeSection(sectionName) {
    return NAV_SECTIONS.indexOf(sectionName) !== -1 ? sectionName : "dashboard";
  }

  function sectionFromLocation() {
    var hash = window.location.hash ? window.location.hash.slice(1) : "";
    if (hash.indexOf("section=") === 0) {
      hash = hash.slice(8);
    }
    return normalizeSection(hash || "dashboard");
  }

  function setActiveSection(sectionName) {
    state.activeSection = normalizeSection(sectionName || "dashboard");
    if (state.activeSection !== "orders") {
      closeOrderFilterPanel();
      closeManualOrder();
    }
    if (state.activeSection !== "products") {
      closeProductFilterPanel();
    }

    Array.prototype.forEach.call(document.querySelectorAll("[data-nav-section]"), function (panel) {
      var isAnalyticsPanel = panel.id === "analyticsPanel";
      var isDashboardSalesPanel = panel.id === "dashboardSalesPanel";
      var isDashboardKpiGrid = panel.id === "dashboardKpiGrid";
      var isDashboardStatusPanel = panel.id === "dashboardOrderStatusPanel";
      var analyticsAccess = hasAnalyticsAccess();
      panel.hidden = !sectionIncludes(panel, state.activeSection) || ((isAnalyticsPanel || isDashboardSalesPanel || isDashboardKpiGrid || isDashboardStatusPanel) && !analyticsAccess);
    });

    Array.prototype.forEach.call(document.querySelectorAll(".sidebar-nav-item, .mobile-nav-item"), function (item) {
      var isActive = item.getAttribute("data-nav-target") === state.activeSection && item.getAttribute("data-action") !== "new-product";
      item.classList.toggle("is-active", isActive);
      if (isActive) {
        item.setAttribute("aria-current", "page");
      } else {
        item.removeAttribute("aria-current");
      }
    });

    if (elements.globalSearch) {
      elements.globalSearch.value = "";
    }
    closeGlobalSearchResults();
  }

  function closeSidebar() {
    var wasOpen = Boolean(elements.appSidebar && elements.appSidebar.classList.contains("is-open"));
    if (elements.appSidebar) {
      elements.appSidebar.classList.remove("is-open");
    }
    if (elements.sidebarScrim) {
      elements.sidebarScrim.hidden = true;
    }
    if (elements.mobileMenuToggle) {
      elements.mobileMenuToggle.setAttribute("aria-expanded", "false");
    }
    document.body.classList.remove("menu-open");
    if (wasOpen && state.sidebarTrigger && typeof state.sidebarTrigger.focus === "function") {
      state.sidebarTrigger.focus();
    }
    state.sidebarTrigger = null;
  }

  function toggleSidebar() {
    if (!elements.appSidebar) {
      return;
    }

    var isOpen = elements.appSidebar.classList.toggle("is-open");
    state.sidebarTrigger = isOpen ? elements.mobileMenuToggle : null;
    if (elements.sidebarScrim) {
      elements.sidebarScrim.hidden = !isOpen;
    }
    if (elements.mobileMenuToggle) {
      elements.mobileMenuToggle.setAttribute("aria-expanded", String(isOpen));
    }
    document.body.classList.toggle("menu-open", isOpen);
    if (isOpen) {
      window.setTimeout(function () {
        var firstItem = elements.appSidebar.querySelector("button, a, input, select, textarea");
        if (firstItem) firstItem.focus();
      }, 0);
    }
  }

  function scrollToSectionStart() {
    if (typeof window.scrollTo !== "function") {
      return;
    }

    var prefersReducedMotion = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    try {
      window.scrollTo({
        top: 0,
        left: 0,
        behavior: prefersReducedMotion ? "auto" : "smooth"
      });
    } catch (error) {
      window.scrollTo(0, 0);
    }
  }

  function navigateToSection(sectionName, fromHistory) {
    if (!state.authenticated) {
      return;
    }
    sectionName = normalizeSection(sectionName);
    if (sectionName === "analytics" && !hasAnalyticsAccess()) {
      sectionName = "dashboard";
    }
    setActiveSection(sectionName);
    closeSidebar();
    if (!fromHistory && window.history && window.history.pushState) {
      window.history.pushState({ section: sectionName }, "", "#" + sectionName);
    }
    scrollToSectionStart();
  }

  function globalSearchGroups() {
    return [
      {
        section: "orders",
        items: state.orders.items,
        fields: function (item) { return [item.id, item.number, item.customer, item.customerEmail, item.customerPhone, item.paymentMethod]; },
        title: function (item) { return "سفارش " + formatOrderNumber(item.number || item.id); },
        meta: function (item) { return item.customer || item.customerEmail || "سفارش‌های فروشگاه"; }
      },
      {
        section: "products",
        items: state.products.items,
        fields: function (item) { return [item.id, item.name, item.sku, item.stock, item.status]; },
        title: function (item) { return item.name || "محصول بدون نام"; },
        meta: function (item) { return item.sku ? "SKU: " + item.sku : "محصولات"; }
      },
      {
        section: "customers",
        items: state.customers.items,
        fields: function (item) { return [item.id, item.display, item.username, item.email, item.phone, item.firstName, item.lastName]; },
        title: function (item) { return item.display || item.username || "مشتری بدون نام"; },
        meta: function (item) { return item.email || item.username || "مشتریان"; }
      },
      {
        section: "inventory",
        items: state.inventory.items,
        fields: function (item) { return [item.id, item.name, item.sku, item.stockStatus, item.stockStatusLabel]; },
        title: function (item) { return item.name || "محصول بدون نام"; },
        meta: function (item) { return item.sku ? "SKU: " + item.sku : "موجودی"; }
      },
      {
        section: "categories",
        items: state.categories.items,
        fields: function (item) { return [item.id, item.name, item.slug]; },
        title: function (item) { return item.name || "دسته‌بندی بدون نام"; },
        meta: function (item) { return item.slug ? "slug: " + item.slug : "دسته‌بندی‌ها"; }
      },
      {
        section: "coupons",
        items: state.coupons.items,
        fields: function (item) { return [item.id, item.code, item.description]; },
        title: function (item) { return item.code || "کوپن بدون کد"; },
        meta: function (item) { return item.description || "کوپن‌ها"; }
      },
      {
        section: "reviews",
        items: state.reviews.items,
        fields: function (item) { return [item.id, item.productName, item.author, item.content]; },
        title: function (item) { return item.productName || "دیدگاه محصول"; },
        meta: function (item) { return item.author || "دیدگاه‌ها"; }
      }
    ];
  }

  function globalSearchSectionDestinations() {
    return [
      { section: "dashboard", terms: ["داشبورد", "خانه", "home"] },
      { section: "orders", terms: ["سفارش", "سفارش‌ها", "سفارشات", "فروش", "order", "orders"] },
      { section: "products", terms: ["محصول", "محصولات", "کالا", "product", "products"] },
      { section: "inventory", terms: ["موجودی", "انبار", "stock", "inventory"] },
      { section: "categories", terms: ["دسته", "دسته‌بندی", "دسته‌بندی‌ها", "taxonomy", "category", "categories"] },
      { section: "customers", terms: ["مشتری", "مشتریان", "customer", "customers"] },
      { section: "coupons", terms: ["کوپن", "کوپن‌ها", "تخفیف", "coupon", "coupons", "discount"] },
      { section: "reviews", terms: ["دیدگاه", "دیدگاه‌ها", "نظر", "review", "reviews"] },
      { section: "analytics", terms: ["گزارش", "گزارش‌ها", "گزارشات", "تحلیل", "فروش", "analytics"] },
      { section: "security", terms: ["امنیت", "دستگاه", "نشست", "security"] }
    ];
  }

  function globalSearchSectionLabel(section) {
    return {
      dashboard: "داشبورد",
      orders: "سفارش‌ها",
      products: "محصولات",
      inventory: "موجودی",
      categories: "دسته‌بندی‌ها",
      customers: "مشتریان",
      coupons: "کوپن‌ها",
      reviews: "دیدگاه‌ها",
      analytics: "گزارش‌ها",
      security: "امنیت و دسترسی"
    }[section] || "پنل مدیریت";
  }

  function globalSearchRecordDestination(query) {
    var groups = globalSearchGroups();
    for (var index = 0; index < groups.length; index += 1) {
      var group = groups[index];
      if ((group.items || []).some(function (item) {
        return searchTextIncludes(group.fields(item), query);
      })) {
        return { section: group.section, filter: true };
      }
    }
    return null;
  }

  function globalSearchSectionDestination(query) {
    var destinations = globalSearchSectionDestinations();
    var normalizedQuery = normalizeSearchText(query);
    if (!normalizedQuery) {
      return null;
    }

    return destinations.filter(function (item) {
      return item.terms.some(function (term) {
        var normalizedTerm = normalizeSearchText(term);
        return normalizedTerm === normalizedQuery || (normalizedQuery.length >= 2 && normalizedTerm.indexOf(normalizedQuery) === 0);
      });
    })[0] || null;
  }

  function globalSearchPrefixedDestination(query) {
    var normalizedQuery = normalizeSearchText(query);
    if (!normalizedQuery) {
      return null;
    }
    return globalSearchSectionDestinations().filter(function (item) {
      return item.terms.some(function (term) {
        var normalizedTerm = normalizeSearchText(term);
        return normalizedTerm && normalizedQuery.indexOf(normalizedTerm + " ") === 0;
      });
    })[0] || null;
  }

  function globalSearchFallbackDestination(query) {
    var normalizedQuery = normalizeSearchText(query);
    var destination = globalSearchSectionDestination(query);
    if (destination) {
      return { section: destination.section, filter: false };
    }
    destination = globalSearchPrefixedDestination(query);
    if (destination) {
      return { section: destination.section, filter: true };
    }
    if (/^\d+$/.test(normalizedQuery)) {
      return { section: "orders", filter: true };
    }
    if (normalizedQuery.indexOf("@") !== -1) {
      return { section: "customers", filter: true };
    }
    return { section: searchableSectionForGlobalSearch(), filter: true };
  }

  function globalSearchResultsForQuery(query) {
    var results = [];
    var sectionDestination = globalSearchSectionDestination(query);
    if (sectionDestination) {
      results.push({
        kind: "section",
        section: sectionDestination.section,
        filter: false,
        title: "رفتن به " + globalSearchSectionLabel(sectionDestination.section),
        meta: "نمایش این بخش از پنل مدیریت"
      });
    }

    var prefixedDestination = globalSearchPrefixedDestination(query);
    if (!sectionDestination && prefixedDestination) {
      results.push({
        kind: "search",
        section: prefixedDestination.section,
        filter: true,
        title: "جست‌وجوی اطلاعات در " + globalSearchSectionLabel(prefixedDestination.section),
        meta: "عبارت بعد از نام بخش به‌عنوان فیلتر جست‌وجو می‌شود"
      });
    }

    globalSearchGroups().forEach(function (group) {
      (group.items || []).forEach(function (item) {
        if (results.length >= 8 || !searchTextIncludes(group.fields(item), query)) {
          return;
        }
        results.push({
          kind: "record",
          section: group.section,
          filter: true,
          title: group.title(item),
          meta: group.meta(item)
        });
      });
    });

    if (!results.length) {
      var fallback = globalSearchFallbackDestination(query);
      results.push({
        kind: "search",
        section: fallback.section,
        filter: fallback.filter,
        title: "جست‌وجوی «" + query + "» در " + globalSearchSectionLabel(fallback.section),
        meta: "برای نمایش نتیجه‌ها انتخاب کنید یا Enter بزنید"
      });
    }

    return results.slice(0, 8);
  }

  function globalSearchQueryForSection(section, query) {
    var rawQuery = String(query === null || typeof query === "undefined" ? "" : query).trim();
    var normalizedQuery = normalizeSearchText(rawQuery);
    if (!normalizedQuery) {
      return rawQuery;
    }
    var destination = globalSearchPrefixedDestination(rawQuery);
    if (!destination || destination.section !== section) {
      return rawQuery;
    }

    var sectionTerms = destination.terms || [];
    for (var index = 0; index < sectionTerms.length; index += 1) {
      var normalizedTerm = normalizeSearchText(sectionTerms[index]);
      if (normalizedTerm && normalizedQuery.indexOf(normalizedTerm + " ") === 0) {
        return normalizedQuery.slice(normalizedTerm.length).trim();
      }
    }
    return rawQuery;
  }

  function closeGlobalSearchResults() {
    state.globalSearch.results = [];
    state.globalSearch.activeIndex = -1;
    if (elements.globalSearchResults) {
      elements.globalSearchResults.hidden = true;
      while (elements.globalSearchResults.firstChild) {
        elements.globalSearchResults.removeChild(elements.globalSearchResults.firstChild);
      }
    }
    if (elements.globalSearch) {
      elements.globalSearch.setAttribute("aria-expanded", "false");
    }
  }

  function setGlobalSearchActiveIndex(index) {
    if (!elements.globalSearchResults) {
      return;
    }
    var buttons = elements.globalSearchResults.querySelectorAll("[data-global-search-index]");
    if (!buttons.length) {
      state.globalSearch.activeIndex = -1;
      return;
    }
    var normalizedIndex = Number(index);
    if (!isFinite(normalizedIndex)) normalizedIndex = -1;
    if (normalizedIndex >= buttons.length) normalizedIndex = 0;
    if (normalizedIndex < -1) normalizedIndex = buttons.length - 1;
    state.globalSearch.activeIndex = normalizedIndex;
    Array.prototype.forEach.call(buttons, function (button, buttonIndex) {
      var selected = buttonIndex === normalizedIndex;
      button.classList.toggle("is-active", selected);
      button.setAttribute("aria-selected", String(selected));
    });
  }

  function renderGlobalSearchResults() {
    if (!elements.globalSearch || !elements.globalSearchResults) {
      return;
    }
    // جست‌وجوی سراسری فقط برای نشست مدیریتی فعال معنا دارد؛ پیش از ورود هیچ نتیجه‌ای ساخته نمی‌شود.
    if (!state.authenticated) {
      closeGlobalSearchResults();
      return;
    }
    var query = elements.globalSearch.value.trim();
    if (!normalizeSearchText(query)) {
      closeGlobalSearchResults();
      return;
    }

    var results = globalSearchResultsForQuery(query);
    state.globalSearch.results = results;
    state.globalSearch.activeIndex = -1;
    while (elements.globalSearchResults.firstChild) {
      elements.globalSearchResults.removeChild(elements.globalSearchResults.firstChild);
    }

    results.forEach(function (result, index) {
      var button = document.createElement("button");
      button.type = "button";
      button.className = "global-search-result";
      button.setAttribute("role", "option");
      button.setAttribute("aria-selected", "false");
      button.setAttribute("data-global-search-index", String(index));
      button.appendChild(createTextElement("strong", "global-search-result-title", result.title));
      button.appendChild(createTextElement("span", "global-search-result-meta", result.meta + " · " + globalSearchSectionLabel(result.section)));
      elements.globalSearchResults.appendChild(button);
    });
    elements.globalSearchResults.hidden = false;
    elements.globalSearch.setAttribute("aria-expanded", "true");
  }

  function moveGlobalSearchSelection(offset) {
    if (!elements.globalSearchResults || elements.globalSearchResults.hidden) {
      renderGlobalSearchResults();
    }
    if (!state.globalSearch.results.length) {
      return;
    }
    var current = state.globalSearch.activeIndex;
    if (current < 0) {
      current = offset > 0 ? 0 : state.globalSearch.results.length - 1;
    } else {
      current = (current + offset + state.globalSearch.results.length) % state.globalSearch.results.length;
    }
    setGlobalSearchActiveIndex(current);
  }

  function openGlobalSearchResult(result, query) {
    if (!result || !result.section) {
      return;
    }
    navigateToSection(result.section);
    if (result.filter) {
      applyGlobalSearchToSection(result.section, query || elements.globalSearch.value.trim());
    }
    closeGlobalSearchResults();
    if (elements.globalSearch) {
      elements.globalSearch.value = "";
    }
  }

  function searchableSectionForGlobalSearch() {
    var inputs = {
      products: elements.productSearch,
      categories: elements.categorySearch,
      orders: elements.orderSearch,
      customers: elements.customerSearch,
      inventory: elements.inventorySearch,
      coupons: elements.couponSearch,
      reviews: elements.reviewSearch
    };
    return inputs[state.activeSection] ? state.activeSection : "products";
  }

  function applyGlobalSearchToSection(section, query) {
    var inputMap = {
      products: elements.productSearch,
      categories: elements.categorySearch,
      orders: elements.orderSearch,
      customers: elements.customerSearch,
      inventory: elements.inventorySearch,
      coupons: elements.couponSearch,
      reviews: elements.reviewSearch
    };
    var sectionQuery = globalSearchQueryForSection(section, query);
    var input = inputMap[section];
    if (input) {
      input.value = sectionQuery;
    }

    if (section === "products") {
      scheduleProductSearch();
    } else if (section === "categories") {
      renderCategoryResults();
    } else if (section === "orders") {
      scheduleOrderSearch();
    } else if (section === "customers") {
      scheduleCustomerSearch();
    } else if (section === "inventory") {
      scheduleInventorySearch();
    } else if (section === "coupons") {
      scheduleCouponSearch();
    } else if (section === "reviews") {
      scheduleReviewSearch();
    }
  }

  function navigateFromGlobalSearch() {
    if (!elements.globalSearch) return;
    if (!state.authenticated) return;
    var query = elements.globalSearch.value.trim();
    if (!normalizeSearchText(query)) return;

    var selectedIndex = state.globalSearch.activeIndex;
    var selectedResult = selectedIndex >= 0 ? state.globalSearch.results[selectedIndex] : state.globalSearch.results[0];
    if (selectedResult) {
      openGlobalSearchResult(selectedResult, query);
      return;
    }

    var destination = globalSearchRecordDestination(query) || globalSearchFallbackDestination(query);
    openGlobalSearchResult(destination, query);
    elements.globalSearch.value = "";
  }

  function handleGlobalSearchKeydown(event) {
    if (event.key === "ArrowDown") {
      event.preventDefault();
      moveGlobalSearchSelection(1);
      return;
    }
    if (event.key === "ArrowUp") {
      event.preventDefault();
      moveGlobalSearchSelection(-1);
      return;
    }
    if (event.key === "Escape") {
      event.preventDefault();
      closeGlobalSearchResults();
      return;
    }
    if (event.key === "Enter") {
      event.preventDefault();
      navigateFromGlobalSearch();
    }
  }

  function bindSearchInput(input, handler) {
    if (!input || typeof handler !== "function") {
      return;
    }
    input.addEventListener("input", handler);
    input.addEventListener("search", handler);
    input.addEventListener("change", handler);
    input.addEventListener("keydown", function (event) {
      if (event.key === "Enter") {
        event.preventDefault();
        handler();
      }
    });
  }

  // [UI: VIEW STATE] جابه‌جایی بین صفحهٔ جفت‌سازی و فضای مدیریت.
  function showView(viewName) {
    state.view = viewName;
    var isDashboard = viewName === "dashboard";
    setBootState(false);
    elements.pairingView.hidden = isDashboard;
    elements.dashboardView.hidden = !isDashboard;

    if (isDashboard) {
      setActiveSection(state.activeSection || "dashboard");
    }

    if (!isDashboard) {
      setActiveSection("dashboard");
      elements.pairingMessage.hidden = true;
      elements.pairingCode.focus();
    }
  }

  function showPairingMessage(message) {
    elements.pairingMessage.textContent = formatDisplayText(message);
    elements.pairingMessage.hidden = false;
  }

  function toggleConnectionPopover() {
    var shouldOpen = elements.connectionPopover.hidden;
    elements.connectionPopover.hidden = !shouldOpen;
    elements.connectionControl.setAttribute("aria-expanded", String(shouldOpen));
  }

  function closeConnectionPopover(event) {
    if (!elements.connectionPopover.hidden && (!event || (!elements.connectionControl.contains(event.target) && !elements.connectionPopover.contains(event.target)))) {
      elements.connectionPopover.hidden = true;
      elements.connectionControl.setAttribute("aria-expanded", "false");
    }
  }

  // [API/SESSION] ارتباط با API، نشست امن و جفت‌سازی مدیر.
  function fetchJsonWithTimeout(url, overrides) {
    if (state.previewMode) {
      var previewError = new Error("preview-mode");
      previewError.preview = true;
      return Promise.reject(previewError);
    }

    var controller = typeof AbortController === "function" ? new AbortController() : null;
    var timeoutMs = overrides && typeof overrides.timeoutMs === "number" && isFinite(overrides.timeoutMs) ? Math.max(1000, overrides.timeoutMs) : REQUEST_TIMEOUT_MS;
    var timeoutId = window.setTimeout(function () {
      if (controller) {
        controller.abort();
      }
    }, timeoutMs);

    var requestOptions = {
      method: "GET",
      credentials: "same-origin",
      cache: "no-store",
      headers: {
        Accept: "application/json"
      }
    };

    if (overrides && typeof overrides === "object") {
      Object.keys(overrides).forEach(function (key) {
        if (key === "timeoutMs") {
          return;
        }
        if (key === "headers") {
          requestOptions.headers = Object.assign({}, requestOptions.headers, overrides.headers);
        } else {
          requestOptions[key] = overrides[key];
        }
      });
    }

    if (!state.networkOnline && ["GET", "HEAD"].indexOf(String(requestOptions.method || "GET").toUpperCase()) === -1) {
      var offlineError = new Error("offline");
      offlineError.offline = true;
      return Promise.reject(offlineError);
    }

    if (controller) {
      requestOptions.signal = controller.signal;
    }

    return fetch(url, requestOptions).then(function (response) {
      return response.json().catch(function () {
        return {};
      }).then(function (payload) {
        if (!response.ok) {
          var error = new Error("request-failed");
          error.status = response.status;
          error.payload = payload;
          throw error;
        }
        return payload;
      });
    }).finally(function () {
      window.clearTimeout(timeoutId);
    });
  }

  function loadConfig() {
    if (state.previewMode) {
      return Promise.resolve(true);
    }

    if (!state.networkOnline) {
      setNetworkState(false);
      return Promise.resolve();
    }

    setConnectionState("checking", "در حال بررسی اتصال", "تنظیمات عمومی محیط مدیریت در حال دریافت است.");

    return fetchJsonWithTimeout(CONFIG_URL, { timeoutMs: CONFIG_TIMEOUT_MS }).then(function (payload) {
      var config = normalizeConfig(payload);
      applyConfig(config);
      rememberAppVersion(config.appVersion);
      setConfigSource("server");
      setConnectionState("connected", "اتصال برقرار است", "پیکربندی عمومی از سایت دریافت شد؛ آمادهٔ ادامهٔ کار هستید.");
    }).catch(function () {
      if (!state.networkOnline) {
        setNetworkState(false);
        return;
      }

      var fallbackConfig = normalizeConfig(DEFAULT_CONFIG);
      applyConfig(fallbackConfig);
      setConfigSource("fallback");
      setConnectionState("fallback", "اتصال سایت برقرار نیست", "رابط با پیکربندی محلی در دسترس است؛ برای عملیات مدیریتی اتصال را بررسی کنید.");
    });
  }

  function responseErrorMessage(error, fallback, options) {
    var hints = options && typeof options === "object" ? options : {};

    if (error && error.offline) {
      return "اتصال اینترنت قطع است؛ بعد از آنلاین‌شدن دوباره تلاش کنید.";
    }

    if (error && error.name === "AbortError") {
      return hints.media
        ? "زمان پردازش تصویر تمام شد؛ ابعاد یا حجم تصویر را کم کنید و دوباره امتحان کنید."
        : "زمان پاسخ سرور به پایان رسید؛ دوباره تلاش کنید.";
    }

    // The server's own message is the most accurate description of what went
    // wrong, so it wins over every generic status text (duplicate coupon code,
    // invalid order input, replayed pairing code, …).
    if (error && error.payload && typeof error.payload.message === "string") {
      return safeText(error.payload.message, fallback, 240);
    }

    if (error && error.status === 429) {
      return "تلاش‌های زیاد شناسایی شد؛ چند دقیقه بعد دوباره امتحان کنید.";
    }

    if (error && error.status === 401) {
      return "نشست معتبر نیست یا اطلاعات ورود نادرست است.";
    }

    if (error && error.status === 403) {
      return "درخواست از مبدأ معتبر یا با مجوز لازم ارسال نشده است.";
    }

    if (error && error.payload && error.payload.code === "fandoogh_subscriptions_required") {
      return "برای ساخت محصول اشتراکی باید افزونهٔ WooCommerce Subscriptions فعال باشد.";
    }

    // Media- and inventory-specific status texts only apply to those flows.
    if (error && hints.media) {
      if (error.status === 413) {
        return "حجم فایل از سقف مجاز بیشتر است.";
      }
      if (error.status === 415) {
        return "فرمت یا محتوای فایل تصویر قابل قبول نیست.";
      }
      if (error.status === 422) {
        return "تصویر با تنظیمات ابعاد یا حجم فعلی سازگار نیست.";
      }
      if (error.status === 503) {
        return "ویرایشگر تصویر روی سرور در دسترس نیست؛ فعال بودن GD یا Imagick را بررسی کنید.";
      }
    }

    if (error && hints.inventory && error.status === 409) {
      return "موجودی این محصول هم‌زمان تغییر کرده است؛ اطلاعات تازه را دریافت و دوباره بررسی کنید.";
    }

    return fallback;
  }

  function apiUrl(name) {
    return state.config && state.config.api ? state.config.api[name] : "";
  }

  function makeEphemeralDeviceId() {
    var bytes;
    var index;

    if (window.crypto && typeof window.crypto.getRandomValues === "function") {
      bytes = new Uint8Array(16);
      window.crypto.getRandomValues(bytes);
      return Array.prototype.map.call(bytes, function (value) {
        return ("0" + value.toString(16)).slice(-2);
      }).join("");
    }

    index = 0;
    return String(Date.now()) + String(Math.random()).slice(2) + String(index);
  }

  function setAuthenticated(authenticated, csrfToken) {
    state.authenticated = Boolean(authenticated);
    state.csrfToken = state.authenticated && typeof csrfToken === "string" ? csrfToken : "";
    syncManualOrderAccess();

    if (elements.appShell) {
      elements.appShell.classList.toggle("is-authenticated", state.authenticated);
    }

    if (state.authenticated) {
      startPendingOrderCountRefresh();
    } else {
      stopPendingOrderCountRefresh();
    }

    if (!state.authenticated) {
      state.user.scopes = {};
      closeGlobalSearchResults();
      [state.products, state.customers, state.inventory, state.coupons, state.reviews].forEach(function (collection) {
        if (collection.searchTimer) {
          window.clearTimeout(collection.searchTimer);
          collection.searchTimer = null;
        }
        collection.loadRequestId += 1;
      });
      if (state.orders.searchTimer) {
        window.clearTimeout(state.orders.searchTimer);
        state.orders.searchTimer = null;
      }
      state.orders.loadRequestId += 1;
      state.products.items = [];
      state.products.filteredItems = [];
      state.products.editorImages = [];
      state.products.editorAttributes = [];
      state.products.editorCategoryIds = [];
      state.products.editorLoadRequestId += 1;
      state.products.viewLoadRequestId += 1;
      state.products.viewId = null;
      state.products.viewProduct = null;
      state.products.viewVariations = [];
      state.products.viewStep = "summary";
      state.products.viewImageIndex = 0;
      state.attributeCatalog.items = [];
      state.attributeCatalog.status = "secure";
      state.categories.items = [];
      state.categories.filteredItems = [];
      state.categories.editorId = null;
      state.customers.items = [];
      state.customers.filteredItems = [];
      state.customers.detailId = null;
      state.variations.parentId = null;
      state.variations.items = [];
      state.variations.editorId = null;
      state.orders.items = [];
      state.orders.filteredItems = [];
      state.orders.statuses = {};
      state.orders.pendingCount = null;
      state.orders.pendingItems = [];
      state.orders.pendingCountRequestId += 1;
      state.orders.pendingCountAnnouncement = null;
      state.orders.detailId = null;
      state.orders.detail = null;
      state.orders.detailTrigger = null;
      state.orders.viewTab = "overview";
      state.orders.editTab = "status";
      state.orders.editTrigger = null;
      state.orders.editReturnToDetail = false;
      state.orders.editControls = null;
      state.orders.shipment = null;
      state.orders.shipmentStatus = "idle";
      state.manualOrder.step = 0;
      state.manualOrder.busy = false;
      state.manualOrder.trigger = null;
      state.manualOrder.items = [];
      if (elements.manualOrderOverlay) {
        elements.manualOrderOverlay.hidden = true;
      }
      document.body.classList.remove("manual-order-open");
      state.inventory.items = [];
      state.inventory.summary = null;
      state.inventory.status = "secure";
      state.coupons.items = [];
      state.coupons.status = "secure";
      state.coupons.editorId = null;
      state.reviews.items = [];
      state.reviews.status = "secure";
      state.analytics.data = null;
      state.analytics.status = "secure";
      state.security.devices = [];
      state.security.newSessionsCount = 0;
      state.security.devicesStatus = "secure";
      state.security.auditItems = [];
      state.security.auditStatus = "secure";
      state.security.auditPage = 1;
      state.security.auditTotalPages = 0;
      state.security.auditEvent = "";
      closeProductEditor(true);
      closeProductView(true);
      closeProductFilterPanel();
      closeCategoryEditor();
      closeOrderDetail();
      closeOrderFilterPanel();
      closeCustomerDetail();
      closeCouponEditor();
      setProductsState("secure", "برای دریافت محصولات، ابتدا اتصال امن مدیر باید برقرار شود.");
      setCategoriesState("secure", "برای مدیریت دسته‌بندی‌ها، ابتدا اتصال امن مدیر را برقرار کنید.");
      setCustomersState("secure", "برای مشاهدهٔ مشتریان، ابتدا اتصال امن مدیر را برقرار کنید.");
      setOrdersState("secure", "برای مشاهدهٔ سفارش‌ها، ابتدا اتصال امن مدیر باید برقرار شود.");
      renderOrderStatusOptions();
      setInventoryState("secure", "برای مدیریت موجودی ابتدا اتصال امن مدیر را برقرار کنید.");
      setCouponsState("secure", "برای مدیریت کوپن‌ها ابتدا اتصال امن مدیر را برقرار کنید.");
      setReviewsState("secure", "برای مدیریت دیدگاه‌ها ابتدا اتصال امن مدیر را برقرار کنید.");
      setAnalyticsState(state.analytics.enabled ? "secure" : "disabled", "برای مشاهدهٔ تحلیل، ابتدا اتصال امن مدیر را برقرار کنید.");
      setDevicesState("secure", "برای مشاهدهٔ نشست‌ها، ابتدا اتصال امن مدیر را برقرار کنید.");
      setAuditState("secure", "برای مشاهدهٔ گزارش، ابتدا اتصال امن مدیر را برقرار کنید.");
    if (elements.sessionAlertPanel) {
      elements.sessionAlertPanel.hidden = true;
    }
    if (elements.notificationsButton) {
      elements.notificationsButton.hidden = true;
    }
      if (elements.notificationsPanel) {
        elements.notificationsPanel.hidden = true;
      }
      if (elements.notificationsButton) {
        elements.notificationsButton.setAttribute("aria-expanded", "false");
      }
      updatePendingOrderBadges();
      renderDashboardSummary();
    }
  }

  function activatePreviewMode() {
    var data = PREVIEW_STORE_DATA;
    state.activeSection = "dashboard";
    setPreviewMode(true);

    // Clear any previous session/data first, then expose the full authenticated shell.
    setAuthenticated(false, "");
    setAuthenticated(true, "");
    setCurrentUser(data.user, {
      analytics: { read: true },
      products: { read: true, write: true, create: true, update: true },
      categories: { read: true, write: true, create: true, update: true },
      orders: { read: true, write: true, create: true, update: true },
      customers: { read: true },
      inventory: { read: true, write: true, update: true },
      coupons: { read: true, write: true, create: true, update: true },
      reviews: { read: true, write: true, update: true },
      security: { read: true, write: true, update: true }
    });
    syncManualOrderAccess();

    applyConfig(normalizeConfig({
      site_url: window.location.origin,
      brand: data.brand,
      analytics: { enabled: true },
      currency: { code: "IRT", label: "تومان" }
    }));
    setConfigSource("preview");
    setConnectionState("connected", "اتصال نمایشی برقرار است", "داده‌های نمونه برای بررسی ظاهر بارگذاری شده‌اند؛ هیچ درخواستی به فروشگاه ارسال نمی‌شود.");

    state.products.filters = { query: "", type: "any", status: "any", sort: "updated" };
    state.products.items = data.products.map(normalizeProduct);
    state.products.filteredItems = [];
    state.products.status = "ready";
    if (elements.productSearch) elements.productSearch.value = "";
    if (elements.productTypeFilter) elements.productTypeFilter.value = "any";
    if (elements.productStatusFilter) elements.productStatusFilter.value = "any";
    if (elements.productSort) elements.productSort.value = "updated";

    state.categories.items = data.categories.map(normalizeCategory);
    state.categories.filteredItems = [];
    state.categories.status = "ready";
    if (elements.categorySearch) elements.categorySearch.value = "";

    state.orders.items = data.orders.map(normalizeOrder);
    state.orders.filteredItems = [];
    state.orders.statuses = {
      pending: "در انتظار پرداخت",
      processing: "در حال پردازش",
      "on-hold": "در انتظار بررسی",
      completed: "تکمیل‌شده",
      cancelled: "لغوشده"
    };
    state.orders.status = "ready";
    state.orders.pendingCount = 2;
    state.orders.pendingItems = state.orders.items.filter(isUnprocessedOrder).slice(0, 5);
    state.orders.page = 1;
    state.orders.total = state.orders.items.length;
    state.orders.totalPages = 1;
    state.orders.filters = {
      search: "",
      status: "any",
      dateFrom: "",
      dateTo: "",
      minTotal: "",
      maxTotal: "",
      customerId: "",
      paymentMethod: "",
      shippingMethod: ""
    };
    if (elements.orderSearch) elements.orderSearch.value = "";
    if (elements.orderDateFrom) setPersianDateInputValue(elements.orderDateFrom, "");
    if (elements.orderDateTo) setPersianDateInputValue(elements.orderDateTo, "");
    if (elements.orderMinTotal) elements.orderMinTotal.value = "";
    if (elements.orderMaxTotal) elements.orderMaxTotal.value = "";
    if (elements.orderCustomerId) elements.orderCustomerId.value = "";
    if (elements.orderPaymentMethod) elements.orderPaymentMethod.value = "";
    if (elements.orderShippingMethod) elements.orderShippingMethod.value = "";

    state.customers.items = data.customers.map(normalizeCustomer);
    state.customers.filteredItems = [];
    state.customers.status = "ready";
    state.customers.search = "";
    state.customers.page = 1;
    state.customers.total = state.customers.items.length;
    state.customers.totalPages = 1;
    if (elements.customerSearch) elements.customerSearch.value = "";

    state.inventory.items = data.inventory.map(normalizeInventoryItem);
    state.inventory.status = "ready";
    state.inventory.page = 1;
    state.inventory.total = state.inventory.items.length;
    state.inventory.totalPages = 1;
    state.inventory.filters = { search: "", stockStatus: "" };
    state.inventory.summary = { total: 6, low_stock: 2, out_of_stock: 1, on_backorder: 1 };
    if (elements.inventorySearch) elements.inventorySearch.value = "";
    if (elements.inventoryStatusFilter) elements.inventoryStatusFilter.value = "";

    state.coupons.items = data.coupons.map(normalizeCoupon);
    state.coupons.status = "ready";
    state.coupons.page = 1;
    state.coupons.total = state.coupons.items.length;
    state.coupons.totalPages = 1;
    state.coupons.search = "";
    if (elements.couponSearch) elements.couponSearch.value = "";

    state.reviews.items = data.reviews.map(normalizeReview);
    state.reviews.status = "ready";
    state.reviews.page = 1;
    state.reviews.total = state.reviews.items.length;
    state.reviews.totalPages = 1;
    state.reviews.search = "";
    state.reviews.statusFilter = "all";
    if (elements.reviewSearch) elements.reviewSearch.value = "";
    if (elements.reviewStatusFilter) elements.reviewStatusFilter.value = "all";

    state.analytics.range = "30d";
    state.analytics.data = normalizeAnalytics({ data: data.analytics });
    state.analytics.status = "ready";
    state.security.devices = data.devices.map(normalizeDevice);
    state.security.devicesStatus = "ready";
    state.security.auditItems = data.audit.map(normalizeAudit);
    state.security.auditStatus = "ready";
    state.security.auditPage = 1;
    state.security.auditTotalPages = 1;
    state.security.auditEvent = "";
    if (elements.auditEventFilter) elements.auditEventFilter.value = "";

    renderOrderStatusOptions();
    renderProductResults();
    renderCategoryResults();
    renderOrderResults();
    renderCustomerResults();
    renderInventorySummary();
    renderInventoryResults();
    setInventoryState("ready");
    renderCouponResults();
    setCouponsState("ready");
    renderReviewResults();
    setReviewsState("ready");
    renderAnalytics(state.analytics.data);
    setAnalyticsState("ready");
    renderDevices(state.security.devices);
    setDevicesState("ready");
    renderAudit(state.security.auditItems);
    setAuditState("ready");
    updatePendingOrderBadges();
    showView("dashboard");
  }

  function pairSession() {
    var pairUrl = apiUrl("pairUrl");
    var username = elements.pairingUsername.value.trim();
    var password = elements.pairingPassword.value;
    var code = elements.pairingCode.value.trim();

    if (!pairUrl) {
      showPairingMessage("پیکربندی API جفت‌سازی در سایت موجود نیست.");
      return Promise.resolve();
    }

    if (!username || !password || code.length < 8) {
      showPairingMessage("نام کاربری، رمز عبور و کد جفت‌سازی را کامل وارد کنید.");
      return Promise.resolve();
    }

    state.authBusy = true;
    elements.pairingSubmit.disabled = true;
    showPairingMessage("در حال اعتبارسنجی و ساخت نشست امن...");

    return fetchJsonWithTimeout(pairUrl, {
      method: "POST",
      headers: {
        "Content-Type": "application/json"
      },
      body: JSON.stringify({
        username: username,
        password: password,
        pairing_code: code,
        device_id: makeEphemeralDeviceId(),
        device_label: "PWA browser"
      })
    }).then(function (payload) {
      var data = payload && payload.data ? payload.data : payload;
      if (!data || typeof data.csrf_token !== "string" || !data.csrf_token) {
        throw new Error("missing-csrf");
      }

      elements.pairingPassword.value = "";
      elements.pairingCode.value = "";
      setCurrentUser(data.user, data.scopes);
      setAuthenticated(true, data.csrf_token);
      showPairingMessage("اتصال امن برقرار شد.");
      showView("dashboard");
      setConnectionState("connected", "اتصال امن برقرار است", "نشست مدیریتی فعال شد؛ داده‌ها فقط از API هم‌مبدأ دریافت می‌شوند.");
      return Promise.all([loadProducts(), loadProductAttributes(), loadProductShippingClasses(), loadCategories(), loadCustomers(), loadOrders(), loadInventory(), loadCoupons(), loadReviews(), loadAnalytics(), loadSecurity()]);
    }).catch(function (error) {
      setAuthenticated(false, "");
      showPairingMessage(responseErrorMessage(error, "جفت‌سازی انجام نشد؛ کد یا اطلاعات ورود را بررسی کنید."));
    }).finally(function () {
      state.authBusy = false;
      elements.pairingSubmit.disabled = false;
    });
  }

  function restoreSession() {
    var meUrl = apiUrl("meUrl");
    var csrfUrl = apiUrl("csrfUrl");
    var wasAuthenticated = state.authenticated;

    if (!meUrl || !csrfUrl) {
      setConnectionState("fallback", "نشست بررسی نشد", "نشانی بررسی نشست در پیکربندی سایت موجود نیست؛ نشست فعلی در رابط حفظ شد.");
      return Promise.resolve(wasAuthenticated);
    }

    return fetchJsonWithTimeout(meUrl).then(function (payload) {
      var data = payload && payload.data ? payload.data : payload;
      if (!data || !data.user || typeof data.user !== "object" || Array.isArray(data.user)) {
        throw new Error("invalid-session-user");
      }
      return fetchJsonWithTimeout(csrfUrl).then(function (csrfPayload) {
        var csrfData = csrfPayload && csrfPayload.data ? csrfPayload.data : csrfPayload;
        if (!csrfData || typeof csrfData.csrf_token !== "string" || !csrfData.csrf_token) {
          throw new Error("invalid-session-csrf");
        }

        setCurrentUser(data.user, data.scopes ? data.scopes : {});
        setAuthenticated(true, csrfData.csrf_token);
        showView("dashboard");
        setConnectionState("connected", "اتصال امن برقرار است", "نشست قبلی معتبر بود و بدون ذخیرهٔ توکن در مرورگر ادامه پیدا کرد.");
        return true;
      });
    }).catch(function (error) {
      if (error && (error.status === 401 || error.status === 403)) {
        setAuthenticated(false, "");
        return false;
      }

      if (error && (error.offline || !state.networkOnline)) {
        setConnectionState("offline", "اتصال اینترنت قطع است", "بررسی نشست قبلی انجام نشد؛ نشست فعلی در رابط حفظ شد. پس از اتصال دوباره تلاش کنید.");
      } else if (error && error.name === "AbortError") {
        setConnectionState("fallback", "بررسی نشست انجام نشد", "پاسخ سرور به‌موقع نرسید؛ نشست فعلی در رابط حفظ شد. دوباره تلاش کنید.");
      } else {
        setConnectionState("fallback", "بررسی نشست انجام نشد", "پاسخ معتبر از سرور دریافت نشد؛ نشست فعلی در رابط حفظ شد. دوباره تلاش کنید.");
      }

      return state.authenticated || wasAuthenticated;
    });
  }

  function logoutResponseIsValid(payload) {
    var data = payload && payload.data ? payload.data : payload;
    return Boolean(data && data.logged_out === true);
  }

  function showLogoutFailure(error) {
    if (error && (error.offline || !state.networkOnline)) {
      setConnectionState("offline", "خروج انجام نشد", "اتصال اینترنت قطع است؛ نشست شما همچنان معتبر است. پس از اتصال دوباره روی «خروج امن» بزنید.");
      return;
    }

    if (error && error.name === "AbortError") {
      setConnectionState("fallback", "خروج انجام نشد", "پاسخ سرور به‌موقع نرسید؛ نشست شما همچنان معتبر است. دوباره تلاش کنید.");
      return;
    }

    setConnectionState("fallback", "خروج انجام نشد", "پاسخ معتبر برای خروج دریافت نشد؛ نشست شما همچنان معتبر است. دوباره روی «خروج امن» بزنید.");
  }

  function clearSessionAndShowPairing(message) {
    setAuthenticated(false, "");
    showView("pairing");
    showPairingMessage(message);
    setConnectionState("fallback", "نشست معتبر نیست", "برای ادامه، دوباره جفت‌سازی امن را انجام دهید.");
  }

  function logoutSession() {
    if (state.previewMode) {
      setPreviewMode(false);
      setAuthenticated(false, "");
      applyConfig(normalizeConfig(DEFAULT_CONFIG));
      setConfigSource("fallback");
      showView("pairing");
      showPairingMessage("از حالت پیش‌نمایش خارج شدید؛ برای دیدن اطلاعات واقعی فروشگاه اتصال امن را برقرار کنید.");
      setConnectionState("fallback", "اتصال سایت برقرار نیست", "برای مشاهدهٔ اطلاعات واقعی، اتصال امن فروشگاه را برقرار کنید.");
      return Promise.resolve(true);
    }

    var logoutUrl = apiUrl("logoutUrl");

    if (!state.authenticated) {
      showView("pairing");
      return Promise.resolve(false);
    }

    if (!logoutUrl || !state.csrfToken) {
      showLogoutFailure(new Error("missing-logout-config"));
      return Promise.resolve(false);
    }

    return fetchJsonWithTimeout(logoutUrl, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-Fandoogh-CSRF": state.csrfToken
      },
      body: JSON.stringify({})
    }).then(function (payload) {
      if (!logoutResponseIsValid(payload)) {
        throw new Error("invalid-logout-response");
      }

      clearSessionAndShowPairing("با موفقیت از نشست خارج شدید؛ برای ورود دوباره جفت‌سازی کنید.");
      return true;
    }).catch(function (error) {
      if (error && (error.status === 401 || error.status === 403)) {
        clearSessionAndShowPairing("نشست دیگر معتبر نیست؛ برای ادامه دوباره جفت‌سازی کنید.");
        return false;
      }

      showLogoutFailure(error);
      return false;
    });
  }

  // [UI: PRODUCTS] کاتالوگ، فیلتر، کارت محصول، مودال مشاهده و ویرایش محصول.
  function productStateLabel(stateName) {
    var labels = {
      secure: "نیازمند اتصال امن",
      loading: "در حال دریافت",
      empty: "بدون محصول",
      error: "خطا در دریافت",
      ready: "آماده"
    };

    return labels[stateName] || labels.secure;
  }

  function setProductsState(stateName, detail) {
    state.products.status = stateName;
    elements.productsStateBadge.textContent = productStateLabel(stateName);
    elements.productsStateBadge.setAttribute("data-state", stateName);
    elements.productsSecureState.hidden = stateName !== "secure";
    elements.productsLoadingState.hidden = stateName !== "loading";
    elements.productsEmptyState.hidden = stateName !== "empty";
    elements.productsErrorState.hidden = stateName !== "error";
    elements.productsGrid.hidden = stateName !== "ready";

    if (stateName === "empty" && detail) {
      elements.productsEmptyText.textContent = formatDisplayText(detail);
    }

    if (stateName === "error" && detail) {
      elements.productsErrorText.textContent = formatDisplayText(detail);
    }
  }

  function normalizeProductAttributeOption(value) {
    var source = value && typeof value === "object" ? value : {};
    var rawValue = value && typeof value === "object" ? (typeof source.value !== "undefined" ? source.value : (typeof source.id !== "undefined" ? source.id : (source.name || source.label))) : value;
    var textValue = rawValue === null || typeof rawValue === "undefined" ? "" : String(rawValue);
    var label = safeText(source.label || source.name || textValue, textValue, 200);

    return {
      id: source.id === null || typeof source.id === "undefined" ? "" : String(source.id),
      value: textValue,
      label: label
    };
  }

  function normalizeProductAttribute(value) {
    var source = value && typeof value === "object" ? value : {};
    var options = Array.isArray(source.options) ? source.options.map(normalizeProductAttributeOption).filter(function (option) {
      return option.value !== "";
    }) : [];

    return {
      id: safeText(String(source.id || ""), "", 40),
      name: safeText(source.name, "", 100),
      label: safeText(source.label, source.name ? safeText(source.name, "", 100) : "", 120),
      options: options,
      visible: source.visible !== false,
      variation: Boolean(source.variation),
      global: Boolean(source.global)
    };
  }

  function normalizeProduct(value) {
    var source = value && typeof value === "object" ? value : {};
    var images = Array.isArray(source.images) ? source.images : [];
    var firstImage = images.length && images[0] && typeof images[0] === "object" ? images[0] : {};
    var categories = Array.isArray(source.categories) ? source.categories : [];
    var rawDiscountPercent = source.discount_percent || source.discountPercent;

    return {
      id: safeText(String(source.id || ""), "", 40),
      name: safeText(source.name || source.title, "محصول بدون نام", 120),
      slug: safeText(source.slug, "", 200),
      permalink: safeAssetUrl(source.permalink),
      type: safeText(source.type, "simple", 30),
      productKind: safeText(source.product_kind || source.productKind, source.downloadable ? "downloadable" : "physical", 30),
      price: safeText(source.price || source.regular_price || source.price_html, "قیمت ثبت نشده", 80),
      stock: safeText(source.stock_status || source.stock || "", "", 40),
      imageUrl: safeAssetUrl(source.image || source.image_url || firstImage.src),
      sku: safeText(source.sku, "", 100),
      regularPrice: safeText(source.regular_price, "", 40),
      salePrice: safeText(source.sale_price, "", 40),
      discountAmount: safeText(source.discount_amount || source.discountAmount, "", 40),
      discountPercent: rawDiscountPercent === null || typeof rawDiscountPercent === "undefined" ? "" : String(rawDiscountPercent),
      status: safeText(source.status, "draft", 30),
      manageStock: Boolean(source.manage_stock),
      stockQuantity: source.stock_quantity === null || typeof source.stock_quantity === "undefined" ? "" : String(source.stock_quantity),
      backorders: safeText(source.backorders, "no", 20),
      weight: safeText(String(source.weight || ""), "", 40),
      length: safeText(String(source.length || ""), "", 40),
      width: safeText(String(source.width || ""), "", 40),
      height: safeText(String(source.height || ""), "", 40),
      taxStatus: safeText(source.tax_status, "taxable", 20),
      taxClass: safeText(source.tax_class, "", 80),
      catalogVisibility: safeText(source.catalog_visibility || source.catalogVisibility, "visible", 20),
      shippingClassId: source.shipping_class_id === null || typeof source.shipping_class_id === "undefined" ? "0" : String(source.shipping_class_id),
      shippingClass: safeText(source.shipping_class, "", 100),
      shippingClassName: safeText(source.shipping_class_name || source.shippingClassName, "", 160),
      purchaseNote: safeText(source.purchase_note, "", 1000),
      reviewsAllowed: !(source.reviews_allowed === false || source.reviews_allowed === 0 || source.reviews_allowed === "0" || source.reviewsAllowed === false || source.reviewsAllowed === 0 || source.reviewsAllowed === "0"),
      downloadLimit: source.download_limit === null || typeof source.download_limit === "undefined" ? "" : String(source.download_limit),
      downloadExpiry: source.download_expiry === null || typeof source.download_expiry === "undefined" ? "" : String(source.download_expiry),
      upsellIds: Array.isArray(source.upsell_ids || source.upsellIds) ? (source.upsell_ids || source.upsellIds).map(function (id) { return String(id); }).filter(Boolean) : [],
      crossSellIds: Array.isArray(source.cross_sell_ids || source.crossSellIds) ? (source.cross_sell_ids || source.crossSellIds).map(function (id) { return String(id); }).filter(Boolean) : [],
      totalSales: source.total_sales === null || typeof source.total_sales === "undefined" ? "0" : String(source.total_sales),
      createdAt: safeText(source.created_at || source.createdAt, "", 80),
      updatedAt: safeText(source.updated_at || source.updatedAt, "", 80),
      soldIndividually: Boolean(source.sold_individually),
      virtual: Boolean(source.virtual),
      downloadable: Boolean(source.downloadable),
      shortDescription: safeText(source.short_description, "", 5000),
      description: safeText(source.description, "", 20000),
      attributes: Array.isArray(source.attributes) ? source.attributes.map(normalizeProductAttribute) : [],
      images: images.map(function (image, index) {
        var imageSource = image && typeof image === "object" ? image : {};
        return {
          id: safeText(String(imageSource.id || ""), "", 40),
          src: safeAssetUrl(imageSource.src || imageSource.url),
          alt: safeText(imageSource.alt, "تصویر محصول", 160),
          featured: index === 0
        };
      }).filter(function (image) {
        return image.id && image.src;
      }),
      categoryIds: categories.map(function (category) {
        return category && category.id ? String(category.id) : "";
      }).filter(Boolean),
      categories: categories.map(function (category) {
        var item = category && typeof category === "object" ? category : {};
        return {
          id: item.id ? String(item.id) : "",
          name: safeText(item.name, "دسته‌بندی", 160),
          slug: safeText(item.slug, "", 160),
          parent: item.parent ? String(item.parent) : "0",
          parentName: safeText(item.parent_name || item.parentName, "", 160),
          parentSlug: safeText(item.parent_slug || item.parentSlug, "", 160)
        };
      }).filter(function (category) {
        return category.id;
      })
    };
  }

  function extractProducts(payload) {
    if (Array.isArray(payload)) {
      return payload.map(normalizeProduct);
    }

    if (payload && Array.isArray(payload.products)) {
      return payload.products.map(normalizeProduct);
    }

    if (payload && Array.isArray(payload.data)) {
      return payload.data.map(normalizeProduct);
    }

    return [];
  }

  function createTextElement(tagName, className, text) {
    var node = document.createElement(tagName);
    node.className = className;
    node.textContent = formatDisplayText(text);
    return node;
  }

  function createProductIcon(name) {
    var svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
    svg.setAttribute("class", "ui-icon");
    svg.setAttribute("viewBox", "0 0 24 24");
    svg.setAttribute("aria-hidden", "true");
    var shapes = {
      eye: [
        { tag: "path", attrs: { d: "M2.5 12s3.5-5.5 9.5-5.5 9.5 5.5 9.5 5.5-3.5 5.5-9.5 5.5S2.5 12 2.5 12Z" } },
        { tag: "circle", attrs: { cx: "12", cy: "12", r: "2.5" } }
      ],
      edit: [
        { tag: "path", attrs: { d: "M4 16.5V20h3.5L18.8 8.7a2.2 2.2 0 0 0-3.1-3.1L4 16.5Z" } },
        { tag: "path", attrs: { d: "m14.5 7.5 2 2" } }
      ],
      print: [
        { tag: "path", attrs: { d: "M6 9V4h12v5M6 17H4a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2h-2" } },
        { tag: "path", attrs: { d: "M6 14h12v6H6z" } },
        { tag: "path", attrs: { d: "M18 12h.01" } }
      ]
    };
    (shapes[name] || []).forEach(function (shape) {
      var node = document.createElementNS("http://www.w3.org/2000/svg", shape.tag);
      Object.keys(shape.attrs).forEach(function (attribute) {
        node.setAttribute(attribute, shape.attrs[attribute]);
      });
      svg.appendChild(node);
    });
    return svg;
  }

  function productStatusLabel(status) {
    return {
      publish: "منتشرشده",
      draft: "پیش‌نویس",
      pending: "در انتظار بررسی",
      private: "خصوصی"
    }[status] || "وضعیت نامشخص";
  }

  function productKindLabel(kind) {
    return {
      physical: "فیزیکی",
      downloadable: "دانلودی",
      subscription: "اشتراکی"
    }[kind] || "محصول";
  }

  function productPriceLabel(product) {
    var price = product && product.price ? String(product.price) : "";
    if (/^[0-9۰-۹٬,٫.\s]+$/.test(price)) {
      return formatStoreAmount(price);
    }
    return price || "قیمت ثبت نشده";
  }

  function productDiscountPercentLabel(value) {
    var normalized = toEnglishDigits(value)
      .trim()
      .replace(/[٬,\s]/g, "")
      .replace(/٫/g, ".")
      .replace(/[%٪]/g, "");
    var number = Number(normalized);
    if (!isFinite(number) || number <= 0) {
      return "";
    }
    return formatDisplayNumber(Math.round(number), { group: false });
  }

  function productStockLabel(product) {
    if (product && product.manageStock && product.stockQuantity !== "") {
      return "موجودی: " + formatDisplayNumber(product.stockQuantity) + " قلم";
    }
    return {
      instock: "موجود",
      outofstock: "ناموجود",
      onbackorder: "پیش‌فروش"
    }[product && product.stock] || "موجودی مدیریت نمی‌شود";
  }

  function renderProductCards(items) {
    while (elements.productsGrid.firstChild) {
      elements.productsGrid.removeChild(elements.productsGrid.firstChild);
    }

    items.forEach(function (product) {
      var card = document.createElement("article");
      card.className = "product-card";
      card.setAttribute("role", "listitem");
      card.dataset.productId = product.id;
      card.dataset.productKind = product.productKind || "physical";
      card.dataset.productType = product.type || "simple";
      card.dataset.productStatus = product.status || "draft";

      var imageWrap = document.createElement("div");
      imageWrap.className = "product-card-image-wrap";
      if (product.imageUrl) {
        var image = document.createElement("img");
        image.className = "product-card-image";
        image.src = product.imageUrl;
        image.alt = product.name;
        image.loading = "lazy";
        image.referrerPolicy = "no-referrer";
        imageWrap.appendChild(image);
      } else {
        imageWrap.appendChild(createTextElement("div", "product-card-image product-card-image-fallback", "ف"));
      }
      card.appendChild(imageWrap);

      var discountPercentLabel = productDiscountPercentLabel(product.discountPercent);
      if (discountPercentLabel) {
        imageWrap.appendChild(createTextElement("span", "product-card-discount", discountPercentLabel + "٪ تخفیف"));
      }

      var content = document.createElement("div");
      content.className = "product-card-content";

      var topline = document.createElement("div");
      topline.className = "product-card-topline";
      topline.appendChild(createTextElement("h3", "product-card-name", product.name));
      var kindAndStatus = document.createElement("div");
      kindAndStatus.className = "product-card-meta-pills";
      if (product.type === "variable" || product.type === "variable-subscription") {
        kindAndStatus.appendChild(createTextElement("span", "product-card-kind", "متغیر"));
      }
      if (kindAndStatus.firstChild) {
        topline.appendChild(kindAndStatus);
      }
      content.appendChild(topline);

      var stockLine = document.createElement("div");
      stockLine.className = "product-card-stock-line";
      stockLine.appendChild(createTextElement("span", "product-card-stock-label", productStockLabel(product)));
      if (product.sku) {
        stockLine.appendChild(createTextElement("span", "product-card-sku", "SKU: " + product.sku));
      }
      content.appendChild(stockLine);

      var priceRow = document.createElement("div");
      priceRow.className = "product-card-price-row";
      priceRow.appendChild(createTextElement("strong", "product-card-price", productPriceLabel(product)));
      if (product.salePrice && product.regularPrice && product.salePrice !== product.regularPrice) {
        priceRow.appendChild(createTextElement("del", "product-card-regular-price", formatStoreAmount(product.regularPrice)));
      }
      content.appendChild(priceRow);

      content.appendChild(createTextElement("span", "product-card-category-hint", product.categories && product.categories.length ? product.categories[0].name : "بدون دسته‌بندی"));

      content.appendChild(document.createElement("div")).className = "product-card-divider";

      var actions = document.createElement("div");
      actions.className = "product-card-actions";

      var viewButton = document.createElement("button");
      viewButton.type = "button";
      viewButton.className = "product-card-action product-view-button";
      viewButton.setAttribute("aria-label", "دیدن اطلاعات " + product.name);
      viewButton.title = "دیدن اطلاعات محصول";
      viewButton.appendChild(createProductIcon("eye"));
      viewButton.addEventListener("click", function () {
        openProductView(product.id, viewButton);
      });
      actions.appendChild(viewButton);

      var editButton = document.createElement("button");
      editButton.type = "button";
      editButton.className = "product-card-action product-edit-button";
      editButton.setAttribute("aria-label", "ویرایش " + product.name);
      editButton.title = "ویرایش محصول";
      editButton.appendChild(createProductIcon("edit"));
      editButton.addEventListener("click", function () {
        openProductEditor(product.id);
      });
      actions.appendChild(editButton);
      content.appendChild(actions);

      card.appendChild(content);
      elements.productsGrid.appendChild(card);
    });
  }

  var PRODUCT_VIEW_TAB_DEFINITIONS = [
    { key: "summary", label: "اطلاعات اصلی" },
    { key: "attributes", label: "ویژگی‌ها" },
    { key: "variations", label: "متغیرها" },
    { key: "images", label: "تصاویر" },
    { key: "description", label: "توضیحات" }
  ];

  function productViewValue(value, fallback) {
    var text = value === null || typeof value === "undefined" ? "" : String(value).trim();
    return text || fallback || "—";
  }

  function productViewAmount(value) {
    var text = value === null || typeof value === "undefined" ? "" : String(value).trim();
    return /^[0-9۰-۹٬,٫.\s]+$/.test(text) ? formatStoreAmount(text) : productViewValue(text, "قیمت ثبت نشده");
  }

  function appendProductViewField(container, label, value, className) {
    var field = document.createElement("article");
    field.className = "product-view-field" + (className ? " " + className : "");
    field.appendChild(createTextElement("span", "product-view-field-label", label));
    field.appendChild(createTextElement("strong", "product-view-field-value", value));
    container.appendChild(field);
    return field;
  }

  function productViewSection(title, className) {
    var section = document.createElement("section");
    section.className = "product-view-section" + (className ? " " + className : "");
    section.appendChild(createTextElement("h4", "product-view-section-title", title));
    return section;
  }

  function productViewStock(product) {
    if (product && product.manageStock && product.stockQuantity !== "") {
      return formatDisplayNumber(product.stockQuantity) + " قلم";
    }
    return {
      instock: "موجود",
      outofstock: "ناموجود",
      onbackorder: "پیش‌فروش"
    }[product && product.stock] || "مدیریت نمی‌شود";
  }

  function productViewCategories(product) {
    var categories = product && Array.isArray(product.categories) ? product.categories : [];
    if (!categories.length) {
      return { category: "بدون دسته‌بندی", subcategory: "—" };
    }
    var root = categories.filter(function (category) { return String(category.parent || "0") === "0"; })[0] || categories[0];
    var child = categories.filter(function (category) { return String(category.parent || "0") !== "0"; })[0] || null;
    return {
      category: root && root.name ? root.name : "بدون دسته‌بندی",
      subcategory: child && child.name ? child.name : "—"
    };
  }

  function renderProductViewSummary(product) {
    var fragment = document.createDocumentFragment();
    var hero = document.createElement("div");
    hero.className = "product-view-summary-hero product-view-summary-hero--text-only";
    var heroText = document.createElement("div");
    heroText.className = "product-view-summary-hero-text";
    heroText.appendChild(createTextElement("h4", "product-view-summary-name", product.name));
    var pills = document.createElement("div");
    pills.className = "product-view-pills";
    pills.appendChild(createTextElement("span", "product-view-pill product-view-pill-status", productStatusLabel(product.status)));
    pills.appendChild(createTextElement("span", "product-view-pill product-view-pill-kind", productKindLabel(product.productKind)));
    heroText.appendChild(pills);
    hero.appendChild(heroText);
    fragment.appendChild(hero);

    var categories = productViewCategories(product);
    var stats = document.createElement("div");
    stats.className = "product-view-field-grid product-view-summary-grid";
    appendProductViewField(stats, "قیمت فعلی", productViewAmount(product.price), "is-emphasis");
    appendProductViewField(stats, "قیمت عادی", product.regularPrice ? productViewAmount(product.regularPrice) : "—");
    appendProductViewField(stats, "موجودی", productViewStock(product));
    appendProductViewField(stats, "فروش رفته", formatDisplayNumber(product.totalSales || "0") + " قلم");
    var discountPercentLabel = productDiscountPercentLabel(product.discountPercent);
    if (discountPercentLabel) {
      appendProductViewField(stats, "تخفیف", discountPercentLabel + "٪" + (product.discountAmount ? " · " + productViewAmount(product.discountAmount) : ""), "is-discount");
    }
    appendProductViewField(stats, "دسته‌بندی", categories.category);
    appendProductViewField(stats, "زیردسته", categories.subcategory);
    appendProductViewField(stats, "SKU", productViewValue(product.sku, "بدون SKU"));
    fragment.appendChild(stats);

    var metaSection = productViewSection("تنظیمات فروشگاه", "product-view-meta-section");
    var metaGrid = document.createElement("div");
    metaGrid.className = "product-view-field-grid product-view-meta-grid";
    appendProductViewField(metaGrid, "نمایش در فروشگاه", {
      visible: "فروشگاه و جست‌وجو",
      catalog: "فقط فروشگاه",
      search: "فقط جست‌وجو",
      hidden: "مخفی"
    }[product.catalogVisibility] || "فروشگاه و جست‌وجو");
    appendProductViewField(metaGrid, "کلاس ارسال", product.shippingClassName || product.shippingClass || "بدون کلاس ارسال");
    appendProductViewField(metaGrid, "دیدگاه", product.reviewsAllowed ? "فعال" : "غیرفعال");
    if (product.updatedAt) {
      appendProductViewField(metaGrid, "آخرین ویرایش", formatDisplayDate(product.updatedAt, true));
    }
    metaSection.appendChild(metaGrid);
    fragment.appendChild(metaSection);
    return fragment;
  }

  function renderProductViewAttributes(product) {
    var section = productViewSection("ویژگی‌های محصول");
    var attributes = Array.isArray(product.attributes) ? product.attributes : [];
    if (!attributes.length) {
      section.appendChild(createTextElement("p", "product-view-empty", "برای این محصول ویژگی‌ای ثبت نشده است."));
      return section;
    }

    var grid = document.createElement("div");
    grid.className = "product-view-attribute-grid";
    attributes.forEach(function (attribute) {
      var card = document.createElement("article");
      card.className = "product-view-attribute-card";
      var heading = document.createElement("div");
      heading.className = "product-view-attribute-heading";
      heading.appendChild(createTextElement("h5", "product-view-attribute-name", attribute.label || attribute.name));
      var flags = document.createElement("div");
      flags.className = "product-view-attribute-flags";
      if (attribute.visible) flags.appendChild(createTextElement("span", "product-view-flag", "نمایش")).classList.add("is-positive");
      if (attribute.variation) flags.appendChild(createTextElement("span", "product-view-flag", "برای متغیر")).classList.add("is-accent");
      heading.appendChild(flags);
      card.appendChild(heading);
      var options = document.createElement("div");
      options.className = "product-view-option-list";
      (attribute.options || []).forEach(function (option) {
        options.appendChild(createTextElement("span", "product-view-option", option.label || option.value));
      });
      card.appendChild(options);
      grid.appendChild(card);
    });
    section.appendChild(grid);
    return section;
  }

  function formatProductViewVariationAttributes(attributes) {
    if (!attributes || typeof attributes !== "object") {
      return "بدون ویژگی";
    }
    var keys = Object.keys(attributes);
    if (!keys.length) {
      return "بدون ویژگی";
    }
    return keys.map(function (key) {
      return key.replace(/^pa_/, "") + ": " + String(attributes[key]);
    }).join(" · ");
  }

  function renderProductViewVariations(product) {
    var section = productViewSection("variationهای محصول");
    var variations = Array.isArray(state.products.viewVariations) ? state.products.viewVariations : [];
    if (!variations.length) {
      section.appendChild(createTextElement("p", "product-view-empty", "برای این محصول متغیری ثبت نشده است."));
      return section;
    }
    var grid = document.createElement("div");
    grid.className = "product-view-variation-grid";
    variations.forEach(function (variation) {
      var card = document.createElement("article");
      card.className = "product-view-variation-card";
      var heading = document.createElement("div");
      heading.className = "product-view-variation-heading";
      heading.appendChild(createTextElement("h5", "product-view-variation-name", "متغیر #" + formatDisplayId(variation.id)));
      heading.appendChild(createTextElement("span", "product-view-variation-status", productStatusLabel(variation.status)));
      card.appendChild(heading);
      card.appendChild(createTextElement("p", "product-view-variation-attributes", formatProductViewVariationAttributes(variation.attributes)));
      var meta = document.createElement("div");
      meta.className = "product-view-variation-meta";
      appendProductViewField(meta, "قیمت", productViewAmount(variation.price || variation.regularPrice));
      appendProductViewField(meta, "موجودی", variation.manageStock && variation.stockQuantity !== "" ? formatDisplayNumber(variation.stockQuantity) + " قلم" : productViewValue(variation.stockStatus, "مدیریت نمی‌شود"));
      if (variation.sku) appendProductViewField(meta, "SKU", variation.sku);
      card.appendChild(meta);
      grid.appendChild(card);
    });
    section.appendChild(grid);
    return section;
  }

  function createProductViewChevron(direction) {
    var svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
    svg.setAttribute("class", "ui-icon");
    svg.setAttribute("viewBox", "0 0 24 24");
    svg.setAttribute("aria-hidden", "true");
    var path = document.createElementNS("http://www.w3.org/2000/svg", "path");
    path.setAttribute("d", direction === "next" ? "m9 5 7 7-7 7" : "m15 5-7 7 7 7");
    svg.appendChild(path);
    return svg;
  }

  function renderProductViewImages(product) {
    var section = document.createElement("section");
    section.className = "product-view-section product-view-images-section";
    section.setAttribute("aria-label", "تصاویر محصول");
    var images = Array.isArray(product.images) ? product.images : [];
    if (!images.length) {
      section.appendChild(createTextElement("p", "product-view-empty", "برای این محصول تصویری ثبت نشده است."));
      return section;
    }
    state.products.viewImageIndex = Math.max(0, Math.min(images.length - 1, state.products.viewImageIndex));
    var current = images[state.products.viewImageIndex];
    var carousel = document.createElement("div");
    carousel.className = "product-view-carousel";
    var imageFrame = document.createElement("figure");
    imageFrame.className = "product-view-image-frame";
    var previous = document.createElement("button");
    previous.type = "button";
    previous.className = "product-view-carousel-button is-previous";
    previous.setAttribute("aria-label", "تصویر قبلی");
    previous.title = "تصویر قبلی";
    previous.disabled = images.length < 2;
    previous.appendChild(createProductViewChevron("previous"));
    previous.addEventListener("click", function () {
      state.products.viewImageIndex = (state.products.viewImageIndex - 1 + images.length) % images.length;
      renderProductViewContent("images");
    });
    imageFrame.appendChild(previous);
    var image = document.createElement("img");
    image.src = current.src;
    image.alt = current.alt || product.name;
    image.loading = "eager";
    image.referrerPolicy = "no-referrer";
    imageFrame.appendChild(image);
    var next = document.createElement("button");
    next.type = "button";
    next.className = "product-view-carousel-button is-next";
    next.setAttribute("aria-label", "تصویر بعدی");
    next.title = "تصویر بعدی";
    next.disabled = images.length < 2;
    next.appendChild(createProductViewChevron("next"));
    next.addEventListener("click", function () {
      state.products.viewImageIndex = (state.products.viewImageIndex + 1) % images.length;
      renderProductViewContent("images");
    });
    imageFrame.appendChild(next);
    carousel.appendChild(imageFrame);
    section.appendChild(carousel);

    var thumbnails = document.createElement("div");
    thumbnails.className = "product-view-thumbnail-list";
    images.forEach(function (item, index) {
      var thumbnail = document.createElement("button");
      thumbnail.type = "button";
      thumbnail.className = "product-view-thumbnail" + (index === state.products.viewImageIndex ? " is-active" : "");
      thumbnail.setAttribute("aria-label", "نمایش تصویر " + formatDisplayNumber(index + 1));
      thumbnail.setAttribute("aria-pressed", index === state.products.viewImageIndex ? "true" : "false");
      var thumbnailImage = document.createElement("img");
      thumbnailImage.src = item.src;
      thumbnailImage.alt = item.alt || product.name;
      thumbnailImage.loading = "lazy";
      thumbnail.appendChild(thumbnailImage);
      thumbnail.addEventListener("click", function () {
        state.products.viewImageIndex = index;
        renderProductViewContent("images");
      });
      thumbnails.appendChild(thumbnail);
    });
    section.appendChild(thumbnails);
    return section;
  }

  function renderProductViewDescription(product) {
    var fragment = document.createDocumentFragment();
    var shortSection = productViewSection("توضیح کوتاه");
    shortSection.appendChild(createTextElement("p", "product-view-rich-text", product.shortDescription || "توضیح کوتاهی ثبت نشده است."));
    fragment.appendChild(shortSection);
    var fullSection = productViewSection("توضیحات کامل");
    fullSection.appendChild(createTextElement("p", "product-view-rich-text", product.description || "توضیحات کاملی ثبت نشده است."));
    fragment.appendChild(fullSection);
    if (product.purchaseNote) {
      var noteSection = productViewSection("یادداشت خرید");
      noteSection.appendChild(createTextElement("p", "product-view-rich-text", product.purchaseNote));
      fragment.appendChild(noteSection);
    }
    return fragment;
  }

  function renderProductViewContent(tabKey) {
    if (!elements.productViewTabContent || !state.products.viewProduct) {
      return;
    }
    while (elements.productViewTabContent.firstChild) {
      elements.productViewTabContent.removeChild(elements.productViewTabContent.firstChild);
    }
    var product = state.products.viewProduct;
    if (tabKey === "attributes") {
      elements.productViewTabContent.appendChild(renderProductViewAttributes(product));
    } else if (tabKey === "variations") {
      elements.productViewTabContent.appendChild(renderProductViewVariations(product));
    } else if (tabKey === "images") {
      elements.productViewTabContent.appendChild(renderProductViewImages(product));
    } else if (tabKey === "description") {
      elements.productViewTabContent.appendChild(renderProductViewDescription(product));
    } else {
      elements.productViewTabContent.appendChild(renderProductViewSummary(product));
    }
  }

  function setProductViewTab(tabKey, shouldFocus) {
    if (!state.products.viewProduct) {
      return;
    }
    var hasVariations = state.products.viewProduct.type === "variable" || state.products.viewProduct.type === "variable-subscription";
    if (tabKey === "variations" && !hasVariations) {
      tabKey = "summary";
    }
    if (!PRODUCT_VIEW_TAB_DEFINITIONS.some(function (definition) { return definition.key === tabKey; })) {
      tabKey = "summary";
    }
    state.products.viewStep = tabKey;
    Array.prototype.forEach.call(elements.productViewTabs.querySelectorAll("[data-product-view-tab]"), function (button) {
      var isActive = button.dataset.productViewTab === tabKey;
      button.classList.toggle("is-active", isActive);
      button.setAttribute("aria-selected", isActive ? "true" : "false");
      button.tabIndex = isActive ? 0 : -1;
      if (button.dataset.productViewTab === "variations") {
        button.hidden = !hasVariations;
      }
      if (isActive && shouldFocus) {
        button.focus();
      }
    });
    renderProductViewContent(tabKey);
  }

  function setProductViewLoading(isLoading) {
    elements.productViewLoadingState.hidden = !isLoading;
    elements.productViewErrorState.hidden = true;
    elements.productViewTabContent.hidden = Boolean(isLoading);
    if (isLoading) {
      while (elements.productViewTabContent.firstChild) {
        elements.productViewTabContent.removeChild(elements.productViewTabContent.firstChild);
      }
    }
  }

  function setProductViewError(message) {
    elements.productViewLoadingState.hidden = true;
    elements.productViewTabContent.hidden = true;
    elements.productViewErrorState.hidden = false;
    elements.productViewErrorText.textContent = formatDisplayText(message || "اطلاعات محصول در دسترس نیست.");
  }

  function loadProductViewVariations(parentId) {
    var url = variationCollectionUrl(parentId);
    if (!url || !state.authenticated) {
      return Promise.resolve([]);
    }
    return fetchJsonWithTimeout(url).then(function (payload) {
      return extractVariations(payload);
    }).catch(function () {
      return [];
    });
  }

  function openProductView(productId, trigger) {
    if (!state.authenticated) {
      showPairingMessage("برای مشاهدهٔ محصول ابتدا اتصال امن مدیر را برقرار کنید.");
      showView("pairing");
      return Promise.resolve(false);
    }
    var product = state.products.items.filter(function (item) { return item.id === String(productId); })[0];
    var detailUrl = product ? productResourceUrl(product.id) : "";
    if (!product || (!state.previewMode && !detailUrl)) {
      setProductsState("error", "اطلاعات کامل محصول برای مشاهده در دسترس نیست.");
      return Promise.resolve(false);
    }

    state.products.viewId = product.id;
    state.products.viewProduct = null;
    state.products.viewVariations = [];
    state.products.viewStep = "summary";
    state.products.viewImageIndex = 0;
    state.products.viewTrigger = trigger || (document.activeElement !== document.body ? document.activeElement : null);
    state.products.viewLoadRequestId += 1;
    var requestId = state.products.viewLoadRequestId;
    elements.productViewOverlay.hidden = false;
    elements.productViewTitle.textContent = formatDisplayText(product.name);
    elements.productViewEdit.disabled = true;
    document.body.classList.add("product-view-open");
    setProductViewLoading(true);
    setProductViewTab("summary", false);

    if (state.previewMode) {
      state.products.viewProduct = product;
      state.products.viewVariations = [];
      elements.productViewTitle.textContent = formatDisplayText(product.name);
      elements.productViewEdit.disabled = true;
      setProductViewLoading(false);
      setProductViewTab("summary", false);
      if (elements.closeProductView) {
        elements.closeProductView.focus();
      }
      return Promise.resolve(true);
    }

    return fetchJsonWithTimeout(detailUrl).then(function (payload) {
      if (requestId !== state.products.viewLoadRequestId || state.products.viewId !== product.id) {
        return false;
      }
      var data = payload && payload.data ? payload.data : payload;
      var detail = normalizeProduct(data);
      if (!detail.id || detail.id !== product.id) {
        throw new Error("invalid-product-detail");
      }
      state.products.viewProduct = detail;
      var variationPromise = detail.type === "variable" || detail.type === "variable-subscription" ? loadProductViewVariations(detail.id) : Promise.resolve([]);
      return variationPromise.then(function (variations) {
        if (requestId !== state.products.viewLoadRequestId) {
          return false;
        }
        state.products.viewVariations = variations;
        elements.productViewTitle.textContent = formatDisplayText(detail.name);
        elements.productViewEdit.disabled = false;
        setProductViewLoading(false);
        setProductViewTab("summary", false);
        elements.closeProductView.focus();
        return true;
      });
    }).catch(function (error) {
      if (requestId !== state.products.viewLoadRequestId) {
        return false;
      }
      if (error && (error.status === 401 || error.status === 403)) {
        closeProductView(true);
        setAuthenticated(false, "");
        showView("pairing");
        showPairingMessage("نشست یا مجوز مشاهدهٔ جزئیات محصول معتبر نیست.");
        return false;
      }
      setProductViewError(responseErrorMessage(error, "دریافت اطلاعات کامل محصول انجام نشد."));
      return false;
    });
  }

  function closeProductView(force) {
    if (!elements.productViewOverlay) {
      return;
    }
    var trigger = state.products.viewTrigger;
    state.products.viewLoadRequestId += 1;
    state.products.viewId = null;
    state.products.viewProduct = null;
    state.products.viewVariations = [];
    state.products.viewStep = "summary";
    state.products.viewImageIndex = 0;
    state.products.viewTrigger = null;
    elements.productViewOverlay.hidden = true;
    elements.productViewEdit.disabled = true;
    elements.productViewTabContent.hidden = false;
    while (elements.productViewTabContent.firstChild) {
      elements.productViewTabContent.removeChild(elements.productViewTabContent.firstChild);
    }
    document.body.classList.remove("product-view-open");
    if (!force && trigger && typeof trigger.focus === "function" && document.contains(trigger)) {
      trigger.focus();
    }
  }

  function editProductFromView() {
    var product = state.products.viewProduct;
    if (!product) {
      return;
    }
    closeProductView(true);
    openProductEditor(product.id);
  }

  function syncProductFilterForm() {
    var filters = state.products.filters || {};
    if (elements.productTypeFilter) {
      elements.productTypeFilter.value = filters.type || "any";
    }
    if (elements.productStatusFilter) {
      elements.productStatusFilter.value = filters.status || "any";
    }
    if (elements.productSort) {
      elements.productSort.value = filters.sort || "updated";
    }
  }

  function setProductFilterPanelOpen(isOpen) {
    if (!elements.productFilterPanel || !elements.productFilterToggle) {
      return;
    }

    var shouldOpen = Boolean(isOpen);
    if (shouldOpen) {
      syncProductFilterForm();
    }
    elements.productFilterPanel.hidden = !shouldOpen;
    elements.productFilterToggle.setAttribute("aria-expanded", String(shouldOpen));
    if (elements.productFilterBackdrop) {
      elements.productFilterBackdrop.hidden = !shouldOpen;
    }
    document.body.classList.toggle("product-filter-open", shouldOpen);

    if (!shouldOpen) {
      syncProductFilterForm();
    } else if (elements.productTypeFilter) {
      window.setTimeout(function () {
        if (!elements.productFilterPanel.hidden) {
          elements.productTypeFilter.focus();
        }
      }, 0);
    }
  }

  function toggleProductFilterPanel() {
    if (!elements.productFilterPanel) {
      return;
    }
    setProductFilterPanelOpen(elements.productFilterPanel.hidden);
  }

  function closeProductFilterPanel() {
    setProductFilterPanelOpen(false);
  }

  function applyProductFilters() {
    renderProductResults();
    closeProductFilterPanel();
  }

  function resetProductFilters() {
    if (elements.productTypeFilter) {
      elements.productTypeFilter.value = "any";
    }
    if (elements.productStatusFilter) {
      elements.productStatusFilter.value = "any";
    }
    if (elements.productSort) {
      elements.productSort.value = "updated";
    }
    renderProductResults();
    closeProductFilterPanel();
  }

  function scheduleProductSearch() {
    if (!elements.productSearch) {
      return;
    }

    state.products.filters.query = elements.productSearch.value.trim();
    if (state.products.searchTimer) {
      window.clearTimeout(state.products.searchTimer);
    }

    // Keep the current page responsive while typing, then ask the API for
    // the complete matching set so products outside the first page are found.
    renderProductResults();
    var query = state.products.filters.query;
    state.products.searchTimer = window.setTimeout(function () {
      state.products.searchTimer = null;
      loadProducts(query);
    }, 350);
  }

  function renderProductResults() {
    if (state.products.status !== "ready" && state.products.status !== "empty" && !state.products.items.length) {
      return;
    }

    var query = normalizeSearchText(elements.productSearch.value);
    var type = elements.productTypeFilter.value || "any";
    var status = elements.productStatusFilter.value || "any";
    var sort = elements.productSort.value || "updated";
    state.products.filters = { query: elements.productSearch.value.trim(), type: type, status: status, sort: sort };
    var filteredItems = state.products.items.filter(function (product) {
      return searchTextIncludes([product.id, product.name, product.sku, product.stock, product.status], query) &&
         (type === "any" || (type === "variable" && (product.type === "variable" || product.type === "variable-subscription")) || product.type === type) &&
        (status === "any" || product.status === status);
    });

    filteredItems.sort(function (left, right) {
      if (sort === "name") return left.name.localeCompare(right.name, "fa");
      if (sort === "price-high" || sort === "price-low") {
        var leftPrice = Number(normalizeNumericInput(left.price)) || 0;
        var rightPrice = Number(normalizeNumericInput(right.price)) || 0;
        return sort === "price-high" ? rightPrice - leftPrice : leftPrice - rightPrice;
      }
      return 0;
    });

    state.products.filteredItems = filteredItems;

    if (!filteredItems.length) {
      setProductsState("empty", query ? "محصولی با این عبارت پیدا نشد." : "هنوز محصولی برای نمایش وجود ندارد.");
      return;
    }

    renderProductCards(filteredItems);
    setProductsState("ready");
  }

  function bulkPriceUrl() {
    var base = apiUrl("productsUrl");
    if (!base) {
      return "";
    }
    try {
      return new URL("bulk-price/", base.replace(/\/?$/, "/")).toString();
    } catch (error) {
      return "";
    }
  }

  function setBulkPriceMessage(message, stateName) {
    if (!elements.bulkPriceMessage) {
      return;
    }
    elements.bulkPriceMessage.textContent = formatDisplayText(message || "");
    elements.bulkPriceMessage.hidden = !message;
    elements.bulkPriceMessage.setAttribute("data-state", stateName || "info");
  }

  function setBulkPriceBusy(busy) {
    state.bulkPrice.busy = Boolean(busy);
    elements.bulkPriceForm.setAttribute("aria-busy", String(state.bulkPrice.busy));
    elements.previewBulkPrice.disabled = state.bulkPrice.busy;
    elements.resetBulkPrice.disabled = state.bulkPrice.busy;
    elements.closeBulkPrice.disabled = state.bulkPrice.busy;
    elements.executeBulkPrice.disabled = state.bulkPrice.busy || !state.bulkPrice.confirmationToken;
  }

  function invalidateBulkPricePreview(clearMessage) {
    state.bulkPrice.confirmationToken = "";
    state.bulkPrice.preview = null;
    elements.executeBulkPrice.disabled = true;
    elements.bulkPricePreview.hidden = true;
    if (clearMessage !== false) {
      setBulkPriceMessage("");
    }
  }

  function syncBulkPriceType() {
    var fixed = elements.bulkPriceType.value === "fixed";
    elements.bulkPriceAmountLabel.textContent = fixed ? "مبلغ ثابت افزایش (تومان)" : "درصد افزایش";
    elements.bulkPriceAmount.placeholder = fixed ? "مثلاً ۵۰۰۰۰" : "مثلاً ۱۰";
    invalidateBulkPricePreview();
  }

  function openBulkPricePanel() {
    closeProductFilterPanel();
    if (!state.authenticated) {
      showPairingMessage("برای افزایش گروهی قیمت ابتدا اتصال امن مدیر را برقرار کنید.");
      showView("pairing");
      return;
    }
    elements.bulkPricePanel.hidden = false;
    elements.openBulkPrice.setAttribute("aria-expanded", "true");
    renderCategoryOptions();
    elements.bulkPriceCategories.focus();
  }

  function closeBulkPricePanel() {
    if (state.bulkPrice.busy) {
      return;
    }
    elements.bulkPricePanel.hidden = true;
    elements.openBulkPrice.setAttribute("aria-expanded", "false");
    elements.openBulkPrice.focus();
  }

  function resetBulkPriceForm() {
    if (state.bulkPrice.busy) {
      return;
    }
    elements.bulkPriceForm.reset();
    elements.bulkPriceIncludeChildren.checked = true;
    elements.bulkPriceType.value = "percent";
    elements.bulkPriceAmountLabel.textContent = "درصد افزایش";
    elements.bulkPriceAmount.placeholder = "مثلاً ۱۰";
    invalidateBulkPricePreview();
    setBulkPriceMessage("فرم افزایش گروهی پاک شد.", "info");
  }

  function selectedBulkPriceCategories() {
    return Array.prototype.map.call(elements.bulkPriceCategories.selectedOptions || [], function (option) {
      return Number(option.value);
    }).filter(function (categoryId) {
      return isFinite(categoryId) && categoryId > 0;
    });
  }

  function bulkPriceRequestBody(execute) {
    var categories = selectedBulkPriceCategories();
    var amount = normalizeNumericInput(elements.bulkPriceAmount.value);
    if (!categories.length) {
      setBulkPriceMessage("حداقل یک دسته یا زیردسته را انتخاب کنید.", "error");
      elements.bulkPriceCategories.focus();
      return null;
    }
    if (!/^(?:0\.\d{1,4}|[1-9]\d{0,11}(?:\.\d{1,4})?)$/.test(amount)) {
      setBulkPriceMessage("مقدار افزایش باید عددی مثبت با حداکثر چهار رقم اعشار باشد.", "error");
      elements.bulkPriceAmount.focus();
      return null;
    }
    if (elements.bulkPriceType.value === "percent" && Number(amount) > 1000) {
      setBulkPriceMessage("درصد افزایش نمی‌تواند بیشتر از ۱۰۰۰ درصد باشد.", "error");
      elements.bulkPriceAmount.focus();
      return null;
    }
    return {
      category_ids: categories,
      include_children: elements.bulkPriceIncludeChildren.checked,
      adjustment_type: elements.bulkPriceType.value,
      amount: amount,
      execute: Boolean(execute),
      confirmation_token: execute ? state.bulkPrice.confirmationToken : ""
    };
  }

  function normalizeBulkPriceResponse(payload) {
    var source = payload && payload.data && typeof payload.data === "object" ? payload.data : (payload && typeof payload === "object" ? payload : {});
    return {
      mode: safeText(source.mode, "preview", 20),
      matchedProducts: Math.max(0, Number(source.matched_products) || 0),
      priceRecords: Math.max(0, Number(source.price_records) || 0),
      skipped: Math.max(0, Number(source.skipped) || 0),
      categories: Math.max(0, Number(source.categories) || 0),
      changed: Math.max(0, Number(source.changed) || 0),
      failed: Math.max(0, Number(source.failed) || 0),
      confirmationToken: safeText(source.confirmation_token, "", 100),
      sample: Array.isArray(source.sample) ? source.sample.slice(0, 8).map(function (item) {
        var row = item && typeof item === "object" ? item : {};
        return {
          id: safeText(String(row.id || ""), "", 40),
          name: safeText(row.name, "محصول بدون نام", 160),
          oldRegular: safeText(String(row.old_regular_price || ""), "", 40),
          newRegular: safeText(String(row.new_regular_price || ""), "", 40),
          oldSale: safeText(String(row.old_sale_price || ""), "", 40),
          newSale: safeText(String(row.new_sale_price || ""), "", 40)
        };
      }) : []
    };
  }

  function renderBulkPricePreview(data, executed) {
    while (elements.bulkPriceSummary.firstChild) {
      elements.bulkPriceSummary.removeChild(elements.bulkPriceSummary.firstChild);
    }
    while (elements.bulkPriceSamples.firstChild) {
      elements.bulkPriceSamples.removeChild(elements.bulkPriceSamples.firstChild);
    }

    var summaryItems = executed ? [
      ["قیمت تغییرکرده", data.changed],
      ["ناموفق", data.failed],
      ["بدون قیمت و ردشده", data.skipped]
    ] : [
      ["محصول منطبق", data.matchedProducts],
      ["قیمت قابل تغییر", data.priceRecords],
      ["بدون قیمت و ردشده", data.skipped]
    ];
    summaryItems.forEach(function (item) {
      var card = document.createElement("div");
      card.className = "bulk-price-summary-card";
      card.appendChild(createTextElement("strong", "", formatDisplayNumber(item[1])));
      card.appendChild(createTextElement("span", "", item[0]));
      elements.bulkPriceSummary.appendChild(card);
    });

    if (data.sample.length) {
      var table = document.createElement("div");
      table.className = "bulk-price-sample-table";
      data.sample.forEach(function (sample) {
        var row = document.createElement("article");
        row.className = "bulk-price-sample-row";
        var title = document.createElement("div");
        title.appendChild(createTextElement("strong", "", sample.name));
        title.appendChild(createTextElement("small", "", "شناسه " + formatDisplayId(sample.id)));
        row.appendChild(title);
        var regular = document.createElement("p");
        regular.appendChild(createTextElement("span", "", "قیمت عادی"));
        regular.appendChild(createTextElement("del", "", formatStoreAmount(sample.oldRegular)));
        regular.appendChild(createTextElement("b", "", formatStoreAmount(sample.newRegular)));
        row.appendChild(regular);
        if (sample.oldSale) {
          var sale = document.createElement("p");
          sale.appendChild(createTextElement("span", "", "فروش ویژه"));
          sale.appendChild(createTextElement("del", "", formatStoreAmount(sample.oldSale)));
          sale.appendChild(createTextElement("b", "", formatStoreAmount(sample.newSale)));
          row.appendChild(sale);
        }
        table.appendChild(row);
      });
      elements.bulkPriceSamples.appendChild(table);
    }

    elements.bulkPricePreviewBadge.textContent = executed ? "اجرا شد" : "آمادهٔ تأیید";
    elements.bulkPricePreviewBadge.setAttribute("data-state", executed ? (data.failed ? "error" : "ready") : "loading");
    elements.bulkPricePreview.hidden = false;
  }

  function bulkPriceErrorMessage(error) {
    if (error && error.payload && typeof error.payload.message === "string") {
      return safeText(error.payload.message, "افزایش گروهی قیمت انجام نشد.", 240);
    }
    return responseErrorMessage(error, "افزایش گروهی قیمت انجام نشد؛ تنظیمات را بررسی کنید.");
  }

  function submitBulkPrice(execute) {
    if (!state.authenticated || !state.csrfToken || state.bulkPrice.busy) {
      setBulkPriceMessage("نشست امن معتبر نیست؛ دوباره وارد شوید.", "error");
      return Promise.resolve();
    }
    var url = bulkPriceUrl();
    var body = bulkPriceRequestBody(execute);
    if (!url || !body) {
      if (!url) setBulkPriceMessage("نشانی API افزایش گروهی در پیکربندی سایت موجود نیست.", "error");
      return Promise.resolve();
    }
    if (execute) {
      var preview = state.bulkPrice.preview || {};
      var confirmation = "قیمت " + formatDisplayNumber(preview.priceRecords || 0) + " مورد تغییر می‌کند. این عملیات گروهی فوراً در ووکامرس ذخیره می‌شود. ادامه می‌دهید؟";
      if (!window.confirm(confirmation)) {
        return Promise.resolve();
      }
    }

    setBulkPriceBusy(true);
    setBulkPriceMessage(execute ? "در حال اعمال افزایش قیمت..." : "در حال محاسبهٔ پیش‌نمایش...", "loading");
    return fetchJsonWithTimeout(url, {
      method: "POST",
      timeoutMs: 120000,
      headers: { "Content-Type": "application/json", "X-Fandoogh-CSRF": state.csrfToken },
      body: JSON.stringify(body)
    }).then(function (payload) {
      var data = normalizeBulkPriceResponse(payload);
      if (execute) {
        state.bulkPrice.confirmationToken = "";
        state.bulkPrice.preview = null;
        renderBulkPricePreview(data, true);
        setBulkPriceMessage(data.failed ? "افزایش انجام شد، اما بعضی قیمت‌ها ذخیره نشدند؛ گزارش را بررسی کنید." : "افزایش گروهی قیمت با موفقیت ذخیره شد.", data.failed ? "warning" : "success");
        return Promise.all([loadProducts(), loadCategories()]);
      }
      state.bulkPrice.confirmationToken = data.confirmationToken;
      state.bulkPrice.preview = data;
      renderBulkPricePreview(data, false);
      setBulkPriceMessage(data.priceRecords ? "پیش‌نمایش آماده است؛ نمونه‌ها را بررسی و سپس اعمال را تأیید کنید." : "در دسته‌های انتخاب‌شده قیمت عادی قابل افزایش پیدا نشد.", data.priceRecords ? "success" : "warning");
    }).catch(function (error) {
      if (error && error.status === 401) {
        setAuthenticated(false, "");
        showView("pairing");
        showPairingMessage("نشست منقضی شده است؛ دوباره جفت‌سازی کنید.");
        return;
      }
      if (execute && error && error.status === 409) {
        invalidateBulkPricePreview(false);
      }
      setBulkPriceMessage(bulkPriceErrorMessage(error), "error");
    }).finally(function () {
      setBulkPriceBusy(false);
      elements.executeBulkPrice.disabled = !state.bulkPrice.confirmationToken || !(state.bulkPrice.preview && state.bulkPrice.preview.priceRecords > 0);
    });
  }

  var PRODUCT_EDITOR_STEP_DEFINITIONS = [
    {
      key: "basics",
      label: "اطلاعات اصلی",
      description: "نام، نامک، نوع، قیمت، موجودی، وضعیت و دسته‌بندی محصول را وارد کنید."
    },
    {
      key: "attributes",
      label: "ویژگی و تنوع",
      description: "ویژگی‌ها و variationهای محصول را در صورت نیاز مدیریت کنید."
    },
    {
      key: "content",
      label: "توضیحات",
      description: "توضیح کوتاه و توضیحات کامل محصول را وارد کنید."
    },
    {
      key: "media",
      label: "تصاویر",
      description: "تصویر شاخص و گالری محصول را با تبدیل WebP محلی مدیریت کنید."
    },
    {
      key: "shipping",
      label: "ارسال و مالیات",
      description: "کلاس ارسال، ابعاد، مالیات، دانلود و تنظیمات حمل محصول را مشخص کنید."
    },
    {
      key: "advanced",
      label: "تنظیمات تکمیلی",
      description: "نمایش در فروشگاه، دیدگاه‌ها، دانلود و ارتباط این محصول با محصولات دیگر را تنظیم کنید."
    }
  ];

  // [UI: WIZARDS & MODALS] مراحل و مودال‌های محصول، سفارش و ثبت سفارش دستی.
  // توجه: نمای سفارش از تب‌های orderViewTabs استفاده می‌کند؛ ویزارد مرحله‌ای سفارش
  // (orderDetailStepper) در HTML رابط وجود ندارد و حذف شده است.

  function findProductEditorLabel(fieldId) {
    var node = document.getElementById(fieldId);
    while (node && node !== elements.productEditor) {
      if (String(node.tagName || "").toLowerCase() === "label") {
        return node;
      }
      node = node.parentElement;
    }
    return null;
  }

  function createProductEditorStepPanel(definition, index) {
    var panel = document.createElement("section");
    panel.className = "product-editor-step";
    panel.dataset.productEditorStep = definition.key;
    panel.id = "productEditorStep-" + definition.key;
    panel.hidden = index !== 0;
    panel.setAttribute("aria-labelledby", panel.id + "Title");

    var heading = document.createElement("div");
    heading.className = "product-editor-step-heading";
    var title = document.createElement("h4");
    title.id = panel.id + "Title";
    title.textContent = definition.label;
    heading.appendChild(title);
    var description = document.createElement("p");
    description.className = "product-step-description";
    description.textContent = definition.description;
    heading.appendChild(description);
    panel.appendChild(heading);
    return panel;
  }

  function setupProductEditorWizard() {
    var form = elements.productEditor;
    var overlay = elements.productEditorOverlay;
    if (!form || !overlay || form.getAttribute("data-wizard-ready") === "true") {
      return;
    }

    var heading = form.querySelector(".product-editor-heading");
    var oldGrid = form.querySelector(".product-editor-grid");
    var shortDescriptionLabel = findProductEditorLabel("productShortDescription");
    var descriptionLabel = findProductEditorLabel("productDescription");
    var attributesManager = elements.productAttributesManager;
    var mediaManager = document.getElementById("productMediaManager");
    var advancedFields = elements.productAdvancedFields;
    var variationManager = elements.variationManager;
    var footer = null;
    var directChildren = Array.prototype.slice.call(form.children);
    var childIndex;

    for (childIndex = 0; childIndex < directChildren.length; childIndex += 1) {
      if (directChildren[childIndex].classList && directChildren[childIndex].classList.contains("product-editor-actions") && directChildren[childIndex].querySelector("#saveProductButton")) {
        footer = directChildren[childIndex];
        break;
      }
    }

    if (!heading || !oldGrid || !footer) {
      return;
    }

    var fieldGroups = {
      basics: ["productName", "productSlug", "productKind", "productType", "productCategory", "productSubcategory", "productCategories", "productSku", "productRegularPrice", "productSalePrice", "productStatus", "productManageStock", "productStockQuantity", "productBackorders"],
      shipping: ["productShippingClass", "productTaxClass", "productVirtual", "productDownloadable", "productWeight", "productLength", "productWidth", "productHeight", "productTaxStatus"],
      advanced: ["productDownloadLimit", "productDownloadExpiry", "productPurchaseNote", "productSoldIndividually", "productCatalogVisibility", "productReviewsAllowed", "productUpsells", "productCrossSells"]
    };
    var stepPanels = {};
    var stepper = document.createElement("nav");
    stepper.className = "product-editor-stepper";
    stepper.setAttribute("aria-label", "مراحل معرفی محصول");
    var stepList = document.createElement("ol");

    PRODUCT_EDITOR_STEP_DEFINITIONS.forEach(function (definition, index) {
      var item = document.createElement("li");
      var button = document.createElement("button");
      var label = document.createElement("span");

      button.type = "button";
      button.className = "product-editor-step-button";
      button.dataset.productEditorStepIndex = String(index);
      button.setAttribute("aria-controls", "productEditorStep-" + definition.key);
      label.className = "product-editor-step-label";
      label.textContent = definition.label;
      button.appendChild(label);
      button.addEventListener("click", function () {
        if (index <= state.products.editorStep) {
          setProductEditorStep(index, true);
        }
      });
      item.appendChild(button);
      stepList.appendChild(item);
    });

    stepper.appendChild(stepList);

    var stepContent = document.createElement("div");
    stepContent.className = "product-editor-step-content";

    PRODUCT_EDITOR_STEP_DEFINITIONS.forEach(function (definition, index) {
      var panel = createProductEditorStepPanel(definition, index);
      stepPanels[definition.key] = panel;

      if (fieldGroups[definition.key]) {
        var grid = document.createElement("div");
        grid.className = "product-editor-grid";
        fieldGroups[definition.key].forEach(function (fieldId) {
          var fieldLabel = findProductEditorLabel(fieldId);
          if (fieldLabel) {
            grid.appendChild(fieldLabel);
          }
        });
        panel.appendChild(grid);
      }

      if (definition.key === "content") {
        if (shortDescriptionLabel) panel.appendChild(shortDescriptionLabel);
        if (descriptionLabel) panel.appendChild(descriptionLabel);
      }

      if (definition.key === "media" && mediaManager) {
        panel.appendChild(mediaManager);
      }

      if (definition.key === "attributes") {
        if (attributesManager) panel.appendChild(attributesManager);
        if (variationManager) panel.appendChild(variationManager);
      }

      stepContent.appendChild(panel);
    });

    var knownChildren = [heading, oldGrid, shortDescriptionLabel, descriptionLabel, attributesManager, mediaManager, advancedFields, variationManager, footer];
    var orphanChildren = directChildren.filter(function (child) {
      return knownChildren.indexOf(child) === -1;
    });
    orphanChildren.forEach(function (child) {
      stepPanels.basics.appendChild(child);
    });

    if (oldGrid.parentNode) {
      oldGrid.parentNode.removeChild(oldGrid);
    }

    var previousButton = document.createElement("button");
    previousButton.type = "button";
    previousButton.className = "secondary-button";
    previousButton.id = "productEditorPrevious";
    previousButton.textContent = "مرحلهٔ قبل";
    previousButton.addEventListener("click", function () {
      if (state.products.editorStep > 0) {
        setProductEditorStep(state.products.editorStep - 1, true);
      }
    });

    var nextButton = document.createElement("button");
    nextButton.type = "button";
    nextButton.className = "primary-button";
    nextButton.id = "productEditorNext";
    nextButton.textContent = "مرحلهٔ بعد";
    nextButton.addEventListener("click", function () {
      if (validateProductEditorStep(state.products.editorStep)) {
        setProductEditorStep(state.products.editorStep + 1, true);
      }
    });

    footer.classList.add("product-editor-wizard-actions");
    footer.insertBefore(previousButton, footer.firstChild);
    footer.insertBefore(nextButton, elements.saveProductButton);

    while (form.firstChild) {
      form.removeChild(form.firstChild);
    }
    form.appendChild(heading);
    form.appendChild(stepper);
    form.appendChild(stepContent);
    form.appendChild(footer);
    form.setAttribute("data-wizard-ready", "true");

    elements.productEditorStepper = stepper;
    elements.productEditorStepPanels = stepPanels;
    elements.productEditorPrevious = previousButton;
    elements.productEditorNext = nextButton;
    setProductEditorStep(0, false);
  }

  function setProductEditorStep(stepIndex, shouldFocus) {
    if (!elements.productEditorStepPanels || !PRODUCT_EDITOR_STEP_DEFINITIONS.length) {
      return;
    }

    var lastIndex = PRODUCT_EDITOR_STEP_DEFINITIONS.length - 1;
    var index = Math.max(0, Math.min(lastIndex, Number(stepIndex) || 0));
    state.products.editorStep = index;

    PRODUCT_EDITOR_STEP_DEFINITIONS.forEach(function (definition, definitionIndex) {
      var panel = elements.productEditorStepPanels[definition.key];
      if (panel) {
        panel.hidden = definitionIndex !== index;
        panel.setAttribute("aria-hidden", definitionIndex === index ? "false" : "true");
      }
    });

    if (elements.productEditorStepper) {
      Array.prototype.forEach.call(elements.productEditorStepper.querySelectorAll(".product-editor-step-button"), function (button) {
        var buttonIndex = Number(button.dataset.productEditorStepIndex);
        button.classList.toggle("is-active", buttonIndex === index);
        button.classList.toggle("is-complete", buttonIndex < index);
        button.disabled = buttonIndex > index;
        if (buttonIndex === index) {
          button.setAttribute("aria-current", "step");
        } else {
          button.removeAttribute("aria-current");
        }
      });
    }

    if (elements.productEditorPrevious) {
      elements.productEditorPrevious.disabled = index === 0;
    }
    if (elements.productEditorNext) {
      elements.productEditorNext.hidden = index === lastIndex;
    }
    if (elements.saveProductButton) {
      elements.saveProductButton.hidden = index !== lastIndex;
    }

    if (shouldFocus) {
      var currentPanel = elements.productEditorStepPanels[PRODUCT_EDITOR_STEP_DEFINITIONS[index].key];
      var focusTarget = currentPanel && currentPanel.querySelector("input:not([type=hidden]), select, textarea, button");
      if (focusTarget) {
        focusTarget.focus();
      }
    }
  }

  function validateProductEditorStep(stepIndex) {
    if (stepIndex !== 0 || !elements.productName) {
      return true;
    }

    if (!elements.productName.value.trim()) {
      setProductEditorMessage("نام محصول الزامی است؛ آن را وارد کنید و سپس ادامه دهید.");
      elements.productName.setAttribute("aria-invalid", "true");
      elements.productName.focus();
      return false;
    }

    return true;
  }

  function openProductEditorShell() {
    if (elements.productEditorOverlay) {
      elements.productEditorOverlay.hidden = false;
    }
    if (elements.productEditor) {
      elements.productEditor.hidden = false;
    }
    document.body.classList.add("product-editor-open");
    setProductEditorStep(0, false);
  }

  function setProductEditorTrigger() {
    var activeElement = document.activeElement;
    state.products.editorTrigger = activeElement && activeElement !== document.body ? activeElement : null;
  }

  function setProductEditorMessage(message) {
    elements.productEditorMessage.textContent = formatDisplayText(message || "");
    elements.productEditorMessage.hidden = !message;
    elements.productEditorMessage.setAttribute("data-state", message ? "error" : "idle");
  }

  function markProductEditorDirty() {
    if (elements.productEditor && !elements.productEditor.hidden) {
      state.products.editorDirty = true;
    }
  }

  function setProductMediaMessage(message) {
    if (!elements.productMediaMessage) {
      return;
    }
    elements.productMediaMessage.textContent = formatDisplayText(message || "");
    elements.productMediaMessage.hidden = !message;
  }

  function normalizeEditorImage(value, index) {
    var source = value && typeof value === "object" ? value : {};
    return {
      id: safeText(String(source.id || ""), "", 40),
      src: safeAssetUrl(source.src || source.url),
      alt: safeText(source.alt, "تصویر محصول", 160),
      featured: index === 0
    };
  }

  function renderProductMedia() {
    if (!elements.productMediaGrid) {
      return;
    }

    while (elements.productMediaGrid.firstChild) {
      elements.productMediaGrid.removeChild(elements.productMediaGrid.firstChild);
    }

    if (!state.products.editorImages.length) {
      elements.productMediaGrid.appendChild(createTextElement("p", "media-empty", "هنوز تصویری برای این محصول انتخاب نشده است."));
      return;
    }

    state.products.editorImages.forEach(function (image, index) {
      var card = document.createElement("article");
      card.className = "product-media-card" + (index === 0 ? " is-featured" : "");

      var preview = document.createElement("img");
      preview.className = "product-media-preview";
      preview.src = image.src;
      preview.alt = image.alt;
      preview.loading = "lazy";
      preview.referrerPolicy = "no-referrer";
      card.appendChild(preview);

      var meta = document.createElement("div");
      meta.className = "product-media-meta";
      meta.appendChild(createTextElement("span", "product-media-index", index === 0 ? "تصویر شاخص" : "تصویر گالری"));
      meta.appendChild(createTextElement("span", "product-media-id", "رسانه #" + formatDisplayId(image.id)));
      card.appendChild(meta);

      var actions = document.createElement("div");
      actions.className = "product-media-actions";
      var featuredButton = document.createElement("button");
      featuredButton.type = "button";
      featuredButton.className = "secondary-button compact-button";
      featuredButton.textContent = index === 0 ? "تصویر شاخص" : "انتخاب به‌عنوان شاخص";
      featuredButton.disabled = index === 0;
      featuredButton.addEventListener("click", function () {
        state.products.editorImages = [image].concat(state.products.editorImages.filter(function (item) {
          return item.id !== image.id;
        }));
        renderProductMedia();
      });
      actions.appendChild(featuredButton);

      var removeButton = document.createElement("button");
      removeButton.type = "button";
      removeButton.className = "secondary-button compact-button danger-outline-button";
      removeButton.textContent = "حذف از محصول";
      removeButton.addEventListener("click", function () {
        state.products.editorImages = state.products.editorImages.filter(function (item) {
          return item.id !== image.id;
        });
        renderProductMedia();
      });
      actions.appendChild(removeButton);
      card.appendChild(actions);
      elements.productMediaGrid.appendChild(card);
    });
  }

  function uploadProductMedia() {
    if (!state.authenticated || !state.csrfToken) {
      setProductMediaMessage("برای آپلود تصویر ابتدا اتصال امن مدیر را برقرار کنید.");
      return Promise.resolve();
    }

    var mediaUrl = apiUrl("mediaUrl");
    var files = elements.productMediaInput && elements.productMediaInput.files ? Array.prototype.slice.call(elements.productMediaInput.files) : [];
    if (!mediaUrl) {
      setProductMediaMessage("نشانی API رسانه در پیکربندی سایت موجود نیست.");
      return Promise.resolve();
    }
    if (!files.length) {
      setProductMediaMessage("حداقل یک تصویر انتخاب کنید.");
      return Promise.resolve();
    }

    var allowedTypes = ["image/jpeg", "image/png", "image/webp", "image/gif"];
    var allowedExtensions = ["jpg", "jpeg", "jpe", "png", "webp", "gif"];
    var fileExtension = function (file) {
      var name = file && typeof file.name === "string" ? file.name.toLowerCase() : "";
      var parts = name.split(".");
      return parts.length > 1 ? parts.pop() : "";
    };
    var invalidFile = files.filter(function (file) {
      var typeIsKnown = file && typeof file.type === "string" && file.type;
      var extension = fileExtension(file);
      return !file || (typeIsKnown && allowedTypes.indexOf(file.type) === -1) || allowedExtensions.indexOf(extension) === -1 || file.size < 1 || file.size > 20 * 1024 * 1024;
    })[0];
    if (invalidFile) {
      setProductMediaMessage("فرمت تصویر باید JPG، PNG، WebP یا GIF و حجم آن حداکثر ۲۰ مگابایت باشد.");
      return Promise.resolve();
    }

    elements.uploadProductMedia.disabled = true;
    setProductMediaMessage("در حال تبدیل و افزودن تصویر...");

    function uploadAt(index) {
      if (index >= files.length) {
        elements.productMediaInput.value = "";
        setProductMediaMessage("تصاویر با موفقیت به گالری اضافه شدند؛ برای اتصال به محصول ذخیره را بزنید.");
        return Promise.resolve();
      }

      var formData = new FormData();
      formData.append("file", files[index], files[index].name);
      return fetchJsonWithTimeout(mediaUrl, {
        method: "POST",
        timeoutMs: MEDIA_UPLOAD_TIMEOUT_MS,
        headers: { "X-Fandoogh-CSRF": state.csrfToken },
        body: formData
      }).then(function (payload) {
        var data = payload && payload.data ? payload.data : payload;
        var image = normalizeEditorImage(data, state.products.editorImages.length);
        if (!image.id || !image.src) {
          throw new Error("invalid-media-response");
        }
        state.products.editorImages.push(image);
        renderProductMedia();
        return uploadAt(index + 1);
      });
    }

    return uploadAt(0).catch(function (error) {
      if (error && error.status === 401) {
        setAuthenticated(false, "");
        showView("pairing");
        showPairingMessage("نشست منقضی شده است؛ دوباره جفت‌سازی کنید.");
        return;
      }
      setProductMediaMessage(responseErrorMessage(error, "آپلود تصویر انجام نشد؛ تنظیمات WebP و حجم فایل را بررسی کنید.", { media: true }));
    }).finally(function () {
      elements.uploadProductMedia.disabled = false;
    });
  }

  function closeProductEditor(force) {
    if (!elements.productEditor) {
      return;
    }

    if (!force && state.products.editorDirty && !window.confirm("تغییرات ذخیره‌نشده از بین می‌روند. می‌خواهید خارج شوید؟")) {
      return;
    }

    var trigger = state.products.editorTrigger;
    state.products.editorId = null;
    state.products.editorImages = [];
    state.products.editorAttributes = [];
    state.products.editorCategoryIds = [];
    state.products.editorLoadRequestId += 1;
    state.products.editorStep = 0;
    state.products.editorTrigger = null;
    state.variations.parentId = null;
    state.variations.items = [];
    state.variations.editorId = null;
    elements.productEditor.hidden = true;
    if (elements.productEditorOverlay) {
      elements.productEditorOverlay.hidden = true;
    }
    document.body.classList.remove("product-editor-open");
    if (elements.variationManager) {
      elements.variationManager.hidden = true;
    }
    if (elements.productType) {
      elements.productType.disabled = false;
      elements.productType.value = "simple";
    }
    clearVariationEditor();
    renderProductAttributeRows([]);
    renderProductMedia();
    setProductEditorLoading(false);
    setProductEditorMessage("");
    setProductMediaMessage("");
    state.products.editorDirty = false;
    elements.productName.removeAttribute("aria-invalid");
    if (elements.productEditorStepPanels) {
      setProductEditorStep(0, false);
    }
    if (trigger && typeof trigger.focus === "function" && document.contains(trigger)) {
      trigger.focus();
    }
  }

  function setSelectedProductCategories(categoryIds) {
    var selected = Array.isArray(categoryIds) ? categoryIds.map(String).filter(Boolean) : [];
    state.products.editorCategoryIds = selected.filter(function (value, index, values) {
      return values.indexOf(value) === index;
    });
    if (elements.productCategories) {
      Array.prototype.forEach.call(elements.productCategories.options, function (option) {
        option.selected = state.products.editorCategoryIds.indexOf(option.value) !== -1;
      });
    }
    syncProductCategoryFields(state.products.editorCategoryIds);
  }

  function syncProductSubcategoryOptions(parentId, selectedId) {
    if (!elements.productSubcategory) {
      return;
    }

    var parent = String(parentId || "0");
    var selected = String(selectedId || "");
    while (elements.productSubcategory.firstChild) {
      elements.productSubcategory.removeChild(elements.productSubcategory.firstChild);
    }

    var placeholder = document.createElement("option");
    placeholder.value = "";
    placeholder.textContent = parent === "0" ? "بدون زیردسته" : "انتخاب زیردسته";
    elements.productSubcategory.appendChild(placeholder);

    state.categories.items.filter(function (category) {
      return String(category.parent || "0") === parent;
    }).sort(function (first, second) {
      return first.name.localeCompare(second.name, "fa");
    }).forEach(function (category) {
      var option = document.createElement("option");
      option.value = String(category.id);
      option.textContent = formatDisplayText(category.name);
      option.selected = String(category.id) === selected;
      elements.productSubcategory.appendChild(option);
    });
  }

  function syncProductCategoryFields(categoryIds) {
    if (!elements.productCategory) {
      return;
    }

    var selected = Array.isArray(categoryIds) ? categoryIds.map(String) : [];
    var selectedCategories = state.categories.items.filter(function (category) {
      return selected.indexOf(String(category.id)) !== -1;
    });
    var root = selectedCategories.filter(function (category) {
      return String(category.parent || "0") === "0";
    })[0] || null;
    var child = selectedCategories.filter(function (category) {
      return String(category.parent || "0") !== "0";
    })[0] || null;
    if (!root && child) {
      root = state.categories.items.filter(function (category) {
        return String(category.id) === String(child.parent);
      })[0] || null;
    }

    elements.productCategory.value = root ? String(root.id) : "";
    syncProductSubcategoryOptions(root ? root.id : "0", child ? child.id : "");
    if (elements.productSubcategory && child && Array.prototype.some.call(elements.productSubcategory.options, function (option) { return option.value === String(child.id); })) {
      elements.productSubcategory.value = String(child.id);
    }
    elements.productCategory.dataset.previousValue = elements.productCategory.value;
    if (elements.productSubcategory) {
      elements.productSubcategory.dataset.previousValue = elements.productSubcategory.value;
    }
  }

  function collectProductCategoryIds() {
    var ids = [];
    if (elements.productCategories) {
      ids = Array.prototype.map.call(elements.productCategories.selectedOptions || [], function (option) {
        return String(option.value || "");
      }).filter(Boolean);
    }
    [elements.productCategory, elements.productSubcategory].forEach(function (control) {
      if (control && control.value) {
        ids.push(String(control.value));
      }
    });
    return ids.filter(function (value, index, values) {
      return values.indexOf(value) === index;
    }).map(function (value) {
      return Number(value);
    }).filter(function (value) {
      return value > 0;
    });
  }

  function setProductAttributesMessage(message) {
    if (!elements.productAttributesMessage) {
      return;
    }

    elements.productAttributesMessage.textContent = formatDisplayText(message || "");
    elements.productAttributesMessage.hidden = !message;
  }

  function findAttributeCatalogItem(name) {
    var normalizedName = String(name || "");
    return state.attributeCatalog.items.filter(function (item) {
      return item.name === normalizedName;
    })[0] || null;
  }

  function normalizeAttributeCatalogItem(value) {
    var source = value && typeof value === "object" ? value : {};
    var terms = Array.isArray(source.terms) ? source.terms.map(function (term) {
      var item = term && typeof term === "object" ? term : {};
      return {
        id: String(item.id || ""),
        name: safeText(item.name || item.label, "مقدار ویژگی", 160),
        slug: safeText(item.slug, "", 160)
      };
    }).filter(function (term) {
      return term.id;
    }) : [];

    return {
      id: safeText(String(source.id || ""), "", 40),
      name: safeText(source.name, "", 100),
      label: safeText(source.label, safeText(source.name, "ویژگی", 100), 120),
      terms: terms
    };
  }

  function extractProductAttributes(payload) {
    var items = payload && Array.isArray(payload.data) ? payload.data : (Array.isArray(payload) ? payload : []);
    return items.map(normalizeAttributeCatalogItem).filter(function (item) {
      return item.name;
    });
  }

  function readProductAttributeRow(rowNode) {
    var nameSelect = rowNode.querySelector(".product-attribute-name");
    var customName = rowNode.querySelector(".product-attribute-custom-name");
    var termsSelect = rowNode.querySelector(".product-attribute-terms");
    var customOptions = rowNode.querySelector(".product-attribute-custom-options");
    var selectedName = nameSelect ? nameSelect.value : "";
    var catalogItem = selectedName && selectedName !== "__custom__" ? findAttributeCatalogItem(selectedName) : null;
    var options = [];

    if (termsSelect) {
      options = Array.prototype.map.call(termsSelect.selectedOptions || [], function (option) {
        return Number(option.value);
      }).filter(function (value) {
        return value > 0;
      });
    } else if (customOptions) {
      options = customOptions.value.split(/[،,]/).map(function (value) {
        return value.trim();
      }).filter(Boolean);
    }

    return {
      name: selectedName === "__custom__" ? (customName ? customName.value.trim() : "") : selectedName,
      label: selectedName === "__custom__" ? (customName ? customName.value.trim() : "") : (catalogItem ? catalogItem.label : selectedName),
      options: options,
      visible: Boolean(rowNode.querySelector(".product-attribute-visible") && rowNode.querySelector(".product-attribute-visible").checked),
      variation: Boolean(rowNode.querySelector(".product-attribute-variation") && rowNode.querySelector(".product-attribute-variation").checked)
    };
  }

  function collectProductAttributeRows() {
    if (!elements.productAttributesList) {
      return state.products.editorAttributes.slice();
    }

    return Array.prototype.map.call(elements.productAttributesList.querySelectorAll(".product-attribute-row"), readProductAttributeRow).filter(function (row) {
      return row.name || row.options.length;
    });
  }

  function createAttributeCheckbox(labelText, className, checked) {
    var label = document.createElement("label");
    label.className = "field-label checkbox-field product-attribute-checkbox";
    var input = document.createElement("input");
    input.type = "checkbox";
    input.className = className;
    input.checked = Boolean(checked);
    label.appendChild(input);
    label.appendChild(document.createTextNode(labelText));
    return label;
  }

  function createProductAttributeTermsPicker(termItems, selectedValues) {
    var selectedIds = (Array.isArray(selectedValues) ? selectedValues : []).map(function (value) {
      return String(value);
    });
    var picker = document.createElement("details");
    picker.className = "product-attribute-multiselect";

    var summary = document.createElement("summary");
    summary.className = "product-attribute-terms-toggle";
    var summaryLabel = createTextElement("span", "product-attribute-terms-label", "مقادیر ویژگی");
    var summaryValue = createTextElement("span", "product-attribute-terms-value", "انتخاب کنید");
    var chevron = document.createElementNS("http://www.w3.org/2000/svg", "svg");
    chevron.setAttribute("class", "product-attribute-terms-chevron");
    chevron.setAttribute("viewBox", "0 0 24 24");
    chevron.setAttribute("aria-hidden", "true");
    var chevronPath = document.createElementNS("http://www.w3.org/2000/svg", "path");
    chevronPath.setAttribute("d", "m7 9 5 5 5-5");
    chevron.appendChild(chevronPath);
    summary.appendChild(summaryLabel);
    summary.appendChild(summaryValue);
    summary.appendChild(chevron);

    var optionsSelect = document.createElement("select");
    optionsSelect.className = "product-attribute-terms";
    optionsSelect.multiple = true;
    optionsSelect.hidden = true;
    optionsSelect.tabIndex = -1;
    optionsSelect.setAttribute("aria-hidden", "true");

    var menu = document.createElement("div");
    menu.className = "product-attribute-terms-menu";
    menu.setAttribute("role", "listbox");
    menu.setAttribute("aria-multiselectable", "true");

    function syncSummary() {
      var selected = Array.prototype.map.call(optionsSelect.selectedOptions || [], function (option) {
        return option.textContent;
      }).filter(Boolean);
      if (!selected.length) {
        summaryValue.textContent = "انتخاب کنید";
      } else if (selected.length <= 2) {
        summaryValue.textContent = selected.join("، ");
      } else {
        summaryValue.textContent = formatDisplayNumber(selected.length) + " مقدار انتخاب شد";
      }
      picker.classList.toggle("has-selection", selected.length > 0);
    }

    var validTerms = (Array.isArray(termItems) ? termItems : []).filter(function (term) {
      return term && String(term.id || "");
    });
    if (!validTerms.length) {
      picker.classList.add("is-empty");
      menu.appendChild(createTextElement("p", "product-attribute-terms-empty", "برای این ویژگی مقداری ثبت نشده است."));
    }

    validTerms.forEach(function (term) {
      var termId = String(term.id);
      var selected = selectedIds.indexOf(termId) !== -1;
      var option = document.createElement("option");
      option.value = termId;
      option.textContent = term.name;
      option.selected = selected;
      optionsSelect.appendChild(option);

      var choice = document.createElement("label");
      choice.className = "product-attribute-term-option";
      choice.setAttribute("role", "option");
      choice.setAttribute("aria-selected", selected ? "true" : "false");
      var checkbox = document.createElement("input");
      checkbox.type = "checkbox";
      checkbox.checked = selected;
      checkbox.value = termId;
      checkbox.setAttribute("aria-label", term.name);
      choice.appendChild(checkbox);
      choice.appendChild(document.createTextNode(term.name));
      checkbox.addEventListener("change", function () {
        option.selected = checkbox.checked;
        choice.setAttribute("aria-selected", checkbox.checked ? "true" : "false");
        syncSummary();
      });
      menu.appendChild(choice);
    });

    picker.appendChild(optionsSelect);
    picker.appendChild(summary);
    picker.appendChild(menu);
    syncSummary();
    return picker;
  }

  function renderProductAttributeRows(rows) {
    if (!elements.productAttributesList) {
      return;
    }

    state.products.editorAttributes = (Array.isArray(rows) ? rows : []).map(normalizeProductAttribute);
    while (elements.productAttributesList.firstChild) {
      elements.productAttributesList.removeChild(elements.productAttributesList.firstChild);
    }

    if (!state.products.editorAttributes.length) {
      elements.productAttributesList.appendChild(createTextElement("p", "product-attribute-empty", "هنوز ویژگی‌ای اضافه نشده است. برای محصول متغیر حداقل یک ویژگی variation اضافه کنید."));
      return;
    }

    state.products.editorAttributes.forEach(function (attributeRow, index) {
      var row = document.createElement("article");
      row.className = "product-attribute-row";
      row.dataset.attributeIndex = String(index);

      var grid = document.createElement("div");
      grid.className = "product-attribute-row-grid";

      var nameLabel = document.createElement("label");
      nameLabel.className = "field-label";
      nameLabel.appendChild(document.createTextNode("ویژگی"));
      var nameSelect = document.createElement("select");
      nameSelect.className = "product-attribute-name";
      var customOption = document.createElement("option");
      customOption.value = "__custom__";
      customOption.textContent = "ویژگی سفارشی";
      nameSelect.appendChild(customOption);

      var catalogItem = findAttributeCatalogItem(attributeRow.name);
      state.attributeCatalog.items.forEach(function (item) {
        var option = document.createElement("option");
        option.value = item.name;
        option.textContent = item.label;
        option.selected = item.name === attributeRow.name;
        nameSelect.appendChild(option);
      });

      if (attributeRow.global && !catalogItem && attributeRow.name) {
        var unavailableOption = document.createElement("option");
        unavailableOption.value = attributeRow.name;
        unavailableOption.textContent = attributeRow.label || attributeRow.name;
        unavailableOption.selected = true;
        nameSelect.appendChild(unavailableOption);
      }

      if (!catalogItem && !(attributeRow.global && attributeRow.name)) {
        nameSelect.value = "__custom__";
      } else {
        nameSelect.value = attributeRow.name;
      }
      nameLabel.appendChild(nameSelect);
      grid.appendChild(nameLabel);

      var customNameLabel = document.createElement("label");
      customNameLabel.className = "field-label product-attribute-custom-name-field";
      customNameLabel.appendChild(document.createTextNode("نام ویژگی سفارشی"));
      var customNameInput = document.createElement("input");
      customNameInput.className = "product-attribute-custom-name";
      customNameInput.type = "text";
      customNameInput.maxLength = 100;
      customNameInput.placeholder = "مثلاً جنس";
      customNameInput.value = !catalogItem && !attributeRow.global ? (attributeRow.label || attributeRow.name) : "";
      customNameLabel.appendChild(customNameInput);
      customNameLabel.hidden = nameSelect.value !== "__custom__";
      grid.appendChild(customNameLabel);

      var optionsLabel = document.createElement("label");
      optionsLabel.className = "field-label product-attribute-options-field";
      var isGlobal = Boolean(catalogItem || attributeRow.global);
      if (isGlobal) {
        var termItems = catalogItem ? catalogItem.terms.slice() : [];
        attributeRow.options.forEach(function (optionValue) {
          var optionId = String(optionValue.id || optionValue.value || "");
          if (optionId && !termItems.some(function (term) { return term.id === optionId; })) {
            termItems.push({ id: optionId, name: optionValue.label || optionValue.value });
          }
        });
        optionsLabel.appendChild(createProductAttributeTermsPicker(termItems, attributeRow.options.map(function (optionValue) {
          return String(optionValue.id || optionValue.value || "");
        })));
      } else {
        optionsLabel.appendChild(document.createTextNode("مقادیر ویژگی سفارشی"));
        var optionsInput = document.createElement("input");
        optionsInput.className = "product-attribute-custom-options";
        optionsInput.type = "text";
        optionsInput.maxLength = 2000;
        optionsInput.placeholder = "مثلاً قرمز، آبی";
        optionsInput.value = attributeRow.options.map(function (optionValue) {
          return optionValue.label || optionValue.value;
        }).join("، ");
        optionsLabel.appendChild(optionsInput);
      }
      grid.appendChild(optionsLabel);
      row.appendChild(grid);

      var actions = document.createElement("div");
      actions.className = "product-attribute-row-actions";
      actions.appendChild(createAttributeCheckbox("نمایش در محصول", "product-attribute-visible", attributeRow.visible));
      actions.appendChild(createAttributeCheckbox("استفاده برای variation", "product-attribute-variation", attributeRow.variation));
      var removeButton = document.createElement("button");
      removeButton.type = "button";
      removeButton.className = "secondary-button compact-button danger-outline-button";
      removeButton.textContent = "حذف ویژگی";
      removeButton.addEventListener("click", function () {
        var nextRows = collectProductAttributeRows();
        nextRows.splice(index, 1);
        renderProductAttributeRows(nextRows);
      });
      actions.appendChild(removeButton);
      row.appendChild(actions);
      elements.productAttributesList.appendChild(row);

      nameSelect.addEventListener("change", function () {
        var nextRows = collectProductAttributeRows();
        nextRows[index] = {
          name: nameSelect.value === "__custom__" ? "" : nameSelect.value,
          label: "",
          options: [],
          visible: true,
          variation: nameSelect.value !== "__custom__",
          global: nameSelect.value !== "__custom__"
        };
        renderProductAttributeRows(nextRows);
      });
    });
  }

  function syncProductEditorType() {
    var isVariable = elements.productType && elements.productType.value === "variable";
    if (elements.variationManager) {
      elements.variationManager.hidden = !isVariable;
    }
    if (!isVariable) {
      state.variations.parentId = null;
      state.variations.items = [];
      clearVariationEditor();
      setProductAttributesMessage("ویژگی‌ها برای محصول ساده هم قابل ثبت‌اند؛ برای ساخت variation نوع محصول را متغیر انتخاب کنید.");
    } else {
      setProductAttributesMessage("ویژگی‌های سراسری از ووکامرس خوانده می‌شوند؛ برای variation حداقل یک ویژگی را فعال کنید.");
    }
  }

  function loadProductAttributes() {
    if (state.previewMode) {
      state.attributeCatalog.status = "ready";
      return Promise.resolve(true);
    }

    if (!state.authenticated) {
      state.attributeCatalog.items = [];
      state.attributeCatalog.status = "secure";
      return Promise.resolve();
    }

    var url = apiUrl("productAttributesUrl");
    if (!url) {
      state.attributeCatalog.status = "error";
      setProductAttributesMessage("نشانی API ویژگی‌های ووکامرس در پیکربندی سایت موجود نیست.");
      return Promise.resolve();
    }

    state.attributeCatalog.status = "loading";
    return fetchJsonWithTimeout(url).then(function (payload) {
      state.attributeCatalog.items = extractProductAttributes(payload);
      state.attributeCatalog.status = "ready";
      if (elements.productAttributesList && !elements.productEditor.hidden) {
        renderProductAttributeRows(state.products.editorAttributes);
      }
    }).catch(function (error) {
      state.attributeCatalog.status = "error";
      if (error && error.status === 401) {
        setAuthenticated(false, "");
        showView("pairing");
        showPairingMessage("نشست منقضی شده است؛ دوباره جفت‌سازی کنید.");
        return;
      }
      setProductAttributesMessage(responseErrorMessage(error, "خواندن ویژگی‌های ووکامرس انجام نشد؛ ویژگی سفارشی را بررسی کنید."));
    });
  }

  function extractProductShippingClasses(payload) {
    var items = payload && Array.isArray(payload.data) ? payload.data : (Array.isArray(payload) ? payload : []);
    return items.map(function (value) {
      var source = value && typeof value === "object" ? value : {};
      return {
        id: String(source.id || ""),
        name: safeText(source.name, "کلاس ارسال", 120),
        slug: safeText(source.slug, "", 120)
      };
    }).filter(function (item) {
      return item.id && item.id !== "0";
    });
  }

  function renderProductShippingClassOptions(selectedId) {
    if (!elements.productShippingClass) {
      return;
    }

    var selected = String(typeof selectedId === "undefined" ? elements.productShippingClass.value || "0" : selectedId);
    while (elements.productShippingClass.options.length > 1) {
      elements.productShippingClass.remove(1);
    }
    state.products.shippingClasses.forEach(function (item) {
      var option = document.createElement("option");
      option.value = item.id;
      option.textContent = item.name;
      elements.productShippingClass.appendChild(option);
    });
    elements.productShippingClass.value = state.products.shippingClasses.some(function (item) { return item.id === selected; }) ? selected : "0";
  }

  function loadProductShippingClasses() {
    if (state.previewMode) {
      state.products.shippingClasses = [];
      renderProductShippingClassOptions("0");
      return Promise.resolve(true);
    }

    if (!state.authenticated) {
      state.products.shippingClasses = [];
      renderProductShippingClassOptions("0");
      return Promise.resolve();
    }

    var url = apiUrl("shippingClassesUrl");
    if (!url) {
      state.products.shippingClasses = [];
      renderProductShippingClassOptions("0");
      return Promise.resolve();
    }

    return fetchJsonWithTimeout(url).then(function (payload) {
      state.products.shippingClasses = extractProductShippingClasses(payload);
      renderProductShippingClassOptions(elements.productShippingClass ? elements.productShippingClass.value : "0");
    }).catch(function (error) {
      if (error && error.status === 401) {
        setAuthenticated(false, "");
        showView("pairing");
        showPairingMessage("نشست منقضی شده است؛ دوباره جفت‌سازی کنید.");
        return;
      }
      state.products.shippingClasses = [];
      renderProductShippingClassOptions("0");
    });
  }

  function selectedProductRelationshipIds(control) {
    if (!control) {
      return [];
    }

    return Array.prototype.map.call(control.selectedOptions || [], function (option) {
      return String(option.value || "");
    }).filter(function (value, index, values) {
      return value && values.indexOf(value) === index;
    });
  }

  function renderProductRelationshipOptions(upsellIds, crossSellIds) {
    var products = state.products.items.filter(function (product) {
      return product.id && product.id !== String(state.products.editorId || "");
    });

    function fill(control, selectedIds, fallbackLabel) {
      if (!control) {
        return;
      }

      var selected = (Array.isArray(selectedIds) ? selectedIds : []).map(String).filter(function (value, index, values) {
        return /^\d+$/.test(value) && value !== "0" && values.indexOf(value) === index;
      });
      while (control.firstChild) {
        control.removeChild(control.firstChild);
      }

      var known = {};
      products.forEach(function (product) {
        known[product.id] = true;
        var option = document.createElement("option");
        option.value = product.id;
        option.textContent = formatDisplayText(product.name) + " — #" + formatDisplayId(product.id);
        option.selected = selected.indexOf(product.id) !== -1;
        control.appendChild(option);
      });
      selected.forEach(function (id) {
        if (known[id]) {
          return;
        }
        var option = document.createElement("option");
        option.value = id;
        option.textContent = fallbackLabel + " #" + formatDisplayId(id);
        option.selected = true;
        control.appendChild(option);
      });

      if (!control.options.length) {
        var empty = document.createElement("option");
        empty.value = "";
        empty.textContent = "محصول دیگری برای انتخاب پیدا نشد";
        empty.disabled = true;
        control.appendChild(empty);
      }
    }

    fill(elements.productUpsells, upsellIds, "محصول");
    fill(elements.productCrossSells, crossSellIds, "محصول");
  }

  function makeProductSlug(name) {
    return String(name || "")
      .trim()
      .toLocaleLowerCase()
      .replace(/\s+/g, "-")
      .replace(/[^\w\u0600-\u06ff-]/g, "")
      .replace(/-+/g, "-")
      .replace(/^-|-$/g, "")
      .slice(0, 180);
  }

  function syncProductSlug() {
    if (elements.productSlug && elements.productName) {
      elements.productSlug.value = makeProductSlug(elements.productName.value);
    }
  }

  function syncProductKind() {
    if (!elements.productKind) {
      return;
    }

    var kind = elements.productKind.value || "physical";
    if (kind === "physical") {
      elements.productVirtual.checked = false;
      elements.productDownloadable.checked = false;
    } else if (kind === "downloadable") {
      elements.productVirtual.checked = true;
      elements.productDownloadable.checked = true;
    }

    if (elements.productShippingClass) {
      elements.productShippingClass.disabled = kind !== "physical";
    }
    [elements.productWeight, elements.productLength, elements.productWidth, elements.productHeight].forEach(function (control) {
      if (control) {
        control.disabled = kind !== "physical";
      }
    });
  }

  function openNewProductEditor() {
    closeProductFilterPanel();
    if (!state.authenticated) {
      showPairingMessage("برای ساخت محصول ابتدا اتصال امن مدیر را برقرار کنید.");
      showView("pairing");
      return;
    }

    setProductEditorTrigger();
    state.products.editorId = null;
    elements.productEditorTitle.textContent = "ساخت محصول";
    elements.productType.value = "simple";
    elements.productType.disabled = false;
    elements.productName.value = "";
    elements.productSlug.value = "";
    elements.productKind.value = "physical";
    elements.productCategory.value = "";
    syncProductSubcategoryOptions("0", "");
    elements.productSku.value = "";
    elements.productRegularPrice.value = "";
    elements.productSalePrice.value = "";
    elements.productStatus.value = "draft";
    elements.productManageStock.checked = false;
    elements.productStockQuantity.value = "";
    elements.productBackorders.value = "no";
    elements.productWeight.value = "";
    elements.productLength.value = "";
    elements.productWidth.value = "";
    elements.productHeight.value = "";
    elements.productTaxStatus.value = "taxable";
    elements.productSoldIndividually.checked = false;
    elements.productVirtual.checked = false;
    elements.productDownloadable.checked = false;
    elements.productCatalogVisibility.value = "visible";
    elements.productReviewsAllowed.checked = true;
    elements.productShippingClass.value = "0";
    elements.productTaxClass.value = "";
    elements.productDownloadLimit.value = "";
    elements.productDownloadExpiry.value = "";
    elements.productPurchaseNote.value = "";
    renderProductRelationshipOptions([], []);
    elements.productAdvancedFields.hidden = false;
    elements.productShortDescription.value = "";
    elements.productDescription.value = "";
    state.products.editorImages = [];
    state.products.editorAttributes = [];
    renderProductMedia();
    renderProductAttributeRows([]);
    setSelectedProductCategories([]);
    state.variations.parentId = null;
    syncProductEditorType();
    syncProductKind();
    clearVariationEditor();
    setProductEditorMessage("");
    state.products.editorDirty = false;
    state.products.editorLoadRequestId += 1;
    openProductEditorShell();
    setProductEditorLoading(false);
    elements.productName.focus();
  }

  function setProductEditorLoading(isLoading) {
    if (!elements.productEditor) {
      return;
    }

    elements.productEditor.setAttribute("aria-busy", isLoading ? "true" : "false");
    Array.prototype.forEach.call(elements.productEditor.querySelectorAll("input, select, textarea, button"), function (control) {
      if (control === elements.cancelProductEdit) {
        control.disabled = false;
        return;
      }
      control.disabled = Boolean(isLoading);
    });
  }

  function populateProductEditor(product) {
    elements.productEditorTitle.textContent = formatDisplayText("ویرایش محصول #" + formatDisplayId(product.id));
    elements.productType.value = product.type === "variable" || product.type === "variable-subscription" ? "variable" : "simple";
    elements.productType.disabled = true;
    elements.productName.value = product.name === "محصول بدون نام" ? "" : product.name;
    elements.productSlug.value = product.slug || makeProductSlug(product.name);
    elements.productKind.value = ["physical", "downloadable", "subscription"].indexOf(product.productKind) !== -1 ? product.productKind : "physical";
    elements.productSku.value = product.sku;
    elements.productRegularPrice.value = formatNumericInput(product.regularPrice);
    elements.productSalePrice.value = formatNumericInput(product.salePrice);
    elements.productStatus.value = ["draft", "pending", "publish", "private"].indexOf(product.status) !== -1 ? product.status : "draft";
    elements.productManageStock.checked = product.manageStock;
    elements.productStockQuantity.value = formatNumericInput(product.stockQuantity);
    elements.productBackorders.value = ["no", "notify", "yes"].indexOf(product.backorders) !== -1 ? product.backorders : "no";
    elements.productWeight.value = formatNumericInput(product.weight);
    elements.productLength.value = formatNumericInput(product.length);
    elements.productWidth.value = formatNumericInput(product.width);
    elements.productHeight.value = formatNumericInput(product.height);
    elements.productTaxStatus.value = ["taxable", "shipping", "none"].indexOf(product.taxStatus) !== -1 ? product.taxStatus : "taxable";
    elements.productSoldIndividually.checked = product.soldIndividually;
    elements.productVirtual.checked = product.virtual;
    elements.productDownloadable.checked = product.downloadable;
    elements.productCatalogVisibility.value = ["visible", "catalog", "search", "hidden"].indexOf(product.catalogVisibility) !== -1 ? product.catalogVisibility : "visible";
    elements.productReviewsAllowed.checked = product.reviewsAllowed;
    renderProductShippingClassOptions(product.shippingClassId);
    elements.productTaxClass.value = product.taxClass;
    elements.productDownloadLimit.value = formatNumericInput(product.downloadLimit);
    elements.productDownloadExpiry.value = formatNumericInput(product.downloadExpiry);
    elements.productPurchaseNote.value = product.purchaseNote;
    renderProductRelationshipOptions(product.upsellIds, product.crossSellIds);
    elements.productShortDescription.value = product.shortDescription;
    elements.productDescription.value = product.description;
    state.products.editorImages = product.images.map(normalizeEditorImage);
    state.products.editorAttributes = product.attributes.slice();
    renderProductMedia();
    renderProductAttributeRows(product.attributes);
    setSelectedProductCategories(product.categoryIds);
    state.variations.parentId = product.type === "variable" ? product.id : null;
    syncProductEditorType();
    clearVariationEditor();
    if (product.type === "variable") {
      loadVariations(product.id);
    }
    if (product.type === "variable-subscription") {
      loadVariations(product.id);
    }
    syncProductKind();
    state.products.editorDirty = false;
    setProductEditorLoading(false);
    setProductEditorMessage("");
  }

  function openProductEditor(productId) {
    var product = state.products.items.filter(function (item) {
      return item.id === String(productId);
    })[0];

    if (!product) {
      setProductsState("error", "اطلاعات محصول برای ویرایش در دسترس نیست.");
      return Promise.resolve(false);
    }

    var detailUrl = productResourceUrl(product.id);
    if (!detailUrl) {
      setProductsState("error", "نشانی API جزئیات محصول در پیکربندی سایت موجود نیست.");
      return Promise.resolve(false);
    }

    setProductEditorTrigger();
    state.products.editorId = product.id;
    elements.productEditorTitle.textContent = formatDisplayText("ویرایش محصول #" + formatDisplayId(product.id));
    openProductEditorShell();
    setProductEditorLoading(true);
    setProductEditorMessage("در حال دریافت اطلاعات کامل محصول...");
    var requestId = state.products.editorLoadRequestId + 1;
    state.products.editorLoadRequestId = requestId;

    return fetchJsonWithTimeout(detailUrl).then(function (payload) {
      if (requestId !== state.products.editorLoadRequestId || state.products.editorId !== product.id) {
        return false;
      }

      var data = payload && payload.data ? payload.data : payload;
      var detail = normalizeProduct(data);
      if (!detail.id || detail.id !== product.id) {
        throw new Error("invalid-product-detail");
      }

      populateProductEditor(detail);
      elements.productName.focus();
      return true;
    }).catch(function (error) {
      if (requestId !== state.products.editorLoadRequestId) {
        return false;
      }
      setProductEditorLoading(false);
      if (error && (error.status === 401 || error.status === 403)) {
        closeProductEditor(true);
        setAuthenticated(false, "");
        showView("pairing");
        showPairingMessage("نشست یا مجوز دریافت جزئیات محصول معتبر نیست.");
        return false;
      }
      Array.prototype.forEach.call(elements.productEditor.querySelectorAll("input, select, textarea, button"), function (control) {
        if (control !== elements.cancelProductEdit) {
          control.disabled = true;
        }
      });
      if (elements.saveProductButton) {
        elements.saveProductButton.disabled = true;
      }
      setProductEditorMessage(responseErrorMessage(error, "دریافت اطلاعات کامل محصول انجام نشد؛ برای جلوگیری از حذف توضیحات، ذخیره غیرفعال شد."));
      return false;
    });
  }

  function productResourceUrl(productId) {
    var base = apiUrl("productsUrl");
    productId = String(productId || "");
    if (!base || !/^\d+$/.test(productId) || productId === "0") {
      return "";
    }

    try {
      return new URL(productId + "/", base.replace(/\/?$/, "/")).toString();
    } catch (error) {
      return "";
    }
  }

  function saveProduct(event) {
    event.preventDefault();
    if (!state.authenticated || !state.csrfToken) {
      setProductEditorMessage("نشست امن معتبر نیست؛ دوباره وارد شوید.");
      return;
    }

    var name = elements.productName.value.trim();
    if (!name) {
      setProductEditorMessage("نام محصول الزامی است.");
      setProductEditorStep(0, false);
      elements.productName.setAttribute("aria-invalid", "true");
      elements.productName.focus();
      return;
    }
    elements.productName.removeAttribute("aria-invalid");

    var productKind = elements.productKind ? elements.productKind.value || "physical" : "physical";
    var productType = elements.productType && elements.productType.value === "variable" ? "variable" : "simple";
    if (productKind === "subscription") {
      productType = productType === "variable" ? "variable-subscription" : "subscription";
    }
    var productAttributes = collectProductAttributeRows();
    if (productType === "variable") {
      var attributesStepIndex = PRODUCT_EDITOR_STEP_DEFINITIONS.map(function (definition) {
        return definition.key;
      }).indexOf("attributes");
      attributesStepIndex = attributesStepIndex >= 0 ? attributesStepIndex : 0;
      if (!productAttributes.length) {
        setProductEditorMessage("برای ساخت محصول متغیر حداقل یک ویژگی اضافه کنید.");
        setProductEditorStep(attributesStepIndex, true);
        return;
      }
      if (!productAttributes.some(function (attribute) { return attribute.variation; })) {
        setProductEditorMessage("حداقل یک ویژگی را برای استفاده در variation فعال کنید.");
        setProductEditorStep(attributesStepIndex, true);
        return;
      }
    }

    var body = {
      name: name,
      slug: elements.productSlug ? elements.productSlug.value.trim() : makeProductSlug(name),
      product_kind: productKind,
      type: productType,
      attributes: productAttributes,
      sku: elements.productSku.value.trim(),
      regular_price: normalizeNumericInput(elements.productRegularPrice.value.trim()),
      sale_price: normalizeNumericInput(elements.productSalePrice.value.trim()),
      status: elements.productStatus.value,
      manage_stock: elements.productManageStock.checked,
      backorders: elements.productBackorders.value,
      weight: normalizeNumericInput(elements.productWeight.value.trim()),
      length: normalizeNumericInput(elements.productLength.value.trim()),
      width: normalizeNumericInput(elements.productWidth.value.trim()),
      height: normalizeNumericInput(elements.productHeight.value.trim()),
      tax_status: elements.productTaxStatus.value,
      tax_class: elements.productTaxClass ? elements.productTaxClass.value.trim() : "",
      catalog_visibility: elements.productCatalogVisibility ? elements.productCatalogVisibility.value : "visible",
      shipping_class_id: elements.productShippingClass ? Number(elements.productShippingClass.value || 0) : 0,
      purchase_note: elements.productPurchaseNote ? elements.productPurchaseNote.value : "",
      reviews_allowed: elements.productReviewsAllowed ? elements.productReviewsAllowed.checked : true,
      upsell_ids: selectedProductRelationshipIds(elements.productUpsells).map(Number),
      cross_sell_ids: selectedProductRelationshipIds(elements.productCrossSells).map(Number),
      sold_individually: elements.productSoldIndividually.checked,
      virtual: elements.productVirtual.checked,
      downloadable: elements.productDownloadable.checked,
      short_description: elements.productShortDescription.value,
      description: elements.productDescription.value,
      category_ids: collectProductCategoryIds(),
      image_id: state.products.editorImages.length ? Number(state.products.editorImages[0].id) : 0,
      gallery_ids: state.products.editorImages.slice(1).map(function (image) {
        return Number(image.id);
      })
    };
    var quantity = normalizeNumericInput(elements.productStockQuantity.value.trim());
    if (elements.productManageStock.checked && quantity) {
      body.stock_quantity = quantity;
    }
    body.download_limit = elements.productDownloadLimit && elements.productDownloadLimit.value.trim() ? normalizeNumericInput(elements.productDownloadLimit.value.trim()) : null;
    body.download_expiry = elements.productDownloadExpiry && elements.productDownloadExpiry.value.trim() ? normalizeNumericInput(elements.productDownloadExpiry.value.trim()) : null;

    var editorId = state.products.editorId;
    var url = editorId ? productResourceUrl(editorId) : apiUrl("productsUrl");
    var method = editorId ? "PATCH" : "POST";
    if (!url) {
      setProductEditorMessage("نشانی API محصولات در پیکربندی سایت موجود نیست.");
      return;
    }

    elements.saveProductButton.disabled = true;
    setProductEditorMessage("در حال ذخیرهٔ محصول...");

    fetchJsonWithTimeout(url, {
      method: method,
      headers: {
        "Content-Type": "application/json",
        "X-Fandoogh-CSRF": state.csrfToken
      },
      body: JSON.stringify(body)
    }).then(function () {
      closeProductEditor(true);
      return loadProducts();
    }).catch(function (error) {
      if (error && error.status === 401) {
        setAuthenticated(false, "");
        showView("pairing");
        showPairingMessage("نشست منقضی شده است؛ دوباره جفت‌سازی کنید.");
        return;
      }
      setProductEditorMessage(responseErrorMessage(error, "ذخیرهٔ محصول انجام نشد؛ اطلاعات ورودی را بررسی کنید."));
    }).finally(function () {
      elements.saveProductButton.disabled = false;
    });
  }

  function loadProducts(searchTerm) {
    if (state.previewMode) {
      renderProductResults();
      return Promise.resolve(true);
    }

    if (!state.authenticated) {
      setProductsState("secure", "برای دریافت محصولات، ابتدا اتصال امن مدیر باید برقرار شود.");
      return Promise.resolve();
    }

    var productsUrl = state.config && state.config.api ? state.config.api.productsUrl : "";
    if (!productsUrl) {
      setProductsState("error", "نشانی API محصولات در پیکربندی سایت موجود نیست.");
      return Promise.resolve();
    }

    var search = typeof searchTerm === "string" ? searchTerm.trim() : state.products.filters.query.trim();
    var requestId = state.products.loadRequestId + 1;
    state.products.loadRequestId = requestId;
    var requestUrl;
    try {
      requestUrl = new URL(productsUrl, window.location.href);
      requestUrl.searchParams.set("page", "1");
      requestUrl.searchParams.set("per_page", "50");
      if (search) {
        requestUrl.searchParams.set("search", normalizeSearchText(search));
      }
    } catch (error) {
      setProductsState("error", "نشانی API محصولات در پیکربندی سایت معتبر نیست.");
      return Promise.resolve();
    }

    setProductsState("loading");

    return fetchJsonWithTimeout(requestUrl.toString()).then(function (payload) {
      if (requestId !== state.products.loadRequestId) {
        return false;
      }
      state.products.items = extractProducts(payload);
      state.products.status = "ready";
      renderProductResults();
      if (elements.productEditor && !elements.productEditor.hidden) {
        renderProductRelationshipOptions(
          selectedProductRelationshipIds(elements.productUpsells),
          selectedProductRelationshipIds(elements.productCrossSells)
        );
      }
      renderDashboardSummary();
      return true;
    }).catch(function (error) {
      if (requestId !== state.products.loadRequestId) {
        return false;
      }
      if (error && error.status === 401) {
        setAuthenticated(false, "");
        showView("pairing");
        showPairingMessage("نشست منقضی شده است؛ دوباره جفت‌سازی کنید.");
        return;
      }
      setProductsState("error", "اتصال به API محصولات برقرار نشد. دوباره تلاش کنید.");
    });
  }

  function setVariationEditorMessage(message) {
    elements.variationEditorMessage.textContent = formatDisplayText(message || "");
    elements.variationEditorMessage.hidden = !message;
  }

  function clearVariationEditor() {
    state.variations.editorId = null;
    if (!elements.variationSku) {
      return;
    }
    elements.variationSku.value = "";
    elements.variationRegularPrice.value = "";
    elements.variationSalePrice.value = "";
    elements.variationStatus.value = "draft";
    elements.variationManageStock.checked = false;
    elements.variationStockQuantity.value = "";
    elements.variationAttributes.value = "";
    elements.saveVariationButton.textContent = "ساخت variation";
    elements.cancelVariationEdit.hidden = true;
    setVariationEditorMessage("");
  }

  function normalizeVariation(value) {
    var source = value && typeof value === "object" ? value : {};
    return {
      id: safeText(String(source.id || ""), "", 40),
      sku: safeText(source.sku, "", 100),
      status: safeText(source.status, "draft", 30),
      price: safeText(source.price, "", 40),
      regularPrice: safeText(source.regular_price, "", 40),
      salePrice: safeText(source.sale_price, "", 40),
      stockStatus: safeText(source.stock_status, "", 30),
      manageStock: Boolean(source.manage_stock),
      stockQuantity: source.stock_quantity === null || typeof source.stock_quantity === "undefined" ? "" : String(source.stock_quantity),
      attributes: source.attributes && typeof source.attributes === "object" ? source.attributes : {}
    };
  }

  function extractVariations(payload) {
    if (Array.isArray(payload)) {
      return payload.map(normalizeVariation);
    }
    if (payload && Array.isArray(payload.data)) {
      return payload.data.map(normalizeVariation);
    }
    if (payload && Array.isArray(payload.variations)) {
      return payload.variations.map(normalizeVariation);
    }
    return [];
  }

  function variationCollectionUrl(parentId) {
    var base = productResourceUrl(parentId);
    return base ? base.replace(/\/?$/, "/") + "variations/" : "";
  }

  function variationResourceUrl(parentId, variationId) {
    var base = variationCollectionUrl(parentId);
    variationId = String(variationId || "");
    if (!base || !/^\d+$/.test(variationId) || variationId === "0") {
      return "";
    }
    return base + variationId + "/";
  }

  function renderVariationCards(items) {
    while (elements.variationsGrid.firstChild) {
      elements.variationsGrid.removeChild(elements.variationsGrid.firstChild);
    }

    items.forEach(function (variation) {
      var card = document.createElement("article");
      card.className = "variation-card";
      card.appendChild(createTextElement("h4", "category-card-name", "variation #" + formatDisplayId(variation.id)));
      card.appendChild(createTextElement("p", "category-card-slug", variation.sku ? "SKU: " + variation.sku : "بدون SKU"));
      card.appendChild(createTextElement("p", "category-card-count", variation.price ? formatStoreAmount(variation.price) : "قیمت ثبت نشده"));
      card.appendChild(createTextElement("p", "category-card-count", Object.keys(variation.attributes).length ? JSON.stringify(variation.attributes) : "بدون ویژگی"));

      var editButton = document.createElement("button");
      editButton.type = "button";
      editButton.className = "secondary-button compact-button";
      editButton.textContent = "ویرایش";
      editButton.addEventListener("click", function () {
        openVariationEditor(variation);
      });
      card.appendChild(editButton);
      elements.variationsGrid.appendChild(card);
    });
  }

  function openVariationEditor(variation) {
    state.variations.editorId = variation.id;
    elements.variationSku.value = variation.sku;
    elements.variationRegularPrice.value = formatNumericInput(variation.regularPrice);
    elements.variationSalePrice.value = formatNumericInput(variation.salePrice);
    elements.variationStatus.value = ["draft", "publish", "private"].indexOf(variation.status) !== -1 ? variation.status : "draft";
    elements.variationManageStock.checked = variation.manageStock;
    elements.variationStockQuantity.value = formatNumericInput(variation.stockQuantity);
    elements.variationAttributes.value = Object.keys(variation.attributes).length ? JSON.stringify(variation.attributes) : "";
    elements.saveVariationButton.textContent = "ذخیرهٔ variation";
    elements.cancelVariationEdit.hidden = false;
    setVariationEditorMessage("");
    elements.variationSku.focus();
  }

  function loadVariations(parentId) {
    var url = variationCollectionUrl(parentId);
    if (!url || !state.authenticated) {
      return Promise.resolve();
    }

    state.variations.parentId = String(parentId);
    elements.variationsLoadingState.hidden = false;
    elements.variationsErrorState.hidden = true;
    return fetchJsonWithTimeout(url).then(function (payload) {
      state.variations.items = extractVariations(payload);
      elements.variationsLoadingState.hidden = true;
      renderVariationCards(state.variations.items);
    }).catch(function (error) {
      elements.variationsLoadingState.hidden = true;
      if (error && error.status === 401) {
        setAuthenticated(false, "");
        showView("pairing");
        showPairingMessage("نشست منقضی شده است؛ دوباره جفت‌سازی کنید.");
        return;
      }
      elements.variationsErrorText.textContent = formatDisplayText(responseErrorMessage(error, "اتصال به API variationها برقرار نشد."));
      elements.variationsErrorState.hidden = false;
    });
  }

  function saveVariation() {
    if (!state.authenticated || !state.csrfToken || !state.variations.parentId) {
      setVariationEditorMessage("برای مدیریت variation ابتدا اتصال امن و یک محصول variable را انتخاب کنید.");
      return;
    }

    var attributes = {};
    if (elements.variationAttributes.value.trim()) {
      try {
        attributes = JSON.parse(elements.variationAttributes.value);
      } catch (error) {
        setVariationEditorMessage("ساختار ویژگی‌ها باید JSON معتبر باشد.");
        return;
      }
      if (!attributes || typeof attributes !== "object" || Array.isArray(attributes)) {
        setVariationEditorMessage("ویژگی‌ها باید یک شیء JSON باشند.");
        return;
      }
    }

    var body = {
      sku: elements.variationSku.value.trim(),
      regular_price: normalizeNumericInput(elements.variationRegularPrice.value.trim()),
      sale_price: normalizeNumericInput(elements.variationSalePrice.value.trim()),
      status: elements.variationStatus.value,
      manage_stock: elements.variationManageStock.checked,
      attributes: attributes
    };
    var quantity = normalizeNumericInput(elements.variationStockQuantity.value.trim());
    if (elements.variationManageStock.checked && quantity) {
      body.stock_quantity = quantity;
    }

    var url = state.variations.editorId ? variationResourceUrl(state.variations.parentId, state.variations.editorId) : variationCollectionUrl(state.variations.parentId);
    if (!url) {
      setVariationEditorMessage("نشانی API variationها در دسترس نیست.");
      return;
    }

    elements.saveVariationButton.disabled = true;
    setVariationEditorMessage("در حال ذخیرهٔ variation...");
    fetchJsonWithTimeout(url, {
      method: state.variations.editorId ? "PATCH" : "POST",
      headers: { "Content-Type": "application/json", "X-Fandoogh-CSRF": state.csrfToken },
      body: JSON.stringify(body)
    }).then(function () {
      clearVariationEditor();
      return loadVariations(state.variations.parentId);
    }).catch(function (error) {
      if (error && error.status === 401) {
        setAuthenticated(false, "");
        showView("pairing");
        showPairingMessage("نشست منقضی شده است؛ دوباره جفت‌سازی کنید.");
        return;
      }
      setVariationEditorMessage(responseErrorMessage(error, "ذخیرهٔ variation انجام نشد؛ اطلاعات ورودی را بررسی کنید."));
    }).finally(function () {
      elements.saveVariationButton.disabled = false;
    });
  }

  // [UI: CATEGORIES] دسته‌بندی‌ها و فرم ساخت/ویرایش آن‌ها.
  function categoryStateLabel(stateName) {
    var labels = {
      secure: "نیازمند اتصال امن",
      loading: "در حال دریافت",
      empty: "بدون دسته‌بندی",
      error: "خطا در دریافت",
      ready: "آماده"
    };

    return labels[stateName] || labels.secure;
  }

  function setCategoriesState(stateName, detail) {
    if (!elements.categoriesStateBadge) {
      return;
    }

    state.categories.status = stateName;
    elements.categoriesStateBadge.textContent = categoryStateLabel(stateName);
    elements.categoriesStateBadge.setAttribute("data-state", stateName);
    elements.categoriesSecureState.hidden = stateName !== "secure";
    elements.categoriesLoadingState.hidden = stateName !== "loading";
    elements.categoriesEmptyState.hidden = stateName !== "empty";
    elements.categoriesErrorState.hidden = stateName !== "error";
    elements.categoriesGrid.hidden = stateName !== "ready";

    if (stateName === "empty" && detail) {
      elements.categoriesEmptyText.textContent = formatDisplayText(detail);
    }
    if (stateName === "error" && detail) {
      elements.categoriesErrorText.textContent = formatDisplayText(detail);
    }
  }

  function normalizeCategory(value) {
    var source = value && typeof value === "object" ? value : {};
    return {
      id: safeText(String(source.id || ""), "", 40),
      name: safeText(source.name, "دسته‌بندی بدون نام", 120),
      slug: safeText(source.slug, "", 200),
      parent: safeText(String(source.parent || "0"), "0", 40),
      description: safeText(source.description, "", 2000),
      count: safeText(String(source.count || "0"), "0", 20)
    };
  }

  function extractCategories(payload) {
    if (Array.isArray(payload)) {
      return payload.map(normalizeCategory);
    }
    if (payload && Array.isArray(payload.data)) {
      return payload.data.map(normalizeCategory);
    }
    if (payload && Array.isArray(payload.categories)) {
      return payload.categories.map(normalizeCategory);
    }
    return [];
  }

  function orderedCategories() {
    var byParent = {};
    var included = {};
    state.categories.items.forEach(function (category) {
      var parent = String(category.parent || "0");
      if (!byParent[parent]) byParent[parent] = [];
      byParent[parent].push(category);
    });
    Object.keys(byParent).forEach(function (parent) {
      byParent[parent].sort(function (first, second) {
        return first.name.localeCompare(second.name, "fa");
      });
    });
    var result = [];
    function append(parentId, depth, trail) {
      (byParent[String(parentId)] || []).forEach(function (category) {
        if (trail[category.id] || included[category.id]) return;
        var nextTrail = Object.assign({}, trail);
        nextTrail[category.id] = true;
        included[category.id] = true;
        result.push({ category: category, depth: depth });
        append(category.id, Math.min(depth + 1, 8), nextTrail);
      });
    }
    append("0", 0, {});
    state.categories.items.forEach(function (category) {
      if (!included[category.id]) {
        result.push({ category: category, depth: 0 });
      }
    });
    return result;
  }

  function renderCategoryOptions() {
    if (!elements.productCategories || !elements.categoryParent || !elements.bulkPriceCategories) {
      return;
    }

    var selected = Array.prototype.map.call(elements.productCategories.selectedOptions || [], function (option) {
      return option.value;
    });
    if (!selected.length && state.products.editorCategoryIds.length) {
      selected = state.products.editorCategoryIds.slice();
    }
    var primarySelected = elements.productCategory ? elements.productCategory.value : "";
    var subcategorySelected = elements.productSubcategory ? elements.productSubcategory.value : "";
    var bulkSelected = Array.prototype.map.call(elements.bulkPriceCategories.selectedOptions || [], function (option) {
      return option.value;
    });
    var editorId = state.categories.editorId ? String(state.categories.editorId) : "";
    while (elements.productCategories.firstChild) {
      elements.productCategories.removeChild(elements.productCategories.firstChild);
    }
    while (elements.bulkPriceCategories.firstChild) {
      elements.bulkPriceCategories.removeChild(elements.bulkPriceCategories.firstChild);
    }
    while (elements.categoryParent.options.length > 1) {
      elements.categoryParent.remove(1);
    }
    if (elements.productCategory) {
      while (elements.productCategory.options.length > 1) {
        elements.productCategory.remove(1);
      }
    }

    orderedCategories().forEach(function (entry) {
      var category = entry.category;
      var prefix = entry.depth ? new Array(entry.depth + 1).join("— ") : "";
      var option = document.createElement("option");
      option.value = category.id;
      option.textContent = formatDisplayText(prefix + category.name);
      option.selected = selected.indexOf(category.id) !== -1;
      elements.productCategories.appendChild(option);

      var bulkOption = option.cloneNode(true);
      bulkOption.selected = bulkSelected.indexOf(category.id) !== -1;
      elements.bulkPriceCategories.appendChild(bulkOption);

      if (elements.productCategory && String(category.parent || "0") === "0") {
        var primaryOption = document.createElement("option");
        primaryOption.value = category.id;
        primaryOption.textContent = formatDisplayText(category.name);
        primaryOption.selected = String(category.id) === primarySelected || selected.indexOf(category.id) !== -1 && !subcategorySelected;
        elements.productCategory.appendChild(primaryOption);
      }

      if (category.id === editorId) {
        return;
      }

      var parentOption = document.createElement("option");
      parentOption.value = category.id;
      parentOption.textContent = formatDisplayText(prefix + category.name);
      elements.categoryParent.appendChild(parentOption);
    });

    syncProductCategoryFields(selected.length ? selected : [primarySelected, subcategorySelected].filter(Boolean));
    if (elements.productSubcategory && subcategorySelected && Array.prototype.some.call(elements.productSubcategory.options, function (option) { return option.value === subcategorySelected; })) {
      elements.productSubcategory.value = subcategorySelected;
    }
  }

  function renderCategoryCards(items) {
    while (elements.categoriesGrid.firstChild) {
      elements.categoriesGrid.removeChild(elements.categoriesGrid.firstChild);
    }

    items.forEach(function (category) {
      var card = document.createElement("article");
      card.className = "category-card";
      card.appendChild(createTextElement("h3", "category-card-name", category.name));
      card.appendChild(createTextElement("p", "category-card-slug", category.slug ? "slug: " + category.slug : "بدون slug"));
      card.appendChild(createTextElement("p", "category-card-count", formatDisplayNumber(category.count) + " محصول"));

      var editButton = document.createElement("button");
      editButton.type = "button";
      editButton.className = "secondary-button compact-button";
      editButton.textContent = "ویرایش";
      editButton.addEventListener("click", function () {
        openCategoryEditor(category.id);
      });
      card.appendChild(editButton);
      elements.categoriesGrid.appendChild(card);
    });
  }

  function renderCategoryResults() {
    if (state.categories.status !== "ready" && state.categories.status !== "empty") {
      return;
    }

    var query = normalizeSearchText(elements.categorySearch.value);
    var filteredItems = state.categories.items.filter(function (category) {
      return searchTextIncludes([category.id, category.name, category.slug, category.description], query);
    });
    state.categories.filteredItems = filteredItems;

    if (!filteredItems.length) {
      setCategoriesState("empty", query ? "دسته‌بندی‌ای با این عبارت پیدا نشد." : "هنوز دسته‌بندی‌ای برای نمایش وجود ندارد.");
      return;
    }

    renderCategoryCards(filteredItems);
    setCategoriesState("ready");
  }

  function setCategoryEditorMessage(message) {
    elements.categoryEditorMessage.textContent = formatDisplayText(message || "");
    elements.categoryEditorMessage.hidden = !message;
  }

  function closeCategoryEditor() {
    if (!elements.categoryEditor) {
      return;
    }
    state.categories.editorId = null;
    elements.categoryEditor.hidden = true;
    setCategoryEditorMessage("");
    renderCategoryOptions();
  }

  function openNewCategoryEditor() {
    if (!state.authenticated) {
      showPairingMessage("برای ساخت دسته‌بندی ابتدا اتصال امن مدیر را برقرار کنید.");
      showView("pairing");
      return;
    }

    state.categories.editorId = null;
    elements.categoryEditorTitle.textContent = "ساخت دسته‌بندی";
    elements.categoryName.value = "";
    elements.categorySlug.value = "";
    elements.categoryParent.value = "0";
    elements.categoryDescription.value = "";
    setCategoryEditorMessage("");
    elements.categoryEditor.hidden = false;
    elements.categoryName.focus();
  }

  function openCategoryEditor(categoryId) {
    var category = state.categories.items.filter(function (item) {
      return item.id === String(categoryId);
    })[0];
    if (!category) {
      setCategoriesState("error", "اطلاعات دسته‌بندی برای ویرایش در دسترس نیست.");
      return;
    }

    state.categories.editorId = category.id;
    elements.categoryEditorTitle.textContent = formatDisplayText("ویرایش دسته‌بندی #" + formatDisplayId(category.id));
    elements.categoryName.value = category.name === "دسته‌بندی بدون نام" ? "" : category.name;
    elements.categorySlug.value = category.slug;
    elements.categoryDescription.value = category.description;
    setCategoryEditorMessage("");
    renderCategoryOptions();
    elements.categoryParent.value = category.parent;
    elements.categoryEditor.hidden = false;
    elements.categoryName.focus();
  }

  function categoryResourceUrl(categoryId) {
    var base = apiUrl("categoriesUrl");
    categoryId = String(categoryId || "");
    if (!base || !/^\d+$/.test(categoryId) || categoryId === "0") {
      return "";
    }
    try {
      return new URL(categoryId + "/", base.replace(/\/?$/, "/")).toString();
    } catch (error) {
      return "";
    }
  }

  function saveCategory(event) {
    event.preventDefault();
    if (!state.authenticated || !state.csrfToken) {
      setCategoryEditorMessage("نشست امن معتبر نیست؛ دوباره وارد شوید.");
      return;
    }

    var name = elements.categoryName.value.trim();
    if (!name) {
      setCategoryEditorMessage("نام دسته‌بندی الزامی است.");
      elements.categoryName.focus();
      return;
    }

    var body = {
      name: name,
      slug: elements.categorySlug.value.trim(),
      parent: Number(elements.categoryParent.value || 0),
      description: elements.categoryDescription.value
    };
    var editorId = state.categories.editorId;
    var url = editorId ? categoryResourceUrl(editorId) : apiUrl("categoriesUrl");
    if (!url) {
      setCategoryEditorMessage("نشانی API دسته‌بندی‌ها در پیکربندی سایت موجود نیست.");
      return;
    }

    elements.saveCategoryButton.disabled = true;
    setCategoryEditorMessage("در حال ذخیرهٔ دسته‌بندی...");
    fetchJsonWithTimeout(url, {
      method: editorId ? "PATCH" : "POST",
      headers: { "Content-Type": "application/json", "X-Fandoogh-CSRF": state.csrfToken },
      body: JSON.stringify(body)
    }).then(function () {
      closeCategoryEditor();
      return Promise.all([loadCategories(), loadProducts()]);
    }).catch(function (error) {
      if (error && error.status === 401) {
        setAuthenticated(false, "");
        showView("pairing");
        showPairingMessage("نشست منقضی شده است؛ دوباره جفت‌سازی کنید.");
        return;
      }
      setCategoryEditorMessage(responseErrorMessage(error, "ذخیرهٔ دسته‌بندی انجام نشد؛ اطلاعات ورودی را بررسی کنید."));
    }).finally(function () {
      elements.saveCategoryButton.disabled = false;
    });
  }

  function loadCategories() {
    if (state.previewMode) {
      renderCategoryResults();
      return Promise.resolve(true);
    }

    if (!state.authenticated) {
      setCategoriesState("secure", "برای دریافت دسته‌بندی‌ها، ابتدا اتصال امن مدیر را برقرار کنید.");
      return Promise.resolve();
    }

    var categoriesUrl = state.config && state.config.api ? state.config.api.categoriesUrl : "";
    if (!categoriesUrl) {
      setCategoriesState("error", "نشانی API دسته‌بندی‌ها در پیکربندی سایت موجود نیست.");
      return Promise.resolve();
    }

    function pageUrl(page) {
      var url = new URL(categoriesUrl, window.location.href);
      url.searchParams.set("per_page", "100");
      url.searchParams.set("page", String(page));
      return url.toString();
    }
    function fetchPages(page, totalPages, collected) {
      if (page > totalPages || page > 50) {
        return Promise.resolve(collected);
      }
      return fetchJsonWithTimeout(pageUrl(page)).then(function (payload) {
        return fetchPages(page + 1, totalPages, collected.concat(extractCategories(payload)));
      });
    }

    setCategoriesState("loading");
    return fetchJsonWithTimeout(pageUrl(1)).then(function (payload) {
      var firstPage = extractCategories(payload);
      var totalPages = payload && payload.meta ? Math.max(1, Number(payload.meta.total_pages) || 1) : 1;
      return fetchPages(2, totalPages, firstPage);
    }).then(function (categories) {
      state.categories.items = categories;
      state.categories.status = "ready";
      renderCategoryOptions();
      renderCategoryResults();
    }).catch(function (error) {
      if (error && error.status === 401) {
        setAuthenticated(false, "");
        showView("pairing");
        showPairingMessage("نشست منقضی شده است؛ دوباره جفت‌سازی کنید.");
        return;
      }
      setCategoriesState("error", "اتصال به API دسته‌بندی‌ها برقرار نشد.");
    });
  }

  // [UI: ORDERS] فهرست سفارش‌ها، فیلترها، جزئیات، وضعیت ارسال و عملیات سفارش.
  function orderStateLabel(stateName) {
    var labels = {
      secure: "نیازمند اتصال امن",
      loading: "در حال دریافت",
      empty: "بدون سفارش",
      error: "خطا در دریافت",
      ready: "آماده"
    };

    return labels[stateName] || labels.secure;
  }

  function setOrdersState(stateName, detail) {
    if (!elements.ordersStateBadge) {
      return;
    }

    state.orders.status = stateName;
    elements.ordersStateBadge.textContent = orderStateLabel(stateName);
    elements.ordersStateBadge.setAttribute("data-state", stateName);
    elements.ordersSecureState.hidden = stateName !== "secure";
    elements.ordersLoadingState.hidden = stateName !== "loading";
    elements.ordersEmptyState.hidden = stateName !== "empty";
    elements.ordersErrorState.hidden = stateName !== "error";
    elements.ordersGrid.hidden = stateName !== "ready";
    if (elements.ordersPagination) {
      renderOrdersPagination();
    }

    if (stateName === "empty" && detail) {
      elements.ordersEmptyText.textContent = formatDisplayText(detail);
    }
    if (stateName === "error" && detail) {
      elements.ordersErrorText.textContent = formatDisplayText(detail);
    }
  }

  function setOrdersMutationMessage(message) {
    if (!elements.ordersMutationMessage) {
      return;
    }

    elements.ordersMutationMessage.textContent = formatDisplayText(message || "");
    elements.ordersMutationMessage.hidden = !message;
  }

  function setOrderFilterPanelOpen(isOpen) {
    if (!elements.orderFilterPanel || !elements.orderFilterToggle) {
      return;
    }

    var shouldOpen = Boolean(isOpen);
    elements.orderFilterPanel.hidden = !shouldOpen;
    elements.orderFilterToggle.setAttribute("aria-expanded", String(shouldOpen));
    if (elements.orderFilterBackdrop) {
      elements.orderFilterBackdrop.hidden = !shouldOpen;
    }
    document.body.classList.toggle("order-filter-open", shouldOpen);

    if (shouldOpen && elements.orderDateFrom) {
      window.setTimeout(function () {
        if (!elements.orderFilterPanel.hidden) {
          elements.orderDateFrom.focus();
        }
      }, 0);
    }
  }

  function toggleOrderFilterPanel() {
    if (!elements.orderFilterPanel) {
      return;
    }
    setOrderFilterPanelOpen(elements.orderFilterPanel.hidden);
  }

  function closeOrderFilterPanel() {
    setOrderFilterPanelOpen(false);
  }

  function selectOrderStatus(status) {
    var normalizedStatus = String(status || "any").replace(/^wc-/, "");
    state.orders.filters.status = normalizedStatus || "any";
    state.orders.page = 1;
    if (elements.orderStatusFilter) {
      elements.orderStatusFilter.value = state.orders.filters.status;
    }
    renderOrderQuickStatusFilters();
    closeOrderFilterPanel();
    loadOrders(1);
  }

  function normalizeOrder(value) {
    var source = value && typeof value === "object" ? value : {};
    var customer = source.customer && typeof source.customer === "object" ? source.customer : {};
    var payment = source.payment && typeof source.payment === "object" ? source.payment : {};
    var id = safeText(String(source.id || ""), "", 40);
    var statusLabel = safeText(source.status_label || source.status, "نامشخص", 80);
    var customerName = safeText(customer.display || source.customer_name, "مهمان", 120);
    var totalRaw = safeText(String(source.total || "0"), "0", 50);
    var currency = safeText(source.currency, "", 8);
    var currencyLabel = formatCurrencyLabel(currency);

    return {
      id: id,
      number: safeText(String(source.number || source.order_number || id), id, 80),
      statusValue: safeText(source.status, "", 64),
      status: statusLabel,
      date: source.date || source.created_at ? formatDisplayDate(source.date || source.created_at, true) : "تاریخ ثبت نشده",
      totalRaw: totalRaw,
      total: totalRaw + (currencyLabel ? " " + currencyLabel : ""),
      currency: currency,
      customer: customerName,
      customerId: safeText(String(customer.id || ""), "", 40),
      customerEmail: safeText(customer.email, "", 160),
      customerPhone: safeText(customer.phone, "", 80),
      itemCount: source.items && typeof source.items === "object" ? String(source.items.count || 0) : "0",
      paymentMethod: safeText(payment.method_title || payment.method, "", 160),
      paymentStatus: safeText(payment.status, "", 40)
    };
  }

  function orderStatusKey(value) {
    var key = String(value || "").replace(/^wc-/, "").trim().toLowerCase();
    return /^[a-z0-9-]+$/.test(key) ? key : "";
  }

  function extractOrders(payload) {
    if (Array.isArray(payload)) {
      return payload.map(normalizeOrder);
    }
    if (payload && Array.isArray(payload.orders)) {
      return payload.orders.map(normalizeOrder);
    }
    if (payload && Array.isArray(payload.data)) {
      return payload.data.map(normalizeOrder);
    }
    return [];
  }

  function isUnprocessedOrder(order) {
    if (!order || typeof order !== "object") {
      return false;
    }
    return String(order.statusValue || "").replace(/^wc-/, "") === "pending";
  }

  function fallbackPendingOrderCount() {
    return state.orders.items.filter(isUnprocessedOrder).length;
  }

  function updateNotificationsPanel() {
    if (!elements.notificationsButton || !elements.notificationsBadge || !elements.notificationsPanel) {
      return;
    }

    // پیش از ورود (و بعد از خروج) زنگ اعلان‌ها نباید دیده شود؛
    // این تابع در مسیرهای بدون نشست هم صدا زده می‌شود، پس خودش حالت را کنترل می‌کند.
    if (!state.authenticated) {
      elements.notificationsButton.hidden = true;
      elements.notificationsPanel.hidden = true;
      elements.notificationsButton.setAttribute("aria-expanded", "false");
      return;
    }

    var pendingCount = state.orders.pendingCount === null || typeof state.orders.pendingCount === "undefined" ? fallbackPendingOrderCount() : Math.max(0, Number(state.orders.pendingCount) || 0);
    var displayCount = formatDisplayNumber(pendingCount);
    var hasPendingOrders = pendingCount > 0;
    var fetching = !state.authenticated || state.orders.pendingCountRequestId < 1 ? false : state.orders.pendingCount === null;

    elements.notificationsBadge.textContent = fetching ? "…" : displayCount;
    elements.notificationsBadge.hidden = !hasPendingOrders && !fetching;
    elements.notificationsButton.hidden = false;
    elements.notificationsButton.setAttribute("aria-label", fetching ? "اعلان‌ها، در حال به‌روزرسانی" : hasPendingOrders ? "اعلان‌ها، " + displayCount + " سفارش جدید بدون اقدام" : "اعلان‌ها");

    if (elements.notificationsPanelTitle) {
      elements.notificationsPanelTitle.textContent = "سفارش‌های در انتظار بررسی";
    }
    if (elements.notificationsPanelText) {
      var panelFetching = fetching;
      if (panelFetching) {
        elements.notificationsPanelText.textContent = "در حال به‌روزرسانی";
      } else if (hasPendingOrders) {
        elements.notificationsPanelText.textContent = displayCount + " سفارش جدید بدون اقدام در انتظار بررسی است.";
      } else {
        elements.notificationsPanelText.textContent = "سفارش جدیدی برای بررسی نیست.";
      }
      if (panelFetching) {
        elements.notificationsPanelText.dataset.fetching = "true";
      } else {
        if (elements.notificationsPanelText.dataset.fetching === "true" || elements.notificationsPanelText.dataset.fetching === "true") {
          delete elements.notificationsPanelText.dataset.fetching;
        }
      }
    }

    renderNotificationsPendingList(hasPendingOrders);
  }

  // [UI: NOTIFICATIONS] فهرست جدیدترین سفارش‌های در انتظار بررسی داخل پوپ‌اور اعلان‌ها.
  function renderNotificationsPendingList(hasPendingOrders) {
    var list = elements.notificationsPendingList;
    if (!list) {
      return;
    }

    if (!hasPendingOrders) {
      list.textContent = "";
      list.hidden = true;
      return;
    }

    var pendingItems = Array.isArray(state.orders.pendingItems) ? state.orders.pendingItems.slice(0, 5) : [];
    if (!pendingItems.length) {
      list.textContent = "";
      list.hidden = true;
      return;
    }

    while (list.firstChild) {
      list.removeChild(list.firstChild);
    }

    pendingItems.forEach(function (order) {
      var item = document.createElement("li");
      item.className = "notifications-pending-item";
      item.setAttribute("role", "listitem");

      var orderNumber = safeText(String(order.number || order.id || ""), "سفارش", 80);
      var customer = safeText(order.customer, "مهمان", 120);
      var total = safeText(order.total, "", 60);
      var openButton = document.createElement("button");
      openButton.type = "button";
      openButton.className = "notifications-pending-open";
      openButton.setAttribute("aria-label", "مشاهدهٔ سفارش " + orderNumber);
      openButton.addEventListener("click", function () {
        openNotificationOrder(order.id);
      });

      var numberEl = document.createElement("strong");
      numberEl.className = "notifications-pending-number";
      numberEl.textContent = "#" + formatDisplayNumber(orderNumber);
      var metaEl = document.createElement("span");
      metaEl.className = "notifications-pending-meta";
      metaEl.textContent = customer + (total ? " · " + total : "");

      openButton.appendChild(numberEl);
      openButton.appendChild(document.createTextNode(" "));
      openButton.appendChild(metaEl);
      item.appendChild(openButton);
      list.appendChild(item);
    });

    list.hidden = false;
  }

  function openNotificationOrder(orderId) {
    if (!orderId) {
      return;
    }
    if (elements.notificationsPanel) {
      elements.notificationsPanel.hidden = true;
    }
    if (elements.notificationsButton) {
      elements.notificationsButton.setAttribute("aria-expanded", "false");
    }
    navigateToSection("orders");
    loadOrderDetail(String(orderId));
  }

  function updatePendingOrderBadges() {
    var pendingCount = state.orders.pendingCount === null || typeof state.orders.pendingCount === "undefined" ? fallbackPendingOrderCount() : Math.max(0, Number(state.orders.pendingCount) || 0);
    var displayCount = formatDisplayNumber(pendingCount);
    var hasPendingOrders = pendingCount > 0;
    var fetching = state.orders.pendingCountRequestId < 1 ? false : state.orders.pendingCount === null;

    if (elements.notificationsBadge) {
      elements.notificationsBadge.textContent = fetching ? "…" : displayCount;
      elements.notificationsBadge.hidden = !hasPendingOrders && !fetching;
      if (fetching) {
        elements.notificationsBadge.setAttribute("data-loading", "true");
      } else {
        elements.notificationsBadge.removeAttribute("data-loading");
      }
    }

    updateNotificationsPanel();

    [elements.quickOrdersBadge, elements.sidebarOrdersBadge, elements.mobileOrdersBadge].forEach(function (badge) {
      if (!badge) {
        return;
      }
      badge.textContent = displayCount;
      badge.hidden = !hasPendingOrders;
    });

    Array.prototype.forEach.call(document.querySelectorAll('.sidebar-nav-item[data-nav-target="orders"], .mobile-nav-item[data-nav-target="orders"], .quick-action--orders'), function (item) {
      item.setAttribute("aria-label", hasPendingOrders ? "سفارش‌ها، " + displayCount + " سفارش جدید بدون اقدام" : "سفارش‌ها");
    });

    if (elements.pendingOrdersAnnouncement && state.orders.pendingCountAnnouncement !== pendingCount) {
      elements.pendingOrdersAnnouncement.textContent = hasPendingOrders ? displayCount + " سفارش جدید بدون اقدام در انتظار بررسی است." : "";
      state.orders.pendingCountAnnouncement = pendingCount;
    }
  }

  function renderOrderCards(items) {
    while (elements.ordersGrid.firstChild) {
      elements.ordersGrid.removeChild(elements.ordersGrid.firstChild);
    }

    items.forEach(function (order) {
      var card = document.createElement("article");
      card.className = "order-card";
      card.dataset.status = orderStatusKey(order.statusValue) || "unknown";
      card.setAttribute("role", "listitem");
      card.tabIndex = 0;
      card.setAttribute("aria-label", "سفارش شماره " + formatDisplayId(order.number || order.id));

      var header = document.createElement("div");
      header.className = "order-card-header";
      var heading = document.createElement("div");
      heading.className = "order-card-heading";
      heading.appendChild(createTextElement("h3", "order-card-id", "سفارش #" + formatDisplayId(order.number || order.id)));
      var statusBadge = createTextElement("span", "order-card-status", order.status);
      statusBadge.dataset.status = orderStatusKey(order.statusValue);
      heading.appendChild(statusBadge);
      header.appendChild(heading);

      var meta = document.createElement("div");
      meta.className = "order-card-meta";
      meta.appendChild(createTextElement("p", "order-card-date", order.date));
      header.appendChild(meta);
      card.appendChild(header);

      var details = document.createElement("div");
      details.className = "order-card-details";
      details.appendChild(createTextElement("p", "order-card-customer", order.customer));
      details.appendChild(createTextElement("p", "order-card-total", formatDisplayAmount(order.total)));
      details.appendChild(createTextElement("p", "order-card-items", formatDisplayNumber(order.itemCount) + " قلم"));
      card.appendChild(details);

      var actions = document.createElement("div");
      actions.className = "order-card-actions";
      var viewButton = document.createElement("button");
      viewButton.type = "button";
      viewButton.className = "order-card-icon-button order-card-view-button";
      viewButton.setAttribute("aria-label", "مشاهدهٔ سفارش " + formatDisplayId(order.number || order.id));
      viewButton.title = "مشاهدهٔ سفارش";
      viewButton.appendChild(createProductIcon("eye"));
      var editButton = document.createElement("button");
      editButton.type = "button";
      editButton.className = "order-card-icon-button order-card-edit-button";
      editButton.setAttribute("aria-label", "ویرایش سفارش " + formatDisplayId(order.number || order.id));
      editButton.title = "ویرایش سفارش";
      editButton.appendChild(createProductIcon("edit"));
      actions.appendChild(editButton);
      actions.appendChild(viewButton);
      card.appendChild(actions);

      function openOrderDetails(trigger) {
        state.orders.detailTrigger = trigger || viewButton;
        loadOrderDetail(order.id);
      }

      viewButton.addEventListener("click", function () {
        openOrderDetails(viewButton);
      });
      editButton.addEventListener("click", function () {
        openOrderEditor(order.id, editButton, false);
      });
      card.addEventListener("click", function (event) {
        if (event.target && event.target.closest && event.target.closest("button")) {
          return;
        }
        openOrderDetails(card);
      });
      card.addEventListener("keydown", function (event) {
        if ((event.key === "Enter" || event.key === " ") && !(event.target && event.target.closest && event.target.closest("button"))) {
          event.preventDefault();
          openOrderDetails(card);
        }
      });
      elements.ordersGrid.appendChild(card);
    });
  }

  function renderOrderResults() {
    if (state.orders.status !== "ready" && state.orders.status !== "empty" && !state.orders.items.length) {
      return;
    }

    var query = normalizeSearchText(state.orders.filters.search);
    var filteredItems = state.orders.items.filter(function (order) {
      return searchTextIncludes([
        order.id,
        order.number,
        order.customer,
        order.customerEmail,
        order.customerPhone,
        order.paymentMethod,
        order.paymentStatus
      ], query);
    });
    state.orders.filteredItems = filteredItems;

    if (!filteredItems.length) {
      setOrdersState("empty", state.orders.filters.search ? "سفارشی با این عبارت یا فیلترها پیدا نشد." : "هنوز سفارشی برای نمایش وجود ندارد.");
      renderOrdersPagination();
      return;
    }

    renderOrderCards(filteredItems);
    setOrdersState("ready");
    renderOrdersPagination();
  }

  function orderInputValue(input) {
    return getPersianDateInputValue(input);
  }

  function renderOrderQuickStatusFilters() {
    var selected = String(state.orders.filters.status || "any").replace(/^wc-/, "");
    if (elements.orderStatusFilter) {
      elements.orderStatusFilter.value = selected;
    }
    if (!elements.orderStatusChips) {
      return;
    }

    Array.prototype.forEach.call(elements.orderStatusChips.querySelectorAll("[data-order-status]"), function (chip) {
      var isSelected = chip.getAttribute("data-order-status") === selected;
      chip.classList.toggle("is-active", isSelected);
      chip.setAttribute("aria-pressed", String(isSelected));
    });
  }

  function renderOrderStatusOptions() {
    if (!elements.orderStatusFilter && !elements.orderStatusChips) {
      return;
    }

    var selected = String(state.orders.filters.status || "any").replace(/^wc-/, "");
    if (elements.orderStatusFilter) {
      while (elements.orderStatusFilter.options.length > 1) {
        elements.orderStatusFilter.remove(1);
      }
    }

    if (elements.orderStatusChips) {
      while (elements.orderStatusChips.firstChild) {
        elements.orderStatusChips.removeChild(elements.orderStatusChips.firstChild);
      }
      elements.orderStatusChips.appendChild(createOrderStatusChip("any", "همه"));
    }

    Object.keys(state.orders.statuses).forEach(function (statusKey) {
      var optionValue = orderStatusKey(statusKey);
      if (!optionValue) {
        return;
      }
      var optionLabel = safeText(state.orders.statuses[statusKey], optionValue, 80);
      if (elements.orderStatusFilter) {
        var option = document.createElement("option");
        option.value = optionValue;
        option.textContent = optionLabel;
        elements.orderStatusFilter.appendChild(option);
      }
      if (elements.orderStatusChips && !elements.orderStatusChips.querySelector('[data-order-status="' + optionValue + '"]')) {
        elements.orderStatusChips.appendChild(createOrderStatusChip(optionValue, optionLabel));
      }
    });
    if (elements.orderStatusFilter) {
      elements.orderStatusFilter.value = selected;
    }
    renderOrderQuickStatusFilters();
  }

  function createOrderStatusChip(status, label) {
    var chip = document.createElement("button");
    chip.type = "button";
    chip.className = "orders-status-chip";
    chip.dataset.orderStatus = status;
    chip.setAttribute("aria-pressed", "false");
    chip.textContent = label;
    chip.addEventListener("click", function () {
      selectOrderStatus(status);
    });
    return chip;
  }

  function readOrderFiltersFromForm() {
    var status = orderInputValue(elements.orderStatusFilter) || "any";
    var minTotal = normalizeNumericInput(orderInputValue(elements.orderMinTotal));
    var maxTotal = normalizeNumericInput(orderInputValue(elements.orderMaxTotal));
    var customerId = toEnglishDigits(orderInputValue(elements.orderCustomerId)).replace(/[^0-9]/g, "");
    return {
      search: orderInputValue(elements.orderSearch),
      status: status,
      dateFrom: orderInputValue(elements.orderDateFrom),
      dateTo: orderInputValue(elements.orderDateTo),
      minTotal: minTotal,
      maxTotal: maxTotal,
      customerId: customerId,
      paymentMethod: orderInputValue(elements.orderPaymentMethod).toLowerCase(),
      shippingMethod: orderInputValue(elements.orderShippingMethod).toLowerCase()
    };
  }

  function syncOrderFilterForm() {
    var filters = state.orders.filters;
    if (elements.orderSearch) elements.orderSearch.value = filters.search || "";
    if (elements.orderStatusFilter) elements.orderStatusFilter.value = filters.status || "any";
    if (elements.orderDateFrom) setPersianDateInputValue(elements.orderDateFrom, filters.dateFrom || "");
    if (elements.orderDateTo) setPersianDateInputValue(elements.orderDateTo, filters.dateTo || "");
    if (elements.orderMinTotal) elements.orderMinTotal.value = filters.minTotal || "";
    if (elements.orderMaxTotal) elements.orderMaxTotal.value = filters.maxTotal || "";
    if (elements.orderCustomerId) elements.orderCustomerId.value = filters.customerId || "";
    if (elements.orderPaymentMethod) elements.orderPaymentMethod.value = filters.paymentMethod || "";
    if (elements.orderShippingMethod) elements.orderShippingMethod.value = filters.shippingMethod || "";
    renderOrderQuickStatusFilters();
  }

  function renderOrdersPagination() {
    if (!elements.ordersPagination) return;
    var totalPages = Math.max(1, Number(state.orders.totalPages || 0));
    var page = Math.min(totalPages, Math.max(1, Number(state.orders.page || 1)));
    elements.ordersPagination.hidden = state.orders.status === "secure" || state.orders.status === "loading" || state.orders.status === "error" || state.orders.status === "empty";
    elements.ordersPreviousPage.disabled = page <= 1;
    elements.ordersNextPage.disabled = page >= totalPages;
    elements.ordersPageLabel.textContent = "صفحهٔ " + formatDisplayNumber(page) + " از " + formatDisplayNumber(totalPages);
  }

  function getOrdersPageSize() {
    var viewportWidth = Math.max(Number(window.innerWidth || 0), Number(document.documentElement && document.documentElement.clientWidth || 0));
    if (viewportWidth <= 880) {
      return 6;
    }
    if (viewportWidth <= 1100) {
      return 12;
    }
    return 15;
  }

  function ordersRequestUrl(page, options) {
    var base = state.config && state.config.api ? state.config.api.ordersUrl : "";
    if (!base) return "";
    try {
      var url = new URL(base, window.location.href);
      var filters = state.orders.filters;
      var requestOptions = options && typeof options === "object" ? options : {};
      var includeFilters = requestOptions.includeFilters !== false;
      url.searchParams.set("page", String(Math.max(1, Number(page || 1))));
      url.searchParams.set("per_page", String(requestOptions.perPage || getOrdersPageSize()));
      if (requestOptions.status) {
        url.searchParams.set("status", requestOptions.status);
      } else if (includeFilters && filters.status && filters.status !== "any") {
        url.searchParams.set("status", filters.status);
      }
      if (includeFilters) {
        if (filters.search) url.searchParams.set("search", normalizeSearchText(filters.search));
        if (filters.dateFrom) url.searchParams.set("date_from", filters.dateFrom);
        if (filters.dateTo) url.searchParams.set("date_to", filters.dateTo);
        if (filters.minTotal) url.searchParams.set("min_total", filters.minTotal);
        if (filters.maxTotal) url.searchParams.set("max_total", filters.maxTotal);
        if (filters.customerId) url.searchParams.set("customer_id", filters.customerId);
        if (filters.paymentMethod) url.searchParams.set("payment_method", filters.paymentMethod);
        if (filters.shippingMethod) url.searchParams.set("shipping_method", filters.shippingMethod);
      }
      return url.toString();
    } catch (error) {
      return "";
    }
  }

  function applyOrderFilters() {
    state.orders.filters = readOrderFiltersFromForm();
    state.orders.page = 1;
    renderOrderQuickStatusFilters();
    closeOrderFilterPanel();
    loadOrders(1);
  }

  function resetOrderFilters() {
    state.orders.filters = {
      search: "",
      status: "any",
      dateFrom: "",
      dateTo: "",
      minTotal: "",
      maxTotal: "",
      customerId: "",
      paymentMethod: "",
      shippingMethod: ""
    };
    syncOrderFilterForm();
    closeOrderFilterPanel();
    loadOrders(1);
  }

  function syncManualOrderAccess() {
    if (!elements.newOrderButton) {
      return;
    }
    elements.newOrderButton.hidden = !userHasScope("orders.create");
  }

  function setManualOrderMessage(message, stateName) {
    if (!elements.manualOrderMessage) {
      return;
    }
    elements.manualOrderMessage.textContent = formatDisplayText(message || "");
    elements.manualOrderMessage.hidden = !message;
    elements.manualOrderMessage.setAttribute("data-state", stateName || "info");
  }

  function manualOrderInputLabel(labelText, id, type, value, options) {
    var settings = options && typeof options === "object" ? options : {};
    var label = document.createElement("label");
    var input = document.createElement(type === "textarea" ? "textarea" : (type === "select" ? "select" : "input"));
    label.className = "field-label";
    label.setAttribute("for", id);
    input.id = id;
    input.name = id;
    if (type !== "select" && type !== "textarea") {
      input.type = type || "text";
    }
    if (settings.inputMode) input.inputMode = settings.inputMode;
    if (settings.placeholder) input.placeholder = settings.placeholder;
    if (settings.maxLength) input.maxLength = settings.maxLength;
    if (settings.required) input.required = true;
    if (settings.disabled) input.disabled = true;
    if (type === "textarea" && settings.rows) input.rows = settings.rows;
    if (type === "select" && Array.isArray(settings.options)) {
      settings.options.forEach(function (optionData) {
        var option = document.createElement("option");
        option.value = optionData.value;
        option.textContent = formatDisplayText(optionData.label);
        option.disabled = Boolean(optionData.disabled);
        option.selected = optionData.value === String(value || "");
        input.appendChild(option);
      });
    } else if (type === "checkbox") {
      input.checked = Boolean(value);
    } else {
      input.value = value === null || typeof value === "undefined" ? "" : String(value);
    }
    label.appendChild(document.createTextNode(labelText));
    label.appendChild(input);
    if (settings.hint) {
      var hint = document.createElement("small");
      hint.className = "manual-order-field-hint";
      hint.textContent = formatDisplayText(settings.hint);
      label.appendChild(hint);
    }
    return { label: label, input: input };
  }

  function manualOrderInfo(text, tone) {
    var note = document.createElement("div");
    note.className = "manual-order-info" + (tone ? " manual-order-info--" + tone : "");
    note.setAttribute("role", tone === "warning" ? "status" : "note");
    note.textContent = formatDisplayText(text);
    return note;
  }

  function manualOrderSectionHeading(kicker, title, description) {
    var heading = document.createElement("div");
    heading.className = "product-editor-step-heading";
    heading.appendChild(createTextElement("span", "card-kicker", kicker));
    heading.appendChild(createTextElement("h4", "", title));
    if (description) {
      heading.appendChild(createTextElement("p", "product-step-description", description));
    }
    return heading;
  }

  function manualOrderProductOptions(select, selectedId) {
    var placeholder = document.createElement("option");
    placeholder.value = "";
    placeholder.textContent = "انتخاب محصول";
    placeholder.disabled = true;
    placeholder.selected = !selectedId;
    select.appendChild(placeholder);
    state.products.items.slice(0, 100).forEach(function (product) {
      if (!product || !product.id) return;
      var option = document.createElement("option");
      option.value = String(product.id);
      option.textContent = formatDisplayText(product.name + (product.sku ? " · " + product.sku : ""));
      option.selected = String(product.id) === String(selectedId || "");
      select.appendChild(option);
    });
  }

  function manualOrderProduct(productId) {
    return state.products.items.filter(function (product) {
      return product && String(product.id) === String(productId || "");
    })[0] || null;
  }

  function manualOrderVariationLabel(variation) {
    var attributes = variation && variation.attributes && typeof variation.attributes === "object" ? variation.attributes : {};
    var attributeText = Object.keys(attributes).map(function (key) {
      var value = attributes[key];
      if (value && typeof value === "object") {
        value = value.label || value.name || value.value || "";
      }
      return String(key) + " : " + String(value || "");
    }).filter(Boolean).join("، ");
    return "#" + formatDisplayId(variation.id) + (attributeText ? " · " + attributeText : "");
  }

  function manualOrderVariationOptions(select, item) {
    var placeholder = document.createElement("option");
    placeholder.value = "";
    placeholder.textContent = item.variationsLoading ? "در حال دریافت تنوع‌ها" : (item.variationError ? "دریافت تنوع‌ها ناموفق بود" : (item.variations.length ? "انتخاب تنوع محصول" : "تنوعی ثبت نشده است"));
    placeholder.disabled = true;
    placeholder.selected = !item.variationId;
    select.appendChild(placeholder);
    item.variations.forEach(function (variation) {
      if (!variation || !variation.id) return;
      var option = document.createElement("option");
      option.value = String(variation.id);
      option.textContent = formatDisplayText(manualOrderVariationLabel(variation));
      option.selected = String(variation.id) === String(item.variationId || "");
      select.appendChild(option);
    });
  }

  function loadManualOrderVariations(item, parentId) {
    var url = variationCollectionUrl(parentId);
    if (!url || !state.authenticated || !item) {
      return Promise.resolve();
    }

    var requestId = (Number(item.variationRequestId) || 0) + 1;
    item.variationRequestId = requestId;
    item.variationsLoading = true;
    item.variationError = "";
    return fetchJsonWithTimeout(url).then(function (payload) {
      if (requestId !== Number(item.variationRequestId)) return false;
      item.variations = extractVariations(payload);
      item.variationsLoading = false;
      if (item.variationId && !item.variations.some(function (variation) { return String(variation.id) === String(item.variationId); })) {
        item.variationId = "";
      }
      if (state.manualOrder.step === 1 && elements.manualOrderOverlay && !elements.manualOrderOverlay.hidden) {
        renderManualOrderStep();
      }
      return true;
    }).catch(function (error) {
      if (requestId !== Number(item.variationRequestId)) return false;
      item.variations = [];
      item.variationsLoading = false;
      item.variationError = responseErrorMessage(error, "دریافت تنوع‌های محصول انجام نشد.");
      if (error && error.status === 401) {
        setAuthenticated(false, "");
        showView("pairing");
        showPairingMessage("نشست منقضی شده است؛ دوباره جفت‌سازی کنید.");
        return false;
      }
      if (state.manualOrder.step === 1 && elements.manualOrderOverlay && !elements.manualOrderOverlay.hidden) {
        renderManualOrderStep();
      }
      return false;
    });
  }

  function manualOrderCustomerOptions(select, selectedId) {
    var placeholder = document.createElement("option");
    placeholder.value = "";
    placeholder.textContent = "انتخاب مشتری ثبت‌شده";
    placeholder.disabled = true;
    placeholder.selected = !selectedId;
    select.appendChild(placeholder);
    state.customers.items.slice(0, 100).forEach(function (customer) {
      if (!customer || !customer.id) return;
      var option = document.createElement("option");
      option.value = String(customer.id);
      option.textContent = formatDisplayText(customer.display + (customer.email ? " · " + customer.email : ""));
      option.selected = String(customer.id) === String(selectedId || "");
      select.appendChild(option);
    });
  }

  function renderManualOrderCustomerStep(container) {
    container.appendChild(manualOrderSectionHeading("مرحلهٔ اول", "انتخاب مشتری", "مهمان، مشتری ثبت‌شده یا مشتری جدید را برای این سفارش مشخص کنید."));
    var choices = document.createElement("div");
    choices.className = "manual-order-choice-grid";
    [
      ["guest", "مهمان", "سفارش بدون اتصال به حساب مشتری ثبت می‌شود."],
      ["existing", "مشتری ثبت‌شده", "از مشتریان بارگذاری‌شدهٔ فروشگاه انتخاب کنید."],
      ["new", "مشتری جدید", "اطلاعات پایهٔ مشتری جدید را همراه سفارش بفرستید."]
    ].forEach(function (choice) {
      var label = document.createElement("label");
      label.className = "manual-order-choice" + (state.manualOrder.customerType === choice[0] ? " is-selected" : "");
      var input = document.createElement("input");
      input.type = "radio";
      input.name = "manualCustomerType";
      input.value = choice[0];
      input.checked = state.manualOrder.customerType === choice[0];
      input.addEventListener("change", function () {
        state.manualOrder.customerType = choice[0];
        if (choice[0] !== "existing") state.manualOrder.customerId = "";
        renderManualOrderStep();
      });
      label.appendChild(input);
      var copy = document.createElement("span");
      copy.appendChild(createTextElement("strong", "", choice[1]));
      copy.appendChild(createTextElement("small", "", choice[2]));
      label.appendChild(copy);
      choices.appendChild(label);
    });
    container.appendChild(choices);

    if (state.manualOrder.customerType === "guest") {
      container.appendChild(manualOrderInfo("این سفارش به‌صورت مهمان ثبت می‌شود و به حساب مشتری خاصی متصل نیست."));
      return;
    }

    if (state.manualOrder.customerType === "existing") {
      if (!userHasScope("customers.read") || state.customers.status === "error" || !state.customers.items.length) {
        container.appendChild(manualOrderInfo("فهرست مشتریان برای انتخاب آماده نیست. ابتدا دسترسی خواندن مشتریان و اتصال API داخلی را بررسی کنید؛ فعلاً می‌توانید مشتری مهمان یا جدید را انتخاب کنید.", "warning"));
        return;
      }
      var customerField = manualOrderInputLabel("مشتری ثبت‌شده", "manualOrderCustomerId", "select", state.manualOrder.customerId, { options: [] });
      manualOrderCustomerOptions(customerField.input, state.manualOrder.customerId);
      customerField.input.addEventListener("change", function () {
        state.manualOrder.customerId = customerField.input.value;
      });
      container.appendChild(customerField.label);
      return;
    }

    var newGrid = document.createElement("div");
    newGrid.className = "product-editor-grid manual-order-form-grid";
    [
      ["نام", "manualOrderFirstName", state.manualOrder.customer.firstName, "نام مشتری", "text"],
      ["نام خانوادگی", "manualOrderLastName", state.manualOrder.customer.lastName, "نام خانوادگی مشتری", "text"],
      ["ایمیل", "manualOrderEmail", state.manualOrder.customer.email, "example@example.com", "email"],
      ["تلفن", "manualOrderPhone", state.manualOrder.customer.phone, "مثلاً ۰۹۱۲...", "tel"]
    ].forEach(function (fieldData) {
      var field = manualOrderInputLabel(fieldData[0], fieldData[1], fieldData[4], fieldData[2], { placeholder: fieldData[3], maxLength: fieldData[4] === "email" ? 160 : 80 });
      field.input.addEventListener("input", function () {
        state.manualOrder.customer[fieldData[1].replace("manualOrder", "").replace(/^./, function (letter) { return letter.toLowerCase(); })] = field.input.value.trim();
      });
      newGrid.appendChild(field.label);
    });
    container.appendChild(newGrid);
    container.appendChild(manualOrderInfo("برای مشتری جدید، حداقل یکی از نام یا نام خانوادگی را وارد کنید. ثبت نهایی و اعتبارسنجی روی سرور انجام می‌شود."));
  }

  function renderManualOrderItemRow(item, index) {
    var row = document.createElement("div");
    row.className = "manual-order-item-row";
    row.dataset.itemIndex = String(index);
    var productField = manualOrderInputLabel("محصول", "manualOrderProduct" + index, "select", item.productId, { options: [] });
    manualOrderProductOptions(productField.input, item.productId);
    productField.input.addEventListener("change", function () {
      item.productId = productField.input.value;
      item.variationId = "";
      item.variations = [];
      item.variationsLoading = false;
      item.variationError = "";
      renderManualOrderStep();
    });
    row.appendChild(productField.label);
    var product = manualOrderProduct(item.productId);
    if (product && product.type === "variable") {
      var variationField = manualOrderInputLabel("تنوع محصول", "manualOrderVariation" + index, "select", item.variationId, { options: [] });
      manualOrderVariationOptions(variationField.input, item);
      variationField.input.disabled = item.variationsLoading || !item.variations.length;
      variationField.input.addEventListener("change", function () { item.variationId = variationField.input.value; });
      row.appendChild(variationField.label);
      if (!item.variationsLoading && !item.variations.length && !item.variationError) {
        loadManualOrderVariations(item, product.id);
      }
    } else {
      item.variationId = "";
      item.variations = [];
      item.variationsLoading = false;
      item.variationError = "";
    }
    var quantityField = manualOrderInputLabel("مقدار", "manualOrderQuantity" + index, "text", item.quantity || "1", { inputMode: "numeric", placeholder: "۱", maxLength: 6 });
    quantityField.input.addEventListener("input", function () { item.quantity = quantityField.input.value; });
    row.appendChild(quantityField.label);
    var priceField = manualOrderInputLabel("قیمت واحد اختیاری", "manualOrderUnitPrice" + index, "text", item.unitPrice || "", { inputMode: "decimal", placeholder: "قیمت ووکامرس", maxLength: 30, hint: "خالی بماند تا قیمت از سرور محاسبه شود." });
    priceField.input.addEventListener("input", function () { item.unitPrice = priceField.input.value; });
    row.appendChild(priceField.label);
    var remove = document.createElement("button");
    remove.type = "button";
    remove.className = "secondary-button compact-button manual-order-remove-item";
    remove.textContent = "حذف قلم";
    remove.addEventListener("click", function () {
      state.manualOrder.items.splice(index, 1);
      renderManualOrderStep();
    });
    row.appendChild(remove);
    return row;
  }

  function renderManualOrderItemsStep(container) {
    container.appendChild(manualOrderSectionHeading("مرحلهٔ دوم", "اقلام سفارش", "محصولات از فهرست بارگذاری‌شده انتخاب می‌شوند؛ مقدار و قیمت واحد اختیاری را کنترل کنید."));
    if (!userHasScope("products.read") || state.products.status === "error" || !state.products.items.length) {
      container.appendChild(manualOrderInfo("فهرست محصولات برای انتخاب آماده نیست. ابتدا دسترسی خواندن محصولات و اتصال API داخلی را بررسی کنید؛ تا آماده‌شدن فهرست، ثبت سفارش امکان‌پذیر نیست.", "warning"));
      var reloadProducts = document.createElement("button");
      reloadProducts.type = "button";
      reloadProducts.className = "secondary-button compact-button";
      reloadProducts.textContent = "بارگذاری دوبارهٔ محصولات";
      reloadProducts.addEventListener("click", function () {
        reloadProducts.disabled = true;
        Promise.resolve(loadProducts()).then(renderManualOrderStep).finally(function () { reloadProducts.disabled = false; });
      });
      container.appendChild(reloadProducts);
      return;
    }

    var items = document.createElement("div");
    items.className = "manual-order-items-list";
    if (!state.manualOrder.items.length) {
      items.appendChild(manualOrderInfo("هنوز قلمی به سفارش اضافه نشده است."));
    } else {
      state.manualOrder.items.forEach(function (item, index) {
        items.appendChild(renderManualOrderItemRow(item, index));
      });
    }
    container.appendChild(items);
    var add = document.createElement("button");
    add.type = "button";
    add.className = "secondary-button manual-order-add-item";
    add.textContent = "افزودن قلم";
    add.addEventListener("click", function () {
      state.manualOrder.items.push({ productId: String(state.products.items[0].id), variationId: "", variations: [], variationsLoading: false, variationError: "", variationRequestId: 0, quantity: "1", unitPrice: "" });
      renderManualOrderStep();
    });
    container.appendChild(add);
  }

  function renderManualOrderShippingStep(container) {
    container.appendChild(manualOrderSectionHeading("مرحلهٔ سوم", "ارسال و پرداخت", "شناسه‌های روش ارسال و پرداخت را در صورت نیاز وارد کنید؛ محاسبهٔ نهایی با ووکامرس انجام می‌شود."));
    var grid = document.createElement("div");
    grid.className = "product-editor-grid manual-order-form-grid";
    var shipping = manualOrderInputLabel("روش ارسال", "manualOrderShippingMethod", "text", state.manualOrder.shippingMethod, { placeholder: "مثلاً flat_rate", maxLength: 80, hint: "اختیاری؛ شناسهٔ روش داخلی ووکامرس." });
    shipping.input.addEventListener("input", function () { state.manualOrder.shippingMethod = shipping.input.value.trim(); });
    grid.appendChild(shipping.label);
    var payment = manualOrderInputLabel("روش پرداخت", "manualOrderPaymentMethod", "text", state.manualOrder.paymentMethod, { placeholder: "مثلاً cod", maxLength: 80, hint: "اختیاری؛ شناسهٔ درگاه داخلی ووکامرس." });
    payment.input.addEventListener("input", function () { state.manualOrder.paymentMethod = payment.input.value.trim(); });
    grid.appendChild(payment.label);
    var status = manualOrderInputLabel("وضعیت اولیه سفارش", "manualOrderStatus", "select", state.manualOrder.status, { options: [
      { value: "pending", label: "در انتظار پرداخت" },
      { value: "processing", label: "در حال پردازش" },
      { value: "on-hold", label: "در انتظار بررسی" }
    ] });
    status.input.addEventListener("change", function () { state.manualOrder.status = status.input.value; });
    grid.appendChild(status.label);
    var paid = manualOrderInputLabel("پرداخت تکمیل شده", "manualOrderPaymentComplete", "checkbox", state.manualOrder.paymentComplete, { hint: "فقط وقتی فعال کنید که پرداخت واقعاً انجام شده و نشست شما مجوز لازم دارد." });
    paid.input.className = "manual-order-checkbox";
    paid.input.addEventListener("change", function () { state.manualOrder.paymentComplete = paid.input.checked; });
    grid.appendChild(paid.label);
    container.appendChild(grid);
    container.appendChild(manualOrderInfo("قیمت، مالیات، تخفیف و موجودی باید در سمت سرور و با CRUD رسمی ووکامرس محاسبه و نهایی شوند."));
  }

  function manualOrderCustomerSummary() {
    if (state.manualOrder.customerType === "guest") return "مهمان";
    if (state.manualOrder.customerType === "existing") {
      var existing = state.customers.items.filter(function (customer) { return String(customer.id) === String(state.manualOrder.customerId); })[0];
      return existing ? existing.display : "مشتری ثبت‌شده انتخاب نشده";
    }
    return [state.manualOrder.customer.firstName, state.manualOrder.customer.lastName].filter(Boolean).join(" ") || "مشتری جدید";
  }

  function renderManualOrderReviewStep(container) {
    container.appendChild(manualOrderSectionHeading("مرحلهٔ چهارم", "بازبینی و ثبت", "اطلاعات را یک‌بار بررسی کنید؛ پس از ثبت، سفارش به API داخلی ووکامرس ارسال می‌شود."));
    var review = document.createElement("div");
    review.className = "manual-order-review-grid";
    [
      ["مشتری", manualOrderCustomerSummary()],
      ["تعداد اقلام", formatDisplayNumber(state.manualOrder.items.length)],
      ["ارسال", state.manualOrder.shippingMethod || "بدون تعیین"],
      ["پرداخت", state.manualOrder.paymentMethod || "بدون تعیین"],
      ["وضعیت", state.manualOrder.status === "processing" ? "در حال پردازش" : (state.manualOrder.status === "on-hold" ? "در انتظار بررسی" : "در انتظار پرداخت")],
      ["پرداخت تکمیل‌شده", state.manualOrder.paymentComplete ? "بله" : "خیر"]
    ].forEach(function (entry) {
      var item = document.createElement("div");
      item.className = "manual-order-review-item";
      item.appendChild(createTextElement("span", "manual-order-review-label", entry[0]));
      item.appendChild(createTextElement("strong", "", entry[1]));
      review.appendChild(item);
    });
    container.appendChild(review);
    var items = document.createElement("div");
    items.className = "manual-order-review-items";
    state.manualOrder.items.forEach(function (item) {
      var product = manualOrderProduct(item.productId);
      var line = document.createElement("div");
      line.className = "manual-order-review-line";
      line.appendChild(createTextElement("strong", "", product ? product.name : "محصول انتخاب‌شده"));
      var variation = product && product.type === "variable" ? item.variations.filter(function (candidate) { return String(candidate.id) === String(item.variationId); })[0] : null;
      var variationText = variation ? " · " + manualOrderVariationLabel(variation) : "";
      line.appendChild(createTextElement("span", "", "× " + formatDisplayNumber(toEnglishDigits(item.quantity || "0")) + variationText + (item.unitPrice ? " · " + formatDisplayAmount(item.unitPrice) : " · قیمت سرور")));
      items.appendChild(line);
    });
    container.appendChild(items);
    container.appendChild(manualOrderInfo("مبلغ نهایی در این مرحله از روی ورودی مرورگر حدس زده نمی‌شود؛ سرور باید مالیات، تخفیف، ارسال و موجودی را تعیین کند."));
  }

  function renderManualOrderStep() {
    if (!elements.manualOrderStepContent) return;
    while (elements.manualOrderStepContent.firstChild) elements.manualOrderStepContent.removeChild(elements.manualOrderStepContent.firstChild);
    var step = Math.max(0, Math.min(3, Number(state.manualOrder.step) || 0));
    state.manualOrder.step = step;
    if (step === 0) renderManualOrderCustomerStep(elements.manualOrderStepContent);
    if (step === 1) renderManualOrderItemsStep(elements.manualOrderStepContent);
    if (step === 2) renderManualOrderShippingStep(elements.manualOrderStepContent);
    if (step === 3) renderManualOrderReviewStep(elements.manualOrderStepContent);
    Array.prototype.forEach.call(document.querySelectorAll(".manual-order-step-button"), function (button) {
      var index = Number(button.getAttribute("data-manual-order-step-index"));
      var active = index === step;
      button.classList.toggle("is-active", active);
      button.classList.toggle("is-complete", index < step);
      button.disabled = index > step || state.manualOrder.busy;
      if (active) button.setAttribute("aria-current", "step"); else button.removeAttribute("aria-current");
    });
    if (elements.manualOrderStepStatus) elements.manualOrderStepStatus.textContent = "مرحلهٔ " + formatDisplayNumber(step + 1) + " از ۴";
    if (elements.manualOrderPrevious) {
      elements.manualOrderPrevious.hidden = step === 0;
      elements.manualOrderPrevious.disabled = state.manualOrder.busy;
    }
    if (elements.manualOrderNext) {
      elements.manualOrderNext.hidden = step === 3;
      elements.manualOrderNext.disabled = state.manualOrder.busy;
    }
    if (elements.submitManualOrder) {
      elements.submitManualOrder.hidden = step !== 3;
      elements.submitManualOrder.disabled = state.manualOrder.busy;
    }
    if (elements.manualOrderDialog) elements.manualOrderDialog.setAttribute("aria-busy", String(state.manualOrder.busy));
  }

  function validateManualOrderStep(step) {
    if (step === 0) {
      if (state.manualOrder.customerType === "existing" && !state.manualOrder.customerId) {
        setManualOrderMessage("یک مشتری ثبت‌شده انتخاب کنید یا نوع مشتری را تغییر دهید.", "error");
        return false;
      }
      if (state.manualOrder.customerType === "new" && !(state.manualOrder.customer.firstName || state.manualOrder.customer.lastName)) {
        setManualOrderMessage("برای مشتری جدید حداقل نام یا نام خانوادگی را وارد کنید.", "error");
        return false;
      }
      if (state.manualOrder.customerType === "new" && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(state.manualOrder.customer.email || "").trim())) {
        setManualOrderMessage("برای مشتری جدید یک ایمیل معتبر وارد کنید.", "error");
        return false;
      }
    }
    if (step === 1) {
      if (!state.manualOrder.items.length) {
        setManualOrderMessage("حداقل یک قلم به سفارش اضافه کنید.", "error");
        return false;
      }
      var invalidItem = state.manualOrder.items.some(function (item) {
        var quantity = Number(toEnglishDigits(String(item.quantity || "")).replace(/[٬,\s]/g, ""));
        var product = manualOrderProduct(item.productId);
        var variationSelected = item.variationId && item.variations.some(function (variation) { return String(variation.id) === String(item.variationId); });
        return !/^\d+$/.test(String(item.productId || "")) || !product || !isFinite(quantity) || quantity < 1 || quantity > 999 || (product.type === "variable" && !variationSelected);
      });
      if (invalidItem) {
        setManualOrderMessage("محصول، تنوع و مقدار هر قلم را بررسی کنید؛ مقدار باید بین ۱ تا ۹۹۹ باشد.", "error");
        return false;
      }
    }
    setManualOrderMessage("");
    return true;
  }

  function openManualOrder() {
    if (!state.authenticated || !userHasScope("orders.create")) return;
    state.manualOrder.step = 0;
    state.manualOrder.busy = false;
    state.manualOrder.trigger = elements.newOrderButton;
    state.manualOrder.items = [];
    state.manualOrder.customerType = "guest";
    state.manualOrder.customerId = "";
    state.manualOrder.customer = { firstName: "", lastName: "", email: "", phone: "" };
    state.manualOrder.shippingMethod = "";
    state.manualOrder.paymentMethod = "";
    state.manualOrder.status = "pending";
    state.manualOrder.paymentComplete = false;
    setManualOrderMessage("");
    elements.manualOrderOverlay.hidden = false;
    document.body.classList.add("manual-order-open");
    renderManualOrderStep();
    if (userHasScope("products.read") && !state.products.items.length) loadProducts().finally(renderManualOrderStep);
    if (userHasScope("customers.read") && !state.customers.items.length) loadCustomers(1).finally(renderManualOrderStep);
    window.setTimeout(function () { if (elements.closeManualOrder) elements.closeManualOrder.focus(); }, 0);
  }

  function closeManualOrder(force) {
    if ((state.manualOrder.busy && force !== true) || !elements.manualOrderOverlay) return;
    elements.manualOrderOverlay.hidden = true;
    document.body.classList.remove("manual-order-open");
    setManualOrderMessage("");
    if (state.manualOrder.trigger && typeof state.manualOrder.trigger.focus === "function") state.manualOrder.trigger.focus();
    state.manualOrder.trigger = null;
  }

  function manualOrderPayload() {
    var body = {
      customer_type: state.manualOrder.customerType === "existing" ? "registered" : state.manualOrder.customerType,
      line_items: state.manualOrder.items.map(function (item) {
        var line = { product_id: Number(item.productId), quantity: Number(toEnglishDigits(String(item.quantity || "1")).replace(/[٬,\s]/g, "")) };
        if (item.variationId) line.variation_id = Number(item.variationId);
        var price = normalizeNumericInput(item.unitPrice || "");
        if (price) line.unit_price = price;
        return line;
      }),
      payment_method: state.manualOrder.paymentMethod,
      status: state.manualOrder.status,
      payment_complete: Boolean(state.manualOrder.paymentComplete),
      idempotency_key: "manual-" + makeEphemeralDeviceId()
    };
    if (state.manualOrder.customerType === "existing") {
      body.customer_id = Number(state.manualOrder.customerId);
    }
    if (state.manualOrder.customerType === "new") {
      body.billing = {
        first_name: state.manualOrder.customer.firstName,
        last_name: state.manualOrder.customer.lastName,
        email: state.manualOrder.customer.email,
        phone: state.manualOrder.customer.phone
      };
      body.shipping = { first_name: state.manualOrder.customer.firstName, last_name: state.manualOrder.customer.lastName };
    }
    if (state.manualOrder.shippingMethod) {
      body.shipping_lines = [{ method_id: state.manualOrder.shippingMethod, method_title: state.manualOrder.shippingMethod, total: "0" }];
    }
    return body;
  }

  function submitManualOrder(event) {
    if (event) event.preventDefault();
    if (state.manualOrder.busy || !state.authenticated || !state.csrfToken || !userHasScope("orders.create")) return;
    for (var step = 0; step < 3; step += 1) {
      if (!validateManualOrderStep(step)) {
        state.manualOrder.step = step;
        renderManualOrderStep();
        return;
      }
    }
    var url = apiUrl("ordersUrl");
    if (!url) {
      setManualOrderMessage("نشانی API سفارش‌ها در پیکربندی سایت موجود نیست.", "error");
      return;
    }
    state.manualOrder.busy = true;
    setManualOrderMessage("در حال ثبت امن سفارش...", "loading");
    renderManualOrderStep();
    fetchJsonWithTimeout(url, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-Fandoogh-CSRF": state.csrfToken },
      body: JSON.stringify(manualOrderPayload())
    }).then(function () {
      setManualOrderMessage("سفارش با موفقیت ثبت شد؛ فهرست سفارش‌ها در حال تازه‌سازی است.", "success");
      return loadOrders(1).then(function () {
        closeManualOrder(true);
      });
    }).catch(function (error) {
      if (error && error.status === 401) {
        closeManualOrder(true);
        setAuthenticated(false, "");
        showView("pairing");
        showPairingMessage("نشست منقضی شده است؛ دوباره جفت‌سازی کنید.");
        return;
      }
      var message = error && error.status === 409 && !(error.payload && typeof error.payload.message === "string") ? "این درخواست تکراری است یا سفارش مشابه هم‌زمان در حال ثبت است؛ وضعیت سفارش‌ها را بررسی کنید." : responseErrorMessage(error, "ثبت سفارش انجام نشد؛ اطلاعات سفارش را بررسی و دوباره تلاش کنید.");
      setManualOrderMessage(message, "error");
    }).finally(function () {
      state.manualOrder.busy = false;
      renderManualOrderStep();
    });
  }

  function scheduleOrderSearch() {
    state.orders.filters.search = orderInputValue(elements.orderSearch);
    state.orders.page = 1;
    if (state.orders.searchTimer) window.clearTimeout(state.orders.searchTimer);
    renderOrderResults();
    var query = normalizeSearchText(state.orders.filters.search);
    if (query && query.length < 2) {
      state.orders.searchTimer = null;
      return;
    }
    state.orders.searchTimer = window.setTimeout(function () {
      state.orders.searchTimer = null;
      loadOrders(1);
    }, 350);
  }

  function orderResourceUrl(orderId) {
    var base = apiUrl("ordersUrl");
    orderId = String(orderId || "");
    if (!base || !/^\d+$/.test(orderId) || orderId === "0") {
      return "";
    }
    try {
      return new URL(orderId + "/", base.replace(/\/?$/, "/")).toString();
    } catch (error) {
      return "";
    }
  }

  function isShipmentStatus(status) {
    return typeof status === "string" && Object.prototype.hasOwnProperty.call(SHIPMENT_STATUS_LABELS, status);
  }

  function shipmentStatusLabel(status, fallback) {
    if (isShipmentStatus(status)) {
      return SHIPMENT_STATUS_LABELS[status];
    }

    return safeText(fallback, "نامشخص", 100);
  }

  function normalizeShipment(payload) {
    var source = payload && payload.data && typeof payload.data === "object" ? payload.data : payload;
    source = source && typeof source === "object" ? source : {};
    var rawStatus = safeText(source.status, "pending", 40);
    var status = isShipmentStatus(rawStatus) ? rawStatus : "pending";

    return {
      orderId: safeText(String(source.order_id || state.orders.detailId || ""), "", 40),
      status: status,
      statusLabel: shipmentStatusLabel(status, source.status_label),
      shippingMethodId: safeText(source.shipping_method_id, "", 100),
      shippingMethodTitle: safeText(source.shipping_method_title, "", 255),
      trackingCode: safeText(source.tracking_code, "", 100),
      carrierId: safeText(source.carrier_id || source.carrierId, "", 32).toLowerCase(),
      carrierLabel: safeText(source.carrier_label, "", 80),
      trackingUrl: safeExternalHttpsUrl(source.tracking_url || source.trackingUrl),
      shippedAt: safeText(source.shipped_at, "", 80),
      estimatedDeliveryDate: safeText(source.estimated_delivery_date, "", 80),
      deliveredAt: safeText(source.delivered_at, "", 80),
      note: safeText(source.note, "", 500),
      updatedAt: safeText(source.updated_at, "", 80),
      items: Array.isArray(source.items) ? source.items.map(function (item) {
        var row = item && typeof item === "object" ? item : {};
        return {
          itemId: safeText(String(row.item_id || row.id || ""), "", 40),
          quantity: Math.max(0, Number(row.quantity) || 0)
        };
      }).filter(function (item) { return item.itemId; }) : [],
      hasTracking: source.has_tracking === true || source.has_tracking === 1 || source.has_tracking === "1" || source.has_tracking === "true"
    };
  }

  function appendShipmentSummaryItem(parent, label, value, extraClass) {
    var item = document.createElement("div");
    item.className = "shipment-summary-item";
    if (extraClass === "shipment-summary-wide") {
      item.className += " shipment-summary-wide";
    }
    item.appendChild(createTextElement("span", "shipment-summary-label", label));
    item.appendChild(createTextElement("span", "shipment-summary-value" + (extraClass && extraClass !== "shipment-summary-wide" ? " " + extraClass : ""), value || "—"));
    parent.appendChild(item);
  }

  function createShipmentField(labelText, control) {
    var label = document.createElement("label");
    label.className = "field-label";
    label.appendChild(createTextElement("span", "shipment-field-label", labelText));
    label.appendChild(control);
    if (control && control.dataset && control.dataset.persianDateType) {
      setupPersianDateInput(control);
    }
    return label;
  }

  function createShipmentInput(type, value, maxLength, placeholder) {
    var input = document.createElement("input");
    input.type = type;
    input.value = value || "";
    input.maxLength = maxLength;
    input.autocomplete = "off";
    if (type === "date" || type === "datetime-local") {
      input.dataset.persianDateType = type;
      input.dataset.persianDatePicker = "1";
      input.lang = "fa-IR";
    }
    if (placeholder) {
      input.placeholder = placeholder;
    }
    return input;
  }

  function shipmentDateTimeInputValue(value) {
    if (!value) {
      return "";
    }
    try {
      var date = new Date(String(value));
      if (!isNaN(date.getTime())) {
        var pad = function (part) {
          return ("0" + part).slice(-2);
        };
        return date.getFullYear() + "-" + pad(date.getMonth() + 1) + "-" + pad(date.getDate()) + "T" + pad(date.getHours()) + ":" + pad(date.getMinutes());
      }
    } catch (error) {
      // Keep an empty control when an old or malformed snapshot is encountered.
    }
    return "";
  }

  function shipmentDateTimeRequestValue(input) {
    var value = getPersianDateInputValue(input);
    if (!value) {
      return "";
    }
    try {
      var date = new Date(value);
      if (!isNaN(date.getTime())) {
        return date.toISOString();
      }
    } catch (error) {
      // The server will return the contract error for an invalid value.
    }
    return value;
  }

  function renderOrderShipmentLoading() {
    if (!elements.orderShipmentContainer) {
      return;
    }

    elements.orderShipmentContainer.textContent = "";
    var section = document.createElement("section");
    section.className = "order-shipment-section order-detail-block";
    section.setAttribute("aria-labelledby", "orderShipmentTitle");
    var loadingTitle = createTextElement("h4", "order-detail-block-title", "ارسال و رهگیری");
    loadingTitle.id = "orderShipmentTitle";
    section.appendChild(loadingTitle);

    var loadingState = document.createElement("div");
    loadingState.className = "products-state shipment-inline-state";
    loadingState.setAttribute("role", "status");
    loadingState.appendChild(createTextElement("span", "products-state-icon products-spinner", ""));
    var loadingCopy = document.createElement("div");
    loadingCopy.appendChild(createTextElement("strong", "", "در حال دریافت اطلاعات ارسال"));
    loadingCopy.appendChild(createTextElement("p", "", "وضعیت، کد رهگیری و تاریخ‌های ارسال از API داخلی دریافت می‌شود."));
    loadingState.appendChild(loadingCopy);
    section.appendChild(loadingState);
    elements.orderShipmentContainer.appendChild(section);
  }

  function renderOrderShipmentError(message) {
    if (!elements.orderShipmentContainer) {
      return;
    }

    elements.orderShipmentContainer.textContent = "";
    var section = document.createElement("section");
    section.className = "order-shipment-section order-detail-block";
    section.setAttribute("aria-labelledby", "orderShipmentTitle");
    var errorTitle = createTextElement("h4", "order-detail-block-title", "ارسال و رهگیری");
    errorTitle.id = "orderShipmentTitle";
    section.appendChild(errorTitle);

    var errorState = document.createElement("div");
    errorState.className = "products-state products-error-state shipment-inline-state";
    errorState.setAttribute("role", "alert");
    errorState.appendChild(createTextElement("span", "products-state-icon", "!"));
    var errorCopy = document.createElement("div");
    errorCopy.appendChild(createTextElement("strong", "", "دریافت اطلاعات ارسال ناموفق بود"));
    errorCopy.appendChild(createTextElement("p", "", message));
    errorState.appendChild(errorCopy);
    section.appendChild(errorState);
    elements.orderShipmentContainer.appendChild(section);
  }

  function copyShipmentTrackingCode(value) {
    if (navigator.clipboard && typeof navigator.clipboard.writeText === "function") {
      return navigator.clipboard.writeText(value);
    }

    var area = document.createElement("textarea");
    area.value = value;
    area.setAttribute("readonly", "");
    area.style.position = "fixed";
    area.style.opacity = "0";
    document.body.appendChild(area);
    area.select();
    var copied = false;
    try {
      copied = document.execCommand("copy");
    } catch (error) {
      copied = false;
    }
    document.body.removeChild(area);
    return copied ? Promise.resolve() : Promise.reject(new Error("copy_failed"));
  }

  function appendShipmentTrackingActions(parent, shipment) {
    if (!shipment.trackingCode) {
      return;
    }

    var actions = document.createElement("div");
    actions.className = "shipment-tracking-actions";
    var copyButton = document.createElement("button");
    copyButton.type = "button";
    copyButton.className = "secondary-button compact-button shipment-tracking-copy";
    copyButton.textContent = "کپی کد رهگیری";
    copyButton.addEventListener("click", function () {
      var original = copyButton.textContent;
      copyShipmentTrackingCode(shipment.trackingCode).then(function () {
        copyButton.textContent = "کپی شد";
        window.setTimeout(function () {
          copyButton.textContent = original;
        }, 1800);
      }).catch(function () {
        copyButton.textContent = "کپی نشد";
        window.setTimeout(function () {
          copyButton.textContent = original;
        }, 2200);
      });
    });
    actions.appendChild(copyButton);

    if (shipment.trackingUrl) {
      var trackingLink = document.createElement("a");
      trackingLink.className = "primary-button compact-button shipment-tracking-link";
      trackingLink.href = shipment.trackingUrl;
      trackingLink.target = "_blank";
      trackingLink.rel = "noopener noreferrer";
      trackingLink.textContent = "باز کردن صفحهٔ پیگیری";
      actions.appendChild(trackingLink);
    }

    parent.appendChild(actions);
  }

  function renderOrderShipment(shipment, notice) {
    if (!elements.orderShipmentContainer) {
      return;
    }

    var orderId = safeText(String(state.orders.detailId || shipment.orderId || ""), "", 40);
    elements.orderShipmentContainer.textContent = "";

    var section = document.createElement("section");
    section.className = "order-shipment-section order-detail-block";
    section.setAttribute("aria-labelledby", "orderShipmentTitle");

    var heading = document.createElement("div");
    heading.className = "product-editor-heading shipment-heading";
    var headingCopy = document.createElement("div");
    headingCopy.appendChild(createTextElement("p", "card-kicker", "رهگیری سفارش"));
    var shipmentTitle = createTextElement("h4", "order-shipment-title", "ارسال و رهگیری");
    shipmentTitle.id = "orderShipmentTitle";
    headingCopy.appendChild(shipmentTitle);
    heading.appendChild(headingCopy);
    var shipmentStatusBadge = createTextElement("span", "shipment-status-badge", shipment.statusLabel);
    shipmentStatusBadge.dataset.status = orderStatusKey(shipment.status);
    heading.appendChild(shipmentStatusBadge);
    section.appendChild(heading);

    var summary = document.createElement("div");
    summary.className = "shipment-summary-grid";
    appendShipmentSummaryItem(summary, "وضعیت فعلی", shipment.statusLabel, "shipment-summary-status");
    appendShipmentSummaryItem(summary, "روش ارسال ووکامرس", shipment.shippingMethodTitle || "روش ثبت‌شده هنگام خرید");
    appendShipmentSummaryItem(summary, "شرکت حمل", shipment.carrierLabel || "ثبت نشده است");
    appendShipmentSummaryItem(summary, "کد رهگیری", shipment.trackingCode || "ثبت نشده است", "shipment-summary-ltr");
    appendShipmentSummaryItem(summary, "رهگیری", shipment.trackingUrl ? "پیوند پیگیری آماده است" : (shipment.hasTracking ? "کد ثبت شده است" : "کدی ثبت نشده است"));
    appendShipmentSummaryItem(summary, "زمان ارسال", shipment.shippedAt ? formatDisplayDate(shipment.shippedAt, true) : "ثبت نشده است");
    appendShipmentSummaryItem(summary, "تاریخ تحویل تخمینی", shipment.estimatedDeliveryDate ? formatDisplayDate(shipment.estimatedDeliveryDate, false) : "ثبت نشده است");
    appendShipmentSummaryItem(summary, "زمان تحویل", shipment.deliveredAt ? formatDisplayDate(shipment.deliveredAt, true) : "ثبت نشده است");
    appendShipmentSummaryItem(summary, "آخرین به‌روزرسانی", shipment.updatedAt ? formatDisplayDate(shipment.updatedAt, true) : "ثبت نشده است");
    appendShipmentSummaryItem(summary, "توضیح", shipment.note || "توضیحی ثبت نشده است", "shipment-summary-wide");
    section.appendChild(summary);
    appendShipmentTrackingActions(section, shipment);

    if (notice) {
      var successNote = createTextElement("p", "shipment-success-note", notice);
      successNote.setAttribute("role", "status");
      section.appendChild(successNote);
    }

    var form = document.createElement("form");
    form.className = "shipment-form";
    form.setAttribute("aria-label", "فرم اطلاعات ارسال");

    var formGrid = document.createElement("div");
    formGrid.className = "shipment-form-grid";

    var detailSource = state.orders.detail && typeof state.orders.detail === "object" ? state.orders.detail : {};
    var orderShippingLines = Array.isArray(detailSource.shipping_lines) ? detailSource.shipping_lines : [];
    var selectedShippingMethodId = shipment.shippingMethodId || (orderShippingLines[0] && (orderShippingLines[0].method_id || orderShippingLines[0].method_title)) || "";
    var selectedShippingMethodTitle = shipment.shippingMethodTitle || (orderShippingLines[0] && (orderShippingLines[0].method_title || orderShippingLines[0].method_id)) || "";
    var shippingMethodSelect = document.createElement("select");
    shippingMethodSelect.name = "shipping_method";
    if (orderShippingLines.length) {
      orderShippingLines.forEach(function (line) {
        var methodValue = safeText(line && (line.method_id || line.method_title), "", 100);
        if (!methodValue) return;
        var option = document.createElement("option");
        option.value = methodValue;
        option.textContent = safeText(line && (line.method_title || line.method_id), "روش ارسال", 180);
        option.dataset.methodTitle = option.textContent;
        option.selected = methodValue === selectedShippingMethodId;
        shippingMethodSelect.appendChild(option);
      });
    }
    if (!shippingMethodSelect.options.length) {
      var noMethodOption = document.createElement("option");
      noMethodOption.value = "";
      noMethodOption.textContent = "روش ارسال ثبت نشده است";
      shippingMethodSelect.appendChild(noMethodOption);
    }
    var shippingMethodIdInput = document.createElement("input");
    shippingMethodIdInput.type = "hidden";
    shippingMethodIdInput.value = selectedShippingMethodId;
    var shippingMethodTitleInput = document.createElement("input");
    shippingMethodTitleInput.type = "hidden";
    shippingMethodTitleInput.value = selectedShippingMethodTitle;
    shippingMethodSelect.addEventListener("change", function () {
      var selectedOption = shippingMethodSelect.options[shippingMethodSelect.selectedIndex];
      shippingMethodIdInput.value = shippingMethodSelect.value;
      shippingMethodTitleInput.value = selectedOption ? selectedOption.dataset.methodTitle || selectedOption.textContent : "";
    });
    formGrid.appendChild(createShipmentField("روش ارسال ووکامرس", shippingMethodSelect));

    var statusSelect = document.createElement("select");
    statusSelect.name = "status";
    statusSelect.required = true;
    Object.keys(SHIPMENT_STATUS_LABELS).forEach(function (statusKey) {
      var option = document.createElement("option");
      option.value = statusKey;
      option.textContent = SHIPMENT_STATUS_LABELS[statusKey];
      option.selected = statusKey === shipment.status;
      statusSelect.appendChild(option);
    });
    formGrid.appendChild(createShipmentField("وضعیت ارسال", statusSelect));

    var matchedProvider = trackingProviderForId(shipment.carrierId) || trackingProviderForLabel(shipment.carrierLabel);
    var carrierSelect = document.createElement("select");
    carrierSelect.name = "carrier_id";
    var customCarrierOption = document.createElement("option");
    customCarrierOption.value = "";
    customCarrierOption.textContent = "شرکت دیگر یا انتخاب‌نشده";
    carrierSelect.appendChild(customCarrierOption);
    trackingProviders().forEach(function (provider) {
      var option = document.createElement("option");
      option.value = provider.id;
      option.textContent = provider.label;
      option.selected = Boolean(matchedProvider && matchedProvider.id === provider.id);
      carrierSelect.appendChild(option);
    });
    formGrid.appendChild(createShipmentField("شرکت حمل", carrierSelect));

    var carrierInput = createShipmentInput("text", shipment.carrierLabel, 80, "مثلاً شرکت حمل محلی");
    carrierInput.name = "carrier_label";
    function syncCarrierLabel() {
      var provider = trackingProviderForId(carrierSelect.value);
      if (provider) {
        carrierInput.value = provider.label;
        carrierInput.readOnly = true;
        carrierInput.setAttribute("aria-readonly", "true");
      } else {
        carrierInput.readOnly = false;
        carrierInput.removeAttribute("aria-readonly");
      }
    }
    carrierSelect.addEventListener("change", syncCarrierLabel);
    syncCarrierLabel();
    formGrid.appendChild(createShipmentField("نام شرکت حمل (دلخواه)", carrierInput));

    var trackingInput = createShipmentInput("text", shipment.trackingCode, 100, "کد رهگیری متنی");
    trackingInput.name = "tracking_code";
    trackingInput.dir = "ltr";
    trackingInput.inputMode = "text";
    formGrid.appendChild(createShipmentField("کد رهگیری", trackingInput));

    var shippedAtInput = createShipmentInput("datetime-local", shipmentDateTimeInputValue(shipment.shippedAt), 80, "");
    shippedAtInput.name = "shipped_at";
    shippedAtInput.dir = "ltr";
    formGrid.appendChild(createShipmentField("زمان ارسال", shippedAtInput));

    var estimatedDateInput = createShipmentInput("date", shipment.estimatedDeliveryDate, 80, "");
    estimatedDateInput.name = "estimated_delivery_date";
    estimatedDateInput.dir = "ltr";
    formGrid.appendChild(createShipmentField("تاریخ تحویل تخمینی", estimatedDateInput));

    var deliveredAtInput = createShipmentInput("datetime-local", shipmentDateTimeInputValue(shipment.deliveredAt), 80, "");
    deliveredAtInput.name = "delivered_at";
    deliveredAtInput.dir = "ltr";
    formGrid.appendChild(createShipmentField("زمان تحویل", deliveredAtInput));

    form.appendChild(formGrid);
    var orderItems = Array.isArray(detailSource.line_items) ? detailSource.line_items : [];
    if (orderItems.length) {
      var itemsFieldset = document.createElement("fieldset");
      itemsFieldset.className = "shipment-items-fieldset";
      itemsFieldset.appendChild(createTextElement("legend", "shipment-items-title", "اقلام ارسال‌شده در این مرحله"));
      itemsFieldset.appendChild(createTextElement("p", "shipment-items-help", "برای ارسال ناقص، تعداد هر قلم را وارد کنید؛ مقدار صفر یعنی هنوز ارسال نشده است."));
      var savedItems = shipment.items || [];
      orderItems.forEach(function (item) {
        var itemId = String(item && item.id || "");
        var maxQuantity = Math.max(0, Number(item && item.quantity) || 0);
        if (!/^\d+$/.test(itemId) || maxQuantity < 1) {
          return;
        }
        var saved = savedItems.filter(function (row) { return row.itemId === itemId; })[0];
        var row = document.createElement("label");
        row.className = "shipment-item-row";
        row.appendChild(createTextElement("span", "shipment-item-name", safeText(item.name, "محصول بدون نام", 200) + " · حداکثر " + formatDisplayNumber(maxQuantity)));
        var quantityInput = document.createElement("input");
        quantityInput.type = "number";
        quantityInput.min = "0";
        quantityInput.max = String(maxQuantity);
        quantityInput.step = "1";
        quantityInput.inputMode = "numeric";
        quantityInput.value = formatNumericInput(saved ? saved.quantity : 0);
        quantityInput.dataset.shipmentItemId = itemId;
        quantityInput.setAttribute("aria-label", "تعداد ارسال " + safeText(item.name, "قلم سفارش", 100));
        row.appendChild(quantityInput);
        itemsFieldset.appendChild(row);
      });
      form.appendChild(itemsFieldset);
    }

    var noteInput = document.createElement("textarea");
    noteInput.name = "note";
    noteInput.maxLength = 500;
    noteInput.rows = 3;
    noteInput.placeholder = "توضیح داخلی دربارهٔ ارسال";
    noteInput.value = shipment.note || "";
    form.appendChild(createShipmentField("توضیح", noteInput));
    form.appendChild(shippingMethodIdInput);
    form.appendChild(shippingMethodTitleInput);

    var actions = document.createElement("div");
    actions.className = "shipment-form-actions";
    var saveButton = document.createElement("button");
    saveButton.type = "submit";
    saveButton.className = "primary-button compact-button";
    saveButton.textContent = "ذخیرهٔ اطلاعات ارسال";
    actions.appendChild(saveButton);

    var formMessage = createTextElement("span", "secure-note shipment-form-message", "");
    formMessage.setAttribute("role", "status");
    formMessage.hidden = true;
    actions.appendChild(formMessage);
    form.appendChild(actions);

    form.addEventListener("submit", function (event) {
      event.preventDefault();
      var savePromise = saveOrderShipment(orderId, {
        status: statusSelect,
        shippingMethodId: shippingMethodIdInput,
        shippingMethodTitle: shippingMethodTitleInput,
        carrierId: carrierSelect,
        carrierLabel: carrierInput,
        trackingCode: trackingInput,
        shippedAt: shippedAtInput,
        estimatedDeliveryDate: estimatedDateInput,
        deliveredAt: deliveredAtInput,
        note: noteInput,
        items: Array.prototype.slice.call(form.querySelectorAll("[data-shipment-item-id]")).map(function (input) {
          return { itemId: input.dataset.shipmentItemId, quantity: normalizeNumericInput(input.value) || "0" };
        })
      }, saveButton, formMessage);
      if (elements.orderEditPanel && !elements.orderEditPanel.hidden) {
        savePromise.then(function (saved) {
          setOrderEditMessage(saved ? "اطلاعات ارسال ذخیره شد." : "ذخیرهٔ اطلاعات ارسال انجام نشد.");
        });
      }
    });

    section.appendChild(form);
    elements.orderShipmentContainer.appendChild(section);
  }

  function shipmentErrorMessage(error, fallback) {
    if (error && error.payload && typeof error.payload.message === "string") {
      return safeText(error.payload.message, fallback, 240);
    }
    if (error && error.status === 403) {
      return "این نشست مجوز مشاهده یا مدیریت رهگیری این سفارش را ندارد.";
    }

    if (error && error.status === 422) {
      return "اطلاعات رهگیری معتبر نیست؛ وضعیت و قالب تاریخ‌ها را بررسی کنید.";
    }

    return responseErrorMessage(error, fallback);
  }

  function orderShipmentResourceUrl(orderId) {
    var base = apiUrl("ordersUrl");
    orderId = String(orderId || "");
    if (!base || !/^\d+$/.test(orderId) || orderId === "0") {
      return "";
    }

    try {
      return new URL(orderId + "/shipment/", base.replace(/\/?$/, "/")).toString();
    } catch (error) {
      return "";
    }
  }

  function loadOrderShipment(orderId, notice) {
    var normalizedOrderId = String(orderId || "");
    if (!state.authenticated || !normalizedOrderId || !elements.orderShipmentContainer) {
      return Promise.resolve();
    }

    var url = orderShipmentResourceUrl(normalizedOrderId);
    state.orders.shipmentStatus = "loading";
    renderOrderShipmentLoading();
    if (!url) {
      state.orders.shipmentStatus = "error";
      renderOrderShipmentError("نشانی API رهگیری سفارش در پیکربندی سایت موجود نیست.");
      return Promise.resolve();
    }

    return fetchJsonWithTimeout(url).then(function (payload) {
      if (state.orders.detailId !== normalizedOrderId) {
        return;
      }
      state.orders.shipment = normalizeShipment(payload);
      state.orders.shipmentStatus = "ready";
      renderOrderShipment(state.orders.shipment, notice || "");
      if (elements.orderEditPanel && !elements.orderEditPanel.hidden) {
        renderOrderEditTab(state.orders.editTab || "status");
      } else if (elements.orderDetailPanel && !elements.orderDetailPanel.hidden && state.orders.detail) {
        renderOrderViewTab(state.orders.detail, state.orders.viewTab || "overview");
      }
    }).catch(function (error) {
      if (state.orders.detailId !== normalizedOrderId) {
        return;
      }
      state.orders.shipment = null;
      state.orders.shipmentStatus = "error";
      if (error && error.status === 401) {
        setAuthenticated(false, "");
        showView("pairing");
        showPairingMessage("نشست منقضی شده است؛ دوباره جفت‌سازی کنید.");
        return;
      }
      renderOrderShipmentError(shipmentErrorMessage(error, "اطلاعات ارسال این سفارش دریافت نشد."));
      if (elements.orderEditPanel && !elements.orderEditPanel.hidden && state.orders.editTab === "shipping") {
        renderOrderEditTab("shipping");
      }
    });
  }

  function saveOrderShipment(orderId, fields, button, message) {
    if (!state.authenticated || !state.csrfToken) {
      message.textContent = "نشست امن معتبر نیست؛ دوباره جفت‌سازی کنید.";
      message.hidden = false;
      return Promise.resolve(false);
    }

    var url = orderShipmentResourceUrl(orderId);
    var status = fields.status.value;
    if (!url || !isShipmentStatus(status)) {
      message.textContent = "وضعیت ارسال معتبر نیست یا نشانی API رهگیری موجود نیست.";
      message.hidden = false;
      return Promise.resolve(false);
    }

    var body = {
      status: status,
      shipping_method_id: fields.shippingMethodId && fields.shippingMethodId.value ? fields.shippingMethodId.value.trim() : "",
      shipping_method_title: fields.shippingMethodTitle && fields.shippingMethodTitle.value ? fields.shippingMethodTitle.value.trim() : "",
      tracking_code: fields.trackingCode.value.trim(),
      carrier_label: fields.carrierLabel.value.trim(),
      shipped_at: shipmentDateTimeRequestValue(fields.shippedAt),
      estimated_delivery_date: getPersianDateInputValue(fields.estimatedDeliveryDate),
      delivered_at: shipmentDateTimeRequestValue(fields.deliveredAt),
      note: fields.note.value.trim()
    };

    if (fields.carrierId) {
      body.carrier_id = fields.carrierId.value ? fields.carrierId.value.trim() : "";
    }

    if (Array.isArray(fields.items)) {
      body.items = fields.items.map(function (item) {
        return {
          item_id: Number(item.itemId),
          quantity: Number(normalizeNumericInput(item.quantity) || 0)
        };
      }).filter(function (item) {
        return item.item_id > 0 && item.quantity > 0;
      });
    }

    button.disabled = true;
    message.textContent = "در حال ذخیرهٔ اطلاعات ارسال...";
    message.hidden = false;
    return fetchJsonWithTimeout(url, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-Fandoogh-CSRF": state.csrfToken
      },
      body: JSON.stringify(body)
    }).then(function () {
      return loadOrderShipment(orderId, "اطلاعات ارسال ذخیره و دوباره دریافت شد.").then(function () {
        return true;
      });
    }).catch(function (error) {
      if (error && error.status === 401) {
        setAuthenticated(false, "");
        showView("pairing");
        showPairingMessage("نشست منقضی شده است؛ دوباره جفت‌سازی کنید.");
        return;
      }
      message.textContent = formatDisplayText(shipmentErrorMessage(error, "ذخیرهٔ اطلاعات ارسال انجام نشد؛ ورودی‌ها را بررسی کنید."));
      message.hidden = false;
      return false;
    }).finally(function () {
      button.disabled = false;
    });
  }

  function appendOrderAddress(parent, title, address, stepKey) {
    var section = document.createElement("section");
    section.className = "order-detail-block";
    section.dataset.orderDetailStep = stepKey || "customer";
    section.appendChild(createTextElement("h4", "order-detail-block-title", title));
    var fields = [
      ["نام", [address.first_name, address.last_name].filter(Boolean).join(" ")],
      ["شرکت", address.company],
      ["نشانی", [address.address_1, address.address_2].filter(Boolean).join("، ")],
      ["شهر/استان", [address.city, address.state].filter(Boolean).join("، ")],
      ["کدپستی", address.postcode],
      ["کشور", address.country],
      ["تلفن", address.phone],
      ["ایمیل", address.email]
    ];
    fields.forEach(function (field) {
      var value = safeText(String(field[1] || ""), "—", 300);
      if (value === "—" && field[0] === "نام") {
        value = "مهمان";
      }
      section.appendChild(createTextElement("p", "order-detail-line", field[0] + ": " + value));
    });
    parent.appendChild(section);
  }

  function orderPaymentStatusLabel(status) {
    var labels = {
      paid: "پرداخت شده",
      unpaid: "پرداخت نشده",
      refunded: "مرجوع‌شده"
    };
    return labels[String(status || "")] || "نامشخص";
  }

  function appendOrderSummarySection(parent, title, lines, stepKey) {
    var section = document.createElement("section");
    section.className = "order-detail-block";
    section.dataset.orderDetailStep = stepKey || "summary";
    section.appendChild(createTextElement("h4", "order-detail-block-title", title));
    lines.forEach(function (line) {
      if (!line || !line[1]) {
        return;
      }
      section.appendChild(createTextElement("p", "order-detail-line", line[0] + ": " + line[1]));
    });
    parent.appendChild(section);
  }

  function orderOperationResourceUrl(orderId, operation) {
    var base = apiUrl("ordersUrl");
    var normalizedId = String(orderId || "");
    var action = String(operation || "").replace(/[^a-z_]/g, "");
    if (!base || !/^\d+$/.test(normalizedId) || normalizedId === "0" || !action) {
      return "";
    }
    try {
      return new URL(normalizedId + "/" + action + "/", base.replace(/\/?$/, "/")).toString();
    } catch (error) {
      return "";
    }
  }

  function orderOperationErrorMessage(error, fallback) {
    if (error && error.payload && typeof error.payload.message === "string") {
      return safeText(error.payload.message, fallback, 240);
    }
    if (error && error.status === 403) {
      return "این نشست مجوز اجرای این عملیات سفارش را ندارد.";
    }
    if (error && error.status === 422) {
      return responseErrorMessage(error, "اطلاعات عملیات سفارش معتبر نیست؛ مقادیر واردشده را بررسی کنید.");
    }
    return responseErrorMessage(error, fallback);
  }

  function submitOrderNote(orderId, contentInput, customerNoteInput, button, message) {
    if (!state.authenticated || !state.csrfToken) {
      message.textContent = "نشست امن معتبر نیست؛ دوباره وارد شوید.";
      message.hidden = false;
      return Promise.resolve();
    }
    var content = contentInput.value.trim();
    if (!content) {
      message.textContent = "متن یادداشت را وارد کنید.";
      message.hidden = false;
      contentInput.focus();
      return Promise.resolve();
    }
    var url = orderOperationResourceUrl(orderId, "notes");
    if (!url) {
      message.textContent = "نشانی API یادداشت سفارش موجود نیست.";
      message.hidden = false;
      return Promise.resolve();
    }
    var originalLabel = button.textContent;
    button.disabled = true;
    button.textContent = "در حال ثبت";
    message.textContent = "در حال ثبت یادداشت...";
    message.hidden = false;
    return fetchJsonWithTimeout(url, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-Fandoogh-CSRF": state.csrfToken },
      body: JSON.stringify({ content: content, customer_note: Boolean(customerNoteInput.checked) })
    }).then(function () {
      return loadOrders().then(function () { return loadOrderDetail(orderId); });
    }).catch(function (error) {
      if (error && error.status === 401) {
        setAuthenticated(false, "");
        showView("pairing");
        showPairingMessage("نشست منقضی شده است؛ دوباره جفت‌سازی کنید.");
        return;
      }
      message.textContent = formatDisplayText(orderOperationErrorMessage(error, "ثبت یادداشت انجام نشد."));
      message.hidden = false;
    }).finally(function () {
      button.disabled = false;
      button.textContent = originalLabel;
    });
  }

  function submitOrderRefund(orderId, amountInput, reasonInput, restockInput, paymentInput, button, message) {
    if (!state.authenticated || !state.csrfToken) {
      message.textContent = "نشست امن معتبر نیست؛ دوباره وارد شوید.";
      message.hidden = false;
      return Promise.resolve();
    }
    var amount = normalizeNumericInput(amountInput.value.trim());
    var reason = reasonInput.value.trim();
    var confirmation = amount ? "بازپرداخت " + formatStoreAmount(amount) + " برای این سفارش ثبت شود؟" : "بازپرداخت کامل مبلغ باقی‌ماندهٔ سفارش ثبت شود؟";
    if (!window.confirm(confirmation)) {
      return Promise.resolve();
    }
    var url = orderOperationResourceUrl(orderId, "refund");
    if (!url) {
      message.textContent = "نشانی API بازپرداخت سفارش موجود نیست.";
      message.hidden = false;
      return Promise.resolve();
    }
    var originalLabel = button.textContent;
    var idempotencyFingerprint = JSON.stringify({
      orderId: Number(orderId),
      amount: amount || "",
      reason: reason,
      restockItems: Boolean(restockInput.checked),
      refundPayment: Boolean(paymentInput.checked)
    });
    if (button.dataset.refundIdempotencyFingerprint !== idempotencyFingerprint || !button.dataset.refundIdempotencyKey) {
      button.dataset.refundIdempotencyFingerprint = idempotencyFingerprint;
      button.dataset.refundIdempotencyKey = "refund-" + String(orderId) + "-" + String(Date.now()) + "-" + makeEphemeralDeviceId();
    }
    var idempotencyKey = button.dataset.refundIdempotencyKey;
    var body = {
      reason: reason,
      restock_items: Boolean(restockInput.checked),
      refund_payment: Boolean(paymentInput.checked),
      idempotency_key: idempotencyKey
    };
    if (amount) {
      body.amount = amount;
    }
    button.disabled = true;
    button.textContent = "در حال ثبت";
    message.textContent = "در حال ثبت بازپرداخت...";
    message.hidden = false;
    return fetchJsonWithTimeout(url, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-Fandoogh-CSRF": state.csrfToken, "Idempotency-Key": idempotencyKey },
      body: JSON.stringify(body)
    }).then(function () {
      delete button.dataset.refundIdempotencyFingerprint;
      delete button.dataset.refundIdempotencyKey;
      return loadOrders().then(function () { return loadOrderDetail(orderId); });
    }).catch(function (error) {
      if (error && error.status === 401) {
        setAuthenticated(false, "");
        showView("pairing");
        showPairingMessage("نشست منقضی شده است؛ دوباره جفت‌سازی کنید.");
        return;
      }
      message.textContent = formatDisplayText(orderOperationErrorMessage(error, "ثبت بازپرداخت انجام نشد."));
      message.hidden = false;
    }).finally(function () {
      button.disabled = false;
      button.textContent = originalLabel;
    });
  }

  function renderOrderOperations(parent, source) {
    var section = document.createElement("section");
    section.className = "order-detail-block order-operations-block";
    section.dataset.orderDetailStep = "operations";
    section.appendChild(createTextElement("h4", "order-detail-block-title", "عملیات سفارش"));

    var refunds = source.refunds && typeof source.refunds === "object" ? source.refunds : {};
    var refundSummary = document.createElement("div");
    refundSummary.className = "order-refund-summary";
    refundSummary.appendChild(createTextElement("span", "order-refund-summary-label", "بازپرداخت‌شده: " + formatStoreAmount(String(refunds.total_refunded || "0"))));
    refundSummary.appendChild(createTextElement("span", "order-refund-summary-label", "قابل بازپرداخت: " + formatStoreAmount(String(refunds.remaining || "0"))));
    section.appendChild(refundSummary);

    var grid = document.createElement("div");
    grid.className = "order-operations-grid";

    var noteForm = document.createElement("form");
    noteForm.className = "order-operation-card";
    noteForm.setAttribute("aria-label", "ثبت یادداشت سفارش");
    noteForm.appendChild(createTextElement("h5", "order-operation-title", "یادداشت جدید"));
    var noteInput = document.createElement("textarea");
    noteInput.rows = 4;
    noteInput.maxLength = 1000;
    noteInput.placeholder = "یادداشت داخلی یا پیام مشتری را وارد کنید";
    noteForm.appendChild(noteInput);
    var customerNoteLabel = document.createElement("label");
    customerNoteLabel.className = "checkbox-label";
    var customerNoteInput = document.createElement("input");
    customerNoteInput.type = "checkbox";
    customerNoteLabel.appendChild(customerNoteInput);
    customerNoteLabel.appendChild(document.createTextNode("نمایش یادداشت برای مشتری"));
    noteForm.appendChild(customerNoteLabel);
    var noteActions = document.createElement("div");
    noteActions.className = "order-operation-actions";
    var noteButton = document.createElement("button");
    noteButton.type = "submit";
    noteButton.className = "primary-button compact-button";
    noteButton.textContent = "ثبت یادداشت";
    noteActions.appendChild(noteButton);
    var noteMessage = createTextElement("span", "secure-note operation-message", "");
    noteMessage.setAttribute("role", "status");
    noteMessage.hidden = true;
    noteActions.appendChild(noteMessage);
    noteForm.appendChild(noteActions);
    noteForm.addEventListener("submit", function (event) {
      event.preventDefault();
      submitOrderNote(state.orders.detailId, noteInput, customerNoteInput, noteButton, noteMessage);
    });
    grid.appendChild(noteForm);

    var refundForm = document.createElement("form");
    refundForm.className = "order-operation-card";
    refundForm.setAttribute("aria-label", "ثبت بازپرداخت سفارش");
    refundForm.appendChild(createTextElement("h5", "order-operation-title", "بازپرداخت"));
    var refundAmount = document.createElement("input");
    refundAmount.type = "text";
    refundAmount.inputMode = "decimal";
    refundAmount.placeholder = "خالی = بازپرداخت کامل باقی‌مانده";
    refundAmount.dir = "ltr";
    refundForm.appendChild(createShipmentField("مبلغ بازپرداخت به تومان", refundAmount));
    var refundReason = document.createElement("input");
    refundReason.type = "text";
    refundReason.maxLength = 200;
    refundReason.placeholder = "دلیل بازپرداخت";
    refundForm.appendChild(createShipmentField("دلیل", refundReason));
    var restockLabel = document.createElement("label");
    restockLabel.className = "checkbox-label";
    var restockInput = document.createElement("input");
    restockInput.type = "checkbox";
    restockInput.checked = true;
    restockLabel.appendChild(restockInput);
    restockLabel.appendChild(document.createTextNode("بازگرداندن کالا به موجودی"));
    refundForm.appendChild(restockLabel);
    var paymentLabel = document.createElement("label");
    paymentLabel.className = "checkbox-label";
    var paymentInput = document.createElement("input");
    paymentInput.type = "checkbox";
    paymentInput.checked = true;
    paymentLabel.appendChild(paymentInput);
    paymentLabel.appendChild(document.createTextNode("تلاش برای بازپرداخت از درگاه"));
    refundForm.appendChild(paymentLabel);
    var refundActions = document.createElement("div");
    refundActions.className = "order-operation-actions";
    var refundButton = document.createElement("button");
    refundButton.type = "submit";
    refundButton.className = "secondary-button compact-button danger-outline-button";
    refundButton.textContent = "ثبت بازپرداخت";
    refundActions.appendChild(refundButton);
    var refundMessage = createTextElement("span", "secure-note operation-message", "");
    refundMessage.setAttribute("role", "status");
    refundMessage.hidden = true;
    refundActions.appendChild(refundMessage);
    refundForm.appendChild(refundActions);
    refundForm.addEventListener("submit", function (event) {
      event.preventDefault();
      submitOrderRefund(state.orders.detailId, refundAmount, refundReason, restockInput, paymentInput, refundButton, refundMessage);
    });
    grid.appendChild(refundForm);

    section.appendChild(grid);
    parent.appendChild(section);
  }

  function renderOrderStatusStep(parent, source) {
    var section = document.createElement("section");
    section.className = "order-detail-block order-status-management-block";
    section.dataset.orderDetailStep = "status";
    section.appendChild(createTextElement("h4", "order-detail-block-title", "تغییر وضعیت سفارش"));
    section.appendChild(createTextElement("p", "order-detail-line", "وضعیت فعلی سفارش را بررسی کنید و در صورت نیاز آن را تغییر دهید."));

    var currentStatus = orderStatusKey(source && (source.status || source.status_value));
    var statuses = state.orders.statuses && typeof state.orders.statuses === "object" ? state.orders.statuses : {};
    var statusSelect = document.createElement("select");
    statusSelect.className = "order-detail-status-select";
    statusSelect.name = "order_status";
    statusSelect.setAttribute("aria-label", "وضعیت سفارش");

    var hasCurrentStatus = false;
    Object.keys(statuses).forEach(function (statusKey) {
      var optionValue = orderStatusKey(statusKey);
      if (!optionValue) {
        return;
      }
      var option = document.createElement("option");
      option.value = optionValue;
      option.textContent = safeText(statuses[statusKey], optionValue, 80);
      option.selected = optionValue === currentStatus;
      hasCurrentStatus = hasCurrentStatus || option.selected;
      statusSelect.appendChild(option);
    });

    if (currentStatus && !hasCurrentStatus) {
      var fallbackOption = document.createElement("option");
      fallbackOption.value = currentStatus;
      fallbackOption.textContent = safeText(source && source.status_label, currentStatus, 80);
      fallbackOption.selected = true;
      statusSelect.insertBefore(fallbackOption, statusSelect.firstChild);
    }

    if (!statusSelect.options.length) {
      var unavailableOption = document.createElement("option");
      unavailableOption.value = currentStatus || "pending";
      unavailableOption.textContent = safeText(source && source.status_label, "وضعیت فعلی", 80);
      unavailableOption.selected = true;
      statusSelect.appendChild(unavailableOption);
    }

    var field = document.createElement("label");
    field.className = "field-label order-status-management-field";
    field.appendChild(createTextElement("span", "shipment-field-label", "وضعیت سفارش"));
    field.appendChild(statusSelect);

    var actions = document.createElement("div");
    actions.className = "order-status-management-actions";
    var button = document.createElement("button");
    button.type = "button";
    button.className = "primary-button compact-button";
    button.textContent = "ذخیرهٔ وضعیت";
    button.addEventListener("click", function () {
      updateOrderStatus(state.orders.detailId, statusSelect.value, button);
    });
    actions.appendChild(button);

    var controls = document.createElement("div");
    controls.className = "order-status-management";
    controls.appendChild(field);
    controls.appendChild(actions);
    section.appendChild(controls);
    parent.appendChild(section);
  }

  var ORDER_VIEW_TAB_DEFINITIONS = [
    { key: "overview", label: "خلاصه سفارش" },
    { key: "payment", label: "پرداخت و فاکتور" },
    { key: "address", label: "مشتری و ارسال" },
    { key: "activity", label: "گزارش عملیات" }
  ];

  var ORDER_EDIT_TAB_DEFINITIONS = [
    { key: "status", label: "وضعیت و تحویل" },
    { key: "shipping", label: "ارسال و رهگیری" },
    { key: "refund", label: "بازپرداخت و یادداشت" }
  ];

  function clearOrderElement(element) {
    if (!element) {
      return;
    }
    while (element.firstChild) {
      element.removeChild(element.firstChild);
    }
  }

  function orderDetailSource(payload) {
    var source = payload && payload.data && typeof payload.data === "object" ? payload.data : payload;
    return source && typeof source === "object" ? source : {};
  }

  function orderViewBlock(title, className) {
    var section = document.createElement("section");
    section.className = "order-view-block" + (className ? " " + className : "");
    section.appendChild(createTextElement("h4", "order-view-block-title", title));
    return section;
  }

  function orderKeyValue(label, value, className) {
    var row = document.createElement("div");
    row.className = "order-key-value" + (className ? " " + className : "");
    row.appendChild(createTextElement("span", "order-key-label", label));
    row.appendChild(createTextElement("strong", "order-key-value-text", value || "—"));
    return row;
  }

  function createOrderStatusBadge(status, label, className) {
    var key = orderStatusKey(status);
    var badge = createTextElement("span", "order-status-badge order-view-status-badge" + (className ? " " + className : ""), label || "نامشخص");
    badge.dataset.status = key || "unknown";
    return badge;
  }

  function createOrderPaymentBadge(status) {
    var key = String(status || "unknown").toLowerCase();
    var badge = createTextElement("span", "order-payment-badge", orderPaymentStatusLabel(key));
    badge.dataset.status = key;
    return badge;
  }

  function appendOrderPrintButton(parent, label) {
    var button = document.createElement("button");
    button.type = "button";
    button.className = "secondary-button order-print-button";
    button.disabled = true;
    button.title = "چاپ در نسخهٔ بعدی فعال می‌شود";
    button.appendChild(createProductIcon("print"));
    button.appendChild(document.createTextNode(formatDisplayText(label + " (به‌زودی)")));
    parent.appendChild(button);
  }

  function orderImageNode(item) {
    var imageUrl = safeAssetUrl(item && (item.image || item.image_url));
    var wrapper = document.createElement("div");
    wrapper.className = "order-line-image";
    if (imageUrl) {
      var image = document.createElement("img");
      image.src = imageUrl;
      image.alt = safeText(item && item.name, "تصویر محصول", 160);
      image.loading = "lazy";
      image.decoding = "async";
      image.addEventListener("error", function () {
        wrapper.textContent = "";
        wrapper.classList.add("is-empty");
        wrapper.appendChild(createTextElement("span", "order-line-image-placeholder", "بدون تصویر"));
      });
      wrapper.appendChild(image);
      return wrapper;
    }
    wrapper.classList.add("is-empty");
    wrapper.appendChild(createTextElement("span", "order-line-image-placeholder", "بدون تصویر"));
    return wrapper;
  }

  function orderLineCard(item) {
    var source = item && typeof item === "object" ? item : {};
    var line = document.createElement("article");
    line.className = "order-line-card";
    var image = orderImageNode(source);
    line.appendChild(image);

    var body = document.createElement("div");
    body.className = "order-line-card-body";
    body.appendChild(createTextElement("h5", "order-line-card-title", safeText(source.name, "محصول بدون نام", 240)));
    var variationId = Number(source.variation_id || 0);
    if (variationId > 0) {
      body.appendChild(createTextElement("p", "order-line-card-variation", "شناسهٔ متغیر: " + formatDisplayNumber(variationId)));
    }
    var facts = document.createElement("div");
    facts.className = "order-line-card-facts";
    facts.appendChild(orderKeyValue("تعداد", formatDisplayNumber(String(source.quantity || 0)) + " قلم"));
    facts.appendChild(orderKeyValue("مبلغ کل قلم", formatStoreAmount(String(source.total || "0")), "is-emphasis"));
    body.appendChild(facts);
    line.appendChild(body);
    return line;
  }

  function renderOrderOverview(parent, source) {
    var payment = source.payment && typeof source.payment === "object" ? source.payment : {};
    var hero = document.createElement("section");
    hero.className = "order-view-hero";
    var heroTop = document.createElement("div");
    heroTop.className = "order-view-hero-top";
    var statusGroup = document.createElement("div");
    statusGroup.className = "order-view-status-group";
    statusGroup.appendChild(createOrderStatusBadge(source.status, source.status_label || source.status));
    statusGroup.appendChild(createOrderPaymentBadge(payment.status));
    heroTop.appendChild(statusGroup);
    heroTop.appendChild(createTextElement("span", "order-view-number", "سفارش " + formatOrderNumber(safeText(String(source.number || source.id || ""), "", 80))));
    hero.appendChild(heroTop);

    var meta = document.createElement("div");
    meta.className = "order-view-hero-meta";
    meta.appendChild(createTextElement("span", "order-view-date", source.date ? formatDisplayDate(source.date, true) : "تاریخ ثبت نشده"));
    meta.appendChild(createTextElement("span", "order-view-customer-name", source.customer && source.customer.display ? source.customer.display : "مهمان"));
    meta.appendChild(createTextElement("strong", "order-view-total", formatStoreAmount(String(source.total || "0"))));
    hero.appendChild(meta);
    parent.appendChild(hero);

    var itemsBlock = orderViewBlock("اقلام خریداری‌شده", "order-view-items-block");
    var lineItems = Array.isArray(source.line_items) ? source.line_items : [];
    if (!lineItems.length) {
      itemsBlock.appendChild(createTextElement("p", "order-view-empty", "جزئیات اقلام این سفارش در دسترس نیست."));
    } else {
      var itemsGrid = document.createElement("div");
      itemsGrid.className = "order-line-items-grid";
      lineItems.forEach(function (item) {
        itemsGrid.appendChild(orderLineCard(item));
      });
      itemsBlock.appendChild(itemsGrid);
    }
    parent.appendChild(itemsBlock);
  }

  function hasOrderAmount(object, key) {
    return object && Object.prototype.hasOwnProperty.call(object, key) && object[key] !== null && typeof object[key] !== "undefined" && object[key] !== "";
  }

  function appendOrderInvoiceRow(parent, label, value, className) {
    var row = document.createElement("div");
    row.className = "order-invoice-row" + (className ? " " + className : "");
    row.appendChild(createTextElement("span", "order-invoice-label", label));
    row.appendChild(createTextElement("strong", "order-invoice-value", value || "—"));
    parent.appendChild(row);
  }

  function renderOrderPayment(parent, source) {
    var payment = source.payment && typeof source.payment === "object" ? source.payment : {};
    var paymentBlock = orderViewBlock("وضعیت پرداخت", "order-view-payment-block");
    var paymentHeader = document.createElement("div");
    paymentHeader.className = "order-payment-header";
    paymentHeader.appendChild(createOrderPaymentBadge(payment.status));
    paymentHeader.appendChild(createTextElement("span", "order-payment-method", safeText(payment.method_title || payment.method, "روش پرداخت ثبت نشده است", 180)));
    paymentBlock.appendChild(paymentHeader);
    if (payment.date_paid) {
      paymentBlock.appendChild(createTextElement("p", "order-view-muted", "تاریخ پرداخت: " + formatDisplayDate(payment.date_paid, true)));
    }
    parent.appendChild(paymentBlock);

    var itemsBlock = orderViewBlock("جزئیات فاکتور", "order-view-invoice-block");
    var invoice = document.createElement("div");
    invoice.className = "order-invoice-list";
    var lineItems = Array.isArray(source.line_items) ? source.line_items : [];
    if (lineItems.length) {
      lineItems.forEach(function (item) {
        var quantity = formatDisplayNumber(String(item && item.quantity || 0));
        var name = safeText(item && item.name, "محصول بدون نام", 220);
        appendOrderInvoiceRow(invoice, name + " × " + quantity, formatStoreAmount(String(item && item.total || "0")), "order-invoice-line-item");
      });
    } else {
      appendOrderInvoiceRow(invoice, "اقلام سفارش", "جزئیات ثبت نشده است");
    }
    var totals = source.totals && typeof source.totals === "object" ? source.totals : {};
    if (hasOrderAmount(totals, "subtotal")) appendOrderInvoiceRow(invoice, "جمع اقلام", formatStoreAmount(String(totals.subtotal)));
    if (hasOrderAmount(totals, "discount_total")) appendOrderInvoiceRow(invoice, "تخفیف", formatStoreAmount(String(totals.discount_total)), "is-discount");
    if (hasOrderAmount(totals, "shipping_total")) appendOrderInvoiceRow(invoice, "هزینهٔ ارسال", formatStoreAmount(String(totals.shipping_total)));
    if (hasOrderAmount(totals, "total_tax")) appendOrderInvoiceRow(invoice, "مالیات", formatStoreAmount(String(totals.total_tax)));
    appendOrderInvoiceRow(invoice, "مبلغ نهایی پرداخت‌شده", formatStoreAmount(String(hasOrderAmount(totals, "total") ? totals.total : source.total || "0")), "is-total");
    itemsBlock.appendChild(invoice);
    var actions = document.createElement("div");
    actions.className = "order-view-actions";
    appendOrderPrintButton(actions, "چاپ فاکتور");
    itemsBlock.appendChild(actions);
    parent.appendChild(itemsBlock);
  }

  function orderAddressLines(address) {
    var source = address && typeof address === "object" ? address : {};
    return [
      ["نام", [source.first_name, source.last_name].filter(Boolean).join(" ")],
      ["شرکت", source.company],
      ["نشانی", [source.address_1, source.address_2].filter(Boolean).join("، ")],
      ["شهر و استان", [source.city, source.state].filter(Boolean).join("، ")],
      ["کدپستی", source.postcode],
      ["کشور", source.country],
      ["تلفن", source.phone],
      ["ایمیل", source.email]
    ].map(function (line) {
      return { label: line[0], value: safeText(String(line[1] || ""), "", 300) };
    }).filter(function (line) { return line.value; });
  }

  function appendOrderAddressView(parent, title, address) {
    var card = document.createElement("section");
    card.className = "order-address-card";
    card.appendChild(createTextElement("h5", "order-address-title", title));
    var lines = orderAddressLines(address);
    if (!lines.length) {
      card.appendChild(createTextElement("p", "order-view-empty", "نشانی ثبت نشده است."));
    } else {
      lines.forEach(function (line) {
        card.appendChild(orderKeyValue(line.label, line.value));
      });
    }
    parent.appendChild(card);
  }

  function renderOrderAddress(parent, source) {
    var customer = source.customer && typeof source.customer === "object" ? source.customer : {};
    var customerBlock = orderViewBlock("اطلاعات مشتری", "order-view-customer-block");
    var customerGrid = document.createElement("div");
    customerGrid.className = "order-customer-facts";
    customerGrid.appendChild(orderKeyValue("نام مشتری", safeText(customer.display, "مهمان", 160)));
    customerGrid.appendChild(orderKeyValue("نوع حساب", customer.type === "registered" ? "مشتری ثبت‌شده" : "مهمان"));
    customerGrid.appendChild(orderKeyValue("تلفن", safeText(customer.phone, "ثبت نشده است", 100)));
    customerGrid.appendChild(orderKeyValue("ایمیل", safeText(customer.email, "ثبت نشده است", 180)));
    customerBlock.appendChild(customerGrid);
    parent.appendChild(customerBlock);

    var addressGrid = document.createElement("div");
    addressGrid.className = "order-address-grid";
    appendOrderAddressView(addressGrid, "نشانی صورتحساب", source.billing);
    appendOrderAddressView(addressGrid, "نشانی ارسال", source.shipping);
    parent.appendChild(addressGrid);

    var shippingBlock = orderViewBlock("روش ارسال", "order-view-shipping-block");
    var shippingLines = Array.isArray(source.shipping_lines) ? source.shipping_lines : [];
    if (!shippingLines.length) {
      shippingBlock.appendChild(createTextElement("p", "order-view-empty", "روش ارسال در سفارش ثبت نشده است."));
    } else {
      shippingLines.forEach(function (line) {
        shippingBlock.appendChild(orderKeyValue(
          safeText(line && (line.method_title || line.method_id), "روش ارسال", 180),
          formatStoreAmount(String(line && line.total || "0"))
        ));
      });
    }
    if (state.orders.shipment) {
      shippingBlock.appendChild(orderKeyValue("وضعیت رهگیری", shipmentStatusLabel(state.orders.shipment.status, state.orders.shipment.statusLabel)));
      if (state.orders.shipment.trackingCode) {
        shippingBlock.appendChild(orderKeyValue("کد رهگیری", state.orders.shipment.trackingCode, "is-ltr"));
      }
      if (state.orders.shipment.estimatedDeliveryDate) {
        shippingBlock.appendChild(orderKeyValue("تحویل تخمینی", formatDisplayDate(state.orders.shipment.estimatedDeliveryDate, false)));
      }
    }
    var printActions = document.createElement("div");
    printActions.className = "order-view-actions";
    appendOrderPrintButton(printActions, "چاپ نشانی و ارسال");
    shippingBlock.appendChild(printActions);
    parent.appendChild(shippingBlock);
  }

  function renderOrderActivity(parent, source) {
    var block = orderViewBlock("گزارشات سفارش", "order-view-activity-block");
    var activityList = document.createElement("div");
    activityList.className = "order-activity-list";
    var hasActivity = false;
    if (source.date) {
      hasActivity = true;
      var created = document.createElement("article");
      created.className = "order-activity-item";
      created.appendChild(createTextElement("time", "order-activity-date", formatDisplayDate(source.date, true)));
      created.appendChild(createTextElement("strong", "order-activity-title", "سفارش ثبت شد"));
      activityList.appendChild(created);
    }
    if (source.customer_note) {
      hasActivity = true;
      var customerNote = document.createElement("article");
      customerNote.className = "order-activity-item is-customer-note";
      customerNote.appendChild(createTextElement("time", "order-activity-date", "یادداشت مشتری"));
      customerNote.appendChild(createTextElement("p", "order-activity-copy", source.customer_note));
      activityList.appendChild(customerNote);
    }
    var notes = Array.isArray(source.notes) ? source.notes : [];
    notes.forEach(function (note) {
      hasActivity = true;
      var item = document.createElement("article");
      item.className = "order-activity-item";
      var noteMeta = [note && note.date ? formatDisplayDate(note.date, true) : "", safeText(note && note.added_by, "", 120)].filter(Boolean).join(" — ");
      item.appendChild(createTextElement("time", "order-activity-date", noteMeta || "گزارش ثبت‌شده"));
      item.appendChild(createTextElement("p", "order-activity-copy", safeText(note && note.content, "گزارش بدون متن", 1000)));
      if (note && note.customer_note) {
        item.appendChild(createTextElement("span", "order-activity-tag", "نمایش برای مشتری"));
      }
      activityList.appendChild(item);
    });
    if (state.orders.shipment && state.orders.shipment.updatedAt) {
      hasActivity = true;
      var shipmentActivity = document.createElement("article");
      shipmentActivity.className = "order-activity-item is-shipment";
      shipmentActivity.appendChild(createTextElement("time", "order-activity-date", formatDisplayDate(state.orders.shipment.updatedAt, true)));
      shipmentActivity.appendChild(createTextElement("strong", "order-activity-title", "اطلاعات ارسال به‌روزرسانی شد"));
      activityList.appendChild(shipmentActivity);
    }
    if (!hasActivity) {
      activityList.appendChild(createTextElement("p", "order-view-empty", "گزارشی برای این سفارش ثبت نشده است."));
    }
    block.appendChild(activityList);
    parent.appendChild(block);
  }

  function renderOrderViewTab(source, tabKey) {
    if (!elements.orderDetailContent) {
      return;
    }
    var validKey = ORDER_VIEW_TAB_DEFINITIONS.some(function (definition) { return definition.key === tabKey; }) ? tabKey : "overview";
    state.orders.viewTab = validKey;
    clearOrderElement(elements.orderDetailContent);
    elements.orderDetailContent.setAttribute("aria-labelledby", "orderViewTab-" + validKey);
    elements.orderDetailContent.setAttribute("data-active-tab", validKey);
    var panel = document.createElement("div");
    panel.id = "orderViewPanel-" + validKey;
    panel.className = "order-view-tab-panel";
    panel.setAttribute("role", "tabpanel");
    panel.tabIndex = -1;
    panel.setAttribute("aria-labelledby", "orderViewTab-" + validKey);
    if (validKey === "payment") {
      renderOrderPayment(panel, source);
    } else if (validKey === "address") {
      renderOrderAddress(panel, source);
    } else if (validKey === "activity") {
      renderOrderActivity(panel, source);
    } else {
      renderOrderOverview(panel, source);
    }
    elements.orderDetailContent.appendChild(panel);
  }

  function setOrderViewTab(tabKey, shouldFocus) {
    var validKey = ORDER_VIEW_TAB_DEFINITIONS.some(function (definition) { return definition.key === tabKey; }) ? tabKey : "overview";
    state.orders.viewTab = validKey;
    if (elements.orderViewTabs) {
      Array.prototype.forEach.call(elements.orderViewTabs.querySelectorAll("[data-order-view-tab]"), function (button) {
        var active = button.getAttribute("data-order-view-tab") === validKey;
        button.classList.toggle("is-active", active);
        button.setAttribute("aria-selected", active ? "true" : "false");
        button.tabIndex = active ? 0 : -1;
      });
    }
    if (elements.orderDetailTabStatus) {
      var definition = ORDER_VIEW_TAB_DEFINITIONS.filter(function (item) { return item.key === validKey; })[0];
      elements.orderDetailTabStatus.textContent = definition ? definition.label : "";
    }
    if (state.orders.detail) {
      renderOrderViewTab(state.orders.detail, validKey);
    }
    if (shouldFocus && elements.orderViewTabs) {
      var buttonToFocus = elements.orderViewTabs.querySelector('[data-order-view-tab="' + validKey + '"]');
      if (buttonToFocus) buttonToFocus.focus();
    }
  }

  function createOrderStatusSelect(source) {
    var currentStatus = orderStatusKey(source && (source.status || source.status_value));
    var statuses = state.orders.statuses && typeof state.orders.statuses === "object" ? state.orders.statuses : {};
    var select = document.createElement("select");
    select.className = "order-detail-status-select order-edit-order-status";
    select.name = "order_status";
    var found = false;
    Object.keys(statuses).forEach(function (statusKey) {
      var optionValue = orderStatusKey(statusKey);
      if (!optionValue) return;
      var option = document.createElement("option");
      option.value = optionValue;
      option.textContent = safeText(statuses[statusKey], optionValue, 80);
      option.selected = optionValue === currentStatus;
      found = found || option.selected;
      select.appendChild(option);
    });
    if (currentStatus && !found) {
      var fallback = document.createElement("option");
      fallback.value = currentStatus;
      fallback.textContent = safeText(source && source.status_label, currentStatus, 80);
      fallback.selected = true;
      select.insertBefore(fallback, select.firstChild);
    }
    if (!select.options.length) {
      var unavailable = document.createElement("option");
      unavailable.value = currentStatus || "pending";
      unavailable.textContent = safeText(source && source.status_label, "وضعیت فعلی", 80);
      unavailable.selected = true;
      select.appendChild(unavailable);
    }
    return select;
  }

  function createShipmentStatusSelect(value, className) {
    var select = document.createElement("select");
    select.className = className || "order-edit-shipment-status";
    select.name = "shipment_status";
    Object.keys(SHIPMENT_STATUS_LABELS).forEach(function (statusKey) {
      var option = document.createElement("option");
      option.value = statusKey;
      option.textContent = SHIPMENT_STATUS_LABELS[statusKey];
      option.selected = statusKey === value;
      select.appendChild(option);
    });
    return select;
  }

  function renderOrderEditStatusTab(parent, source) {
    var section = orderViewBlock("وضعیت سفارش و تحویل کالا", "order-edit-status-block");
    section.appendChild(createTextElement("p", "order-edit-help", "وضعیت سفارش و وضعیت تحویل را جداگانه بررسی و ثبت کنید."));
    var grid = document.createElement("div");
    grid.className = "order-edit-fields-grid";
    var shipment = state.orders.shipment && typeof state.orders.shipment === "object" ? state.orders.shipment : {
      status: "pending", shippedAt: "", estimatedDeliveryDate: "", deliveredAt: "", carrierLabel: "", trackingCode: "", note: ""
    };
    var defaultShippingLine = Array.isArray(source.shipping_lines) && source.shipping_lines.length ? source.shipping_lines[0] : {};
    var defaultShippingMethodId = shipment.shippingMethodId || safeText(defaultShippingLine.method_id || defaultShippingLine.method_title, "", 100);
    var defaultShippingMethodTitle = shipment.shippingMethodTitle || safeText(defaultShippingLine.method_title || defaultShippingLine.method_id, "", 255);
    var orderStatus = createOrderStatusSelect(source);
    var deliveryStatus = createShipmentStatusSelect(shipment.status || "pending");
    var estimatedDate = createShipmentInput("date", shipment.estimatedDeliveryDate, 80, "");
    var deliveredAt = createShipmentInput("datetime-local", shipmentDateTimeInputValue(shipment.deliveredAt), 80, "");
    grid.appendChild(createShipmentField("وضعیت سفارش", orderStatus));
    grid.appendChild(createShipmentField("وضعیت تحویل کالا", deliveryStatus));
    grid.appendChild(createShipmentField("زمان تحویل", deliveredAt));
    grid.appendChild(createShipmentField("تاریخ تحویل تخمینی", estimatedDate));
    section.appendChild(grid);
    parent.appendChild(section);
    state.orders.editControls = {
      orderStatus: orderStatus,
      shipmentStatus: deliveryStatus,
      estimatedDeliveryDate: estimatedDate,
      deliveredAt: deliveredAt,
      shippedAt: { value: shipmentDateTimeInputValue(shipment.shippedAt) },
      shippingMethodId: { value: defaultShippingMethodId },
      shippingMethodTitle: { value: defaultShippingMethodTitle },
      carrierLabel: { value: shipment.carrierLabel || "" },
      trackingCode: { value: shipment.trackingCode || "" },
      note: { value: shipment.note || "" }
    };
  }

  function mountOrderShipmentForEdit() {
    if (!elements.orderEditTabContent || !elements.orderShipmentContainer) {
      return;
    }
    elements.orderShipmentContainer.hidden = false;
    elements.orderEditTabContent.appendChild(elements.orderShipmentContainer);
  }

  function renderOrderEditShippingTab(parent, source) {
    var intro = orderViewBlock("ارسال و رهگیری", "order-edit-shipping-intro");
    intro.appendChild(createTextElement("p", "order-edit-help", "کد رهگیری، شرکت حمل، زمان ارسال، زمان تحویل و اقلام ارسال‌شده را ثبت کنید."));
    var shippingLines = Array.isArray(source.shipping_lines) ? source.shipping_lines : [];
    if (shippingLines.length) {
      intro.appendChild(createTextElement("p", "order-edit-method-note", "روش ارسال پیش‌فرض از انتخاب ثبت‌شدهٔ مشتری در هنگام خرید خوانده می‌شود."));
    }
    parent.appendChild(intro);
    mountOrderShipmentForEdit();
    if (state.orders.shipmentStatus === "ready" && state.orders.shipment) {
      renderOrderShipment(state.orders.shipment);
    } else if (state.orders.shipmentStatus === "error") {
      renderOrderShipmentError("اطلاعات رهگیری برای ویرایش در دسترس نیست؛ دوباره دریافت کنید.");
    } else {
      renderOrderShipmentLoading();
    }
    mountOrderShipmentForEdit();
  }

  function renderOrderEditRefundTab(parent, source) {
    renderOrderOperations(parent, source);
  }

  function renderOrderEditTab(tabKey) {
    if (!elements.orderEditTabContent || !state.orders.detail) {
      return;
    }
    var validKey = ORDER_EDIT_TAB_DEFINITIONS.some(function (definition) { return definition.key === tabKey; }) ? tabKey : "status";
    state.orders.editTab = validKey;
    state.orders.editControls = null;
    clearOrderElement(elements.orderEditTabContent);
    if (elements.orderShipmentContainer && elements.orderShipmentContainer.parentNode !== elements.orderEditDialog) {
      elements.orderEditDialog.appendChild(elements.orderShipmentContainer);
    }
    if (elements.orderShipmentContainer) {
      elements.orderShipmentContainer.hidden = true;
    }
    if (validKey === "shipping") {
      renderOrderEditShippingTab(elements.orderEditTabContent, state.orders.detail);
    } else if (validKey === "refund") {
      renderOrderEditRefundTab(elements.orderEditTabContent, state.orders.detail);
    } else {
      renderOrderEditStatusTab(elements.orderEditTabContent, state.orders.detail);
    }
    elements.orderEditTabContent.setAttribute("aria-labelledby", "orderEditTab-" + validKey);
    if (elements.orderEditTabStatus) {
      var definition = ORDER_EDIT_TAB_DEFINITIONS.filter(function (item) { return item.key === validKey; })[0];
      elements.orderEditTabStatus.textContent = definition ? definition.label : "";
    }
  }

  function setOrderEditTab(tabKey, shouldFocus) {
    var validKey = ORDER_EDIT_TAB_DEFINITIONS.some(function (definition) { return definition.key === tabKey; }) ? tabKey : "status";
    state.orders.editTab = validKey;
    if (elements.orderEditTabs) {
      Array.prototype.forEach.call(elements.orderEditTabs.querySelectorAll("[data-order-edit-tab]"), function (button) {
        var active = button.getAttribute("data-order-edit-tab") === validKey;
        button.classList.toggle("is-active", active);
        button.setAttribute("aria-selected", active ? "true" : "false");
        button.tabIndex = active ? 0 : -1;
      });
    }
    renderOrderEditTab(validKey);
    if (shouldFocus && elements.orderEditTabs) {
      var buttonToFocus = elements.orderEditTabs.querySelector('[data-order-edit-tab="' + validKey + '"]');
      if (buttonToFocus) buttonToFocus.focus();
    }
  }

  function setOrderEditMessage(message) {
    if (!elements.orderEditMessage) return;
    elements.orderEditMessage.textContent = formatDisplayText(message || "");
    elements.orderEditMessage.hidden = !message;
  }

  function openOrderEditor(orderId, trigger, returnToDetail) {
    if (!state.authenticated) {
      showPairingMessage("برای ویرایش سفارش ابتدا اتصال امن مدیر را برقرار کنید.");
      showView("pairing");
      return;
    }
    var normalizedId = String(orderId || "");
    state.orders.editTrigger = trigger || state.orders.detailTrigger;
    state.orders.editReturnToDetail = Boolean(returnToDetail);
    function showEditor() {
      if (!state.authenticated) {
        return;
      }
      if (!state.orders.detail || String(state.orders.detail.id || state.orders.detailId || "") !== normalizedId) {
        return;
      }
      if (elements.orderDetailPanel) elements.orderDetailPanel.hidden = true;
      document.body.classList.remove("order-detail-open");
      elements.orderEditTitle.textContent = formatDisplayText("ویرایش سفارش " + formatOrderNumber(safeText(String(state.orders.detail.number || normalizedId), "", 80)));
      state.orders.editTab = "status";
      elements.orderEditPanel.hidden = false;
      document.body.classList.add("order-edit-open");
      setOrderEditMessage("");
      setOrderEditTab("status", false);
      if (elements.closeOrderEdit) elements.closeOrderEdit.focus();
    }
    if (state.orders.detail && String(state.orders.detail.id || state.orders.detailId || "") === normalizedId) {
      showEditor();
      return;
    }
    loadOrderDetail(normalizedId).then(showEditor);
  }

  function closeOrderEdit(silent) {
    if (!elements.orderEditPanel) return;
    var trigger = state.orders.editTrigger;
    var returnToDetail = state.orders.editReturnToDetail;
    elements.orderEditPanel.hidden = true;
    document.body.classList.remove("order-edit-open");
    clearOrderElement(elements.orderEditTabContent);
    if (elements.orderShipmentContainer) {
      elements.orderShipmentContainer.hidden = true;
      if (elements.orderEditDialog && elements.orderShipmentContainer.parentNode !== elements.orderEditDialog) {
        elements.orderEditDialog.appendChild(elements.orderShipmentContainer);
      }
    }
    state.orders.editTab = "status";
    state.orders.editTrigger = null;
    state.orders.editReturnToDetail = false;
    state.orders.editControls = null;
    if (returnToDetail && state.orders.detail && elements.orderDetailPanel) {
      elements.orderDetailPanel.hidden = false;
      document.body.classList.add("order-detail-open");
      setOrderViewTab(state.orders.viewTab || "overview", false);
      if (!silent && elements.orderDetailEdit) elements.orderDetailEdit.focus();
      return;
    }
    if (!silent && trigger && typeof trigger.focus === "function" && document.contains(trigger)) {
      trigger.focus();
    }
  }

  function saveOrderEditChanges() {
    if (!state.orders.detail || !state.orders.detailId || !state.authenticated) {
      setOrderEditMessage("نشست امن معتبر نیست؛ دوباره وارد شوید.");
      return;
    }
    var tabKey = state.orders.editTab || "status";
    if (tabKey === "refund") {
      setOrderEditMessage("برای ثبت یادداشت یا بازپرداخت، دکمهٔ همان فرم را بزنید تا عملیات ناخواسته اجرا نشود.");
      return;
    }
    if (tabKey === "shipping") {
      var shipmentForm = elements.orderShipmentContainer && elements.orderShipmentContainer.querySelector(".shipment-form");
      if (!shipmentForm) {
        setOrderEditMessage("فرم ارسال هنوز آماده نیست؛ دوباره اطلاعات را دریافت کنید.");
        return;
      }
      setOrderEditMessage("در حال ذخیرهٔ اطلاعات ارسال...");
      if (typeof shipmentForm.requestSubmit === "function") {
        shipmentForm.requestSubmit();
      } else {
        shipmentForm.dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));
      }
      return;
    }
    var controls = state.orders.editControls;
    if (!controls) {
      setOrderEditMessage("فرم وضعیت آماده نیست؛ دوباره تب را باز کنید.");
      return;
    }
    var orderId = state.orders.detailId;
    var currentStatus = orderStatusKey(state.orders.detail.status || state.orders.detail.status_value);
    var statusPromise = controls.orderStatus.value !== currentStatus ? updateOrderStatus(orderId, controls.orderStatus.value, elements.saveOrderEdit) : Promise.resolve(true);
    setOrderEditMessage("در حال ذخیرهٔ وضعیت و تحویل...");
    statusPromise.then(function () {
      return saveOrderShipment(orderId, {
        status: controls.shipmentStatus,
        carrierLabel: controls.carrierLabel,
        trackingCode: controls.trackingCode,
        shippedAt: controls.shippedAt,
        estimatedDeliveryDate: controls.estimatedDeliveryDate,
        deliveredAt: controls.deliveredAt,
        note: controls.note
      }, elements.saveOrderEdit, elements.orderEditMessage);
    }).then(function (saved) {
      if (saved !== false) {
        setOrderEditMessage("تغییرات وضعیت و تحویل ذخیره شد.");
      }
    });
  }

  function renderOrderDetail(payload) {
    var source = orderDetailSource(payload);
    state.orders.detail = source;
    elements.orderDetailTitle.textContent = formatDisplayText("جزئیات سفارش " + formatOrderNumber(safeText(String(source.number || source.id || state.orders.detailId || ""), "", 80)));
    setOrderViewTab(state.orders.viewTab || "overview", false);
    if (elements.orderDetailPanel) {
      elements.orderDetailPanel.setAttribute("aria-busy", "false");
    }
  }

  function closeOrderDetail() {
    if (!elements.orderDetailPanel) {
      return;
    }
    if (elements.orderEditPanel && !elements.orderEditPanel.hidden) {
      closeOrderEdit(true);
    }
    var trigger = state.orders.detailTrigger;
    state.orders.detailId = null;
    state.orders.detail = null;
    state.orders.detailTrigger = null;
    state.orders.viewTab = "overview";
    state.orders.shipment = null;
    state.orders.shipmentStatus = "idle";
    elements.orderDetailPanel.hidden = true;
    document.body.classList.remove("order-detail-open");
    elements.orderDetailLoadingState.hidden = true;
    elements.orderDetailErrorState.hidden = true;
    while (elements.orderDetailContent.firstChild) {
      elements.orderDetailContent.removeChild(elements.orderDetailContent.firstChild);
    }
    if (elements.orderShipmentContainer) {
      elements.orderShipmentContainer.textContent = "";
      elements.orderShipmentContainer.hidden = true;
      if (elements.orderEditDialog && elements.orderShipmentContainer.parentNode !== elements.orderEditDialog) {
        elements.orderEditDialog.appendChild(elements.orderShipmentContainer);
      }
    }
    if (trigger && typeof trigger.focus === "function" && document.contains(trigger)) {
      trigger.focus();
    }
  }

  function loadOrderDetail(orderId) {
    if (!state.authenticated) {
      showPairingMessage("برای مشاهدهٔ جزئیات سفارش ابتدا اتصال امن مدیر را برقرار کنید.");
      showView("pairing");
      return Promise.resolve();
    }

    var url = orderResourceUrl(orderId);
    if (!url) {
      return Promise.resolve();
    }

    var keepEditOpen = Boolean(elements.orderEditPanel && !elements.orderEditPanel.hidden);
    state.orders.detailId = String(orderId);
    if (!keepEditOpen) {
      state.orders.viewTab = "overview";
      elements.orderDetailPanel.hidden = false;
      document.body.classList.add("order-detail-open");
    }
    elements.orderDetailLoadingState.hidden = keepEditOpen;
    elements.orderDetailErrorState.hidden = true;
    elements.orderDetailContent.textContent = "";
    renderOrderShipmentLoading();
    return fetchJsonWithTimeout(url).then(function (payload) {
      if (state.orders.detailId !== String(orderId)) {
        return;
      }
      elements.orderDetailLoadingState.hidden = true;
      renderOrderDetail(payload);
      if (keepEditOpen) {
        renderOrderEditTab(state.orders.editTab || "status");
      } else if (elements.closeOrderDetail) {
        elements.closeOrderDetail.focus();
      }
      return loadOrderShipment(orderId);
    }).catch(function (error) {
      elements.orderDetailLoadingState.hidden = true;
      if (error && error.status === 401) {
        setAuthenticated(false, "");
        showView("pairing");
        showPairingMessage("نشست منقضی شده است؛ دوباره جفت‌سازی کنید.");
        return;
      }
      elements.orderDetailErrorText.textContent = formatDisplayText(responseErrorMessage(error, "جزئیات سفارش دریافت نشد."));
      elements.orderDetailErrorState.hidden = false;
      if (keepEditOpen) {
        setOrderEditMessage(formatDisplayText(responseErrorMessage(error, "جزئیات سفارش دریافت نشد.")));
      }
    });
  }

  function orderStatusResourceUrl(orderId) {
    var base = apiUrl("ordersUrl");
    orderId = String(orderId || "");
    if (!base || !/^\d+$/.test(orderId) || orderId === "0") {
      return "";
    }

    try {
      return new URL(orderId + "/status/", base.replace(/\/?$/, "/")).toString();
    } catch (error) {
      return "";
    }
  }

  function orderStatusWasApplied(orderId, expectedStatus) {
    var targetId = String(orderId || "");
    var targetStatus = String(expectedStatus || "");

    return state.orders.items.some(function (order) {
      return order && String(order.id) === targetId && order.statusValue === targetStatus;
    });
  }

  function applyOrderStatusResponse(payload, orderId, fallbackStatus) {
    var targetId = String(orderId || "");
    var source = payload && payload.data && typeof payload.data === "object" ? payload.data : null;
    var normalized = source ? normalizeOrder(source) : null;
    var itemIndex = -1;

    for (var index = 0; index < state.orders.items.length; index += 1) {
      if (state.orders.items[index] && String(state.orders.items[index].id) === targetId) {
        itemIndex = index;
        break;
      }
    }

    if (normalized && normalized.id === targetId) {
      if (itemIndex >= 0) {
        state.orders.items[itemIndex] = normalized;
      } else {
        state.orders.items.push(normalized);
      }
    } else if (itemIndex >= 0) {
      var current = state.orders.items[itemIndex];
      current.statusValue = String(fallbackStatus || current.statusValue || "");
      current.status = safeText(
        state.orders.statuses["wc-" + current.statusValue] || state.orders.statuses[current.statusValue],
        current.status || current.statusValue,
        80
      );
    } else {
      return false;
    }

    state.orders.status = "ready";
    renderOrderResults();
    return orderStatusWasApplied(targetId, fallbackStatus);
  }

  function syncOrderDetailStatus(orderId, fallbackStatus, payload) {
    if (!state.orders.detail || String(state.orders.detailId || "") !== String(orderId || "")) {
      return;
    }

    var source = payload && payload.data && typeof payload.data === "object" ? payload.data : null;
    var normalized = source ? normalizeOrder(source) : null;
    var rawStatus = normalized && normalized.statusValue ? normalized.statusValue : String(fallbackStatus || "");
    var statusKey = orderStatusKey(rawStatus);
    var statusLabel = normalized && normalized.status ? normalized.status : safeText(
      state.orders.statuses["wc-" + statusKey] || state.orders.statuses[statusKey],
      statusKey || "نامشخص",
      80
    );
    state.orders.detail.status = rawStatus;
    state.orders.detail.status_label = statusLabel;

    Array.prototype.forEach.call(document.querySelectorAll("#orderDetailContent .order-view-status-badge, #orderEditTabContent .order-card-status"), function (badge) {
      badge.textContent = statusLabel;
      badge.dataset.status = statusKey;
    });
    Array.prototype.forEach.call(document.querySelectorAll("#orderDetailContent .order-detail-status-select, #orderEditTabContent .order-detail-status-select"), function (select) {
      select.value = statusKey;
    });
  }

  function confirmOrderStatus(orderId, expectedStatus, retries) {
    return loadOrders().then(function (refreshed) {
      if (refreshed && orderStatusWasApplied(orderId, expectedStatus)) {
        return true;
      }

      if (!state.authenticated || retries < 1) {
        return false;
      }

      return new Promise(function (resolve) {
        window.setTimeout(resolve, 1200);
      }).then(function () {
        return confirmOrderStatus(orderId, expectedStatus, retries - 1);
      });
    });
  }

  function updateOrderStatus(orderId, status, button) {
    if (!state.authenticated || !state.csrfToken) {
      return Promise.resolve(false);
    }

    var url = orderStatusResourceUrl(orderId);
    if (!url || !status) {
      return Promise.resolve(false);
    }

    var expectedStatus = orderStatusKey(state.orders.detail && (state.orders.detail.status || state.orders.detail.status_value));
    if (!expectedStatus) {
      setOrdersMutationMessage("وضعیت فعلی سفارش در دسترس نیست؛ جزئیات را تازه‌سازی کنید.");
      return Promise.resolve(false);
    }

    button.disabled = true;
    var originalLabel = button.textContent;
    button.textContent = "در حال ذخیره";
    setOrdersMutationMessage("");
    return fetchJsonWithTimeout(url, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-Fandoogh-CSRF": state.csrfToken
      },
      body: JSON.stringify({ status: status, expected_status: expectedStatus })
    }).then(function (payload) {
      var responseApplied = applyOrderStatusResponse(payload, orderId, status);
      syncOrderDetailStatus(orderId, status, payload);
      return confirmOrderStatus(orderId, status, 1).then(function (confirmed) {
        if (confirmed) {
          setOrdersMutationMessage("وضعیت سفارش ذخیره و از سرور تأیید شد.");
          return;
        }

        if (responseApplied && state.authenticated) {
          applyOrderStatusResponse(payload, orderId, status);
          setOrdersMutationMessage("وضعیت سفارش ذخیره شد؛ تازه‌سازی فهرست با تأخیر مواجه شد.");
          return;
        }

        setOrdersState("error", "ذخیره انجام شد اما وضعیت سفارش از سرور تأیید نشد؛ دوباره تلاش کنید.");
      });
    }).catch(function (error) {
      if (error && error.status === 401) {
        setAuthenticated(false, "");
        showView("pairing");
        showPairingMessage("نشست منقضی شده است؛ دوباره جفت‌سازی کنید.");
        return;
      }

      return confirmOrderStatus(orderId, status, 1).then(function (confirmed) {
        if (confirmed) {
          syncOrderDetailStatus(orderId, status, null);
          setOrdersMutationMessage("وضعیت سفارش در سرور ثبت شد و پس از بازخوانی تأیید شد.");
          return;
        }

        setOrdersState("error", responseErrorMessage(error, "تغییر وضعیت سفارش انجام نشد."));
      });
    }).finally(function () {
      button.disabled = false;
      button.textContent = originalLabel;
    });
  }

  // [UI: NOTIFICATIONS] تازه‌سازی دوره‌ای شمارندهٔ سفارش‌های بدون اقدام؛
  // زنگ اعلان‌ها و نشان‌ها را بدون دخالت کاربر به‌روز نگه می‌دارد.
  var PENDING_COUNT_REFRESH_MS = 60000;
  var pendingCountRefreshTimer = null;

  function startPendingOrderCountRefresh() {
    if (pendingCountRefreshTimer !== null) {
      return;
    }
    pendingCountRefreshTimer = window.setInterval(function () {
      refreshPendingOrderCount();
    }, PENDING_COUNT_REFRESH_MS);
  }

  function stopPendingOrderCountRefresh() {
    if (pendingCountRefreshTimer !== null) {
      window.clearInterval(pendingCountRefreshTimer);
      pendingCountRefreshTimer = null;
    }
  }

  function refreshPendingOrderCount() {
    if (!state.authenticated || state.previewMode) {
      return;
    }
    if (typeof document !== "undefined" && document.hidden) {
      return;
    }
    if (typeof navigator !== "undefined" && navigator.onLine === false) {
      return;
    }
    loadPendingOrderCount();
  }

  function loadPendingOrderCount() {
    if (!state.authenticated || !userHasScope("orders.read")) {
      state.orders.pendingCount = null;
      updatePendingOrderBadges();
      return Promise.resolve();
    }

    var requestId = state.orders.pendingCountRequestId + 1;
    state.orders.pendingCountRequestId = requestId;
    // perPage > 1 also fetches the newest pending rows so the notifications
    // popover can list them; the count still comes from meta.total.
    var pendingUrl = ordersRequestUrl(1, { perPage: 5, status: "pending", includeFilters: false });
    if (!pendingUrl) {
      state.orders.pendingCount = null;
      state.orders.pendingItems = [];
      updatePendingOrderBadges();
      return Promise.resolve();
    }

    var wasFetching = state.orders.pendingCount === null && state.orders.pendingCountRequestId >= 1;
    state.orders.pendingFetching = true;
    elements.notificationsBadge.setAttribute("data-loading", "true");
    elements.notificationsPanelText && (elements.notificationsPanelText.dataset.fetching = "true");

    return fetchJsonWithTimeout(pendingUrl).then(function (payload) {
      if (requestId !== state.orders.pendingCountRequestId) {
        return false;
      }
      var meta = payload && payload.meta && typeof payload.meta === "object" ? payload.meta : {};
      state.orders.pendingCount = Math.max(0, Number(meta.total || 0) || extractOrders(payload).filter(isUnprocessedOrder).length);
      state.orders.pendingItems = extractOrders(payload).filter(isUnprocessedOrder).slice(0, 5);
      elements.notificationsBadge.removeAttribute("data-loading");
      if (elements.notificationsPanelText) {
        delete elements.notificationsPanelText.dataset.fetching;
      }
      updatePendingOrderBadges();
      return true;
    }).catch(function () {
      if (requestId === state.orders.pendingCountRequestId) {
        state.orders.pendingCount = null;
        state.orders.pendingItems = [];
        elements.notificationsBadge.removeAttribute("data-loading");
        if (elements.notificationsPanelText) {
          delete elements.notificationsPanelText.dataset.fetching;
        }
        updatePendingOrderBadges();
      }
      return false;
    });
  }

  function loadOrders(page) {
    if (state.previewMode) {
      if (typeof page === "number" && isFinite(page)) {
        state.orders.page = Math.max(1, Math.floor(page));
      }
      renderOrderResults();
      return Promise.resolve(true);
    }

    if (!state.authenticated) {
      setOrdersState("secure", "برای مشاهدهٔ سفارش‌ها، ابتدا اتصال امن مدیر باید برقرار شود.");
      return Promise.resolve();
    }

    var normalizedSearch = normalizeSearchText(state.orders.filters.search);
    if (normalizedSearch && normalizedSearch.length < 2) {
      renderOrderResults();
      return Promise.resolve();
    }

    var requestId = state.orders.loadRequestId + 1;
    state.orders.loadRequestId = requestId;
    var pageSize = getOrdersPageSize();
    var pageSizeChanged = state.orders.pageSize && state.orders.pageSize !== pageSize;
    if (pageSizeChanged) {
      state.orders.page = 1;
    }
    state.orders.pageSize = pageSize;
    if (!pageSizeChanged && typeof page === "number" && isFinite(page)) {
      state.orders.page = Math.max(1, Math.floor(page));
    }
    var ordersUrl = ordersRequestUrl(state.orders.page || 1);
    if (!ordersUrl) {
      setOrdersState("error", "نشانی API سفارش‌ها در پیکربندی سایت موجود نیست.");
      return Promise.resolve();
    }

    setOrdersMutationMessage("");
    setOrdersState("loading");
    return fetchJsonWithTimeout(ordersUrl).then(function (payload) {
      if (requestId !== state.orders.loadRequestId) {
        return false;
      }

      state.orders.items = extractOrders(payload);
      state.orders.statuses = payload && payload.meta && payload.meta.statuses && typeof payload.meta.statuses === "object" ? payload.meta.statuses : {};
      state.orders.page = payload && payload.meta && Number(payload.meta.page) > 0 ? Number(payload.meta.page) : state.orders.page;
      state.orders.total = payload && payload.meta ? Number(payload.meta.total || 0) : state.orders.items.length;
      state.orders.totalPages = payload && payload.meta ? Number(payload.meta.total_pages || 0) : (state.orders.items.length ? 1 : 0);
      renderOrderStatusOptions();
      state.orders.status = "ready";
      renderOrderResults();
      renderDashboardSummary();
      loadPendingOrderCount();
      return true;
    }).catch(function (error) {
      if (requestId !== state.orders.loadRequestId) {
        return false;
      }

      if (error && error.status === 401) {
        setAuthenticated(false, "");
        showView("pairing");
        showPairingMessage("نشست منقضی شده است؛ دوباره جفت‌سازی کنید.");
        return false;
      }
      if (error && error.status === 403) {
        setOrdersState("error", "این نشست مجوز مشاهدهٔ سفارش‌ها را ندارد.");
        return false;
      }
      renderOrdersPagination();
      setOrdersState("error", "اتصال به API سفارش‌ها برقرار نشد. دوباره تلاش کنید.");
      return false;
    });
  }

  // [UI: CUSTOMERS] فهرست مشتریان، صفحه‌بندی و جزئیات مشتری.
  function customerStateLabel(stateName) {
    var labels = {
      secure: "نیازمند اتصال امن",
      loading: "در حال دریافت",
      empty: "بدون مشتری",
      error: "خطا در دریافت",
      ready: "آماده"
    };
    return labels[stateName] || labels.secure;
  }

  function setCustomersState(stateName, detail) {
    if (!elements.customersStateBadge) {
      return;
    }
    state.customers.status = stateName;
    elements.customersStateBadge.textContent = customerStateLabel(stateName);
    elements.customersStateBadge.setAttribute("data-state", stateName);
    elements.customersSecureState.hidden = stateName !== "secure";
    elements.customersLoadingState.hidden = stateName !== "loading";
    elements.customersEmptyState.hidden = stateName !== "empty";
    elements.customersErrorState.hidden = stateName !== "error";
    elements.customersGrid.hidden = stateName !== "ready";
    if (elements.customersPagination) {
      renderCustomersPagination();
    }
    if (stateName === "empty" && detail) {
      elements.customersEmptyText.textContent = formatDisplayText(detail);
    }
    if (stateName === "error" && detail) {
      elements.customersErrorText.textContent = formatDisplayText(detail);
    }
  }

  function normalizeCustomer(value) {
    var source = value && typeof value === "object" ? value : {};
    var display = source.display_name || source.display || [source.first_name, source.last_name].filter(Boolean).join(" ");
    return {
      id: safeText(String(source.id || ""), "", 40),
      display: safeText(display, "مشتری بدون نام", 160),
      username: safeText(source.username, "", 80),
      email: safeText(source.email, "", 160),
      phone: safeText(source.phone || (source.billing && source.billing.phone), "", 80),
      firstName: safeText(source.first_name, "", 80),
      lastName: safeText(source.last_name, "", 80),
      paying: Boolean(source.is_paying_customer),
      orderCount: safeText(String(source.order_count || 0), "0", 20),
      totalSpent: safeText(String(source.total_spent || 0), "0", 60),
      dateCreated: source.date_created ? formatDisplayDate(source.date_created, true) : "",
      billing: source.billing && typeof source.billing === "object" ? source.billing : {},
      shipping: source.shipping && typeof source.shipping === "object" ? source.shipping : {}
    };
  }

  function extractCustomers(payload) {
    if (Array.isArray(payload)) {
      return payload.map(normalizeCustomer);
    }
    if (payload && Array.isArray(payload.data)) {
      return payload.data.map(normalizeCustomer);
    }
    if (payload && Array.isArray(payload.customers)) {
      return payload.customers.map(normalizeCustomer);
    }
    return [];
  }

  function renderCustomerCards(items) {
    while (elements.customersGrid.firstChild) {
      elements.customersGrid.removeChild(elements.customersGrid.firstChild);
    }
    items.forEach(function (customer) {
      var card = document.createElement("article");
      card.className = "customer-card";
      card.appendChild(createTextElement("h3", "category-card-name", customer.display));
      card.appendChild(createTextElement("p", "category-card-slug", customer.email || customer.username || "بدون اطلاعات تماس"));
      if (customer.phone) {
        card.appendChild(createTextElement("p", "category-card-slug", customer.phone));
      }
      card.appendChild(createTextElement("p", "category-card-count", formatDisplayNumber(customer.orderCount) + " سفارش"));
      var detailButton = document.createElement("button");
      detailButton.type = "button";
      detailButton.className = "secondary-button compact-button";
      detailButton.textContent = "پروفایل و نشانی";
      detailButton.addEventListener("click", function () {
        loadCustomerDetail(customer.id);
      });
      card.appendChild(detailButton);
      elements.customersGrid.appendChild(card);
    });
  }

  function renderCustomerResults() {
    if (state.customers.status !== "ready" && state.customers.status !== "empty" && !state.customers.items.length) {
      return;
    }
    var query = normalizeSearchText(state.customers.search);
    state.customers.filteredItems = state.customers.items.filter(function (customer) {
      return searchTextIncludes([
        customer.id,
        customer.display,
        customer.username,
        customer.email,
        customer.phone,
        customer.firstName,
        customer.lastName
      ], query);
    });
    if (!state.customers.filteredItems.length) {
      setCustomersState("empty", state.customers.search ? "مشتری‌ای با این عبارت پیدا نشد." : "هنوز مشتری‌ای برای نمایش وجود ندارد.");
      renderCustomersPagination();
      return;
    }
    renderCustomerCards(state.customers.filteredItems);
    setCustomersState("ready");
    renderCustomersPagination();
  }

  function renderCustomersPagination() {
    if (!elements.customersPagination) {
      return;
    }

    var totalPages = Math.max(1, Number(state.customers.totalPages || 0));
    var page = Math.min(totalPages, Math.max(1, Number(state.customers.page || 1)));
    elements.customersPagination.hidden = state.customers.status === "secure" || state.customers.status === "loading" || state.customers.status === "error" || state.customers.status === "empty";
    elements.customersPreviousPage.disabled = page <= 1;
    elements.customersNextPage.disabled = page >= totalPages;
    elements.customersPageLabel.textContent = totalPages > 1 ? "صفحهٔ " + formatDisplayNumber(page) + " از " + formatDisplayNumber(totalPages) : "";
  }

  function customersRequestUrl(page) {
    var base = state.config && state.config.api ? state.config.api.customersUrl : "";
    if (!base) {
      return "";
    }

    try {
      var url = new URL(base, window.location.href);
      url.searchParams.set("page", String(Math.max(1, Number(page || 1))));
      url.searchParams.set("per_page", "20");
      if (state.customers.search) {
        url.searchParams.set("search", normalizeSearchText(state.customers.search));
      }
      return url.toString();
    } catch (error) {
      return "";
    }
  }

  function scheduleCustomerSearch() {
    state.customers.search = orderInputValue(elements.customerSearch);
    state.customers.page = 1;
    if (state.customers.searchTimer) {
      window.clearTimeout(state.customers.searchTimer);
    }
    renderCustomerResults();
    state.customers.searchTimer = window.setTimeout(function () {
      state.customers.searchTimer = null;
      loadCustomers(1);
    }, 350);
  }

  function customerResourceUrl(customerId) {
    var base = apiUrl("customersUrl");
    customerId = String(customerId || "");
    if (!base || !/^\d+$/.test(customerId) || customerId === "0") {
      return "";
    }
    try {
      return new URL(customerId + "/", base.replace(/\/?$/, "/")).toString();
    } catch (error) {
      return "";
    }
  }

  function customerOrdersResourceUrl(customerId, page) {
    var base = customerResourceUrl(customerId);
    if (!base) return "";
    try {
      var url = new URL("orders/", base);
      url.searchParams.set("page", String(Math.max(1, Number(page || 1))));
      url.searchParams.set("per_page", "20");
      return url.toString();
    } catch (error) {
      return "";
    }
  }

  function renderCustomerOrderHistory() {
    var section = document.getElementById("customerOrderHistory");
    if (!section) return;
    while (section.firstChild) section.removeChild(section.firstChild);
    section.appendChild(createTextElement("h4", "order-detail-block-title", "سفارش‌های قبلی"));

    if (state.customers.detailOrdersStatus === "loading") {
      section.appendChild(createTextElement("p", "order-detail-line", "در حال دریافت تاریخچهٔ سفارش‌ها..."));
      return;
    }
    if (state.customers.detailOrdersStatus === "error") {
      section.appendChild(createTextElement("p", "order-detail-line", "تاریخچهٔ سفارش‌های این مشتری دریافت نشد."));
      return;
    }
    if (!state.customers.detailOrders.length) {
      section.appendChild(createTextElement("p", "order-detail-line", "هنوز سفارشی برای این مشتری ثبت نشده است."));
      return;
    }

    state.customers.detailOrders.forEach(function (order) {
      var row = document.createElement("div");
      row.className = "customer-order-row";
      var copy = document.createElement("div");
      copy.appendChild(createTextElement("strong", "customer-order-number", "سفارش #" + (order.number || order.id)));
      copy.appendChild(createTextElement("span", "customer-order-meta", order.status + " — " + order.date));
      row.appendChild(copy);
      row.appendChild(createTextElement("strong", "customer-order-total", formatDisplayAmount(order.total)));
      var button = document.createElement("button");
      button.type = "button";
      button.className = "secondary-button compact-button";
      button.textContent = "مشاهدهٔ سفارش";
      button.addEventListener("click", function () {
        state.orders.detailTrigger = button;
        navigateToSection("orders");
        closeCustomerDetail();
        loadOrderDetail(order.id);
      });
      row.appendChild(button);
      section.appendChild(row);
    });

    var totalPages = Math.max(1, Number(state.customers.detailOrdersTotalPages || 0));
    if (totalPages > 1) {
      var pagination = document.createElement("div");
      pagination.className = "list-pagination customer-orders-pagination";
      var previous = document.createElement("button");
      previous.type = "button";
      previous.className = "secondary-button compact-button";
      previous.textContent = "قبلی";
      previous.disabled = state.customers.detailOrdersPage <= 1;
      previous.addEventListener("click", function () { loadCustomerOrders(state.customers.detailId, state.customers.detailOrdersPage - 1); });
      var label = createTextElement("span", "list-pagination-label", "صفحهٔ " + formatDisplayNumber(state.customers.detailOrdersPage) + " از " + formatDisplayNumber(totalPages));
      var next = document.createElement("button");
      next.type = "button";
      next.className = "secondary-button compact-button";
      next.textContent = "بعدی";
      next.disabled = state.customers.detailOrdersPage >= totalPages;
      next.addEventListener("click", function () { loadCustomerOrders(state.customers.detailId, state.customers.detailOrdersPage + 1); });
      pagination.appendChild(previous);
      pagination.appendChild(label);
      pagination.appendChild(next);
      section.appendChild(pagination);
    }
  }

  function loadCustomerOrders(customerId, page) {
    var targetId = String(customerId || "");
    var url = customerOrdersResourceUrl(targetId, page || 1);
    if (!url) return Promise.resolve();
    var requestId = state.customers.detailOrdersRequestId + 1;
    state.customers.detailOrdersRequestId = requestId;
    state.customers.detailOrdersStatus = "loading";
    renderCustomerOrderHistory();
    return fetchJsonWithTimeout(url).then(function (payload) {
      if (requestId !== state.customers.detailOrdersRequestId || state.customers.detailId !== targetId) return false;
      state.customers.detailOrders = extractOrders(payload);
      state.customers.detailOrdersStatus = "ready";
      state.customers.detailOrdersPage = payload && payload.meta && Number(payload.meta.page) > 0 ? Number(payload.meta.page) : Number(page || 1);
      state.customers.detailOrdersTotal = payload && payload.meta ? Number(payload.meta.total || state.customers.detailOrders.length) : state.customers.detailOrders.length;
      state.customers.detailOrdersTotalPages = payload && payload.meta ? Number(payload.meta.total_pages || 0) : (state.customers.detailOrders.length ? 1 : 0);
      renderCustomerOrderHistory();
      return true;
    }).catch(function (error) {
      if (requestId !== state.customers.detailOrdersRequestId || state.customers.detailId !== targetId) return false;
      if (error && error.status === 401) {
        setAuthenticated(false, "");
        showView("pairing");
        showPairingMessage("نشست منقضی شده است؛ دوباره جفت‌سازی کنید.");
        return false;
      }
      state.customers.detailOrdersStatus = "error";
      renderCustomerOrderHistory();
      return false;
    });
  }

  function renderCustomerDetail(payload) {
    var source = payload && payload.data && typeof payload.data === "object" ? payload.data : payload;
    var customer = normalizeCustomer(source);
    state.customers.detailId = customer.id;
    elements.customerDetailTitle.textContent = formatDisplayText(customer.display);
    while (elements.customerDetailContent.firstChild) {
      elements.customerDetailContent.removeChild(elements.customerDetailContent.firstChild);
    }
    var summary = document.createElement("div");
    summary.className = "order-detail-summary";
    summary.appendChild(createTextElement("strong", "order-detail-total", customer.email || "بدون ایمیل"));
    summary.appendChild(createTextElement("span", "order-card-date", customer.phone || "بدون تلفن"));
    summary.appendChild(createTextElement("span", "order-card-status", formatDisplayNumber(customer.orderCount) + " سفارش"));
    summary.appendChild(createTextElement("span", "order-card-date", formatStoreAmount(customer.totalSpent) + " مجموع خرید"));
    if (customer.dateCreated) {
      summary.appendChild(createTextElement("span", "order-card-date", "عضویت: " + customer.dateCreated));
    }
    elements.customerDetailContent.appendChild(summary);
    appendOrderAddress(elements.customerDetailContent, "صورتحساب", customer.billing);
    appendOrderAddress(elements.customerDetailContent, "ارسال", customer.shipping);
    var history = document.createElement("section");
    history.id = "customerOrderHistory";
    history.className = "order-detail-block customer-order-history";
    elements.customerDetailContent.appendChild(history);
    state.customers.detailOrders = [];
    state.customers.detailOrdersPage = 1;
    state.customers.detailOrdersTotal = 0;
    state.customers.detailOrdersTotalPages = 0;
    state.customers.detailOrdersStatus = "loading";
    renderCustomerOrderHistory();
  }

  function closeCustomerDetail() {
    if (!elements.customerDetailPanel) {
      return;
    }
    state.customers.detailId = null;
    state.customers.detailOrdersRequestId += 1;
    state.customers.detailOrdersStatus = "idle";
    state.customers.detailOrders = [];
    elements.customerDetailPanel.hidden = true;
    while (elements.customerDetailContent.firstChild) {
      elements.customerDetailContent.removeChild(elements.customerDetailContent.firstChild);
    }
  }

  function loadCustomerDetail(customerId) {
    if (!state.authenticated) {
      showPairingMessage("برای مشاهدهٔ پروفایل مشتری ابتدا اتصال امن مدیر را برقرار کنید.");
      showView("pairing");
      return Promise.resolve();
    }
    var url = customerResourceUrl(customerId);
    if (!url) {
      return Promise.resolve();
    }
    state.customers.detailId = String(customerId);
    elements.customerDetailPanel.hidden = false;
    elements.customerDetailContent.textContent = "در حال دریافت...";
    return fetchJsonWithTimeout(url).then(function (payload) {
      renderCustomerDetail(payload);
      return loadCustomerOrders(customerId, 1);
    }).catch(function (error) {
      if (error && error.status === 401) {
        setAuthenticated(false, "");
        showView("pairing");
        showPairingMessage("نشست منقضی شده است؛ دوباره جفت‌سازی کنید.");
        return;
      }
      elements.customerDetailContent.textContent = formatDisplayText(responseErrorMessage(error, "پروفایل مشتری دریافت نشد."));
    });
  }

  function loadCustomers(page) {
    if (state.previewMode) {
      if (typeof page === "number" && isFinite(page)) {
        state.customers.page = Math.max(1, Math.floor(page));
      }
      renderCustomerResults();
      return Promise.resolve(true);
    }

    if (!state.authenticated) {
      setCustomersState("secure", "برای مشاهدهٔ مشتریان، ابتدا اتصال امن مدیر را برقرار کنید.");
      return Promise.resolve();
    }
    if (typeof page === "number" && isFinite(page)) {
      state.customers.page = Math.max(1, Math.floor(page));
    }
    var customersUrl = customersRequestUrl(state.customers.page || 1);
    if (!customersUrl) {
      setCustomersState("error", "نشانی API مشتریان در پیکربندی سایت موجود نیست.");
      return Promise.resolve();
    }
    var requestId = state.customers.loadRequestId + 1;
    state.customers.loadRequestId = requestId;
    setCustomersState("loading");
    return fetchJsonWithTimeout(customersUrl).then(function (payload) {
      if (requestId !== state.customers.loadRequestId) {
        return false;
      }
      state.customers.items = extractCustomers(payload);
      state.customers.page = payload && payload.meta && Number(payload.meta.page) > 0 ? Number(payload.meta.page) : state.customers.page;
      state.customers.total = payload && payload.meta ? Number(payload.meta.total || 0) : state.customers.items.length;
      state.customers.totalPages = payload && payload.meta ? Number(payload.meta.total_pages || 0) : (state.customers.items.length ? 1 : 0);
      state.customers.status = "ready";
      renderCustomerResults();
      return true;
    }).catch(function (error) {
      if (requestId !== state.customers.loadRequestId) {
        return false;
      }
      if (error && error.status === 401) {
        setAuthenticated(false, "");
        showView("pairing");
        showPairingMessage("نشست منقضی شده است؛ دوباره جفت‌سازی کنید.");
        return;
      }
      renderCustomersPagination();
      setCustomersState("error", "اتصال به API مشتریان برقرار نشد.");
    });
  }

  // [UI: DASHBOARD & ANALYTICS] KPIهای داشبورد، نمودار و گزارش‌های تحلیلی.
  function analyticsStateLabel(stateName) {
    var labels = {
      secure: "نیازمند اتصال امن",
      loading: "در حال دریافت",
      error: "خطا در دریافت",
      ready: "آماده",
      disabled: "خاموش"
    };
    return labels[stateName] || labels.secure;
  }

  function setAnalyticsState(stateName, detail) {
    if (!elements.analyticsPanel) {
      return;
    }

    var configured = Boolean(state.config && state.config.analytics && state.config.analytics.enabled);
    var enabled = configured && hasAnalyticsAccess();
    state.analytics.enabled = configured;
    state.analytics.status = stateName;
    elements.analyticsPanel.hidden = !enabled || state.activeSection !== "analytics";
    if (elements.dashboardSalesPanel) {
      elements.dashboardSalesPanel.hidden = !enabled || state.activeSection !== "dashboard";
    }
    if (elements.dashboardKpiGrid) {
      elements.dashboardKpiGrid.hidden = !enabled || state.activeSection !== "dashboard";
    }
    if (elements.dashboardOrderStatusPanel) {
      elements.dashboardOrderStatusPanel.hidden = !enabled || state.activeSection !== "dashboard";
    }
    Array.prototype.forEach.call(document.querySelectorAll('[data-nav-target="analytics"]'), function (item) {
      item.hidden = !enabled;
    });

    if (!enabled) {
      return;
    }

    elements.analyticsStateBadge.textContent = analyticsStateLabel(stateName);
    elements.analyticsStateBadge.setAttribute("data-state", stateName);
    elements.analyticsRange.disabled = stateName === "loading";
    elements.refreshAnalytics.disabled = stateName === "loading";
    elements.analyticsSecureState.hidden = stateName !== "secure";
    elements.analyticsLoadingState.hidden = stateName !== "loading";
    elements.analyticsErrorState.hidden = stateName !== "error";
    elements.analyticsKpis.hidden = stateName !== "ready";
    elements.analyticsContent.hidden = stateName !== "ready";
    if (stateName === "error" && detail) {
      elements.analyticsErrorText.textContent = formatDisplayText(detail);
    }
  }

  function normalizeAnalytics(payload) {
    var source = payload && payload.data && typeof payload.data === "object" ? payload.data : {};
    var range = source.range && typeof source.range === "object" ? source.range : {};
    var currency = source.currency && typeof source.currency === "object" ? source.currency : {};
    var sales = source.sales && typeof source.sales === "object" ? source.sales : {};
    var orders = source.orders && typeof source.orders === "object" ? source.orders : {};
    var products = source.products && typeof source.products === "object" ? source.products : {};
    var customers = source.customers && typeof source.customers === "object" ? source.customers : {};
    var meta = source.meta && typeof source.meta === "object" ? source.meta : {};

    return {
      range: {
        label: safeText(range.label, "بازهٔ انتخابی", 60),
        start: safeText(range.start, "", 50),
        end: safeText(range.end, "", 50)
      },
      currency: {
        code: safeText(currency.code, "IRT", 12).toUpperCase(),
        label: safeText(currency.label, "تومان", 32)
      },
      sales: {
        gross: safeText(String(sales.gross || "0"), "0", 50),
        averageOrder: safeText(String(sales.average_order || "0"), "0", 50)
      },
      orders: {
        total: Math.max(0, Number(orders.total) || 0),
        successful: Math.max(0, Number(orders.successful) || 0),
        statuses: Array.isArray(orders.statuses) ? orders.statuses.map(function (item) {
          var status = item && typeof item === "object" ? item : {};
          return {
            label: safeText(status.label || status.key, "نامشخص", 80),
            count: Math.max(0, Number(status.count) || 0)
          };
        }) : []
      },
      products: {
        total: Math.max(0, Number(products.total) || 0),
        top: Array.isArray(products.top) ? products.top.map(function (item) {
          var product = item && typeof item === "object" ? item : {};
          return {
            name: safeText(product.name, "محصول بدون نام", 160),
            quantity: Math.max(0, Number(product.quantity) || 0),
            total: safeText(String(product.total || "0"), "0", 50)
          };
        }) : []
      },
      customers: {
        unique: Math.max(0, Number(customers.unique) || 0),
        guestOrders: Math.max(0, Number(customers.guest_orders) || 0)
      },
      truncated: Boolean(meta.truncated)
    };
  }

  function extractAnalytics(payload) {
    return normalizeAnalytics(payload);
  }

  function analyticsAmount(value, currencyLabel) {
    return formatDisplayAmount(String(value || "0") + " " + formatCurrencyLabel(currencyLabel || "تومان"));
  }

  function renderDashboardKpis() {
    if (!elements.dashboardKpiGrid) {
      return;
    }

    var data = state.analytics.data && typeof state.analytics.data === "object" ? state.analytics.data : null;
    var rangeLabel = data && data.range ? formatDisplayText(data.range.label) : "در بازهٔ انتخابی";
    var orderCount = data && data.orders ? data.orders.total : (state.orders.total || state.orders.items.length);
    var customerCount = data && data.customers ? data.customers.unique : (state.customers.total || state.customers.items.length);
    var productCount = data && data.products ? data.products.total : state.products.items.length;

    if (elements.dashboardKpiRevenue) {
      elements.dashboardKpiRevenue.textContent = data && data.sales ? analyticsAmount(data.sales.gross, data.currency && data.currency.label) : "—";
    }
    if (elements.dashboardKpiRevenuePeriod) {
      elements.dashboardKpiRevenuePeriod.textContent = data ? rangeLabel : "پس از فعال‌سازی تحلیل";
    }
    if (elements.dashboardKpiOrders) {
      elements.dashboardKpiOrders.textContent = formatDisplayNumber(orderCount);
    }
    if (elements.dashboardKpiOrdersPeriod) {
      elements.dashboardKpiOrdersPeriod.textContent = data ? rangeLabel : "فهرست فعلی سفارش‌ها";
    }
    if (elements.dashboardKpiCustomers) {
      elements.dashboardKpiCustomers.textContent = formatDisplayNumber(customerCount);
    }
    if (elements.dashboardKpiCustomersPeriod) {
      elements.dashboardKpiCustomersPeriod.textContent = data ? rangeLabel : "فهرست مشتریان";
    }
    if (elements.dashboardKpiProducts) {
      elements.dashboardKpiProducts.textContent = formatDisplayNumber(productCount);
    }
    if (elements.dashboardKpiProductsPeriod) {
      elements.dashboardKpiProductsPeriod.textContent = data ? "در کاتالوگ این بازه" : "کاتالوگ فعلی فروشگاه";
    }
  }

  function analyticsStatusColor(index) {
    var colors = ["#0f766e", "#f59e0b", "#3b82f6", "#ef4444", "#8b5cf6", "#ec4899", "#64748b"];
    return colors[index % colors.length];
  }

  function renderStatusDistribution(data, list, donut, donutTotal) {
    var statuses = data && data.orders && Array.isArray(data.orders.statuses) ? data.orders.statuses : [];
    var total = data && data.orders ? Math.max(0, Number(data.orders.total) || 0) : 0;
    var segments = [];
    var cursor = 0;

    if (donutTotal) {
      donutTotal.textContent = formatDisplayNumber(total);
    }

    statuses.forEach(function (status, index) {
      var count = Math.max(0, Number(status.count) || 0);
      if (total <= 0 || count <= 0 || cursor >= 100) {
        return;
      }
      var end = Math.min(100, cursor + (count / total) * 100);
      segments.push(analyticsStatusColor(index) + " " + cursor + "% " + end + "%");
      cursor = end;
    });

    if (donut) {
      if (segments.length && cursor < 100) {
        segments.push("#e5e7eb " + cursor + "% 100%");
      }
      donut.style.setProperty("--analytics-donut-gradient", segments.length ? "conic-gradient(" + segments.join(", ") + ")" : "conic-gradient(#e5e7eb 0 100%)");
      donut.setAttribute("aria-label", "توزیع وضعیت سفارش‌ها؛ جمع کل " + formatDisplayNumber(total));
    }

    if (!list) {
      return;
    }

    while (list.firstChild) {
      list.removeChild(list.firstChild);
    }

    if (!statuses.length) {
      list.appendChild(createTextElement("p", "analytics-note", "در این بازه سفارشی ثبت نشده است."));
      return;
    }

    statuses.forEach(function (status, index) {
      var count = Math.max(0, Number(status.count) || 0);
      var percent = total > 0 ? Math.round((count / total) * 100) : 0;
      var row = document.createElement("div");
      row.className = "analytics-status-row";
      row.style.setProperty("--analytics-status-color", analyticsStatusColor(index));

      var heading = document.createElement("div");
      heading.className = "analytics-status-heading";
      var labelWrap = document.createElement("span");
      labelWrap.className = "analytics-status-label-wrap";
      var marker = document.createElement("i");
      marker.className = "analytics-status-dot";
      marker.setAttribute("aria-hidden", "true");
      labelWrap.appendChild(marker);
      labelWrap.appendChild(createTextElement("span", "analytics-status-label", status.label));
      var valueWrap = document.createElement("span");
      valueWrap.className = "analytics-status-value";
      valueWrap.appendChild(createTextElement("strong", "", formatDisplayNumber(count)));
      valueWrap.appendChild(createTextElement("small", "", formatDisplayNumber(percent) + "٪"));
      heading.appendChild(labelWrap);
      heading.appendChild(valueWrap);
      row.appendChild(heading);
      list.appendChild(row);
    });
  }

  function renderAnalytics(data) {
    var currencyLabel = data.currency.label || "تومان";
    elements.analyticsGrossSales.textContent = analyticsAmount(data.sales.gross, currencyLabel);
    elements.analyticsAverageOrder.textContent = analyticsAmount(data.sales.averageOrder, currencyLabel);
    elements.analyticsOrdersCount.textContent = formatDisplayNumber(data.orders.total);
    elements.analyticsCustomersCount.textContent = formatDisplayNumber(data.customers.unique);
    elements.analyticsRangeLabel.textContent = formatDisplayText(data.range.label);

    renderStatusDistribution(data, elements.analyticsStatusList, elements.analyticsStatusDonut, elements.analyticsStatusDonutTotal);
    renderStatusDistribution(data, elements.dashboardStatusList, elements.dashboardStatusDonut, elements.dashboardStatusDonutTotal);

    while (elements.analyticsProductList.firstChild) {
      elements.analyticsProductList.removeChild(elements.analyticsProductList.firstChild);
    }

    data.products.top.forEach(function (product) {
      var row = document.createElement("div");
      row.className = "analytics-product-row";
      var heading = document.createElement("div");
      heading.className = "analytics-product-heading";
      var copy = document.createElement("div");
      copy.appendChild(createTextElement("strong", "", product.name));
      copy.appendChild(createTextElement("small", "", formatDisplayNumber(product.quantity) + " عدد فروش"));
      heading.appendChild(copy);
      heading.appendChild(createTextElement("span", "analytics-product-total", analyticsAmount(product.total, currencyLabel)));
      row.appendChild(heading);
      elements.analyticsProductList.appendChild(row);
    });

    if (!data.products.top.length) {
      elements.analyticsProductList.appendChild(createTextElement("p", "analytics-note", "محصولی برای این بازه پیدا نشد."));
    }

    if (data.truncated) {
      elements.analyticsNote.textContent = "برای حفظ سرعت، تحلیل روی سقف امن داده‌های اخیر محاسبه شده است.";
      elements.analyticsNote.hidden = false;
    } else {
      elements.analyticsNote.hidden = true;
    }

    renderDashboardSummary();
  }

  function dashboardNumericValue(value) {
    var normalized = toEnglishDigits(String(value === null || typeof value === "undefined" ? "" : value)).replace(/[٬,\s]/g, "");
    var match = normalized.match(/[+-]?(?:\d+(?:\.\d+)?|\.\d+)/);
    return match ? Number(match[0]) || 0 : 0;
  }

  function renderDashboardSummary() {
    updatePendingOrderBadges();
    renderDashboardKpis();
    renderStatusDistribution(state.analytics.data, elements.dashboardStatusList, elements.dashboardStatusDonut, elements.dashboardStatusDonutTotal);
    if (!elements.dashboardRecentOrders || !elements.dashboardTopProducts) {
      return;
    }

    while (elements.dashboardRecentOrders.firstChild) {
      elements.dashboardRecentOrders.removeChild(elements.dashboardRecentOrders.firstChild);
    }
    while (elements.dashboardTopProducts.firstChild) {
      elements.dashboardTopProducts.removeChild(elements.dashboardTopProducts.firstChild);
    }

    var recentOrders = state.orders.items.slice(0, 4);
    if (!recentOrders.length) {
      elements.dashboardRecentOrders.appendChild(createTextElement("p", "dashboard-list-empty", state.authenticated ? "هنوز سفارشی برای نمایش وجود ندارد." : "پس از اتصال امن، سفارش‌های اخیر اینجا دیده می‌شوند."));
    } else {
      recentOrders.forEach(function (order) {
        var row = document.createElement("div");
        row.className = "dashboard-order-row";
        var copy = document.createElement("div");
        copy.className = "dashboard-row-copy";
        copy.appendChild(createTextElement("strong", "", "سفارش #" + order.id));
        copy.appendChild(createTextElement("small", "", order.customer + " · " + order.date));
        var meta = document.createElement("div");
        meta.className = "dashboard-row-meta";
        meta.appendChild(createTextElement("span", "dashboard-status-pill", order.status));
        meta.appendChild(createTextElement("strong", "dashboard-row-total", formatDisplayAmount(order.total)));
        row.appendChild(copy);
        row.appendChild(meta);
        elements.dashboardRecentOrders.appendChild(row);
      });
    }

    var topProducts = state.analytics.data && state.analytics.data.products && Array.isArray(state.analytics.data.products.top) ? state.analytics.data.products.top : state.products.items.slice(0, 4).map(function (product) {
      return {
        name: product.name,
        quantity: 0,
        total: product.price
      };
    });

    if (!topProducts.length) {
      elements.dashboardTopProducts.appendChild(createTextElement("p", "dashboard-list-empty", state.authenticated ? "اطلاعات محصولی برای نمایش وجود ندارد." : "پس از اتصال امن، محصولات پرفروش اینجا دیده می‌شوند."));
    } else {
      topProducts.slice(0, 4).forEach(function (product, index) {
        var row = document.createElement("div");
        row.className = "dashboard-product-row";
        row.appendChild(createTextElement("span", "dashboard-product-rank", formatDisplayNumber(index + 1)));
        var copy = document.createElement("div");
        copy.className = "dashboard-row-copy";
        copy.appendChild(createTextElement("strong", "", product.name));
        copy.appendChild(createTextElement("small", "", product.quantity ? formatDisplayNumber(product.quantity) + " عدد فروش" : "قیمت " + formatStoreAmount(product.total)));
        row.appendChild(copy);
        row.appendChild(createTextElement("strong", "dashboard-row-total", product.total ? analyticsAmount(product.total, state.analytics.data && state.analytics.data.currency ? state.analytics.data.currency.label : "تومان") : "—"));
        elements.dashboardTopProducts.appendChild(row);
      });
    }

    var salesOrders = state.orders.items.slice(0, 7).reverse();
    if (elements.dashboardChartData) {
      while (elements.dashboardChartData.firstChild) elements.dashboardChartData.removeChild(elements.dashboardChartData.firstChild);
      salesOrders.forEach(function (order) {
        var row = document.createElement("tr");
        row.appendChild(createTextElement("td", "", order.date));
        row.appendChild(createTextElement("td", "", formatDisplayAmount(order.total)));
        row.appendChild(createTextElement("td", "", formatDisplayNumber(order.itemCount)));
        elements.dashboardChartData.appendChild(row);
      });
    }
    if (!salesOrders.length) {
      if (elements.dashboardSalesLine) {
        elements.dashboardSalesLine.setAttribute("points", "");
      }
      if (elements.dashboardSalesOrdersLine) {
        elements.dashboardSalesOrdersLine.setAttribute("points", "");
      }
      if (elements.dashboardSalesChartEmpty) {
        elements.dashboardSalesChartEmpty.hidden = false;
      }
      return;
    }

    var salesValues = salesOrders.map(function (order) {
      return dashboardNumericValue(order.total);
    });
    var orderValues = salesOrders.map(function (order) {
      return Math.max(0, dashboardNumericValue(order.itemCount));
    });
    var pointString = function (values) {
      var min = Math.min.apply(Math, values);
      var max = Math.max.apply(Math, values);
      var spread = max - min || 1;
      return values.map(function (value, index) {
        var x = values.length === 1 ? 320 : 20 + (600 * index / (values.length - 1));
        var y = 180 - ((value - min) / spread) * 130;
        return Math.round(x) + "," + Math.round(y);
      }).join(" ");
    };

    if (elements.dashboardSalesLine) {
      elements.dashboardSalesLine.setAttribute("points", pointString(salesValues));
    }
    if (elements.dashboardSalesOrdersLine) {
      elements.dashboardSalesOrdersLine.setAttribute("points", pointString(orderValues));
    }
    if (elements.dashboardSalesChartEmpty) {
      elements.dashboardSalesChartEmpty.hidden = true;
    }
  }

  function analyticsResourceUrl() {
    var base = apiUrl("analyticsUrl");
    if (!base) {
      return "";
    }

    try {
      var url = new URL(base);
      url.searchParams.set("range", state.analytics.range);
      return url.toString();
    } catch (error) {
      return base;
    }
  }

  function loadAnalytics() {
    if (state.previewMode) {
      renderAnalytics(state.analytics.data);
      setAnalyticsState("ready");
      return Promise.resolve(true);
    }

    if (!state.config || !state.config.analytics || !state.config.analytics.enabled) {
      setAnalyticsState("disabled");
      return Promise.resolve();
    }

    if (!state.authenticated) {
      setAnalyticsState("secure", "برای مشاهدهٔ تحلیل، ابتدا اتصال امن مدیر را برقرار کنید.");
      return Promise.resolve();
    }

    if (!userHasScope("analytics.read")) {
      setAnalyticsState("disabled");
      return Promise.resolve();
    }

    var url = analyticsResourceUrl();
    if (!url) {
      setAnalyticsState("error", "نشانی API تحلیل در پیکربندی سایت موجود نیست.");
      return Promise.resolve();
    }

    setAnalyticsState("loading");
    return fetchJsonWithTimeout(url).then(function (payload) {
      state.analytics.data = extractAnalytics(payload);
      renderAnalytics(state.analytics.data);
      setAnalyticsState("ready");
    }).catch(function (error) {
      if (error && error.status === 401) {
        setAuthenticated(false, "");
        showView("pairing");
        showPairingMessage("نشست منقضی شده است؛ دوباره جفت‌سازی کنید.");
        return;
      }
      setAnalyticsState("error", responseErrorMessage(error, "دریافت تحلیل فروش انجام نشد."));
    });
  }

  // [UI: INVENTORY] موجودی، خلاصهٔ انبار و تغییرات سریع موجودی.
  function operationsStateLabel(stateName) {
    var labels = {
      secure: "نیازمند اتصال امن",
      loading: "در حال دریافت",
      empty: "بدون مورد",
      error: "خطا در دریافت",
      ready: "آماده"
    };
    return labels[stateName] || labels.secure;
  }

  function operationCollectionUrl(apiName, page, params) {
    var base = apiUrl(apiName);
    if (!base) {
      return "";
    }
    try {
      var url = new URL(base);
      url.searchParams.set("page", String(Math.max(1, Number(page) || 1)));
      url.searchParams.set("per_page", "20");
      Object.keys(params || {}).forEach(function (key) {
        var value = params[key];
        if (value !== null && typeof value !== "undefined" && String(value) !== "") {
          url.searchParams.set(key, key === "search" ? normalizeSearchText(value) : String(value));
        }
      });
      return url.toString();
    } catch (error) {
      return "";
    }
  }

  function operationResourceUrl(apiName, id) {
    var base = apiUrl(apiName);
    var normalizedId = String(id || "");
    if (!base || !/^\d+$/.test(normalizedId) || normalizedId === "0") {
      return "";
    }
    try {
      return new URL(normalizedId + "/", base.replace(/\/?$/, "/")).toString();
    } catch (error) {
      return "";
    }
  }

  function renderOperationPager(previous, next, label, page, totalPages, ready) {
    if (!previous || !next || !label) {
      return;
    }
    var currentPage = Math.max(1, Number(page) || 1);
    var pages = Math.max(1, Number(totalPages) || 1);
    previous.disabled = !ready || currentPage <= 1;
    next.disabled = !ready || currentPage >= pages;
    label.textContent = formatDisplayText(formatDisplayNumber(currentPage) + " از " + formatDisplayNumber(pages));
  }

  function setInventoryState(stateName, detail) {
    state.inventory.status = stateName;
    elements.inventoryStateBadge.textContent = operationsStateLabel(stateName);
    elements.inventoryStateBadge.setAttribute("data-state", stateName);
    elements.inventorySecureState.hidden = stateName !== "secure";
    elements.inventoryLoadingState.hidden = stateName !== "loading";
    elements.inventoryEmptyState.hidden = stateName !== "empty";
    elements.inventoryErrorState.hidden = stateName !== "error";
    elements.inventoryGrid.hidden = stateName !== "ready";
    elements.inventorySummary.hidden = stateName !== "ready";
    elements.inventoryPagination.hidden = stateName !== "ready";
    if (elements.inventoryMessage) {
      elements.inventoryMessage.hidden = !detail;
      elements.inventoryMessage.textContent = detail ? formatDisplayText(detail) : "";
    }
    if (stateName === "error" && detail) {
      elements.inventoryErrorText.textContent = formatDisplayText(detail);
    }
    renderOperationPager(elements.inventoryPreviousPage, elements.inventoryNextPage, elements.inventoryPageLabel, state.inventory.page, state.inventory.totalPages, stateName === "ready");
  }

  function normalizeInventoryItem(value) {
    var source = value && typeof value === "object" ? value : {};
    return {
      id: safeText(String(source.id || ""), "", 40),
      name: safeText(source.name, "محصول بدون نام", 180),
      sku: safeText(source.sku, "", 100),
      type: safeText(source.type, "simple", 30),
      stockStatus: safeText(source.stock_status, "instock", 30),
      stockStatusLabel: safeText(source.stock_status_label || source.stock_label, "موجود", 80),
      quantity: source.stock_quantity === null || typeof source.stock_quantity === "undefined" ? "" : String(source.stock_quantity),
      manageStock: Boolean(source.manage_stock),
      backorders: safeText(source.backorders, "no", 20),
      lowStockAmount: source.low_stock_amount === null || typeof source.low_stock_amount === "undefined" ? "" : String(source.low_stock_amount),
      lowStock: Boolean(source.low_stock),
      updatedAt: safeText(source.updated_at, "", 40)
    };
  }

  function extractInventory(payload) {
    var rows = payload && Array.isArray(payload.data) ? payload.data : (Array.isArray(payload) ? payload : []);
    return rows.map(normalizeInventoryItem);
  }

  function renderInventorySummary() {
    while (elements.inventorySummary.firstChild) {
      elements.inventorySummary.removeChild(elements.inventorySummary.firstChild);
    }
    var summary = state.inventory.summary || {};
    [
      ["تعداد این صفحه", summary.total || state.inventory.items.length, "inventory-summary-total"],
      ["نیازمند تأمین", summary.low_stock || 0, "inventory-summary-low"],
      ["ناموجود", summary.out_of_stock || 0, "inventory-summary-out"],
      ["پیش‌فروش", summary.on_backorder || 0, "inventory-summary-backorder"]
    ].forEach(function (item) {
      var card = document.createElement("div");
      card.className = "inventory-summary-card " + item[2];
      card.appendChild(createTextElement("span", "inventory-summary-label", item[0]));
      card.appendChild(createTextElement("strong", "inventory-summary-value", formatDisplayNumber(item[1])));
      elements.inventorySummary.appendChild(card);
    });
  }

  function inventoryStatusLabel(status) {
    return {
      instock: "موجود",
      outofstock: "ناموجود",
      onbackorder: "پیش‌فروش"
    }[status] || "وضعیت نامشخص";
  }

  function filteredInventoryItems() {
    var query = normalizeSearchText(state.inventory.filters.search);
    return state.inventory.items.filter(function (item) {
      return searchTextIncludes([item.id, item.name, item.sku, item.stockStatus, item.stockStatusLabel], query);
    });
  }

  function renderInventoryResults() {
    while (elements.inventoryGrid.firstChild) {
      elements.inventoryGrid.removeChild(elements.inventoryGrid.firstChild);
    }
    filteredInventoryItems().forEach(function (item) {
      var card = document.createElement("article");
      card.className = "inventory-card";
      card.appendChild(createTextElement("h3", "inventory-card-title", item.name));
      card.appendChild(createTextElement("p", "inventory-card-meta", item.sku ? "SKU: " + item.sku : "بدون SKU"));
      var statusRow = document.createElement("div");
      statusRow.className = "inventory-card-status";
      statusRow.appendChild(createTextElement("span", "inventory-status-pill", item.stockStatusLabel || inventoryStatusLabel(item.stockStatus)));
      if (item.lowStock) {
        statusRow.appendChild(createTextElement("span", "inventory-warning-text", "موجودی کم"));
      }
      card.appendChild(statusRow);

      var controls = document.createElement("div");
      controls.className = "inventory-card-controls";
      var manageLabel = document.createElement("label");
      manageLabel.className = "checkbox-label";
      var manageInput = document.createElement("input");
      manageInput.type = "checkbox";
      manageInput.checked = item.manageStock;
      manageLabel.appendChild(manageInput);
      manageLabel.appendChild(document.createTextNode("مدیریت موجودی"));
      controls.appendChild(manageLabel);

      var quantityLabel = document.createElement("label");
      quantityLabel.className = "field-label";
      quantityLabel.appendChild(createTextElement("span", "shipment-field-label", "تعداد"));
      var quantityInput = document.createElement("input");
      quantityInput.type = "text";
      quantityInput.inputMode = "numeric";
      quantityInput.dir = "ltr";
      quantityInput.value = formatNumericInput(item.quantity);
      quantityInput.disabled = !item.manageStock;
      quantityLabel.appendChild(quantityInput);
      controls.appendChild(quantityLabel);

      var statusLabel = document.createElement("label");
      statusLabel.className = "field-label";
      statusLabel.appendChild(createTextElement("span", "shipment-field-label", "وضعیت"));
      var statusSelect = document.createElement("select");
      ["instock", "outofstock", "onbackorder"].forEach(function (status) {
        var option = document.createElement("option");
        option.value = status;
        option.textContent = inventoryStatusLabel(status);
        option.selected = status === item.stockStatus;
        statusSelect.appendChild(option);
      });
      statusLabel.appendChild(statusSelect);
      controls.appendChild(statusLabel);

      var backorderLabel = document.createElement("label");
      backorderLabel.className = "field-label";
      backorderLabel.appendChild(createTextElement("span", "shipment-field-label", "پیش‌فروش"));
      var backorderSelect = document.createElement("select");
      [["no", "مجاز نیست"], ["notify", "با اطلاع"], ["yes", "مجاز"]].forEach(function (optionData) {
        var option = document.createElement("option");
        option.value = optionData[0];
        option.textContent = optionData[1];
        option.selected = optionData[0] === item.backorders;
        backorderSelect.appendChild(option);
      });
      backorderLabel.appendChild(backorderSelect);
      controls.appendChild(backorderLabel);

      var thresholdLabel = document.createElement("label");
      thresholdLabel.className = "field-label";
      thresholdLabel.appendChild(createTextElement("span", "shipment-field-label", "آستانهٔ کمبود"));
      var thresholdInput = document.createElement("input");
      thresholdInput.type = "text";
      thresholdInput.inputMode = "numeric";
      thresholdInput.dir = "ltr";
      thresholdInput.value = formatNumericInput(item.lowStockAmount);
      thresholdInput.placeholder = "اختیاری";
      thresholdLabel.appendChild(thresholdInput);
      controls.appendChild(thresholdLabel);
      card.appendChild(controls);

      var actions = document.createElement("div");
      actions.className = "inventory-card-actions";
      var saveButton = document.createElement("button");
      saveButton.type = "button";
      saveButton.className = "primary-button compact-button";
      saveButton.textContent = "ذخیرهٔ موجودی";
      saveButton.addEventListener("click", function () {
        var body = {
          manage_stock: Boolean(manageInput.checked),
          stock_status: statusSelect.value,
          backorders: backorderSelect.value,
          low_stock_amount: normalizeNumericInput(thresholdInput.value)
        };
        if (manageInput.checked) {
          body.stock_quantity = normalizeNumericInput(quantityInput.value);
        }
        if (item.updatedAt) {
          body.expected_updated_at = item.updatedAt;
        }
        updateInventoryItem(item.id, body, saveButton);
      });
      actions.appendChild(saveButton);
      card.appendChild(actions);
      manageInput.addEventListener("change", function () {
        quantityInput.disabled = !manageInput.checked;
      });
      elements.inventoryGrid.appendChild(card);
    });
  }

  function scheduleInventorySearch() {
    if (!elements.inventorySearch) {
      return;
    }

    state.inventory.filters.search = elements.inventorySearch.value.trim();
    if (state.inventory.searchTimer) {
      window.clearTimeout(state.inventory.searchTimer);
    }
    renderInventoryResults();
    if (state.authenticated && state.inventory.items.length && (state.inventory.status === "ready" || state.inventory.status === "empty")) {
      var inventoryMatches = filteredInventoryItems();
      setInventoryState(inventoryMatches.length ? "ready" : "empty", inventoryMatches.length ? "" : "محصولی با این عبارت در این صفحه پیدا نشد.");
    }
    state.inventory.searchTimer = window.setTimeout(function () {
      state.inventory.searchTimer = null;
      loadInventory(1);
    }, 350);
  }

  function loadInventory(page) {
    if (state.previewMode) {
      if (typeof page === "number" && isFinite(page)) {
        state.inventory.page = Math.max(1, Math.floor(page));
      }
      renderInventorySummary();
      renderInventoryResults();
      setInventoryState("ready");
      return Promise.resolve(true);
    }

    if (!state.authenticated) {
      setInventoryState("secure", "برای مدیریت موجودی ابتدا اتصال امن مدیر را برقرار کنید.");
      return Promise.resolve();
    }
    if (typeof page === "number" && isFinite(page)) {
      state.inventory.page = Math.max(1, Math.floor(page));
    }
    var url = operationCollectionUrl("inventoryUrl", state.inventory.page, {
      search: state.inventory.filters.search,
      stock_status: state.inventory.filters.stockStatus
    });
    if (!url) {
      setInventoryState("error", "نشانی API موجودی در پیکربندی سایت موجود نیست.");
      return Promise.resolve();
    }
    var requestId = state.inventory.loadRequestId + 1;
    state.inventory.loadRequestId = requestId;
    setInventoryState("loading");
    return fetchJsonWithTimeout(url).then(function (payload) {
      if (requestId !== state.inventory.loadRequestId) {
        return false;
      }
      state.inventory.items = extractInventory(payload);
      state.inventory.summary = payload && payload.summary && typeof payload.summary === "object" ? payload.summary : null;
      var meta = payload && payload.meta && typeof payload.meta === "object" ? payload.meta : {};
      state.inventory.page = Math.max(1, Number(meta.page) || state.inventory.page);
      state.inventory.total = Math.max(0, Number(meta.total) || state.inventory.items.length);
      state.inventory.totalPages = Math.max(0, Number(meta.total_pages) || (state.inventory.items.length ? 1 : 0));
      if (!filteredInventoryItems().length) {
        setInventoryState("empty", state.inventory.filters.search ? "محصولی با این فیلتر موجودی پیدا نشد." : "محصولی برای مدیریت موجودی وجود ندارد.");
        return;
      }
      renderInventorySummary();
      renderInventoryResults();
      setInventoryState("ready");
      return true;
    }).catch(function (error) {
      if (requestId !== state.inventory.loadRequestId) {
        return false;
      }
      if (error && error.status === 401) {
        setAuthenticated(false, "");
        showView("pairing");
        showPairingMessage("نشست منقضی شده است؛ دوباره جفت‌سازی کنید.");
        return;
      }
      setInventoryState("error", responseErrorMessage(error, "دریافت موجودی انجام نشد."));
    });
  }

  function updateInventoryItem(itemId, body, button) {
    if (!state.authenticated || !state.csrfToken) {
      return Promise.resolve();
    }
    var url = operationResourceUrl("inventoryUrl", itemId);
    if (!url) {
      return Promise.resolve();
    }
    var originalLabel = button.textContent;
    button.disabled = true;
    button.textContent = "در حال ذخیره";
    return fetchJsonWithTimeout(url, {
      method: "PATCH",
      headers: { "Content-Type": "application/json", "X-Fandoogh-CSRF": state.csrfToken },
      body: JSON.stringify(body)
    }).then(function () {
      return loadInventory(state.inventory.page).then(function () {
        if (elements.inventoryMessage) {
          elements.inventoryMessage.hidden = false;
          elements.inventoryMessage.textContent = "موجودی محصول ذخیره شد.";
        }
      });
    }).catch(function (error) {
      if (error && error.status === 401) {
        setAuthenticated(false, "");
        showView("pairing");
        showPairingMessage("نشست منقضی شده است؛ دوباره جفت‌سازی کنید.");
        return;
      }
      setInventoryState("error", responseErrorMessage(error, "ذخیرهٔ موجودی انجام نشد.", { inventory: true }));
    }).finally(function () {
      button.disabled = false;
      button.textContent = originalLabel;
    });
  }

  // [UI: COUPONS] فهرست، فیلتر و فرم ساخت/ویرایش کوپن.
  function normalizeCoupon(value) {
    var source = value && typeof value === "object" ? value : {};
    return {
      id: safeText(String(source.id || ""), "", 40),
      code: safeText(source.code, "بدون کد", 80),
      description: safeText(source.description, "", 255),
      discountType: safeText(source.discount_type, "fixed_cart", 30),
      amount: safeText(String(source.amount || "0"), "0", 40),
      dateExpires: safeText(source.date_expires, "", 40),
      freeShipping: Boolean(source.free_shipping),
      individualUse: Boolean(source.individual_use),
      excludeSaleItems: Boolean(source.exclude_sale_items),
      minimumAmount: safeText(String(source.minimum_amount || ""), "", 40),
      maximumAmount: safeText(String(source.maximum_amount || ""), "", 40),
      usageLimit: safeText(String(source.usage_limit || "0"), "0", 30),
      usagePerUser: safeText(String(source.usage_limit_per_user || "0"), "0", 30),
      usageCount: safeText(String(source.usage_count || "0"), "0", 30),
      status: safeText(source.status, "publish", 20)
    };
  }

  function extractCoupons(payload) {
    var rows = payload && Array.isArray(payload.data) ? payload.data : (Array.isArray(payload) ? payload : []);
    return rows.map(normalizeCoupon);
  }

  function setCouponsState(stateName, detail) {
    state.coupons.status = stateName;
    elements.couponsStateBadge.textContent = operationsStateLabel(stateName);
    elements.couponsStateBadge.setAttribute("data-state", stateName);
    elements.couponsSecureState.hidden = stateName !== "secure";
    elements.couponsLoadingState.hidden = stateName !== "loading";
    elements.couponsEmptyState.hidden = stateName !== "empty";
    elements.couponsErrorState.hidden = stateName !== "error";
    elements.couponsGrid.hidden = stateName !== "ready";
    elements.couponsPagination.hidden = stateName !== "ready";
    if (stateName === "empty") {
      elements.couponsEmptyState.querySelector("p").textContent = formatDisplayText(detail || "کوپنی برای نمایش وجود ندارد.");
    }
    if (stateName === "error" && detail) {
      elements.couponsErrorText.textContent = formatDisplayText(detail);
    }
    renderOperationPager(elements.couponsPreviousPage, elements.couponsNextPage, elements.couponsPageLabel, state.coupons.page, state.coupons.totalPages, stateName === "ready");
  }

  function couponTypeLabel(type) {
    return { percent: "درصدی", fixed_cart: "مبلغ ثابت سبد", fixed_product: "مبلغ ثابت محصول" }[type] || type;
  }

  function filteredCouponItems() {
    var query = normalizeSearchText(state.coupons.search);
    return state.coupons.items.filter(function (coupon) {
      return searchTextIncludes([coupon.id, coupon.code, coupon.description], query);
    });
  }

  function renderCouponResults() {
    while (elements.couponsGrid.firstChild) {
      elements.couponsGrid.removeChild(elements.couponsGrid.firstChild);
    }
    filteredCouponItems().forEach(function (coupon) {
      var card = document.createElement("article");
      card.className = "coupon-card";
      var heading = document.createElement("div");
      heading.className = "coupon-card-heading";
      heading.appendChild(createTextElement("h3", "coupon-card-code", coupon.code));
      heading.appendChild(createTextElement("span", "inventory-status-pill", couponTypeLabel(coupon.discountType)));
      card.appendChild(heading);
      card.appendChild(createTextElement("p", "coupon-card-amount", coupon.discountType === "percent" ? formatDisplayNumber(coupon.amount) + "٪" : formatStoreAmount(coupon.amount)));
      var metadata = ["مصرف: " + formatDisplayNumber(coupon.usageCount) + (coupon.usageLimit !== "0" ? " از " + formatDisplayNumber(coupon.usageLimit) : ""), coupon.dateExpires ? "انقضا: " + formatDisplayDate(coupon.dateExpires, false) : "بدون انقضا"];
      card.appendChild(createTextElement("p", "coupon-card-meta", metadata.join(" · ")));
      if (coupon.minimumAmount) {
        card.appendChild(createTextElement("p", "coupon-card-meta", "حداقل خرید: " + formatStoreAmount(coupon.minimumAmount)));
      }
      if (coupon.maximumAmount) {
        card.appendChild(createTextElement("p", "coupon-card-meta", "حداکثر خرید: " + formatStoreAmount(coupon.maximumAmount)));
      }
      var flags = [];
      if (coupon.freeShipping) flags.push("ارسال رایگان");
      if (coupon.individualUse) flags.push("استفادهٔ مستقل");
      if (coupon.excludeSaleItems) flags.push("بدون حراجی");
      if (flags.length) card.appendChild(createTextElement("p", "coupon-card-flags", flags.join(" · ")));
      var actions = document.createElement("div");
      actions.className = "coupon-card-actions";
      var editButton = document.createElement("button");
      editButton.type = "button";
      editButton.className = "secondary-button compact-button";
      editButton.textContent = "ویرایش";
      editButton.addEventListener("click", function () { openCouponEditor(coupon); });
      actions.appendChild(editButton);
      var deleteButton = document.createElement("button");
      deleteButton.type = "button";
      deleteButton.className = "secondary-button compact-button danger-outline-button";
      deleteButton.textContent = "حذف";
      deleteButton.addEventListener("click", function () { deleteCoupon(coupon.id, deleteButton); });
      actions.appendChild(deleteButton);
      card.appendChild(actions);
      elements.couponsGrid.appendChild(card);
    });
  }

  function setCouponEditorMessage(message, isError) {
    elements.couponEditorMessage.textContent = formatDisplayText(message || "");
    elements.couponEditorMessage.hidden = !message;
    elements.couponEditorMessage.setAttribute("data-state", isError ? "error" : "ready");
  }

  function openNewCouponEditor() {
    state.coupons.editorId = null;
    elements.couponEditorTitle.textContent = "ساخت کوپن";
    elements.couponCode.value = "";
    elements.couponType.value = "fixed_cart";
    elements.couponAmount.value = "";
    setPersianDateInputValue(elements.couponExpiry, "");
    elements.couponMinAmount.value = "";
    elements.couponMaxAmount.value = "";
    elements.couponUsageLimit.value = "";
    elements.couponUsagePerUser.value = "";
    elements.couponFreeShipping.checked = false;
    elements.couponIndividualUse.checked = false;
    elements.couponExcludeSaleItems.checked = false;
    setCouponEditorMessage("");
    elements.couponEditor.hidden = false;
    elements.couponCode.focus();
  }

  function openCouponEditor(coupon) {
    state.coupons.editorId = coupon.id;
    elements.couponEditorTitle.textContent = formatDisplayText("ویرایش کوپن " + coupon.code);
    elements.couponCode.value = coupon.code;
    elements.couponType.value = coupon.discountType;
    elements.couponAmount.value = formatNumericInput(coupon.amount);
    setPersianDateInputValue(elements.couponExpiry, coupon.dateExpires ? coupon.dateExpires.slice(0, 10) : "");
    elements.couponMinAmount.value = formatNumericInput(coupon.minimumAmount);
    elements.couponMaxAmount.value = formatNumericInput(coupon.maximumAmount);
    elements.couponUsageLimit.value = coupon.usageLimit === "0" ? "" : formatNumericInput(coupon.usageLimit);
    elements.couponUsagePerUser.value = coupon.usagePerUser === "0" ? "" : formatNumericInput(coupon.usagePerUser);
    elements.couponFreeShipping.checked = coupon.freeShipping;
    elements.couponIndividualUse.checked = coupon.individualUse;
    elements.couponExcludeSaleItems.checked = coupon.excludeSaleItems;
    setCouponEditorMessage("");
    elements.couponEditor.hidden = false;
    elements.couponCode.focus();
  }

  function closeCouponEditor() {
    state.coupons.editorId = null;
    elements.couponEditor.hidden = true;
    setCouponEditorMessage("");
  }

  function saveCoupon(event) {
    event.preventDefault();
    if (!state.authenticated || !state.csrfToken) {
      setCouponEditorMessage("نشست امن معتبر نیست؛ دوباره وارد شوید.", true);
      return;
    }
    var code = elements.couponCode.value.trim();
    var amount = normalizeNumericInput(elements.couponAmount.value.trim());
    if (!code || !amount) {
      setCouponEditorMessage("کد و مقدار تخفیف الزامی است.", true);
      return;
    }
    var body = {
      code: code,
      discount_type: elements.couponType.value,
      amount: amount,
      date_expires: getPersianDateInputValue(elements.couponExpiry),
      minimum_amount: normalizeNumericInput(elements.couponMinAmount.value.trim()),
      maximum_amount: normalizeNumericInput(elements.couponMaxAmount.value.trim()),
      usage_limit: normalizeNumericInput(elements.couponUsageLimit.value.trim()),
      usage_limit_per_user: normalizeNumericInput(elements.couponUsagePerUser.value.trim()),
      free_shipping: Boolean(elements.couponFreeShipping.checked),
      individual_use: Boolean(elements.couponIndividualUse.checked),
      exclude_sale_items: Boolean(elements.couponExcludeSaleItems.checked)
    };
    var url = state.coupons.editorId ? operationResourceUrl("couponsUrl", state.coupons.editorId) : apiUrl("couponsUrl");
    if (!url) {
      setCouponEditorMessage("نشانی API کوپن‌ها در پیکربندی سایت موجود نیست.", true);
      return;
    }
    elements.saveCouponButton.disabled = true;
    setCouponEditorMessage("در حال ذخیرهٔ کوپن...");
    fetchJsonWithTimeout(url, {
      method: state.coupons.editorId ? "PATCH" : "POST",
      headers: { "Content-Type": "application/json", "X-Fandoogh-CSRF": state.csrfToken },
      body: JSON.stringify(body)
    }).then(function () {
      closeCouponEditor();
      return loadCoupons(state.coupons.page);
    }).catch(function (error) {
      if (error && error.status === 401) {
        setAuthenticated(false, "");
        showView("pairing");
        showPairingMessage("نشست منقضی شده است؛ دوباره جفت‌سازی کنید.");
        return;
      }
      setCouponEditorMessage(responseErrorMessage(error, "ذخیرهٔ کوپن انجام نشد."), true);
    }).finally(function () {
      elements.saveCouponButton.disabled = false;
    });
  }

  function deleteCoupon(couponId, button) {
    if (!state.authenticated || !state.csrfToken || !window.confirm("این کوپن به زباله‌دان وردپرس منتقل شود؟")) {
      return Promise.resolve();
    }
    var url = operationResourceUrl("couponsUrl", couponId);
    if (!url) return Promise.resolve();
    var originalLabel = button.textContent;
    button.disabled = true;
    button.textContent = "در حال حذف";
    return fetchJsonWithTimeout(url, {
      method: "DELETE",
      headers: { "Content-Type": "application/json", "X-Fandoogh-CSRF": state.csrfToken },
      body: JSON.stringify({})
    }).then(function () {
      return loadCoupons(state.coupons.page);
    }).catch(function (error) {
      if (error && error.status === 401) {
        setAuthenticated(false, "");
        showView("pairing");
        showPairingMessage("نشست منقضی شده است؛ دوباره جفت‌سازی کنید.");
        return;
      }
      setCouponsState("error", responseErrorMessage(error, "حذف کوپن انجام نشد."));
    }).finally(function () {
      button.disabled = false;
      button.textContent = originalLabel;
    });
  }

  function scheduleCouponSearch() {
    if (!elements.couponSearch) {
      return;
    }

    state.coupons.search = elements.couponSearch.value.trim();
    if (state.coupons.searchTimer) {
      window.clearTimeout(state.coupons.searchTimer);
    }
    renderCouponResults();
    if (state.authenticated && state.coupons.items.length && (state.coupons.status === "ready" || state.coupons.status === "empty")) {
      var couponMatches = filteredCouponItems();
      setCouponsState(couponMatches.length ? "ready" : "empty", couponMatches.length ? "" : "کوپنی با این عبارت در این صفحه پیدا نشد.");
    }
    state.coupons.searchTimer = window.setTimeout(function () {
      state.coupons.searchTimer = null;
      loadCoupons(1);
    }, 350);
  }

  function loadCoupons(page) {
    if (state.previewMode) {
      if (typeof page === "number" && isFinite(page)) {
        state.coupons.page = Math.max(1, Math.floor(page));
      }
      renderCouponResults();
      setCouponsState("ready");
      return Promise.resolve(true);
    }

    if (!state.authenticated) {
      setCouponsState("secure", "برای مدیریت کوپن‌ها ابتدا اتصال امن مدیر را برقرار کنید.");
      return Promise.resolve();
    }
    if (typeof page === "number" && isFinite(page)) state.coupons.page = Math.max(1, Math.floor(page));
    var url = operationCollectionUrl("couponsUrl", state.coupons.page, { search: state.coupons.search });
    if (!url) {
      setCouponsState("error", "نشانی API کوپن‌ها در پیکربندی سایت موجود نیست.");
      return Promise.resolve();
    }
    var requestId = state.coupons.loadRequestId + 1;
    state.coupons.loadRequestId = requestId;
    setCouponsState("loading");
    return fetchJsonWithTimeout(url).then(function (payload) {
      if (requestId !== state.coupons.loadRequestId) {
        return false;
      }
      state.coupons.items = extractCoupons(payload);
      var meta = payload && payload.meta && typeof payload.meta === "object" ? payload.meta : {};
      state.coupons.page = Math.max(1, Number(meta.page) || state.coupons.page);
      state.coupons.total = Math.max(0, Number(meta.total) || state.coupons.items.length);
      state.coupons.totalPages = Math.max(0, Number(meta.total_pages) || (state.coupons.items.length ? 1 : 0));
      if (!filteredCouponItems().length) {
        setCouponsState("empty", state.coupons.search ? "کوپنی با این عبارت پیدا نشد." : "هنوز کوپنی ساخته نشده است.");
        return;
      }
      renderCouponResults();
      setCouponsState("ready");
      return true;
    }).catch(function (error) {
      if (requestId !== state.coupons.loadRequestId) {
        return false;
      }
      if (error && error.status === 401) {
        setAuthenticated(false, "");
        showView("pairing");
        showPairingMessage("نشست منقضی شده است؛ دوباره جفت‌سازی کنید.");
        return;
      }
      setCouponsState("error", responseErrorMessage(error, "دریافت کوپن‌ها انجام نشد."));
    });
  }

  // [UI: REVIEWS] فهرست دیدگاه‌ها و اکشن‌های تأیید، اسپم و پاسخ.
  function normalizeReview(value) {
    var source = value && typeof value === "object" ? value : {};
    return {
      id: safeText(String(source.id || ""), "", 40),
      productId: safeText(String(source.product_id || ""), "", 40),
      productName: safeText(source.product_name, "محصول", 160),
      author: safeText(source.author, "مهمان", 120),
      content: safeText(source.content, "", 1000),
      rating: Math.max(0, Math.min(5, Number(source.rating) || 0)),
      verified: Boolean(source.verified),
      status: safeText(source.status, "hold", 20),
      date: safeText(source.date, "", 40),
      parent: safeText(String(source.parent || "0"), "0", 40)
    };
  }

  function extractReviews(payload) {
    var rows = payload && Array.isArray(payload.data) ? payload.data : (Array.isArray(payload) ? payload : []);
    return rows.map(normalizeReview);
  }

  function reviewStatusLabel(status) {
    return { approve: "تأیید شده", hold: "در انتظار بررسی", spam: "هرزنامه", trash: "زباله‌دان" }[status] || status;
  }

  function setReviewsState(stateName, detail) {
    state.reviews.status = stateName;
    elements.reviewsStateBadge.textContent = operationsStateLabel(stateName);
    elements.reviewsStateBadge.setAttribute("data-state", stateName);
    elements.reviewsSecureState.hidden = stateName !== "secure";
    elements.reviewsLoadingState.hidden = stateName !== "loading";
    elements.reviewsEmptyState.hidden = stateName !== "empty";
    elements.reviewsErrorState.hidden = stateName !== "error";
    elements.reviewsGrid.hidden = stateName !== "ready";
    elements.reviewsPagination.hidden = stateName !== "ready";
    if (stateName === "empty") {
      elements.reviewsEmptyState.querySelector("p").textContent = formatDisplayText(detail || "دیدگاهی برای نمایش وجود ندارد.");
    }
    if (stateName === "error" && detail) elements.reviewsErrorText.textContent = formatDisplayText(detail);
    renderOperationPager(elements.reviewsPreviousPage, elements.reviewsNextPage, elements.reviewsPageLabel, state.reviews.page, state.reviews.totalPages, stateName === "ready");
  }

  function reviewAction(reviewId, body, button) {
    if (!state.authenticated || !state.csrfToken) return Promise.resolve();
    var url = operationResourceUrl("reviewsUrl", reviewId);
    if (!url) return Promise.resolve();
    var originalLabel = button.textContent;
    button.disabled = true;
    button.textContent = "در حال ذخیره";
    return fetchJsonWithTimeout(url, {
      method: "PATCH",
      headers: { "Content-Type": "application/json", "X-Fandoogh-CSRF": state.csrfToken },
      body: JSON.stringify(body)
    }).then(function () {
      return loadReviews(state.reviews.page);
    }).catch(function (error) {
      if (error && error.status === 401) {
        setAuthenticated(false, "");
        showView("pairing");
        showPairingMessage("نشست منقضی شده است؛ دوباره جفت‌سازی کنید.");
        return;
      }
      setReviewsState("error", responseErrorMessage(error, "تغییر دیدگاه انجام نشد."));
    }).finally(function () {
      button.disabled = false;
      button.textContent = originalLabel;
    });
  }

  function renderReviewResults() {
    while (elements.reviewsGrid.firstChild) elements.reviewsGrid.removeChild(elements.reviewsGrid.firstChild);
    var query = normalizeSearchText(state.reviews.search);
    state.reviews.items.filter(function (review) {
      return searchTextIncludes([review.id, review.productName, review.author, review.content], query);
    }).forEach(function (review) {
      var card = document.createElement("article");
      card.className = "review-card";
      var heading = document.createElement("div");
      heading.className = "review-card-heading";
      heading.appendChild(createTextElement("h3", "review-card-title", review.productName));
      heading.appendChild(createTextElement("span", "review-status-pill", reviewStatusLabel(review.status)));
      card.appendChild(heading);
      var stars = "★★★★★".slice(0, review.rating) + "☆☆☆☆☆".slice(0, 5 - review.rating);
      card.appendChild(createTextElement("p", "review-stars", stars + (review.verified ? " · خرید تأییدشده" : "")));
      card.appendChild(createTextElement("p", "review-card-author", review.author + (review.date ? " · " + formatDisplayDate(review.date, true) : "")));
      card.appendChild(createTextElement("p", "review-card-content", review.content || "بدون متن"));
      var actions = document.createElement("div");
      actions.className = "review-card-actions";
      [["approve", "تأیید"], ["hold", "در انتظار"], ["spam", "هرزنامه"]].forEach(function (actionData) {
        var actionButton = document.createElement("button");
        actionButton.type = "button";
        actionButton.className = "secondary-button compact-button" + (actionData[0] === "spam" ? " danger-outline-button" : "");
        actionButton.textContent = actionData[1];
        actionButton.disabled = review.status === actionData[0];
        actionButton.addEventListener("click", function () { reviewAction(review.id, { status: actionData[0] }, actionButton); });
        actions.appendChild(actionButton);
      });
      var replyButton = document.createElement("button");
      replyButton.type = "button";
      replyButton.className = "primary-button compact-button";
      replyButton.textContent = "پاسخ";
      replyButton.addEventListener("click", function () {
        var reply = window.prompt("پاسخ فروشگاه به این دیدگاه:", "");
        if (reply !== null && reply.trim()) reviewAction(review.id, { reply: reply.trim() }, replyButton);
      });
      actions.appendChild(replyButton);
      card.appendChild(actions);
      elements.reviewsGrid.appendChild(card);
    });
  }

  function filteredReviewItems() {
    var query = normalizeSearchText(state.reviews.search);
    return state.reviews.items.filter(function (review) {
      return searchTextIncludes([review.id, review.productName, review.author, review.content], query);
    });
  }

  function scheduleReviewSearch() {
    if (!elements.reviewSearch) {
      return;
    }

    state.reviews.search = elements.reviewSearch.value.trim();
    if (state.reviews.searchTimer) {
      window.clearTimeout(state.reviews.searchTimer);
    }
    renderReviewResults();
    if (state.authenticated && state.reviews.items.length && (state.reviews.status === "ready" || state.reviews.status === "empty")) {
      var reviewMatches = filteredReviewItems();
      setReviewsState(reviewMatches.length ? "ready" : "empty", reviewMatches.length ? "" : "دیدگاهی با این عبارت در این صفحه پیدا نشد.");
    }
    state.reviews.searchTimer = window.setTimeout(function () {
      state.reviews.searchTimer = null;
      loadReviews(1);
    }, 350);
  }

  function loadReviews(page) {
    if (state.previewMode) {
      if (typeof page === "number" && isFinite(page)) {
        state.reviews.page = Math.max(1, Math.floor(page));
      }
      renderReviewResults();
      setReviewsState("ready");
      return Promise.resolve(true);
    }

    if (!state.authenticated) {
      setReviewsState("secure", "برای مدیریت دیدگاه‌ها ابتدا اتصال امن مدیر را برقرار کنید.");
      return Promise.resolve();
    }
    if (typeof page === "number" && isFinite(page)) state.reviews.page = Math.max(1, Math.floor(page));
    var url = operationCollectionUrl("reviewsUrl", state.reviews.page, { search: state.reviews.search, status: state.reviews.statusFilter });
    if (!url) {
      setReviewsState("error", "نشانی API دیدگاه‌ها در پیکربندی سایت موجود نیست.");
      return Promise.resolve();
    }
    var requestId = state.reviews.loadRequestId + 1;
    state.reviews.loadRequestId = requestId;
    setReviewsState("loading");
    return fetchJsonWithTimeout(url).then(function (payload) {
      if (requestId !== state.reviews.loadRequestId) {
        return false;
      }
      state.reviews.items = extractReviews(payload);
      var meta = payload && payload.meta && typeof payload.meta === "object" ? payload.meta : {};
      state.reviews.page = Math.max(1, Number(meta.page) || state.reviews.page);
      state.reviews.total = Math.max(0, Number(meta.total) || state.reviews.items.length);
      state.reviews.totalPages = Math.max(0, Number(meta.total_pages) || (state.reviews.items.length ? 1 : 0));
      if (!filteredReviewItems().length) {
        setReviewsState("empty", state.reviews.search ? "دیدگاهی با این عبارت پیدا نشد." : "هنوز دیدگاهی برای نمایش وجود ندارد.");
        return;
      }
      renderReviewResults();
      setReviewsState("ready");
      return true;
    }).catch(function (error) {
      if (requestId !== state.reviews.loadRequestId) {
        return false;
      }
      if (error && error.status === 401) {
        setAuthenticated(false, "");
        showView("pairing");
        showPairingMessage("نشست منقضی شده است؛ دوباره جفت‌سازی کنید.");
        return;
      }
      setReviewsState("error", responseErrorMessage(error, "دریافت دیدگاه‌ها انجام نشد."));
    });
  }

  var AUDIT_EVENT_LABELS = {
    pairing_success: "اتصال موفق",
    pairing_auth_failed: "شکست احراز هویت اتصال",
    pairing_rate_limited: "محدودیت تلاش اتصال",
    pairing_replay: "تلاش مجدد کد اتصال",
    pairing_issued: "ساخت کد Pairing",
    session_created: "ساخت نشست",
    session_revoked: "ابطال نشست",
    session_revoke_all: "ابطال نشست‌های دیگر",
    access_policy_updated: "تغییر محدودیت دسترسی",
    access_removed: "حذف دسترسی کاربر",
    product_created: "ساخت محصول",
    product_updated: "ویرایش محصول",
    product_bulk_price_updated: "افزایش گروهی قیمت",
    category_created: "ساخت دسته‌بندی",
    category_updated: "ویرایش دسته‌بندی",
    variation_created: "ساخت variation",
    variation_updated: "ویرایش variation",
    order_created: "ثبت سفارش",
    order_status_updated: "تغییر وضعیت سفارش",
    media_uploaded: "آپلود رسانه",
    font_uploaded: "آپلود فونت",
    font_deleted: "حذف فونت"
  };

  // [UI: SECURITY & AUDIT] دستگاه‌های فعال، ابطال نشست و گزارش رویدادها.
  function securityStateLabel(stateName) {
    var labels = {
      secure: "نیازمند اتصال امن",
      loading: "در حال دریافت",
      empty: "بدون نشست",
      error: "خطا در دریافت",
      ready: "آماده"
    };
    return labels[stateName] || labels.secure;
  }

  function setDevicesState(stateName, detail) {
    if (!elements.securityStateBadge) {
      return;
    }
    state.security.devicesStatus = stateName;
    elements.securityStateBadge.textContent = securityStateLabel(stateName);
    elements.securityStateBadge.setAttribute("data-state", stateName);
    elements.devicesSecureState.hidden = stateName !== "secure";
    elements.devicesLoadingState.hidden = stateName !== "loading";
    elements.devicesEmptyState.hidden = stateName !== "empty";
    elements.devicesErrorState.hidden = stateName !== "error";
    elements.devicesGrid.hidden = stateName !== "ready";
    if (stateName === "empty" && detail) {
      elements.devicesEmptyText.textContent = formatDisplayText(detail);
    }
    if (stateName === "error" && detail) {
      elements.devicesErrorText.textContent = formatDisplayText(detail);
    }
  }

  function setAuditState(stateName, detail) {
    if (!elements.auditStateBadge) {
      return;
    }
    state.security.auditStatus = stateName;
    elements.auditStateBadge.textContent = stateName === "secure" ? "فقط مدیر اصلی" : securityStateLabel(stateName);
    elements.auditStateBadge.setAttribute("data-state", stateName);
    elements.auditSecureState.hidden = stateName !== "secure";
    elements.auditLoadingState.hidden = stateName !== "loading";
    elements.auditEmptyState.hidden = stateName !== "empty";
    elements.auditErrorState.hidden = stateName !== "error";
    elements.auditGrid.hidden = stateName !== "ready";
    if (stateName === "empty" && detail) {
      elements.auditEmptyText.textContent = formatDisplayText(detail);
    }
    if (stateName === "error" && detail) {
      elements.auditErrorText.textContent = formatDisplayText(detail);
    }
    updateAuditPager();
  }

  function securityDateLabel(value) {
    var raw = safeText(String(value || ""), "تاریخ ثبت نشده", 40);
    if (raw === "تاریخ ثبت نشده") {
      return raw;
    }
    try {
      var candidate = raw;
      if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(raw)) {
        candidate = raw.replace(" ", "T") + "Z";
      }
      return formatDisplayDate(candidate, /[T\s]\d{2}:\d{2}/.test(candidate));
    } catch (error) {
      // Keep the sanitized server timestamp when localized formatting is not available.
    }
    return raw;
  }

  function normalizeDevice(value) {
    var source = value && typeof value === "object" ? value : {};
    return {
      id: safeText(String(source.id || ""), "", 40),
      label: safeText(source.device_label, "مرورگر ناشناس", 120),
      status: safeText(source.status, "active", 20),
      createdAt: securityDateLabel(source.created_at),
      lastSeenAt: securityDateLabel(source.last_seen_at),
      expiresAt: securityDateLabel(source.expires_at),
      revokedAt: source.revoked_at ? securityDateLabel(source.revoked_at) : "",
      current: Boolean(source.current)
    };
  }

  function extractDevices(payload) {
    if (Array.isArray(payload)) {
      return payload.map(normalizeDevice);
    }
    if (payload && Array.isArray(payload.data)) {
      return payload.data.map(normalizeDevice);
    }
    return [];
  }

  function deviceStatusLabel(device) {
    if (device.current && device.status === "active") {
      return "نشست فعلی";
    }
    if (device.status === "active") {
      return "فعال";
    }
    if (device.status === "expired") {
      return "منقضی‌شده";
    }
    return "باطل‌شده";
  }

  function sessionAlertNotice() {
    var count = state.security.newSessionsCount || 0;
    if (!count) {
      return null;
    }
    var label = count === 1 ? "یک نشست جدید" : formatDisplayNumber(count) + " نشست جدید";
    return "⚠ هشدار: " + label + " پس از ورود شما ساخته شده است. اگر این دستگاه‌ها مال شما نیستند، آن‌ها را باطل کنید.";
  }

  function renderSessionAlert() {
    var message = sessionAlertNotice();
    if (!elements.sessionAlertPanel) {
      return;
    }
    elements.sessionAlertPanel.textContent = message || "";
    elements.sessionAlertPanel.hidden = !message;
  }

  function acknowledgeNewSessions() {
    var url = apiUrl("acknowledgeUrl");
    if (!url || !state.authenticated || !state.csrfToken) {
      return Promise.resolve();
    }
    return fetchJsonWithTimeout(url, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-Fandoogh-CSRF": state.csrfToken },
      body: JSON.stringify({})
    }).catch(function () {
      // Non-critical: the warning simply reappears on the next devices load.
    });
  }

  function renderDevices(items) {
    while (elements.devicesGrid.firstChild) {
      elements.devicesGrid.removeChild(elements.devicesGrid.firstChild);
    }

    items.forEach(function (device) {
      var card = document.createElement("article");
      card.className = "device-card" + (device.current ? " is-current" : "");
      var heading = document.createElement("div");
      heading.className = "device-card-heading";
      heading.appendChild(createTextElement("h3", "device-card-name", device.label));
      heading.appendChild(createTextElement("span", "device-status", deviceStatusLabel(device)));
      card.appendChild(heading);
      card.appendChild(createTextElement("p", "device-card-line", "شروع: " + device.createdAt));
      card.appendChild(createTextElement("p", "device-card-line", "آخرین فعالیت: " + device.lastSeenAt));
      card.appendChild(createTextElement("p", "device-card-line", "انقضا: " + device.expiresAt));

      if (device.revokedAt) {
        card.appendChild(createTextElement("p", "device-card-line device-card-muted", "ابطال: " + device.revokedAt));
      }

      var action = document.createElement("button");
      action.type = "button";
      action.className = "secondary-button compact-button" + (device.current ? "" : " danger-outline-button");
      action.textContent = device.current ? "دستگاه فعلی" : (device.status === "active" ? "ابطال نشست" : "قبلاً غیرفعال شده");
      action.disabled = device.current || device.status !== "active";
      if (!action.disabled) {
        action.addEventListener("click", function () {
          revokeDevice(device.id, action);
        });
      }
      card.appendChild(action);
      elements.devicesGrid.appendChild(card);
    });
  }

  function devicesResourceUrl(deviceId) {
    var base = apiUrl("devicesUrl");
    deviceId = String(deviceId || "");
    if (!base || !/^\d+$/.test(deviceId) || deviceId === "0") {
      return "";
    }
    try {
      return new URL(deviceId + "/revoke/", base.replace(/\/?$/, "/")).toString();
    } catch (error) {
      return "";
    }
  }

  function loadDevices() {
    if (state.previewMode) {
      renderDevices(state.security.devices);
      setDevicesState("ready");
      return Promise.resolve(true);
    }

    if (!state.authenticated) {
      setDevicesState("secure", "برای مشاهدهٔ نشست‌ها، ابتدا اتصال امن مدیر را برقرار کنید.");
      return Promise.resolve();
    }
    var devicesUrl = apiUrl("devicesUrl");
    if (!devicesUrl) {
      setDevicesState("error", "نشانی API دستگاه‌ها در پیکربندی سایت موجود نیست.");
      return Promise.resolve();
    }
    setDevicesState("loading");
    return fetchJsonWithTimeout(devicesUrl).then(function (payload) {
      state.security.devices = extractDevices(payload);
      var meta = payload && typeof payload === "object" ? payload.meta : null;
      if (meta && typeof meta === "object" && typeof meta.new_sessions !== "undefined") {
        state.security.newSessionsCount = Math.max(0, Number(meta.new_sessions) || 0);
      } else {
        state.security.newSessionsCount = 0;
      }
      renderSessionAlert();
      if (!state.security.devices.length) {
        setDevicesState("empty", "نشست فعالی برای این مدیر ثبت نشده است.");
        return;
      }
      renderDevices(state.security.devices);
      setDevicesState("ready");
    }).catch(function (error) {
      if (error && error.status === 401) {
        setAuthenticated(false, "");
        showView("pairing");
        showPairingMessage("نشست منقضی شده است؛ دوباره جفت‌سازی کنید.");
        return;
      }
      setDevicesState("error", responseErrorMessage(error, "فهرست دستگاه‌ها دریافت نشد."));
    });
  }

  function revokeDevice(deviceId, button) {
    if (!state.authenticated || !state.csrfToken) {
      return Promise.resolve();
    }
    if (!window.confirm("این نشست از دسترسی به اپ خارج می‌شود. ادامه می‌دهید؟")) {
      return Promise.resolve();
    }
    var url = devicesResourceUrl(deviceId);
    if (!url) {
      return Promise.resolve();
    }
    var originalLabel = button.textContent;
    button.disabled = true;
    button.textContent = "در حال ابطال";
    return fetchJsonWithTimeout(url, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-Fandoogh-CSRF": state.csrfToken },
      body: JSON.stringify({})
    }).then(function () {
      return Promise.all([loadDevices(), loadAudit()]);
    }).catch(function (error) {
      if (error && error.status === 401) {
        setAuthenticated(false, "");
        showView("pairing");
        showPairingMessage("نشست منقضی شده است؛ دوباره جفت‌سازی کنید.");
        return;
      }
      setDevicesState("error", responseErrorMessage(error, "ابطال نشست انجام نشد."));
    }).finally(function () {
      button.disabled = false;
      button.textContent = originalLabel;
    });
  }

  function revokeOtherDevices() {
    if (!state.authenticated || !state.csrfToken) {
      return Promise.resolve();
    }
    var devicesUrl = apiUrl("devicesUrl");
    if (!devicesUrl || !window.confirm("همهٔ نشست‌های غیر از دستگاه فعلی باطل می‌شوند. ادامه می‌دهید؟")) {
      return Promise.resolve();
    }
    elements.revokeOtherDevices.disabled = true;
    var originalLabel = elements.revokeOtherDevices.textContent;
    elements.revokeOtherDevices.textContent = "در حال ابطال";
    var url = devicesUrl.replace(/\/?$/, "/") + "revoke-all/";
    return fetchJsonWithTimeout(url, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-Fandoogh-CSRF": state.csrfToken },
      body: JSON.stringify({})
    }).then(function () {
      return Promise.all([loadDevices(), loadAudit()]);
    }).catch(function (error) {
      if (error && error.status === 401) {
        setAuthenticated(false, "");
        showView("pairing");
        showPairingMessage("نشست منقضی شده است؛ دوباره جفت‌سازی کنید.");
        return;
      }
      setDevicesState("error", responseErrorMessage(error, "ابطال نشست‌های دیگر انجام نشد."));
    }).finally(function () {
      elements.revokeOtherDevices.disabled = false;
      elements.revokeOtherDevices.textContent = originalLabel;
    });
  }

  function normalizeAudit(value) {
    var source = value && typeof value === "object" ? value : {};
    var context = source.context && typeof source.context === "object" ? source.context : {};
    return {
      id: safeText(String(source.id || ""), "", 40),
      event: safeText(source.event_type, "unknown", 80),
      resourceType: safeText(source.resource_type, "", 50),
      resourceId: safeText(String(source.resource_id || "0"), "0", 40),
      device: safeText(source.device_label, "مرورگر ناشناس", 120),
      createdAt: securityDateLabel(source.created_at),
      context: context
    };
  }

  function extractAudit(payload) {
    if (Array.isArray(payload)) {
      return payload.map(normalizeAudit);
    }
    if (payload && Array.isArray(payload.data)) {
      return payload.data.map(normalizeAudit);
    }
    return [];
  }

  function auditContextLabel(context) {
    var labels = {
      outcome: "نتیجه",
      reason: "دلیل",
      count: "تعداد",
      target_session_id: "نشست هدف",
      attachment_id: "رسانه",
      order_id: "سفارش",
      product_id: "محصول",
      category_id: "دسته‌بندی",
      variation_id: "variation",
      customer_id: "مشتری",
      file_type: "نوع فایل",
      status: "وضعیت"
    };
    return Object.keys(context).map(function (key) {
      if (!Object.prototype.hasOwnProperty.call(labels, key)) {
        return "";
      }
      return labels[key] + ": " + safeText(String(context[key]), "—", 160);
    }).filter(Boolean).join("، ");
  }

  function renderAudit(items) {
    while (elements.auditGrid.firstChild) {
      elements.auditGrid.removeChild(elements.auditGrid.firstChild);
    }
    items.forEach(function (item) {
      var card = document.createElement("article");
      card.className = "audit-card";
      card.appendChild(createTextElement("h4", "audit-card-title", AUDIT_EVENT_LABELS[item.event] || item.event));
      card.appendChild(createTextElement("p", "audit-card-line", item.device + " · " + item.createdAt));
      if (item.resourceType && item.resourceId !== "0") {
        card.appendChild(createTextElement("p", "audit-card-line", "منبع: " + item.resourceType + " #" + item.resourceId));
      }
      var contextText = auditContextLabel(item.context);
      if (contextText) {
        card.appendChild(createTextElement("p", "audit-card-line", contextText));
      }
      elements.auditGrid.appendChild(card);
    });
  }

  function updateAuditPager() {
    if (!elements.auditPreviousPage || !elements.auditNextPage) {
      return;
    }
    var ready = state.security.auditStatus === "ready";
    elements.auditPreviousPage.disabled = !ready || state.security.auditPage <= 1;
    elements.auditNextPage.disabled = !ready || state.security.auditPage >= state.security.auditTotalPages;
  }

  function auditUrlForPage(page) {
    var base = apiUrl("auditUrl");
    if (!base) {
      return "";
    }
    try {
      var url = new URL(base);
      url.searchParams.set("page", String(Math.max(1, Number(page) || 1)));
      url.searchParams.set("per_page", "30");
      if (state.security.auditEvent) {
        url.searchParams.set("event", state.security.auditEvent);
      }
      return url.toString();
    } catch (error) {
      return "";
    }
  }

  function loadAudit(page) {
    if (state.previewMode) {
      state.security.auditPage = Math.max(1, Number(page) || 1);
      renderAudit(state.security.auditItems);
      setAuditState("ready");
      return Promise.resolve(true);
    }

    if (!state.authenticated) {
      setAuditState("secure", "برای مشاهدهٔ گزارش، ابتدا اتصال امن مدیر را برقرار کنید.");
      return Promise.resolve();
    }
    var auditUrl = apiUrl("auditUrl");
    if (!auditUrl) {
      setAuditState("error", "نشانی API گزارش در پیکربندی سایت موجود نیست.");
      return Promise.resolve();
    }
    state.security.auditPage = Math.max(1, Number(page) || 1);
    if (elements.auditEventFilter) {
      state.security.auditEvent = elements.auditEventFilter.value;
    }
    setAuditState("loading");
    return fetchJsonWithTimeout(auditUrlForPage(state.security.auditPage)).then(function (payload) {
      state.security.auditItems = extractAudit(payload);
      var meta = payload && payload.meta && typeof payload.meta === "object" ? payload.meta : {};
      state.security.auditTotalPages = Math.max(0, Number(meta.total_pages) || (state.security.auditItems.length ? state.security.auditPage : 0));
      if (!state.security.auditItems.length) {
        setAuditState("empty", "برای این فیلتر رویدادی ثبت نشده است.");
        return;
      }
      renderAudit(state.security.auditItems);
      setAuditState("ready");
    }).catch(function (error) {
      if (error && error.status === 401) {
        setAuthenticated(false, "");
        showView("pairing");
        showPairingMessage("نشست منقضی شده است؛ دوباره جفت‌سازی کنید.");
        return;
      }
      if (error && error.status === 403) {
        setAuditState("error", "این نشست مجوز مشاهدهٔ گزارش رویدادها را ندارد.");
        return;
      }
      setAuditState("error", responseErrorMessage(error, "دریافت گزارش رویدادها ناموفق بود."));
    });
  }

  function loadSecurity() {
    return Promise.all([loadDevices(), loadAudit()]);
  }

  // [APP: PWA LIFECYCLE] ثبت service worker و مدیریت به‌روزرسانی پوسته.
  function registerServiceWorker() {
    if (!("serviceWorker" in navigator)) {
      return;
    }

    navigator.serviceWorker.addEventListener("controllerchange", function () {
      if (state.pwa.updateReloading) {
        window.location.reload();
      }
    });

    // The server exposes this local file through the plugin. A registration
    // failure must not block the UI.
    navigator.serviceWorker.register(SERVICE_WORKER_URL, { scope: MANAGER_SCOPE }).then(function (registration) {
      state.pwa.registration = registration;

      if (typeof registration.update === "function") {
        registration.update().catch(function () {
          // A failed background update must not block the already loaded app.
        });
      }

      if (registration.waiting && navigator.serviceWorker.controller) {
        showUpdateBanner();
      }

      registration.addEventListener("updatefound", function () {
        var installing = registration.installing;
        if (!installing) {
          return;
        }

        installing.addEventListener("statechange", function () {
          if (installing.state === "installed" && navigator.serviceWorker.controller) {
            showUpdateBanner();
          }
        });
      });
    }).catch(function () {
      // Offline support is optional; the application shell remains usable.
    });
  }

  // [UI: EVENT WIRING] اتصال کلیک‌ها، submitها، فیلترها، تب‌ها و میانبرها به رفتار برنامه.
  function bindEvents() {
    elements.pairingForm.addEventListener("submit", function (event) {
      event.preventDefault();
      if (!state.authBusy) {
        pairSession();
      }
    });

    elements.previewDashboard.addEventListener("click", function () {
      activatePreviewMode();
    });

    elements.backToPairing.addEventListener("click", function () {
      if (state.previewMode) {
        setPreviewMode(false);
        setAuthenticated(false, "");
        applyConfig(normalizeConfig(DEFAULT_CONFIG));
        setConfigSource("fallback");
        setConnectionState("fallback", "اتصال سایت برقرار نیست", "برای مشاهدهٔ اطلاعات واقعی، اتصال امن فروشگاه را برقرار کنید.");
      }
      showView("pairing");
    });

    elements.logoutButton.addEventListener("click", logoutSession);
    if (elements.notificationsButton && elements.notificationsPanel) {
      elements.notificationsButton.addEventListener("click", function () {
        var shouldOpen = elements.notificationsPanel.hidden;
        elements.notificationsPanel.hidden = !shouldOpen;
        elements.notificationsButton.setAttribute("aria-expanded", String(shouldOpen));
      });
    }
    if (elements.notificationsOpenOrders) {
      elements.notificationsOpenOrders.addEventListener("click", function () {
        if (elements.notificationsPanel) {
          elements.notificationsPanel.hidden = true;
        }
        if (elements.notificationsButton) {
          elements.notificationsButton.setAttribute("aria-expanded", "false");
        }
        navigateToSection("orders");
      });
    }
    elements.connectionControl.addEventListener("click", toggleConnectionPopover);
    elements.installAppButton.addEventListener("click", handleInstallApp);
    elements.closeInstallGuide.addEventListener("click", closeInstallGuide);
    elements.applyAppUpdate.addEventListener("click", applyAppUpdate);
    bindSearchInput(elements.productSearch, scheduleProductSearch);
    elements.globalSearch.addEventListener("input", renderGlobalSearchResults);
    elements.globalSearch.addEventListener("focus", renderGlobalSearchResults);
    elements.globalSearch.addEventListener("search", renderGlobalSearchResults);
    elements.globalSearch.addEventListener("change", renderGlobalSearchResults);
    elements.globalSearch.addEventListener("keydown", handleGlobalSearchKeydown);
    if (elements.globalSearchResults) {
      elements.globalSearchResults.addEventListener("click", function (event) {
        var target = event.target;
        while (target && target !== elements.globalSearchResults && !target.hasAttribute("data-global-search-index")) {
          target = target.parentNode;
        }
        if (!target || target === elements.globalSearchResults) {
          return;
        }
        var index = Number(target.getAttribute("data-global-search-index"));
        if (!isFinite(index) || !state.globalSearch.results[index]) {
          return;
        }
        openGlobalSearchResult(state.globalSearch.results[index], elements.globalSearch.value.trim());
      });
    }
    document.addEventListener("click", function (event) {
      if (!elements.globalSearch || !elements.globalSearchResults || elements.globalSearchResults.hidden) {
        return;
      }
      if (event.target === elements.globalSearch || elements.globalSearchResults.contains(event.target)) {
        return;
      }
      closeGlobalSearchResults();
    });
    elements.newProductButton.addEventListener("click", openNewProductEditor);
    elements.openBulkPrice.addEventListener("click", openBulkPricePanel);
    elements.closeBulkPrice.addEventListener("click", closeBulkPricePanel);
    elements.bulkPriceForm.addEventListener("submit", function (event) {
      event.preventDefault();
      submitBulkPrice(false);
    });
    elements.executeBulkPrice.addEventListener("click", function () { submitBulkPrice(true); });
    elements.resetBulkPrice.addEventListener("click", resetBulkPriceForm);
    elements.bulkPriceType.addEventListener("change", syncBulkPriceType);
    elements.bulkPriceCategories.addEventListener("change", invalidateBulkPricePreview);
    elements.bulkPriceAmount.addEventListener("input", invalidateBulkPricePreview);
    elements.bulkPriceIncludeChildren.addEventListener("change", invalidateBulkPricePreview);
    elements.cancelProductEdit.addEventListener("click", closeProductEditor);
    elements.productEditorOverlay.addEventListener("click", function (event) {
      if (event.target === elements.productEditorOverlay || (event.target.classList && event.target.classList.contains("product-editor-backdrop"))) {
        closeProductEditor();
      }
    });
    elements.closeProductView.addEventListener("click", function () { closeProductView(); });
    elements.productViewCloseFooter.addEventListener("click", function () { closeProductView(); });
    elements.productViewEdit.addEventListener("click", editProductFromView);
    elements.productViewOverlay.addEventListener("click", function (event) {
      if (event.target === elements.productViewOverlay || (event.target.classList && event.target.classList.contains("product-view-backdrop"))) {
        closeProductView();
      }
    });
    elements.productViewTabs.addEventListener("click", function (event) {
      var button = event.target;
      while (button && button !== elements.productViewTabs && !button.hasAttribute("data-product-view-tab")) {
        button = button.parentNode;
      }
      if (button && button !== elements.productViewTabs && !button.hidden) {
        setProductViewTab(button.getAttribute("data-product-view-tab"), false);
      }
    });
    elements.productViewTabs.addEventListener("keydown", function (event) {
      if (event.key !== "ArrowLeft" && event.key !== "ArrowRight") {
        return;
      }
      var buttons = Array.prototype.filter.call(elements.productViewTabs.querySelectorAll("[data-product-view-tab]"), function (button) { return !button.hidden; });
      var index = buttons.indexOf(document.activeElement);
      if (index === -1) return;
      event.preventDefault();
      var nextIndex = event.key === "ArrowLeft" ? index + 1 : index - 1;
      if (nextIndex < 0) nextIndex = buttons.length - 1;
      if (nextIndex >= buttons.length) nextIndex = 0;
      setProductViewTab(buttons[nextIndex].getAttribute("data-product-view-tab"), true);
    });
    elements.productEditor.addEventListener("submit", saveProduct);
    elements.productEditor.addEventListener("input", markProductEditorDirty);
    elements.productEditor.addEventListener("change", markProductEditorDirty);
    elements.productName.addEventListener("input", function () {
      elements.productName.removeAttribute("aria-invalid");
      syncProductSlug();
      if (elements.productEditorMessage.getAttribute("data-state") === "error") {
        setProductEditorMessage("");
      }
    });
    elements.productType.addEventListener("change", syncProductEditorType);
    elements.productKind.addEventListener("change", syncProductKind);
    elements.productCategory.addEventListener("change", function () {
      var previousPrimary = elements.productCategory.dataset.previousValue || "";
      var previousSubcategory = elements.productSubcategory.dataset.previousValue || "";
      state.products.editorCategoryIds = state.products.editorCategoryIds.filter(function (value) {
        return value !== previousPrimary && value !== previousSubcategory;
      });
      if (elements.productCategory.value) {
        state.products.editorCategoryIds.push(String(elements.productCategory.value));
      }
      syncProductSubcategoryOptions(elements.productCategory.value || "0", "");
      elements.productCategory.dataset.previousValue = elements.productCategory.value;
      elements.productSubcategory.dataset.previousValue = "";
      setSelectedProductCategories(state.products.editorCategoryIds);
    });
    elements.productSubcategory.addEventListener("change", function () {
      var previousSubcategory = elements.productSubcategory.dataset.previousValue || "";
      state.products.editorCategoryIds = state.products.editorCategoryIds.filter(function (value) {
        return value !== previousSubcategory;
      });
      if (elements.productSubcategory.value) {
        state.products.editorCategoryIds.push(String(elements.productSubcategory.value));
      }
      elements.productSubcategory.dataset.previousValue = elements.productSubcategory.value;
      setSelectedProductCategories(state.products.editorCategoryIds);
    });
    elements.productCategories.addEventListener("change", function () {
      state.products.editorCategoryIds = Array.prototype.map.call(elements.productCategories.selectedOptions || [], function (option) {
        return String(option.value);
      }).filter(Boolean);
      syncProductCategoryFields(state.products.editorCategoryIds);
    });
    elements.addProductAttribute.addEventListener("click", function () {
      var rows = collectProductAttributeRows();
      rows.push({ name: "", label: "", options: [], visible: true, variation: elements.productType.value === "variable" });
      renderProductAttributeRows(rows);
    });
    elements.uploadProductMedia.addEventListener("click", uploadProductMedia);
    elements.refreshProducts.addEventListener("click", loadProducts);
    elements.productFilterToggle.addEventListener("click", toggleProductFilterPanel);
    if (elements.productFilterBackdrop) {
      elements.productFilterBackdrop.addEventListener("click", closeProductFilterPanel);
    }
    elements.closeProductFilters.addEventListener("click", closeProductFilterPanel);
    elements.applyProductFilters.addEventListener("click", applyProductFilters);
    elements.resetProductFilters.addEventListener("click", resetProductFilters);
    bindSearchInput(elements.categorySearch, renderCategoryResults);
    elements.newCategoryButton.addEventListener("click", openNewCategoryEditor);
    elements.cancelCategoryEdit.addEventListener("click", closeCategoryEditor);
    elements.categoryEditor.addEventListener("submit", saveCategory);
    elements.refreshCategories.addEventListener("click", loadCategories);
    elements.refreshVariations.addEventListener("click", function () {
      if (state.variations.parentId) {
        loadVariations(state.variations.parentId);
      }
    });
    elements.cancelVariationEdit.addEventListener("click", clearVariationEditor);
    elements.saveVariationButton.addEventListener("click", saveVariation);
    bindSearchInput(elements.orderSearch, scheduleOrderSearch);
    elements.orderStatusFilter.addEventListener("change", function () {
      selectOrderStatus(elements.orderStatusFilter.value || "any");
    });
    elements.orderFilterToggle.addEventListener("click", toggleOrderFilterPanel);
    if (elements.orderFilterBackdrop) {
      elements.orderFilterBackdrop.addEventListener("click", closeOrderFilterPanel);
    }
    elements.closeOrderFilters.addEventListener("click", closeOrderFilterPanel);
    elements.applyOrderFilters.addEventListener("click", applyOrderFilters);
    elements.resetOrderFilters.addEventListener("click", resetOrderFilters);
    elements.refreshOrders.addEventListener("click", function () { loadOrders(state.orders.page || 1); });
    elements.newOrderButton.addEventListener("click", openManualOrder);
    elements.ordersPreviousPage.addEventListener("click", function () {
      if (state.orders.page > 1) loadOrders(state.orders.page - 1);
    });
    elements.ordersNextPage.addEventListener("click", function () {
      if (state.orders.page < state.orders.totalPages) loadOrders(state.orders.page + 1);
    });
    elements.closeOrderDetail.addEventListener("click", closeOrderDetail);
    elements.orderDetailCloseFooter.addEventListener("click", closeOrderDetail);
    elements.orderDetailEdit.addEventListener("click", function () {
      openOrderEditor(state.orders.detailId, elements.orderDetailEdit, true);
    });
    elements.orderDetailPanel.addEventListener("click", function (event) {
      if (event.target === elements.orderDetailPanel || (event.target.classList && event.target.classList.contains("order-detail-backdrop"))) {
        closeOrderDetail();
      }
    });
    elements.closeOrderEdit.addEventListener("click", function () { closeOrderEdit(false); });
    elements.closeOrderEditFooter.addEventListener("click", function () { closeOrderEdit(false); });
    elements.saveOrderEdit.addEventListener("click", saveOrderEditChanges);
    elements.orderEditPanel.addEventListener("click", function (event) {
      if (event.target === elements.orderEditPanel || (event.target.classList && event.target.classList.contains("order-edit-backdrop"))) {
        closeOrderEdit(false);
      }
    });
    Array.prototype.forEach.call(document.querySelectorAll("[data-order-view-tab]"), function (button) {
      button.addEventListener("click", function () {
        setOrderViewTab(button.getAttribute("data-order-view-tab"), false);
      });
      button.addEventListener("keydown", function (event) {
        if (event.key !== "ArrowLeft" && event.key !== "ArrowRight") return;
        var buttons = Array.prototype.slice.call(elements.orderViewTabs.querySelectorAll("[data-order-view-tab]"));
        var index = buttons.indexOf(button);
        if (index < 0) return;
        event.preventDefault();
        var nextIndex = event.key === "ArrowRight" ? index + 1 : index - 1;
        if (nextIndex < 0) nextIndex = buttons.length - 1;
        if (nextIndex >= buttons.length) nextIndex = 0;
        setOrderViewTab(buttons[nextIndex].getAttribute("data-order-view-tab"), true);
      });
    });
    Array.prototype.forEach.call(document.querySelectorAll("[data-order-edit-tab]"), function (button) {
      button.addEventListener("click", function () {
        setOrderEditTab(button.getAttribute("data-order-edit-tab"), false);
      });
      button.addEventListener("keydown", function (event) {
        if (event.key !== "ArrowLeft" && event.key !== "ArrowRight") return;
        var buttons = Array.prototype.slice.call(elements.orderEditTabs.querySelectorAll("[data-order-edit-tab]"));
        var index = buttons.indexOf(button);
        if (index < 0) return;
        event.preventDefault();
        var nextIndex = event.key === "ArrowRight" ? index + 1 : index - 1;
        if (nextIndex < 0) nextIndex = buttons.length - 1;
        if (nextIndex >= buttons.length) nextIndex = 0;
        setOrderEditTab(buttons[nextIndex].getAttribute("data-order-edit-tab"), true);
      });
    });
    elements.closeManualOrder.addEventListener("click", closeManualOrder);
    elements.manualOrderOverlay.addEventListener("click", function (event) {
      if (event.target === elements.manualOrderOverlay || (event.target.classList && event.target.classList.contains("manual-order-backdrop"))) {
        closeManualOrder();
      }
    });
    elements.manualOrderDialog.addEventListener("submit", submitManualOrder);
    elements.manualOrderPrevious.addEventListener("click", function () {
      if (!state.manualOrder.busy && state.manualOrder.step > 0) {
        state.manualOrder.step -= 1;
        setManualOrderMessage("");
        renderManualOrderStep();
      }
    });
    elements.manualOrderNext.addEventListener("click", function () {
      if (state.manualOrder.busy) return;
      if (!validateManualOrderStep(state.manualOrder.step)) return;
      state.manualOrder.step = Math.min(3, state.manualOrder.step + 1);
      renderManualOrderStep();
    });
    Array.prototype.forEach.call(document.querySelectorAll(".manual-order-step-button"), function (button) {
      button.addEventListener("click", function () {
        var targetStep = Number(button.getAttribute("data-manual-order-step-index"));
        if (!state.manualOrder.busy && isFinite(targetStep) && targetStep <= state.manualOrder.step) {
          state.manualOrder.step = targetStep;
          setManualOrderMessage("");
          renderManualOrderStep();
        }
      });
    });
    bindSearchInput(elements.customerSearch, scheduleCustomerSearch);
    elements.refreshCustomers.addEventListener("click", function () { loadCustomers(state.customers.page || 1); });
    elements.customersPreviousPage.addEventListener("click", function () {
      if (state.customers.page > 1) loadCustomers(state.customers.page - 1);
    });
    elements.customersNextPage.addEventListener("click", function () {
      if (state.customers.page < state.customers.totalPages) loadCustomers(state.customers.page + 1);
    });
    elements.closeCustomerDetail.addEventListener("click", closeCustomerDetail);
    bindSearchInput(elements.inventorySearch, scheduleInventorySearch);
    elements.inventoryStatusFilter.addEventListener("change", function () {
      state.inventory.filters.stockStatus = elements.inventoryStatusFilter.value;
      loadInventory(1);
    });
    elements.refreshInventory.addEventListener("click", function () { loadInventory(state.inventory.page || 1); });
    elements.inventoryPreviousPage.addEventListener("click", function () {
      if (state.inventory.page > 1) loadInventory(state.inventory.page - 1);
    });
    elements.inventoryNextPage.addEventListener("click", function () {
      if (state.inventory.page < state.inventory.totalPages) loadInventory(state.inventory.page + 1);
    });
    bindSearchInput(elements.couponSearch, scheduleCouponSearch);
    elements.newCouponButton.addEventListener("click", openNewCouponEditor);
    elements.refreshCoupons.addEventListener("click", function () { loadCoupons(state.coupons.page || 1); });
    elements.couponEditor.addEventListener("submit", saveCoupon);
    elements.cancelCouponEdit.addEventListener("click", closeCouponEditor);
    elements.couponsPreviousPage.addEventListener("click", function () {
      if (state.coupons.page > 1) loadCoupons(state.coupons.page - 1);
    });
    elements.couponsNextPage.addEventListener("click", function () {
      if (state.coupons.page < state.coupons.totalPages) loadCoupons(state.coupons.page + 1);
    });
    bindSearchInput(elements.reviewSearch, scheduleReviewSearch);
    elements.reviewStatusFilter.addEventListener("change", function () {
      state.reviews.statusFilter = elements.reviewStatusFilter.value;
      loadReviews(1);
    });
    elements.refreshReviews.addEventListener("click", function () { loadReviews(state.reviews.page || 1); });
    elements.reviewsPreviousPage.addEventListener("click", function () {
      if (state.reviews.page > 1) loadReviews(state.reviews.page - 1);
    });
    elements.reviewsNextPage.addEventListener("click", function () {
      if (state.reviews.page < state.reviews.totalPages) loadReviews(state.reviews.page + 1);
    });
    elements.mobileMenuToggle.addEventListener("click", toggleSidebar);
    elements.sidebarScrim.addEventListener("click", closeSidebar);
    document.addEventListener("click", function (event) {
      if (elements.productFilterPanel && !elements.productFilterPanel.hidden && elements.productFilterToggle) {
        if (!elements.productFilterPanel.contains(event.target) && !elements.productFilterToggle.contains(event.target)) {
          closeProductFilterPanel();
        }
      }
      if (!elements.orderFilterPanel || elements.orderFilterPanel.hidden || !elements.orderFilterToggle) {
        return;
      }
      if (!elements.orderFilterPanel.contains(event.target) && !elements.orderFilterToggle.contains(event.target)) {
        closeOrderFilterPanel();
      }
    });
    document.addEventListener("keydown", function (event) {
      if (elements.manualOrderOverlay && !elements.manualOrderOverlay.hidden) {
        if (event.key === "Escape") {
          event.preventDefault();
          closeManualOrder();
          return;
        }

        if (event.key === "Tab") {
          var manualOrderFocusable = Array.prototype.filter.call(elements.manualOrderOverlay.querySelectorAll("button, a, input, select, textarea, [tabindex]:not([tabindex='-1'])"), function (node) {
            return !node.disabled && !node.hidden && node.offsetParent !== null;
          });
          if (manualOrderFocusable.length) {
            var manualOrderFirst = manualOrderFocusable[0];
            var manualOrderLast = manualOrderFocusable[manualOrderFocusable.length - 1];
            if (event.shiftKey && (document.activeElement === manualOrderFirst || !elements.manualOrderOverlay.contains(document.activeElement))) {
              event.preventDefault();
              manualOrderLast.focus();
            } else if (!event.shiftKey && (document.activeElement === manualOrderLast || !elements.manualOrderOverlay.contains(document.activeElement))) {
              event.preventDefault();
              manualOrderFirst.focus();
            }
          }
          return;
        }
      }

      if (elements.orderDetailPanel && !elements.orderDetailPanel.hidden) {
        if (event.key === "Escape") {
          event.preventDefault();
          closeOrderDetail();
          return;
        }

        if (event.key === "Tab") {
          var orderFocusable = Array.prototype.filter.call(elements.orderDetailPanel.querySelectorAll("button, a, input, select, textarea, [tabindex]:not([tabindex='-1'])"), function (node) {
            return !node.disabled && !node.hidden && node.offsetParent !== null;
          });
          if (orderFocusable.length) {
            var orderFirst = orderFocusable[0];
            var orderLast = orderFocusable[orderFocusable.length - 1];
            if (event.shiftKey && (document.activeElement === orderFirst || !elements.orderDetailPanel.contains(document.activeElement))) {
              event.preventDefault();
              orderLast.focus();
            } else if (!event.shiftKey && (document.activeElement === orderLast || !elements.orderDetailPanel.contains(document.activeElement))) {
              event.preventDefault();
              orderFirst.focus();
            }
          }
          return;
        }
      }

      if (elements.orderEditPanel && !elements.orderEditPanel.hidden) {
        if (event.key === "Escape") {
          event.preventDefault();
          closeOrderEdit(false);
          return;
        }

        if (event.key === "Tab") {
          var orderEditFocusable = Array.prototype.filter.call(elements.orderEditPanel.querySelectorAll("button, a, input, select, textarea, [tabindex]:not([tabindex='-1'])"), function (node) {
            return !node.disabled && !node.hidden && node.offsetParent !== null;
          });
          if (orderEditFocusable.length) {
            var orderEditFirst = orderEditFocusable[0];
            var orderEditLast = orderEditFocusable[orderEditFocusable.length - 1];
            if (event.shiftKey && (document.activeElement === orderEditFirst || !elements.orderEditPanel.contains(document.activeElement))) {
              event.preventDefault();
              orderEditLast.focus();
            } else if (!event.shiftKey && (document.activeElement === orderEditLast || !elements.orderEditPanel.contains(document.activeElement))) {
              event.preventDefault();
              orderEditFirst.focus();
            }
          }
          return;
        }
      }

      if (elements.productViewOverlay && !elements.productViewOverlay.hidden) {
        if (event.key === "Escape") {
          event.preventDefault();
          closeProductView();
          return;
        }

        if (event.key === "Tab") {
          var viewFocusable = Array.prototype.filter.call(elements.productViewOverlay.querySelectorAll("button, a, input, select, textarea, [tabindex]:not([tabindex='-1'])"), function (node) {
            return !node.disabled && !node.hidden && node.offsetParent !== null;
          });
          if (viewFocusable.length) {
            var viewFirst = viewFocusable[0];
            var viewLast = viewFocusable[viewFocusable.length - 1];
            if (event.shiftKey && (document.activeElement === viewFirst || !elements.productViewOverlay.contains(document.activeElement))) {
              event.preventDefault();
              viewLast.focus();
            } else if (!event.shiftKey && (document.activeElement === viewLast || !elements.productViewOverlay.contains(document.activeElement))) {
              event.preventDefault();
              viewFirst.focus();
            }
          }
          return;
        }
      }

      if (elements.productEditorOverlay && !elements.productEditorOverlay.hidden) {
        if (event.key === "Escape") {
          event.preventDefault();
          closeProductEditor();
          return;
        }

        if (event.key === "Tab") {
          var editorFocusable = Array.prototype.filter.call(elements.productEditorOverlay.querySelectorAll("button, a, input, select, textarea, [tabindex]:not([tabindex='-1'])"), function (node) {
            return !node.disabled && !node.hidden && node.offsetParent !== null;
          });
          if (editorFocusable.length) {
            var editorFirst = editorFocusable[0];
            var editorLast = editorFocusable[editorFocusable.length - 1];
            if (event.shiftKey && (document.activeElement === editorFirst || !elements.productEditorOverlay.contains(document.activeElement))) {
              event.preventDefault();
              editorLast.focus();
            } else if (!event.shiftKey && (document.activeElement === editorLast || !elements.productEditorOverlay.contains(document.activeElement))) {
              event.preventDefault();
              editorFirst.focus();
            }
          }
          return;
        }
      }

      if (elements.productFilterPanel && !elements.productFilterPanel.hidden) {
        if (event.key === "Escape") {
          event.preventDefault();
          closeProductFilterPanel();
          if (elements.productFilterToggle) {
            elements.productFilterToggle.focus();
          }
          return;
        }

        if (event.key === "Tab") {
          var productFilterFocusable = Array.prototype.filter.call(elements.productFilterPanel.querySelectorAll("button, a, input, select, textarea, [tabindex]:not([tabindex='-1'])"), function (node) {
            return !node.disabled && !node.hidden && node.offsetParent !== null;
          });
          if (productFilterFocusable.length) {
            var productFilterFirst = productFilterFocusable[0];
            var productFilterLast = productFilterFocusable[productFilterFocusable.length - 1];
            if (event.shiftKey && (document.activeElement === productFilterFirst || !elements.productFilterPanel.contains(document.activeElement))) {
              event.preventDefault();
              productFilterLast.focus();
            } else if (!event.shiftKey && (document.activeElement === productFilterLast || !elements.productFilterPanel.contains(document.activeElement))) {
              event.preventDefault();
              productFilterFirst.focus();
            }
          }
          return;
        }
      }

      if (elements.orderFilterPanel && !elements.orderFilterPanel.hidden && event.key === "Escape") {
        event.preventDefault();
        closeOrderFilterPanel();
        if (elements.orderFilterToggle) {
          elements.orderFilterToggle.focus();
        }
        return;
      }

      if (elements.orderFilterPanel && !elements.orderFilterPanel.hidden && event.key === "Tab") {
        var orderFilterFocusable = Array.prototype.filter.call(elements.orderFilterPanel.querySelectorAll("button, a, input, select, textarea, [tabindex]:not([tabindex='-1'])"), function (node) {
          return !node.disabled && !node.hidden && node.offsetParent !== null;
        });
        if (orderFilterFocusable.length) {
          var orderFilterFirst = orderFilterFocusable[0];
          var orderFilterLast = orderFilterFocusable[orderFilterFocusable.length - 1];
          if (event.shiftKey && (document.activeElement === orderFilterFirst || !elements.orderFilterPanel.contains(document.activeElement))) {
            event.preventDefault();
            orderFilterLast.focus();
          } else if (!event.shiftKey && (document.activeElement === orderFilterLast || !elements.orderFilterPanel.contains(document.activeElement))) {
            event.preventDefault();
            orderFilterFirst.focus();
          }
        }
        return;
      }

      if (event.key === "Escape") {
        if (elements.appSidebar && elements.appSidebar.classList.contains("is-open")) {
          closeSidebar();
          return;
        }
        if (elements.connectionPopover && !elements.connectionPopover.hidden) {
          closeConnectionPopover();
        }
        return;
      }

      if (event.key !== "Tab" || !elements.appSidebar || !elements.appSidebar.classList.contains("is-open")) {
        return;
      }

      var focusable = Array.prototype.filter.call(elements.appSidebar.querySelectorAll("button, a, input, select, textarea"), function (node) {
        return !node.disabled && node.offsetParent !== null;
      });
      if (!focusable.length) return;
      var first = focusable[0];
      var last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });
    window.addEventListener("popstate", function () {
      navigateToSection(sectionFromLocation(), true);
    });
    window.addEventListener("hashchange", function () {
      navigateToSection(sectionFromLocation(), true);
    });
    window.addEventListener("resize", function () {
      if (!state.authenticated || state.activeSection !== "orders") {
        return;
      }
      var nextPageSize = getOrdersPageSize();
      if (state.orders.pageSize && state.orders.pageSize !== nextPageSize) {
        loadOrders(1);
      }
    });
    Array.prototype.forEach.call(document.querySelectorAll("[data-nav-target]"), function (item) {
      item.addEventListener("click", function () {
        navigateToSection(item.getAttribute("data-nav-target") || "dashboard");
        if (item.getAttribute("data-action") === "new-product") {
          window.setTimeout(openNewProductEditor, 0);
        }
      });
    });
    // «بیشتر» در ناوبری موبایل: به‌جای پرش نادرست به بخش امنیت، منوی کناری را باز می‌کند.
    Array.prototype.forEach.call(document.querySelectorAll(".mobile-nav-item[data-action='open-menu']"), function (item) {
      item.addEventListener("click", function () {
        toggleSidebar();
      });
    });
    elements.openStoreButton.addEventListener("click", function () {
      if (state.config && state.config.siteUrl) {
        window.location.href = state.config.siteUrl;
      }
    });
    elements.analyticsRange.addEventListener("change", function () {
      state.analytics.range = elements.analyticsRange.value || "30d";
      if (elements.dashboardRange) {
        elements.dashboardRange.value = state.analytics.range;
      }
      loadAnalytics();
    });
    elements.dashboardRange.addEventListener("change", function () {
      state.analytics.range = elements.dashboardRange.value || "30d";
      if (elements.analyticsRange) {
        elements.analyticsRange.value = state.analytics.range;
      }
      loadAnalytics();
    });
    elements.refreshAnalytics.addEventListener("click", loadAnalytics);
    elements.refreshDevices.addEventListener("click", loadDevices);
    elements.revokeOtherDevices.addEventListener("click", revokeOtherDevices);
    if (elements.sessionAlertPanel) {
      elements.sessionAlertPanel.addEventListener("click", acknowledgeNewSessions);
    }
    elements.refreshAudit.addEventListener("click", function () {
      loadAudit(1);
    });
    elements.auditEventFilter.addEventListener("change", function () {
      loadAudit(1);
    });
    elements.auditPreviousPage.addEventListener("click", function () {
      if (state.security.auditPage > 1) {
        loadAudit(state.security.auditPage - 1);
      }
    });
    elements.auditNextPage.addEventListener("click", function () {
      if (state.security.auditPage < state.security.auditTotalPages) {
        loadAudit(state.security.auditPage + 1);
      }
    });
    document.addEventListener("click", function (event) {
      closeConnectionPopover(event);
      if (elements.installPopover && !elements.installPopover.hidden && !elements.installPopover.contains(event.target) && !elements.installAppButton.contains(event.target)) {
        closeInstallGuide();
      }
      if (elements.notificationsPanel && !elements.notificationsPanel.hidden && elements.notificationsButton && !elements.notificationsPanel.contains(event.target) && !elements.notificationsButton.contains(event.target)) {
        elements.notificationsPanel.hidden = true;
        elements.notificationsButton.setAttribute("aria-expanded", "false");
      }
    });
    window.addEventListener("beforeinstallprompt", function (event) {
      event.preventDefault();
      state.pwa.deferredInstallPrompt = isIosDevice() ? event : null;
      setInstallButtonVisibility();
    });
    window.addEventListener("appinstalled", function () {
      state.pwa.installed = true;
      state.pwa.deferredInstallPrompt = null;
      closeInstallGuide();
      setInstallButtonVisibility();
    });
    window.addEventListener("offline", function () {
      setNetworkState(false);
    });
    window.addEventListener("online", function () {
      setNetworkState(true);
      loadConfig();
      refreshPendingOrderCount();
    });
    document.addEventListener("visibilitychange", function () {
      if (!document.hidden && state.authenticated) {
        refreshPendingOrderCount();
      }
    });
  }

  // [APP: BOOTSTRAP] ترتیب راه‌اندازی DOM، تنظیمات، نشست و بارگذاری اولیهٔ داده‌ها.
  function init() {
    getElements();
    setupSemanticUiHooks();
    rememberAppVersion();
    initializePersianDatePickers();
    setupProductEditorWizard();
    state.activeSection = sectionFromLocation();
    state.pwa.installed = isStandaloneMode();
    applyConfig(normalizeConfig(DEFAULT_CONFIG));
    bindEvents();
    setInstallButtonVisibility();
    setBootState(true);
    setProductsState("secure");
    setProductAttributesMessage("");
    setCategoriesState("secure");
    setCustomersState("secure");
    setOrdersState("secure");
    renderOrderStatusOptions();
    setInventoryState("secure");
    setCouponsState("secure");
    setReviewsState("secure");
    setDevicesState("secure");
    setAuditState("secure");

    if (shouldStartPreviewMode()) {
      activatePreviewMode();
      return;
    }

    registerServiceWorker();
    loadConfig().then(function () {
      return restoreSession();
    }).then(function (authenticated) {
      if (!authenticated) {
        showView("pairing");
        return false;
      }
      return Promise.all([loadProducts(), loadProductAttributes(), loadProductShippingClasses(), loadCategories(), loadCustomers(), loadOrders(), loadInventory(), loadCoupons(), loadReviews(), loadAnalytics(), loadSecurity()]).then(function () {
        showView("dashboard");
        return true;
      }).catch(function () {
        showView("dashboard");
        return true;
      });
    }).catch(function () {
      setAuthenticated(false, "");
      showView("pairing");
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init, { once: true });
  } else {
    init();
  }
}());
