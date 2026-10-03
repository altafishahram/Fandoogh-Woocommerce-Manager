'use strict';
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const php = process.env.PHP || 'php';
const options = { encoding: 'utf8', env: { ...process.env } };
const phpArgs = [];
// Windows portable PHP often ships the extension without enabling it. Only this
// test process loads the existing DLL/config; no machine configuration is edited.
if (process.platform === 'win32') {
  const binary = execFileSync(php, ['-r', 'echo PHP_BINARY;'], options).trim();
  const directory = path.dirname(binary);
  if (fs.existsSync(path.join(directory, 'ext', 'php_openssl.dll'))) {
    phpArgs.push('-d', `extension_dir=${path.join(directory, 'ext')}`, '-d', 'extension=openssl');
    options.env.OPENSSL_CONF = path.join(directory, 'extras', 'ssl', 'openssl.cnf');
  }
}
const client = crypto.createECDH('prime256v1');
client.generateKeys();
const auth = crypto.randomBytes(16);
const subscription = { endpoint: 'https://fcm.googleapis.com/fcm/send/test-only', keys: { p256dh: client.getPublicKey().toString('base64url'), auth: auth.toString('base64url') } };
const sent = JSON.parse(execFileSync(php, [...phpArgs, path.join(__dirname, 'push-crypto-fixture.php')], { ...options, input: JSON.stringify(subscription) }));
assert.equal(sent.redirects, 0);
assert.equal(sent.headers['Content-Encoding'], 'aes128gcm');
const [, token, publicKey] = /^vapid t=([^,]+), k=(.+)$/.exec(sent.headers.Authorization);
const [header, claims, signature] = token.split('.');
const point = Buffer.from(publicKey, 'base64url');
const spki = Buffer.concat([Buffer.from('3059301306072a8648ce3d020106082a8648ce3d030107034200', 'hex'), point]);
assert(crypto.verify('sha256', Buffer.from(`${header}.${claims}`), { key: crypto.createPublicKey({ key: spki, type: 'spki', format: 'der' }), dsaEncoding: 'ieee-p1363' }, Buffer.from(signature, 'base64url')));
const jwt = JSON.parse(Buffer.from(claims, 'base64url'));
assert.equal(jwt.aud, 'https://fcm.googleapis.com');
assert(jwt.exp > Date.now() / 1000 && jwt.exp <= Date.now() / 1000 + 3601);
const record = Buffer.from(sent.body, 'base64');
assert.equal(record.readUInt32BE(16), 4096);
assert.equal(record[20], 65);
const server = record.subarray(21, 86);
const shared = client.computeSecret(server);
const ikm = crypto.hkdfSync('sha256', shared, auth, Buffer.concat([Buffer.from('WebPush: info\0'), client.getPublicKey(), server]), 32);
const cek = crypto.hkdfSync('sha256', ikm, record.subarray(0, 16), Buffer.from('Content-Encoding: aes128gcm\0'), 16);
const nonce = crypto.hkdfSync('sha256', ikm, record.subarray(0, 16), Buffer.from('Content-Encoding: nonce\0'), 12);
const decipher = crypto.createDecipheriv('aes-128-gcm', cek, nonce);
decipher.setAuthTag(record.subarray(-16));
const plaintext = Buffer.concat([decipher.update(record.subarray(86, -16)), decipher.final()]);
assert.equal(plaintext.at(-1), 2);
const payload = JSON.parse(plaintext.subarray(0, -1));
assert.equal(payload.kind, 'low_stock');
assert.deepEqual(Object.keys(payload).sort(), ['body', 'kind', 'title']);
console.log('Web Push: PHP VAPID signature and RFC 8291 envelope independently verified with Node crypto.');
