# Cluster Station Name and Health Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep Router station names synchronized after a child rename and show a reliable green/red connectivity state throughout Cluster administration.

**Architecture:** Reuse the authenticated `cluster_status.php` response as the source of a child station's current title. The Router accepts an optional, bounded title only after a complete successful refresh, so older children remain compatible and failed refreshes keep the last known title. One shared Cluster helper defines online/offline from the existing error code and the agreed 150-second snapshot freshness window; both admin views render that value without new polling or storage.

**Tech Stack:** PHP 8.3, SQLite, existing Cluster Router protocol, server-rendered admin UI, existing PHP test runner.

---

## File structure

- Modify: `app/cluster_router.php` — expose, validate, persist, and derive station connection data.
- Modify: `app/admin_dashboard.php` — include the derived connection state in active station summaries and tabs.
- Modify: `admin/index.php` — render the Dashboard tab dot and active station's text label.
- Modify: `admin/cluster.php` — render the same text label in child station cards.
- Modify: `assets/css/admin-dashboard.css` — style compact green/red dots and labels.
- Modify: `assets/css/admin-base.css` — style the Cluster-management page's shared text badge.
- Modify: `README.md` — state the approved 150-second Router freshness window.
- Modify: `tests/test_cluster_router.php` — protect protocol, rename, 150-second, and error-state behavior.
- Modify: `tests/test_admin_dashboard.php` — protect Dashboard model and markup contracts.
- Modify: `tests/test_cluster_admin.php` — protect Cluster-management markup contract.

### Task 1: Synchronize a verified child station name

**Files:**
- Modify: `tests/test_cluster_router.php:1001-1041,1182-1224`
- Modify: `app/cluster_router.php:2980-3019,3570-3634`

- [ ] **Step 1: Write failing protocol and refresh tests**

Add `display_name` to the expected child status payload and add a refresh fixture that reports a different title:

```php
$refreshed = hub_cluster_refresh_station($db, $station, static function (array $request) use ($snapshotAt): array {
    if (str_ends_with((string)$request['url'], '/api_manifest.json.php')) {
        return ['status' => 200, 'body' => json_encode(['services' => [['mode' => 'ocr']]], JSON_THROW_ON_ERROR)];
    }

    return ['status' => 200, 'body' => json_encode([
        'ok' => true,
        'display_name' => '更名後子節點',
        'snapshot_at' => $snapshotAt,
        'gpu' => ['available' => true],
        'active_gpu_leases' => 0,
        'queued_jobs' => 0,
        'running_jobs' => 0,
        'modes' => ['ocr'],
    ], JSON_THROW_ON_ERROR)];
});
$stored = hub_cluster_get_station($db, $stationId);
hub_test_assert(
    !empty($refreshed['fresh']) && ($stored['display_name'] ?? '') === '更名後子節點',
    'a verified child status must synchronize its display name'
);
```

Keep the legacy protocol compatible and validate the new trust-boundary field:

```php
$legacy = $status;
unset($legacy['display_name']);
hub_test_assert(hub_cluster_compact_status_snapshot($legacy, $now) !== null, 'older child status must remain compatible');

$invalidName = $status;
$invalidName['display_name'] = '   ';
hub_test_assert(hub_cluster_compact_status_snapshot($invalidName, $now) === null, 'blank child display names must be rejected');
```

Update both exact key assertions in the child-status test: the full report and the legacy-health fallback must each contain `display_name` after `snapshot_at`.

In the existing non-200 status test, store the original name first and assert it remains unchanged after `status_http_403`:

```php
$stored = hub_cluster_get_station($db, $stationId);
hub_test_assert(
    ($stored['display_name'] ?? '') === 'Taipei GPU 1',
    'a failed station refresh must retain the last verified display name'
);
```

