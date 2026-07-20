# Job-first Audio Packs Runtime Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move cleanup, transcription, and VoxCPM2 generation into three governed asynchronous 3waAIHub Pack jobs, then connect MyAI through signed callbacks and managed artifacts.

**Architecture:** Keep the existing `tasks` business queue, `runtime_runs`, `task_artifacts`, Pack manifests, `local_jobs`, and `bin/aihub-run`. Add immutable mode routing, a persistent host GPU lease, managed-run execution, atomic terminal commits, callback outbox delivery, and file retention; Pack-specific audio/model behavior stays inside each Pack runner.

**Tech Stack:** PHP 8, SQLite, existing Hub test harness, Docker with NVIDIA Container Toolkit, Python 3, Demucs, DeepFilterNet, faster-whisper/WhisperX/pyannote, VoxCPM2, ffmpeg/ffprobe, MyAI PHP job pipeline.

---

## Delivery shape

This is one dependency-ordered plan, but each numbered task is an independently testable commit. Do not create separate Pack queues, Pack-specific PHP workers, a generic voice-assembly library, or new MyAI tables.

## File map

### Hub Core

- Modify `app/db.php`: idempotent task, artifact, callback, retry, and GPU lease schema.
- Modify `app/bootstrap.php`: load the three focused Core modules below.
- Modify `app/storage.php`: retention, callback, sync, and GPU safety settings.
- Modify `app/pack_registry.php`: validate manifest-declared job input/output contracts.
- Modify `app/task_queue.php`: owned task creation, lineage, terminal state, and manual retry linkage.
- Modify `app/runtime_worker.php`: provisional attempts, dual heartbeat fencing, GPU resource state transitions.
- Modify `app/gateway.php`: public async mode routing, owned task APIs, artifact ACK/pin, and sync limits.
- Modify `app/docker_runner.php`: read-only Docker/NVIDIA evidence helpers.
- Create `app/task_callbacks.php`: callback target lookup, HMAC, outbox claim, retry state.
- Create `app/task_artifacts.php`: output validation, retention holds, purge planning, safe download state.
- Create `app/pack_jobs.php`: saved-route resolution and generic Pack-job execution orchestration.
- Modify `bin/aihub-run`: attach to an existing managed run without creating or terminally updating it.
- Modify `scripts/task_worker.php`: dispatch the existing `pack_job` task type through the generic adapter.
- Create `scripts/callback_worker.php`: post claimed outbox deliveries after commit.
- Create `scripts/register_callback_target.php`: admin-only callback alias registration.
- Modify `scripts/prune_db.php`: purge physical managed files before old metadata.
- Modify `scripts/command_worker.php` and `scripts/self_check.php`: stop schema mutations outside install/init.

### Packs

- Create `packs/audio-cleanup/`: Pack manifest, image, cleanup job runner, and dry-run smoke.
- Modify `packs/catalog.json`: register `audio-cleanup`.
- Modify `packs/whisper-asr/pack.json` and `packs/whisper-asr/service/`: add the `transcribe` job contract and runner while preserving short sync ASR.
- Modify `packs/tts-voxcpm2/pack.json` and `packs/tts-voxcpm2/service/`: add the `synthesize` job contract and VoxCPM2-only long-form assembly.

### MyAI

- Create `/var/www/html/myai/myai_voice/voice_aihub.php`: small Hub HTTP/HMAC/artifact client.
- Modify `/var/www/html/myai/myai_voice/voice_common.php`: load the client, remove the embedded Hugging Face credential, and store Hub IDs in existing `job_options`.
- Modify `/var/www/html/myai/myai_voice/api.php`: public signed callback handler.
- Modify `/var/www/html/myai/myai_voice/crontab/1min_step1_run_demucs.php`: submit/poll `audio_cleanup` instead of running models locally when Hub mode is enabled.
- Modify `/var/www/html/myai/myai_voice/crontab/1min_step2_run_whisper.php`: submit/poll `speech_transcribe` and import artifacts.
- Modify `/var/www/html/myai/myai_voice/crontab/1min.sh`: run the lightweight reconciliation poller.

### Tests and acceptance

- Create `tests/test_job_first_schema.php`.
- Create `tests/test_audio_task_gateway.php`.
- Create `tests/test_task_callbacks.php`.
- Create `tests/test_task_artifacts_retention.php`.
- Create `tests/test_gpu_resource_lease.php`.
- Create `tests/test_pack_job_worker.php`.
- Modify `tests/test_audio_packs.php` and `tests/test_tts_voxcpm2.php`.
- Create `scripts/audio_packs_acceptance.php`.
- Create `/var/www/html/myai/myai_voice/tests/myai_voice_aihub_static_test.php`.
- Create `/var/www/html/myai/myai_voice/tests/myai_voice_aihub_callback_behavior_test.php`.

## Interfaces locked by this plan

Keep these signatures consistent across tasks and tests:

```php
function hub_audio_async_routes(): array;
function hub_resolve_audio_async_route(PDO $db, string $mode): array;
function hub_runtime_schema_missing(PDO $db): array;
function hub_validate_pack_job_artifacts(array $manifest, array $request, string $workspace, ?callable $audioProbe = null): array;
function hub_finish_pack_job_terminal(PDO $db, array $context, array $artifacts, string $taskState, ?string $errorCode = null): void;
function hub_run_pack_job_task(PDO $db, array $task, array $hooks = []): void;
function hub_callback_signature(string $secret, string $timestamp, string $body): string;
function hub_callback_retry_delay(int $attemptCount): ?int;
function hub_artifact_ack(PDO $db, array $task, array $artifactIds, string $now): int;
function hub_artifact_set_pin(PDO $db, array $task, int $artifactId, bool $pinned): bool;
```

`$hooks` exists only for deterministic process/GPU evidence in tests. Production always passes the default empty array.

## Task 1: Idempotent schema and default policies

**Files:**
- Modify: `app/db.php`
- Modify: `app/storage.php`
- Modify: `scripts/command_worker.php`
- Modify: `scripts/self_check.php`
- Modify: `scripts/prune_db.php`
- Create: `tests/test_job_first_schema.php`

- [ ] **Step 1: Write the failing migration test**

Create `tests/test_job_first_schema.php` with helpers that inspect `PRAGMA table_info` and assert every required table/column plus the fixed GPU row:

