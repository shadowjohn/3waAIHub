# Live Pack API Documentation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make public docs, the agent manifest, and admin docs advertise only Pack APIs that are installed, enabled, running, and healthy on the current host.

**Architecture:** `hub_public_api_services()` becomes the only contract inventory. It filters service rows, performs one bounded concurrent loopback health batch, resolves eligible rows to Pack manifests, and returns the existing contract shape. All three documentation surfaces render that same array; no schema, cache, worker, or dependency is added.

**Tech Stack:** PHP 8, PDO/SQLite, PHP cURL multi, existing Hub Pack registry and PHP test runner.

---

## File Structure

| File | Responsibility |
| --- | --- |
| `app/public_api_docs.php` | Candidate filtering, loopback health checks, canonical contract list, public HTML, and agent manifest. |
| `admin/api_docs.php` | Admin-only rendering of the canonical contract list. |
| `tests/test_public_api_docs.php` | Visibility, health, derived API, empty-state, and source regression checks. |
| `README.md` | Current Pack inventory and live documentation policy. |

No new PHP files are created, so the existing file modes remain unchanged.

### Task 1: Build The Canonical Live Contract Inventory

**Files:**
- Modify: `tests/test_public_api_docs.php`
- Modify: `app/public_api_docs.php`

- [ ] **Step 1: Add deterministic service fixtures and failing visibility tests**

Add this helper before the first test in `tests/test_public_api_docs.php`:

```php
function hub_test_make_documentable_pack(PDO $db, string $packId, array $state = []): array
{
    $pack = hub_get_pack($packId);
    hub_test_assert($pack !== null && ($pack['status'] ?? '') === 'ok', 'test Pack unavailable: ' . $packId);
    $manifest = $pack['manifest'];
    $installed = hub_install_pack($db, $packId, [
        'service_key' => (string)($manifest['install']['default_service_key'] ?? ($packId . '-main')),
        'idempotent' => true,
    ]);
    $service = $installed['service'];
    $stmt = $db->prepare(
        'UPDATE services SET mode = :mode, health_url = :health_url, install_status = :install_status,
            enabled = :enabled, runtime_status = :runtime_status, status = :status WHERE id = :id'
    );
    $stmt->execute([
        ':mode' => (string)($state['mode'] ?? $service['mode']),
        ':health_url' => (string)($state['health_url'] ?? $service['health_url']),
        ':install_status' => (string)($state['install_status'] ?? 'installed'),
        ':enabled' => (int)($state['enabled'] ?? 1),
        ':runtime_status' => (string)($state['runtime_status'] ?? 'running'),
        ':status' => (string)($state['status'] ?? 'running'),
        ':id' => (int)$service['id'],
    ]);

    return hub_get_service($db, (int)$service['id']) ?: [];
}
```

Add a focused test that creates these rows:

```php
hub_test('Public API inventory requires installed enabled running and healthy services', function (): void {
    require_once HUB_ROOT . '/app/public_api_docs.php';
    $db = hub_test_reset_db();

    hub_test_make_documentable_pack($db, 'hello', ['mode' => 'hello_live']);
    hub_test_make_documentable_pack($db, 'ocr-ppocrv5', ['enabled' => 0]);
    hub_test_make_documentable_pack($db, 'yolo', ['runtime_status' => 'stopped', 'status' => 'stopped']);
    hub_test_make_documentable_pack($db, 'translate-gemma12b', ['install_status' => 'pending']);
    hub_test_make_documentable_pack($db, 'sam3', ['health_url' => 'http://198.51.100.8/health']);
    hub_test_make_documentable_pack($db, 'image-birefnet');
    hub_test_make_documentable_pack($db, 'docparser');
    hub_test_make_documentable_pack($db, 'llm-gemma4-12b');
    hub_test_make_documentable_pack($db, 'yolo-serving');

    $probe = static fn (array $service): bool => in_array((string)$service['mode'], ['hello_live', 'chat'], true);
    $services = hub_public_api_services($db, $probe);
    $modes = array_column($services, 'mode');

    hub_test_assert(in_array('hello_live', $modes, true), 'service row mode must be documented');
    hub_test_assert(in_array('docparser', $modes, true), 'running internal task must be documented');
    hub_test_assert(in_array('photo_upload', $modes, true), 'healthy Gemma parent must expose photo APIs');
    hub_test_assert(!in_array('ocr', $modes, true), 'disabled service must be hidden');
    hub_test_assert(!in_array('yolo', $modes, true), 'stopped service must be hidden');
    hub_test_assert(!in_array('translate', $modes, true), 'not-installed service must be hidden');
    hub_test_assert(!in_array('sam3', $modes, true), 'non-loopback health URL must be hidden');
    hub_test_assert(!in_array('background_remove', $modes, true), 'failed health probe must hide service');
    hub_test_assert(!in_array('yolo_model_register', $modes, true), 'unhealthy YOLO parent must hide derived APIs');

    $allHealthy = hub_public_api_services($db, static fn (array $service): bool => true);
    hub_test_assert(in_array('yolo_model_register', array_column($allHealthy, 'mode'), true), 'healthy YOLO parent must expose derived APIs');

    hub_test_assert(hub_public_api_health_response_ok(200, '{"ok":true}'), 'healthy JSON response rejected');
    hub_test_assert(hub_public_api_health_response_ok(204, ''), 'empty success response rejected');
    hub_test_assert(!hub_public_api_health_response_ok(503, '{"ok":true}'), 'HTTP failure accepted');
    hub_test_assert(!hub_public_api_health_response_ok(200, '{"ok":false}'), 'ok=false accepted');
    hub_test_assert(!hub_public_api_health_response_ok(200, '{"ready":false}'), 'ready=false accepted');

    $emptyDb = hub_test_reset_db();
    $emptyManifest = hub_public_api_manifest($emptyDb, static fn (array $service): bool => true);
    hub_test_assert(($emptyManifest['services'] ?? null) === [], 'empty inventory must not fall back to repository Packs');
});
```

In the existing long policy/example test, install the Packs whose examples it asserts:

```php
foreach ([
    'hello',
    'ocr-ppocrv5',
    'yolo',
    'yolo-serving',
    'translate-gemma12b',
    'sam3',
    'llm-gemma4-12b',
    'image-birefnet',
    'docparser',
] as $packId) {
    hub_test_make_documentable_pack($db, $packId);
}
$healthy = static fn (array $service): bool => true;
```

Pass `$healthy` to the existing calls:

```php
$manifest = hub_public_api_manifest($db, $healthy);
$docsHtml = hub_public_api_docs_html($db, null, $healthy);
```

- [ ] **Step 2: Run the suite and confirm the new behavior fails**

Run:

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php
```

Expected: non-zero exit with the new visibility test failing because the health response helper and DB-backed filtering do not exist.

- [ ] **Step 3: Add the minimal bounded health helpers**

Add these functions immediately before `hub_public_api_services()` in `app/public_api_docs.php`:

```php
function hub_public_api_health_response_ok(int $status, string $body): bool
{
    if ($status < 200 || $status >= 400) {
        return false;
    }
    $payload = json_decode($body, true);

    return !is_array($payload)
        || ((!array_key_exists('ok', $payload) || $payload['ok'] !== false)
            && (!array_key_exists('ready', $payload) || $payload['ready'] !== false));
}

function hub_public_api_health_url_allowed(string $url): bool
{
    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'http' || isset($parts['user']) || isset($parts['pass'])) {
        return false;
    }
    $host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));

    return in_array($host, ['127.0.0.1', '::1', 'localhost'], true);
}

