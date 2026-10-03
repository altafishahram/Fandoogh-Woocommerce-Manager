/* Private POS checkout and invoice printing. No customer data is stored locally. */
(function () {
  "use strict";
  var app, cart = [], quote = null, quoteBody = null, busy = false, uncertain = false;
  var page = 1, pages = 1, parent = 0, catalogLoading = false, catalogEpoch = 0, customerEpoch = 0, generation = 0;
  var products = [], customerId = 0, pendingKey = "", pendingBody = null, invoice = null, printFrame = null;
  var searchTimer, customerTimer, returnFocus, recoveryOrder = 0;
  var pendingOwner = 0, pendingLegacy = false, pendingClaims = {};
  function el(id) { return document.getElementById(id); }
  function node(tag, text, cls, doc) { var n = (doc || document).createElement(tag); if (text !== undefined) n.textContent = String(text); if (cls) n.className = cls; return n; }
  function clear(n) { if (n) n.replaceChildren(); }
  function digits(v) { return String(v || "0").replace(/[۰-۹]/g, function (c) { return "۰۱۲۳۴۵۶۷۸۹".indexOf(c); }).replace(/[٠-٩]/g, function (c) { return "٠١٢٣٤٥٦٧٨٩".indexOf(c); }).replace(/٫/g, "."); }
  function currency() { return app.state.config.currency || { label: "تومان" }; }
  function money(v, label) { return (Number(v) || 0).toLocaleString("fa-IR", { maximumFractionDigits: 6 }) + " " + (label || currency().label || currency().code || ""); }
  function errorText(error, fallback) { if (error && error.status === 401) app.unauthorized(); return error && error.payload && error.payload.message || fallback; }
  function message(text) { el("posMessage").textContent = text || ""; }
  function access() { return app && app.state.authenticated && ["products.read", "orders.read", "orders.create"].every(app.hasScope); }
  function url(name, params, suffix) {
    var base = app.url(name); if (!base) throw new Error("missing-api");
    var u = new URL(base, location.href);
    if (u.origin !== location.origin) throw new Error("invalid-api");
    if (suffix) { if (u.searchParams.has("rest_route")) u.searchParams.set("rest_route", u.searchParams.get("rest_route").replace(/\/$/, "") + "/" + suffix); else u.pathname = u.pathname.replace(/\/$/, "") + "/" + suffix; }
    Object.keys(params || {}).forEach(function (k) { if (params[k] !== "") u.searchParams.set(k, String(params[k])); }); return u.toString();
  }
  function post(name, body) { return app.request(url(name), { method: "POST", headers: { "Content-Type": "application/json", "X-Fandoogh-CSRF": app.state.csrfToken }, body: JSON.stringify(body) }); }
  function lock() {
    var disabled = busy || uncertain || !access();
    el("posWorkspace").querySelectorAll("input, select, textarea, button").forEach(function (n) { n.disabled = disabled || n.dataset.unavailable === "true"; });
    el("posReview").disabled = disabled || !cart.length;
    el("posNewSale").disabled = busy || uncertain;
    el("posMoreProducts").disabled = disabled || catalogLoading;
    el("posRecovery").hidden = !uncertain;
    el("posRecover").disabled = busy;
    el("posReviewOrder").disabled = busy;
    el("posReviewOrder").hidden = !recoveryOrder;
    el("posPaymentClose").disabled = busy;
    el("posSubmit").disabled = busy || uncertain;
  }
  function invalidate() { quote = null; quoteBody = null; el("posPaymentReceived").checked = false; }
  function button(text, fn, cls) { var b = node("button", text, cls || "secondary-button"); b.type = "button"; b.addEventListener("click", fn); return b; }
  function previewProduct(p) { return { id: Number(p.id), name: p.name, sku: p.sku, price: p.price, type: p.type || "simple", available: p.stock !== "outofstock" && p.status === "publish", quantity: p.stockQuantity, sold_individually: false, image: p.image || p.imageUrl }; }
  function renderProducts() {
    var list = el("posProducts"); clear(list);
    products.forEach(function (p) {
      var b = button("", function () { choose(p); }, "pos-product");
      if (p.image) { try { var u = new URL(p.image, location.href); if (u.origin === location.origin && /^https?:$/.test(u.protocol)) { var img = node("img"); img.src = u.href; img.alt = ""; img.loading = "lazy"; b.appendChild(img); } } catch (ignore) { /* Skip unsafe image. */ } }
      b.append(node("strong", p.name), node("small", p.sku ? "SKU: " + p.sku : "شناسه: " + p.id), node("span", p.type === "variable" ? "انتخاب تنوع · از " + money(p.price) : money(p.price)));
      if (!p.available) { b.appendChild(node("small", "قابل فروش نیست")); b.disabled = true; b.dataset.unavailable = "true"; }
      list.appendChild(b);
    });
    if (!products.length) list.appendChild(node("p", "کالایی پیدا نشد. نام یا بارکد را بررسی کنید.", "pos-hint"));
    el("posMoreProducts").hidden = page >= pages;
  }
  function loadCatalog(more) {
    if (more && catalogLoading) return Promise.resolve();
    if (!access()) { lock(); message("برای استفاده از صندوق، مجوز مشاهدهٔ کالا، مشاهده و ثبت سفارش لازم است."); return Promise.resolve(); }
    var epoch = ++catalogEpoch, session = generation; page = more ? page + 1 : 1; catalogLoading = true;
    var params = { page: page, search: el("posProductSearch").value.trim(), category_id: el("posCategory").value, parent_id: parent || "" };
    el("posMoreProducts").disabled = true; el("posProducts").setAttribute("aria-busy", "true");
    var request;
    if (app.state.previewMode) {
      var sample = app.state.products.items.filter(function(p){return !params.category_id || p.categoryIds.indexOf(String(params.category_id))>=0;}).map(previewProduct).filter(function (p) { return (p.name + " " + p.sku).indexOf(params.search) >= 0; });
      if (parent) { var main = app.state.products.items.find(function(p){return Number(p.id)===parent;}); sample = main ? [Object.assign(previewProduct(main),{id:parent*100+1,parent_id:parent,type:"variation",name:main.name+" · تنوع نمونه",sku:main.sku+"-SAMPLE"})] : []; }
      request = Promise.resolve({ data: sample, meta: { total_pages: 1 } });
    } else request = app.request(url("posCatalogUrl", params));
    return request.then(function (data) {
      if (epoch !== catalogEpoch || session !== generation) return;
      pages = Number(data.meta && data.meta.total_pages) || 1;
      products = more ? products.concat(data.data || []) : data.data || []; renderProducts();
      message(app.state.previewMode ? "حالت نمونه؛ هیچ فروش، پرداخت یا تغییری در موجودی ثبت نمی‌شود." : "");
    }).catch(function (error) { if (epoch === catalogEpoch && session === generation) { if (more) page--; message(errorText(error, "دریافت کالاها انجام نشد؛ دوباره جست‌وجو کنید.")); } })
      .finally(function () { if (epoch === catalogEpoch && session === generation) { catalogLoading = false; lock(); el("posProducts").setAttribute("aria-busy", "false"); } });
  }
  function choose(p) {
    if (busy || uncertain || !access() || !p.available) return;
    if (p.type === "variable") { parent = Number(p.id); el("posVariationName").textContent = p.name; el("posVariationHeading").hidden = false; el("posProductSearch").value = ""; loadCatalog(false); return; }
    var line = cart.find(function (l) { return l.id === Number(p.id); });
    if (line) { if (line.sold || line.quantity >= 999) { message("تعداد مجاز این کالا در فاکتور کامل است."); return; } line.quantity++; }
    else { if (cart.length >= 100) { message("حداکثر صد ردیف کالا مجاز است."); return; } cart.push({ id: Number(p.id), name: p.name, sku: p.sku, quantity: 1, price: String(p.price), original: String(p.price), override: false, sold: !!p.sold_individually }); }
    invalidate(); renderCart(); message("اضافه شد: " + p.name);
  }
  function exactProduct(id) {
    var session = generation;
    if (app.state.previewMode) { var p = app.state.products.items.find(function (item) { return Number(item.id) === Number(id); }); if (p) choose(previewProduct(p)); return; }
    app.request(url("posCatalogUrl", { id: id })).then(function (data) { if (session === generation && data.data && data.data[0]) choose(data.data[0]); }).catch(function (error) { if (session === generation) message(errorText(error, "کالای بارکد پیدا نشد یا قابل فروش نیست.")); });
  }
  function renderCart() {
    clear(el("posCart")); var sum = 0, count = 0;
    cart.forEach(function (l, i) {
      var row = node("div", undefined, "pos-cart-line");
      var heading = node("div", undefined, "pos-cart-line-title"); heading.append(node("strong", l.name), button("حذف", function () { cart.splice(i, 1); invalidate(); renderCart(); }, "text-link-button"));
      row.append(heading, node("small", l.sku || "شناسه: " + l.id));
      var fields = node("div", undefined, "pos-fields");
      var qtyLabel = node("label", "تعداد"), qty = node("input"); qty.type = "number"; qty.min = "1"; qty.max = l.sold ? "1" : "999"; qty.value = l.quantity; qty.setAttribute("aria-label", "تعداد " + l.name);
      qty.addEventListener("change", function () { var n = Number(digits(qty.value)); if (!Number.isInteger(n) || n < 1 || n > Number(qty.max)) { qty.value = l.quantity; message("تعداد صحیح و مجاز وارد کنید."); return; } l.quantity = n; invalidate(); renderCart(); }); qtyLabel.appendChild(qty);
      var priceLabel = node("label", "قیمت واحد " + (currency().label || "")), price = node("input"); price.type = "text"; price.inputMode = "decimal"; price.value = l.price; price.setAttribute("aria-label", "قیمت " + l.name);
      price.addEventListener("change", function () { var v = digits(price.value).trim(); if (!/^\d{1,12}(?:\.\d{1,6})?$/.test(v)) { price.value = l.price; message("قیمت معتبر و بدون جداکننده وارد کنید."); return; } l.price = v; l.override = true; invalidate(); renderCart(); }); priceLabel.appendChild(price); fields.append(qtyLabel, priceLabel); row.appendChild(fields);
      if (l.override) row.appendChild(button("بازگردانی قیمت اصلی: " + money(l.original), function () { l.price = l.original; l.override = false; invalidate(); renderCart(); }, "text-link-button"));
      row.appendChild(node("strong", money(Number(l.price) * l.quantity), "pos-line-total")); el("posCart").appendChild(row); sum += Number(l.price) * l.quantity; count += l.quantity;
    });
    if (!cart.length) el("posCart").appendChild(node("p", "برای شروع، یک کالا از فهرست انتخاب یا بارکد آن را اسکن کنید.", "pos-empty"));
    el("posCartCount").textContent = count.toLocaleString("fa-IR") + " کالا"; el("posEstimate").textContent = money(sum); lock();
  }
  function customerValues() { return { mode: el("posCustomerMode").value, id: customerId, first_name: el("posCustomerFirst").value, last_name: el("posCustomerLast").value, phone: el("posCustomerPhone").value, email: el("posCustomerEmail").value, address_1: el("posCustomerAddress").value }; }
  function body() { return { items: cart.map(function (l) { var v = { id: l.id, quantity: l.quantity }; if (l.override) v.unit_price = l.price; return v; }), customer: customerValues(), discount: { type: el("posDiscountType").value, value: digits(el("posDiscountValue").value) }, note: el("posNote").value }; }
  function searchCustomers() {
    var epoch = ++customerEpoch, session = generation, term = el("posCustomerSearch").value.trim(); clear(el("posCustomerResults"));
    if (term.length < 2) return;
    var request = app.state.previewMode ? Promise.resolve({ data: app.state.customers.items.filter(function (c) { return [c.display, c.phone, c.email].join(" ").indexOf(term) >= 0; }) }) : app.request(url("customersUrl", { search: term, per_page: 20 }));
    request.then(function (data) {
      if (epoch !== customerEpoch || session !== generation) return;
      var list = data.data || []; if (!list.length) el("posCustomerResults").appendChild(node("p", "مشتری پیدا نشد؛ می‌توانید مشتری جدید معرفی کنید."));
      list.forEach(function (c) { var name = c.display_name || c.display || [c.first_name, c.last_name].join(" "); var phone = c.phone || c.billing && c.billing.phone || "";
        el("posCustomerResults").appendChild(button(name + " · " + (phone || c.email || ""), function () { if (busy || uncertain) return; customerId = Number(c.id); el("posSelectedCustomer").textContent = "مشتری: " + name; clear(el("posCustomerResults")); invalidate(); })); });
    }).catch(function (error) { if (epoch === customerEpoch && session === generation) message(errorText(error, "جست‌وجوی مشتری انجام نشد.")); });
  }
  function previewQuote(b) {
    var subtotal = cart.reduce(function (s, l) { return s + Number(l.price) * l.quantity; }, 0), value = Number(b.discount.value);
    var discount = b.discount.type === "percent" ? subtotal * value / 100 : value;
    if (!isFinite(discount) || discount < 0 || discount > subtotal || b.discount.type === "percent" && value > 100) throw new Error("تخفیف نمونه معتبر نیست.");
    return { lines: cart.map(function (l) { return { name: l.name, quantity: l.quantity, unit_price: l.price }; }), customer: b.customer, subtotal: String(subtotal), discount_total: String(discount), tax: "0", total: String(subtotal - discount), currency: currency(), expires: Math.floor(Date.now()/1000)+300, quote_token: "preview" };
  }
  function review() {
    if (!cart.length || !access() || busy || uncertain) return;
    var b = body(), session = generation;
    if (b.customer.mode === "registered" && !customerId) { message("یک مشتری ثبت‌شده انتخاب کنید."); return; }
    if (b.customer.mode === "new" && ((!b.customer.first_name && !b.customer.last_name) || (!b.customer.phone && !b.customer.email))) { message("نام و تلفن یا ایمیل مشتری جدید را وارد کنید."); return; }
    busy = true; lock(); message("در حال بررسی قیمت، موجودی و مبلغ نهایی…");
    var request = app.state.previewMode ? Promise.resolve().then(function () { return { data: previewQuote(b) }; }) : post("posQuoteUrl", b);
    request.then(function (data) {
      if (session !== generation) return;
      quote = data.data; if (!quote || !quote.quote_token || !Array.isArray(quote.lines)) throw new Error("invalid-quote");
      quoteBody = b; clear(el("posQuoteSummary")); var lines = node("ul", undefined, "pos-quote-lines");
      var contact = quote.customer && (quote.customer.billing || quote.customer) || {};
      el("posQuoteSummary").appendChild(node("p","مشتری: " + ([contact.first_name,contact.last_name].filter(Boolean).join(" ") || (customerId ? el("posSelectedCustomer").textContent : "مشتری گذری")) + (contact.phone ? " · " + contact.phone : "")));
      quote.lines.forEach(function (l) { lines.appendChild(node("li", l.name + " × " + Number(l.quantity).toLocaleString("fa-IR") + " · قیمت واحد: " + money(l.unit_price,quote.currency.label))); });
      el("posQuoteSummary").appendChild(lines);
      [["جمع کالاها",quote.subtotal],["تخفیف",quote.discount_total],["مالیات منظورشده",quote.tax],["قابل پرداخت",quote.total]].forEach(function (r) { var n = node("div", undefined, "pos-total-row"); n.append(node("span", r[0]), node("strong", money(r[1], quote.currency.label))); el("posQuoteSummary").appendChild(n); });
      el("posPaymentMethod").value = "cash"; el("posPaymentReference").value = ""; el("posPaymentReceived").checked = false; el("posPaymentMessage").textContent = app.state.previewMode ? "فاکتور نمونه؛ پرداخت واقعی یا ثبت سفارش انجام نمی‌شود." : "با ثبت، سفارش پرداخت‌شده و تحویل‌شده ساخته و موجودی کم می‌شود.";
      paymentMode(); returnFocus = el("posReview"); el("posPaymentDialog").showModal(); message("");
    }).catch(function (error) { if (session === generation) message(errorText(error, app.state.previewMode ? error.message : "بررسی فاکتور انجام نشد.")); })
      .finally(function () { if (session === generation) { busy = false; lock(); } });
  }
  function paymentMode() {
    if (!quote) return;
    var m = el("posPaymentMethod").value;
    el("posCashLabel").hidden = m === "card"; el("posCardLabel").hidden = m === "cash"; el("posReferenceLabel").hidden = m === "cash";
    el("posCashReceived").value = m === "cash" ? quote.total : "0"; el("posCardAmount").value = m === "card" ? quote.total : "0"; el("posPaymentReceived").checked = false; showChange();
  }
  function showChange() { if (!quote) return; var rest = Number(digits(el("posCashReceived").value)) + Number(digits(el("posCardAmount").value)) - Number(quote.total); el("posChange").textContent = rest >= 0 ? "باقی‌ماندهٔ وجه نقد: " + money(rest, quote.currency.label) : "ماندهٔ پرداخت: " + money(-rest, quote.currency.label); }
  function uuid() { return window.crypto && crypto.randomUUID ? crypto.randomUUID() : "sale-" + Date.now() + "-" + Math.random().toString(36).slice(2); }
  function rememberKey() {
    if (app.state.previewMode || !pendingOwner) return;
    if (pendingKey) pendingClaims[pendingOwner] = pendingKey; else delete pendingClaims[pendingOwner];
    try {
      if (pendingLegacy) { if (!pendingKey) { sessionStorage.removeItem("fandoogh-pos-pending"); pendingLegacy = false; } return; }
      var key = "fandoogh-pos-pending:" + pendingOwner;
      if (pendingKey) sessionStorage.setItem(key, pendingKey); else sessionStorage.removeItem(key);
    } catch (ignore) { /* Keep per-user recovery in memory when storage is unavailable. */ }
  }
  function finish(snapshot) {
    pendingKey = ""; pendingBody = null; rememberKey(); uncertain = false; recoveryOrder = 0; busy = false;
    if (el("posPaymentDialog").open) el("posPaymentDialog").close(); cart = []; newSale(); openInvoice(snapshot);
    message(app.state.previewMode ? "فاکتور نمونه آماده است؛ هیچ تغییری در فروشگاه ثبت نشد." : "فروش ثبت و تحویل شد. فاکتور آمادهٔ چاپ است.");
    if (!app.state.previewMode) app.refresh();
  }
  function submit(event) {
    event.preventDefault(); if (!quote || !quoteBody || busy || uncertain) return;
    if (quote.expires < Date.now()/1000) { el("posPaymentMessage").textContent = "زمان بررسی فاکتور تمام شد؛ به فاکتور برگردید و دوباره بررسی کنید."; return; }
    var payment = { method: el("posPaymentMethod").value, cash_received: digits(el("posCashReceived").value), card_amount: digits(el("posCardAmount").value), reference: el("posPaymentReference").value, received: el("posPaymentReceived").checked };
    var c = Number(payment.cash_received), k = Number(payment.card_amount), total = Number(quote.total);
    if (!payment.received || !/^\d{1,12}(?:\.\d{1,6})?$/.test(payment.cash_received) || !/^\d{1,12}(?:\.\d{1,6})?$/.test(payment.card_amount) || c+k < total || k > total || payment.method === "cash" && k !== 0 || payment.method === "card" && (c !== 0 || k !== total) || payment.method === "mixed" && (k <= 0 || k >= total)) { el("posPaymentMessage").textContent = "مبلغ و دریافت وجه را بررسی کنید؛ در پرداخت ترکیبی، بخشی نقدی و بخشی کارت است."; return; }
    busy = true; lock(); var session = generation;
    if (app.state.previewMode) { finish(sampleInvoice(quote, payment)); return; }
    pendingOwner = Number(app.state.user.id) || 0;
    if (!pendingOwner) { busy = false; lock(); el("posPaymentMessage").textContent = "هویت فروشنده مشخص نیست؛ دوباره وارد شوید."; return; }
    pendingKey = uuid(); pendingBody = Object.assign({}, quoteBody, { payment: payment, quote_token: quote.quote_token, idempotency_key: pendingKey }); rememberKey();
    el("posPaymentMessage").textContent = "در حال ثبت؛ تا دریافت نتیجه صبر کنید…";
    post("posSalesUrl", pendingBody).then(function (data) { if (session === generation) finish(data.data); }).catch(function (error) {
      if (session !== generation) return;
      busy = false;
      if (error && (error.offline || [401,403].indexOf(error.status) >= 0) || error && error.payload && ["fandoogh_pos_quote_changed","fandoogh_pos_unavailable","fandoogh_pos_invalid","fandoogh_pos_key"].indexOf(error.payload.code) >= 0) {
        pendingKey = ""; pendingBody = null; rememberKey();
        el("posPaymentMessage").textContent = errorText(error, "ثبت انجام نشد؛ فاکتور را دوباره بررسی کنید.");
      } else {
        uncertain = true; recoveryOrder = Number(error && error.payload && error.payload.data && error.payload.data.order_id) || 0;
        el("posPaymentDialog").close(); message(errorText(error, "پاسخ ثبت دریافت نشد؛ نتیجهٔ همین فروش را بررسی کنید."));
      }
      lock();
    });
  }
  function recover() {
    if (!pendingKey || busy || app.state.previewMode) return;
    busy = true; lock(); var session = generation;
    app.request(url("posSalesUrl", {}, pendingKey)).then(function (data) { if (session === generation) finish(data.data); }).catch(function (error) {
      if (session !== generation) return;
      var data = error && error.payload && error.payload.data || {};
      recoveryOrder = Number(data.order_id) || recoveryOrder;
      if (data.safe_to_restart === true) { uncertain = false; pendingKey = ""; pendingBody = null; rememberKey(); invalidate(); }
      message(errorText(error, "نتیجه هنوز مشخص نیست؛ در سفارش‌ها بررسی کنید و دوباره نتیجه را بگیرید."));
    }).finally(function () { if (session === generation) { busy = false; lock(); } });
  }
  function sampleInvoice(q, payment) {
    var cfg = app.state.config; return { id: 0, number: "نمونه", channel: "pos", date: new Date().toISOString(), status: "نمونه", paid: false, demo: true,
      store: { name: cfg.brand && cfg.brand.name || "فندوق", address: "نشانی نمونهٔ فروشگاه", website: location.origin }, customer: { display: [q.customer.first_name,q.customer.last_name].filter(Boolean).join(" ") || "مشتری گذری", phone: q.customer.phone || "" }, billing: { address_1: q.customer.address_1 || "" }, currency: q.currency,
      lines: cart.map(function (l) { return { name: l.name, sku: l.sku, quantity: l.quantity, unit_price: l.price, total: String(Number(l.price)*l.quantity) }; }), fees: [], totals: { subtotal: q.subtotal, discount: q.discount_total, tax: q.tax, shipping: "0", total: q.total, refunded: "0", net: q.total }, payment: { title: {cash:"نقدی",card:"کارت‌خوان",mixed:"نقدی و کارت‌خوان"}[payment.method], tenders: Object.assign({},payment,{ change:String(Number(payment.cash_received)+Number(payment.card_amount)-Number(q.total)) }) }, note: el("posNote").value };
  }
  function invoiceNode(data, doc, paper) {
    var root = node("article", undefined, "fm-invoice paper-" + paper, doc), store = data.store || {}, customer = data.customer || {}, billing = data.billing || {}, totals = data.totals || {}, label = data.currency && data.currency.label;
    var header = node("header", undefined, "invoice-store", doc);
    if (store.logo) { try { var logo = new URL(store.logo, location.href); if (logo.origin === location.origin && /^https?:$/.test(logo.protocol)) { var img = node("img", undefined, "invoice-logo",doc); img.src = logo.href; img.alt = ""; header.appendChild(img); } } catch (ignore) { /* Skip unsafe logo. */ } }
    header.append(node("h1",store.name || "فروشگاه", "",doc), node("p",store.address || "", "",doc)); if (store.phone) header.appendChild(node("p","تلفن: " + store.phone,"",doc)); root.appendChild(header);
    root.append(node("h2",data.demo ? "فاکتور نمونه — بدون ثبت فروش" : "فاکتور فروش #" + data.number,"",doc));
    var date = data.date ? new Date(data.date) : null;
    root.append(node("p", (date && !isNaN(date) ? date.toLocaleString("fa-IR") : "") + " · " + (data.channel === "pos" ? "فروش حضوری" : "سفارش آنلاین / سایر") + " · " + (data.status || ""), "invoice-meta",doc));
    root.append(node("p", "مشتری: " + (customer.display || "مهمان") + (customer.phone ? " · " + customer.phone : ""),"",doc));
    var address = [billing.address_1,billing.address_2,billing.city,billing.postcode].filter(Boolean).join("، "); if (address) root.appendChild(node("p",address,"",doc));
    var table = node("table",undefined,"invoice-table",doc), thead = node("thead",undefined,"",doc), tr = node("tr",undefined,"",doc);
    ["کالا","تعداد","قیمت واحد","مبلغ ردیف"].forEach(function (h) { var th = node("th",h,"",doc); th.scope = "col"; tr.appendChild(th); }); thead.appendChild(tr); table.appendChild(thead); var tbody = node("tbody",undefined,"",doc);
    (data.lines || []).forEach(function (l) { var row = node("tr",undefined,"",doc), name = node("td",l.name,"",doc); if (l.sku) name.appendChild(node("small","SKU: " + l.sku,"invoice-sku",doc)); row.append(name,node("td",Number(l.quantity).toLocaleString("fa-IR"),"",doc),node("td",money(l.unit_price,label),"",doc),node("td",money(l.total,label),"",doc)); tbody.appendChild(row); }); table.appendChild(tbody); root.appendChild(table);
    var summary = node("div",undefined,"invoice-totals",doc);
    [["جمع کالاها",totals.subtotal],["تخفیف",totals.discount],["مالیات منظورشده",totals.tax],["ارسال",totals.shipping]].forEach(function (r) { var n = node("div",undefined,"invoice-total-row",doc); n.append(node("span",r[0],"",doc),node("strong",money(r[1],label),"",doc)); summary.appendChild(n); });
    (data.fees || []).forEach(function (fee) { var n=node("div",undefined,"invoice-total-row",doc); n.append(node("span",fee.name,"",doc),node("strong",money(fee.total,label),"",doc)); summary.appendChild(n); });
    [["مبلغ فاکتور",totals.total],["بازپرداخت",totals.refunded],["مبلغ پس از بازپرداخت",totals.net]].forEach(function (r,i) { if (i && !Number(totals.refunded)) return; var n=node("div",undefined,"invoice-total-row invoice-grand-total",doc); n.append(node("span",r[0],"",doc),node("strong",money(r[1],label),"",doc)); summary.appendChild(n); }); root.appendChild(summary);
    root.appendChild(node("p","روش پرداخت: " + (data.payment && data.payment.title || "ثبت نشده") + " · " + (data.demo ? "نمونه" : data.paid ? "پرداخت‌شده" : "پرداخت تأیید نشده"),"",doc));
    var tender = data.payment && data.payment.tenders;
    if (tender) { root.appendChild(node("p","نقد دریافت‌شده: " + money(tender.cash_received,label) + " · کارت‌خوان: " + money(tender.card_amount,label) + " · باقی‌مانده: " + money(tender.change,label),"",doc)); if (tender.reference) root.appendChild(node("p","پیگیری: " + tender.reference,"",doc)); }
    if (data.note) root.appendChild(node("p","یادداشت: " + data.note,"invoice-note",doc));
    root.appendChild(node("footer","از خرید شما سپاسگزاریم" + (store.website ? " · " + store.website : ""),"",doc)); return root;
  }
  function renderInvoice() { clear(el("invoicePreview")); if (invoice) el("invoicePreview").appendChild(invoiceNode(invoice,document,el("invoicePaper").value)); }
  function openInvoice(snapshot) { if (!snapshot || !Array.isArray(snapshot.lines)) throw new Error("invalid-invoice"); invoice = snapshot; el("invoiceMessage").textContent = snapshot.demo ? "این فاکتور فقط نمونه است." : "فاکتور سفارش ذخیره‌شده آماده است."; el("invoicePrint").disabled = false; renderInvoice(); if (!el("invoiceDialog").open) el("invoiceDialog").showModal(); }
  function loadInvoice(id) {
    returnFocus = el("orderPrintInvoice"); if (!el("invoiceDialog").open) el("invoiceDialog").showModal(); invoice = null; clear(el("invoicePreview")); el("invoicePrint").disabled = true; el("invoiceMessage").textContent = "در حال دریافت فاکتور…";
    var session = generation;
    if (app.state.previewMode) { var order = app.state.orders.items.find(function (o) { return String(o.id) === String(id); }); var sample = sampleInvoice({customer:{},currency:currency(),subtotal:order && order.total || "0",discount_total:"0",tax:"0",total:order && order.total || "0"},{method:"cash",cash_received:"0",card_amount:"0"}); sample.number = "نمونه " + id; sample.channel = "online"; sample.lines = [{name:"اقلام سفارش نمونه",quantity:1,unit_price:sample.totals.total,total:sample.totals.total}]; openInvoice(sample); return; }
    app.request(url("ordersUrl",{},String(id)+"/invoice")).then(function (data) { if (session === generation && el("invoiceDialog").open) openInvoice(data.data); }).catch(function (error) { if (session === generation) el("invoiceMessage").textContent = errorText(error,"دریافت فاکتور انجام نشد؛ دوباره دکمهٔ چاپ را بزنید."); });
  }
  function destroyFrame() { if (printFrame) printFrame.remove(); printFrame = null; }
  async function printInvoice() {
    if (!invoice) return; destroyFrame(); el("invoicePrint").disabled = true;
    var session = generation, data = invoice, paper = el("invoicePaper").value;
    var frame = node("iframe",undefined,"pos-print-frame"); frame.title = "فاکتور برای چاپ"; frame.setAttribute("aria-hidden","true"); document.body.appendChild(frame); printFrame = frame;
    try {
      var doc = frame.contentDocument; doc.documentElement.lang = "fa"; doc.documentElement.dir = "rtl"; doc.title = data.demo ? "فاکتور نمونه" : "فاکتور " + data.number;
      var styles = [el("invoiceStyles"),document.querySelector('link[href*="fonts.css"]')].filter(Boolean);
      await Promise.all(styles.map(function (source) { return new Promise(function (resolve,reject) { var link = doc.createElement("link"); link.rel = "stylesheet"; link.href = source.href; link.onload = function () { resolve(link); }; link.onerror = reject; doc.head.appendChild(link); }); }));
      doc.body.appendChild(invoiceNode(data,doc,paper)); if (doc.fonts && doc.fonts.ready) await doc.fonts.ready;
      await Promise.all(Array.from(doc.images).map(function (img) { return img.complete ? Promise.resolve() : new Promise(function (resolve) { img.onload = resolve; img.onerror = resolve; }); }));
      if (paper === "receipt") { var height = Math.min(2000,Math.max(70,Math.ceil(doc.querySelector('.fm-invoice').getBoundingClientRect().height*25.4/96)+6)); doc.styleSheets[0].insertRule("@page receipt { size: 80mm " + height + "mm; margin: 3mm; }",doc.styleSheets[0].cssRules.length); }
      if (session !== generation || !invoice || printFrame !== frame) return;
      frame.contentWindow.focus(); frame.contentWindow.print();
    } catch (error) { if (session === generation) el("invoiceMessage").textContent = "آماده‌سازی چاپ انجام نشد؛ فاکتور را دوباره باز کنید."; }
    finally { if (session === generation) el("invoicePrint").disabled = false; }
  }
  function renderChannels(channels,label) {
    clear(el("analyticsChannels")); if (!channels) return;
    ["pos","online"].forEach(function (key) { var c=channels[key] || {}, card=node("section",undefined,"pos-channel-card"); card.append(node("h3",key === "pos" ? "فروش حضوری" : "آنلاین و سایر سفارش‌ها"),node("p","فروش: " + money(c.gross,label)),node("p","بازپرداخت: " + money(c.refunded,label)),node("strong","خالص فروش: " + money(c.net,label)),node("small",(Number(c.orders)||0).toLocaleString("fa-IR") + " سفارش")); el("analyticsChannels").appendChild(card); });
  }
  function newSale() {
    if (busy || uncertain || cart.length && !window.confirm("فاکتور جاری پاک شود و فروش جدید شروع شود؟")) return;
    cart = []; customerId = 0; pendingBody = null; pendingKey = ""; rememberKey(); invalidate();
    ["posCustomerFirst","posCustomerLast","posCustomerPhone","posCustomerEmail","posCustomerAddress","posCustomerSearch","posNote"].forEach(function (id) { el(id).value=""; });
    el("posCustomerMode").value="guest"; el("posCustomerFields").hidden=false; el("posCustomerLookup").hidden=true; el("posSelectedCustomer").textContent=""; clear(el("posCustomerResults"));
    el("posPanel").querySelector('.pos-customer').open=false;
    el("posDiscountValue").value="0"; renderCart(); message("");
  }
  function activate(section) {
    if (!app || section !== "pos") return;
    var category=el("posCategory"), selected=category.value; clear(category); var all=node("option","همهٔ دسته‌ها"); all.value=""; category.appendChild(all);
    (app.state.categories.items || []).forEach(function (c) { var option=node("option",c.name); option.value=c.id; category.appendChild(option); }); category.value=selected;
    var options=el("posCustomerMode").options; options[1].disabled=!app.hasScope("customers.read"); options[2].disabled=!app.hasScope("customers.write");
    if (!app.state.previewMode && !pendingKey && !busy) {
      pendingOwner = Number(app.state.user.id) || 0;
      pendingKey = pendingClaims[pendingOwner] || "";
      try {
        pendingKey = pendingKey || sessionStorage.getItem("fandoogh-pos-pending:" + pendingOwner) || "";
        if (!pendingKey) { pendingKey = sessionStorage.getItem("fandoogh-pos-pending") || ""; pendingLegacy = Boolean(pendingKey); }
      } catch(ignore) { /* In-memory keys survive logout on this page. */ }
    }
    if (pendingKey && !app.state.previewMode) { uncertain=true; recover(); }
    renderCart(); loadCatalog(false);
  }
  function reset() {
    if (!app || app.state.authenticated) return;
    rememberKey();
    generation++; catalogEpoch++; customerEpoch++; clearTimeout(searchTimer); clearTimeout(customerTimer); cart=[]; products=[]; quote=null; quoteBody=null; invoice=null; busy=false; uncertain=false; pendingKey=""; pendingBody=null; pendingOwner=0; pendingLegacy=false; recoveryOrder=0; customerId=0; parent=0; page=1; pages=1; catalogLoading=false; destroyFrame();
    ["invoiceDialog","posPaymentDialog"].forEach(function(id) { if(el(id).open) el(id).close(); }); clear(el("invoicePreview")); clear(el("posProducts")); clear(el("posCustomerResults")); clear(el("analyticsChannels")); clear(el("posQuoteSummary"));
    ["posProductSearch","posPaymentReference","posCashReceived","posCardAmount"].forEach(function(id){el(id).value="";}); el("posVariationHeading").hidden=true; el("posVariationName").textContent=""; el("posPaymentReceived").checked=false; newSale(); renderCart();
  }
  function init(adapter) {
    app=adapter;
    el("posProductSearch").addEventListener("input",function(){ clearTimeout(searchTimer); searchTimer=setTimeout(function(){ loadCatalog(false); },300); });
    el("posProductSearch").addEventListener("keydown",function(e){ if(e.key !== "Enter" || !this.value.trim() || busy || uncertain) return; e.preventDefault(); var session=generation; if(app.state.previewMode) { var p=app.state.products.items.find(function(p){ return p.sku===el("posProductSearch").value; }); if(p) exactProduct(p.id); return; } app.request(url("barcodeUrl",{code:this.value.trim()})).then(function(data){ if(session===generation) exactProduct(data.data.id); }).catch(function(error){ if(session===generation) message(errorText(error,"بارکد پیدا نشد؛ برای انتخاب نام کالا روی کارت آن بزنید.")); }); });
    el("posProductSearch").addEventListener("fandoogh:barcode",function(e){ exactProduct(e.detail.id); });
    el("posMoreProducts").addEventListener("click",function(){ loadCatalog(true); }); el("posCategory").addEventListener("change",function(){ parent=0; el("posVariationHeading").hidden=true; loadCatalog(false); }); el("posBackCatalog").addEventListener("click",function(){ parent=0; el("posVariationHeading").hidden=true; loadCatalog(false); });
    if (window.IntersectionObserver) { var observer=new IntersectionObserver(function(entries){ if(entries[0].isIntersecting && !el("posPanel").hidden && !el("posMoreProducts").hidden && !el("posMoreProducts").disabled && !busy && !uncertain) loadCatalog(true); }); observer.observe(el("posMoreProducts")); }
    el("posCustomerMode").addEventListener("change",function(){ customerId=0; customerEpoch++; invalidate(); clear(el("posCustomerResults")); el("posSelectedCustomer").textContent=""; el("posCustomerLookup").hidden=this.value!=="registered"; el("posCustomerFields").hidden=this.value==="registered"; });
    el("posCustomerSearch").addEventListener("input",function(){ clearTimeout(customerTimer); customerTimer=setTimeout(searchCustomers,300); });
    ["posCustomerFirst","posCustomerLast","posCustomerPhone","posCustomerEmail","posCustomerAddress","posDiscountType","posDiscountValue","posNote"].forEach(function(id){ el(id).addEventListener("input",invalidate); });
    el("posReview").addEventListener("click",review); el("posNewSale").addEventListener("click",newSale); el("posRecover").addEventListener("click",recover); el("posReviewOrder").addEventListener("click",function(){ if(recoveryOrder) { location.hash="#orders"; app.state.orders.detailId=String(recoveryOrder); app.openOrder(recoveryOrder,el("posReviewOrder")); } });
    el("posPaymentForm").addEventListener("submit",submit); el("posPaymentMethod").addEventListener("change",paymentMode); ["posCashReceived","posCardAmount"].forEach(function(id){ el(id).addEventListener("input",showChange); });
    el("posPaymentClose").addEventListener("click",function(){ if(!busy) el("posPaymentDialog").close(); }); el("posPaymentDialog").addEventListener("cancel",function(e){ if(busy) e.preventDefault(); });
    el("invoicePaper").addEventListener("change",renderInvoice); el("invoicePrint").addEventListener("click",printInvoice); el("invoiceClose").addEventListener("click",function(){ el("invoiceDialog").close(); });
    el("invoiceDialog").addEventListener("close",function(){ invoice=null; clear(el("invoicePreview")); destroyFrame(); if(returnFocus && returnFocus.isConnected) returnFocus.focus(); });
    el("posPaymentDialog").addEventListener("close",function(){ if(returnFocus && returnFocus.isConnected) returnFocus.focus(); });
    el("orderPrintInvoice").addEventListener("click",function(){ if(app.state.orders.detailId) loadInvoice(app.state.orders.detailId); }); renderCart();
  }
  window.FandooghPOS={init:init,reset:reset,activate:activate,renderChannels:renderChannels};
}());
