# Pack-job Runtime SQLite A+ Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 量出 pack-job/runtime 各 SQLite 寫入 action 的 contention，並把 60 秒 lease 的 heartbeat renewal write 從每 10 秒一次降為只在剩餘 30 秒內寫入。

**Architecture:** 在既有 claim／heartbeat／finish／recovery transaction 邊界就地計時，DB 完全結束後 append 每日 NDJSON。Heartbeat 保留 10 秒 health tick 與 cancellation/fence read，execution-local PHP array 以 reference 保存最新 expiry 與 skipped count；不新增通用 transaction wrapper、不改 claim selection，也不新增依賴。

**Tech Stack:** PHP 8、PDO SQLite、NDJSON、既有 `scripts/run_tests.php` 測試 runner、既有 retention cron。

---

## File map

- Create `app/runtime_telemetry.php`: 唯一新增的 production helper；負責 event 驗證／append、每日檔路徑、串流 summary、7 日清理。
- Modify `app/bootstrap.php:48-60`: 在 runtime worker 前載入 telemetry helper。
- Modify `app/db.php:15-32`: 讓 `hub_sqlite_begin_immediate()` 選擇性回報 wait/retry stats，原 retry policy 不變。
- Modify `app/task_queue.php:806-860, 2126-2175, 4752-4910`: task claim、retention、finish transaction 埋點。
- Modify `app/runtime_worker.php:56-116, 197-250, 630-634, 928-947`: GPU claim、CPU/GPU heartbeat 的精確 expiry 與 timing stats。
- Modify `app/pack_job_runner.php:19-90, 1008-1015, 2570-2820, 2880-3270`: runtime claim、shared heartbeat state、heartbeat/recovery telemetry 與 terminal state 傳遞。
- Create `scripts/runtime_telemetry_summary.php`: 嚴格的 `--since=` CLI 入口。
- Modify `scripts/run_tests.php:431-445` and create `tests/suites/runtime-telemetry.php`: 一個 focused suite。
- Create `tests/test_runtime_telemetry.php`: 本功能唯一新測試檔；fixture、emitter、summary、retention、claim、heartbeat、finish、recovery checks 都放這裡。

## Task 1: Add the fail-safe daily NDJSON emitter

**Files:**

- Create: `app/runtime_telemetry.php`
- Modify: `app/bootstrap.php:48-60`
- Create: `tests/test_runtime_telemetry.php`
- Create: `tests/suites/runtime-telemetry.php`
- Modify: `scripts/run_tests.php:438`

- [ ] **Step 1: Register a focused test suite and write the failing emitter checks**

Add `runtime-telemetry` to the static allowlist in `hub_test_suite_files()` and create this manifest:

```php
<?php
declare(strict_types=1);

return [
    __DIR__ . '/../test_runtime_telemetry.php',
];
```

Start `tests/test_runtime_telemetry.php` with one helper that reads today's file line by line and two checks:

```php
<?php
declare(strict_types=1);

function hub_test_runtime_telemetry_events(): array
{
    $path = HUB_LOG_DIR . '/runtime-telemetry-' . date('Y-m-d') . '.ndjson';
    if (!is_file($path)) {
        return [];
    }
    $events = [];
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Cannot read runtime telemetry fixture.');
    }
    try {
        while (($line = fgets($handle)) !== false) {
            $event = json_decode($line, true);
            if (is_array($event)) {
                $events[] = $event;
            }
        }
    } finally {
        fclose($handle);
    }
    return $events;
}

hub_test('runtime telemetry appends one validated NDJSON line', function (): void {
    $before = count(hub_test_runtime_telemetry_events());
    hub_test_assert(hub_runtime_telemetry_emit([
        'action' => 'heartbeat',
        'variant' => 'cpu',
        'outcome' => 'committed',
        'tx_mode' => 'autocommit',
        'tx_begin_at' => hub_runtime_telemetry_timestamp(),
        'tx_commit_at' => hub_runtime_telemetry_timestamp(),
        'pre_tx_ms' => 0.1,
        'lock_wait_ms' => 0.2,
        'lock_wait_kind' => 'first_write_upper_bound',
        'tx_ms' => 0.2,
        'post_tx_ms' => 0.1,
        'total_ms' => 0.4,
        'retry_count' => 0,
        'skipped_ticks' => 2,
    ]), 'valid telemetry event must append');
    $events = hub_test_runtime_telemetry_events();
    hub_test_assert(count($events) === $before + 1, 'telemetry append must create exactly one line');
    hub_test_assert(($events[array_key_last($events)]['schema_version'] ?? null) === 1, 'telemetry schema changed');
});

hub_test('runtime telemetry failure and unknown fields are non-throwing', function (): void {
    $event = [
        'action' => 'heartbeat', 'variant' => 'cpu', 'outcome' => 'committed',
        'tx_mode' => 'autocommit', 'tx_begin_at' => null, 'tx_commit_at' => null,
        'pre_tx_ms' => 0, 'lock_wait_ms' => 0, 'lock_wait_kind' => 'none',
        'tx_ms' => 0, 'post_tx_ms' => 0, 'total_ms' => 0,
        'retry_count' => 0, 'skipped_ticks' => 0,
    ];
    hub_test_assert(!hub_runtime_telemetry_emit($event + ['token' => 'must-not-leak']), 'unknown telemetry fields must be rejected');
    hub_test_assert(!hub_runtime_telemetry_emit($event, static fn (): bool => false), 'writer failure must not throw');
});
```

