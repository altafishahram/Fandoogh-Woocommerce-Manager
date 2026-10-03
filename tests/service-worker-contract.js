// Standalone contract: node tests/service-worker-contract.js
"use strict";

const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const test = require("node:test");
const vm = require("node:vm");

const source = fs.readFileSync(path.join(__dirname, "../app/sw.js"), "utf8");
const CACHE_NAME = "fandoogh-manager-shell-v43";
const ASSET_QUERY = "fandoogh_manager_asset";
const FILENAMES = ["styles.css", "app.js", "operations.js", "pos.js", "pos.css", "invoice.css", "barcode-reader.js", "fonts.css", "ui.css", "manifest.webmanifest"];
const CASES = [
  { name: "local static root", origin: "http://127.0.0.1:4173", base: "/", query: false },
  { name: "query root", origin: "https://example.test", base: "/", query: true },
  { name: "legacy manager", origin: "https://example.test", base: "/manager/", query: false },
  { name: "query manager", origin: "https://example.test", base: "/manager/", query: true },
  { name: "legacy subdirectory", origin: "https://example.test", base: "/shop/manager/", query: false },
  { name: "query subdirectory", origin: "https://example.test", base: "/shop/manager/", query: true },
];

function workerUrl(scenario, version = "fingerprint-123") {
  const script = scenario.query ? `?${ASSET_QUERY}=sw.js` : "sw.js";
  const suffix = version === null ? "" : `${scenario.query ? "&" : "?"}ver=${encodeURIComponent(version)}`;
  return scenario.origin + scenario.base + script + suffix;
}

function response(body, { status = 200, type = "basic" } = {}) {
  return {
    body, status, type,
    clone() { return response(body, { status, type }); },
  };
}

function loadWorker(url, { failedAdds = [], failPut = false } = {}) {
  const handlers = new Map();
  const buckets = new Map();
  const calls = { add: [], open: [], put: [], match: [], fetch: [], delete: [], lifecycle: [], skipWaiting: 0, errors: 0, notifications: [], opened: [] };
  const errorResponse = response("", { status: 0, type: "error" });
  let network = () => response("network");

  function cacheKey(input) {
    const parsed = new URL(typeof input === "string" ? input : input.url, url);
    parsed.hash = "";
    return parsed.href;
  }

  function bucket(name) {
    if (!buckets.has(name)) buckets.set(name, new Map());
    return buckets.get(name);
  }

  const context = vm.createContext({
    URL,
    self: {
      location: new URL(url),
      addEventListener(type, handler) {
        assert.equal(handlers.has(type), false, `duplicate ${type} listener`);
        handlers.set(type, handler);
      },
      async skipWaiting() { calls.skipWaiting += 1; },
      registration: { async showNotification(title, options) { calls.notifications.push({ title, options }); } },
      clients: { async claim() { calls.lifecycle.push("claim"); }, async matchAll() { return []; }, async openWindow(url) { calls.opened.push(url); } },
    },
    caches: {
      async open(name) {
        calls.open.push(name);
        const entries = bucket(name);
        return {
          async add(input) {
            calls.add.push(input);
            if (failedAdds.includes(input)) throw new Error("precache unavailable");
            entries.set(cacheKey(input), response(`precache:${input}`));
          },
          async put(request, result) {
            calls.put.push({ request, response: result, cache: name });
            if (failPut) throw new Error("cache write failed");
            entries.set(cacheKey(request), result);
          },
        };
      },
      async match(request) {
        calls.match.push(request);
        for (const entries of buckets.values()) {
          if (entries.has(cacheKey(request))) return entries.get(cacheKey(request));
        }
        return undefined;
      },
      async keys() {
        calls.lifecycle.push("keys");
        return [...buckets.keys()];
      },
      async delete(name) {
        calls.delete.push(name);
        calls.lifecycle.push(`delete:${name}`);
        return buckets.delete(name);
      },
    },
    async fetch(request) {
      calls.fetch.push(request);
      return network(request);
    },
    Response: {
      error() {
        calls.errors += 1;
        return errorResponse;
      },
    },
  });
  vm.runInContext(source, context, { filename: "app/sw.js", timeout: 1000 });

  async function dispatch(type, fields = {}) {
    const pending = [];
    let intercepted = false;
    let result;
    assert.equal(typeof handlers.get(type), "function", `${type} listener must exist`);
    handlers.get(type)({
      ...fields,
      waitUntil(promise) { pending.push(promise); },
      respondWith(promise) {
        assert.equal(intercepted, false, "respondWith called only once");
        intercepted = true;
        result = promise;
      },
    });
    await Promise.all(pending);
    return { intercepted, response: await result, waitUntilCount: pending.length };
  }

  return {
    calls, buckets, dispatch, errorResponse,
    setNetwork(handler) { network = handler; },
    seed(name, assetUrl, result) { bucket(name).set(cacheKey(assetUrl), result); },
    request(assetUrl, method = "GET") {
      return dispatch("fetch", { request: { url: new URL(assetUrl, url).href, method } });
    },
  };
}