function hub_public_api_healthy_service_ids(array $services, ?callable $probe = null): array
{
    $healthy = [];
    $pending = [];
    foreach ($services as $service) {
        $id = (int)($service['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        if (hub_service_is_internal_task($service)) {
            $healthy[$id] = true;
            continue;
        }
        if (!hub_public_api_health_url_allowed((string)($service['health_url'] ?? ''))) {
            continue;
        }
        if ($probe !== null) {
            if ($probe($service) === true) {
                $healthy[$id] = true;
            }
            continue;
        }
        $pending[$id] = $service;
    }
    if ($pending === [] || !function_exists('curl_multi_init')) {
        return $healthy;
    }

    $multi = curl_multi_init();
    $handles = [];
    foreach ($pending as $id => $service) {
        $handle = curl_init((string)$service['health_url']);
        if ($handle === false) {
            continue;
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => 250,
            CURLOPT_TIMEOUT_MS => 750,
            CURLOPT_NOSIGNAL => true,
        ]);
        curl_multi_add_handle($multi, $handle);
        $handles[$id] = $handle;
    }

    $deadline = microtime(true) + 1.0;
    do {
        $result = curl_multi_exec($multi, $running);
        if ($result !== CURLM_OK || $running === 0 || microtime(true) >= $deadline) {
            break;
        }
        if (curl_multi_select($multi, min(0.05, max(0.001, $deadline - microtime(true)))) === -1) {
            usleep(10000);
        }
    } while (true);

    foreach ($handles as $id => $handle) {
        $body = curl_multi_getcontent($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if (curl_errno($handle) === 0 && hub_public_api_health_response_ok($status, (string)$body)) {
            $healthy[$id] = true;
        }
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }
    curl_multi_close($multi);

    return $healthy;
}
```

- [ ] **Step 4: Make `hub_public_api_services()` DB-first**

Change its signature to:

```php
function hub_public_api_services(PDO $db, ?callable $healthProbe = null): array
```

Build candidates and healthy IDs before the contract loop:

```php
$candidates = array_values(array_filter(
    hub_list_services($db),
    static fn (array $service): bool =>
        (string)($service['install_status'] ?? '') === 'installed'
        && (int)($service['enabled'] ?? 0) === 1
        && (string)($service['runtime_status'] ?? '') === 'running'
));
$healthyIds = hub_public_api_healthy_service_ids($candidates, $healthProbe);
$services = [];
$documentedPacks = [];
```

Replace the repository Pack loop with:

```php
foreach ($candidates as $row) {
    if (!isset($healthyIds[(int)$row['id']])) {
        continue;
    }
    $pack = hub_get_pack((string)($row['pack_id'] ?? ''));
    if ($pack === null || ($pack['status'] ?? '') !== 'ok') {
        continue;
    }
    $manifest = is_array($pack['manifest'] ?? null) ? $pack['manifest'] : [];
    $mode = trim((string)($row['mode'] ?? ''));
    if ($mode === '') {
        continue;
    }
    $contract = hub_public_api_contract_for_manifest($manifest);
    $method = hub_public_api_method($manifest, $contract);
    $contentType = hub_public_api_content_type($method, $contract);
    $fields = is_array($contract['input']['fields'] ?? null) ? $contract['input']['fields'] : [];
    $output = is_array($contract['output'] ?? null) ? $contract['output'] : [];
    $service = [
        'mode' => $mode,
        'pack_id' => (string)($manifest['id'] ?? $pack['id'] ?? ''),
        'name' => (string)($manifest['name'] ?? $pack['id'] ?? ''),
        'description' => (string)($manifest['description'] ?? ''),
        'method' => $method,
        'content_type' => $contentType,
        'endpoint' => 'api.php?mode=' . $mode,
        'url' => hub_public_api_mode_url($mode),
        'execution_type' => (string)($manifest['execution_type'] ?? ''),
        'runtime_level' => (string)($manifest['runtime_level'] ?? ''),
        'task_type' => (string)($contract['task_type'] ?? ''),
        'input_fields' => $fields,
        'output_keys' => array_values(array_map('strval', is_array($output['required_keys'] ?? null) ? $output['required_keys'] : [])),
        'response_content_type' => trim((string)($output['content_type'] ?? 'application/json')),
        'response_headers' => array_values(array_map('strval', is_array($output['required_headers'] ?? null) ? $output['required_headers'] : [])),
        'error_codes' => array_values(array_map('strval', is_array($contract['errors'] ?? null) ? $contract['errors'] : [])),
        'task_api' => hub_public_api_task_api_refs(is_array($contract['task_api'] ?? null) ? $contract['task_api'] : []),
    ];
    $service['examples'] = hub_public_api_examples($service);
    $services[$mode] = $service;
    $documentedPacks[(string)$service['pack_id']] = true;
}
```

Gate derived APIs with the eligible parent map and de-duplicate by mode:

```php
if (isset($documentedPacks['llm-gemma4-12b'])) {
    foreach (hub_public_api_gemma4_services() as $service) {
        $service['examples'] = hub_public_api_examples($service);
        $services[(string)$service['mode']] = $service;
    }
}
if (isset($documentedPacks['yolo-serving'])) {
    foreach (hub_public_api_yolo_model_services() as $service) {
        $service['examples'] = hub_public_api_examples($service);
        $services[(string)$service['mode']] = $service;
    }
}
ksort($services);

return array_values($services);
```

Add an optional health callback to the manifest wrapper:

```php
function hub_public_api_manifest(PDO $db, ?callable $healthProbe = null): array
{
    return [
        'name' => '3waAIHub',
        'version' => HUB_VERSION,
        'auth' => ['type' => 'bearer', 'header' => 'Authorization: Bearer <TOKEN>'],
        'base_endpoint' => 'api.php',
        'services' => hub_public_api_services($db, $healthProbe),
    ];
}

```

Change the existing HTML wrapper declaration and first assignment:

```diff
-function hub_public_api_docs_html(PDO $db, ?array $user = null): string
+function hub_public_api_docs_html(PDO $db, ?array $user = null, ?callable $healthProbe = null): string
 {
-    $services = hub_public_api_services($db);
+    $services = hub_public_api_services($db, $healthProbe);
```

- [ ] **Step 5: Run the regression suite**

Run:

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php
```

Expected: `failures=0`. Existing example assertions still pass because their test now installs and enables the required Packs.

- [ ] **Step 6: Commit the canonical inventory**

```bash
git add app/public_api_docs.php tests/test_public_api_docs.php
git commit -m "feat: filter API contracts by live services"
```

### Task 2: Render The Same Contracts On All Documentation Surfaces

**Files:**
- Modify: `tests/test_public_api_docs.php`
- Modify: `app/public_api_docs.php`
- Modify: `admin/api_docs.php`

- [ ] **Step 1: Add failing public/admin rendering assertions**

Extend the empty-inventory test:

```php
$emptyHtml = hub_public_api_docs_html($emptyDb, null, static fn (array $service): bool => true);
hub_test_assert(str_contains($emptyHtml, '目前沒有健康且可用的 API 服務'), 'public docs empty state missing');
hub_test_assert(!str_contains($emptyHtml, 'DocParser 局部補翻譯'), 'DocParser hint must follow Pack visibility');
hub_test_assert(!str_contains($emptyHtml, 'Local Job Contract v0.1'), 'YOLO Local Jobs must follow Pack visibility');
```

Extend the source contract test:

```php
$adminDocs = (string)file_get_contents(HUB_ROOT . '/admin/api_docs.php');
hub_test_assert(str_contains($adminDocs, "require_once __DIR__ . '/../app/public_api_docs.php'"), 'admin docs must load shared contracts');
hub_test_assert(str_contains($adminDocs, 'hub_public_api_services($db)'), 'admin docs must use shared contracts');
foreach (['hub_list_services($db)', 'hub_pack_api_contracts()', '<h2>GET hello</h2>', '<h2>POST OCR</h2>', '<h2>POST Translate</h2>', '<h2>POST SAM3</h2>'] as $removed) {
    hub_test_assert(!str_contains($adminDocs, $removed), 'admin docs still contains duplicated source: ' . $removed);
}
```

- [ ] **Step 2: Run the suite and confirm rendering tests fail**

Run:

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php
```

Expected: non-zero exit because the public empty state and simplified admin source are absent.

- [ ] **Step 3: Make Pack-specific public prose conditional**

After loading `$services` in `hub_public_api_docs_html()`, add:

```php
$packIds = array_fill_keys(array_map('strval', array_column($services, 'pack_id')), true);
$hasDocParser = isset($packIds['docparser']);
$hasYolo = isset($packIds['yolo']);
```

Wrap the DocParser repair paragraph with `$hasDocParser`. In the API modes panel, replace the unconditional loop with:

```php
<?php if ($services === []): ?>
    <p class="muted"><?= $t('目前沒有健康且可用的 API 服務。') ?></p>
<?php else: ?>
    <p><?php foreach ($services as $service): ?><code><?= hub_h((string)$service['mode']) ?></code> <?php endforeach; ?></p>
<?php endif; ?>
```

Show the Local Jobs navigation tab and the complete existing `id="local-jobs"` section only when `$hasYolo` is true. Do not build a generic Local Jobs generator.

- [ ] **Step 4: Replace admin's second documentation flow**

At the top of `admin/api_docs.php`, load the shared helper:

```php
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/public_api_docs.php';
require __DIR__ . '/_layout.php';
```

Delete `hub_api_docs_public_base_url()`, `hub_api_docs_mode_url()`, `hub_api_docs_multipart_curl_fields()`, `$services = hub_list_services($db)`, `$contracts = hub_pack_api_contracts()`, and the hard-coded Hello/OCR/Translate/SAM3 sections. Initialize:

```php
$services = hub_public_api_services($db);
$baseUrl = hub_public_api_base_url();
```

Use `<mode>` in the generic token example:

```php
<pre><?= hub_h($curlExecutable) ?> "<?= hub_h($baseUrl) ?>?mode=&lt;mode&gt;" <?= hub_h($curlContinuation) ?>
  -H "Authorization: Bearer 3wa_live_xxx"</pre>
```

Render the canonical contracts:

```php
<section class="panel">
    <h2>目前可用的 Pack API</h2>
    <?php if ($services === []): ?>
        <p class="muted">目前沒有健康且可用的 API 服務。</p>
    <?php endif; ?>
    <?php foreach ($services as $service): ?>
        <h3><?= hub_h((string)$service['name']) ?></h3>
        <table>
            <tr><th>Mode</th><td><code><?= hub_h((string)$service['mode']) ?></code></td></tr>
            <tr><th>Pack</th><td><code><?= hub_h((string)$service['pack_id']) ?></code></td></tr>
            <tr><th>HTTP 方法</th><td><code><?= hub_h((string)$service['method']) ?></code></td></tr>
            <tr><th>Content-Type</th><td><code><?= hub_h((string)$service['content_type']) ?></code></td></tr>
            <tr><th>輸入欄位</th><td><pre class="inline-pre"><?= hub_h(json_encode($service['input_fields'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre></td></tr>
            <tr><th>輸出欄位</th><td><pre class="inline-pre"><?= hub_h(json_encode($service['output_keys'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre></td></tr>
            <tr><th>錯誤碼</th><td><code><?= hub_h(implode(', ', $service['error_codes'])) ?></code></td></tr>
        </table>
        <h4>curl</h4>
        <pre><?= hub_h((string)$service['examples']['curl']) ?></pre>
    <?php endforeach; ?>
</section>
```

Keep the generic unknown-mode section, changing its URL helper to `hub_public_api_mode_url('unknown')`.

- [ ] **Step 5: Run syntax and regression checks**

Run:

```bash
php -l app/public_api_docs.php
php -l admin/api_docs.php
AIHUB_TEST_QUIET=1 php scripts/run_tests.php
```

Expected: both syntax checks report no errors and the test summary reports `failures=0`.

- [ ] **Step 6: Commit the aligned renderers**

```bash
git add app/public_api_docs.php admin/api_docs.php tests/test_public_api_docs.php
git commit -m "fix: align API documentation surfaces"
```

### Task 3: Refresh README And Run Final Verification

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Update the current capability summary**

Replace the opening Pack inventory sentence with one that distinguishes current levels:

```markdown
目前已完成 Local HubPack Catalog、多 Service Instance、service-level IP whitelist、API trace、Bearer token auth、SQLite retention guard、Dashboard metrics 與 Pack hardware preflight。L5 benchmark-ready Pack 包含 `hello`、`ocr-ppocrv5`、`yolo`、`sam3`、`translate-gemma12b`、`tts-voxcpm2`、`structure-ppstructurev3`、`docparser`、`llm-gemma4-12b`、`image-birefnet` 與 `bioclip`；`taiwan-address` 為 L3 trusted upstream adapter，`audio-cleanup` 為 L1 contract，`whisper-asr` 仍標示為 experimental。
```

Add this feature bullet after the current API documentation bullets:

```markdown
- 公開 API 文件、Agent Manifest 與後台 API 文件共用同一份即時 inventory，只列出已安裝、已啟用、運行中且通過本機 health 的 Pack API
- Windows Core 支援受控 PHP／IIS FastCGI control plane；Windows 11 可搭配 WSL Runtime preview 執行目前的 Linux Docker vertical slice
```

- [ ] **Step 2: Document the live inventory policy**

After the paragraph that lists public contract fields, add:

```markdown
三個文件入口都從本機 `services` inventory 產生內容。HTTP Pack 會在開啟文件時並行執行 loopback health probe，整批最多等待一秒；失敗只隱藏該 Pack contract，不會讓文件入口失敗。`internal-task:health` 沿用已安裝、已啟用且運行中的既有語意。文件不會因 inventory 為空而退回列出 repository 內所有 Packs。
```

Keep the existing Windows Core, IIS FastCGI, and WSL Runtime preview sections; they already describe the current platform behavior.

- [ ] **Step 3: Verify README wording and the whole repository**

Run:

```bash
rg -n "即時 inventory|整批最多等待一秒|image-birefnet|bioclip|taiwan-address|Windows Core|WSL Runtime" README.md
git diff --check
AIHUB_TEST_QUIET=1 php scripts/run_tests.php
php -d zend.assertions=1 -d assert.exception=1 scripts/self_check.php
./scripts/bootstrap_self_check.sh
```

Expected:

- `rg` finds the live policy, current Pack levels, and retained Windows/WSL guidance.
- `git diff --check` prints nothing.
- PHP tests report `failures=0`.
- web self-check reports success.

- [ ] **Step 4: Confirm no generated or runtime files entered the diff**

Run:

```bash
git status --short
git diff --stat HEAD
```

Expected: only `README.md` is uncommitted at this task boundary; no `data/`, generated Compose, model, log, or cache files are present.

- [ ] **Step 5: Commit the documentation refresh**

```bash
git add README.md
git commit -m "docs: refresh live API inventory guidance"
```

- [ ] **Step 6: Final commit and permission audit**

Run:

```bash
git status --short --branch
git log -3 --oneline
stat -c '%a %n' app/public_api_docs.php admin/api_docs.php tests/test_public_api_docs.php
```

Expected:

- the worktree is clean;
- the three implementation commits are present;
- no new PHP file exists, and the three modified PHP files retain their existing mode.
