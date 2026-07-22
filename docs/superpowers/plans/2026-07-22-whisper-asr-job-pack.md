# Whisper ASR Job Pack Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (- [ ]) syntax for tracking.

**Goal:** Upgrade the existing whisper-asr Pack into the governed speech_transcribe asynchronous GPU job while preserving its diagnostic sync API.

**Architecture:** Add the approved shared task metadata, artifact, callback, retention, and GPU-lease primitives once, then route speech_transcribe to the generic pack_job worker. The Pack runner is a one-shot Docker process with only manifest-allowed inputs and workspace artifacts; it ports the proven ASR presets from MyAI Voice but never imports MyAI application code.

**Tech Stack:** PHP 8, SQLite, existing Hub task/runtime helpers, Docker + NVIDIA Container Toolkit, Python 3.11, faster-whisper 1.2.1, WhisperX, pyannote, ffmpeg/ffprobe.

---

## Scope and file map

This plan implements the shared foundations required by the approved contract for one Pack. It deliberately does not add audio-cleanup, VoxCPM2 async jobs, or the MyAI cutover; they reuse these primitives later.

| File | Responsibility |
| --- | --- |
| app/db.php | idempotent task, artifact, callback, and gpu:0 lease schema |
| app/pack_registry.php | fixed public route and manifest job validation |
| app/task_queue.php | owned Pack tasks, artifact lineage, terminal state helpers |
| app/runtime_worker.php | fenced host GPU lease and recovery transitions |
| app/task_artifacts.php | output validation, registry, retention holds, safe purge |
| app/task_callbacks.php | signed callback outbox and retry schedule |
| app/pack_jobs.php | generic managed Pack-job orchestration |
| app/gateway.php | speech_transcribe, owned task/artifact APIs, ACK/pin |
| app/bootstrap.php | loads the three focused shared modules |
| bin/aihub-run | attaches to the already-owned managed runtime run |
| scripts/task_worker.php | dispatches the one generic pack_job type |
| scripts/callback_worker.php | sends committed callback deliveries |
| scripts/prune_db.php | physical retention purge before metadata deletion |
| packs/whisper-asr/* | manifest, one-shot job runner, Python transcriber, tests |

### Task 1: Make the schema and default policy idempotent

**Files:**
- Modify: app/db.php
- Modify: app/storage.php
- Modify: scripts/task_worker.php, scripts/prune_db.php, bin/aihub-run
- Create: tests/test_whisper_job_schema.php

- [ ] **Step 1: Write the migration regression test**

~~~php
hub_test('Whisper job schema is idempotent and preserves a legacy task', function (): void {
    $db = hub_test_reset_db();
    $legacy = hub_enqueue_task($db, 'demo_task', 'default', 0, ['legacy' => true], null, '127.0.0.1');
    hub_migrate($db);
    hub_migrate($db);

    foreach (['task_callback_targets', 'task_callback_deliveries', 'runtime_resource_leases'] as $table) {
        $exists = $db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name=" . $db->quote($table))->fetchColumn();
        hub_test_assert($exists !== false, 'missing ' . $table);
    }
    foreach (['owner_member_id', 'requested_mode', 'pack_id', 'pack_version', 'job', 'runtime_mode', 'accelerator', 'source_artifact_id', 'source_task_id', 'waiting_reason', 'next_attempt_at', 'source_expires_at', 'workspace_expires_at'] as $column) {
        $columns = array_column($db->query('PRAGMA table_info(tasks)')->fetchAll(), 'name');
        hub_test_assert(in_array($column, $columns, true), 'missing tasks.' . $column);
    }
    $gpu = $db->query("SELECT state FROM runtime_resource_leases WHERE resource_key='gpu:0'")->fetchColumn();
    hub_test_assert($gpu === 'available', 'gpu:0 must start available');
    hub_test_assert(hub_get_task($db, $legacy) !== null, 'legacy task was lost');
});
~~~

- [ ] **Step 2: Run the test to verify it fails**

Run: php scripts/run_tests.php

Expected: the new test fails because callback/resource tables and task columns are absent.

- [ ] **Step 3: Add all data changes only in hub_migrate()**

Add these task columns with hub_add_column_if_missing():

~~~php
foreach ([
    'owner_member_id' => 'INTEGER NULL', 'owner_token_id' => 'INTEGER NULL',
    'requested_mode' => 'TEXT NULL', 'pack_id' => 'TEXT NULL',
    'pack_version' => 'TEXT NULL', 'job' => 'TEXT NULL',
    'runtime_mode' => 'TEXT NULL', 'accelerator' => 'TEXT NULL',
    'route_resolved_at' => 'TEXT NULL', 'source_artifact_id' => 'INTEGER NULL',
    'source_task_id' => 'INTEGER NULL', 'retry_of_task_id' => 'INTEGER NULL',
    'callback_target_id' => 'INTEGER NULL', 'waiting_reason' => 'TEXT NULL',
    'next_attempt_at' => 'TEXT NULL', 'error_code' => 'TEXT NULL',
    'source_expires_at' => 'TEXT NULL', 'workspace_expires_at' => 'TEXT NULL',
    'source_state' => "TEXT NOT NULL DEFAULT 'available'",
    'workspace_state' => "TEXT NOT NULL DEFAULT 'active'",
    'retention_state' => "TEXT NOT NULL DEFAULT 'active'",
    'purged_at' => 'TEXT NULL', 'freed_bytes' => 'INTEGER NOT NULL DEFAULT 0',
] as $column => $definition) {
    hub_add_column_if_missing($db, 'tasks', $column, $definition);
}
~~~

Add the equivalent artifact fields: artifact_type, sha256, expires_at, state,
pinned_at, legal_hold, acknowledged_at, last_accessed_at, purged_at, and
purge_error. Add runtime_runs task_id, nullable attempt_no,
gpu_process_baseline_json, and owned_gpu_pids_json.

Create task_callback_targets, task_callback_deliveries, and
runtime_resource_leases through CREATE TABLE IF NOT EXISTS. Seed only this
fixed resource without overwriting a recovery state:

~~~php
$db->exec(
    "INSERT OR IGNORE INTO runtime_resource_leases (resource_key, state, updated_at)
     VALUES ('gpu:0', 'available', " . $db->quote(hub_now()) . ')'
);
~~~

Add indexes for callback delivery (delivered_at, next_attempt_at), callback
task_id, resource lease (state, lease_expires_at), and runtime_run_id. Add
hub_runtime_schema_missing(PDO $db): array as a read-only compatibility check.

Replace worker/pruner/runner hub_migrate() calls with the check and the exact
error schema_upgrade_required: php scripts/init_db.php. Only init_db.php may
migrate after deployment.

Add policy defaults: source retention 7 days, workspace 24 hours, artifact 30
days, partial 1 hour, metadata 180 days, VRAM margin 256 MB, runtime attempts
2, sync audio limit 30 seconds, sync upload limit 10485760 bytes, and sync
concurrency 1.

- [ ] **Step 4: Verify twice and commit**

Run: php scripts/init_db.php && php scripts/init_db.php && php scripts/run_tests.php

Expected: both migrations exit 0 and the suite reports failures=0.

~~~bash
git add app/db.php app/storage.php scripts/task_worker.php scripts/prune_db.php bin/aihub-run tests/test_whisper_job_schema.php
git commit -m "feat: add managed audio task schema"
~~~

### Task 2: Add fixed routing, ownership, and source-artifact lineage

**Files:**
- Modify: app/pack_registry.php, app/task_queue.php, app/gateway.php
- Create: tests/test_speech_transcribe_gateway.php

- [ ] **Step 1: Write route and admission tests**

~~~php
hub_test('speech_transcribe snapshots its fixed Pack route', function (): void {
    $db = hub_test_reset_db();
    $routes = hub_audio_async_routes();
    hub_test_assert($routes['speech_transcribe'] === ['pack_id' => 'whisper-asr', 'job' => 'transcribe'], 'route mismatch');

    $route = hub_resolve_audio_async_route($db, 'speech_transcribe');
    hub_test_assert($route['runtime_mode'] === 'job' && $route['accelerator'] === 'gpu', 'route is not a GPU job');
});
~~~

Create two API members. Member A can submit an audio upload and reuse A's
available audio artifact; member B receives 404 for A's task or artifact.
Requests containing pack_id, pack_version, job, runtime_mode, accelerator,
entrypoint, command, script, or any key ending in _path return 400
forbidden_task_control.

- [ ] **Step 2: Run the test to verify it fails**

Run: php scripts/run_tests.php

Expected: failure for undefined hub_audio_async_routes().

- [ ] **Step 3: Implement one saved route and strict admission**

~~~php
function hub_audio_async_routes(): array
{
    return ['speech_transcribe' => ['pack_id' => 'whisper-asr', 'job' => 'transcribe']];
}
~~~

Implement hub_resolve_audio_async_route() to validate the installed
whisper-asr service and exact saved Pack version/job. Return pack_not_installed
or pack_version_unavailable; never silently select a newer manifest.

Extend manifest validation for managed local jobs: safe job_key, existing
relative entrypoint, explicit input schema, and artifacts.required plus
artifacts.conditional. Keep the current sync compose service valid for
diagnostic asr.

Add pack_job to hub_allowed_task_types(). Extend hub_enqueue_task() with an
optional server-only metadata array. Save owner member/token, resolved route,
and retention fields in columns. Add hub_task_owned_by_auth() using
owner_member_id. Manual retry always inserts a new task with retry_of_task_id.

Implement hub_api_speech_transcribe_submit(): accept exactly one multipart
audio file or source_artifact_id; validate source ownership, available state,
expiry, artifact allowlist, canonical regular-file path; save source artifact
and source task lineage; then enqueue one gpu pack_job.

Pass auth context to task status/result/log/cancel/retry/ACK/pin/artifact
handlers. A different member receives 404, not a resource-existence leak.

- [ ] **Step 4: Verify and commit**

Run: php scripts/run_tests.php

Expected: failures=0.

~~~bash
git add app/pack_registry.php app/task_queue.php app/gateway.php tests/test_speech_transcribe_gateway.php
git commit -m "feat: route owned speech transcription tasks"
~~~

### Task 3: Add durable artifacts, callback outbox, and retention rules

**Files:**
- Create: app/task_artifacts.php, app/task_callbacks.php, scripts/callback_worker.php
- Modify: app/bootstrap.php, app/gateway.php, scripts/prune_db.php
- Create: tests/test_speech_task_artifacts.php, tests/test_speech_task_callbacks.php

- [ ] **Step 1: Write artifact and callback tests**

~~~php
hub_test('validated transcription artifacts are registered before task success', function (): void {
    $workspace = hub_test_workspace('transcribe');
    mkdir($workspace . '/output', 0775, true);
    file_put_contents($workspace . '/output/transcript.json', "{}\n");
    file_put_contents($workspace . '/output/report.json', "{}\n");
    $manifest = hub_get_pack('whisper-asr')['manifest'];
    $items = hub_validate_pack_job_artifacts($manifest, ['output_srt' => false, 'output_vtt' => false], $workspace);
    hub_test_assert(array_column($items, 'artifact_type') === ['transcript_json', 'transcription_report'], 'required artifacts mismatch');
});

hub_test('callback retry backoff is bounded', function (): void {
    hub_test_assert(hub_callback_retry_delay(1) === 30, 'first retry must be 30 seconds');
    hub_test_assert(hub_callback_retry_delay(5) === 3600, 'fifth retry must be one hour');
    hub_test_assert(hub_callback_retry_delay(6) === null, 'retry ceiling missing');
});
~~~

Add cases for missing transcript/report, symlink escape, invalid report JSON,
wrong MIME, source retention hold, ACK's 24-hour minimum, pin/legal hold, and
a purged artifact returning HTTP 410.

- [ ] **Step 2: Run the tests to verify they fail**

Run: php scripts/run_tests.php

Expected: failures for undefined artifact and callback helpers.

- [ ] **Step 3: Implement validation, outbox, and safe purge**

hub_validate_pack_job_artifacts() must canonicalize every path inside the
workspace; reject symlinks/non-regular files; detect MIME with finfo; calculate
SHA-256/size; parse JSON reports; and ffprobe audio. Missing required or
conditional outputs throws output_contract_invalid.

hub_finish_pack_job_terminal() performs one short transaction: verify runtime
and GPU fencing tokens; register verified artifacts; finish runtime/task; insert
the callback outbox row; release gpu:0. It never hashes files, invokes Docker,
runs ffprobe, or sends a callback inside that transaction.

Use registered callback aliases only. Compute the signature as:

~~~php
function hub_callback_signature(string $secret, string $timestamp, string $body): string
{
    return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
}

function hub_callback_retry_delay(int $attemptCount): ?int
{
    return [1 => 30, 2 => 120, 3 => 600, 4 => 3600, 5 => 3600][$attemptCount] ?? null;
}
~~~

callback_worker claims deliveries transactionally, posts after commit, and
marks delivered only for HTTP 2xx. Payloads contain delivery ID, task state,
and artifact IDs; never large artifacts or a secret.

The pruner separately expires workspace after 24 hours, source after 7 days,
and results after 30 days. It preserves an artifact while a downstream task is
nonterminal. It marks purging, takes an exclusive lock, repeats canonical path
checks, then unlinks. Any failure records purge_failed and does not guess.

- [ ] **Step 4: Verify and commit**

Run: php scripts/run_tests.php

Expected: failures=0.

~~~bash
git add app/task_artifacts.php app/task_callbacks.php app/bootstrap.php app/gateway.php scripts/callback_worker.php scripts/prune_db.php tests/test_speech_task_artifacts.php tests/test_speech_task_callbacks.php
git commit -m "feat: validate and retain Pack job artifacts"
~~~

### Task 4: Add the fenced host gpu:0 lease

**Files:**
- Modify: app/runtime_worker.php
- Create: tests/test_speech_gpu_lease.php

- [ ] **Step 1: Write race and recovery tests**

~~~php
hub_test('only one worker can acquire gpu:0', function (): void {
    $db = hub_test_reset_db();
    $first = hub_gpu_lease_acquire($db, 'run-a', 'worker-a', 60);
    $second = hub_gpu_lease_acquire($db, 'run-b', 'worker-b', 60);
    hub_test_assert(is_array($first), 'first worker must lease');
    hub_test_assert($second === null, 'second worker must not lease');
    hub_test_assert(!hub_gpu_lease_release($db, 'run-a', 'worker-a', 'wrong-token'), 'wrong token released GPU');
    hub_test_assert(hub_gpu_lease_release($db, 'run-a', 'worker-a', $first['lease_token']), 'owner could not release GPU');
});
~~~

Add stale fixtures showing expiry changes leased to recovery_required, never
directly available. Only clean recorded container/PID evidence recovers it.
Unknown GPU PIDs yield blocked.

- [ ] **Step 2: Run the tests to verify they fail**

Run: php scripts/run_tests.php

Expected: undefined hub_gpu_lease_acquire().

- [ ] **Step 3: Implement fenced lease and dual heartbeat**

Add hub_gpu_lease_acquire(), hub_gpu_lease_heartbeat(),
hub_gpu_lease_release(), hub_gpu_lease_mark_recovery_required(), and
hub_gpu_lease_mark_blocked(). Every mutation filters resource_key,
runtime_run_id, worker_id, lease_token, and state; rowCount other than one
means ownership is lost and the worker stops.

Update runtime and GPU heartbeat in the same BEGIN IMMEDIATE transaction.
Gather nvidia-smi, VRAM, container, and process evidence outside the
transaction. Low VRAM/unmanaged process returns a task to waiting_gpu with
backoff and releases the unused lease without consuming an attempt.

Record baseline GPU PIDs, container ID, and only run-owned PIDs. Recovery may
remove only the recorded container; it never kills unknown host processes.

- [ ] **Step 4: Verify and commit**

Run: php scripts/run_tests.php

Expected: failures=0.

~~~bash
git add app/runtime_worker.php tests/test_speech_gpu_lease.php
git commit -m "feat: fence GPU jobs with persistent lease"
~~~

### Task 5: Execute one managed generic Pack job

**Files:**
- Create: app/pack_jobs.php, tests/test_speech_pack_job_worker.php
- Modify: app/bootstrap.php, scripts/task_worker.php, bin/aihub-run

- [ ] **Step 1: Write a dry-run adapter test**

~~~php
hub_test('pack_job runs the saved transcription route only once', function (): void {
    $db = hub_test_reset_db();
    $taskId = hub_test_enqueue_speech_task($db, ['dry_run' => true]);
    $task = hub_claim_next_task($db);
    $runner = static function (array $context): array {
        $output = $context['workspace'] . '/output';
        mkdir($output, 0775, true);
        file_put_contents($output . '/transcript.json', "{}\n");
        file_put_contents($output . '/report.json', "{}\n");
        return ['exit_code' => 0, 'container_removed' => true, 'owned_gpu_pids_gone' => true];
    };
    hub_run_pack_job_task($db, $task, ['runner' => $runner]);
    $done = hub_get_task($db, $taskId);
    hub_test_assert($done['status'] === 'success', 'task did not finish');
    hub_test_assert(($done['result']['pack_id'] ?? '') === 'whisper-asr', 'wrong Pack executed');
});
~~~

Add a cleanup failure fixture: valid output before container/PID cleanup must
finish failed with cleanup_failed and leave GPU blocked. Add cancel and timeout
fixtures proving the terminal transition uses the same fencing function.

- [ ] **Step 2: Run the test to verify it fails**

Run: php scripts/run_tests.php

Expected: undefined hub_run_pack_job_task().

- [ ] **Step 3: Implement generic orchestration and managed runner mode**

hub_run_pack_job_task() resolves only the fields saved on the task. It creates
a provisional runtime run, claims runtime/GPU lease, runs preflight, starts the
manifest job, verifies container removal and owned PID disappearance, validates
artifacts, and calls hub_finish_pack_job_terminal().

Add --managed-run-id and --managed-lease-token to bin/aihub-run. In managed
mode it checks the run is already owned, writes workspace/run logs, executes
the manifest entrypoint, and returns its exit code. It must not migrate,
create another runtime row, terminally update the runtime, or release GPU.

Add only this task-worker branch:

~~~php
if ((string)$task['task_type'] === 'pack_job') {
    hub_run_pack_job_task($db, $task);
    return;
}
~~~

No whisper-specific PHP worker is created.

- [ ] **Step 4: Verify and commit**

Run: php scripts/run_tests.php

Expected: failures=0.

~~~bash
git add app/pack_jobs.php app/bootstrap.php scripts/task_worker.php bin/aihub-run tests/test_speech_pack_job_worker.php
git commit -m "feat: execute managed Pack jobs"
~~~

### Task 6: Implement the WhisperX/faster-whisper Pack job

**Files:**
- Modify: packs/whisper-asr/pack.json, packs/whisper-asr/service/Dockerfile, packs/whisper-asr/service/requirements.txt, packs/whisper-asr/service/smoke.py
- Create: packs/whisper-asr/jobs/speech_transcribe.sh, packs/whisper-asr/service/job.py, packs/whisper-asr/service/test_job.py
- Create: tests/test_whisper_asr_pack.php

- [ ] **Step 1: Write the model-loading and contract tests**

~~~python
def test_model_loading_matrix(monkeypatch, tmp_path):
    events = []
    result = job.transcribe(tmp_path / "input.wav", {"word_timestamps": False, "diarization": False}, loaders=fake_loaders(events))
    assert events == ["asr"]
    assert "speaker_timeline" not in result["artifacts"]

    job.transcribe(tmp_path / "input.wav", {"word_timestamps": True, "diarization": False}, loaders=fake_loaders(events := []))
    assert events == ["asr", "alignment"]

    job.transcribe(tmp_path / "input.wav", {"word_timestamps": False, "diarization": True}, loaders=fake_loaders(events := []))
    assert events == ["asr", "pyannote"]
~~~

PHP tests assert transcribe declares required transcript_json and
transcription_report; conditional SRT/VTT/timeline; and admission rejects
speaker bounds with diarization false or min_speakers greater than
max_speakers.

- [ ] **Step 2: Run the tests to verify they fail**

Run: python3 -m unittest -v packs/whisper-asr/service/test_job.py && php scripts/run_tests.php

Expected: test_job.py is missing and PHP reports missing transcribe job.

- [ ] **Step 3: Add the manifest, Docker wrapper, and Python runner**

Add runtime_contract 0.1, runtime_modes service/job, managed_job true, and one
transcribe local job with gpu required, timeout 7200, and min_vram_mb 10000.
Its manifest allowlist is model aliases large-v3/medium; language auto/nan/zh/
en/ja/ko; booleans word_timestamps, diarization, output_srt, output_vtt; and
speaker limits 1 through 16.

The shell wrapper validates workspace-relative input, reads the prebuilt image
from AIHUB_WHISPER_IMAGE, runs Docker as worker UID, mounts workspace read-write
and Hub models/whisper read-only, passes only GPU index and
AIHUB_SECRET_PYANNOTE_TOKEN, uses --cidfile, and traps TERM/INT to remove its
container. It does not mount /park/conda_vm or MyAI source.

job.py writes output/transcript.json, output/transcription-report.json, and
requested subtitle/timeline files. ASR always loads; alignment loads only for
word timestamps; pyannote loads only for diarization. The nan preset keeps the
proven Taiwan mixed-language prompt. The report records model/version, device,
compute type, language, preset, duration, and artifact list; it never records
the pyannote token. Scheduled jobs have no CPU fallback.

Pin faster-whisper==1.2.1 with compatible WhisperX/pyannote and existing CUDA
CTranslate2 libraries. Extend Docker build import/unit smoke without model
download.

- [ ] **Step 4: Verify Pack tests and commit**

Run:

~~~bash
python3 -m unittest -v packs/whisper-asr/service/test_job.py
python3 packs/whisper-asr/service/smoke.py
php scripts/run_tests.php
~~~

Expected: all exit 0 and PHP reports failures=0.

~~~bash
git add packs/whisper-asr tests/test_whisper_asr_pack.php
git commit -m "feat: add Whisper transcription Pack job"
~~~

### Task 7: Import models and stage real acceptance

**Files:**
- Create: scripts/import_whisper_models.sh, scripts/whisper_asr_acceptance.php
- Modify: docs/pack_runtime_contract_v0.1.md, README.md
- Modify: tests/test_whisper_asr_pack.php

- [ ] **Step 1: Write import and acceptance dry-run tests**

~~~php
hub_test('Whisper model import refuses unsafe source and keeps existing files', function (): void {
    $run = hub_run_command(['bash', HUB_ROOT . '/scripts/import_whisper_models.sh', '--dry-run', '--source', '/tmp/not-models'], 30);
    hub_test_assert($run['exit_code'] !== 0, 'unsafe source accepted');
});
~~~

Acceptance dry-run checks Pack installation/version, Docker GPU capability,
model aliases, free VRAM plus safety margin, and command availability without
mutating the task DB or model store.

- [ ] **Step 2: Run the test to verify it fails**

Run: php scripts/run_tests.php

Expected: test fails because import_whisper_models.sh does not exist.

- [ ] **Step 3: Implement controlled model import and real smoke**

The importer accepts only a canonical existing source under
/opt/models/faster-whisper. It copies only regular files to
AIHUB_MODELS_DIR/whisper, retains matching destination SHA-256 files, copies
new files through a temporary sibling and atomic rename, supports --dry-run,
and never deletes source/destination data.

whisper_asr_acceptance.php --real submits a short fixture to speech_transcribe.
It verifies terminal success, expected artifact hashes, callback delivery to a
local receiver, gpu:0 available, removed container ID, and no owned NVIDIA PID.
A real smoke remains separate from normal CI.

Document deployment:

~~~bash
php scripts/init_db.php
bash scripts/import_whisper_models.sh --source /opt/models/faster-whisper
php scripts/whisper_asr_acceptance.php --real
~~~

State that asr is diagnostic only and production clients use
mode=speech_transcribe.

- [ ] **Step 4: Verify and commit**

Run:

~~~bash
php scripts/run_tests.php
php scripts/whisper_asr_acceptance.php --dry-run
~~~

Expected: suite reports failures=0 and dry acceptance has no mutations.

~~~bash
git add scripts/import_whisper_models.sh scripts/whisper_asr_acceptance.php docs/pack_runtime_contract_v0.1.md README.md tests/test_whisper_asr_pack.php
git commit -m "docs: add Whisper Pack deployment acceptance"
~~~

## Plan self-review

- Spec coverage: route/ownership (Task 2), lifecycle/callback/retention (Tasks
  1 and 3), GPU fencing/cleanup (Task 4), generic job path (Task 5),
  conditional ASR/alignment/diarization plus artifacts (Task 6), and real 5060
  Ti validation (Task 7) are all assigned.
- Scope: MyAI cutover, cleanup Pack, and VoxCPM2 job work are intentionally
  absent; they reuse this shared runtime.
- Naming consistency: public mode is speech_transcribe; its Pack/job pair is
  whisper-asr/transcribe; the only new queue type is pack_job; resource is
  gpu:0.
