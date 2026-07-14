# PhaseL-1C Gemma 4 Photo Vision Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add minimal photo vision to `gemma4-main`: upload once, receive `image_id`, ask many independent questions with `image_id + text`, and prune expired assets.

**Architecture:** Keep 3waAIHub boundaries small. `photo_upload` and `photo` are Hub internal API modes protected by the existing Gateway token/IP/request logging path. Gemma 4 remains the only runtime service; the Pack-local adapter adds `POST /photo` and receives only Hub-generated internal paths.

**Tech Stack:** PHP 8 + SQLite + existing Gateway/API token helpers; FastAPI adapter inside `packs/llm-gemma4-12b/service`; vLLM internal sidecar; no new PHP/JS build step.

## Global Constraints

- Do not add a new execution type.
- Do not add `streaming_api`.
- Do not create a server-side session system.
- Do not expose vLLM OpenAI-compatible API.
- Do not accept client-provided host/container/storage paths.
- Do not modify other Pack contracts.
- Do not create a generic multimedia framework.
- Externally expose only `api.php?mode=photo_upload` and `api.php?mode=photo`.
- Token, IP policy, request ID, API usage logging must use existing Gateway flow.
- Store photo files under `data/uploads/photo/{image_id}/original`.
- Default TTL is `PHOTO_TTL_DAYS=7`; reads update `last_accessed_at` but do not extend expiry.
- Supported upload MIME types: `image/jpeg`, `image/png`, `image/webp`.
- Default upload limits: `PHOTO_MAX_UPLOAD_MB=10`, `PHOTO_MAX_WIDTH=8192`, `PHOTO_MAX_HEIGHT=8192`, `PHOTO_MAX_PIXELS=25000000`.
- First implementation does not dedupe by SHA-256; every upload creates a new `image_id`.
- `photo` request defaults: `max_tokens=256`, `real_inference=false`.
- `photo` response requires `ok`, `mock`, `runtime_level`, `model`, `image_id`, `answer`, `caption`, `tags`, `usage`, `elapsed_ms`.

---

### Task 1: Photo Asset Storage And Validation

**Files:**
- Modify: `/DATA/3waAIHub/app/db.php`
- Modify: `/DATA/3waAIHub/app/storage.php`
- Modify: `/DATA/3waAIHub/app/bootstrap.php`
- Create: `/DATA/3waAIHub/app/photo_assets.php`
- Test: `/DATA/3waAIHub/tests/test_photo_vision.php`

**Interfaces:**
- Produces:
  - `hub_photo_modes(): array`
  - `hub_photo_settings(PDO $db): array`
  - `hub_photo_generate_image_id(): string`
  - `hub_photo_store_upload(PDO $db, array $file, array $authContext): array`
  - `hub_photo_get_asset_for_auth(PDO $db, string $imageId, array $authContext): ?array`
  - `hub_photo_asset_host_path(array $asset): ?string`
  - `hub_photo_asset_container_path(array $asset): string`
  - `hub_photo_prune_expired(PDO $db, bool $dryRun, int $limit): array`
- Consumes:
  - `hub_get_storage_setting()`
  - `hub_now()`
  - `HUB_DATA_DIR`
  - existing test runner helpers.

- [ ] **Step 1: Write the failing storage tests**

Append to `/DATA/3waAIHub/tests/test_photo_vision.php`:

```php
<?php
declare(strict_types=1);

hub_test('Photo asset upload stores validated image with owner and TTL', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Photo Owner', 'photo@example.test', '')['id'];
    $token = hub_create_api_token($db, (int)$memberId, 'photo token', null, null);
    $tmp = tempnam(sys_get_temp_dir(), 'photo_');
    imagepng(imagecreatetruecolor(16, 8), $tmp);

    $asset = hub_photo_store_upload($db, [
        'name' => 'client-name.png',
        'type' => 'image/jpeg',
        'tmp_name' => $tmp,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($tmp),
    ], ['member_id' => (int)$memberId, 'token_id' => (int)$token['token_id']]);

    hub_test_assert(preg_match('/^img_[A-Za-z0-9_-]{20,64}$/', (string)$asset['image_id']) === 1, 'image_id format mismatch');
    hub_test_assert((string)$asset['mime'] === 'image/png', 'real MIME must be detected from file');
    hub_test_assert((int)$asset['width'] === 16 && (int)$asset['height'] === 8, 'image dimensions mismatch');
    hub_test_assert(is_file(HUB_DATA_DIR . '/' . $asset['storage_relpath']), 'stored photo missing');
    hub_test_assert(!str_contains((string)$asset['storage_relpath'], 'client-name'), 'client filename must not be used as storage path');
});

hub_test('Photo asset validation rejects unsafe uploads and enforces ownership', function (): void {
    $db = hub_test_reset_db();
    $memberA = hub_create_api_member($db, 'A', 'a@example.test', '')['id'];
    $memberB = hub_create_api_member($db, 'B', 'b@example.test', '')['id'];
    $tokenA = hub_create_api_token($db, (int)$memberA, 'A token', null, null);
    $tokenB = hub_create_api_token($db, (int)$memberB, 'B token', null, null);
    $fake = tempnam(sys_get_temp_dir(), 'fake_');
    file_put_contents($fake, 'not an image');

    hub_test_assert(hub_test_throws(fn () => hub_photo_store_upload($db, [
        'name' => 'fake.jpg',
        'type' => 'image/jpeg',
        'tmp_name' => $fake,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($fake),
    ], ['member_id' => (int)$memberA, 'token_id' => (int)$tokenA['token_id']])), 'fake image must be rejected');

    $tmp = tempnam(sys_get_temp_dir(), 'photo_');
    imagepng(imagecreatetruecolor(4, 4), $tmp);
    $asset = hub_photo_store_upload($db, [
        'name' => 'ok.png',
        'type' => 'image/png',
        'tmp_name' => $tmp,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($tmp),
    ], ['member_id' => (int)$memberA, 'token_id' => (int)$tokenA['token_id']]);

    hub_test_assert(hub_photo_get_asset_for_auth($db, (string)$asset['image_id'], ['member_id' => (int)$memberA]) !== null, 'owner member must read asset');
    hub_test_assert(hub_photo_get_asset_for_auth($db, (string)$asset['image_id'], ['member_id' => (int)$memberB, 'token_id' => (int)$tokenB['token_id']]) === null, 'other member must not read asset');
});
```

