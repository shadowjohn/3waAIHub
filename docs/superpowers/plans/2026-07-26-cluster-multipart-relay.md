# Cluster Multipart Relay Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Route every fresh, published `multipart/form-data` mode through `cluster_api.php` without exposing child-node details.

**Architecture:** Keep the existing Router JSON flow intact. For multipart input, normalize flat `$_POST` fields and PHP temporary uploads, pass remote uploads through the existing `hub_proxy_post_fields()`/`CURLFile` path, and scope those same values around a local self-station gateway dispatch. Publish multipart contracts by removing the current Router-only exclusion; their existing contract examples already use `curl -F`, `CURLFile`, and `FormData`.

**Tech Stack:** PHP 8, cURL/CURLFile, PHP upload temporary files, existing SQLite Router accounting, existing PHP test runner.

---

## File Structure

- Modify: `app/cluster_router.php:755-840` - publish fresh multipart contracts alongside JSON contracts.
- Modify: `app/cluster_router.php:1000-1145` - normalize, relay, self-dispatch, and account for multipart requests.
- Modify: `app/cluster_router.php:2321-2395` - send a normalized multipart request through existing `hub_proxy_post_fields()`.
- Modify: `tests/test_cluster_router.php:1103-1245, 1390-1425, 1975-2074` - replace rejection coverage with relay, self-node, accounting, discovery, documentation, and rejection coverage.

### Task 1: Capture the Multipart Contract in Tests

**Files:**
- Modify: `tests/test_cluster_router.php:1174-1245`
- Modify: `tests/test_cluster_router.php:1390-1425`
- Modify: `tests/test_cluster_router.php:1975-2074`

- [x] **Step 1: Replace the upload-rejection test with remote relay coverage**

Use the existing image fixture and Router seams:

```php
$fixture = HUB_ROOT . '/packs/yolo/demo/camera_cat.png';
$bytes = (int)filesize($fixture);
$request = hub_test_cluster_router_request((string)$token['plain_token'], [
    'headers' => ['Content-Type' => 'multipart/form-data; boundary=client-boundary', 'Accept' => 'image/png'],
    'content_length' => (string)$bytes,
    'raw_body' => '',
    'post' => ['real_inference' => '1', 'conf' => '0.25'],
    'files' => ['image' => [
        'name' => 'camera_cat.png', 'type' => 'image/png', 'tmp_name' => $fixture,
        'error' => UPLOAD_ERR_OK, 'size' => $bytes,
    ]],
]);
```

Capture the request passed to `transport`, return `Content-Type: image/png` with a PNG signature, and assert:

```php
hub_test_assert(($response['status'] ?? 0) === 200, 'multipart Router response must remain successful');
hub_test_assert(($proxied[0]['headers'] ?? []) === [
    'Authorization' => 'Bearer remote_station_token', 'Accept' => 'image/png',
], 'multipart relay must replace the client boundary and keep only safe headers');
hub_test_assert(($proxied[0]['form']['post'] ?? []) === ['real_inference' => '1', 'conf' => '0.25'], 'multipart relay must preserve flat form values');
hub_test_assert(is_file((string)($proxied[0]['form']['files']['image']['tmp_name'] ?? '')), 'multipart relay must pass a validated temporary upload');
hub_test_assert(str_starts_with((string)$response['body'], "\x89PNG\r\n\x1a\n"), 'Router must preserve a binary child response');
```

Query the resulting `cluster_route_accesses.upload_bytes` and assert it equals `$bytes`.

- [x] **Step 2: Add invalid multipart and self-station regression tests**

Add one nested-file case and one nonexistent `tmp_name` case. Both must return `400 router_request_unsupported` without invoking transport.

Add a self-station test that starts with sentinel globals, invokes `hub_cluster_dispatch()`, and captures the in-scope values:

```php
$oldPost = $_POST;
$oldFiles = $_FILES;
$_POST = ['sentinel' => 'before'];
$_FILES = ['sentinel' => ['error' => UPLOAD_ERR_NO_FILE]];
try {
    $response = hub_cluster_dispatch($db, 'vision', $request, [
        'refresh_due' => static fn (): array => [hub_test_cluster_station_fixture(['id' => (int)$station['id'], 'station_key' => 'self_station'])],
        'direct_dispatcher' => static function (PDO $db, string $mode, array $internalRequest) use (&$captured): array {
            $captured = ['post' => $_POST, 'files' => $_FILES, 'request' => $internalRequest];
            return ['status' => 200, 'headers' => ['Content-Type: application/json'], 'body' => '{"ok":true}'];
        },
    ]);
} finally {
    $_POST = $oldPost;
    $_FILES = $oldFiles;
}
```

Assert that the callback sees the normalized form/files, `raw_body` is absent from `$captured['request']`, and the sentinel globals are restored.

- [x] **Step 3: Extend the public-manifest fixture for a form contract**

Change the existing `image_upload` fixture from an excluded service into a multipart contract with form-aware examples:

```php
$imageContract = array_replace($contract, [
    'mode' => 'image_upload',
    'content_type' => 'multipart/form-data',
    'input_fields' => [['name' => 'image', 'type' => 'file', 'required' => true]],
    'examples' => [
        'curl' => "curl -F 'image=@sample.png' 'https://configured.station.example/aihub/api.php?mode=image_upload'",
        'php' => "new CURLFile('/path/to/sample.png');",
        'js_fetch' => 'const formData = new FormData();',
    ],
]);
```

Assert that the public manifest lists `['image_upload', 'ocr']`, rewrites the image endpoint to `cluster_api.php?mode=image_upload`, and that the public document contains `-F`, `new CURLFile`, and `FormData` without the configured station origin or station secret.

- [x] **Step 4: Run the full suite to prove the current Router fails**

Run:

```bash
AIHUB_TEST_DB="$(mktemp /tmp/3waaihub_test_XXXXXX.sqlite)" AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=full
```

Expected: FAIL at the old `router_upload_unsupported` behavior or because the multipart public contract is absent.

- [x] **Step 5: Keep the failing checkpoint uncommitted**

Do not create a known-failing commit during inline execution. Continue directly to Task 2.

### Task 2: Normalize and Relay Multipart Requests

**Files:**
- Modify: `app/cluster_router.php:1050-1145`
- Modify: `app/cluster_router.php:2321-2395`

- [x] **Step 1: Add narrow form normalizers**

Add these helpers beside `hub_cluster_router_normalize_request()`:

```php
function hub_cluster_router_normalize_scalar_fields(mixed $source): ?array
{
    if (!is_array($source)) {
        return null;
    }
    $fields = [];
    foreach ($source as $key => $value) {
        if (!is_string($key) || preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $key) !== 1 || !is_scalar($value)) {
            return null;
        }
        $value = (string)$value;
        if (strlen($value) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return null;
        }
        $fields[$key] = $value;
    }

    return $fields;
}

function hub_cluster_router_normalize_uploaded_files(mixed $source): ?array
{
    if (!is_array($source)) {
        return null;
    }
    $files = [];
    foreach ($source as $field => $file) {
        if (!is_string($field) || preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $field) !== 1 || !is_array($file) || is_array($file['tmp_name'] ?? null)) {
            return null;
        }
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $path = $file['tmp_name'] ?? null;
        if ($error !== UPLOAD_ERR_OK || !is_string($path) || !is_file($path)) {
            return null;
        }
        $size = filesize($path);
        if ($size === false || $size < 0) {
            return null;
        }
        $name = basename((string)($file['name'] ?? $field));
        if ($name === '' || strlen($name) > 255 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            return null;
        }
        $files[$field] = ['name' => $name, 'type' => (string)($file['type'] ?? 'application/octet-stream'), 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => (int)$size];
    }

    return $files;
}
```

Skip blank optional file controls. Reject nested arrays and malformed entries before routing. Do not add MIME-specific rules because the selected child service owns image, audio, and model validation.

- [x] **Step 2: Distinguish JSON from multipart during normalization**

At the top of `hub_cluster_router_normalize_request()`, retain method and Router request-size checks. Move the existing query validation before body selection so both paths share `$query`; capture `$requestUri` and `$contentLength` from the same request/server sources used by `hub_cluster_router_read_request_body()`. When the safe content type is multipart, return a normalized form rather than reading `php://input`:

```php
$isMultipart = preg_match('/^multipart\/form-data(?:;|$)/i', (string)($headers['Content-Type'] ?? '')) === 1;
if ($isMultipart) {
    $post = hub_cluster_router_normalize_scalar_fields($request['post'] ?? $_POST);
    $files = hub_cluster_router_normalize_uploaded_files($request['files'] ?? $_FILES);
    if ($post === null || $files === null) {
        return ['response' => hub_gateway_error(400, 'router_request_unsupported', 'multipart form is not supported')];
    }
    $requestBytes = hub_cluster_router_request_bytes($contentLength, $post, $files);
    if ($requestBytes > hub_cluster_proxy_request_limit_bytes()) {
        return ['response' => hub_gateway_error(413, 'router_request_too_large', 'request body is too large for the cluster router')];
    }
    unset($headers['Content-Type']);
    return ['method' => $method, 'headers' => $headers, 'raw_body' => '', 'form' => ['post' => $post, 'files' => $files], 'query' => $query, 'request_uri' => $requestUri, 'request_bytes' => $requestBytes];
}
```

Implement `hub_cluster_router_request_bytes()` beside the normalizers: use a valid declared content length when present; otherwise sum validated file sizes plus scalar value lengths. For the non-multipart path set `request_bytes` to `strlen($body['body'])` and retain rejection of nonempty files.

- [x] **Step 3: Send remote forms through the existing cURL file builder**

In `hub_cluster_dispatch()`, include the normalized form and record `request_bytes`:

```php
'body' => $normalized['raw_body'],
'form' => $normalized['form'] ?? null,
```

Replace the final `strlen($normalized['raw_body'])` argument with:

```php
(int)$normalized['request_bytes']
```

In `hub_cluster_proxy_transport()`, use `hub_proxy_post_fields()` when a form exists:

```php
$form = is_array($request['form'] ?? null) ? $request['form'] : null;
if ($form !== null) {
    $headers = array_values(array_filter($headers, static fn (string $header): bool => !str_starts_with(strtolower($header), 'content-type:')));
}
// Keep the existing CURLOPT setup.
if ($configured && !in_array($method, ['GET', 'HEAD'], true)) {
    $configured = curl_setopt(
        $handle,
        CURLOPT_POSTFIELDS,
        $form === null ? $body : hub_proxy_post_fields((array)($form['post'] ?? []), (array)($form['files'] ?? []))
    );
}
```

This reuses the existing `CURLFile` construction and lets cURL generate the outbound multipart boundary.

- [x] **Step 4: Scope form globals for self-station dispatch**

Add `hub_cluster_router_dispatch_self()` and call it from the current self-station branch:

```php
function hub_cluster_router_dispatch_self(PDO $db, string $mode, array $request, callable $dispatcher): array
{
    $form = is_array($request['form'] ?? null) ? $request['form'] : null;
    if ($form === null) {
        return $dispatcher($db, $mode, $request);
    }
    $oldPost = $_POST;
    $oldFiles = $_FILES;
    $oldServer = $_SERVER;
    try {
        $_POST = (array)($form['post'] ?? []);
        $_FILES = (array)($form['files'] ?? []);
        $_SERVER['CONTENT_LENGTH'] = (string)($request['request_bytes'] ?? 0);
        unset($request['raw_body']);
        return $dispatcher($db, $mode, $request);
    } finally {
        $_POST = $oldPost;
        $_FILES = $oldFiles;
        $_SERVER = $oldServer;
    }
}
```

Keep this helper in `app/cluster_router.php`; do not modify the gateway or introduce a second upload abstraction. `finally` prevents one PHP-FPM request from contaminating another.

- [x] **Step 5: Run the full test suite and make the relay green**

Run:

```bash
AIHUB_TEST_DB="$(mktemp /tmp/3waaihub_test_XXXXXX.sqlite)" AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=full
```

Expected: `suite=full ... failures=0`.

- [x] **Step 6: Commit the relay implementation**

```bash
git add app/cluster_router.php tests/test_cluster_router.php
git commit -m "feat: relay multipart requests through cluster"
```

### Task 3: Publish Multipart Modes and Verify the Customer Contract

**Files:**
- Modify: `app/cluster_router.php:755-805`
- Modify: `tests/test_cluster_router.php:1975-2074`
- Modify: `docs/superpowers/plans/2026-07-26-cluster-multipart-relay.md`

- [x] **Step 1: Remove the Router-only multipart catalog exclusion**

Delete only this filter from `hub_cluster_public_manifest()`:

```php
if (preg_match('~^multipart/form-data(?:;|$)~i', trim((string)($service['content_type'] ?? ''))) === 1) {
    continue;
}
```

Keep fresh-station filtering, endpoint rewriting, and the no-station-detail contract unchanged. The existing portal consumes the form-aware examples already supplied by a service contract, so do not duplicate an example generator.

- [x] **Step 2: Validate discovery, permissions, and form-aware portal examples**

Run:

```bash
php -l app/cluster_router.php
php -l tests/test_cluster_router.php
AIHUB_TEST_DB="$(mktemp /tmp/3waaihub_test_XXXXXX.sqlite)" AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=full
curl --fail --silent --show-error https://3wa.tw/3waAIHub/cluster_public_api_docs.php | rg -n 'mode-(sam3|yolo)|curl -F|CURLFile|FormData'
```

Expected: both linters report no syntax errors, the full suite reports `failures=0`, and the live portal lists the 5090 multipart modes with form-aware examples.

- [x] **Step 3: Run one real 5090 Router smoke with an authorized customer token**

On the Router host, set a shell-only token variable and run YOLO through the single entry:

```bash
read -rsp 'Router customer token: ' AIHUB_ROUTER_SMOKE_TOKEN; echo
curl --fail --silent --show-error -X POST 'https://3wa.tw/3waAIHub/cluster_api.php?mode=yolo' \
  -H "Authorization: Bearer $AIHUB_ROUTER_SMOKE_TOKEN" \
  -F 'image=@packs/yolo/demo/camera_cat.png' \
  -F 'real_inference=1' \
  -F 'conf=0.25' \
  -F 'iou=0.7'
unset AIHUB_ROUTER_SMOKE_TOKEN
```

Expected: a JSON response with `"ok":true`, `"mock":false`, and detections from the 5090 child. Do not save or commit the token.

Verified on 2026-07-26 with a temporary customer token restricted to `yolo` only. The live multipart call to `cluster_api.php?mode=yolo` returned HTTP `200`, `ok: true`, `mock: false`, and one detection; the test member and token were deleted in `finally`.

- [x] **Step 4: Record completed verification and commit**

Mark the completed checkboxes in this plan, then run:

```bash
git add docs/superpowers/plans/2026-07-26-cluster-multipart-relay.md
git commit -m "docs: record cluster multipart relay verification"
```
