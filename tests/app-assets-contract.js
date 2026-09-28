'use strict';
const assert = require('node:assert/strict');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

// Executes production PHP with CLI-only WP doubles, never a live site.
function phpResponse(mode, base, asset = '', method = 'GET') {
  return JSON.parse(execFileSync(process.env.PHP || 'php', [path.join(__dirname, 'app-assets-fixture.php'), mode, base, asset, method], { encoding: 'utf8', maxBuffer: 4 * 1024 * 1024 }));
}

if (require.main === module) {
  let checks = 0;
  for (const base of ['https://example.test', 'https://example.test/shop']) {
    const urls = phpResponse('urls', base);
    const shell = phpResponse('shell', base);
    assert.equal(shell.status, 200);
    assert.match(shell.headers['content-type'], /^text\/html/);
    assert.equal(shell.headers['x-content-type-options'], 'nosniff');
    assert.match(shell.headers['content-security-policy'], /script-src 'self'/);
    const files = { app: 'app.js', styles: 'styles.css', ui: 'ui.css', fonts: 'fonts.css', sw: 'sw.js', manifest: 'manifest.webmanifest' };
    for (const [key, file] of Object.entries(files)) {
      const url = new URL(urls[key]);
      assert.equal(url.origin + url.pathname, base + '/manager/');
      assert.equal(url.searchParams.get('fandoogh_manager_asset'), file);
      assert.equal(url.searchParams.get('ver'), urls.version);
      assert.ok(shell.body.includes(urls[key].replaceAll('&', '&amp;')), `shell references ${file}`);
      const response = phpResponse('asset', base, file);
      assert.equal(response.status, 200);
      assert.ok(response.body.length > 10);
      const mime = file.endsWith('.css') ? 'text/css' : file.endsWith('.js') ? 'application/javascript' : 'application/manifest+json';
      assert.ok(response.headers['content-type'].startsWith(mime));
      checks++;
    }
    const manifest = JSON.parse(phpResponse('asset', base, files.manifest).body);
    assert.equal(manifest.scope, base + '/manager/');
    assert.equal(manifest.start_url, manifest.scope);
    assert.equal(manifest.id, manifest.scope);
    assert.equal(phpResponse('asset', base, 'security.php').status, 404);
    assert.equal(phpResponse('asset', base, 'styles.css', 'POST').status, 405);
    const head = phpResponse('asset', base, 'styles.css', 'HEAD');
    assert.equal(head.status, 200);
    assert.equal(head.body, '');
    checks += 5;
  }
  console.log(`App asset contracts passed: ${checks} checks (PHP handlers with WordPress doubles).`);
}
module.exports = { phpResponse };