- [ ] **Step 2: Run the focused suite and confirm RED**

Run:

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=runtime-telemetry
```

Expected: failure containing `Call to undefined function hub_runtime_telemetry_emit()`.

- [ ] **Step 3: Implement the minimum emitter**

In `app/runtime_telemetry.php`, define only these public primitives:

```php
<?php
declare(strict_types=1);

const HUB_RUNTIME_TELEMETRY_SCHEMA_VERSION = 1;
const HUB_RUNTIME_TELEMETRY_RETENTION_DAYS = 7;

function hub_runtime_telemetry_timestamp(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d\TH:i:s.uP');
}

function hub_runtime_telemetry_elapsed_ms(int|float $start, int|float $end): float
{
    return round(max(0, ($end - $start) / 1_000_000), 3);
}

function hub_runtime_telemetry_path(DateTimeInterface $date): string
{
    return HUB_LOG_DIR . '/runtime-telemetry-' . $date->format('Y-m-d') . '.ndjson';
}
```

`hub_runtime_telemetry_emit(array $event, ?callable $writer = null): bool` must:

1. Accept exactly the schema fields listed in the approved design; reject unknown keys before encoding.
2. Validate the fixed action/variant map, outcome allowlist, `tx_mode`, `lock_wait_kind`, non-negative finite timings, and non-negative integer counters.
3. Add `schema_version=1` and `observed_at` itself.
4. Encode with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR` and one trailing `PHP_EOL`.
5. Default to one `file_put_contents($path, $line, FILE_APPEND | LOCK_EX)` call and require the returned byte count to equal `strlen($line)`.
6. Catch every `Throwable`, call `error_log('[3waAIHub] runtime telemetry append failed')`, and return `false`; never include event data or exception text in that log.

Use the optional writer only as the existing-style test seam; its signature is `fn(string $path, string $line): int|false`.

- [ ] **Step 4: Load the helper and confirm GREEN**

Add immediately after `require_once __DIR__ . '/db.php';`:

```php
require_once __DIR__ . '/runtime_telemetry.php';
```

Run:

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=runtime-telemetry
```

Expected: summary line has `suite=runtime-telemetry` and `failures=0`.

- [ ] **Step 5: Commit**

```bash
git add app/runtime_telemetry.php app/bootstrap.php scripts/run_tests.php tests/suites/runtime-telemetry.php tests/test_runtime_telemetry.php
git commit -m "feat: add runtime NDJSON telemetry emitter"
```

## Task 2: Add the streaming summary CLI and seven-day retention

**Files:**

- Modify: `app/runtime_telemetry.php`
- Create: `scripts/runtime_telemetry_summary.php`
- Modify: `app/task_queue.php:2126-2175`
- Modify: `tests/test_runtime_telemetry.php`

- [ ] **Step 1: Write failing summary and retention checks**

Append tests that create three dated NDJSON files with two groups plus one malformed line, then assert:

```php
$summary = hub_runtime_telemetry_summary(
    new DateTimeImmutable('2026-08-13 23:00:00'),
    new DateTimeImmutable('2026-08-14 01:00:00')
);
hub_test_assert($summary['invalid_lines'] === 1, 'summary must count malformed lines');
hub_test_assert($summary['groups']['heartbeat/cpu']['count'] === 3, 'summary group count changed');
hub_test_assert($summary['groups']['heartbeat/cpu']['p50_tx'] === 2.0, 'p50 must be calculated inside its group');
hub_test_assert($summary['groups']['claim/runtime']['p95_tx'] === 20.0, 'p95 groups must not share samples');
```

Use a writer callback in the fixture to record opened paths and assert only `2026-08-13` and `2026-08-14` are requested. Add a retention check containing:

- `runtime-telemetry-2026-08-07.ndjson` (delete at now `2026-08-14`)
- `runtime-telemetry-2026-08-08.ndjson` (retain)
- `runtime-telemetry-2026-08-14.ndjson` (retain)
- `runtime-telemetry-bad.ndjson` (retain)
- a symlink named like an expired telemetry file (retain)

Assert `hub_prune_runtime_telemetry(new DateTimeImmutable('2026-08-14 12:00:00')) === 1`.

- [ ] **Step 2: Run and confirm RED**

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=runtime-telemetry
```