- [ ] **Step 2: Run RED**

Run:

```bash
cd /DATA/3waAIHub
php scripts/run_tests.php
```

Expected: FAIL because `hub_photo_store_upload()` is undefined.

- [ ] **Step 3: Add schema and default settings**

Modify `/DATA/3waAIHub/app/db.php` inside `hub_migrate()` SQL:

```sql
CREATE TABLE IF NOT EXISTS photo_assets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    image_id TEXT NOT NULL UNIQUE,
    owner_member_id INTEGER NULL,
    owner_token_id INTEGER NULL,
    mime TEXT NOT NULL,
    byte_size INTEGER NOT NULL,
    width INTEGER NOT NULL,
    height INTEGER NOT NULL,
    sha256 TEXT NOT NULL,
    storage_relpath TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    last_accessed_at TEXT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY(owner_member_id) REFERENCES api_members(id) ON DELETE SET NULL,
    FOREIGN KEY(owner_token_id) REFERENCES api_tokens(id) ON DELETE SET NULL
);
```

Add indexes near the existing index section:

```php
$db->exec('CREATE INDEX IF NOT EXISTS idx_photo_assets_expires_at ON photo_assets(expires_at)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_photo_assets_sha256 ON photo_assets(sha256)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_photo_assets_owner_member ON photo_assets(owner_member_id)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_photo_assets_owner_token ON photo_assets(owner_token_id)');
```

Modify `/DATA/3waAIHub/app/storage.php` default settings:

```php
'PHOTO_TTL_DAYS' => '7',
'PHOTO_MAX_UPLOAD_MB' => '10',
'PHOTO_MAX_WIDTH' => '8192',
'PHOTO_MAX_HEIGHT' => '8192',
'PHOTO_MAX_PIXELS' => '25000000',
'PHOTO_MAX_TOKENS' => '2048',
'PHOTO_REAL_INFERENCE' => '0',
'PHOTO_VISION_SERVICE_KEY' => 'gemma4-main',
```

- [ ] **Step 4: Implement `/DATA/3waAIHub/app/photo_assets.php`**

Create the file with these functions:

```php
<?php
declare(strict_types=1);

function hub_photo_modes(): array
{
    return [
        'photo_upload' => 'Photo Upload',
        'photo' => 'Photo Vision',
    ];
}

function hub_photo_settings(PDO $db): array
{
    return [
        'ttl_days' => max(1, min(30, (int)hub_get_storage_setting($db, 'PHOTO_TTL_DAYS'))),
        'max_upload_mb' => max(1, (int)hub_get_storage_setting($db, 'PHOTO_MAX_UPLOAD_MB')),
        'max_width' => max(1, (int)hub_get_storage_setting($db, 'PHOTO_MAX_WIDTH')),
        'max_height' => max(1, (int)hub_get_storage_setting($db, 'PHOTO_MAX_HEIGHT')),
        'max_pixels' => max(1, (int)hub_get_storage_setting($db, 'PHOTO_MAX_PIXELS')),
        'max_tokens' => max(32, min(2048, (int)hub_get_storage_setting($db, 'PHOTO_MAX_TOKENS'))),
        'real_inference' => hub_get_storage_setting($db, 'PHOTO_REAL_INFERENCE') === '1',
        'vision_service_key' => hub_get_storage_setting($db, 'PHOTO_VISION_SERVICE_KEY') ?: 'gemma4-main',
    ];
}

function hub_photo_generate_image_id(): string
{
    return 'img_' . rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
}

function hub_photo_upload_root(): string
{
    return HUB_DATA_DIR . '/uploads/photo';
}

function hub_photo_store_upload(PDO $db, array $file, array $authContext): array
{
    if (empty($authContext['member_id']) && empty($authContext['token_id'])) {
        throw new RuntimeException('photo_owner_unavailable');
    }
    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file((string)($file['tmp_name'] ?? ''))) {
        throw new RuntimeException('file_required');
    }

    $settings = hub_photo_settings($db);
    $tmpName = (string)$file['tmp_name'];
    $size = (int)($file['size'] ?? filesize($tmpName));
    if ($size < 1 || $size > $settings['max_upload_mb'] * 1024 * 1024) {
        throw new RuntimeException('payload_too_large');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmpName);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        throw new RuntimeException('unsupported_media_type');
    }

    $dimensions = @getimagesize($tmpName);
    if (!is_array($dimensions) || empty($dimensions[0]) || empty($dimensions[1])) {
        throw new RuntimeException('invalid_image');
    }
    [$width, $height] = [(int)$dimensions[0], (int)$dimensions[1]];
    if ($width > $settings['max_width'] || $height > $settings['max_height'] || ($width * $height) > $settings['max_pixels']) {
        throw new RuntimeException('image_dimensions_too_large');
    }

    $imageId = hub_photo_generate_image_id();
    $relDir = 'uploads/photo/' . $imageId;
    $dir = HUB_DATA_DIR . '/' . $relDir;
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('storage_failed');
    }
    $path = $dir . '/original';
    $ok = is_uploaded_file($tmpName) ? move_uploaded_file($tmpName, $path) : copy($tmpName, $path);
    if (!$ok) {
        throw new RuntimeException('upload_failed');
    }

    $createdAt = hub_now();
    $expiresAt = date('Y-m-d H:i:s', strtotime($createdAt . ' +' . $settings['ttl_days'] . ' days'));
    $row = [
        'image_id' => $imageId,
        'owner_member_id' => !empty($authContext['member_id']) ? (int)$authContext['member_id'] : null,
        'owner_token_id' => !empty($authContext['token_id']) ? (int)$authContext['token_id'] : null,
        'mime' => $mime,
        'byte_size' => filesize($path) ?: $size,
        'width' => $width,
        'height' => $height,
        'sha256' => hash_file('sha256', $path),
        'storage_relpath' => $relDir . '/original',
        'expires_at' => $expiresAt,
        'created_at' => $createdAt,
    ];
    $stmt = $db->prepare(
        'INSERT INTO photo_assets
            (image_id, owner_member_id, owner_token_id, mime, byte_size, width, height, sha256, storage_relpath, expires_at, created_at)
         VALUES
            (:image_id, :owner_member_id, :owner_token_id, :mime, :byte_size, :width, :height, :sha256, :storage_relpath, :expires_at, :created_at)'
    );
    $stmt->execute($row);

    return $row + ['size' => $row['byte_size']];
}

function hub_photo_get_asset_for_auth(PDO $db, string $imageId, array $authContext): ?array
{
    if (!preg_match('/^img_[A-Za-z0-9_-]{20,64}$/', $imageId)) {
        return null;
    }
    $stmt = $db->prepare('SELECT * FROM photo_assets WHERE image_id = :image_id LIMIT 1');
    $stmt->execute([':image_id' => $imageId]);
    $asset = $stmt->fetch();
    if (!$asset || (string)$asset['expires_at'] < hub_now()) {
        return null;
    }
    $memberId = (int)($authContext['member_id'] ?? 0);
    $tokenId = (int)($authContext['token_id'] ?? 0);
    $ownerMemberId = (int)($asset['owner_member_id'] ?? 0);
    $ownerTokenId = (int)($asset['owner_token_id'] ?? 0);
    $allowed = ($memberId > 0 && $ownerMemberId > 0 && $memberId === $ownerMemberId)
        || ($ownerMemberId === 0 && $tokenId > 0 && $ownerTokenId > 0 && $tokenId === $ownerTokenId);
    if (!$allowed) {
        return null;
    }
    $db->prepare('UPDATE photo_assets SET last_accessed_at = :now WHERE id = :id')
        ->execute([':now' => hub_now(), ':id' => (int)$asset['id']]);

    return $asset;
}

function hub_photo_asset_host_path(array $asset): ?string
{
    $root = realpath(hub_photo_upload_root());
    $path = realpath(HUB_DATA_DIR . '/' . ltrim((string)($asset['storage_relpath'] ?? ''), '/'));
    if ($root === false || $path === false || !is_file($path) || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
        return null;
    }

    return $path;
}

function hub_photo_asset_container_path(array $asset): string
{
    return '/data/photo/' . (string)$asset['image_id'] . '/original';
}
```