```php
<?php
declare(strict_types=1);

function hub_test_columns(PDO $db, string $table): array
{
    return array_column($db->query('PRAGMA table_info(' . $table . ')')->fetchAll(), 'name');
}

hub_test('job-first audio migration is idempotent and preserves old rows', function (): void {
    $db = hub_test_reset_db();
    $taskId = hub_enqueue_task($db, 'demo_task', 'default', 0, ['legacy' => true], null, '127.0.0.1');
    hub_migrate($db);
    hub_migrate($db);

    foreach (['task_callback_targets', 'task_callback_deliveries', 'runtime_resource_leases'] as $table) {
        hub_test_assert((string)$db->query("SELECT name FROM sqlite_master WHERE type='table' AND name=" . $db->quote($table))->fetchColumn() === $table, 'missing table ' . $table);
    }
    foreach (['owner_member_id', 'owner_token_id', 'requested_mode', 'pack_id', 'pack_version', 'job', 'runtime_mode', 'accelerator', 'route_resolved_at', 'source_artifact_id', 'source_task_id', 'retry_of_task_id', 'callback_target_id', 'waiting_reason', 'next_attempt_at', 'error_code', 'source_expires_at', 'workspace_expires_at', 'source_state', 'workspace_state', 'retention_state', 'purged_at', 'freed_bytes'] as $column) {
        hub_test_assert(in_array($column, hub_test_columns($db, 'tasks'), true), 'missing tasks.' . $column);
    }
    foreach (['artifact_type', 'sha256', 'expires_at', 'state', 'pinned_at', 'legal_hold', 'acknowledged_at', 'last_accessed_at', 'purged_at', 'purge_error'] as $column) {
        hub_test_assert(in_array($column, hub_test_columns($db, 'task_artifacts'), true), 'missing task_artifacts.' . $column);
    }
    foreach (['task_id', 'attempt_no', 'gpu_process_baseline_json', 'owned_gpu_pids_json'] as $column) {
        hub_test_assert(in_array($column, hub_test_columns($db, 'runtime_runs'), true), 'missing runtime_runs.' . $column);
    }
    $gpu = $db->query("SELECT * FROM runtime_resource_leases WHERE resource_key='gpu:0'")->fetch();
    hub_test_assert($gpu !== false && $gpu['state'] === 'available', 'gpu:0 must be initialized once');
    hub_test_assert(hub_get_task($db, $taskId) !== null, 'legacy task was lost');

    foreach (['scripts/task_worker.php', 'scripts/command_worker.php', 'scripts/self_check.php', 'scripts/prune_db.php', 'bin/aihub-run'] as $relative) {
        $source = (string)file_get_contents(HUB_ROOT . '/' . $relative);
        hub_test_assert(!str_contains($source, 'hub_migrate($db)'), $relative . ' must not mutate schema');
    }
});
```

- [ ] **Step 2: Run the suite and confirm the expected failure**

Run: `php scripts/run_tests.php`

Expected: FAIL in `job-first audio migration is idempotent and preserves old rows` because `task_callback_targets` is absent.

- [ ] **Step 3: Add all schema through `hub_migrate()`**

Add the callback/resource DDL with `CREATE TABLE IF NOT EXISTS`:

```sql
CREATE TABLE IF NOT EXISTS task_callback_targets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_member_id INTEGER NOT NULL,
    target_alias TEXT NOT NULL,
    callback_url TEXT NOT NULL,
    signing_secret TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY(owner_member_id) REFERENCES api_members(id) ON DELETE CASCADE,
    UNIQUE(owner_member_id, target_alias)
);

CREATE TABLE IF NOT EXISTS task_callback_deliveries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    delivery_id TEXT NOT NULL UNIQUE,
    callback_target_id INTEGER NOT NULL,
    task_id INTEGER NOT NULL,
    event_type TEXT NOT NULL,
    payload_json TEXT NOT NULL,
    attempt_count INTEGER NOT NULL DEFAULT 0,
    next_attempt_at TEXT NULL,
    delivered_at TEXT NULL,
    last_http_status INTEGER NULL,
    last_error TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY(callback_target_id) REFERENCES task_callback_targets(id) ON DELETE CASCADE,
    FOREIGN KEY(task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    UNIQUE(callback_target_id, task_id, event_type)
);

CREATE TABLE IF NOT EXISTS runtime_resource_leases (
    resource_key TEXT PRIMARY KEY,
    runtime_run_id TEXT NULL,
    worker_id TEXT NULL,
    lease_token TEXT NULL,
    state TEXT NOT NULL,
    acquired_at TEXT NULL,
    heartbeat_at TEXT NULL,
    lease_expires_at TEXT NULL,
    last_error TEXT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY(runtime_run_id) REFERENCES runtime_runs(run_id) ON DELETE SET NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_callback_target_alias
ON task_callback_targets(owner_member_id, target_alias);

CREATE INDEX IF NOT EXISTS idx_callback_delivery_pending
ON task_callback_deliveries(delivered_at, next_attempt_at);

CREATE INDEX IF NOT EXISTS idx_callback_delivery_task
ON task_callback_deliveries(task_id);

CREATE INDEX IF NOT EXISTS idx_resource_lease_expiry
ON runtime_resource_leases(state, lease_expires_at);

CREATE INDEX IF NOT EXISTS idx_resource_lease_run
ON runtime_resource_leases(runtime_run_id);
```

Use `hub_add_column_if_missing()` with these exact definitions:

```php
foreach ([
    'owner_member_id' => 'INTEGER NULL',
    'owner_token_id' => 'INTEGER NULL',
    'requested_mode' => 'TEXT NULL',
    'pack_id' => 'TEXT NULL',
    'pack_version' => 'TEXT NULL',
    'job' => 'TEXT NULL',
    'runtime_mode' => 'TEXT NULL',
    'accelerator' => 'TEXT NULL',
    'route_resolved_at' => 'TEXT NULL',
    'source_artifact_id' => 'INTEGER NULL',
    'source_task_id' => 'INTEGER NULL',
    'retry_of_task_id' => 'INTEGER NULL',
    'callback_target_id' => 'INTEGER NULL',
    'waiting_reason' => 'TEXT NULL',
    'next_attempt_at' => 'TEXT NULL',
    'error_code' => 'TEXT NULL',
    'source_expires_at' => 'TEXT NULL',
    'workspace_expires_at' => 'TEXT NULL',
    'source_state' => "TEXT NOT NULL DEFAULT 'available'",
    'workspace_state' => "TEXT NOT NULL DEFAULT 'active'",
    'retention_state' => "TEXT NOT NULL DEFAULT 'active'",
    'purged_at' => 'TEXT NULL',
    'freed_bytes' => 'INTEGER NOT NULL DEFAULT 0',
] as $column => $definition) {
    hub_add_column_if_missing($db, 'tasks', $column, $definition);
}

foreach ([
    'artifact_type' => 'TEXT NULL',
    'sha256' => 'TEXT NULL',
    'expires_at' => 'TEXT NULL',
    'state' => "TEXT NOT NULL DEFAULT 'available'",
    'pinned_at' => 'TEXT NULL',
    'legal_hold' => 'INTEGER NOT NULL DEFAULT 0',
    'acknowledged_at' => 'TEXT NULL',
    'last_accessed_at' => 'TEXT NULL',
    'purged_at' => 'TEXT NULL',
    'purge_error' => 'TEXT NULL',
] as $column => $definition) {
    hub_add_column_if_missing($db, 'task_artifacts', $column, $definition);
}

foreach ([
    'task_id' => 'INTEGER NULL',
    'attempt_no' => 'INTEGER NULL',
    'gpu_process_baseline_json' => 'TEXT NULL',
    'owned_gpu_pids_json' => 'TEXT NULL',
] as $column => $definition) {
    hub_add_column_if_missing($db, 'runtime_runs', $column, $definition);
}
```