Expected: undefined `hub_runtime_telemetry_summary()`.

- [ ] **Step 3: Implement bounded parsing and streaming aggregation**

Add to `app/runtime_telemetry.php`:

```php
function hub_runtime_telemetry_parse_since(string $value, DateTimeImmutable $now): DateTimeImmutable
{
    if (preg_match('/\A([1-9][0-9]*) (minute|minutes|hour|hours|day|days)\z/D', $value, $match) !== 1) {
        throw new InvalidArgumentException('runtime_telemetry_since_invalid');
    }
    $seconds = (int)$match[1] * match ($match[2]) {
        'minute', 'minutes' => 60,
        'hour', 'hours' => 3600,
        'day', 'days' => 86400,
    };
    if ($seconds > HUB_RUNTIME_TELEMETRY_RETENTION_DAYS * 86400) {
        throw new InvalidArgumentException('runtime_telemetry_since_invalid');
    }
    return $now->modify('-' . $seconds . ' seconds');
}

function hub_runtime_telemetry_quantile(array $values, float $quantile): float
{
    sort($values, SORT_NUMERIC);
    return round((float)$values[max(0, (int)ceil($quantile * count($values)) - 1)], 3);
}
```

`hub_runtime_telemetry_summary($since, $until, ?callable $opener = null): array` must generate each date from `$since->setTime(0, 0)` through `$until` directly, call `hub_runtime_telemetry_path()`, and use `fgets()` for each existing file. Never call `glob()` or `scandir()`. Validate each decoded event with the same schema rules as the emitter, discard out-of-range `observed_at`, increment `invalid_lines`, and keep only the `tx_ms` sample array per `action/variant`; all other totals are incremental.

Return stable keys:

```php
[
    'invalid_lines' => 0,
    'groups' => [
        'heartbeat/cpu' => [
            'action' => 'heartbeat', 'variant' => 'cpu', 'count' => 0,
            'p50_tx' => 0.0, 'p95_tx' => 0.0, 'p99_tx' => 0.0,
            'lock_count' => 0, 'retries' => 0, 'exhausted' => 0, 'skipped' => 0,
        ],
    ],
]
```

- [ ] **Step 4: Implement exact-name retention and wire it into the existing cron**

`hub_prune_runtime_telemetry(?DateTimeImmutable $now = null): int` may use `scandir(HUB_LOG_DIR)` because it only runs in retention. It must accept only `/\Aruntime-telemetry-(\d{4}-\d{2}-\d{2})\.ndjson\z/D`, reject links, require `lstat()` regular-file mode, require `realpath(dirname($path)) === realpath(HUB_LOG_DIR)`, parse the date exactly with `!Y-m-d`, and delete only dates before `$now->setTime(0, 0)->modify('-6 days')`.

At the start of `hub_prune_retention()`, after resolving `$now`, call it once:

```php
$runtimeTelemetryPurged = hub_prune_runtime_telemetry(new DateTimeImmutable($now));
```

Add `'runtime_telemetry_files_purged' => $runtimeTelemetryPurged` to the existing report. No hot path may call the cleaner.

- [ ] **Step 5: Add the strict CLI**

Create `scripts/runtime_telemetry_summary.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
hub_cli_only();

if ($argc !== 2 || !str_starts_with($argv[1], '--since=')) {
    fwrite(STDERR, 'Usage: php scripts/runtime_telemetry_summary.php --since="1 hour"' . PHP_EOL);
    exit(64);
}

try {
    $until = new DateTimeImmutable('now');
    $since = hub_runtime_telemetry_parse_since(substr($argv[1], 8), $until);
    echo hub_runtime_telemetry_render_summary(hub_runtime_telemetry_summary($since, $until));
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, 'runtime_telemetry_since_invalid' . PHP_EOL);
    exit(64);
}
```

