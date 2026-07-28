'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {
  validateAllowedHosts,
  validateDocumentNavigation,
  validatePublicHttpUrl,
} = require('./url_policy');

async function test() {
  const resolve = () => [{ address: '93.184.216.34', family: 4 }];
  const cases = JSON.parse(fs.readFileSync(path.join(__dirname, 'url_policy_cases.json'), 'utf8'));

  for (const url of [
    'file:///etc/passwd',
    'ftp://example.com/',
    'http://user:pass@example.com/',
    'http://127.0.0.1/',
    'http://[::1]/',
    'http://[2001::1]/',
    'http://localhost/',
    'https://example.com:8080/',
  ]) {
    assert.throws(() => validatePublicHttpUrl(url, resolve), /url_not_allowed/, url);
  }

  await assert.rejects(
    () => validatePublicHttpUrl('https://private.example/', async () => [{ address: '10.0.0.1', family: 4 }]),
    /url_not_allowed/
  );
  assert.equal(
    await validatePublicHttpUrl('https://example.com/path?q=1', resolve),
    'https://example.com/path?q=1'
  );

  for (const host of cases.valid_hosts) {
    assert.deepEqual(validateAllowedHosts([host]), [host], host);
  }
  for (const host of cases.invalid_hosts) {
    assert.throws(() => validateAllowedHosts([host]), /url_not_allowed/, host);
  }
  for (const { input, output } of cases.canonical_hosts) {
    assert.deepEqual(validateAllowedHosts([input]), [output], input);
  }

  const allowed = validateAllowedHosts(['3wa.tw', 'tile.openstreetmap.org']);
  assert.deepEqual(allowed, ['3wa.tw', 'tile.openstreetmap.org']);
  assert.equal(
    await validateDocumentNavigation('https://3wa.tw/next', '3wa.tw', allowed, resolve),
    'https://3wa.tw/next'
  );
  await assert.rejects(
    () => validateDocumentNavigation('https://tile.openstreetmap.org/0/0/0.png', '3wa.tw', allowed, resolve),
    /url_not_allowed/
  );
  await assert.rejects(
    () => validateDocumentNavigation('https://3wa.tw/next', '3wa.tw', ['tile.openstreetmap.org'], resolve),
    /url_not_allowed/
  );
  assert.throws(() => validateAllowedHosts([]), /url_not_allowed/);
  assert.throws(() => validateAllowedHosts(['3wa.tw', '3wa.tw']), /url_not_allowed/);
  assert.throws(() => validateAllowedHosts(['*.3wa.tw']), /url_not_allowed/);
}

test().then(
  () => console.log('test_url_policy: ok'),
  (error) => {
    console.error(error.stack || String(error));
    process.exitCode = 1;
  }
);