- [ ] **Step 2: Run the control-plane suite and verify RED**

Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane`

Expected: failures identify the missing `display_name` payload contract and that successful refresh does not yet update `cluster_stations.display_name`.

- [ ] **Step 3: Add only the compatible protocol fields and persistence**

In `hub_cluster_status_payload()`, insert the current child title after `snapshot_at`:

```php
'display_name' => hub_site_title($db),
```

In `hub_cluster_compact_status_snapshot()`, accept `display_name` only when it is a non-empty string no longer than 120 characters. Do not reject an absent field so older children remain refreshable:

```php
$displayName = null;
if (array_key_exists('display_name', $status)) {
    if (!is_string($status['display_name'])) {
        return null;
    }
    $displayName = trim($status['display_name']);
    $length = function_exists('mb_strlen') ? mb_strlen($displayName, 'UTF-8') : strlen($displayName);
    if ($displayName === '' || $length > 120) {
        return null;
    }
}
$snapshot = array_merge([
    'snapshot_at' => $snapshotAt,
    'gpu' => $gpu,
    'active_gpu_leases' => $status['active_gpu_leases'],
    'queued_jobs' => $status['queued_jobs'],
    'running_jobs' => $status['running_jobs'],
    'modes' => $modes,
], $report);
if ($displayName !== null) {
    $snapshot['display_name'] = $displayName;
}

return $snapshot;
```

In `hub_cluster_store_station_status()`, update the name in the same successful status write only when the compact snapshot supplied one:

```php
$displayName = (string)($snapshot['display_name'] ?? '');
$db->prepare(
    'UPDATE cluster_stations
     SET display_name = CASE WHEN :display_name <> \'\' THEN :display_name ELSE display_name END,
         status_json = :status_json, status_fetched_at = :fetched_at,
         last_error = :last_error, updated_at = :updated_at
     WHERE id = :id'
)->execute([
    ':display_name' => $displayName,
    ':status_json' => json_encode($snapshot, JSON_THROW_ON_ERROR),
    ':fetched_at' => $snapshot['snapshot_at'],
    ':last_error' => '',
    ':updated_at' => hub_now(),
    ':id' => $stationId,
]);
```

- [ ] **Step 4: Run the control-plane suite and verify GREEN**

Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane`

Expected: `failures=0`; legacy fixtures without `display_name` still refresh successfully.

- [ ] **Step 5: Commit the protocol change**

```bash
git add app/cluster_router.php tests/test_cluster_router.php
git commit -m "feat: sync cluster station names"
```

### Task 2: Define the shared green/red connection state

**Files:**
- Modify: `tests/test_cluster_router.php:1032-1041`
- Modify: `app/cluster_router.php:3022-3033,3671-3688,464-528`

- [ ] **Step 1: Write failing freshness and connection-state tests**

Replace every Cluster test fixture using 91 seconds as stale (`1032-1041`, `1212-1224`, and `2633-2648`) with the agreed 150-second boundary, and add error and in-progress cases:

```php
$now = strtotime('2026-07-29 12:00:00');
$station = [
    'manifest_fetched_at' => '2026-07-29 11:57:30',
    'status_fetched_at' => '2026-07-29 11:57:30',
    'last_error' => '',
];
hub_test_assert(hub_cluster_station_is_fresh($station, $now), '150-second station snapshot must remain fresh');
hub_test_assert(hub_cluster_station_connection_state($station, $now) === 'online', 'fresh error-free station must be online');

$station['status_fetched_at'] = '2026-07-29 11:57:29';
hub_test_assert(!hub_cluster_station_is_fresh($station, $now), '151-second station snapshot must be stale');
hub_test_assert(hub_cluster_station_connection_state($station, $now) === 'offline', 'stale station must be offline');

$station['status_fetched_at'] = '2026-07-29 11:59:55';
$station['last_error'] = 'status_fetch_failed';
hub_test_assert(hub_cluster_station_connection_state($station, $now) === 'offline', 'failed refresh must be offline immediately');

$station['last_error'] = 'refreshing';
hub_test_assert(hub_cluster_station_connection_state($station, $now) === 'online', 'a fresh station being refreshed must not flash offline');
```

Also update the Router operator statement in `README.md:400` from `90 秒內` to `150 秒內` so it matches routing behavior.