Render one fixed-width row per sorted `action/variant`, with columns `action variant count p50_tx p95_tx p99_tx lock>0 retries exhausted skipped`, then one `invalid_lines=N` line.

- [ ] **Step 6: Verify and commit**

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=runtime-telemetry
php scripts/runtime_telemetry_summary.php --since="1 hour"
php scripts/runtime_telemetry_summary.php --since="all time"; test $? -eq 64
git add app/runtime_telemetry.php app/task_queue.php scripts/runtime_telemetry_summary.php tests/test_runtime_telemetry.php
git commit -m "feat: summarize and retain runtime telemetry"
```

Expected: focused suite passes; valid CLI prints a header and `invalid_lines`; invalid CLI exits 64.

## Task 3: Measure BEGIN IMMEDIATE and the three claim variants

**Files:**

- Modify: `app/db.php:15-32`
- Modify: `app/task_queue.php:806-860`
- Modify: `app/pack_job_runner.php:19-90`
- Modify: `app/runtime_worker.php:56-116, 630-634`
- Modify: `tests/test_runtime_telemetry.php`

- [ ] **Step 1: Write failing helper and claim checks**

Use an isolated second PDO that holds a short writer lock, call `hub_sqlite_begin_immediate($db, $stats)`, release the lock with the existing process fixture pattern from `tests/test_pack_job_adapter.php`, and assert:

```php
hub_test_assert(($stats['retry_count'] ?? 0) >= 1, 'BEGIN IMMEDIATE retry must be counted');
hub_test_assert(($stats['lock_wait_ms'] ?? 0) > 0, 'BEGIN IMMEDIATE wait must be measured');
hub_test_assert(($stats['lock_exhausted'] ?? true) === false, 'successful retry must not be exhausted');
```

Then enqueue and claim one `demo_task` and one minimally valid `pack_job`. Assert only the pack job creates `claim/task`, `hub_pack_job_claim_runtime()` creates `claim/runtime`, and `hub_runtime_gpu_acquire_for_task()` creates `claim/gpu`. Verify no task input, token, path, or ID fields appear in emitted events.

- [ ] **Step 2: Run and confirm RED**

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=runtime-telemetry
```

Expected: by-reference stats remain empty and claim events are absent.

- [ ] **Step 3: Extend the centralized retry helper without changing policy**

Change only the signature and stats assignment:

```php
function hub_sqlite_begin_immediate(PDO $db, ?array &$stats = null): void
```

Initialize `retry_count=0`, `lock_exhausted=false`, measure from immediately before the first `BEGIN IMMEDIATE` until success/throw with `hrtime(true)`, and set `retry_count` to the number of sleeps actually taken. On the seventh locked failure set `lock_exhausted=true` before rethrowing. Keep exactly 7 attempts and `usleep(5000 * (1 << $attempt))`.

- [ ] **Step 4: Instrument queue and runtime claims at their existing boundaries**

For every action, capture monotonic action/begin/commit points in local variables, emit only after commit/rollback, and use:

- task claim: `tx_mode=deferred`, `lock_wait_kind=first_write_upper_bound`; emit only after a successful claimed row whose `task_type === 'pack_job'`.
- runtime claim: `tx_mode=immediate`, `lock_wait_kind=begin_immediate`; emit every pack-job runtime claim transaction, including committed no-match, fence-lost, failed, and lock-exhausted outcomes.
- GPU claim: add optional stats to `hub_runtime_gpu_acquire()`, but emit only from `hub_runtime_gpu_acquire_for_task()` so gateway/non-pack callers stay outside Phase 1.

Do not move `candidateFilter`, SELECT, random token generation, or any SQL. Do not emit while `$db->inTransaction()`.

- [ ] **Step 5: Verify and commit**

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=runtime-telemetry
php -l app/db.php
php -l app/task_queue.php
php -l app/runtime_worker.php
php -l app/pack_job_runner.php
git add app/db.php app/task_queue.php app/runtime_worker.php app/pack_job_runner.php tests/test_runtime_telemetry.php
git commit -m "feat: measure pack job claim contention"
```

## Task 4: Add the shared heartbeat state and cadence logic

**Files:**

- Modify: `app/pack_job_runner.php:2550-2595`
- Modify: `tests/test_runtime_telemetry.php`

- [ ] **Step 1: Write the fake-clock cadence check**

Use an initial expiry of `2026-08-14 12:01:00` and call the pure decision helper at seconds 10, 20, and 30. After the due tick, call the commit helper with `12:01:30`, then repeat at 40, 50, 60. Assert exactly four skips and two renewals in the minute. Also assert missing, malformed, and 30-second leases renew immediately.

Add a shared-state check:

```php
$state = hub_pack_job_heartbeat_state(
    ['lease_expires_at' => '2026-08-14 12:00:30'],
    null
);
$first = static function () use (&$state): int {
    return hub_pack_job_heartbeat_mark_committed($state, '2026-08-14 12:01:00');
};
$first();
hub_test_assert(!hub_pack_job_heartbeat_should_renew($state, strtotime('2026-08-14 12:00:10')), 'committed expiry must be visible through the shared reference');
```

- [ ] **Step 2: Run and confirm RED**

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=runtime-telemetry
```

