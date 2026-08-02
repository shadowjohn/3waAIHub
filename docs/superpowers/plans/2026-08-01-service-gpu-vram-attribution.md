# Service GPU VRAM Attribution Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show current, actually measured GPU VRAM per registered service in the local and child-station Dashboard service table.

**Architecture:** The existing one-minute host metric collector will measure a running service only when `nvidia-smi` GPU PIDs can be matched to that service's Docker container PIDs. Native Linux runs the commands directly; Windows runs both commands inside the service's configured WSL2 distro. The resulting compact `service_gpu` rows stay in the existing host snapshot and Cluster status payload, then the Dashboard merges them into its existing service rows.

**Tech Stack:** PHP 8, SQLite host metric snapshots, Docker CLI, `nvidia-smi`, existing WSL runtime adapter, Cluster status relay, PHP i18n `__()`.

---

## File Structure

- `app/docker_runner.php`: adds one safe command adapter that runs inspection commands in the same native Linux or WSL2 service runtime.
- `app/host_metrics.php`: collects bounded PID/VRAM measurements and stores `service_gpu` in the existing host snapshot.
- `app/cluster_router.php`: includes compact service telemetry in status responses and validates it at the router trust boundary.
- `app/admin_dashboard.php`: merges measured telemetry into local and selected-child service rows by `service_key` or `mode`.
- `admin/index.php`: adds the i18n-aware `實際 VRAM` table column.
- `i18n/seed.json`: adds only missing English strings.
- `tests/test_environment_probe.php`: covers native and WSL command routing plus exact PID/VRAM measurement rules.
- `tests/test_cluster_router.php`: covers status relay validation and child Dashboard input.
- `tests/test_admin_dashboard.php`: covers local/child merging and table contracts.

### Task 1: Measure only attributable service GPU processes

**Files:**
- Modify: `app/docker_runner.php`
- Modify: `app/host_metrics.php`
- Test: `tests/test_environment_probe.php`

- [ ] **Step 1: Write failing native and WSL measurement tests**

Add one Linux fixture service and one Windows WSL2 fixture service. The runner returns two GPU processes, Docker container IDs, and container process lists. Assert that only matching PIDs are summed, a successful empty match is `0 MB`, and a malformed GPU row produces no measurement.

```php
$services = [[
    'service_key' => 'ocr-gpu',
    'mode' => 'ocr',
    'compose_project' => '3waaihub_ocr_gpu',
    'runtime_status' => 'running',
    'install_status' => 'installed',
]];
$rows = hub_collect_service_gpu_metrics($services, $runner);

hub_test_assert(
    $rows === [[
        'service_key' => 'ocr-gpu',
        'mode' => 'ocr',
        'vram_used_mb' => 1536,
        'measured' => true,
    ]],
    'only GPU PIDs inside the registered service container may be summed'
);
```

For the WSL fixture, assert the runner receives the `powershell.exe` command built by `hub_wsl_script_command()` and that its payload names the configured distro. A native Windows service without a supported WSL runtime must return `[]`.

- [ ] **Step 2: Run the focused suite and verify it fails**