Modify `/DATA/3waAIHub/app/bootstrap.php`:

```php
require_once __DIR__ . '/photo_assets.php';
```

- [ ] **Step 5: Run GREEN**

Run:

```bash
cd /DATA/3waAIHub
php scripts/run_tests.php
```

Expected: the new photo asset tests PASS.

---

### Task 2: Gateway Internal Modes `photo_upload` And `photo`

**Files:**
- Modify: `/DATA/3waAIHub/app/gateway.php`
- Modify: `/DATA/3waAIHub/app/api_tokens.php`
- Modify: `/DATA/3waAIHub/app/customer_accounts.php`
- Modify: `/DATA/3waAIHub/admin/api_token_permissions.php`
- Modify: `/DATA/3waAIHub/admin/customer_edit.php`
- Test: `/DATA/3waAIHub/tests/test_photo_vision.php`

**Interfaces:**
- Consumes Task 1 functions.
- Produces:
  - `hub_is_photo_api_mode(string $mode): bool`
  - `hub_photo_api_dispatch(PDO $db, string $mode, array $authContext): array`
  - `hub_api_photo_upload(PDO $db, array $authContext): array`
  - `hub_api_photo(PDO $db, array $authContext): array`
  - `hub_photo_request_payload(PDO $db, array $asset, array $payload): array`

- [ ] **Step 1: Add failing Gateway tests**

Append:

```php
hub_test('Photo upload and ask are protected internal Gateway modes', function (): void {
    $db = hub_test_reset_db();
    hub_ensure_default_storage_settings($db);
    $memberId = hub_create_api_member($db, 'Photo API', 'photo-api@example.test', '')['id'];
    $token = hub_create_api_token($db, (int)$memberId, 'Photo token', null, null);
    hub_add_api_token_mode_permission($db, (int)$token['token_id'], 'photo_upload', null);
    hub_add_api_token_mode_permission($db, (int)$token['token_id'], 'photo', null);
    hub_add_api_token_ip_rule($db, (int)$token['token_id'], '203.0.113.30', '');

    $tmp = tempnam(sys_get_temp_dir(), 'photo_');
    imagepng(imagecreatetruecolor(6, 6), $tmp);
    [$oldServer, $oldFiles, $oldPost] = [$_SERVER, $_FILES, $_POST];
    $_SERVER['REMOTE_ADDR'] = '203.0.113.30';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token['plain_token'];
    $_FILES = ['image' => ['name' => 'ok.png', 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)]];
    $_POST = [];
    $upload = hub_gateway_dispatch($db, 'photo_upload');
    $_SERVER = $oldServer; $_FILES = $oldFiles; $_POST = $oldPost;

    $uploadPayload = json_decode((string)$upload['body'], true);
    hub_test_assert((int)$upload['status'] === 200, 'photo_upload must pass');
    hub_test_assert(($uploadPayload['ok'] ?? false) === true, 'photo_upload ok missing');
    hub_test_assert(preg_match('/^img_/', (string)($uploadPayload['image_id'] ?? '')) === 1, 'image_id missing');

    [$oldServer, $oldFiles, $oldPost] = [$_SERVER, $_FILES, $_POST];
    $_SERVER['REMOTE_ADDR'] = '203.0.113.30';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['CONTENT_TYPE'] = 'application/json';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token['plain_token'];
    $_FILES = [];
    $_POST = [];
    $response = hub_gateway_dispatch($db, 'photo', static function (array $service, int $timeoutSec): array {
        return hub_gateway_json(200, [
            'ok' => true,
            'mock' => true,
            'runtime_level' => 'L5-benchmark-ready',
            'model' => 'gemma4-12b',
            'image_id' => 'img_test',
            'answer' => 'mock answer',
            'caption' => 'mock caption',
            'tags' => [],
            'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0],
            'elapsed_ms' => 1,
        ]);
    });
    $_SERVER = $oldServer; $_FILES = $oldFiles; $_POST = $oldPost;
    hub_test_assert(in_array($response['status'], [400, 200], true), 'photo gateway must be routable');
});
```

