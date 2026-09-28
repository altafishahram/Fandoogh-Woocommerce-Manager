/*
 * Fandoogh Manager service worker.
 * Keep this allowlist intentionally small: API responses and private routes
 * must always stay outside the cache.
 */
"use strict";

var CACHE_NAME = "fandoogh-manager-shell-v39";
var WORKER_URL = new URL(self.location.href);
var WORKER_VERSION = WORKER_URL.searchParams.get("ver") || "base";
var MANAGER_BASE = new URL("./", WORKER_URL).pathname;
var ASSET_QUERY_VAR = "fandoogh_manager_asset";
var USE_QUERY_ASSETS = WORKER_URL.searchParams.get(ASSET_QUERY_VAR) === "sw.js";
var PUBLIC_ASSET_FILENAMES = [
  "styles.css",
  "app.js",
  "fonts.css",
  "ui.css",
  "manifest.webmanifest"
];
var PUBLIC_ASSET_PATHS = PUBLIC_ASSET_FILENAMES.map(function (filename) {
  return MANAGER_BASE + filename;
});
var PUBLIC_ASSETS = [MANAGER_BASE].concat(PUBLIC_ASSET_FILENAMES.map(function (filename) {
  // Query-served workers must also fetch assets through PHP on Nginx hosts.
  var assetUrl = USE_QUERY_ASSETS ? MANAGER_BASE + "?" + ASSET_QUERY_VAR + "=" + filename : MANAGER_BASE + filename;
  return WORKER_VERSION === "base" ? assetUrl : assetUrl + (USE_QUERY_ASSETS ? "&" : "?") + "ver=" + encodeURIComponent(WORKER_VERSION);
}));

function isWpJsonPath(url) {
  return /\/wp-json(?:\/|$)/.test(url.pathname) || url.searchParams.has("rest_route");
}

function isPublicAsset(url) {
  if (url.origin !== self.location.origin) {
    return false;
  }

  var isBasePath = url.pathname === MANAGER_BASE;
  var isQueryAsset = url.searchParams.has(ASSET_QUERY_VAR);
  if (isQueryAsset) {
    if (!isBasePath || PUBLIC_ASSET_FILENAMES.indexOf(url.searchParams.get(ASSET_QUERY_VAR)) === -1) {
      return false;
    }
  } else if (!isBasePath && PUBLIC_ASSET_PATHS.indexOf(url.pathname) === -1) {
    return false;
  }

  var allowed = true;
  url.searchParams.forEach(function (value, key) {
    // Reject ambiguous duplicate parameters as well as private/unknown routes.
    if (url.searchParams.getAll(key).length !== 1) {
      allowed = false;
    } else if (key === "ver" || (isQueryAsset && key === ASSET_QUERY_VAR)) {
      return;
    } else if (isBasePath && !isQueryAsset && key === "preview" && ["1", "true", "demo"].indexOf(value) !== -1) {
      return;
    } else {
      allowed = false;
    }
  });

  // Fragments are client-side navigation; they do not select a server route.
  return allowed;
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
