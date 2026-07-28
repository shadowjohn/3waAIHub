'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const sharp = require('sharp');
const {
  FIXED_USER_AGENT,
  assertMainDocumentAllowed,
  buildCaptureReport,
  buildClientHints,
  captureNavigationDecision,
  cropPng,
  parseCaptureRequest,
  validateCropBounds,
} = require('./capture');

async function test() {
  const resolve = () => [{ address: '93.184.216.34', family: 4 }];
  const allowedHosts = ['3wa.tw', 'tile.openstreetmap.org'];

  assert.equal(
    FIXED_USER_AGENT,
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36'
  );
  assert.deepEqual(buildClientHints(FIXED_USER_AGENT), {
    'Sec-CH-UA': '"Google Chrome";v="144", "Chromium";v="144", "Not)A;Brand";v="24"',
    'Sec-CH-UA-Mobile': '?0',
    'Sec-CH-UA-Platform': '"Windows"',
  });

  const report = buildCaptureReport({
    requestedUrl: 'https://example.com/',
    finalUrl: 'https://example.com/not-found',
    httpStatus: 404,
    viewport: { width: 1280, height: 720 },
    image: { width: 1280, height: 900, bytes: 1234 },
    delaySeconds: 0,
    timeoutSeconds: 60,
    javascript: 'document.cookie = "session=secret"; fetch("https://secret.example/")',
    javascriptExecuted: true,
    crop: null,
    elapsedSeconds: 0.25,
    playwrightVersion: '1.61.1',
    warnings: [{ host: 'blocked.example', reason: 'url_not_allowed' }],
    headers: { authorization: 'Bearer secret' },
    body: 'secret',
  });
  assert.deepEqual(Object.keys(report), [
    'requested_url', 'final_url', 'http_status', 'viewport', 'image',
    'delay_seconds', 'timeout_seconds', 'javascript_executed', 'crop',
    'elapsed_seconds', 'playwright_version', 'warnings',
  ]);
  assert.equal(JSON.stringify(report).includes('secret'), false);
  assert.equal(JSON.stringify(report).includes('authorization'), false);

  assert.deepEqual(parseCaptureRequest({
    url: 'https://3wa.tw/',
    allowed_hosts: allowedHosts,
  }).allowedHosts, allowedHosts);
  assert.throws(() => parseCaptureRequest({ url: 'https://3wa.tw/' }), /url_not_allowed/);

  assert.deepEqual(
    await captureNavigationDecision('document', true, true, 'https://3wa.tw/after-301', '3wa.tw', allowedHosts, resolve),
    { action: 'continue' }
  );
  assert.deepEqual(
    await captureNavigationDecision('document', true, true, 'https://tile.openstreetmap.org/redirect', '3wa.tw', allowedHosts, resolve),
    { action: 'abort', mainBlocked: true, warning: false }
  );
  assert.deepEqual(
    await captureNavigationDecision('document', true, false, 'https://3wa.tw/frame', '3wa.tw', allowedHosts, resolve),
    { action: 'continue' }
  );
  assert.deepEqual(
    await captureNavigationDecision('document', true, false, 'https://tile.openstreetmap.org/frame', '3wa.tw', allowedHosts, resolve),
    { action: 'abort', mainBlocked: false, warning: true }
  );
  assert.deepEqual(
    await captureNavigationDecision('document', false, true, 'https://3wa.tw/popup', '3wa.tw', allowedHosts, resolve),
    { action: 'abort', mainBlocked: false, warning: true }
  );
  assert.deepEqual(
    await captureNavigationDecision('document', false, true, 'https://tile.openstreetmap.org/popup', '3wa.tw', allowedHosts, resolve),
    { action: 'abort', mainBlocked: false, warning: true }
  );
  assert.equal(
    await assertMainDocumentAllowed({ url: () => 'https://3wa.tw/delayed' }, '3wa.tw', allowedHosts, resolve),
    'https://3wa.tw/delayed'
  );
  await assert.rejects(
    () => assertMainDocumentAllowed({ url: () => 'https://tile.openstreetmap.org/delayed' }, '3wa.tw', allowedHosts, resolve),
    /url_not_allowed/
  );

  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'web-capture-'));
  try {
    const source = path.join(directory, 'source.png');
    const output = path.join(directory, 'crop.png');
    await sharp({ create: { width: 4, height: 3, channels: 4, background: '#112233' } }).png().toFile(source);
    await cropPng(source, output, { x: 1, y: 1, width: 2, height: 1 });
    const metadata = await sharp(output).metadata();
    assert.deepEqual({ width: metadata.width, height: metadata.height }, { width: 2, height: 1 });
    assert.throws(() => validateCropBounds({ x: 3, y: 1, width: 2, height: 1 }, { width: 4, height: 3 }), /invalid_crop/);
  } finally {
    fs.rmSync(directory, { recursive: true, force: true });
  }
}

test().then(
  () => console.log('test_capture: ok'),
  (error) => {
    console.error(error.stack || String(error));
    process.exitCode = 1;
  }
);