The implementation may need a test helper for raw JSON body. The lazy approach is to keep the first Gateway unit test focused on upload and request validation, and cover adapter payload in Task 3.

- [ ] **Step 2: Run RED**

Run:

```bash
php scripts/run_tests.php
```

Expected: FAIL because `photo_upload` is unknown mode.

- [ ] **Step 3: Add photo mode routing**

Modify `/DATA/3waAIHub/app/gateway.php` near the task API routing:

```php
if (!$service && hub_is_photo_api_mode($mode)) {
    $clientIp = hub_get_client_ip();
    $auth = hub_gateway_authenticate_api_token($db, $mode, $clientIp);
    $authContext = $auth['context'] ?? [];
    if (empty($auth['ok'])) {
        return hub_gateway_finish($db, null, $mode, $auth['response'], $started, $requestId, $authContext);
    }
    $photoResponse = hub_photo_api_dispatch($db, $mode, $authContext);
    $logService = is_array($photoResponse['service'] ?? null) ? $photoResponse['service'] : null;
    unset($photoResponse['service']);
    return hub_gateway_finish($db, $logService, $mode, $photoResponse, $started, $requestId, $authContext);
}
```

Add:

```php
function hub_is_photo_api_mode(string $mode): bool
{
    return array_key_exists($mode, hub_photo_modes());
}

function hub_photo_api_dispatch(PDO $db, string $mode, array $authContext): array
{
    return match ($mode) {
        'photo_upload' => hub_api_photo_upload($db, $authContext),
        'photo' => hub_api_photo($db, $authContext),
        default => hub_gateway_json(404, ['ok' => false, 'error' => 'unknown_mode']),
    };
}
```

- [ ] **Step 4: Implement upload endpoint**

Add:

```php
function hub_api_photo_upload(PDO $db, array $authContext): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return hub_gateway_error(405, 'method_not_allowed', 'photo_upload requires POST');
    }
    try {
        $asset = hub_photo_store_upload($db, is_array($_FILES['image'] ?? null) ? $_FILES['image'] : [], $authContext);
    } catch (RuntimeException $e) {
        return hub_gateway_error(match ($e->getMessage()) {
            'payload_too_large' => 413,
            'unsupported_media_type' => 415,
            default => 400,
        }, $e->getMessage(), $e->getMessage());
    } catch (Throwable) {
        return hub_gateway_error(500, 'storage_failed', 'photo storage failed');
    }

    return hub_gateway_json(200, [
        'ok' => true,
        'image_id' => $asset['image_id'],
        'mime' => $asset['mime'],
        'size' => (int)$asset['byte_size'],
        'width' => (int)$asset['width'],
        'height' => (int)$asset['height'],
        'expires_at' => $asset['expires_at'],
    ]);
}
```

- [ ] **Step 5: Implement ask endpoint**

Add:

```php
function hub_api_photo(PDO $db, array $authContext): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return hub_gateway_error(405, 'method_not_allowed', 'photo requires POST');
    }
    $payload = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        return hub_gateway_error(400, 'bad_request', 'JSON body is required');
    }
    foreach (['image_path', 'file_path', 'host_path', 'container_path', 'storage_relpath', 'image_url', 'image_internal_path'] as $blocked) {
        if (array_key_exists($blocked, $payload)) {
            return hub_gateway_error(400, 'bad_request', 'client image paths are not accepted');
        }
    }
    $imageId = trim((string)($payload['image_id'] ?? ''));
    if ($imageId === '') {
        return hub_gateway_error(400, 'image_id_required', 'image_id is required');
    }
    $text = trim((string)($payload['text'] ?? ''));
    if ($text === '') {
        return hub_gateway_error(400, 'text_required', 'text is required');
    }
    if ((function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text)) > 12000) {
        return hub_gateway_error(400, 'bad_request', 'text is too long');
    }
    $asset = hub_photo_get_asset_for_auth($db, $imageId, $authContext);
    if (!$asset) {
        return hub_gateway_error(404, 'image_not_found', 'image was not found or is not available');
    }
    if (hub_photo_asset_host_path($asset) === null) {
        return hub_gateway_error(404, 'image_not_found', 'image file is not available');
    }

    $settings = hub_photo_settings($db);
    $service = hub_get_service_by_key($db, (string)$settings['vision_service_key']);
    if (!$service || (int)$service['enabled'] !== 1 || (string)$service['runtime_status'] !== 'running') {
        return hub_gateway_error(503, 'model_not_ready', 'photo vision service is not ready');
    }
    $maxTokens = max(32, min((int)$settings['max_tokens'], (int)($payload['max_tokens'] ?? 256)));
    $body = json_encode([
        'image_id' => $imageId,
        'image_internal_path' => hub_photo_asset_container_path($asset),
        'text' => $text,
        'max_tokens' => $maxTokens,
        'real_inference' => (bool)($payload['real_inference'] ?? $settings['real_inference']),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $url = preg_replace('#/chat$#', '/photo', (string)$service['internal_url']) ?: (string)$service['internal_url'];
    $response = hub_proxy_json_to_url($url, hub_service_gateway_timeout_sec($service), (string)$body);
    $response['service'] = $service;
    return $response;
}
```