Expected: undefined heartbeat-state helpers.

- [ ] **Step 3: Add four small array helpers beside `hub_pack_job_tick()`**

Use fixed threshold `30`; do not add config or a class:

```php
const HUB_PACK_JOB_HEARTBEAT_RENEW_THRESHOLD_SECONDS = 30;

function hub_pack_job_heartbeat_state(array $run, ?array $gpuLease): array
{
    return [
        'runtime_expires_at' => $run['lease_expires_at'] ?? null,
        'gpu_required' => $gpuLease !== null,
        'gpu_expires_at' => $gpuLease['lease_expires_at'] ?? null,
        'skipped_ticks' => 0,
    ];
}

function hub_pack_job_heartbeat_should_renew(array $state, int $now): bool
{
    $expiries = [$state['runtime_expires_at'] ?? null];
    if (!empty($state['gpu_required'])) {
        $expiries[] = $state['gpu_expires_at'] ?? null;
    }
    foreach ($expiries as $expiry) {
        $timestamp = is_string($expiry) ? strtotime($expiry) : false;
        if ($timestamp === false || $timestamp - $now <= HUB_PACK_JOB_HEARTBEAT_RENEW_THRESHOLD_SECONDS) {
            return true;
        }
    }
    return false;
}

function hub_pack_job_heartbeat_mark_skipped(array &$state): void
{
    $state['skipped_ticks'] = max(0, (int)($state['skipped_ticks'] ?? 0)) + 1;
}

function hub_pack_job_heartbeat_mark_committed(array &$state, string $newExpiry): int
{
    if (strtotime($newExpiry) === false) {
        throw new InvalidArgumentException('runtime_heartbeat_expiry_invalid');
    }
    $skipped = max(0, (int)($state['skipped_ticks'] ?? 0));
    $state['runtime_expires_at'] = $newExpiry;
    if (!empty($state['gpu_required'])) {
        $state['gpu_expires_at'] = $newExpiry;
    }
    $state['skipped_ticks'] = 0;
    return $skipped;
}

function hub_pack_job_heartbeat_take_skipped(?array &$state): int
{
    if ($state === null) {
        return 0;
    }
    $skipped = max(0, (int)($state['skipped_ticks'] ?? 0));
    $state['skipped_ticks'] = 0;
    return $skipped;
}
```

- [ ] **Step 4: Verify and commit**

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=runtime-telemetry
git add app/pack_job_runner.php tests/test_runtime_telemetry.php
git commit -m "feat: model pack job heartbeat renewal state"
```

## Task 5: Skip healthy heartbeat writes and preserve GPU atomicity

**Files:**

- Modify: `app/runtime_worker.php:197-250, 928-947`
- Modify: `app/pack_job_runner.php:2570-2595, 2880-3270`
- Modify: `tests/test_runtime_telemetry.php`

- [ ] **Step 1: Write failing CPU/GPU integration checks**

Add checks for:

1. A due CPU tick performs one autocommit write, writes the supplied expiry, emits `heartbeat/cpu`, consumes accumulated skips, and the immediate next tick skips DB renewal while still detecting cancellation.
2. A due GPU tick writes one exact expiry string to both `runtime_runs` and `runtime_resource_leases`, updates both memory fields only after commit, and emits one `heartbeat/gpu` event.
3. A temporary SQLite trigger that raises `ABORT` before updating `runtime_resource_leases` proves the runtime row rolls back and memory expiry does not change.
4. Expired DB lease returns `fence_lost`; malformed memory expiry attempts renewal rather than skipping.

For the GPU rollback check, use a connection-local trigger and remove it in `finally`:

```sql
CREATE TEMP TRIGGER runtime_telemetry_gpu_fail
BEFORE UPDATE ON runtime_resource_leases
BEGIN
    SELECT RAISE(ABORT, 'forced gpu heartbeat failure');
END
```

- [ ] **Step 2: Run and confirm RED**

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=runtime-telemetry
```

