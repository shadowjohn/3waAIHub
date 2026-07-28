#!/usr/bin/env node
'use strict';

const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium } = require('playwright');
const sharp = require('sharp');
const {
  validateAllowedHosts,
  validateDocumentNavigation,
  validatePublicHttpUrl,
} = require('./url_policy');

const REQUEST_PATH = '/workspace/input/request.json';
const OUTPUT_DIR = '/workspace/output';
const SCREENSHOT_PATH = path.join(OUTPUT_DIR, 'screenshot.png');
const CROP_PATH = path.join(OUTPUT_DIR, 'crop.png');
const REPORT_PATH = path.join(OUTPUT_DIR, 'capture_report.json');
const FIXED_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36';
const MAX_IMAGE_BYTES = 50 * 1024 * 1024;
const MAX_IMAGE_WIDTH = 2560;
const MAX_IMAGE_HEIGHT = 30000;
const MAX_IMAGE_PIXELS = 60000000;
const MAX_WARNINGS = 16;

function runnerError(code) {
  const error = new Error(code);
  error.code = code;
  return error;
}

function integer(value, fallback, min, max) {
  const result = value === undefined ? fallback : value;
  if (!Number.isInteger(result) || result < min || result > max) {
    throw runnerError('invalid_request');
  }
  return result;
}

function parseCaptureRequest(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    throw runnerError('invalid_request');
  }
  const allowedKeys = new Set(['url', 'width', 'height', 'delay_seconds', 'timeout_seconds', 'javascript', 'crop_x', 'crop_y', 'crop_width', 'crop_height', 'allowed_hosts']);
  if (Object.keys(value).some((key) => !allowedKeys.has(key))) {
    throw runnerError('invalid_request');
  }
  if (typeof value.url !== 'string' || Buffer.byteLength(value.url) > 2048) {
    throw runnerError('invalid_request');
  }
  const request = {
    url: value.url,
    width: integer(value.width, 1280, 320, 2560),
    height: integer(value.height, 720, 320, 2160),
    delaySeconds: integer(value.delay_seconds, 0, 0, 60),
    timeoutSeconds: integer(value.timeout_seconds, 60, 10, 120),
    javascript: value.javascript,
    allowedHosts: validateAllowedHosts(value.allowed_hosts),
  };
  if (request.timeoutSeconds <= request.delaySeconds
    || (request.javascript !== undefined && (typeof request.javascript !== 'string' || Buffer.byteLength(request.javascript) > 16384))) {
    throw runnerError('invalid_request');
  }
  const cropKeys = ['crop_x', 'crop_y', 'crop_width', 'crop_height'];
  const cropCount = cropKeys.filter((key) => value[key] !== undefined).length;
  if (cropCount !== 0 && cropCount !== cropKeys.length) {
    throw runnerError('invalid_request');
  }
  request.crop = cropCount === 0 ? null : {
    x: integer(value.crop_x, null, 0, 2559),
    y: integer(value.crop_y, null, 0, 2159),
    width: integer(value.crop_width, null, 1, 2560),
    height: integer(value.crop_height, null, 1, 2160),
  };
  return request;
}

async function captureNavigationDecision(kind, pageIsPrimary, isMainFrame, url, initialHost, allowedHosts, resolve) {
  if (kind !== 'document') {
    await validatePublicHttpUrl(url, resolve);
    return { action: 'continue' };
  }
  if (!pageIsPrimary) {
    return { action: 'abort', mainBlocked: false, warning: true };
  }
  try {
    await validateDocumentNavigation(url, initialHost, allowedHosts, resolve);
    return { action: 'continue' };
  } catch {
    return { action: 'abort', mainBlocked: isMainFrame, warning: !isMainFrame };
  }
}

async function assertMainDocumentAllowed(
  page,
  initialHost,
  allowedHosts,
  resolve,
  mainDocumentRoutes = new Set(),
  isMainDocumentBlocked = () => false
) {
  for (;;) {
    const routes = [...mainDocumentRoutes];
    if (routes.length === 0) {
      break;
    }
    await Promise.allSettled(routes);
  }
  if (isMainDocumentBlocked()) {
    throw runnerError('url_not_allowed');
  }
  const href = await validateDocumentNavigation(page.url(), initialHost, allowedHosts, resolve);
  for (;;) {
    const routes = [...mainDocumentRoutes];
    if (routes.length === 0) {
      break;
    }
    await Promise.allSettled(routes);
  }
  if (isMainDocumentBlocked()) {
    throw runnerError('url_not_allowed');
  }
  return href;
}

function buildClientHints(userAgent) {
  const match = /Chrome\/(\d+)/.exec(userAgent);
  if (!match) {
    throw runnerError('invalid_user_agent');
  }
  return {
    'Sec-CH-UA': `"Google Chrome";v="${match[1]}", "Chromium";v="${match[1]}", "Not)A;Brand";v="24"`,
    'Sec-CH-UA-Mobile': /Mobile/.test(userAgent) ? '?1' : '?0',
    'Sec-CH-UA-Platform': /Windows/.test(userAgent) ? '"Windows"' : '"Linux"',
  };
}