for (const scenario of CASES) {
  test(`${scenario.name}: push renders safely and clicks stay in the app scope`, async () => {
    const worker = loadWorker(workerUrl(scenario));
    await worker.dispatch('push', { data: { json: () => ({ kind: 'low_stock', body: 'کمبود موجودی', url: 'https://evil.test/' }) } });
    assert.equal(worker.calls.notifications[0].options.tag, 'fandoogh-low_stock');
    let closed = false;
    await worker.dispatch('notificationclick', { notification: { close() { closed = true; }, data: { url: 'https://evil.test/' } } });
    assert.equal(closed, true);
    assert.deepEqual(worker.calls.opened, [scenario.origin + scenario.base + '#dashboard']);
    await worker.dispatch('push', { data: { json() { throw new Error('bad JSON'); } } });
    assert.equal(worker.calls.notifications[1].options.body, 'کارهای فروشگاه را در پنل بررسی کنید.');
    assert.equal(worker.calls.fetch.length, 0);
  });
  test(`${scenario.name}: precache URLs retain the base, route style, and fingerprint`, async () => {
    const worker = loadWorker(workerUrl(scenario));
    const result = await worker.dispatch("install");
    const prefix = scenario.query ? `${scenario.base}?${ASSET_QUERY}=` : scenario.base;
    const suffix = scenario.query ? "&ver=fingerprint-123" : "?ver=fingerprint-123";
    assert.deepEqual(worker.calls.add, [
      scenario.base,
      `${prefix}styles.css${suffix}`,
      `${prefix}app.js${suffix}`,
      `${prefix}operations.js${suffix}`,
      `${prefix}pos.js${suffix}`,
      `${prefix}pos.css${suffix}`,
      `${prefix}invoice.css${suffix}`,
      `${prefix}barcode-reader.js${suffix}`,
      `${prefix}fonts.css${suffix}`,
      `${prefix}ui.css${suffix}`,
      `${prefix}manifest.webmanifest${suffix}`,
    ]);
    assert.deepEqual(worker.calls.open, [CACHE_NAME]);
    assert.equal(result.waitUntilCount, 1);
    assert.equal(worker.calls.skipWaiting, 0, "installation must preserve the update prompt");
    assert.equal(worker.buckets.get(CACHE_NAME).size, FILENAMES.length + 1);
  });

  test(`${scenario.name}: allow only the public shell and exact query/legacy assets`, async () => {
    const worker = loadWorker(workerUrl(scenario));
    const base = scenario.base;
    const allowed = [base, `${base}?ver=next`, `${base}#products`];
    for (const preview of ["1", "true", "demo"]) {
      allowed.push(`${base}?preview=${preview}`, `${base}?ver=next&preview=${preview}#products`);
    }
    for (const filename of FILENAMES) {
      allowed.push(
        `${base}${filename}`, `${base}${filename}?ver=next#public`,
        `${base}?${ASSET_QUERY}=${filename}`,
        `${base}?${ASSET_QUERY}=${filename}&ver=next`,
        `${base}?ver=next&${ASSET_QUERY}=${filename}#public`,
      );
    }
    for (const assetUrl of allowed) {
      const result = await worker.request(assetUrl);
      assert.equal(result.intercepted, true, assetUrl);
      assert.equal(result.response.body, "network", assetUrl);
    }
    assert.equal(worker.calls.fetch.length, allowed.length);
    assert.equal(worker.calls.put.length, allowed.length);
    assert.equal(worker.calls.match.length, 0, "network successes must not read the cache");
  });

  test(`${scenario.name}: REST, auth, unknown assets and ambiguous queries bypass all worker I/O`, async () => {
    const worker = loadWorker(workerUrl(scenario));
    const base = scenario.base;
    const denied = [
      "/wp-json", "/wp-json/", "/wp-json/fandoogh-manager/v1/config",
      "/wp-json/fandoogh-manager/v1/pos/sales", "/wp-json/fandoogh-manager/v1/orders/91/invoice",
      "/shop/wp-json", "/shop/wp-json/fandoogh-manager/v1/auth/login",
      "/shop/?rest_route=/fandoogh-manager/v1/config",
      `${base}wp-json`, `${base}wp-json/fandoogh-manager/v1/config`,
      `${base}auth`, `${base}auth/login`, `${base}login`, `${base}logout`,
      `${base}config`, `${base}orders`, `${base}products`,
      "/wp-admin/", "/wp-login.php", "/shop/wp-admin/admin-ajax.php",
      `${base}index.html`, `${base}index.php`, `${base}inventory.php`,
      `${base}styles.css/extra`, `${base}Styles.css`, `${base}assets/app.js`,
      `${base}fonts.woff2`, `${base}assets/icon/fandoogh-dashboard.svg`,
      `${base}sw.js`, `${base}sw.js?ver=next`,
      `${base}?${ASSET_QUERY}=sw.js&ver=next`,
      `${base}?${ASSET_QUERY}=config`, `${base}?${ASSET_QUERY}=index.html`,
      `${base}?${ASSET_QUERY}=inventory.php`, `${base}?${ASSET_QUERY}=unknown.css`,
      `${base}?${ASSET_QUERY}=Styles.css`, `${base}?${ASSET_QUERY}=styles.css/extra`,
      `${base}?${ASSET_QUERY}=../app.js`, `${base}?${ASSET_QUERY}=%2Fapp.js`,
      `${base}?${ASSET_QUERY}=`, `${base}?${ASSET_QUERY}`,
      `${base}?preview=0`, `${base}?preview=false`, `${base}?preview=unknown`, `${base}?preview=`,
      `${base}?preview=1&preview=demo`, `${base}?ver=one&ver=two`,
      `${base}?${ASSET_QUERY}=app.js&${ASSET_QUERY}=styles.css`,
      `${base}?${ASSET_QUERY}=app.js&${ASSET_QUERY}=app.js`,
      `${base}?${ASSET_QUERY}[]=app.js`, `${base}?ver[]=one`,
      `${base}app.js?${ASSET_QUERY}=styles.css`,
      `${base}other/?${ASSET_QUERY}=app.js`,
      `https://unrelated.test${base}`,
      `https://unrelated.test${base}app.js?ver=next`,
      `https://unrelated.test${base}?${ASSET_QUERY}=app.js&ver=next`,
      `${scenario.origin.replace(/^https?:/, scenario.origin.startsWith("https:") ? "http:" : "https:")}${base}app.js`,
    ];
    if (base !== "/") denied.push(base.slice(0, -1), "/app.js", `/?${ASSET_QUERY}=app.js`);
    if (base === "/shop/manager/") denied.push("/manager/", "/manager/app.js", `/manager/?${ASSET_QUERY}=app.js`);

    // Every allowed route must still reject REST and private query selectors.
    const publicRoutes = [base, ...FILENAMES.map(filename => base + filename),
      ...FILENAMES.map(filename => `${base}?${ASSET_QUERY}=${filename}`)];
    for (const route of publicRoutes) {
      const separator = route.includes("?") ? "&" : "?";
      for (const query of [
        "rest_route=/fandoogh-manager/v1/config", "rest_route=", "rest_route",
        "%72est_route=%2Ffandoogh-manager%2Fv1%2Fauth%2Flogin",
        "ver=next&rest_route=/wc/v3/orders", "rest_route[]=private",
        "auth=1", "token=secret", "_wpnonce=secret", "action=logout",
        "redirect_to=/wp-admin/", "wc-ajax=get_refreshed_fragments",
        "unknown=1", "unknown", "ver=one&ver=two", "VER=next",
      ]) denied.push(route + separator + query);
      if (route !== base) denied.push(route + separator + "preview=1");
      for (const method of ["POST", "PUT", "PATCH", "DELETE", "HEAD", "OPTIONS"]) {
        assert.equal((await worker.request(route, method)).intercepted, false, `${method} ${route}`);
      }
    }
    for (const assetUrl of denied) {
      assert.equal((await worker.request(assetUrl)).intercepted, false, assetUrl);
    }
    assert.deepEqual(worker.calls.fetch, []);
    assert.deepEqual(worker.calls.open, []);
    assert.deepEqual(worker.calls.put, []);
    assert.deepEqual(worker.calls.match, []);
    assert.equal(worker.buckets.size, 0);
  });
}