Add a tiny local helper instead of widening `hub_proxy_request()`:

```php
function hub_proxy_json_to_url(string $url, int $timeoutSec, string $jsonBody): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return hub_gateway_error(502, 'proxy_error', 'service proxy error');
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $jsonBody,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => max(1, $timeoutSec),
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $errno = curl_errno($ch);
        curl_close($ch);
        return match ($errno) {
            CURLE_OPERATION_TIMEDOUT => hub_gateway_error(504, 'vision_timeout', 'photo vision timeout'),
            CURLE_COULDNT_CONNECT => hub_gateway_error(503, 'model_not_ready', 'photo vision service is unavailable'),
            default => hub_gateway_error(502, 'vision_failed', 'photo vision failed'),
        };
    }
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 502;
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE) ?: 0;
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/json';
    $body = substr($raw, $headerSize);
    curl_close($ch);

    return ['status' => $status, 'headers' => ['Content-Type: ' . $contentType], 'body' => $body];
}
```

- [ ] **Step 6: Expose pseudo modes in token/customer UI**

Modify `/DATA/3waAIHub/admin/api_token_permissions.php`:

```php
$photoModes = hub_photo_modes();
```

Render after services:

```php
<h2>Photo Vision Modes</h2>
<?php foreach ($photoModes as $mode => $label): ?>
    <label><input type="checkbox" name="modes[]" value="<?= hub_h($mode) ?>"<?= in_array($mode, $enabledModes, true) ? ' checked' : '' ?>> <code><?= hub_h($mode) ?></code> <?= hub_h($label) ?></label>
<?php endforeach; ?>
```

Modify `/DATA/3waAIHub/app/customer_accounts.php`:

```php
function hub_playground_supported_modes(): array
{
    return ['hello', 'translate', 'ocr', 'yolo', 'sam3', 'tts', 'chat', 'photo'];
}
```

Add `photo_upload` automatically when creating customer tokens if `photo` is allowed:

```php
$modes = hub_user_allowed_modes($db, $userId);
if (in_array('photo', $modes, true) && !in_array('photo_upload', $modes, true)) {
    $modes[] = 'photo_upload';
}
foreach ($modes as $mode) {
    $service = hub_get_service_by_mode($db, $mode);
    hub_add_api_token_mode_permission($db, (int)$token['token_id'], $mode, $service ? (int)$service['id'] : null);
}
```

Modify `/DATA/3waAIHub/admin/customer_edit.php` to render photo pseudo rows below service rows:

```php
<?php foreach (hub_photo_modes() as $mode => $label): ?>
    <tr>
        <td><input name="modes[]" type="checkbox" value="<?= hub_h($mode) ?>" <?= isset($allowedModes[$mode]) ? 'checked' : '' ?>></td>
        <td><?= hub_h($label) ?></td>
        <td><code><?= hub_h($mode) ?></code></td>
        <td><code>llm-gemma4-12b</code></td>
        <td><code>internal photo API</code></td>
        <td><code>L5-benchmark-ready</code></td>
    </tr>
<?php endforeach; ?>
```

- [ ] **Step 7: Run GREEN**

Run:

```bash
php scripts/run_tests.php
```

Expected: Gateway, token, and customer tests PASS.

---

### Task 3: Gemma 4 Pack Adapter `/photo`

**Files:**
- Modify: `/DATA/3waAIHub/packs/llm-gemma4-12b/service/app.py`
- Modify: `/DATA/3waAIHub/packs/llm-gemma4-12b/service/requirements.txt`
- Modify: `/DATA/3waAIHub/packs/llm-gemma4-12b/docker-compose.yml`
- Modify: `/DATA/3waAIHub/app/pack_registry.php`
- Modify: `/DATA/3waAIHub/packs/llm-gemma4-12b/pack.json`
- Test: `/DATA/3waAIHub/tests/test_llm_gemma4_pack.php`

**Interfaces:**
- Consumes Hub-injected JSON:
  - `image_id`
  - `image_internal_path`
  - `text`
  - `max_tokens`
  - `real_inference`
- Produces Pack-local JSON matching `photo` response shape.

- [ ] **Step 1: Add failing pack tests**

Append to `/DATA/3waAIHub/tests/test_llm_gemma4_pack.php`:

```php
hub_test('Gemma 4 photo adapter stays inside Hub image_id contract', function (): void {
    $manifest = hub_get_pack('llm-gemma4-12b')['manifest'];
    $contract = $manifest['l5_contract'] ?? [];
    $inputFields = array_column($contract['input']['fields'] ?? [], 'name');
    foreach (['image_id', 'text', 'max_tokens', 'real_inference'] as $field) {
        hub_test_assert(in_array($field, $inputFields, true), 'photo contract missing ' . $field);
    }
    foreach (['image_path', 'host_path', 'container_path', 'storage_relpath', 'image_url'] as $field) {
        hub_test_assert(!in_array($field, $inputFields, true), 'photo contract leaks path field ' . $field);
    }
    $app = (string)file_get_contents(HUB_ROOT . '/packs/llm-gemma4-12b/service/app.py');
    foreach (['@app.post("/photo")', 'image_internal_path', '/data/photo', 'vision_failed'] as $needle) {
        hub_test_assert(str_contains($app, $needle), 'Gemma photo adapter missing ' . $needle);
    }
});
```

- [ ] **Step 2: Run RED**

Run:

```bash
php scripts/run_tests.php
```