Seed the resource without overwriting fault state:

```php
$db->exec(
    "INSERT OR IGNORE INTO runtime_resource_leases
        (resource_key, state, updated_at)
     VALUES ('gpu:0', 'available', " . $db->quote(hub_now()) . ')'
);
```

Add these defaults to `hub_default_storage_settings()`:

```php
'AIHUB_SOURCE_RETENTION_DAYS' => '7',
'AIHUB_WORKSPACE_RETENTION_HOURS' => '24',
'AIHUB_ARTIFACT_RETENTION_DAYS' => '30',
'AIHUB_PARTIAL_RETENTION_HOURS' => '1',
'AIHUB_TASK_RETENTION_DAYS' => '180',
'AIHUB_GPU_VRAM_SAFETY_MARGIN_MB' => '256',
'AIHUB_RUNTIME_MAX_ATTEMPTS' => '2',
'AIHUB_CALLBACK_ALLOWED_HOSTS' => '127.0.0.1,localhost',
'AIHUB_CALLBACK_ALLOW_LOOPBACK_HTTP' => '1',
'AIHUB_SYNC_MAX_AUDIO_SECONDS' => '30',
'AIHUB_SYNC_CONCURRENCY' => '1',
```

Remove `hub_migrate($db)` from `scripts/task_worker.php`, `scripts/command_worker.php`, `scripts/self_check.php`, `bin/aihub-run`, and the new callback worker. Their startup schema check must return `schema_upgrade_required` with the command `php scripts/init_db.php`; it must not execute DDL. Tests and `scripts/init_db.php` remain allowed migration callers.

Add this read-only helper to `app/db.php` and reuse it everywhere:

```php
function hub_runtime_schema_missing(PDO $db): array
{
    $required = [
        'tasks' => ['owner_member_id', 'requested_mode', 'pack_id', 'job'],
        'task_artifacts' => ['artifact_type', 'sha256', 'expires_at', 'state'],
        'runtime_runs' => ['task_id', 'attempt_no'],
        'task_callback_targets' => [],
        'task_callback_deliveries' => [],
        'runtime_resource_leases' => [],
    ];
    $missing = [];
    foreach ($required as $table => $columns) {
        $exists = $db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name=" . $db->quote($table))->fetchColumn();
        if ($exists === false) {
            $missing[] = $table;
            continue;
        }
        $actual = array_column($db->query('PRAGMA table_info(' . $table . ')')->fetchAll(), 'name');
        foreach ($columns as $column) {
            if (!in_array($column, $actual, true)) {
                $missing[] = $table . '.' . $column;
            }
        }
    }
    return $missing;
}
```

- [ ] **Step 4: Verify migration twice and the full suite**

Run: `php scripts/init_db.php && php scripts/init_db.php && php scripts/run_tests.php`

Expected: both migrations exit 0; all PHP tests report `failures=0`.

- [ ] **Step 5: Commit**

```bash
git add app/db.php app/storage.php scripts/task_worker.php scripts/command_worker.php scripts/self_check.php scripts/prune_db.php bin/aihub-run tests/test_job_first_schema.php
git commit -m "feat: add audio task runtime schema"
```

## Task 2: Immutable async mode admission and ownership

**Files:**
- Modify: `app/pack_registry.php`
- Modify: `app/task_queue.php`
- Modify: `app/gateway.php`
- Create: `tests/test_audio_task_gateway.php`

- [ ] **Step 1: Write failing route, ownership, and lineage tests**

Create two API members and tokens. Assert:

```php
$routes = hub_audio_async_routes();
hub_test_assert($routes['audio_cleanup'] === ['pack_id' => 'audio-cleanup', 'job' => 'cleanup'], 'cleanup route mismatch');
hub_test_assert($routes['speech_transcribe'] === ['pack_id' => 'whisper-asr', 'job' => 'transcribe'], 'ASR route mismatch');
hub_test_assert($routes['voice_generate'] === ['pack_id' => 'tts-voxcpm2', 'job' => 'synthesize'], 'TTS route mismatch');
```

Submit a fixture upload as member A, then assert the task snapshots `requested_mode`, Pack version, `runtime_mode=job`, `accelerator=gpu`, owner member/token, and resolution time. Include `pack_id`, `entrypoint`, and `command` in a second request and expect HTTP 400 `forbidden_task_control`.

Register a fixture artifact for member A. Assert another token for member A can create a downstream task, member B receives 404, expired/purged/wrong-type artifacts are rejected, and accepted lineage saves both source IDs.

Finally assert member B receives 404 for member A's status, result, log, cancel, retry, and artifact download.

- [ ] **Step 2: Run the suite and confirm the route helper is missing**

Run: `php scripts/run_tests.php`

Expected: FAIL with `Call to undefined function hub_audio_async_routes()`.

- [ ] **Step 3: Add the fixed route map and manifest job validation**

Add to `app/pack_registry.php`:

```php
function hub_audio_async_routes(): array
{
    return [
        'audio_cleanup' => ['pack_id' => 'audio-cleanup', 'job' => 'cleanup'],
        'speech_transcribe' => ['pack_id' => 'whisper-asr', 'job' => 'transcribe'],
        'voice_generate' => ['pack_id' => 'tts-voxcpm2', 'job' => 'synthesize'],
    ];
}
```

Extend manifest validation so every `local_jobs` item has a safe `job_key`, a relative existing entrypoint, explicit `input`, and `artifacts.required`/`artifacts.conditional` arrays. Add `runtime.kind=job_container` for an async Pack that only builds/runs one-shot containers: it requires `execution_type=async_task`, a compose build definition, and at least one local job, but reserves no HTTP port and exposes no sync Gateway route. Its install row records `install_status=installed` and `runtime_status=stopped`; normal service start is unavailable.

Add `hub_resolve_audio_async_route()` to combine the fixed route with the installed `services.pack_id` row and current valid manifest. It returns the installed Pack version, job, `runtime_mode=job`, and `accelerator=gpu`; absent/mismatched installation returns `pack_not_installed` or `pack_version_unavailable`.

- [ ] **Step 4: Extend owned task creation without replacing the queue**

Add optional ownership/route fields to the existing `hub_enqueue_task()` argument list and INSERT. Add:

```php
function hub_task_owned_by_auth(array $task, array $auth): bool
{
    return !empty($auth['member_id'])
        && (int)($task['owner_member_id'] ?? 0) === (int)$auth['member_id'];
}
```

Add `hub_create_manual_retry()` that inserts a new task, copies the saved route and still-valid source/checkpoint references, sets `retry_of_task_id`, and never updates the old task.

- [ ] **Step 5: Add async admission before ordinary service routing**