test("precache omits absent versions and encodes fingerprint values without inheriting other worker parameters", async () => {
  for (const scenario of CASES) {
    const unversioned = loadWorker(workerUrl(scenario, null));
    await unversioned.dispatch("install");
    assert.equal(unversioned.calls.add[0], scenario.base);
    for (const assetUrl of unversioned.calls.add.slice(1)) {
      assert.equal(new URL(assetUrl, scenario.origin).searchParams.has("ver"), false, assetUrl);
    }
    const version = "build +/&?=#";
    const versioned = loadWorker(workerUrl(scenario, version) + "&unknown=ignored#worker");
    await versioned.dispatch("install");
    assert.equal(versioned.calls.add[0], scenario.base);
    for (const assetUrl of versioned.calls.add.slice(1)) {
      const parsed = new URL(assetUrl, scenario.origin);
      assert.equal(parsed.searchParams.get("ver"), version, assetUrl);
      assert.equal(parsed.searchParams.has("unknown"), false, assetUrl);
      assert.equal(parsed.hash, "", assetUrl);
      assert.equal(parsed.searchParams.size, scenario.query ? 2 : 1, assetUrl);
    }
  }
});

test("installation tolerates individual and total precache failures", async () => {
  for (const scenario of CASES) {
    const available = loadWorker(workerUrl(scenario));
    await available.dispatch("install");
    for (const failedAdds of [[scenario.base], [available.calls.add[1]], available.calls.add]) {
      const worker = loadWorker(workerUrl(scenario), { failedAdds });
      assert.equal((await worker.dispatch("install")).waitUntilCount, 1);
      assert.deepEqual(worker.calls.add, available.calls.add, "attempt every public entry");
      assert.equal(worker.buckets.get(CACHE_NAME).size, FILENAMES.length + 1 - failedAdds.length);
    }
  }
});