function contextOptions(request) {
  return {
    viewport: { width: request.width, height: request.height },
    userAgent: FIXED_USER_AGENT,
    extraHTTPHeaders: buildClientHints(FIXED_USER_AGENT),
    locale: 'zh-TW',
    timezoneId: 'Asia/Taipei',
    deviceScaleFactor: 1,
    serviceWorkers: 'block',
  };
}

function validateCropBounds(crop, image) {
  if (!crop || !Number.isInteger(image.width) || !Number.isInteger(image.height)
    || crop.x + crop.width > image.width || crop.y + crop.height > image.height) {
    throw runnerError('invalid_crop');
  }
  return crop;
}

async function cropPng(source, output, crop) {
  const sourceImage = await sharp(source).metadata();
  validateCropBounds(crop, sourceImage);
  await sharp(source).extract({ left: crop.x, top: crop.y, width: crop.width, height: crop.height }).png().toFile(output);
  return sharp(output).metadata();
}

function assertImageBounds(image, bytes) {
  if (image.format !== 'png' || !Number.isInteger(image.width) || !Number.isInteger(image.height)
    || image.width > MAX_IMAGE_WIDTH || image.height > MAX_IMAGE_HEIGHT
    || image.width * image.height > MAX_IMAGE_PIXELS || bytes > MAX_IMAGE_BYTES) {
    throw runnerError('page_too_large');
  }
}

async function imageInfo(file) {
  const [image, stat] = await Promise.all([sharp(file).metadata(), fs.stat(file)]);
  assertImageBounds(image, stat.size);
  return { width: image.width, height: image.height, bytes: stat.size };
}

function remainingMs(deadline) {
  const milliseconds = deadline - Date.now();
  if (milliseconds <= 0) {
    throw runnerError('capture_timeout');
  }
  return milliseconds;
}

function withinDeadline(promise, deadline) {
  const milliseconds = remainingMs(deadline);
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(runnerError('capture_timeout')), milliseconds);
    Promise.resolve(promise).then(
      (value) => { clearTimeout(timer); resolve(value); },
      (error) => { clearTimeout(timer); reject(error); }
    );
  });
}

function addWarning(warnings, target, reason) {
  if (warnings.length >= MAX_WARNINGS) {
    return;
  }
  try {
    const host = new URL(target).hostname.slice(0, 253);
    if (host) {
      warnings.push({ host, reason: 'url_not_allowed' });
    }
  } catch {
    if (reason === 'url_not_allowed') {
      warnings.push({ host: '', reason: 'url_not_allowed' });
    }
  }
}

function roundedSeconds(started) {
  return Math.round((Date.now() - started) / 10) / 100;
}

function buildCaptureReport(values) {
  return {
    requested_url: values.requestedUrl,
    final_url: values.finalUrl,
    http_status: values.httpStatus,
    viewport: values.viewport,
    image: values.image,
    delay_seconds: values.delaySeconds,
    timeout_seconds: values.timeoutSeconds,
    javascript_executed: values.javascriptExecuted,
    crop: values.crop,
    elapsed_seconds: values.elapsedSeconds,
    playwright_version: values.playwrightVersion,
    warnings: values.warnings,
  };
}

async function readRequest() {
  let value;
  try {
    value = JSON.parse(await fs.readFile(REQUEST_PATH, 'utf8'));
  } catch {
    throw runnerError('invalid_request');
  }
  return parseCaptureRequest(value);
}

async function writeReport(report, deadline) {
  const json = JSON.stringify(report) + '\n';
  if (Buffer.byteLength(json) > 65536) {
    throw runnerError('capture_report_too_large');
  }
  await withinDeadline(fs.writeFile(REPORT_PATH, json, { mode: 0o644 }), deadline);
}

