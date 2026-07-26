# Cluster Unified Entry Self Node Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let Router-issued customer Tokens receive every live Router Mode, while the Router host can register its own selected services as a direct local Cluster station.

**Architecture:** Reuse the Router's public manifest as the single source for customer-visible remote Modes. Reuse the child-node Token and `cluster_stations` record for the Router host, but bind it to `127.0.0.1` and refresh it in-process so no public-DNS loopback is involved. Existing Router dispatch continues to select the station and uses its existing self-station direct gateway path.

**Tech Stack:** PHP 8.3, SQLite, existing 3waAIHub test runner.

---

## File Structure

- Modify: `app/cluster_router.php` — derive live Router Mode names, register the local station, and refresh a self station without a remote HTTP fetch.
- Modify: `admin/api_token_permissions.php` — show remote-only live Router Modes alongside the existing permission groups.
- Modify: `admin/cluster.php` — provide the explicit system-admin action that registers or refreshes the local station.
- Modify: `docs/cluster-router.md` — document the customer Token and local-node operator flow.
- Modify: `tests/test_cluster_router.php` — prove visible Router Modes, loopback-only local registration, direct dispatch, and no self HTTP refresh.

### Task 1: Expose Live Router Modes to Token Permissions

**Files:**
- Modify: `app/cluster_router.php:709-780`
- Modify: `admin/api_token_permissions.php:23-53`
- Test: `tests/test_cluster_router.php`

- [ ] **Step 1: Write the failing Router-mode test**

Add this test beside the existing public-manifest tests in `tests/test_cluster_router.php`:

```php
hub_test('cluster router exposes fresh remote modes for customer permissions', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        $station = hub_test_cluster_router_station($db, ['station_key' => 'remote_address']);
        hub_cluster_store_station_manifest($db, (int)$station['id'], [
            'modes' => ['taiwan_address'],
            'services' => [[
                'mode' => 'taiwan_address',
                'name' => 'Taiwan Address',
                'method' => 'POST',
                'content_type' => 'application/json',
                'endpoint' => 'api.php?mode=taiwan_address',
            ]],
        ]);
        hub_cluster_store_station_status($db, (int)$station['id'], [
            'snapshot_at' => hub_now(),
            'gpu' => ['available' => true, 'memory_free_mb' => 1024],
            'active_gpu_leases' => 0,
            'queued_jobs' => 0,
            'running_jobs' => 0,
            'modes' => ['taiwan_address'],
        ]);

        hub_test_assert(hub_cluster_router_available_modes($db) === ['taiwan_address'], 'fresh Router-only modes must be available for Token permissions');
        $page = (string)file_get_contents(HUB_ROOT . '/admin/api_token_permissions.php');
        hub_test_assert(str_contains($page, 'hub_cluster_router_available_modes($db)') && str_contains($page, 'Cluster Router Mode'), 'Token permissions page must render live Router modes');
    });
});
```

- [ ] **Step 2: Run the suite to verify RED**

Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=full`

Expected: FAIL because `hub_cluster_router_available_modes()` does not exist.

- [ ] **Step 3: Add the smallest Router-mode helper and permission section**

After `hub_cluster_public_manifest()` in `app/cluster_router.php`, add a helper that returns sorted, validated Mode names only when `hub_cluster_router_enabled($db)` is true:

```php
function hub_cluster_router_available_modes(PDO $db): array
{
    if (!hub_cluster_router_enabled($db)) {
        return [];
    }
    $modes = [];
    foreach (hub_cluster_public_manifest($db)['services'] as $service) {
        $mode = is_array($service) ? trim((string)($service['mode'] ?? '')) : '';
        if (preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $mode) === 1) {
            $modes[$mode] = true;
        }
    }
    ksort($modes, SORT_STRING);

    return array_keys($modes);
}
```

In `admin/api_token_permissions.php`, build the existing local/system Mode name set, remove it from `hub_cluster_router_available_modes($db)`, and render only the remaining names under `Cluster Router Mode`:

```php
$shownModes = array_fill_keys(array_merge(
    array_column($services, 'mode'),
    array_keys($taskModes),
    array_keys($photoModes),
    array_keys($audioModes),
), true);
$routerModes = array_values(array_filter(
    hub_cluster_router_available_modes($db),
    static fn (string $mode): bool => !isset($shownModes[$mode]),
));
```

```php
<?php if ($routerModes !== []): ?>
    <h2>Cluster Router Mode</h2>
    <?php foreach ($routerModes as $mode): ?>
        <label><input type="checkbox" name="modes[]" value="<?= hub_h($mode) ?>"<?= in_array($mode, $enabledModes, true) ? ' checked' : '' ?>> <code><?= hub_h($mode) ?></code></label>
    <?php endforeach; ?>