test("activation removes only obsolete manager shell caches before claiming clients", async () => {
  const worker = loadWorker(workerUrl(CASES[5]));
  for (const name of ["fandoogh-manager-shell-v1", "fandoogh-manager-shell-v34", CACHE_NAME, "other-app-v1", "fandoogh-manager-data-v1"]) {
    worker.seed(name, "/", response(name));
  }
  const result = await worker.dispatch("activate");
  assert.equal(result.waitUntilCount, 1);
  assert.deepEqual(worker.calls.delete, ["fandoogh-manager-shell-v1", "fandoogh-manager-shell-v34"]);
  assert.deepEqual([...worker.buckets.keys()], [CACHE_NAME, "other-app-v1", "fandoogh-manager-data-v1"]);
  assert.deepEqual(worker.calls.lifecycle, ["keys", "delete:fandoogh-manager-shell-v1", "delete:fandoogh-manager-shell-v34", "claim"]);
  assert.equal(worker.calls.skipWaiting, 0);
});

test("only the explicit update message skips waiting", async () => {
  const worker = loadWorker(workerUrl(CASES[3]));
  for (const data of [undefined, null, {}, { type: "OTHER" }, "SKIP_WAITING"]) {
    await worker.dispatch("message", { data });
  }
  assert.equal(worker.calls.skipWaiting, 0);
  await worker.dispatch("message", { data: { type: "SKIP_WAITING" } });
  assert.equal(worker.calls.skipWaiting, 1);
});