async function runCapture() {
  const started = Date.now();
  const request = await readRequest();
  const deadline = started + (request.timeoutSeconds * 1000);
  const requestedUrl = await withinDeadline(validatePublicHttpUrl(request.url), deadline);
  const initialHost = new URL(requestedUrl).hostname.toLowerCase().replace(/\.$/, '');
  if (!request.allowedHosts.includes(initialHost)) {
    throw runnerError('url_not_allowed');
  }
  const warnings = [];
  let browser;
  let context;
  try {
    await fs.mkdir(OUTPUT_DIR, { recursive: true, mode: 0o755 });
    browser = await withinDeadline(chromium.launch({ headless: true }), deadline);
    context = await withinDeadline(browser.newContext(contextOptions(request)), deadline);
    const page = await withinDeadline(context.newPage(), deadline);
    let mainDocumentBlocked = false;
    const mainDocumentRoutes = new Set();
    context.on('page', (candidate) => {
      if (candidate !== page) {
        candidate.once('requestfailed', (failedRequest) => {
          if (failedRequest.isNavigationRequest()) {
            candidate.close().catch(() => {});
          }
        });
      }
    });
    await withinDeadline(context.route('**/*', async (route) => {
      const routeRequest = route.request();
      let kind = 'resource';
      let routePage;
      let pageIsPrimary = false;
      let isPrimaryMainDocument = false;
      let decisionPromise;
      let decision;
      try {
        const frame = routeRequest.frame();
        routePage = frame.page();
        kind = routeRequest.isNavigationRequest() ? 'document' : 'resource';
        pageIsPrimary = routePage === page;
        isPrimaryMainDocument = kind === 'document' && pageIsPrimary && frame === page.mainFrame();
        decisionPromise = captureNavigationDecision(
          kind,
          pageIsPrimary,
          frame === page.mainFrame(),
          routeRequest.url(),
          initialHost,
          request.allowedHosts
        );
        if (isPrimaryMainDocument) {
          mainDocumentRoutes.add(decisionPromise);
        }
        decision = await decisionPromise;
      } catch {
        decision = { action: 'abort', mainBlocked: isPrimaryMainDocument, warning: !isPrimaryMainDocument };
      }
      if (decision.action === 'continue') {
        if (isPrimaryMainDocument) {
          mainDocumentRoutes.delete(decisionPromise);
        }
        await route.continue();
        return;
      }
      if (decision.mainBlocked) {
        mainDocumentBlocked = true;
      }
      if (isPrimaryMainDocument) {
        mainDocumentRoutes.delete(decisionPromise);
      }
      if (decision.warning) {
        addWarning(warnings, routeRequest.url(), 'url_not_allowed');
      }
      await route.abort('blockedbyclient');
      if (kind === 'document' && !pageIsPrimary && routePage) {
        await routePage.close().catch(() => {});
      }
    }), deadline);

    const checkMainDocument = () => {
      if (mainDocumentBlocked) {
        throw runnerError('url_not_allowed');
      }
      return assertMainDocumentAllowed(
        page,
        initialHost,
        request.allowedHosts,
        undefined,
        mainDocumentRoutes,
        () => mainDocumentBlocked
      );
    };

    let response;
    try {
      response = await withinDeadline(page.goto(requestedUrl, { waitUntil: 'load', timeout: remainingMs(deadline) }), deadline);
    } catch (error) {
      if (mainDocumentBlocked) {
        throw runnerError('url_not_allowed');
      }
      throw error;
    }
    if (mainDocumentBlocked) {
      throw runnerError('url_not_allowed');
    }
    await withinDeadline(checkMainDocument(), deadline);
    if (!response) {
      throw runnerError('navigation_failed');
    }
    if (request.delaySeconds > 0) {
      await withinDeadline(page.waitForTimeout(request.delaySeconds * 1000), deadline);
    }
    await withinDeadline(checkMainDocument(), deadline);
    if (request.javascript !== undefined) {
      await withinDeadline(page.evaluate(request.javascript), deadline);
    }
    await withinDeadline(checkMainDocument(), deadline);
    await withinDeadline(page.evaluate(() => new Promise((resolve) => requestAnimationFrame(resolve))), deadline);
    await withinDeadline(checkMainDocument(), deadline);
    await withinDeadline(page.screenshot({ path: SCREENSHOT_PATH, type: 'png', fullPage: true }), deadline);
    await withinDeadline(checkMainDocument(), deadline);
    const image = await withinDeadline(imageInfo(SCREENSHOT_PATH), deadline);
    let crop = null;
    if (request.crop) {
      const cropped = await withinDeadline(cropPng(SCREENSHOT_PATH, CROP_PATH, request.crop), deadline);
      const cropImage = await withinDeadline(imageInfo(CROP_PATH), deadline);
      crop = { ...request.crop, image: { width: cropped.width, height: cropped.height, bytes: cropImage.bytes } };
    }
    const finalUrl = await withinDeadline(checkMainDocument(), deadline);
    await withinDeadline(context.close(), deadline);
    context = undefined;
    const report = buildCaptureReport({
      requestedUrl,
      finalUrl,
      httpStatus: response.status(),
      viewport: { width: request.width, height: request.height },
      image,
      delaySeconds: request.delaySeconds,
      timeoutSeconds: request.timeoutSeconds,
      javascriptExecuted: request.javascript !== undefined,
      crop,
      elapsedSeconds: roundedSeconds(started),
      playwrightVersion: require('playwright/package.json').version,
      warnings,
    });
    await writeReport(report, deadline);
  } finally {
    if (context) {
      await context.close().catch(() => {});
    }
    if (browser) {
      await browser.close().catch(() => {});
    }
  }
}

async function main() {
  try {
    await runCapture();
  } catch (error) {
    const code = typeof error.code === 'string' && /^[a-z0-9_]{1,120}$/.test(error.code)
      ? error.code
      : 'capture_failed';
    process.stderr.write(`error_code=${code}\n`);
    process.exitCode = 1;
  }
}

module.exports = {
  FIXED_USER_AGENT,
  assertMainDocumentAllowed,
  buildCaptureReport,
  buildClientHints,
  captureNavigationDecision,
  contextOptions,
  cropPng,
  parseCaptureRequest,
  runCapture,
  validateCropBounds,
};

if (require.main === module) {
  main();
}