Expected: FAIL because `/photo` is missing.

- [ ] **Step 3: Add read-only photo mount**

Modify both static and generated compose. In `/DATA/3waAIHub/packs/llm-gemma4-12b/docker-compose.yml`, under `chat-api.volumes`:

```yaml
      - "${AIHUB_UPLOADS_DIR:-/DATA/3waAIHub/data/uploads}/photo:/data/photo:ro"
```

In `/DATA/3waAIHub/app/pack_registry.php`, add the same line to `hub_generate_llm_gemma4_compose()` under `chat-api` volumes:

```php
. '      - "${AIHUB_UPLOADS_DIR}/photo:/data/photo:ro' . "\n"
```

Use the same quoting style as adjacent volume lines.

- [ ] **Step 4: Implement `/photo` adapter**

Modify `/DATA/3waAIHub/packs/llm-gemma4-12b/service/app.py`:

```python
import base64
import json
import re
from pathlib import Path
```

Add:

```python
PHOTO_ROOT = Path("/data/photo").resolve()

class PhotoRequest(BaseModel):
    image_id: str
    image_internal_path: str
    text: str = Field(default="")
    max_tokens: int = Field(default=256, ge=32, le=2048)
    real_inference: bool = False

def safe_photo_path(path: str) -> Path | None:
    try:
        resolved = Path(path).resolve()
    except OSError:
        return None
    if not str(resolved).startswith(str(PHOTO_ROOT) + os.sep):
        return None
    return resolved if resolved.is_file() else None

def image_data_url(path: Path) -> str:
    data = path.read_bytes()
    mime = "image/png"
    if data.startswith(b"\xff\xd8"):
        mime = "image/jpeg"
    elif data.startswith(b"RIFF") and b"WEBP" in data[:16]:
        mime = "image/webp"
    return f"data:{mime};base64," + base64.b64encode(data).decode("ascii")

def parse_model_json(text: str) -> dict[str, Any] | None:
    stripped = text.strip()
    match = re.search(r"```(?:json)?\s*(\{.*?\})\s*```", stripped, re.S)
    if match:
        stripped = match.group(1)
    try:
        value = json.loads(stripped)
    except json.JSONDecodeError:
        return None
    return value if isinstance(value, dict) else None
```

Add endpoint:

```python
@app.post("/photo")
def photo(request: PhotoRequest) -> JSONResponse:
    started = time.monotonic()
    question = request.text.strip()
    if not question:
        return error_response(400, "text_required", "text is required.")
    if not re.match(r"^img_[A-Za-z0-9_-]{20,64}$", request.image_id):
        return error_response(400, "image_id_required", "valid image_id is required.")

    path = safe_photo_path(request.image_internal_path)
    if path is None:
        return error_response(403, "photo_forbidden", "image path is not allowed.")

    if not request.real_inference:
        elapsed = int((time.monotonic() - started) * 1000)
        return JSONResponse(content={
            "ok": True,
            "mock": True,
            "runtime_level": RUNTIME_LEVEL,
            "model": served_model(),
            "image_id": request.image_id,
            "answer": "這是一個 Gemma 4 Photo Vision mock answer。",
            "caption": "一張上傳到 3waAIHub 的圖片。",
            "tags": [],
            "usage": {"prompt_tokens": 0, "completion_tokens": 0, "total_tokens": 0},
            "elapsed_ms": elapsed,
        })

    prompt = (
        "請使用正體中文回答。請根據圖片與使用者問題輸出 JSON："
        "{\"answer\":\"針對問題的完整回答\",\"caption\":\"一句客觀圖片描述\",\"tags\":[\"最多八個短標籤\"]}"
        "不得猜測圖片中不可見的資訊。無法確認時必須明確說明不確定。"
        f"\n使用者問題：{question}"
    )
    payload = {
        "model": served_model(),
        "messages": [{
            "role": "user",
            "content": [
                {"type": "text", "text": prompt},
                {"type": "image_url", "image_url": {"url": image_data_url(path)}},
            ],
        }],
        "temperature": 0.1,
        "max_tokens": request.max_tokens,
        "stream": False,
    }
    try:
        response = requests.post(f"{vllm_base_url()}/v1/chat/completions", json=payload, timeout=env_float("VLLM_TIMEOUT_SEC", 600.0))
    except requests.Timeout:
        return error_response(504, "vision_timeout", "Gemma 4 vision request timed out.")
    except requests.RequestException as exc:
        return error_response(503, "model_not_ready", "Gemma 4 vision backend is unavailable.", str(exc))
    if response.status_code < 200 or response.status_code >= 300:
        return error_response(502, "vision_bad_response", "Gemma 4 vision returned an error.", response.text)

    data = response.json()
    message = (data.get("choices") or [{}])[0].get("message", {})
    parsed = parse_model_json(str(message.get("content") or ""))
    if parsed is None:
        return error_response(502, "vision_failed", "Gemma 4 vision response was not valid JSON.")
    answer = str(parsed.get("answer") or "").strip()
    caption = str(parsed.get("caption") or "").strip()
    tags = parsed.get("tags") if isinstance(parsed.get("tags"), list) else []
    tags = [str(tag).strip() for tag in tags if str(tag).strip()][:8]
    if not answer:
        return error_response(502, "vision_failed", "Gemma 4 vision answer was empty.")
    usage = data.get("usage") if isinstance(data.get("usage"), dict) else {}
    elapsed = int((time.monotonic() - started) * 1000)
    return JSONResponse(content={
        "ok": True,
        "mock": False,
        "runtime_level": RUNTIME_LEVEL,
        "model": served_model(),
        "image_id": request.image_id,
        "answer": answer,
        "caption": caption,
        "tags": tags,
        "usage": {
            "prompt_tokens": int(usage.get("prompt_tokens", 0) or 0),
            "completion_tokens": int(usage.get("completion_tokens", 0) or 0),
            "total_tokens": int(usage.get("total_tokens", 0) or 0),
        },
        "elapsed_ms": elapsed,
    })
```