Run:

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane
```

Expected: the new collector symbol is undefined or the exact-attribution assertion fails. The suite may still end with the already-known voice-profile temporary-directory teardown failure; do not change that unrelated cleanup in this feature.

- [ ] **Step 3: Add one runtime-aware inspection command adapter**

In `app/docker_runner.php`, extend `hub_wsl_service_runtime()` with its existing optional platform/profile test inputs, then add `hub_service_runtime_inspection_command(array $service, array $command, ?string $platform = null, ?array $profile = null): ?array` beside the existing WSL helpers. It returns the native command array for a supported Linux Docker service, wraps the same argv with `hub_wsl_shell_literal()` and `hub_wsl_script_command()` for a supported WSL2 service, and returns `null` for any unsupported target.

```php
function hub_service_runtime_inspection_command(
    array $service,
    array $command,
    ?string $platform = null,
    ?array $profile = null
): ?array
{
    if (hub_service_uses_wsl_runtime($service, $platform, $profile)) {
        $runtime = hub_wsl_service_runtime($service, $platform, $profile);
        if ($runtime === null) {
            return null;
        }
        $script = 'exec ' . implode(' ', array_map('hub_wsl_shell_literal', $command));
        return hub_wsl_script_command($runtime, $script);
    }

    $resolution = hub_service_runtime_resolution($service, $platform, $profile);
    return !empty($resolution['supported']) && ($resolution['target'] ?? '') === 'linux-docker'
        ? $command
        : null;
}
```

Do not run a Windows-host `nvidia-smi` or Docker command for WSL services; the adapter is the single runtime boundary.

- [ ] **Step 4: Add the minimal exact-attribution collector**

In `app/host_metrics.php`, add `hub_collect_service_gpu_metrics(array $services, ?callable $runner = null, ?string $platform = null, ?array $profile = null): array`. Keep only installed, running services with valid `service_key`, `mode`, and `compose_project`. Pass the optional platform/profile values only to the inspection adapter so WSL routing is unit-testable; production uses the defaults. For each supported runtime command context:

```php
$gpuCommand = [
    'nvidia-smi',
    '--query-compute-apps=pid,used_memory',
    '--format=csv,noheader,nounits',
];
$containerCommand = [
    'docker', 'ps', '-q',
    '--filter', 'label=com.docker.compose.project=' . $service['compose_project'],
];
```

For every returned container ID, run `docker top <id> -eo pid` through the same adapter. Accept a GPU row only when `pid` is a positive integer and `used_memory` is an integer from `0` through `1_000_000_000`. Accept a container PID only when it is a positive integer. A successful GPU query plus successful PID inspections with no matching GPU PID returns this exact row:

```php
[
    'service_key' => $serviceKey,
    'mode' => $mode,
    'vram_used_mb' => 0,
    'measured' => true,
]
```

If any required command fails, any PID output is malformed, or no running container is found, omit that service. Do not persist PIDs, container IDs, stderr, or command output.

- [ ] **Step 5: Attach collector output to the existing host snapshot**

In `hub_collect_host_metrics(PDO $db)`, reuse the service list already available from the database and add one key only:

```php
'service_gpu' => hub_collect_service_gpu_metrics(hub_list_services($db)),
```

No new table, scheduler, or live Dashboard command is permitted.

- [ ] **Step 6: Re-run tests and commit**

Run:

```bash
php -l app/docker_runner.php
php -l app/host_metrics.php
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane
```

Expected: all new attribution tests pass; if the known voice-profile teardown remains, record it as unchanged.

```bash
git add app/docker_runner.php app/host_metrics.php tests/test_environment_probe.php
git commit -m "feat: collect attributable service GPU metrics"
```

### Task 2: Relay compact service GPU telemetry through Cluster

**Files:**
- Modify: `app/cluster_router.php`
- Test: `tests/test_cluster_router.php`

- [ ] **Step 1: Write failing status and compacting tests**

Seed a latest host metric snapshot with one valid `service_gpu` row, call `hub_cluster_status_payload()`, and assert it contains the same four public fields. Exercise `hub_cluster_compact_status_snapshot()` with a malformed `service_key`, a duplicate `service_key` or `mode`, negative memory, oversized memory, and `measured => false`; each must reject the status snapshot. Assert no PID or container ID survives a valid compact snapshot.

```php
hub_test_assert(
    ($payload['service_gpu'] ?? []) === [[
        'service_key' => 'ocr-gpu',
        'mode' => 'ocr',
        'vram_used_mb' => 1536,
        'measured' => true,
    ]],
    'cluster status must relay only compact measured service GPU telemetry'
);
```

- [ ] **Step 2: Run the control-plane suite and verify it fails**

Run:

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane
```