In `hub_gateway_dispatch()`, authenticate `audio_cleanup`, `speech_transcribe`, and `voice_generate` by their requested mode before `hub_get_service_by_mode()`. Route all three to `hub_api_audio_task_submit()`.

`hub_api_audio_task_submit()` must:

1. reject public control keys `pack_id`, `pack_version`, `job`, `runtime_mode`, `accelerator`, `entrypoint`, `command`, `script`, and any path key;
2. resolve the fixed manifest job and snapshot the Pack version;
3. accept exactly one multipart file or `source_artifact_id`;
4. validate same-member ownership, state, expiry, type allowlist, and canonical managed path;
5. save only schema-allowlisted job parameters;
6. create a `pack_job` task in the existing queue.

Pass `$authContext` to status/result/log/cancel/retry/ACK/artifact handlers and return 404 for ownership mismatch. Keep trusted localhost/admin access only for legacy null-owner tasks.

- [ ] **Step 6: Run tests and commit**

Run: `php scripts/run_tests.php`

Expected: `failures=0`.

```bash
git add app/pack_registry.php app/task_queue.php app/gateway.php tests/test_audio_task_gateway.php
git commit -m "feat: add owned async audio task routing"
```

## Task 3: Registered callback targets and durable outbox

**Files:**
- Create: `app/task_callbacks.php`
- Modify: `app/bootstrap.php`
- Modify: `app/gateway.php`
- Create: `scripts/register_callback_target.php`
- Create: `scripts/callback_worker.php`
- Create: `tests/test_task_callbacks.php`

- [ ] **Step 1: Write failing callback tests**

Test exact signing and retry semantics:

```php
$body = '{"event":"task.completed","task_id":7}';
$signature = hub_callback_signature('secret', '1784500000', $body);
hub_test_assert($signature === 'sha256=' . hash_hmac('sha256', '1784500000.' . $body, 'secret'), 'HMAC mismatch');
hub_test_assert(hub_callback_retry_delay(1) === 30, 'attempt 1 delay mismatch');
hub_test_assert(hub_callback_retry_delay(2) === 120, 'attempt 2 delay mismatch');
hub_test_assert(hub_callback_retry_delay(3) === 600, 'attempt 3 delay mismatch');
hub_test_assert(hub_callback_retry_delay(4) === 3600, 'attempt 4 delay mismatch');
hub_test_assert(hub_callback_retry_delay(5) === null, 'attempt 5 must stop');
```

Also assert target aliases are member-scoped, task payload cannot supply a URL, only `task.completed` and `task.failed` are accepted, one task/event produces one stable delivery ID, only 2xx marks delivered, retries keep the same body/delivery ID, and redirects remain failures.

- [ ] **Step 2: Run the suite and confirm the signing helper is missing**

Run: `php scripts/run_tests.php`

Expected: FAIL with `Call to undefined function hub_callback_signature()`.

- [ ] **Step 3: Implement the minimal callback module**

Create pure helpers and DB operations:

```php
function hub_callback_signature(string $secret, string $timestamp, string $body): string
{
    return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
}

function hub_callback_retry_delay(int $attemptCount): ?int
{
    return [1 => 30, 2 => 120, 3 => 600, 4 => 3600, 5 => null][$attemptCount] ?? null;
}
```

Add `hub_callback_target_for_member()`, `hub_callback_enqueue_in_transaction()`, `hub_callback_claim_next()`, and `hub_callback_record_result()`. Claim with `BEGIN IMMEDIATE`; sending happens after commit. Store the final JSON once and reuse it unchanged.

Extend async admission so `callback_target=<alias>` resolves through `hub_callback_target_for_member()` and saves only `callback_target_id`. Reject `callback_url`, `callback_secret`, and unknown/disabled aliases.

Target validation accepts HTTPS hosts listed in `AIHUB_CALLBACK_ALLOWED_HOSTS`; HTTP is accepted only for loopback when `AIHUB_CALLBACK_ALLOW_LOOPBACK_HTTP=1`. Disable redirects with `CURLOPT_FOLLOWLOCATION=false`.

- [ ] **Step 4: Add CLI registration and delivery worker**

`scripts/register_callback_target.php` accepts:

```text
--member-id <id> --alias <alias> --url <url> --secret-env <ENV_NAME>
```

It reads the secret from the named environment variable, validates the URL policy, and upserts the member/alias. It never prints the secret.

`scripts/callback_worker.php` claims one due delivery, sends headers `X-AIHub-Event`, `X-AIHub-Delivery`, `X-AIHub-Timestamp`, and `X-AIHub-Signature`, then records 2xx success or the next retry.

The stored body is compact and immutable:

```json
{
  "event": "task.completed",
  "task_id": 7,
  "state": "success",
  "completed_at": "2026-07-20T10:30:00+08:00",
  "artifacts": [
    {"artifact_id": 11, "artifact_type": "transcript_json"}
  ]
}
```

No transcript, subtitle body, audio, host path, or secret is embedded in the webhook.

- [ ] **Step 5: Run tests and commit**

Run: `php scripts/run_tests.php`

Expected: `failures=0`.

```bash
git add app/task_callbacks.php app/bootstrap.php app/gateway.php scripts/register_callback_target.php scripts/callback_worker.php tests/test_task_callbacks.php
git commit -m "feat: add signed callback outbox"
```

## Task 4: Artifact output contract and atomic terminal commit

**Files:**
- Create: `app/task_artifacts.php`
- Modify: `app/bootstrap.php`
- Modify: `app/task_queue.php`
- Create: `tests/test_task_artifacts_retention.php`

- [ ] **Step 1: Write failing validation tests**

Build temporary workspace fixtures and assert `hub_validate_pack_job_artifacts()`:

- accepts required artifacts and activated conditional artifacts;
- rejects missing required output as `output_contract_invalid`;
- rejects traversal, symlink escape, directory, bad MIME, unreadable audio, and invalid report JSON;
- recomputes SHA-256 and size rather than trusting runner output;
- does not insert `task_artifacts` during validation.

Inject a deterministic audio probe in tests:

```php
$probe = static fn (string $path): array => [
    'duration_seconds' => 1.25,
    'sample_rate' => 24000,
    'channels' => 1,
];
```

- [ ] **Step 2: Run the suite and confirm the validator is missing**

Run: `php scripts/run_tests.php`

Expected: FAIL with `Call to undefined function hub_validate_pack_job_artifacts()`.

- [ ] **Step 3: Implement pre-transaction validation**

Use `realpath()`, `is_link()`, `is_file()`, `finfo`, `hash_file('sha256', $realPath)`, `filesize()`, `json_decode($contents, true, 512, JSON_THROW_ON_ERROR)`, and ffprobe. Return an array of precomputed rows containing only:

```php
[
    'artifact_type' => $type,
    'name' => basename($realPath),
    'path' => $realPath,
    'mime_type' => $detectedMime,
    'size_bytes' => $size,
    'sha256' => $sha256,
    'expires_at' => $expiresAt,
]
```