- [ ] **Step 2: Run the control-plane suite and verify RED**

Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane`

Expected: failure because `hub_cluster_station_connection_state()` does not exist and the fresh threshold is still 90 seconds.

- [ ] **Step 3: Implement the minimal shared state**

Change only the existing freshness limit and add the helper next to it:

```php
function hub_cluster_station_is_fresh(array $station, ?int $now = null): bool
{
    $now ??= time();
    foreach (['manifest_fetched_at', 'status_fetched_at'] as $field) {
        $fetchedAt = strtotime((string)($station[$field] ?? ''));
        if ($fetchedAt === false || $fetchedAt > $now || ($now - $fetchedAt) > 150) {
            return false;
        }
    }

    return true;
}

function hub_cluster_station_connection_state(array $station, ?int $now = null): string
{
    $error = trim((string)($station['last_error'] ?? ''));

    return ($error === '' || $error === 'refreshing') && hub_cluster_station_is_fresh($station, $now)
        ? 'online'
        : 'offline';
}
```

Include `'connection_state' => hub_cluster_station_connection_state($station)` in `hub_cluster_station_dashboard_rows()` so all admin consumers read one authoritative value.

- [ ] **Step 4: Run the control-plane suite and verify GREEN**

Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane`

Expected: `failures=0`; dispatch eligibility and the dashboard now share the same 150-second definition of fresh.

- [ ] **Step 5: Commit the connection-state change**

```bash
git add README.md app/cluster_router.php tests/test_cluster_router.php
git commit -m "feat: expose cluster station connectivity"
```

### Task 3: Render accessible green/red indicators in both admin views

**Files:**
- Modify: `tests/test_admin_dashboard.php:52-75,126-150`
- Modify: `tests/test_cluster_admin.php:3-19`
- Modify: `app/admin_dashboard.php:139-246`
- Modify: `admin/index.php:79-93,143-154`
- Modify: `admin/cluster.php:302`
- Modify: `assets/css/admin-dashboard.css:1-42`
- Modify: `assets/css/admin-base.css:409-414`

- [ ] **Step 1: Write failing model and markup tests**

Extend the Dashboard model test with the expected state and extend the source-contract tests:

```php
hub_test_assert(
    ($model['station_tabs'][0]['connection_state'] ?? '') === 'offline',
    'station tabs must expose the shared connection state'
);
```

```php
foreach (['station-tab__status', 'connection_state', '可連線', '無法連線'] as $needle) {
    hub_test_assert(str_contains($page, $needle), 'dashboard station connectivity markup missing ' . $needle);
}
```

In `tests/test_cluster_admin.php`, add a source contract for the reused state and label:

```php
foreach (['connection_state', 'station-connection', '可連線', '無法連線'] as $needle) {
    hub_test_assert(str_contains($page, $needle), 'cluster admin station connectivity markup missing ' . $needle);
}
```