Expected: heartbeat still writes every tick or CPU/GPU expiry values do not use the supplied exact value.

- [ ] **Step 3: Let heartbeat writers accept one precomputed expiry and optional stats**

Use backward-compatible trailing parameters:

```php
function hub_runtime_heartbeat(PDO $db, int $runId, string $leaseToken, int $leaseSeconds, ?string $expiresAt = null, ?array &$stats = null): bool
function hub_runtime_gpu_heartbeat(PDO $db, array $run, array $lease, int $leaseSeconds, ?string $expiresAt = null, ?array &$stats = null): bool
```

When `$expiresAt` is null, preserve current `hub_runtime_lease_until($leaseSeconds)` behavior for existing callers. CPU remains exactly one conditional autocommit `UPDATE`. GPU keeps raw `BEGIN IMMEDIATE`, then exactly the current runtime update and GPU lease update. Populate this exact stats shape after the DB operation/rollback; do not emit in `runtime_worker.php` because non-pack callers are out of scope:

```php
[
    'tx_begin_at' => $transactionOpened ? $txBeginAt : null,
    'tx_commit_at' => $txCommitAt,
    'begin_requested_ns' => $beginRequestedNs,
    'tx_started_ns' => $transactionOpened ? $txStartedNs : null,
    'tx_ended_ns' => $txEndedNs,
    'lock_wait_ms' => $lockWaitMs,
    'lock_wait_kind' => $gpu ? 'begin_immediate' : 'first_write_upper_bound',
    'retry_count' => 0,
    'lock_exhausted' => $lockExhausted,
]
```

For CPU, `begin_requested_ns` and `tx_started_ns` are the autocommit write start, and `lock_wait_ms` is the full `execute()` duration upper bound. For GPU, `begin_requested_ns` is immediately before raw `BEGIN IMMEDIATE`, `tx_started_ns` is immediately after it returns, and `tx_ended_ns` is after commit/rollback. If begin throws, leave `tx_started_ns` and `tx_begin_at` null, set `lock_exhausted` only for a SQLite locked error, and still set `tx_ended_ns`/`tx_commit_at` after cleanup.

- [ ] **Step 4: Integrate the state into every pack-job tick call**

Immediately after a successful runtime claim:

```php
$heartbeatState = hub_pack_job_heartbeat_state($run, null);
```

After GPU acquisition, set `gpu_required` and copy the acquired lease expiry. After `hub_pack_job_begin_execution()` refreshes `$run`, copy its actual runtime expiry back into state.

Change the tick signature to:

```php
function hub_pack_job_tick(PDO $db, array $run, ?array $gpuLease, int $leaseSeconds, array &$heartbeatState): ?string
```

At tick start:

```php
$actionStartedNs = hrtime(true);
$nowEpoch = time();
if (!hub_pack_job_heartbeat_should_renew($heartbeatState, $nowEpoch)) {
    hub_pack_job_heartbeat_mark_skipped($heartbeatState);
} else {
    $newExpiry = date('Y-m-d H:i:s', $nowEpoch + max(1, $leaseSeconds));
    $stats = [];
    $alive = $gpuLease === null
        ? hub_runtime_heartbeat($db, (int)$run['id'], (string)$run['lease_token'], $leaseSeconds, $newExpiry, $stats)
        : hub_runtime_gpu_heartbeat($db, $run, $gpuLease, $leaseSeconds, $newExpiry, $stats);
    $skipped = $alive ? hub_pack_job_heartbeat_mark_committed($heartbeatState, $newExpiry) : 0;
    $emitStartedNs = hrtime(true);
    $beginRequestedNs = $stats['begin_requested_ns'] ?? $actionStartedNs;
    $txStartedNs = $stats['tx_started_ns'] ?? $beginRequestedNs;
    $txEndedNs = $stats['tx_ended_ns'] ?? $emitStartedNs;
    hub_runtime_telemetry_emit([
        'action' => 'heartbeat',
        'variant' => $gpuLease === null ? 'cpu' : 'gpu',
        'outcome' => $alive ? 'committed' : 'fence_lost',
        'tx_mode' => $gpuLease === null ? 'autocommit' : 'immediate',
        'tx_begin_at' => $stats['tx_begin_at'] ?? null,
        'tx_commit_at' => $stats['tx_commit_at'] ?? null,
        'pre_tx_ms' => hub_runtime_telemetry_elapsed_ms($actionStartedNs, $beginRequestedNs),
        'lock_wait_ms' => (float)($stats['lock_wait_ms'] ?? 0),
        'lock_wait_kind' => (string)($stats['lock_wait_kind'] ?? 'none'),
        'tx_ms' => hub_runtime_telemetry_elapsed_ms($txStartedNs, $txEndedNs),
        'post_tx_ms' => hub_runtime_telemetry_elapsed_ms($txEndedNs, $emitStartedNs),
        'total_ms' => hub_runtime_telemetry_elapsed_ms($actionStartedNs, $emitStartedNs),
        'retry_count' => (int)($stats['retry_count'] ?? 0),
        'skipped_ticks' => $skipped,
    ]);
    if (!$alive) {
        return 'fence_lost';
    }
}
```