Use small Pack-specific report validators keyed by artifact type; do not introduce a JSON-schema dependency.

- [ ] **Step 4: Add one fenced terminal transaction**

Add `hub_finish_pack_job_terminal()` to `app/task_queue.php`. It must begin `BEGIN IMMEDIATE`, verify exactly one owned runtime row and exactly one owned GPU row, insert prevalidated artifact metadata, finish the run/task, enqueue the callback row, and release or block `gpu:0` before commit.

The success path accepts metadata only after this external sequence:

```text
runner exit 0 -> container removed -> owned PID absent -> artifacts validated
```

The function must never hash, probe, move files, call Docker/NVIDIA, or send HTTP. Any fencing update count other than one throws and rolls back. Cleanup failure commits `failed/cleanup_failed` and `gpu:0=blocked`, with no public artifacts.

- [ ] **Step 5: Test rollback and terminal precedence**

Add assertions that a wrong lease token leaves task, run, GPU row, artifact registry, and callback outbox unchanged. Assert cleanup failure cannot become success, cancelled, or timed out.

- [ ] **Step 6: Run tests and commit**

Run: `php scripts/run_tests.php`

Expected: `failures=0`.

```bash
git add app/task_artifacts.php app/bootstrap.php app/task_queue.php tests/test_task_artifacts_retention.php
git commit -m "feat: validate and atomically commit task artifacts"
```

## Task 5: Persistent GPU lease, dual heartbeat, and recovery

**Files:**
- Modify: `app/runtime_worker.php`
- Modify: `app/docker_runner.php`
- Create: `tests/test_gpu_resource_lease.php`

- [ ] **Step 1: Write failing lease competition tests**

Test two workers racing for `gpu:0`, non-owner heartbeat/release/block, dual heartbeat rollback, waiting release, expired transition, clean recovery, owned PID remaining, ambiguous PID, and unmanaged pressure. The first acquire must return a row; the second must return null.

- [ ] **Step 2: Run the suite and confirm resource acquisition is missing**

Run: `php scripts/run_tests.php`

Expected: FAIL with `Call to undefined function hub_runtime_acquire_resource()`.

- [ ] **Step 3: Implement short SQLite-only resource transitions**

Add:

```php
hub_runtime_acquire_resource(PDO $db, array $run, string $workerId, string $leaseToken, int $leaseSeconds): ?array
hub_runtime_heartbeat_with_resource(PDO $db, int $runId, string $runUuid, string $workerId, string $leaseToken, int $leaseSeconds): bool
hub_runtime_release_for_waiting_gpu(PDO $db, array $run, string $workerId, string $leaseToken, string $reason, string $nextAttemptAt): bool
hub_runtime_expire_resource(PDO $db, string $resourceKey, string $recoveryWorkerId, int $leaseSeconds): ?array
hub_runtime_finish_resource_recovery(PDO $db, array $lease, string $nextState, ?string $error): bool
```

Every write uses run ID, worker ID, lease token, and expected state. Dual heartbeat uses one `BEGIN IMMEDIATE` transaction and rolls back if either update count is not one.

Provisional rows keep `attempt_no=NULL`. Only `hub_runtime_mark_running()` assigns `MAX(attempt_no)+1`; waiting GPU returns the same row to queued and consumes no attempt.

- [ ] **Step 4: Add read-only external evidence helpers**

In `app/docker_runner.php`, add helpers using argv arrays rather than shell strings:

```php
hub_nvidia_compute_processes(): array
hub_nvidia_free_vram_mb(int $gpuIndex): int
hub_docker_container_state(string $containerId): array
hub_docker_container_host_pids(string $containerId): array
hub_gpu_owned_pids(string $containerId, array $nvidiaPids): array
```

Recovery stops/removes only the recorded container. It never kills a PID directly. A known owned PID or ambiguous attribution prevents release; ambiguity becomes `blocked`.

- [ ] **Step 5: Run focused and full tests, then commit**

Run: `php scripts/run_tests.php`

Expected: `failures=0` including existing runtime recovery/cancel tests.

```bash
git add app/runtime_worker.php app/docker_runner.php tests/test_gpu_resource_lease.php
git commit -m "feat: fence host GPU job leases"
```

## Task 6: Generic managed Pack-job adapter

**Files:**
- Create: `app/pack_jobs.php`
- Modify: `app/bootstrap.php`
- Modify: `bin/aihub-run`
- Modify: `scripts/task_worker.php`
- Create: `tests/test_pack_job_worker.php`

- [ ] **Step 1: Write failing managed-run and retry tests**

Assert that:

- the saved Pack ID/version/job is resolved exactly and a missing old version fails `pack_version_unavailable`;
- the adapter never reads command/entrypoint from task input;
- insufficient preflight returns `waiting_gpu` without consuming `attempt_no`;
- a managed `aihub-run` attaches to one existing run and does not insert a duplicate;
- infrastructure failure creates at most two consumed runtime attempts;
- deterministic/input/output-contract failures do not retry;
- cancellation/timeout require cleanup and fencing;
- CPU-only ffmpeg tasks never acquire `gpu:0`.

Keep retry accounting independent: `runtime_attempt` is the count of this task's non-null `runtime_runs.attempt_no`; `callback_attempt` is `task_callback_deliveries.attempt_count`; Pack chunk metadata owns its own per-chunk `attempts` value. Queue claims, admission rejection, and `waiting_gpu` change none of them.

- [ ] **Step 2: Run the suite and confirm the adapter is missing**

Run: `php scripts/run_tests.php`

Expected: FAIL with `Call to undefined function hub_run_pack_job_task()`.

- [ ] **Step 3: Add managed mode to `bin/aihub-run`**

Add CLI flags:

```text
--managed --worker-id <id> --lease-token <token>
```

When `--managed` is present, require an existing `runtime_runs.run_id`, verify its Pack/job/worker/token, and skip the current INSERT and terminal UPDATE. During the process loop, call `hub_runtime_heartbeat_with_resource()` at the configured heartbeat interval. If fencing fails, terminate the child and exit nonzero.

Keep the current self-managed CLI path unchanged for direct YOLO/local-job use and its existing tests.

- [ ] **Step 4: Implement orchestration around existing helpers**

`hub_run_pack_job_task()` performs:

```text
saved route resolution
-> provisional runtime row/lease
-> gpu:0 acquire
-> outside-transaction VRAM/process preflight
-> fenced attempt_no assignment
-> managed bin/aihub-run invocation
-> recorded-container stop/remove verification
-> owned GPU PID verification
-> artifact validation
-> atomic terminal transaction
```

Before launch it stores the NVIDIA PID baseline. After the container ID appears, it intersects container host PIDs with the NVIDIA process list and stores only those owned PIDs on the run. Cleanup/recovery never kills the baseline or any unowned PID.

Pass standardized environment variables `AIHUB_CONTAINER_ID_PATH`, `AIHUB_REQUEST_JSON`, and `AIHUB_WORKSPACE`. Pack entrypoints must use Docker `--cidfile "$AIHUB_CONTAINER_ID_PATH" --rm`.