- [ ] **Step 5: Update manifest contract**

Modify `/DATA/3waAIHub/packs/llm-gemma4-12b/pack.json`:

- Add `vision` to capabilities only after real smoke passes.
- Add fields `image_id`, `max_tokens`, `real_inference` to `l5_contract.input.fields`.
- Add required output keys: `answer`, `caption`, `tags`.
- Add errors: `image_id_required`, `text_required`, `photo_forbidden`, `model_not_ready`, `vision_timeout`, `vision_bad_response`, `vision_failed`.
- Add benchmark cases:
  - `gemma4_mock_photo`
  - `gemma4_real_photo_general`
  - `gemma4_real_photo_ui`

- [ ] **Step 6: Run GREEN and Python checks**

Run:

```bash
php scripts/run_tests.php
python3 -m py_compile packs/llm-gemma4-12b/service/*.py
```

Expected: PASS.

---

### Task 4: Playground And Public Docs

**Files:**
- Modify: `/DATA/3waAIHub/admin/playground.php`
- Modify: `/DATA/3waAIHub/app/public_api_docs.php`
- Modify: `/DATA/3waAIHub/docs/api_examples.md`
- Modify: `/DATA/3waAIHub/docs/client_quickstart.md`
- Modify: `/DATA/3waAIHub/README.md`
- Test: `/DATA/3waAIHub/tests/test_phase_dx4_client_starter.php`
- Test: `/DATA/3waAIHub/tests/test_llm_gemma4_pack.php`

**Interfaces:**
- Consumes `photo_upload` and `photo` modes.
- Produces user-facing examples with `<TOKEN>` only.

- [ ] **Step 1: Add failing docs/playground tests**

Append to `/DATA/3waAIHub/tests/test_llm_gemma4_pack.php`:

```php
hub_test('Gemma 4 photo playground and docs expose image_id workflow', function (): void {
    $playground = (string)file_get_contents(HUB_ROOT . '/admin/playground.php');
    foreach (['mode=photo', 'photo_upload', 'image_id', '圖片問答', 'max_tokens'] as $needle) {
        hub_test_assert(str_contains($playground, $needle), 'photo playground missing ' . $needle);
    }
    foreach (['host_path', 'container_path', 'storage_relpath'] as $forbidden) {
        hub_test_assert(!str_contains($playground, 'name="' . $forbidden . '"'), 'playground must not expose ' . $forbidden);
    }
    $examples = (string)file_get_contents(HUB_ROOT . '/docs/api_examples.md');
    foreach (['mode=photo_upload', 'mode=photo', 'image_id', '<TOKEN>'] as $needle) {
        hub_test_assert(str_contains($examples, $needle), 'photo API examples missing ' . $needle);
    }
});
```

- [ ] **Step 2: Run RED**

Run:

```bash
php scripts/run_tests.php
```

Expected: FAIL because docs/playground do not mention photo.

- [ ] **Step 3: Add Playground UI**

Modify `/DATA/3waAIHub/admin/playground.php`:

- Include `photo` in the supported mode description.
- For selected `photo`, render:

```php
<label>image</label>
<input name="image" type="file" accept="image/jpeg,image/png,image/webp">
<label>image_id</label>
<input name="image_id" value="<?= hub_h((string)($_POST['image_id'] ?? '')) ?>">
<label>問題</label>
<textarea name="text" rows="4">這張圖裡有什麼？</textarea>
<label>max_tokens</label>
<input name="max_tokens" type="number" min="32" max="2048" value="256">
<label><input name="real_inference" type="checkbox" value="1" checked> 真實圖片理解</label>
<p class="muted">先上傳圖片取得 image_id，再用 image_id 重複提問；不建立 server-side session。</p>
```

For POST handling, if file exists call local `photo_upload` first, then call `photo` with the returned `image_id`. Keep token in POST only; do not save it.

- [ ] **Step 4: Add docs examples**

Add to `/DATA/3waAIHub/docs/api_examples.md`:

~~~markdown
## Photo Vision

Upload once:

```bash
curl -X POST "https://nature.focusit.tw/3waAIHub/api.php?mode=photo_upload" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "image=@example.jpg"
```

Ask many times:

```bash
curl -X POST "https://nature.focusit.tw/3waAIHub/api.php?mode=photo" \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{"image_id":"img_...","text":"這張圖裡有什麼？","max_tokens":256,"real_inference":true}'
```

No session is stored. Send prior context in `text` when needed.
~~~

Add the same flow in `/DATA/3waAIHub/docs/client_quickstart.md`, `/DATA/3waAIHub/README.md`, and public docs service list.

- [ ] **Step 5: Run GREEN**

Run:

```bash
php scripts/run_tests.php
php -l admin/playground.php
```

Expected: PASS.

---

### Task 5: Prune CLI And L5 Benchmarks

**Files:**
- Create: `/DATA/3waAIHub/tools/prune_photo_assets.php`
- Modify: `/DATA/3waAIHub/app/benchmarks.php`
- Modify: `/DATA/3waAIHub/packs/llm-gemma4-12b/fixtures/benchmark_cases.json`
- Create: `/DATA/3waAIHub/packs/llm-gemma4-12b/demo/photo_general.png`
- Create: `/DATA/3waAIHub/packs/llm-gemma4-12b/demo/photo_ui.png`
- Modify: `/DATA/3waAIHub/history.md`
- Test: `/DATA/3waAIHub/tests/test_photo_vision.php`
- Test: `/DATA/3waAIHub/tests/test_benchmark.php`