test("network-first returns fresh basic 200 responses, caches clones and falls back on network failure", async () => {
  for (const scenario of CASES) {
    const worker = loadWorker(workerUrl(scenario));
    await worker.dispatch("install");
    const assetUrl = worker.calls.add[1];
    const fresh = response("fresh stylesheet");
    worker.setNetwork(() => fresh);
    const online = await worker.request(assetUrl);
    assert.equal(online.intercepted, true);
    assert.equal(online.response, fresh, scenario.name);
    assert.equal(worker.calls.match.length, 0);
    assert.equal(worker.calls.put.length, 1);
    const stored = worker.calls.put[0];
    assert.equal(stored.cache, CACHE_NAME);
    assert.equal(stored.request, worker.calls.fetch[0], "cache the original request including its query");
    assert.notEqual(stored.response, fresh, "cache a clone so the response body remains readable");
    assert.equal(stored.response.body, fresh.body);

    worker.setNetwork(() => { throw new Error("offline"); });
    const offline = await worker.request(assetUrl);
    assert.equal(offline.response, stored.response, scenario.name);
    assert.equal(worker.calls.fetch.length, 2, "try the network even with a warm cache");
    assert.equal(worker.calls.match.length, 1);
    assert.equal(worker.calls.put.length, 1);
  }
});

test("offline misses return Response.error without borrowing a shell, route or version", async () => {
  const worker = loadWorker(workerUrl(CASES[5]));
  worker.seed(CACHE_NAME, "/shop/manager/", response("shell"));
  worker.seed(CACHE_NAME, `/shop/manager/?${ASSET_QUERY}=app.js&ver=old`, response("old script"));
  worker.seed(CACHE_NAME, "/shop/manager/app.js?ver=new", response("legacy script"));
  worker.setNetwork(() => { throw new Error("offline"); });
  const result = await worker.request(`/shop/manager/?${ASSET_QUERY}=app.js&ver=new`);
  assert.equal(result.response, worker.errorResponse);
  assert.equal(worker.calls.errors, 1);
  assert.equal(worker.calls.match.length, 1);
  assert.deepEqual(worker.calls.put, []);
});

test("HTTP errors and non-basic responses pass through without caching or offline fallback", async () => {
  const worker = loadWorker(workerUrl(CASES[3]));
  const assetUrl = `/manager/?${ASSET_QUERY}=app.js&ver=current`;
  worker.seed(CACHE_NAME, assetUrl, response("cached script"));
  for (const options of [
    { status: 204 }, { status: 301 }, { status: 401 }, { status: 403 }, { status: 404 }, { status: 500 },
    { type: "cors" }, { type: "default" }, { status: 0, type: "opaque" }, { status: 0, type: "opaqueredirect" },
  ]) {
    const networkResponse = response("uncacheable", options);
    worker.setNetwork(() => networkResponse);
    assert.equal((await worker.request(assetUrl)).response, networkResponse);
  }
  assert.deepEqual(worker.calls.open, []);
  assert.deepEqual(worker.calls.put, []);
  assert.deepEqual(worker.calls.match, []);
});

test("cache-write failures preserve the existing network-first fallback", async () => {
  const worker = loadWorker(workerUrl(CASES[3]), { failPut: true });
  const assetUrl = "/manager/styles.css?ver=current";
  const cached = response("cached stylesheet");
  worker.seed(CACHE_NAME, assetUrl, cached);
  assert.equal((await worker.request(assetUrl)).response, cached);
  assert.equal(worker.calls.fetch.length, 1);
  assert.equal(worker.calls.put.length, 1);
  assert.equal(worker.calls.match.length, 1);
});