Wrap only the renewal call in `try/catch`: after rollback/cleanup, emit the same schema with outcome `lock_exhausted` when stats says so, otherwise `failed`; use `skipped_ticks=0`, leave memory state untouched, then rethrow. A failed renewal never consumes accumulated skips.

Keep the existing cancellation, current-run fence, and timeout reads after this block. Change the executor closure to `use ($db, $run, $gpuLease, $leaseSeconds, &$heartbeatState)` and pass the same variable to the closure, post-executor tick, success follow-up tick, catch tick, and lost-fence paths. Do not reconstruct state in any branch.

- [ ] **Step 5: Verify and commit**

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=runtime-telemetry
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane
git add app/runtime_worker.php app/pack_job_runner.php tests/test_runtime_telemetry.php
git commit -m "feat: reduce pack job heartbeat writes"
```

Expected: focused suite passes; control-plane has no new heartbeat/fence failures. Record any pre-existing unrelated control-plane failures verbatim rather than weakening tests.

## Task 6: Instrument finish/recovery and flush terminal skipped ticks

**Files:**

- Modify: `app/task_queue.php:4752-4910`
- Modify: `app/pack_job_runner.php:1008-1015, 2654-2820, 2880-3270`
- Modify: `tests/test_runtime_telemetry.php`

- [ ] **Step 1: Write failing terminal and recovery checks**

Using minimal pack-job terminal fixtures, set `$heartbeatState['skipped_ticks'] = 2`, commit one success and one failure, and assert each emits exactly one `finish/success` or `finish/failure` event with `skipped_ticks=2`, then state is zero. Set it to 0 before a second terminal attempt and assert no duplicate skips.

Create expired runtime-only and GPU lost-fence fixtures, call `hub_pack_job_reconcile_lost_fence()`, and assert one `recovery/runtime` or one `recovery/gpu` event per transaction. A rolled-back fence mismatch must emit a failure/fence outcome only after rollback and must never emit both variants.

- [ ] **Step 2: Run and confirm RED**

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=runtime-telemetry
```

Expected: finish/recovery events are absent and terminal skipped ticks remain unconsumed.

- [ ] **Step 3: Add one optional by-reference state parameter through the terminal chain**

Append `?array &$heartbeatState = null` to these existing signatures, preserving every prior positional argument:

```php
function hub_pack_job_adapter_failure(PDO $db, int $taskId, array $run, string $code, string $message, array $cleanup, ?array $gpuLease, ?array &$heartbeatState = null): array
function hub_commit_published_pack_job_success(PDO $db, int $taskId, ?array $run, array $publishedArtifacts, array $cleanup, ?array $gpuLease = null, ?callable $beforeTerminalFence = null, ?array &$heartbeatState = null): array
function hub_commit_pack_job_success(PDO $db, int $taskId, ?array $run, array $validatedArtifacts, array $cleanup, ?array $gpuLease = null, ?callable $afterHandoff = null, ?callable $beforeTerminalFence = null, ?array &$heartbeatState = null): array
function hub_commit_pack_job_failure(PDO $db, int $taskId, ?array $run, string $status, string $errorCode, string $errorMessage, array $cleanup = [], ?array $gpuLease = null, ?array &$heartbeatState = null): void
function hub_finalize_pack_job_success(PDO $db, int $taskId, ?array $run, string $workspace, array $taskInput, array $jobContract, array $cleanup, ?callable $audioProbe = null, ?array $gpuLease = null, ?array $runnerConfig = null, ?array $sourceAudioAttestation = null, ?array &$heartbeatState = null): array
function hub_pack_job_reconcile_lost_fence(PDO $db, array $task, array $run, array $cleanup, ?array $gpuLease = null, ?array &$heartbeatState = null): bool
function hub_pack_job_lost_fence_outcome(PDO $db, array $task, array $run, array $options, bool $started, ?array $context, array $details, ?callable $pidInspector, ?array $gpuLease = null, ?array $cleanup = null, ?array &$heartbeatState = null): array
```