**Interfaces:**
- Consumes `hub_photo_prune_expired()`.
- Produces:
  - `php tools/prune_photo_assets.php --dry-run`
  - `php tools/prune_photo_assets.php --limit=100`
  - benchmark cases `gemma4_mock_photo`, `gemma4_real_photo_general`, `gemma4_real_photo_ui`.

- [ ] **Step 1: Add failing prune and benchmark tests**

Append:

```php
hub_test('Photo prune dry-run does not delete active files and apply deletes expired rows', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Prune Owner', 'prune@example.test', '')['id'];
    $tmp = tempnam(sys_get_temp_dir(), 'photo_');
    imagepng(imagecreatetruecolor(3, 3), $tmp);
    $asset = hub_photo_store_upload($db, ['name' => 'a.png', 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)], ['member_id' => (int)$memberId]);
    $db->prepare('UPDATE photo_assets SET expires_at = :expires_at WHERE image_id = :image_id')
        ->execute([':expires_at' => '2000-01-01 00:00:00', ':image_id' => $asset['image_id']]);

    $dry = hub_photo_prune_expired($db, true, 100);
    hub_test_assert((int)$dry['matched'] === 1 && (int)$dry['rows_deleted'] === 0, 'dry run must not delete rows');
    $applied = hub_photo_prune_expired($db, false, 100);
    hub_test_assert((int)$applied['rows_deleted'] === 1, 'apply must delete expired row');
    hub_test_assert(!is_file(HUB_DATA_DIR . '/' . $asset['storage_relpath']), 'expired file must be deleted');
});
```

- [ ] **Step 2: Run RED**

Run:

```bash
php scripts/run_tests.php
```

Expected: FAIL until prune is implemented.

- [ ] **Step 3: Implement prune function and CLI**

Add to `/DATA/3waAIHub/app/photo_assets.php`:

```php
function hub_photo_prune_expired(PDO $db, bool $dryRun, int $limit): array
{
    $limit = max(1, min(1000, $limit));
    $stmt = $db->prepare('SELECT * FROM photo_assets WHERE expires_at < :now ORDER BY expires_at ASC LIMIT :limit');
    $stmt->bindValue(':now', hub_now(), PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $summary = ['ok' => true, 'dry_run' => $dryRun, 'matched' => count($rows), 'files_deleted' => 0, 'rows_deleted' => 0, 'errors' => 0];
    foreach ($rows as $row) {
        $path = hub_photo_asset_host_path($row);
        if (!$dryRun && $path !== null && is_file($path) && unlink($path)) {
            $summary['files_deleted']++;
            @rmdir(dirname($path));
        }
        if (!$dryRun) {
            $db->prepare('DELETE FROM photo_assets WHERE id = :id')->execute([':id' => (int)$row['id']]);
            $summary['rows_deleted']++;
        }
    }
    return $summary;
}
```

Create `/DATA/3waAIHub/tools/prune_photo_assets.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
hub_cli_only();

$dryRun = in_array('--dry-run', $argv, true);
$limit = 100;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = (int)substr($arg, 8);
    }
}
$db = hub_db();
hub_migrate($db);
hub_ensure_default_storage_settings($db);
echo json_encode(hub_photo_prune_expired($db, $dryRun, $limit), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
```

- [ ] **Step 4: Add benchmark support**

Modify `/DATA/3waAIHub/app/benchmarks.php`:

- For `gemma4_mock_photo`, return mock payload directly.
- For real photo cases, upload fixture with `hub_photo_store_upload()`, then dispatch `photo` with JSON body.
- Assert required keys, no path leaks, `answer` non-empty, `caption` non-empty, `tags` array.

Add fixture list to `/DATA/3waAIHub/packs/llm-gemma4-12b/fixtures/benchmark_cases.json`:

```json
[
  "gemma4_mock_chat",
  "gemma4_real_chat",
  "gemma4_mock_photo",
  "gemma4_real_photo_general",
  "gemma4_real_photo_ui"
]
```

- [ ] **Step 5: Add history entry**

Add to top of `/DATA/3waAIHub/history.md`:

```markdown
## PhaseL-1C Gemma 4 Photo Vision

Implemented:

- `photo_upload` internal Gateway mode.
- `photo` internal Gateway mode using `image_id + text`.
- Secure `photo_assets` storage with owner, TTL, SHA-256, dimensions, and prune support.
- Gemma 4 Pack-local `/photo` adapter.
- Playground and docs for upload-once / ask-many workflow.

Skipped:

- server-side sessions
- multi-image prompts
- album/RAG indexing
- bbox/segmentation
- streaming
```

- [ ] **Step 6: Full verification**

Run:

```bash
cd /DATA/3waAIHub
php scripts/run_tests.php
php -d zend.assertions=1 -d assert.exception=1 scripts/self_check.php
php scripts/token_api_smoke.php
find . -path './data' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
python3 -m py_compile packs/llm-gemma4-12b/service/*.py
php tools/prune_photo_assets.php --dry-run
git diff --check
```

Expected: all PASS.

Optional real smoke when `gemma4-main` is running:

```bash
php scripts/benchmark.php --service=gemma4-main --case=gemma4_mock_photo
php scripts/benchmark.php --service=gemma4-main --case=gemma4_real_photo_general
php scripts/benchmark.php --service=gemma4-main --case=gemma4_real_photo_ui
```

---

## Self-Review

- Spec coverage: upload API, ask API, storage, ownership, TTL, path safety, Pack adapter, read-only mount, prune, Playground, docs, and benchmarks are assigned to tasks.
- YAGNI check: no session, no media framework, no new execution type, no new Pack, no provider router.
- Security check: tests include invalid image, ownership denial, blocked path fields, expired prune, and no path exposure.
- Type consistency: `image_id`, `image_internal_path`, `owner_member_id`, `owner_token_id`, `storage_relpath`, `expires_at`, and response fields match the PhaseL-1C spec.
- Known implementation ceiling: benchmark fixture assertions are intentionally shape/keyword checks, not exact model output checks.