<?php endif; ?>
```

Reuse the current CSRF form and `hub_set_api_token_mode_permissions()` save path.

- [ ] **Step 4: Run the suite to verify GREEN**

Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=full`

Expected: PASS with zero failures.

- [ ] **Step 5: Commit Task 1**

```bash
git add app/cluster_router.php admin/api_token_permissions.php tests/test_cluster_router.php
git commit -m "feat: expose router modes to token permissions"
```

### Task 2: Register and Refresh the Router Host as a Local Station

**Files:**
- Modify: `app/cluster_router.php:1-5,205-405,2695-2774`
- Modify: `admin/cluster.php:24-86,220-260`
- Test: `tests/test_cluster_router.php:1103-1134`

- [ ] **Step 1: Write failing self-registration tests**

Replace the setup in the existing `cluster router dispatches a configured self station directly with its paired router IP` test with this registration flow and expected peer IP:

```php
hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
hub_test_cluster_publish_mode($db, 'vision');
hub_cluster_node_configure($db, true, ['vision']);
$station = hub_cluster_register_self_station($db);

$rules = hub_enabled_api_token_ip_rules($db, hub_cluster_node_token_id($db));
hub_test_assert(hub_cluster_router_station_is_self($db, $station), 'registered local station must be the Router self station');
hub_test_assert(count($rules) === 1 && (string)$rules[0]['ip_rule'] === '127.0.0.1', 'local station Token must bind only to loopback');
hub_test_assert(hub_cluster_node_has_verified_router_peer($db, hub_cluster_node_token_id($db), '127.0.0.1'), 'local station must have a verified loopback Router peer');
```

Keep the existing direct-dispatch seam, change its expected `client_ip` to `127.0.0.1`, and remove the hand-written pairing invitation, station record, and self-key setup. Add a second test that registers the local station, calls `hub_cluster_refresh_station()` with a fetcher that increments a counter, and asserts it returns fresh inventory with the counter still `0`. Add a third test that pairs the node to `198.51.100.44` through `hub_cluster_accept_pair_invitation()` and asserts local registration throws without changing that remote IP rule.

- [ ] **Step 2: Run the suite to verify RED**

Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=full`

Expected: FAIL because `hub_cluster_register_self_station()` does not exist.

- [ ] **Step 3: Implement local registration and in-process self refresh**

Require `app/public_api_docs.php` from `app/cluster_router.php` so the existing `hub_public_api_manifest($db)` builder is available without a web request.

Add `hub_cluster_register_self_station(PDO $db): array` beside pairing import/save helpers. It must:

```php
if (!hub_cluster_router_enabled($db) || !hub_cluster_node_enabled($db)) {
    throw new RuntimeException('local cluster node requires both roles');
}
if (hub_get_storage_setting($db, 'AIHUB_CLUSTER_NODE_ROUTER_NAME') !== ''
    && !hub_cluster_node_has_verified_router_peer($db, hub_cluster_node_token_id($db), '127.0.0.1')) {
    throw new RuntimeException('local cluster node is paired to another router');
}
```

Then use this single transaction. It reuses the existing encrypted-station writer and exact child Token IP-rule writer:

```php
$routerName = trim(hub_site_title($db));
$routerName = function_exists('mb_substr') ? mb_substr($routerName, 0, 120, 'UTF-8') : substr($routerName, 0, 120);
$pairing = hub_cluster_node_pairing_descriptor($db);
$pairing['station_token'] = hub_cluster_node_reveal_token($db);

