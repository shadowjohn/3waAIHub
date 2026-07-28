'use strict';

const assert = require('node:assert/strict');
const { validatePublicHttpUrl } = require('./url_policy');

async function test() {
  const resolve = () => [{ address: '93.184.216.34', family: 4 }];

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
}

test().then(
  () => console.log('test_url_policy: ok'),
  (error) => {
    console.error(error.stack || String(error));
    process.exitCode = 1;
  }
);