On rollback, leave workspace output unregistered. On cancel/timeout, signal the managed runner, clean the container/PIDs, then use the same terminal fencing function. Cleanup failure blocks the GPU.

- [ ] **Step 5: Wire only one new task type**

Add `pack_job` to `hub_allowed_task_types()` and one `pack_job` branch in `scripts/task_worker.php`. Do not add cleanup/Whisper/VoxCPM PHP worker branches.

- [ ] **Step 6: Run tests and commit**

Run: `php scripts/run_tests.php`

Expected: `failures=0`.

```bash
git add app/pack_jobs.php app/bootstrap.php bin/aihub-run scripts/task_worker.php tests/test_pack_job_worker.php
git commit -m "feat: run managed Pack jobs through shared worker"
```

## Task 7: Retention, ACK, pin, and safe physical purge

**Files:**
- Modify: `app/task_artifacts.php`
- Modify: `app/gateway.php`
- Modify: `scripts/prune_db.php`
- Modify: `tests/test_task_artifacts_retention.php`

- [ ] **Step 1: Add failing retention lifecycle tests**

Assert defaults resolve to partial 1 hour, workspace 24 hours, source 7 days, artifacts 30 days, metadata 180 days. Assert ACK never expires earlier than 24 hours from ACK. Assert active run/GPU lease, downstream lineage, pin, legal hold, retry pending, and shared download lock prevent purge. Cover `.partial` upload, task workspace, original source, public artifact, and task metadata as separate lifecycle targets.

Create a symlink/traversal fixture and assert the pruner records `purge_failed` without deleting the target. Assert a successfully purged artifact returns HTTP 410 and its task row remains.

- [ ] **Step 2: Run the suite and confirm ACK mode is unavailable**

Run: `php scripts/run_tests.php`

Expected: FAIL because `task_artifacts_ack` is not routed.

- [ ] **Step 3: Implement lifecycle helpers**

Add:

```php
hub_artifact_ack(PDO $db, array $task, array $artifactIds, string $now): int
hub_artifact_set_pin(PDO $db, array $task, int $artifactId, bool $pinned): bool
hub_artifact_has_retention_hold(PDO $db, array $artifact, string $now): bool
hub_plan_due_artifact_purges(PDO $db, string $now, int $limit): array
hub_purge_artifact(PDO $db, array $artifact): array
hub_plan_due_task_path_purges(PDO $db, string $now, int $limit): array
hub_purge_task_source(PDO $db, array $task): array
hub_purge_task_workspace(PDO $db, array $task): array
hub_prune_partial_uploads(string $uploadsRoot, string $cutoff): array
```

Download opens the regular file first, takes `LOCK_SH`, rechecks DB state, streams, and updates `last_accessed_at`. Purge marks `purging`, takes `LOCK_EX`, repeats canonical root/symlink checks, deletes, and records freed bytes. Failed `realpath()` is never treated as permission to delete.

- [ ] **Step 4: Replace row-first pruning**

Make `scripts/prune_db.php --apply` purge expired `.partial` files, workspaces, sources, and artifacts before deleting audit metadata. Update `source_state`, `workspace_state`, task/artifact `purged_at`, and `freed_bytes` independently. Delete a task row only after 180 days and only when it has no available/pinned/held/referenced artifacts. Include `timed_out` while retaining compatibility with legacy `timeout` rows. Remove its existing `hub_migrate($db)` call; prune reports `schema_upgrade_required` instead of changing schema.

- [ ] **Step 5: Run tests and commit**

Run: `php scripts/run_tests.php`

Expected: `failures=0`.

```bash
git add app/task_artifacts.php app/gateway.php scripts/prune_db.php tests/test_task_artifacts_retention.php
git commit -m "feat: enforce managed artifact retention"
```

## Task 8: `audio-cleanup` Pack

**Files:**
- Create: `packs/audio-cleanup/pack.json`
- Create: `packs/audio-cleanup/docker-compose.yml`
- Create: `packs/audio-cleanup/jobs/audio_cleanup.sh`
- Create: `packs/audio-cleanup/service/Dockerfile`
- Create: `packs/audio-cleanup/service/requirements.txt`
- Create: `packs/audio-cleanup/service/job.py`
- Create: `packs/audio-cleanup/service/smoke.py`
- Modify: `packs/catalog.json`
- Modify: `tests/test_audio_packs.php`

- [ ] **Step 1: Add failing manifest matrix tests**

Assert the `cleanup` local job is GPU-required, uses only `operation=separate|enhance|separate_and_enhance`, allowlists `htdemucs`, and declares:

```json
{
  "required": ["cleanup_report"],
  "conditional": {
    "vocals_audio": "operation=separate|separate_and_enhance",
    "background_audio": "operation=separate|separate_and_enhance",
    "cleaned_audio": "operation=enhance|separate_and_enhance"
  }
}
```

Assert there is no CPU fallback and no fake `cleaned_audio` copy path.

The manifest uses `runtime.kind=job_container`, `execution_type=async_task`, and `hardware.min_vram_mb=6000`.

- [ ] **Step 2: Run the suite and confirm the Pack is absent**

Run: `php scripts/run_tests.php`

Expected: FAIL with `audio-cleanup pack missing`.

- [ ] **Step 3: Build the minimum Pack runner**

The shell entrypoint validates `AIHUB_WORKSPACE`, invokes one `docker run --rm --gpus device=0 --cidfile "$AIHUB_CONTAINER_ID_PATH"`, mounts only workspace/models/cache, and launches `job.py`.

`job.py` reads `request.json`, rejects disabled DeepFilterNet enhancement, runs Demucs/DeepFilterNet with argv arrays, writes only the operation-required WAV files plus `cleanup_report.json`, and exits nonzero on any missing output. Dry-run mode writes valid tiny WAV fixtures and the actual execution-chain report for dependency-free CI.

- [ ] **Step 4: Run Pack dry smoke and full PHP tests**

Run:

```bash
python3 packs/audio-cleanup/service/smoke.py
php scripts/run_tests.php
```

Expected: smoke prints `audio-cleanup smoke passed`; PHP reports `failures=0`.

- [ ] **Step 5: Commit**

```bash
git add packs/audio-cleanup packs/catalog.json tests/test_audio_packs.php
git commit -m "feat: add audio cleanup job Pack"
```

## Task 9: `whisper-asr` asynchronous transcription job

**Files:**
- Modify: `packs/whisper-asr/pack.json`
- Create: `packs/whisper-asr/jobs/speech_transcribe.sh`
- Create: `packs/whisper-asr/service/job.py`
- Modify: `packs/whisper-asr/service/requirements.txt`
- Modify: `packs/whisper-asr/service/Dockerfile`
- Modify: `packs/whisper-asr/service/smoke.py`
- Modify: `tests/test_audio_packs.php`

- [ ] **Step 1: Add failing model-loading and artifact tests**

