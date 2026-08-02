# Cluster GPU History Implementation Plan
> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show current GPU utilization/temperature and 24-hour temperature and VRAM-used charts for both local and paired Cluster stations.

**Architecture:** The local Hub already saves `host_metric_snapshots` each minute, so the Dashboard queries that table directly. A Router saves the already-validated compact GPU part of each successful child `cluster_status` refresh in a timestamp-deduplicated table; Dashboard code turns either source into the same Chart.js rows.

**Tech Stack:** PHP 8, SQLite, existing one-minute cron, existing Chart.js vendor bundle, i18n `__()` seed catalog.

---

## File Structure

- `app/db.php`: creates the child GPU snapshot table and indexed cascading station relationship.
- `app/cluster_router.php`: persists one bounded compact GPU sample after successful child status refreshes.
- `app/admin_dashboard.php`: reads the last 24 hours of local or child GPU samples and preserves child current utilization/temperature.
- `admin/index.php`: supplies chart rows and renders two Dashboard chart surfaces.
- `assets/js/admin-dashboard.js`: draws line charts with the existing Chart.js bundle.
- `i18n/seed.json`: English translations for new labels.
- `tests/test_cluster_router.php`, `tests/test_admin_dashboard.php`: persistence, 24-hour bounds, and Dashboard contracts.

### Task 1: Persist bounded child GPU history

**Files:**
- Modify: `app/db.php`
- Modify: `app/cluster_router.php`
- Test: `tests/test_cluster_router.php`

- [ ] **Step 1: Write the failing refresh-history test**

Add a refresh fixture test for a paired station with a valid compact GPU status. Refresh twice with the same verified timestamp, seed one old record, then assert the current record is deduplicated, only compact GPU fields are stored, and old data is removed.

```php
$db->exec("INSERT INTO cluster_gpu_metric_snapshots (station_id, sampled_at, gpu_json)
    VALUES ({$stationId}, '2020-01-01 00:00:00', '{\"available\":true}')");

hub_cluster_refresh_station($db, $station, $fetcher);
hub_cluster_refresh_station($db, $station, $fetcher);

hub_test_assert(
    (int)$db->query("SELECT COUNT(*) FROM cluster_gpu_metric_snapshots WHERE station_id = {$stationId}")->fetchColumn() === 1,
    'a repeated child status timestamp must not create duplicate GPU samples'
);
```

- [ ] **Step 2: Run the focused test suite and verify it fails**

Run: `php scripts/run_tests.php --suite=control-plane`

Expected: the new assertion fails because no history table or sample exists.

- [ ] **Step 3: Create the minimal schema**

In `hub_migrate()`, create one table and one index. Do not store tokens, URLs, manifests, or services.

```sql
CREATE TABLE IF NOT EXISTS cluster_gpu_metric_snapshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    station_id INTEGER NOT NULL,
    sampled_at TEXT NOT NULL,
    gpu_json TEXT NOT NULL,
    UNIQUE(station_id, sampled_at),
    FOREIGN KEY(station_id) REFERENCES cluster_stations(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_cluster_gpu_metric_snapshots_station_time
    ON cluster_gpu_metric_snapshots(station_id, sampled_at DESC);
```

- [ ] **Step 4: Persist samples only after a successful status refresh**

Add `hub_cluster_store_station_gpu_metric_snapshot(PDO $db, int $stationId, array $statusSnapshot): void` beside `hub_cluster_store_station_status()`. Reuse `hub_cluster_compact_gpu_snapshot()`, use `INSERT OR IGNORE` for the verified `snapshot_at`, then delete samples older than 24 hours. Call it immediately after `hub_cluster_store_station_status()`; failed status or manifest refreshes do not write history.

```php
$gpu = hub_cluster_compact_gpu_snapshot((array)($statusSnapshot['gpu'] ?? []));
$sampledAt = (string)($statusSnapshot['snapshot_at'] ?? '');
if ($sampledAt === '' || $gpu === []) {
    return;
}

$insert->execute([
    ':station_id' => $stationId,
    ':sampled_at' => $sampledAt,
    ':gpu_json' => json_encode($gpu, JSON_THROW_ON_ERROR),
]);
$prune->execute([':cutoff' => date('Y-m-d H:i:s', time() - 86400)]);
```

- [ ] **Step 5: Re-run tests and commit**

Run: `php scripts/run_tests.php --suite=control-plane`

Expected: PASS, including valid-field, de-duplication, and 24-hour prune assertions.

```bash
git add app/db.php app/cluster_router.php tests/test_cluster_router.php
git commit -m "feat: retain child GPU metric history"
```

### Task 2: Normalize local and child data for the Dashboard

**Files:**
- Modify: `app/admin_dashboard.php`
- Modify: `app/cluster_router.php`
- Test: `tests/test_admin_dashboard.php`

- [ ] **Step 1: Write failing model tests**

Seed recent and expired local host snapshots, plus recent and expired child GPU rows. Assert that the model provides only recent `temperature` and `vram_used` values. Make the child fixture contain utilization and temperature, then assert those values remain in `summary.gpu`.

```php
hub_test_assert(
    $model['summary']['gpu']['util_percent'] === 73
        && $model['summary']['gpu']['temperature_c'] === 66,
    'child Dashboard must retain current compact GPU utilization and temperature'
);
hub_test_assert(
    count($model['summary']['gpu_history']['temperature']) === 1,
    'Dashboard history must exclude samples outside the 24-hour window'
);
```

- [ ] **Step 2: Run the admin UI suite and verify it fails**

Run: `php scripts/run_tests.php --suite=admin-ui`

Expected: model history and child current values do not exist yet.

- [ ] **Step 3: Add one normalizer and two readers**

