/* Phone input tools, Web Push preferences and daily work. Loaded before app.js. */
(function () {
  "use strict";
  var app, generation = 0, todayRequest = 0, recognition = null, microphone = null;
  var stream = null, reader = null, scanTimer = null, scannerGeneration = 0, scanTarget = null;
  var scannerLibrary = null, pushState = null, todayPages = { order_page: 1, inventory_page: 1 };
  var statusTimer, todayTimer, scannerBusy = false;
  function el(id) { return document.getElementById(id); }
  function textNode(tag, text, className) {
    var node = document.createElement(tag);
    node.textContent = text;
    if (className) node.className = className;
    return node;
  }
  function message(text) {
    var node = el("searchAssistStatus");
    node.textContent = text;
    node.hidden = false;
    clearTimeout(statusTimer);
    statusTimer = setTimeout(function () { node.hidden = true; }, 8000);
  }
  function requestError(error, fallback) {
    if (error && error.status === 401 && app.unauthorized) app.unauthorized();
    return error && error.payload && typeof error.payload.message === "string" ? error.payload.message : fallback;
  }
  function urlWith(name, params) {
    var base = app.url(name);
    if (!base) throw new Error("missing-endpoint");
    var url = new URL(base);
    Object.keys(params || {}).forEach(function (key) { url.searchParams.set(key, String(params[key])); });
    return url.toString();
  }
  function write(name, body, method) {
    return app.request(urlWith(name), {
      method: method || "POST", headers: { "Content-Type": "application/json", "X-Fandoogh-CSRF": app.state.csrfToken },
      body: body ? JSON.stringify(body) : undefined
    });
  }
  function inputValue(input, value) {
    if (!input || !input.isConnected || input.disabled || input.readOnly) return;
    input.value = value;
    input.dispatchEvent(new Event("input", { bubbles: true }));
    input.dispatchEvent(new Event("change", { bubbles: true }));
  }
  function stopVoice() {
    if (recognition) { recognition.onresult = null; recognition.onerror = null; recognition.onend = null; try { recognition.abort(); } catch (error) { /* Already stopped. */ } }
    recognition = null;
    if (microphone) { microphone.setAttribute("aria-pressed", "false"); microphone.classList.remove("is-listening"); }
    microphone = null;
  }
  function startVoice(input, button) {
    if (!app.state.authenticated) return;
    if (recognition && microphone === button) { stopVoice(); message("جست‌وجوی صوتی متوقف شد."); return; }
    stopVoice();
    var Speech = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!Speech) { message("این مرورگر جست‌وجوی صوتی را پشتیبانی نمی‌کند؛ از مرورگر سازگار یا تایپ استفاده کنید."); return; }
    if (!window.isSecureContext) { message("برای دسترسی به میکروفن، پنل را با HTTPS باز کنید."); return; }
    try {
      recognition = new Speech();
      var current = recognition;
      microphone = button;
      current.lang = "fa-IR";
      current.continuous = false;
      current.interimResults = false;
      current.maxAlternatives = 1;
      current.onresult = function (event) {
        if (current !== recognition || !app.state.authenticated || !input.getClientRects().length) return;
        var result = event.results && event.results[event.resultIndex || 0];
        if (result && result[0] && result[0].transcript) {
          inputValue(input, result[0].transcript.trim());
          input.focus();
          message("جست‌وجوی صوتی: " + input.value);
        }
      };
      current.onerror = function (event) {
        var messages = { "not-allowed": "دسترسی میکروفن رد شده؛ آن را در تنظیمات مرورگر فعال کنید.", "audio-capture": "میکروفن در دسترس نیست.", network: "سرویس گفتار مرورگر در دسترس نیست؛ اتصال اینترنت را بررسی کنید.", "no-speech": "گفتاری دریافت نشد؛ دوباره امتحان کنید.", "language-not-supported": "سرویس گفتار این مرورگر زبان فارسی را پشتیبانی نمی‌کند." };
        if (event.error !== "aborted") message(messages[event.error] || "تبدیل گفتار انجام نشد؛ دوباره امتحان کنید.");
        stopVoice();
      };
      current.onend = function () { if (current === recognition) stopVoice(); };
      current.start();
      button.setAttribute("aria-pressed", "true");
      button.classList.add("is-listening");
      message("گوش می‌دهم؛ عبارت جست‌وجو را بگویید. پردازش گفتار ممکن است با سرویس مرورگر انجام شود.");
    } catch (error) { stopVoice(); message("میکروفن شروع نشد؛ دسترسی مرورگر را بررسی کنید."); }
  }
  var MIC_ICON = '<svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="3" width="6" height="12" rx="3"/><path d="M6 10v2a6 6 0 0 0 12 0v-2M12 18v3M8 21h8"/></svg>';
  var BARCODE_ICON = '<svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8V4h4M16 4h4v4M20 16v4h-4M8 20H4v-4M8 8v8M11 8v8M14 8v8M17 8v8"/></svg>';
  function enhanceSearches(root) {
    Array.prototype.forEach.call((root || document).querySelectorAll('input[type="search"], input[data-voice-search]'), function (input) {
      if (input.dataset.searchEnhanced) return;
      input.dataset.searchEnhanced = "true";
      var wrap = document.createElement("div");
      wrap.className = "search-tools";
      input.parentNode.insertBefore(wrap, input);
      wrap.appendChild(input);
      var actions = document.createElement("span");
      actions.className = "search-tools-actions";
      var voice = textNode("button", "", "search-tool-button");
      voice.type = "button";
      voice.innerHTML = MIC_ICON; // Fixed, local icon markup only.
      voice.setAttribute("aria-label", "جست‌وجوی صوتی · " + (input.placeholder || "جست‌وجو"));
      voice.setAttribute("aria-pressed", "false");
      voice.title = "جست‌وجوی صوتی فارسی؛ پردازش با سرویس گفتار مرورگر";
      voice.addEventListener("click", function () { startVoice(input, voice); });
      actions.appendChild(voice);
      if (["globalSearch", "productSearch", "inventorySearch", "bulkPriceProductSearch"].indexOf(input.id) !== -1 || input.hasAttribute("data-barcode-search")) {
        var barcode = textNode("button", "", "search-tool-button");
        barcode.type = "button";
        barcode.innerHTML = BARCODE_ICON;
        barcode.setAttribute("aria-label", "اسکن بارکد · " + (input.placeholder || "محصول"));
        barcode.title = "اسکن بارکد با دوربین گوشی";
        barcode.addEventListener("click", function () { openScanner(input); });
        actions.appendChild(barcode);
        wrap.classList.add("has-barcode");
      }
      wrap.appendChild(actions);
    });
  }
  function stopCamera() {
    scannerGeneration += 1;
    clearTimeout(scanTimer);
    if (reader) { try { reader.reset(); } catch (error) { /* Still release our own tracks. */ } reader = null; }
    if (stream) { stream.getTracks().forEach(function (track) { track.stop(); }); stream = null; }
    var video = el("barcodeVideo");
    if (video) { video.pause(); video.srcObject = null; }
  }
  function scannerUrl() {
    var meta = document.querySelector('meta[name="fandoogh-barcode-reader-url"]');
    return meta ? meta.content : new URL("vendor/zxing-0.21.3.min.js", window.location.href).toString();
  }
  function loadScannerLibrary() {
    if (window.ZXing) return Promise.resolve(window.ZXing);
    if (scannerLibrary) return scannerLibrary;
    scannerLibrary = new Promise(function (resolve, reject) {
      var script = document.createElement("script");
      var timer = setTimeout(function () { script.remove(); scannerLibrary = null; reject(new Error("scanner-timeout")); }, 10000);
      script.src = scannerUrl();
      script.onload = function () { clearTimeout(timer); if (window.ZXing) resolve(window.ZXing); else { scannerLibrary = null; reject(new Error("scanner-missing")); } };
      script.onerror = function () { clearTimeout(timer); scannerLibrary = null; reject(new Error("scanner-load")); };
      document.head.appendChild(script);
    });
    return scannerLibrary;
  }
  function lookupBarcode(raw) {
    if (scannerBusy || !app.state.authenticated || !el("barcodeDialog").open) return Promise.resolve();
    var code = String(raw || "").trim().replace(/[۰-۹٠-٩]/g, function (digit) { var index = "۰۱۲۳۴۵۶۷۸۹".indexOf(digit); return String(index < 0 ? "٠١٢٣٤٥٦٧٨٩".indexOf(digit) : index); });
    if (!/^[A-Za-z0-9._-]{1,100}$/.test(code)) { el("barcodeStatus").textContent = "بارکد یا SKU معتبر وارد کنید."; return Promise.resolve(); }
    stopCamera();
    scannerBusy = true;
    el("barcodeManualCode").value = code;
    el("barcodeLookup").disabled = true;
    el("barcodeStatus").textContent = "در حال پیدا کردن محصول...";
    var epoch = scannerGeneration;
    var sessionEpoch = generation;
    var target = scanTarget;
    var result;
    if (app.state.previewMode) {
      var product = app.state.products.items.filter(function (item) { return String(item.sku) === code || String(item.global_unique_id || "") === code; })[0];
      result = product ? Promise.resolve({ data: { id: product.id, name: product.name, search: product.sku || product.name } }) : Promise.reject(new Error("preview-not-found"));
    } else {
      try { result = app.request(urlWith("barcodeUrl", { code: code })); } catch (error) { result = Promise.reject(error); }
    }
    return result.then(function (payload) {
      if (epoch !== scannerGeneration || sessionEpoch !== generation || !el("barcodeDialog").open || !app.state.authenticated) return;
      var product = payload && payload.data;
      if (!product || !product.id || typeof product.search !== "string") throw new Error("invalid-barcode-response");
      el("barcodeDialog").close();
      if (target && target.id === "posProductSearch") {
        target.dispatchEvent(new CustomEvent("fandoogh:barcode", { detail: product }));
      } else if (target && target.id === "globalSearch") {
        app.navigate("products");
        app.openProduct(product.parent_id || product.id, target);
      } else {
        inputValue(target, product.search);
        if (target) target.focus();
      }
      message("محصول پیدا شد: " + product.name);
    }).catch(function (error) {
      if (epoch !== scannerGeneration || sessionEpoch !== generation) return;
      el("barcodeStatus").textContent = requestError(error, app.state.previewMode ? "در دادهٔ نمونه محصولی با این SKU نیست؛ یکی از SKUهای نمونه را وارد کنید." : "محصول پیدا نشد یا اتصال برقرار نیست؛ بارکد و شناسهٔ محصول را بررسی کنید.");
    }).finally(function () { if (epoch === scannerGeneration) scannerBusy = false; el("barcodeLookup").disabled = false; });
  }
  function startCamera() {
    stopCamera();
    scannerBusy = false;
    var epoch = scannerGeneration;
    var video = el("barcodeVideo");
    el("barcodeStatus").textContent = "در حال آماده‌کردن دوربین...";
    if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      el("barcodeStatus").textContent = "دوربین به HTTPS و مرورگر سازگار نیاز دارد؛ می‌توانید کد را دستی وارد کنید.";
      return;
    }
    navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: "environment" }, width: { ideal: 1280 } }, audio: false }).then(function (camera) {
      if (epoch !== scannerGeneration || !el("barcodeDialog").open) { camera.getTracks().forEach(function (track) { track.stop(); }); return null; }
      stream = camera;
      video.srcObject = camera;
      return video.play().then(function () {
        el("barcodeStatus").textContent = "بارکد را مقابل دوربین بگیرید؛ نور و فاصله را تنظیم کنید.";
        return window.BarcodeDetector && window.BarcodeDetector.getSupportedFormats ? window.BarcodeDetector.getSupportedFormats().catch(function () { return []; }) : [];
      });
    }).then(function (formats) {
      if (!formats || epoch !== scannerGeneration || !el("barcodeDialog").open) return;
      var wanted = ["ean_13", "ean_8", "upc_a", "upc_e", "code_128", "code_39", "itf", "qr_code"].filter(function (format) { return formats.indexOf(format) !== -1; });
      if (window.BarcodeDetector && wanted.length) {
        var detector = new window.BarcodeDetector({ formats: wanted });
        function frame() {
          if (epoch !== scannerGeneration || !el("barcodeDialog").open) return;
          detector.detect(video).then(function (codes) {
            if (epoch !== scannerGeneration) return;
            var code = codes.filter(function (item) { return /^[A-Za-z0-9._-]{1,100}$/.test(item.rawValue); })[0];
            if (code) lookupBarcode(code.rawValue);
            else scanTimer = setTimeout(frame, 220);
          }).catch(function () { if (epoch === scannerGeneration) scanTimer = setTimeout(frame, 400); });
        }
        frame();
      } else {
        return loadScannerLibrary().then(function (ZXing) {
          if (epoch !== scannerGeneration || !el("barcodeDialog").open) return;
          reader = new ZXing.BrowserMultiFormatReader();
          return reader.decodeFromStream(stream, video, function (result) {
            if (result && epoch === scannerGeneration) lookupBarcode(result.getText());
          });
        });
      }
    }).catch(function (error) {
      if (epoch !== scannerGeneration) return;
      stopCamera();
      el("barcodeStatus").textContent = error.name === "NotAllowedError" ? "دسترسی دوربین رد شده؛ آن را در تنظیمات مرورگر فعال کنید یا کد را دستی وارد کنید." : "اسکنر آماده نشد؛ دوباره شروع کنید یا کد را دستی وارد کنید.";
    });
  }
  function openScanner(input) {
    if (!app.hasScope("products.read")) return;
    stopVoice();
    scanTarget = input;
    el("barcodeManualCode").value = "";
    el("barcodeDialog").showModal();
    startCamera();
  }
  function previewToday() {
    var orders = app.state.orders.items || [], stock = app.state.inventory.items || [];
    return { groups: [
      { kind: "payment", title: "نیازمند بررسی پرداخت", items: orders.filter(function (order) { return ["pending", "on-hold"].indexOf(order.statusValue) !== -1; }).map(function (order) { return { id: order.id, title: "سفارش #" + (order.number || order.id), section: "orders" }; }) },
      { kind: "shipping", title: "آمادهٔ ارسال", items: orders.filter(function (order) { return order.statusValue === "processing"; }).map(function (order) { return { id: order.id, title: "سفارش #" + (order.number || order.id), section: "orders", late: false }; }) },
      { kind: "stock", title: "نیازمند تأمین موجودی", items: stock.filter(function (product) { return product.lowStock || product.stockStatus === "outofstock"; }).map(function (product) { return { id: product.id, title: product.name, section: "products", quantity: product.quantity === "" ? null : product.quantity }; }) }
    ] };
  }
  function renderToday(data) {
    var root = el("todayGroups");
    root.replaceChildren();
    data.groups.forEach(function (group) {
      var section = textNode("section", "", "today-group");
      var count = typeof group.count === "number" ? group.count : group.items.length;
      section.appendChild(textNode("h3", group.title + " · " + count.toLocaleString("fa-IR")));
      if (group.paged) section.appendChild(textNode("p", "نتیجهٔ بررسی این صفحه از سفارش‌ها یا محصولات", "operations-help"));
      if (!group.items.length) section.appendChild(textNode("p", group.paged && group.pages > 1 ? "در این صفحه موردی نیست؛ صفحه‌های دیگر را هم بررسی کنید." : "موردی برای پیگیری نیست.", "operations-help"));
      var list = document.createElement("ul");
      group.items.forEach(function (item) {
        var li = document.createElement("li");
        var label = item.title + (item.late ? " · تأخیر بیش از ۴۸ ساعت" : "");
        if (group.kind === "stock" && item.quantity !== null && typeof item.quantity !== "undefined") label += " · موجودی " + Number(item.quantity).toLocaleString("fa-IR");
        var button = textNode("button", label, "today-task-button");
        button.type = "button";
        if (item.late) button.classList.add("is-late");
        button.addEventListener("click", function () {
          app.navigate(item.section);
          if (item.section === "orders") app.openOrder(item.id);
          else app.openProduct(item.parent_id || item.id, button);
        });
        li.appendChild(button); list.appendChild(li);
      });
      section.appendChild(list);
      if (group.kind === "payment" && count > group.items.length) {
        var all = textNode("button", "مشاهدهٔ همه در سفارش‌ها", "secondary-button");
        all.type = "button"; all.addEventListener("click", function () { app.navigate("orders"); }); section.appendChild(all);
      }
      if (group.paged && group.pages > 1) {
        var pagination = document.createElement("div"); pagination.className = "operations-inline";
        [-1, 1].forEach(function (direction) {
          var button = textNode("button", direction < 0 ? "صفحهٔ قبل" : "صفحهٔ بعد", "secondary-button");
          button.type = "button";
          button.disabled = direction < 0 ? group.page <= 1 : group.page >= group.pages;
          button.addEventListener("click", function () { todayPages[group.kind === "stock" ? "inventory_page" : "order_page"] = group.page + direction; loadToday(); });
          pagination.appendChild(button);
        });
        pagination.appendChild(textNode("span", group.page.toLocaleString("fa-IR") + " / " + group.pages.toLocaleString("fa-IR")));
        section.appendChild(pagination);
      }
      root.appendChild(section);
    });
  }
  function loadToday() {
    if (!app || !app.state.authenticated) return Promise.resolve();
    el("todayPanel").hidden = !app.hasScope("orders.read") && !app.hasScope("inventory.read");
    if (el("todayPanel").hidden) return Promise.resolve();
    var epoch = generation, id = ++todayRequest;
    el("todayRefresh").disabled = true;
    el("todayStatus").textContent = "در حال دریافت کارهای امروز...";
    var pending;
    try { pending = app.state.previewMode ? Promise.resolve({ data: previewToday() }) : app.request(urlWith("todayUrl", todayPages)); } catch (error) { pending = Promise.reject(error); }
    return pending.then(function (payload) {
      if (epoch !== generation || id !== todayRequest || !app.state.authenticated) return;
      if (!payload || !payload.data || !Array.isArray(payload.data.groups)) throw new Error("invalid-today");
      renderToday(payload.data);
      el("todayStatus").textContent = app.state.previewMode ? "دادهٔ نمونه؛ تغییری در فروشگاه انجام نمی‌شود." : "به‌روز شد · " + new Date().toLocaleTimeString("fa-IR", { hour: "2-digit", minute: "2-digit" });
    }).catch(function (error) {
      if (epoch !== generation || id !== todayRequest) return;
      el("todayGroups").replaceChildren();
      el("todayStatus").textContent = requestError(error, "کارهای امروز دریافت نشد؛ دوباره تازه‌سازی کنید.");
    }).finally(function () { if (epoch === generation && id === todayRequest) el("todayRefresh").disabled = false; });
  }
  function pushSupported() { return window.isSecureContext && "Notification" in window && "PushManager" in window && "serviceWorker" in navigator; }
  function setPushBusy(busy) {
    el("pushSettingsForm").setAttribute("aria-busy", String(busy));
    el("pushEnable").disabled = busy || !pushState || !pushState.available;
    el("pushDisable").disabled = busy || !pushState || !pushState.subscribed;
    el("pushTest").disabled = busy || !pushState || !pushState.subscribed;
  }
  function applyPushStatus(data) {
    pushState = data;
    var preferences = data.preferences || {};
    el("pushNewOrder").checked = preferences.new_order !== false;
    el("pushLowStock").checked = preferences.low_stock !== false;
    el("pushDelayedOrder").checked = preferences.delayed_order !== false;
    el("pushNewOrder").disabled = !app.hasScope("orders.read");
    el("pushDelayedOrder").disabled = !app.hasScope("orders.read");
    el("pushLowStock").disabled = !app.hasScope("inventory.read");
    el("pushQuietStart").value = preferences.quiet_start || "";
    el("pushQuietEnd").value = preferences.quiet_end || "";
    el("pushTimezone").textContent = "ساعت سکوت بر اساس منطقهٔ زمانی فروشگاه: " + (data.timezone || "تنظیمات وردپرس") + ". هشدارها پس از سکوت تجمیع می‌شوند.";
    el("pushEnable").textContent = data.subscribed ? "ذخیرهٔ تنظیمات / اتصال دوباره" : "فعال‌کردن اعلان‌ها";
    el("pushSettingsStatus").textContent = !data.available ? "رمزنگاری اعلان روی میزبان آماده نیست؛ OpenSSL با پشتیبانی EC لازم است." : data.last_error ? "آخرین ارسال ناموفق بود؛ اتصال دوباره و اعلان آزمایشی را بررسی کنید." : data.subscribed ? "اعلان‌های این گوشی فعال است." : "اعلان‌های این گوشی خاموش است. برای فعال‌کردن، اجازهٔ مرورگر لازم است.";
    if (window.Notification && Notification.permission === "denied") el("pushSettingsStatus").textContent = "اجازهٔ اعلان رد شده؛ آن را در تنظیمات مرورگر یا گوشی فعال کنید.";
    setPushBusy(false);
  }
  function openPushSettings() {
    if (!app.state.authenticated) return;
    el("pushSettingsDialog").showModal();
    pushState = null; setPushBusy(true);
    if (app.state.previewMode) { el("pushSettingsStatus").textContent = "در پیش‌نمایش، اعلان واقعی ثبت یا ارسال نمی‌شود. پس از اتصال به فروشگاه این بخش فعال است."; return; }
    if (!pushSupported()) { el("pushSettingsStatus").textContent = "این محیط اعلان گوشی را پشتیبانی نمی‌کند. پنل را با HTTPS و مرورگر سازگار باز کنید؛ روی آیفون از وب‌اپ نصب‌شده در صفحهٔ اصلی استفاده کنید."; return; }
    var epoch = generation;
    app.request(urlWith("pushUrl")).then(function (payload) {
      if (epoch === generation && el("pushSettingsDialog").open) applyPushStatus(payload.data);
    }).catch(function (error) { if (epoch === generation) el("pushSettingsStatus").textContent = requestError(error, "تنظیمات اعلان دریافت نشد؛ دوباره این پنجره را باز کنید."); });
  }
  function waitForWorker() {
    return new Promise(function (resolve, reject) {
      var timer = setTimeout(function () { reject(new Error("worker-timeout")); }, 8000);
      navigator.serviceWorker.ready.then(function (worker) { clearTimeout(timer); resolve(worker); }, function (error) { clearTimeout(timer); reject(error); });
    });
  }
  function vapidBytes(value) {
    var binary = atob(value.replace(/-/g, "+").replace(/_/g, "/") + "=".repeat((4 - value.length % 4) % 4));
    return Uint8Array.from(binary, function (char) { return char.charCodeAt(0); });
  }
  function savePush(event) {
    event.preventDefault();
    if (!pushState || !pushState.available || !app.state.authenticated || app.state.previewMode) return;
    var start = el("pushQuietStart").value, end = el("pushQuietEnd").value;
    if (Boolean(start) !== Boolean(end)) { el("pushSettingsStatus").textContent = "برای ساعت سکوت، شروع و پایان را هر دو وارد کنید یا هر دو را خالی بگذارید."; return; }
    var preferences = { new_order: el("pushNewOrder").checked, low_stock: el("pushLowStock").checked, delayed_order: el("pushDelayedOrder").checked, quiet_start: start, quiet_end: end };
    var epoch = generation, subscription, created = false;
    // Request permission synchronously in the click/submit gesture (required by iOS).
    var permission = Notification.permission === "granted" ? Promise.resolve("granted") : Notification.requestPermission();
    setPushBusy(true);
    el("pushSettingsStatus").textContent = "در حال فعال‌کردن اعلان‌ها...";
    permission.then(function (result) {
      if (result !== "granted") throw new Error("permission-denied");
      if (epoch !== generation) throw new Error("session-changed");
      return waitForWorker();
    }).then(function (worker) {
      if (epoch !== generation) throw new Error("session-changed");
      return worker.pushManager.getSubscription().then(function (existing) {
        var key = vapidBytes(pushState.public_key);
        var oldKey = existing && existing.options && existing.options.applicationServerKey;
        var matches = !oldKey || (new Uint8Array(oldKey).length === key.length && new Uint8Array(oldKey).every(function (byte, index) { return byte === key[index]; }));
        if (existing && matches) return existing;
        return (existing ? existing.unsubscribe() : Promise.resolve()).then(function () {
          created = true;
          return worker.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
        });
      });
    }).then(function (value) {
      subscription = value;
      if (epoch !== generation) throw new Error("session-changed");
      return write("pushUrl", { subscription: subscription.toJSON(), preferences: preferences });
    }).then(function (payload) { if (epoch === generation) applyPushStatus(payload.data); }).catch(function (error) {
      // Roll back only a newly created browser subscription if server registration failed.
      if (created && subscription) subscription.unsubscribe().catch(function () {});
      if (epoch !== generation) return;
      el("pushSettingsStatus").textContent = requestError(error, error.message === "permission-denied" ? "اعلان اجازه نگرفت؛ اجازه را در تنظیمات مرورگر فعال کنید." : "فعال‌کردن اعلان انجام نشد؛ اتصال و نصب وب‌اپ را بررسی کنید.");
    }).finally(function () { if (epoch === generation) setPushBusy(false); });
  }
  function disablePush() {
    if (!pushState || !app.state.authenticated || app.state.previewMode) return;
    var epoch = generation;
    setPushBusy(true);
    write("pushUrl", null, "DELETE").then(function () {
      if (epoch !== generation) return;
      pushState.subscribed = false;
      el("pushSettingsStatus").textContent = "اعلان‌های این گوشی خاموش شد.";
      return waitForWorker().then(function (worker) { return worker.pushManager.getSubscription(); }).then(function (subscription) { return subscription && subscription.unsubscribe(); }).catch(function () { /* Server registration has already been deleted. */ });
    }).catch(function (error) { if (epoch === generation) el("pushSettingsStatus").textContent = requestError(error, "خاموش‌کردن اعلان انجام نشد؛ دوباره تلاش کنید."); }).finally(function () { if (epoch === generation) setPushBusy(false); });
  }
  function testPush() {
    var epoch = generation;
    if (!pushState || !pushState.subscribed || app.state.previewMode) return;
    setPushBusy(true);
    write("pushTestUrl", {}).then(function () { if (epoch === generation) el("pushSettingsStatus").textContent = "اعلان آزمایشی برای سرویس گوشی ارسال شد."; }).catch(function (error) { if (epoch === generation) el("pushSettingsStatus").textContent = requestError(error, "ارسال آزمایشی انجام نشد؛ اتصال دوباره را امتحان کنید."); }).finally(function () { if (epoch === generation) setPushBusy(false); });
  }
  function reset() {
    generation += 1; todayRequest += 1; pushState = null;
    todayPages = { order_page: 1, inventory_page: 1 };
    stopVoice(); stopCamera();
    ["barcodeDialog", "pushSettingsDialog"].forEach(function (id) { if (el(id) && el(id).open) el(id).close(); });
    if (el("todayGroups")) el("todayGroups").replaceChildren();
    if (el("todayStatus")) el("todayStatus").textContent = "پس از اتصال، کارهای فروشگاه نمایش داده می‌شوند.";
    if (el("todayRefresh")) el("todayRefresh").disabled = false;
    if (el("searchAssistStatus")) el("searchAssistStatus").hidden = true;
  }
  function init(adapter) {
    app = adapter;
    enhanceSearches();
    new MutationObserver(function (records) { if (records.some(function (record) { return record.addedNodes.length; })) enhanceSearches(); }).observe(document.body, { childList: true, subtree: true });
    document.querySelectorAll("[data-open-push-settings]").forEach(function (button) { button.addEventListener("click", openPushSettings); });
    el("todayRefresh").addEventListener("click", function () { loadToday(); });
    el("barcodeClose").addEventListener("click", function () { stopCamera(); el("barcodeDialog").close(); });
    el("barcodeDialog").addEventListener("close", stopCamera);
    el("barcodeDialog").addEventListener("cancel", stopCamera);
    el("barcodeRestart").addEventListener("click", startCamera);
    el("barcodeManualForm").addEventListener("submit", function (event) { event.preventDefault(); lookupBarcode(el("barcodeManualCode").value); });
    el("pushSettingsClose").addEventListener("click", function () { el("pushSettingsDialog").close(); });
    el("pushSettingsForm").addEventListener("submit", savePush);
    el("pushDisable").addEventListener("click", disablePush);
    el("pushTest").addEventListener("click", testPush);
    document.addEventListener("visibilitychange", function () { if (document.hidden) { stopVoice(); stopCamera(); } else if (app.state.authenticated && !el("todayPanel").hidden) loadToday(); });
    window.addEventListener("pagehide", function () { stopVoice(); stopCamera(); });
    todayTimer = setInterval(function () { if (app.state.authenticated && !app.state.previewMode && !document.hidden && el("todayPanel").getClientRects().length) loadToday(); }, 90000);
  }
  window.FandooghOperations = { init: init, reset: reset, loadToday: loadToday };
}());
