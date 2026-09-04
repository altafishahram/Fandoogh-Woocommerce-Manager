/* Dependency-free static server for the Playwright specs.
 *
 * Serves the `app/` directory (the PWA shell) plus the root
 * `manifest.webmanifest`, and maps the real plugin's `/manager/*` asset URLs
 * so the shell loads exactly like the WordPress route does. API endpoints
 * (`/wp-json/...`) answer 404 JSON — preview mode does not need them.
 *
 * Run: node tools/static-server.js  (PORT env overrides the default)
 */
'use strict';

const http = require('http');
const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..');
const APP = path.join(ROOT, 'app');
const PORT = (() => {
  const p = Number(process.env.PORT);
  return Number.isFinite(p) && p > 0 ? p : 8124;
})();

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.mjs': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.webmanifest': 'application/manifest+json; charset=utf-8',
  '.png': 'image/png',
  '.svg': 'image/svg+xml',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
  '.ico': 'image/x-icon',
};

function send(res, status, body, type) {
  res.writeHead(status, {
    'Content-Type': type || 'text/plain; charset=utf-8',
    'Cache-Control': 'no-store',
  });
  res.end(body);
}

function resolveFile(urlPath) {
  let rel = decodeURIComponent(urlPath);
  if (rel === '/' || rel === '/manager/' || rel === '/index.html' || rel === '/manager/index.html') {
    return path.join(APP, 'index.html');
  }
  if (rel === '/manager/manifest.webmanifest' || rel === '/manifest.webmanifest') {
    return path.join(ROOT, 'manifest.webmanifest');
  }
  if (rel.startsWith('/manager/')) {
    rel = rel.slice('/manager/'.length);
  }
  if (rel === '' || rel.endsWith('/')) {
    return path.join(APP, rel || '', 'index.html');
  }
  const base = rel.startsWith('/') ? APP : APP;
  const file = path.join(APP, rel.replace(/^\/+/, ''));
  const normalized = path.normalize(file);
  if (!normalized.startsWith(path.join(APP, path.sep)) && normalized !== APP) {
    return null;
  }
  return normalized;
}

http
  .createServer((req, res) => {
    const urlPath = (req.url || '/').split('?')[0];
    if (urlPath.startsWith('/wp-json/')) {
      send(res, 404, JSON.stringify({ code: 'not_found', message: 'API unavailable in static preview.' }), 'application/json; charset=utf-8');
      return;
    }
    const file = resolveFile(urlPath);
    if (!file || !fs.existsSync(file) || !fs.statSync(file).isFile()) {
      send(res, 404, 'Not found');
      return;
    }
    const type = MIME[path.extname(file).toLowerCase()] || 'application/octet-stream';
    send(res, 200, fs.readFileSync(file), type);
  })
  .listen(PORT, '127.0.0.1', () => {
    console.log('fandoogh static server on http://127.0.0.1:' + PORT);
  });
