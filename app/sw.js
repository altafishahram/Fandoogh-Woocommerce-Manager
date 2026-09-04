/*
 * Fandoogh Manager service worker.
 * Keep this allowlist intentionally small: API responses and private routes
 * must always stay outside the cache.
 */
"use strict";

var CACHE_NAME = "fandoogh-manager-shell-v33";
var WORKER_VERSION = new URL(self.location.href).searchParams.get("ver") || "base";
var MANAGER_BASE = new URL("./", self.location.href).pathname;
var PUBLIC_ASSET_PATHS = [
  MANAGER_BASE,
  MANAGER_BASE + "styles.css",
  MANAGER_BASE + "app.js",
  MANAGER_BASE + "fonts.css"
];
var PUBLIC_ASSETS = [MANAGER_BASE].concat(PUBLIC_ASSET_PATHS.slice(1).map(function (assetPath) {
  return WORKER_VERSION === "base" ? assetPath : assetPath + "?ver=" + encodeURIComponent(WORKER_VERSION);
}));

function isWpJsonPath(url) {
  return url.pathname === "/wp-json" || url.pathname.indexOf("/wp-json/") === 0;
}

function isPublicAsset(url) {
  return url.origin === self.location.origin && PUBLIC_ASSET_PATHS.indexOf(url.pathname) !== -1;
}

function isGetRequest(request) {
  return request.method === "GET";
}

function cacheNetworkResponse(request, networkResponse) {
  if (!networkResponse || networkResponse.status !== 200 || networkResponse.type !== "basic") {
    return Promise.resolve(networkResponse);
  }

  return caches.open(CACHE_NAME).then(function (cache) {
    return cache.put(request, networkResponse.clone()).then(function () {
      return networkResponse;
    });
  });
}

function networkFirst(request) {
  return fetch(request).then(function (networkResponse) {
    return cacheNetworkResponse(request, networkResponse);
  }).catch(function () {
    return caches.match(request).then(function (cachedResponse) {
      return cachedResponse || Response.error();
    });
  });
}

self.addEventListener("install", function (event) {
  event.waitUntil(
    caches.open(CACHE_NAME).then(function (cache) {
      return Promise.all(PUBLIC_ASSETS.map(function (assetUrl) {
        return cache.add(assetUrl).catch(function () {
          // A host may expose the shell through PHP instead of a static file.
          // Missing public entries must not prevent the worker from installing.
        });
      }));
    })
  );
});

self.addEventListener("message", function (event) {
  if (event.data && event.data.type === "SKIP_WAITING") {
    self.skipWaiting();
  }
});

self.addEventListener("activate", function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.filter(function (key) {
        return key.indexOf("fandoogh-manager-shell-") === 0 && key !== CACHE_NAME;
      }).map(function (key) {
        return caches.delete(key);
      }));
    }).then(function () {
      return self.clients.claim();
    })
  );
});

self.addEventListener("fetch", function (event) {
  var request = event.request;
  var url = new URL(request.url);

  // Never intercept, read from, or write to cache for REST API requests.
  if (!isGetRequest(request) || isWpJsonPath(url)) {
    return;
  }

  if (!isPublicAsset(url)) {
    return;
  }

  event.respondWith(
    networkFirst(request)
  );
});