In `app/admin_dashboard.php`, add:

```php
function hub_admin_dashboard_local_gpu_history(PDO $db, string $since): array
function hub_admin_dashboard_station_gpu_history(PDO $db, int $stationId, string $since): array
function hub_admin_dashboard_gpu_history_rows(iterable $samples): array
```

The local reader decodes `host_metric_snapshots.snapshot_json`; the child reader decodes `cluster_gpu_metric_snapshots.gpu_json`. Both call the same normalizer and return no row for a missing/non-numeric metric:

```php
[
    'temperature' => [['label' => '13:05', 'value' => 66.0]],
    'vram_used' => [['label' => '13:05', 'value' => 9892.0]],
]
```

Attach the result as `summary.gpu_history` in `hub_admin_dashboard_model()`. Dashboard reads remain read-only.

- [ ] **Step 4: Preserve current child utilization and temperature**

In `hub_cluster_station_dashboard_rows()`, pass the existing compact `status['gpu']` into the station row. In `hub_admin_dashboard_station_summary()`, preserve it while deriving the current memory values:

```php
$gpu = is_array($station['gpu'] ?? null) ? $station['gpu'] : [];
$summaryGpu = array_replace($gpu, [
    'available' => !empty($gpu['available']) && $totalVram > 0,
    'memory_total_mb' => $totalVram,
    'memory_free_mb' => $freeVram,
    'memory_used_mb' => max(0, $totalVram - $freeVram),
]);
```

- [ ] **Step 5: Re-run tests and commit**

Run: `php scripts/run_tests.php --suite=admin-ui`

Expected: PASS, including local/child 24-hour filtering and current-card values.

```bash
git add app/admin_dashboard.php app/cluster_router.php tests/test_admin_dashboard.php
git commit -m "feat: expose GPU history on dashboard models"
```

### Task 3: Render accessible 24-hour charts

**Files:**
- Modify: `admin/index.php`
- Modify: `assets/js/admin-dashboard.js`
- Modify: `i18n/seed.json`
- Test: `tests/test_admin_dashboard.php`

- [ ] **Step 1: Write failing page and JS contract tests**

Extend the existing Dashboard page test to require `gpuTemperatureChart`, `gpuVramHistoryChart`, `gpuTemperatureHistory`, `gpuVramHistory`, and line-chart creation. Require `__('GPU 溫度（24 小時）')` and `__('GPU VRAM 使用量（24 小時）')`.

- [ ] **Step 2: Run the admin UI suite and verify it fails**

Run: `php scripts/run_tests.php --suite=admin-ui`

Expected: missing chart IDs and history keys.

- [ ] **Step 3: Render two ordinary Dashboard cards**

In `admin/index.php`, add the model data to `$chartData` and render a two-column band below the current summary cards. Use a compact empty state when a series is empty, never a zero line.

```php
'gpuTemperatureHistory' => $gpuHistory['temperature'] ?? [],
'gpuVramHistory' => $gpuHistory['vram_used'] ?? [],
```

```php
<div class="grid grid--2">
    <section class="card card--fill">
        <div class="card__head"><h2 class="card__title"><?= hub_h(__('GPU 溫度（24 小時）')) ?></h2></div>
        <div class="chart"><canvas id="gpuTemperatureChart" role="img"></canvas></div>
    </section>
    <section class="card card--fill">
        <div class="card__head"><h2 class="card__title"><?= hub_h(__('GPU VRAM 使用量（24 小時）')) ?></h2></div>
        <div class="chart"><canvas id="gpuVramHistoryChart" role="img"></canvas></div>
    </section>
</div>
```

- [ ] **Step 4: Extend the existing Chart.js helper for line charts**

Keep `create()` as the only chart constructor. For `line`, use a two-pixel border, no fill, small points, and normal numeric axes. Add:

```js
create('gpuTemperatureChart', 'line', rows('gpuTemperatureHistory'), palette.amber, lineOptions('°C'));
create('gpuVramHistoryChart', 'line', rows('gpuVramHistory'), palette.blue, lineOptions(' MB'));
```

An empty source returns before a chart is created.

- [ ] **Step 5: Add i18n entries, verify, and commit**

Add English seed values for both titles and the empty-history caption; all new visible text uses `__()`.

Run:

```bash
php scripts/run_tests.php --suite=admin-ui
php scripts/run_tests.php --suite=control-plane
```

Expected: both suites PASS. Use the existing local Dashboard page at desktop and narrow width to verify readable axes, non-overlapping labels, and a clear empty state.

```bash
git add admin/index.php assets/js/admin-dashboard.js i18n/seed.json tests/test_admin_dashboard.php
git commit -m "feat: chart 24 hour cluster GPU history"
```

### Task 4: Final verification and handoff

**Files:**
- Verify only: `app/db.php`, `app/cluster_router.php`, `app/admin_dashboard.php`, `admin/index.php`, `assets/js/admin-dashboard.js`, `i18n/seed.json`

- [ ] **Step 1: Confirm database shape**

Run:

```bash
php scripts/init_db.php
sqlite3 data/3waaihub.sqlite ".schema cluster_gpu_metric_snapshots"
```

Expected: the table has a station foreign key, unique station/timestamp pair, and no credentials or service payload fields.

- [ ] **Step 2: Run final regressions**

Run:

```bash
php scripts/run_tests.php --suite=control-plane
php scripts/run_tests.php --suite=admin-ui
git diff --check HEAD~3..HEAD
```

Expected: both suites pass without whitespace errors.

- [ ] **Step 3: Confirm release scope**

Run:

```bash
git status --short
git log --oneline -3
```

Expected: only the GPU-history commits are ready. Do not include pre-existing untracked documentation drafts; push only after explicit user request.