Assert the `transcribe` job contract rejects speaker bounds when `diarization=0`, enforces `min_speakers <= max_speakers`, and declares required `transcript_json`/`transcription_report`, conditional SRT/VTT/speaker timeline.

Use injected fake loaders to assert:

```text
ASR always loads
alignment loads only for word_timestamps/output alignment
pyannote loads only for diarization
speaker_timeline exists only for diarization
```

The manifest keeps its sync `asr` service and adds `hardware.min_vram_mb=10000` for the GPU job contract.

- [ ] **Step 2: Run the tests and confirm the local job is absent**

Run: `php scripts/run_tests.php`

Expected: FAIL with `whisper-asr missing transcribe local job`.

- [ ] **Step 3: Implement the job runner without changing the sync route semantics**

Add pinned WhisperX/pyannote dependencies. The job runner reads the managed source path from workspace input, obtains the pyannote token only from `AIHUB_SECRET_PYANNOTE_TOKEN`, and never logs it. Keep model aliases in the manifest allowlist.

Write transcript/report JSON and requested subtitles. Load ASR unconditionally, alignment conditionally, and pyannote conditionally. Any required output failure exits nonzero; there is no CPU model fallback.

- [ ] **Step 4: Run dry smoke and tests**

Run:

```bash
python3 packs/whisper-asr/service/smoke.py
php scripts/run_tests.php
```

Expected: smoke verifies all three loading combinations; PHP reports `failures=0`.

- [ ] **Step 5: Commit**

```bash
git add packs/whisper-asr tests/test_audio_packs.php
git commit -m "feat: add Whisper asynchronous transcription job"
```

## Task 10: `tts-voxcpm2` deterministic long-form job

**Files:**
- Modify: `packs/tts-voxcpm2/pack.json`
- Create: `packs/tts-voxcpm2/jobs/voice_generate.sh`
- Create: `packs/tts-voxcpm2/service/long_form.py`
- Create: `packs/tts-voxcpm2/service/job.py`
- Create: `packs/tts-voxcpm2/service/long_form_smoke.py`
- Modify: `packs/tts-voxcpm2/service/app.py`
- Modify: `tests/test_tts_voxcpm2.php`

- [ ] **Step 1: Add failing design/clone and deterministic-plan tests**

Assert design rejects `voice_profile_id`; clone requires one owned managed profile; host paths and arbitrary model settings are rejected. Assert one task fixes voice profile/anchor, model alias, controls, format, and seed policy.

The existing manifest retains `hardware.min_vram_mb=16000` and adds only the `synthesize` local job; no second TTS Pack is created.

Golden cases must prove `semantic-v1` does not split units, abbreviations, names, balanced quotes/brackets, or mixed Chinese/English phrases. Running the planner twice with identical input must produce byte-identical `chunks.json`; derived seeds must use SHA-256 of task seed plus chunk ID.

- [ ] **Step 2: Run tests and confirm the long-form module is absent**

Run: `php scripts/run_tests.php`

Expected: FAIL because `long_form.py` and the `synthesize` job are absent.

- [ ] **Step 3: Port only VoxCPM2-specific long-form behavior**

Implement in `long_form.py`:

- normalization and immutable semantic chunk planning;
- checkpoint validation by plan/text/voice/model hashes;
- `fixed` and `derived_per_chunk` seeds;
- at most three chunk attempts;
- boundary decisions `direct_concat`, `silence_insert`, `crossfade`, `trim_then_pause`, `regenerate_chunk`;
- bounded punctuation pauses and 10–40 ms conditional crossfade;
- one final global loudness pass;
- sample-accurate chunk timeline and required `synthesis_metadata`.

Do not create a Hub-wide voice library. `job.py` loads/resolves voice conditioning once, synthesizes missing chunks, reuses valid checkpoints, assembles final WAV, and writes `generated_audio` plus metadata. Word-level forced alignment remains absent.

- [ ] **Step 4: Run deterministic smoke and PHP tests**

Run:

```bash
python3 packs/tts-voxcpm2/service/long_form_smoke.py
php scripts/run_tests.php
```

Expected: smoke reports deterministic plan/checkpoint/assembly checks passed; PHP reports `failures=0`.

- [ ] **Step 5: Commit**

```bash
git add packs/tts-voxcpm2 tests/test_tts_voxcpm2.php
git commit -m "feat: add VoxCPM2 long-form job"
```

## Task 11: Diagnostic sync limits and operator documentation

**Files:**
- Modify: `app/gateway.php`
- Modify: `docs/pack_runtime_contract_v0.1.md`
- Modify: `docs/local_job_contract_v0.1.md`
- Modify: `docs/api_examples.md`
- Modify: `docs/client_quickstart.md`
- Modify: `README.md`
- Modify: `tests/test_gateway.php`
- Modify: `tests/test_api_examples.php`

- [ ] **Step 1: Add failing sync-boundary tests**

Assert `asr`/`tts` reject over 30 seconds, oversized upload, callback fields, and source artifact chaining; a busy sync slot returns `sync_busy`; an oversized request returns `async_required` naming `speech_transcribe` or `voice_generate`; no sync request silently creates a task.

- [ ] **Step 2: Run the suite and confirm the limit is not enforced**

Run: `php scripts/run_tests.php`

Expected: FAIL in the new sync-boundary test.

- [ ] **Step 3: Add the small sync admission guard**

Use one shared `hub_validate_audio_sync_request()` in `app/gateway.php` before proxying current ASR/TTS services. Read duration with ffprobe outside any transaction. Create a provisional diagnostic `runtime_runs` row and acquire the same `gpu:0` resource; if unavailable, return `sync_busy`. After proxying, stop/remove the diagnostic service container, prove its owned GPU PID is gone, and only then release `gpu:0`. Cleanup uncertainty returns `cleanup_failed` and leaves the resource blocked. Do not add a daemon, lock file, or another queue.

- [ ] **Step 4: Document exact public contracts and deployment order**

Add multipart examples for all three async modes, callback signature verification, polling fallback, artifact download/ACK, source artifact chaining, retention defaults, `php scripts/init_db.php`, callback worker, prune worker, and real acceptance command.

- [ ] **Step 5: Run tests and commit**

Run: `php scripts/run_tests.php`

Expected: `failures=0`.

```bash
git add app/gateway.php docs README.md tests/test_gateway.php tests/test_api_examples.php
git commit -m "docs: publish asynchronous audio task contracts"
```

## Task 12: MyAI callback client and staged Hub cutover

**Files:**
- Create: `/var/www/html/myai/myai_voice/voice_aihub.php`
- Modify: `/var/www/html/myai/myai_voice/voice_common.php`
- Modify: `/var/www/html/myai/myai_voice/api.php`
- Modify: `/var/www/html/myai/myai_voice/crontab/1min_step1_run_demucs.php`
- Modify: `/var/www/html/myai/myai_voice/crontab/1min_step2_run_whisper.php`
- Modify: `/var/www/html/myai/myai_voice/crontab/1min.sh`
- Create: `/var/www/html/myai/myai_voice/tests/myai_voice_aihub_static_test.php`
- Create: `/var/www/html/myai/myai_voice/tests/myai_voice_aihub_callback_behavior_test.php`