Expected: `service_gpu` is absent from the status payload and status compaction has no validation for it.

- [ ] **Step 3: Add bounded status relay and trust-boundary validation**

In `hub_cluster_status_payload()`, read `service_gpu` only from `hub_latest_host_metric_snapshot($db)['data']`; do not invoke Docker or `nvidia-smi` in the HTTP request. Add `hub_cluster_compact_service_gpu_snapshot(array $rows): ?array` beside `hub_cluster_compact_gpu_snapshot()`.

Each row must contain exactly valid scalar values:

```php
[
    'service_key' => '/\A[a-z0-9][a-z0-9_-]{0,63}\z/',
    'mode' => '/\A[a-zA-Z0-9_-]{1,64}\z/',
    'vram_used_mb' => 0..1_000_000_000,
    'measured' => true,
]
```

Cap the list at 256 rows and reject duplicate `service_key` or `mode` values. `hub_cluster_compact_status_snapshot()` must accept absent `service_gpu` for older nodes, but reject it when present and invalid. It stores only the compact array in `status_json`.

- [ ] **Step 4: Expose compact telemetry to child Dashboard rows**

In `hub_cluster_station_dashboard_rows()`, pass the compact status array through as `service_gpu` on the station row:

```php
'service_gpu' => is_array($status['service_gpu'] ?? null) ? $status['service_gpu'] : [],
```

Do not mutate child manifest contracts or public API documents.

- [ ] **Step 5: Re-run tests and commit**

Run:

```bash
php -l app/cluster_router.php
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane
```

Expected: valid child telemetry relays, old child status remains compatible, and malformed telemetry rejects the received status.

```bash
git add app/cluster_router.php tests/test_cluster_router.php
git commit -m "feat: relay service GPU metrics through cluster status"
```

### Task 3: Merge measurements into Dashboard service rows

**Files:**
- Modify: `app/admin_dashboard.php`
- Test: `tests/test_admin_dashboard.php`

- [ ] **Step 1: Write failing local and child model tests**

Save a local host snapshot containing two `service_gpu` rows and assert the Dashboard attaches the numeric measurement to the matching local `service_key`, including an explicit measured zero. Seed a child station with manifest services plus compact status rows and assert its matching `mode` receives the value. Unknown, absent, or `measured !== true` rows must not create a numeric field.

```php
hub_test_assert(
    ($model['summary']['services'][0]['gpu_vram_measured'] ?? false) === true
        && ($model['summary']['services'][0]['gpu_vram_used_mb'] ?? null) === 0,
    'a successful measurement with no GPU process must stay a measured zero'
);
```

- [ ] **Step 2: Run the admin UI suite and verify it fails**

Run:

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=admin-ui
```

Expected: service rows do not yet have `gpu_vram_measured` or `gpu_vram_used_mb`.

- [ ] **Step 3: Add one Dashboard-only merge helper**

In `app/admin_dashboard.php`, add `hub_admin_dashboard_services_with_gpu(array $services, array $measurements): array`. Index valid `measured === true` rows by both `service_key` and `mode`; never use a row with an invalid integer range. For every existing Dashboard service row, add exactly these fields only when the matching measurement is valid:

```php
$service['gpu_vram_measured'] = true;
$service['gpu_vram_used_mb'] = $measurement['vram_used_mb'];
```

Use the helper in `hub_admin_dashboard_local_summary()` with the latest metric snapshot's `service_gpu`, and in `hub_admin_dashboard_station_summary()` with the station row's `service_gpu`. Local matching uses `service_key`; child matching uses `mode`, because paired-child service contracts intentionally expose modes rather than private container identity.

- [ ] **Step 4: Preserve unknown values without fallback arithmetic**

Do not derive per-service VRAM from total GPU memory, free memory, Docker state, Pack requirements, or active leases. A service with no merged field remains unknown even if the selected station has a healthy GPU.

- [ ] **Step 5: Re-run tests and commit**

Run:

```bash
php -l app/admin_dashboard.php
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=admin-ui
```

Expected: local and child models preserve measured values, zeroes, and unknowns exactly.

```bash
git add app/admin_dashboard.php tests/test_admin_dashboard.php
git commit -m "feat: expose measured service VRAM on dashboard models"
```

### Task 4: Render the measured VRAM column

**Files:**
- Modify: `admin/index.php`
- Modify: `i18n/seed.json`
- Test: `tests/test_admin_dashboard.php`

- [ ] **Step 1: Write failing page contract tests**

Extend the existing Dashboard static-page assertions to require an `__('實際 VRAM')` table heading and the `gpu_vram_measured` conditional. Require the visible unknown state to use `__('尚未取得')`, not a fabricated `0 MB`.

- [ ] **Step 2: Run the admin UI suite and verify it fails**

Run:

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=admin-ui
```