- [ ] **Step 2: Run the admin-ui suite and verify RED**

Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=admin-ui`

Expected: failures identify the missing Dashboard tab state, connection indicator markup, or styles. The Cluster-management source contract also runs in both suites.

- [ ] **Step 3: Thread the state into the model and render it**

In `hub_admin_dashboard_station_summary()`, carry the already-derived value:

```php
'connection_state' => (string)($station['connection_state'] ?? 'offline'),
```

In the station-tab mapping, keep the existing label/key and add the state:

```php
'connection_state' => (string)($station['connection_state'] ?? 'offline'),
```

In `admin/index.php`, render one dot per tab plus visually-hidden state text, and a visible state badge beside the active station title:

```php
<?php $online = ($station['connection_state'] ?? 'offline') === 'online'; ?>
<span class="station-tab__status station-tab__status--<?= $online ? 'online' : 'offline' ?>" aria-hidden="true"></span>
<span><?= hub_h($station['label']) ?></span>
<span class="sr-only">：<?= hub_h($online ? __('可連線') : __('無法連線')) ?></span>
```

Replace the existing `$statusTone` conditional in `admin/index.php` with this complete branch. It keeps a stale aggregate/router station red and prevents a fresh `refreshing` lock from flashing red:

```php
$connectionState = (string)($summary['connection_state'] ?? 'offline');
if (!$hasSummary) {
    $statusTone = 'warn';
    $statusLabel = __('尚無站台');
} elseif (empty($summary['enabled'])) {
    $statusTone = 'danger';
    $statusLabel = __('站台離線');
    $statusMessage = __('選取的站台目前已停用。');
} elseif ($isStationDashboard && $connectionState === 'offline') {
    $statusTone = 'danger';
    $statusLabel = __('無法連線');
    $statusMessage = (string)($summary['error'] ?? '') === 'refreshing'
        ? ''
        : (string)($summary['error'] ?? __('子節點快照已超過 150 秒。'));
} elseif ((string)($summary['error'] ?? '') !== '' && (string)($summary['error'] ?? '') !== 'refreshing') {
    $statusTone = 'danger';
    $statusLabel = __('站台錯誤');
    $statusMessage = (string)$summary['error'];
} elseif (!$isStationDashboard && !$snapshotAvailable) {
    $statusTone = 'warn';
    $statusLabel = __('尚無監測資料');
    $statusMessage = __('主機監測快照尚未建立。');
} elseif (!$isStationDashboard && empty($summary['fresh'])) {
    $statusTone = 'warn';
    $statusLabel = __('資料已過期');
    $statusMessage = __('監測快照已超過 90 秒。');
} elseif (!$gpuAvailable) {
    $statusTone = 'warn';
    $statusLabel = __('GPU 不可用');
    $statusMessage = (string)($gpu['reason'] ?? __('目前沒有可用的 GPU 監測資料。'));
}
```

Use the same `$summary['connection_state']` mapping in the active header and in each `admin/cluster.php` child-station card, with `station-connection--online` or `station-connection--offline` and visible `可連線` / `無法連線` text. Do not output `last_error` as the lamp label; the existing detail view remains the source for its safe error code.

Add only the shared dashboard CSS:

```css
.station-tab__status {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--danger);
}
.station-tab__status--online { background: var(--success); }
```

```css
.station-connection {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  color: var(--danger);
  font-size: var(--fs-sm);
  font-weight: 700;
}
.station-connection::before {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: currentColor;
  content: '';
}
.station-connection--online { color: var(--success); }
```

Put `.station-connection` and its green/red variants in `assets/css/admin-base.css`, because `admin/cluster.php` does not load `admin-dashboard.css`. Leave only `.station-tab__status` in `assets/css/admin-dashboard.css`.

- [ ] **Step 4: Run the control-plane suite and lint changed PHP files**

Run:

```bash
php -l app/cluster_router.php
php -l app/admin_dashboard.php
php -l admin/index.php
php -l admin/cluster.php
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=admin-ui
```

Expected: every lint command reports `No syntax errors detected`; both suites end with `failures=0`.

- [ ] **Step 5: Commit the UI change**

```bash
git add app/admin_dashboard.php admin/index.php admin/cluster.php assets/css/admin-base.css assets/css/admin-dashboard.css tests/test_admin_dashboard.php tests/test_cluster_admin.php
git commit -m "feat: show cluster station connectivity"
```

### Task 4: Final verification and handoff

**Files:**
- Verify: `app/cluster_router.php`
- Verify: `app/admin_dashboard.php`
- Verify: `admin/index.php`
- Verify: `admin/cluster.php`
- Verify: `assets/css/admin-dashboard.css`
- Verify: `tests/test_cluster_router.php`
- Verify: `tests/test_admin_dashboard.php`
- Verify: `tests/test_cluster_admin.php`

- [ ] **Step 1: Inspect the final diff and ensure no unrelated files are staged**

Run:

```bash
git diff --check origin/main..HEAD
git status -sb
```

Expected: no whitespace errors; do not stage the pre-existing `docs/superpowers/specs/2026-07-29-web-screenshot-field-intel-draft.md`.

- [ ] **Step 2: Run final fresh verification**

Run:

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=admin-ui
```

Expected: both suite outputs end in `failures=0`; report the actual test and skipped counts.

- [ ] **Step 3: Hand off without publishing automatically**

Report the commits, the changed behavior, and final test output. Push only when the user explicitly asks to publish the Cluster feature.