Pass the same `$heartbeatState` variable from `hub_run_pack_job_task()` through every adapter failure, cancelled/timed-out failure, final success, exception failure, and lost-fence call. Existing external/tests callers may omit it and receive `skipped_ticks=0`.

- [ ] **Step 4: Emit one event around each actual terminal transaction**

Instrument only `hub_commit_published_pack_job_success()` and `hub_commit_pack_job_failure()` because the surrounding functions validate or hand off files outside the DB transaction. Start `pre_tx` immediately before their existing `$db->beginTransaction()`. On commit emit `committed`; on rollback emit `rolled_back`, `fence_lost`, or `failed` according to the existing exception/outcome. Use `tx_mode=deferred`, `lock_wait_kind=first_write_upper_bound`, and consume skipped ticks once after DB completion:

```php
$skipped = hub_pack_job_heartbeat_take_skipped($heartbeatState);
hub_runtime_telemetry_emit($event + ['skipped_ticks' => $skipped]);
```

Telemetry failure still leaves the counter cleared. Do not move artifact metadata, terminal fence, retention metadata, hold release, or callback enqueue out of their current transaction in Phase 1.

- [ ] **Step 5: Instrument the existing recovery transaction without duplication**

Call `hub_sqlite_begin_immediate($db, $beginStats)` in `hub_pack_job_reconcile_lost_fence()`. Build one event after each commit/rollback/catch, with `variant = $gpuLease === null ? 'runtime' : 'gpu'`, `tx_mode=immediate`, and begin stats. Consume terminal skips in that one recovery event. Scheduled recovery calls without execution-local state naturally report zero.

- [ ] **Step 6: Verify and commit**

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=runtime-telemetry
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane
php -l app/task_queue.php
php -l app/pack_job_runner.php
git add app/task_queue.php app/pack_job_runner.php tests/test_runtime_telemetry.php
git commit -m "feat: measure pack job terminal transactions"
```

## Task 7: Final verification and rollout evidence

**Files:**

- Modify only if a test exposes a defect; do not add benchmark infrastructure.

- [ ] **Step 1: Run focused and existing regression suites**

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=runtime-telemetry
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane
php scripts/run_tests.php
```

Expected: focused suite has zero failures. Compare control-plane/full failures with the pre-change baseline; fix only regressions caused by this feature.

- [ ] **Step 2: Run syntax and static path checks**

```bash
php -l app/runtime_telemetry.php
php -l app/db.php
php -l app/runtime_worker.php
php -l app/task_queue.php
php -l app/pack_job_runner.php
php -l scripts/runtime_telemetry_summary.php
rg -n "runtime_telemetry|heartbeatState" app scripts tests
rg -n "hub_pack_job_tick\(" app tests
```

Expected: no syntax errors; every pack-job tick call passes the same state; no hot path calls `hub_prune_runtime_telemetry()`.

- [ ] **Step 3: Prove cadence and inspect real output**

Run one focused pack-job load long enough to cross at least two renewal thresholds, then:

```bash
php scripts/runtime_telemetry_summary.php --since="1 hour"
```

Confirm:

- `heartbeat_ticks_total = heartbeat event count + skipped` matches the test/load tick count.
- 60-second lease produces about 2 renewal writes/minute instead of 6 (at least 60% lower; target 67%).
- `heartbeat/gpu` records one event per two-row transaction.
- no event contains task input, IDs, tokens, paths, or exception stacks.

- [ ] **Step 4: Review the diff against the approved non-goals**

```bash
git diff 7db88e8..HEAD --stat
git diff 7db88e8..HEAD -- app/db.php app/runtime_worker.php app/task_queue.php app/pack_job_runner.php
git status --short --branch
```

Reject any accidental claim atomic rewrite, callback/outbox instrumentation, retry-policy change, single-writer code, PostgreSQL work, dependency, or config knob.

- [ ] **Step 5: Commit any verification-only fix, then stop before push unless the user explicitly authorizes publication**

```bash
git add app/runtime_telemetry.php app/db.php app/runtime_worker.php app/task_queue.php app/pack_job_runner.php scripts/runtime_telemetry_summary.php scripts/run_tests.php tests/test_runtime_telemetry.php tests/suites/runtime-telemetry.php
git commit -m "fix: preserve pack job runtime telemetry semantics"
```

If no fix was needed, do not create an empty commit. Report focused/control-plane/full results and the summary sample. Deployment should collect at least half a day before deciding Phase 2.