$db->beginTransaction();
try {
    $db->prepare('DELETE FROM api_token_ip_whitelists WHERE token_id = :token_id')
        ->execute([':token_id' => $tokenId]);
    hub_add_api_token_ip_rule($db, $tokenId, '127.0.0.1', 'cluster router');
    hub_set_storage_setting($db, 'AIHUB_CLUSTER_NODE_ROUTER_NAME', $routerName);
    hub_cluster_clear_pair_invitation($db);
    $stationId = hub_cluster_save_paired_station($db, $pairing);
    hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_SELF_STATION_KEY', (string)$pairing['station_key']);
    $station = hub_cluster_get_station($db, $stationId);
    if ($station === null) {
        throw new RuntimeException('local cluster station registration failed');
    }
    $db->commit();

    return $station;
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $e;
}
```

In `hub_cluster_refresh_station_now()`, branch after loading the station. Keep manifest compaction/storage after the branch, then do the same for status compaction/storage:

```php
if (hub_cluster_router_station_is_self($db, $station)) {
    $manifest = hub_public_api_manifest($db);
    $status = hub_cluster_status_payload($db);
    $statusReceivedAt = time();
} else {
    $baseUrl = hub_cluster_station_request_base_url($station);
    $token = hub_cluster_station_token($station);
    $fetcher ??= 'hub_cluster_default_station_fetcher';
    $manifestResponse = $fetcher(['url' => $baseUrl . 'api_manifest.json.php', 'method' => 'GET', 'headers' => []]);
    $manifest = hub_cluster_refresh_json_payload($manifestResponse);
    $statusResponse = $fetcher([
        'url' => $baseUrl . 'cluster_status.php',
        'method' => 'GET',
        'headers' => ['Authorization' => 'Bearer ' . $token],
    ]);
    $status = hub_cluster_refresh_json_payload($statusResponse);
    $statusReceivedAt = time();
}
```

Keep the existing compact snapshot validation and storage calls shared by both branches. The self branch must never invoke the supplied remote fetcher, and the remote branch must preserve its current error names and Token-safe behavior.

In `admin/cluster.php`, add this CSRF-protected post branch before the existing child-pairing branch:

```php
} elseif ($action === 'register_self_station') {
    $station = hub_cluster_register_self_station($db);
    hub_cluster_refresh_station_now($db, $station, true, null);
    $message = '本機節點已登錄。';
```

When both roles are enabled, render this single control after the child-node service selector:

```php
<section class="panel">
    <h2>本機節點</h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <button class="primary" name="action" value="register_self_station" type="submit">登錄 / 更新本機服務</button>
    </form>
</section>
```

Do not add a second Token UI or a manual station URL field.

- [ ] **Step 4: Run the suite to verify GREEN**

Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=full`

Expected: PASS with zero failures, including the direct-dispatch and no-fetcher self-refresh tests.

- [ ] **Step 5: Commit Task 2**

```bash
git add app/cluster_router.php admin/cluster.php tests/test_cluster_router.php
git commit -m "feat: register router host as local cluster station"
```

### Task 3: Publish the Operator Flow and Verify the Integrated Entry

**Files:**
- Modify: `docs/cluster-router.md`
- Test: `tests/test_cluster_router.php`

- [ ] **Step 1: Write the failing documentation contract test**

Add a source-contract assertion that `docs/cluster-router.md` contains all three operator-facing terms:

```php
$guide = (string)file_get_contents(HUB_ROOT . '/docs/cluster-router.md');
foreach (['Cluster Router Mode', '登錄 / 更新本機服務', 'cluster_api.php'] as $needle) {
    hub_test_assert(str_contains($guide, $needle), 'cluster guide must document ' . $needle);
}
```

- [ ] **Step 2: Run the suite to verify RED**

Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=full`

Expected: FAIL because the guide does not yet describe the Router Token permission group or local registration action.

- [ ] **Step 3: Update the concise customer and operator guide**

In `docs/cluster-router.md`:

```markdown
1. Create the customer account and Token at the unified entry.
2. In that Token's `Mode 權限`, check local modes and any visible `Cluster Router Mode` entries.
3. Give the client only `cluster_api.php`, the Router Token, and the public manifest/docs URLs.
```

Add the local Router-host operation: enable both roles, select running services under `子入口節點`, press `登錄 / 更新本機服務`, refresh the card, and confirm the selected Modes appear in `cluster_manifest.json.php`. State that this action uses an in-process local station; external 1080/5090 nodes continue to use their one-time pairing links.

- [ ] **Step 4: Run PHP lint and the full suite**

Run:

```bash
php -l app/cluster_router.php
php -l admin/cluster.php
php -l admin/api_token_permissions.php
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=full
```

Expected: every syntax check reports `No syntax errors detected`; test summary reports `failures=0`.

- [ ] **Step 5: Commit Task 3**

```bash
git add docs/cluster-router.md tests/test_cluster_router.php
git commit -m "docs: explain unified cluster entry"
```