Expected: the service table has only three headings and no measurement renderer.

- [ ] **Step 3: Add one table column with no new CSS or chart**

In `admin/index.php`, insert the column after `Mode`. Keep the existing table layout and format a value only when the model explicitly marks it measured:

```php
<th><?= hub_h(__('實際 VRAM')) ?></th>
```

```php
<td>
    <?php if (!empty($service['gpu_vram_measured']) && is_int($service['gpu_vram_used_mb'] ?? null)): ?>
        <?= number_format($service['gpu_vram_used_mb']) ?> MB
    <?php else: ?>
        <?= hub_h(__('尚未取得')) ?>
    <?php endif; ?>
</td>
```

Do not add a poll button, a history chart, a tooltip framework, or a new asset.

- [ ] **Step 4: Add only missing i18n seed entries**

Add English seed translations for the new visible strings if they do not already exist:

```json
"實際 VRAM": "Measured VRAM",
"尚未取得": "Not available"
```

All new visible PHP text must remain wrapped in `__()`.

- [ ] **Step 5: Verify and commit**

Run:

```bash
php -l admin/index.php
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=admin-ui
```

Expected: the fourth column shows `0 MB` only for a measured zero and `尚未取得` for all unknown states.

```bash
git add admin/index.php i18n/seed.json tests/test_admin_dashboard.php
git commit -m "feat: show measured service VRAM on dashboard"
```

### Task 5: Final verification

**Files:**
- Verify only: `app/docker_runner.php`, `app/host_metrics.php`, `app/cluster_router.php`, `app/admin_dashboard.php`, `admin/index.php`, `i18n/seed.json`

- [ ] **Step 1: Confirm no sensitive fields enter snapshots**

Run:

```bash
rg -n "service_gpu|container_id|containerId|\bpid\b" app/host_metrics.php app/cluster_router.php
```

Expected: `pid` and container IDs are parser-local only; persisted and relayed rows contain only `service_key`, `mode`, `vram_used_mb`, and `measured`.

- [ ] **Step 2: Run regression suites and static checks**

Run:

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=admin-ui
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane
git diff --check HEAD~4..HEAD
```

Expected: `admin-ui` passes. All new control-plane assertions pass; if the existing voice-profile temporary-directory teardown remains the sole suite failure, report it unchanged rather than expanding this feature's scope.

- [ ] **Step 3: Smoke-check native and WSL paths without inventing data**

Run the existing host metric collector once on a Linux service host and once on a Windows WSL2 service host:

```bash
php scripts/collect_host_metrics.php --force
```

Expected: Dashboard shows a numeric value only for an attributable running container; it shows `尚未取得` on unsupported or unverifiable runtime paths.

- [ ] **Step 4: Confirm release scope**

Run:

```bash
git status --short
git log --oneline -4
```

Expected: only the service-GPU feature commits are ready. Do not add pre-existing untracked documentation drafts or push without explicit user approval.