- [ ] **Step 1: Write failing MyAI static and behavior tests**

Assert the client reads only environment settings:

```text
MYAI_VOICE_AIHUB_URL
MYAI_VOICE_AIHUB_TOKEN
MYAI_VOICE_AIHUB_CALLBACK_SECRET
MYAI_VOICE_AIHUB_ENABLED
```

Assert no credential literal beginning with the Hugging Face token prefix remains in `voice_common.php`. Callback behavior must reject stale timestamp/bad HMAC, return 200 for a repeated delivery ID, and store delivery/task/event state in existing `job_options`.

- [ ] **Step 2: Run MyAI tests and confirm the client is absent**

Run:

```bash
php /var/www/html/myai/myai_voice/tests/myai_voice_aihub_static_test.php
php /var/www/html/myai/myai_voice/tests/myai_voice_aihub_callback_behavior_test.php
```

Expected: FAIL because `voice_aihub.php` does not exist.

- [ ] **Step 3: Add the minimal Hub client and rotate the exposed secret**

Implement `myai_voice_aihub_request()`, multipart submit, task poll, artifact download with SHA verification, HMAC verification, and delivery deduplication. Store Hub task IDs, artifact IDs, delivery IDs, and import state using `myai_voice_upsert_option()`; do not add a table.

Change `myai_voice_hf_token()` to return only `MYAI_VOICE_HF_TOKEN`/`HF_TOKEN`. Rotate the previously embedded credential outside the repository before real diarization acceptance.

- [ ] **Step 4: Add the public callback before password auth**

Add `aihub_task_callback` to `myai_voice_api_public_modes()`, but make it accept POST JSON only and authenticate the exact raw body with timestamp/HMAC before reading task data. Reject timestamps older than 300 seconds. Return HTTP 200 for a known duplicate delivery. On a new terminal event, record it and queue local artifact import; do not download large artifacts inside the callback request.

- [ ] **Step 5: Cut over cleanup and ASR behind one switch**

When `MYAI_VOICE_AIHUB_ENABLED=1`, Step 1 submits `audio_cleanup` once and polls/imports `vocals_audio`, `background_audio`, and optional `cleaned_audio`; Step 2 submits `speech_transcribe` using the cleanup artifact ID when available and imports JSON/SRT/VTT/speaker timeline. Existing YouTube subtitle import remains local and bypasses Hub ASR.

When the switch is off, preserve the current local path during acceptance. Do not retain CPU fallback in the final enabled Hub path.

- [ ] **Step 6: Route existing Character TTS APIs through `voice_generate`**

When the switch is enabled, `character_tts_generate` submits one `voice_generate` task, `character_tts_status` returns the Hub task state, and `character_tts_result` downloads/imports `generated_audio`, `synthesis_metadata`, and optional `waveform_preview`. Preserve the current Character TTS URL path only while the switch is off. Do not move article/character/paragraph arrangement into Hub.

- [ ] **Step 7: Add one reconciliation poll per minute**

The cron path checks only MyAI jobs with a stored nonterminal Hub task ID. Callback remains primary; polling repairs missed delivery and supports diagnostics. It must not resubmit when a Hub task ID already exists.

- [ ] **Step 8: Run MyAI tests and commit in the MyAI repository**

Run the two new tests plus existing MyAI voice static tests affected by Demucs/Whisper behavior. Expected: all exit 0.

```bash
cd /var/www/html/myai
git add myai/myai_voice/voice_aihub.php myai/myai_voice/voice_common.php myai/myai_voice/api.php myai/myai_voice/crontab myai/myai_voice/tests/myai_voice_aihub_static_test.php myai/myai_voice/tests/myai_voice_aihub_callback_behavior_test.php
git commit -m "feat: use 3waAIHub audio tasks"
```

## Task 13: Real 5060 Ti acceptance and staged enablement

**Files:**
- Create: `scripts/audio_packs_acceptance.php`
- Modify: `README.md`
- Modify: `history.md`
- Modify: `tests/test_audio_packs.php`

- [ ] **Step 1: Write the acceptance command contract test**

Add a PHP test that asserts `scripts/audio_packs_acceptance.php` supports:

```text
--pack audio-cleanup|whisper-asr|tts-voxcpm2|all
--fixture <path>
--callback-target <alias>
--voice-profile-id <managed-id>
--json
```

The script must refuse to run without NVIDIA/Docker readiness and must never substitute mock output for a real acceptance request.

- [ ] **Step 2: Implement the thin acceptance client**

Reuse the public async API: submit, poll, download artifacts, verify SHA, and print measured peak VRAM/duration plus final task/artifact states. Do not invoke Pack runners directly.

- [ ] **Step 3: Run dependency-free verification**

Run:

```bash
php scripts/run_tests.php
git diff --check
```

Expected: `failures=0`; no whitespace errors.

- [ ] **Step 4: Run real station acceptance serially**

Run audio cleanup, ASR without diarization, ASR with diarization, VoxCPM2 design, managed clone, long-form checkpoint resume, and MyAI callback/download/chaining. Confirm only one heavy CUDA container runs at once and `gpu:0` returns to `available` with no owned PID after every job.

Tune only manifest `min_vram_mb` and `AIHUB_GPU_VRAM_SAFETY_MARGIN_MB` from measured results. If cleanup attribution is uncertain, leave the resource `blocked` and stop acceptance.

- [ ] **Step 5: Enable MyAI one capability at a time**

Enable cleanup first, then ASR, then TTS. For each capability, process one short fixture and one real long input, compare imported files/metadata with the current MyAI path, and retain the switch-off rollback until the comparison passes.

- [ ] **Step 6: Commit Hub acceptance tooling and release notes**

```bash
git add scripts/audio_packs_acceptance.php README.md history.md
git commit -m "test: add real audio Pack acceptance"
```

## Final verification gate

Before declaring the feature complete:

1. Run `php scripts/init_db.php` twice.
2. Run `php scripts/run_tests.php` and require `failures=0`.
3. Run the affected MyAI tests and require exit 0.
4. Run `git diff --check` in both repositories.
5. Run `php scripts/audio_packs_acceptance.php --pack all --fixture packs/whisper-asr/demo/sample.wav --callback-target myai --voice-profile-id voice_profile_1 --json` on the RTX 5060 Ti after creating the approved acceptance voice profile.
6. Confirm callback replay is idempotent, source-artifact chaining works, ACK does not delete early, and purge returns 410 after expiry.
7. Confirm no CUDA job starts when `gpu:0` is `leased`, `recovery_required`, or `blocked`.
8. Confirm no embedded credential, public host path, Pack entrypoint, arbitrary command, or callback URL appears in task payloads/logs/API output.
