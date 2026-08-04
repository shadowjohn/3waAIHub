# MyAI Voice Phase A Runtime Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make MyAI Voice asynchronous audio jobs observable and schedulable, add a resident Whisper ASR path that preserves managed artifacts, and publish an unambiguous Cluster task contract.

**Architecture:** Extend the existing Pack job queue and `service_data_v1` resident protocol instead of adding another worker system. Persist a bounded GPU-wait snapshot with each waiting task, project only validated fields through the Cluster Router, and keep the safety rule that Hub never evicts resident or external GPU processes. Reuse the existing priority queue and Cluster station pressure selection, while making Whisper's resident/CPU choices explicit service settings.

**Tech Stack:** PHP 8, SQLite, Pack JSON, FastAPI/Python, Docker loopback `service_data_v1`, existing PHP and Python test suites.

---

### Task 1: Field-level Pack validation and stable task status

**Files:**
- Modify: `app/pack_registry.php`
- Modify: `app/gateway.php`
- Modify: `app/task_queue.php`
- Modify: `tests/test_audio_task_gateway.php`

- [x] **Step 1: Keep the failing contract tests**

The gateway test must submit `min_speakers=2` with `diarization=false` and expect:

```php
hub_test_assert($response['status'] === 400);
hub_test_assert($payload['error'] === 'invalid_request');
hub_test_assert($payload['message'] === 'min_speakers requires diarization=true');
hub_test_assert($payload['field_errors'] === ['min_speakers' => 'requires diarization=true']);
```

The status test must expect every response to include `status`, integer `progress`, and a displayable `message` such as `Queued.`.

- [x] **Step 2: Verify RED**

Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=voice-cluster`

Expected: failures for generic Pack validation and missing task `message`.

- [x] **Step 3: Emit and decode bounded field errors**

Add a Pack validation exception format whose field name matches `^[a-z][a-z0-9_]{0,63}$` and whose reason is generated only by schema rules. For the dependency rule, generate:

```php
throw new InvalidArgumentException('invalid_request:' . $field . ':requires ' . $requiredField . '=' . hub_pack_job_requirement_value($requiredValue));
```

In `hub_api_pack_job_task_submit()`, decode only that exact internal format and return:

```php
return hub_gateway_json(400, [
    'ok' => false,
    'error' => 'invalid_request',
    'message' => $field . ' ' . $reason,
    'field_errors' => [$field => $reason],
]);
```

All malformed or unknown exceptions must retain the existing generic error.

- [x] **Step 4: Add the stable display message**

Add `hub_task_status_message(string $status, ?string $waitingReason = null): string` in `app/task_queue.php` with fixed messages for `staging`, `queued`, `waiting_gpu`, `running`, `success`, `failed`, `cancelled`, and `timed_out`. Add the result to `hub_api_task_status()` without exposing logs or exception text.

- [x] **Step 5: Verify GREEN**

Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=voice-cluster`

Expected: the Task 1 gateway tests pass.

### Task 2: Persist and publish GPU wait diagnostics

**Files:**
- Modify: `app/db.php`
- Modify: `app/runtime_worker.php`
- Modify: `app/pack_job_runner.php`
- Modify: `app/task_queue.php`
- Modify: `app/gateway.php`
- Modify: `tests/test_gpu_leases.php`
- Modify: `tests/test_audio_task_gateway.php`
- Modify: `tests/test_job_first_schema.php`

- [x] **Step 1: Write waiting diagnostic tests**

Use a deterministic probe:

```php
static fn (): array => [
    'free_vram_mb' => 768,
    'processes' => [731],
    'process_details' => [[
        'pid' => 731,
        'process_name' => 'ffmpeg',
        'used_vram_mb' => 512,
        'classification' => 'external',
    ]],
]
```

Expect the waiting task and `task_status` to expose `waiting_reason=unmanaged_gpu_process`, `required_vram_mb=10000`, `free_vram_mb=768`, a positive `retry_after_seconds`, and a bounded `gpu_processes` list. Verify that terminal and promoted tasks clear the stored wait snapshot.

- [x] **Step 2: Verify RED**

Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane`

Expected: missing schema column and diagnostic assertions fail.

- [x] **Step 3: Add one bounded wait snapshot column**

Add `tasks.waiting_detail_json TEXT NULL` through `hub_add_column_if_missing()`, include it in `hub_runtime_schema_missing()`, and never store arbitrary command output. The JSON shape is:

```json
{
  "required_vram_mb": 10000,
  "free_vram_mb": 768,
  "gpu_processes": [
    {"pid": 731, "process_name": "ffmpeg", "used_vram_mb": 512, "classification": "external"}
  ]
}
```

Limit the process list to 32 rows, names to 128 printable characters, and numeric values to non-negative integers.

- [x] **Step 4: Capture process name and VRAM without changing the safety decision**

Change the runtime probe to query `pid,process_name,used_memory`, retain the legacy integer `processes` list, and add validated `process_details`. `hub_runtime_gpu_preflight_result()` must always return `required_vram_mb`, `free_vram_mb`, and the unmanaged detail rows. Insufficient capacity still waits; no process is stopped or killed.

- [x] **Step 5: Save, clear, and render the snapshot**

Pass the preflight details into both `hub_runtime_gpu_wait_for_capacity()` and `hub_pack_job_wait_without_gpu()`. Save `waiting_detail_json` on the transition to `waiting_gpu`; clear it when promoting, starting, or finishing the task. Derive:

```php
$retryAfter = max(0, (strtotime((string)$task['next_attempt_at']) ?: time()) - time());
```

Expose the four requested top-level fields plus `gpu_processes` only while waiting.

- [x] **Step 6: Verify GREEN**

Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane`

Expected: all GPU lease tests pass and no test invokes real GPU hardware.

### Task 3: Route Whisper async work through the resident ASR service

**Files:**
- Modify: `packs/whisper-asr/pack.json`
- Modify: `packs/whisper-asr/docker-compose.yml`
- Modify: `packs/whisper-asr/service/app.py`
- Modify: `packs/whisper-asr/service/job.py`
- Modify: `packs/whisper-asr/service/test_app.py`
- Modify: `tests/test_audio_task_gateway.php`
- Modify: `tests/test_pack_job_adapter.php`

- [x] **Step 1: Add failing resident ASR tests**

Test authenticated `GET /internal/capacity`, `POST /internal/jobs`, status, duplicate dispatch, cancellation, invalid run IDs, and terminal state persistence. Add a Pack adapter test proving that `WHISPER_ASYNC_EXECUTION_MODE=resident` dispatches to `asr-main`, returns normal managed `transcript_json` and `transcription_report` artifacts, and does not launch the one-shot container.

- [x] **Step 2: Verify RED**

Run: `cd packs/whisper-asr/service && python3 -m unittest -v test_app.py; cd ../../.. && AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=voice-cluster`

Expected: resident endpoints and Whisper resident contract are absent.

- [x] **Step 3: Declare the existing resident protocol**

Add to the Whisper async job:

```json
"resident": {
  "protocol": "service_data_v1",
  "mode_setting": "WHISPER_EXECUTION_MODE",
  "mode_value": "resident",
  "min_free_vram_setting": "WHISPER_RESIDENT_MIN_FREE_VRAM_MB"
}
```

Add settings `WHISPER_EXECUTION_MODE=resident|isolated`, `WHISPER_RESIDENT_MIN_FREE_VRAM_MB`, and the generated secret `WHISPER_INTERNAL_JOB_TOKEN`; mount the existing service-data directory. Preserve the safe `isolated` default until an operator explicitly opts in and restarts `asr-main`.

- [x] **Step 4: Implement the internal job endpoints by reusing the VoxCPM2 lifecycle**

Use the same strict run ID, stage containment, constant-time token check, capacity states (`cold`, `ready`, `running`), terminal file, cancellation event, and one-active-job lock as `packs/tts-voxcpm2/service/app.py`. Call `job.run_job()` against `/data/service/resident_jobs/<run_id>` so the Hub's existing resident executor copies validated outputs back into the task workspace.

- [x] **Step 5: Share the resident model cache**

Change `job.run_job()` to accept injected/model-cache inference and cancellation callbacks while keeping its CLI defaults unchanged. The API service and async resident path must call the same `load_model()` cache; artifact filenames and report schema remain identical to the frozen Pack contract.

- [x] **Step 6: Verify GREEN**

Run: `cd packs/whisper-asr/service && python3 -m unittest -v test_app.py; cd ../../.. && AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=voice-cluster`

Expected: resident endpoint, adapter, artifact, cancellation, and replay tests pass.

### Task 4: Configurable CPU fallback and priority policy

**Files:**
- Modify: `packs/whisper-asr/pack.json`
- Modify: `packs/whisper-asr/service/job.py`
- Modify: `app/gateway.php`
- Modify: `app/task_queue.php`
- Modify: `app/public_api_docs.php`
- Modify: `tests/test_audio_task_gateway.php`
- Modify: `tests/test_task_queue.php`

- [x] **Step 1: Write policy tests**

Test that Pack submission accepts an integer `priority` from 0 through 100, removes it before Pack schema validation, stores it on the task, and that `hub_claim_next_task()` returns priority 90 before priority 10. Test that invalid priorities return a field-level error. Test Whisper CPU policy with a mocked CUDA failure and assert effective device `cpu` without an eviction command.

- [x] **Step 2: Verify RED**

Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=voice-cluster`

Expected: Pack priorities are currently fixed to zero and the public contract does not list priority.

- [x] **Step 3: Wire priority into the existing queue**

Parse the reserved submit field before `hub_pack_job_task_input()`, pass it to `hub_enqueue_owned_pack_job()`, and preserve it on retries. Keep the existing queue order:

```sql
ORDER BY priority DESC, created_at ASC, id ASC
```

Do not create a second priority scheduler.

- [x] **Step 4: Make Whisper shortage behavior explicit**

Add `WHISPER_GPU_SHORTAGE_POLICY=wait|cpu` with default `wait`. In `cpu`, run the resident model with device `cpu`/`int8`; in `wait`, retain GPU preflight and backoff. The Cluster Router remains the cross-node fallback at initial dispatch: it selects an unpressured eligible station before accepting a child task. Once accepted, a task stays pinned to preserve ownership and idempotency.

- [x] **Step 5: Document estimates and non-eviction**

Describe `retry_after_seconds` as the next scheduler retry, not a promised completion time. State that resident services and external GPU processes are never evicted; operators choose `wait`, `cpu`, or send the request through Cluster for another node.

- [x] **Step 6: Verify GREEN**

Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=voice-cluster`

Expected: priority, retry preservation, CPU fallback, and public field tests pass.

### Task 5: Cluster-safe status/error projection and formal audio API contract

**Files:**
- Modify: `app/cluster_router.php`
- Modify: `app/public_api_docs.php`
- Modify: `docs/cluster-router.md`
- Modify: `docs/api_examples.md`
- Modify: `tests/test_cluster_router.php`

- [x] **Step 1: Keep the failing Cluster tests**

Require `production_audio_modes` to be exactly:

```php
['audio_cleanup', 'speech_transcribe', 'voice_generate']
```

Require Router status responses to include normalized `status`, `progress`, and `message`, and to relay only validated wait fields. Require structured child `field_errors` to survive, while free-form messages, URLs, unknown keys, and oversized values are replaced by the Router's generic error.

- [x] **Step 2: Verify RED**

Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=voice-cluster`

Expected: missing production audio directory, rich artifact contract, structured error relay, and stable status fields fail.

- [x] **Step 3: Project only a bounded public task status**

Normalize aliases (`waiting_gpu` to public `queued`, `completed` to `success`, `timeout` to `timed_out`), default progress to zero, generate Router-owned display messages, and validate numeric wait fields and process rows before relaying them. Never relay child logs or arbitrary error text.

- [x] **Step 4: Publish the task/result/artifact/ACK model**

Add `production_audio_modes` and `async_task_contract` to `hub_cluster_public_manifest()`. Include all three audio modes in the rich artifact/ACK path. The HTML guide and Markdown docs must state:

```text
Router task_id is an opaque string; store it exactly and never cast it to an integer.
Native api.php task IDs are numeric and belong to a different namespace.
```

Explain submit → status → result → artifact download → ACK, and that the live `services` array still reflects installed, enabled, fresh station inventory; documentation does not fabricate an unavailable live mode.

- [x] **Step 5: Relay exact field-level errors safely**

Implement `hub_cluster_pack_validation_error_response()` that accepts only HTTP 400, `error=invalid_request`, one bounded field key, and a reason matching the Pack schema vocabulary (`is required`, `is invalid`, `requires ...`, `must be greater than ...`). Rebuild the response rather than passing through the child body.

- [x] **Step 6: Verify GREEN**

Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=voice-cluster`

Expected: all Cluster Router assertions pass.

### Task 6: Full verification and operator handoff

**Files:**
- Modify: `docs/operations/whisper-asr-resident.md`
- Modify: `docs/superpowers/plans/2026-08-04-myai-voice-phase-a-runtime.md`

- [x] **Step 1: Add the operator runbook**

Document settings, service restart, `/health`, resident capacity, a real `speech_transcribe` submit, polling the four wait fields, artifact download and ACK, `nvidia-smi --query-compute-apps=pid,process_name,used_memory --format=csv,noheader,nounits`, CPU fallback, and the rule that no resident/external process is auto-terminated.

- [x] **Step 2: Run focused verification**

Run:

```bash
(cd packs/whisper-asr/service && python3 -m unittest -v test_app.py)
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=voice-cluster
```

Expected: all focused tests pass.

- [x] **Step 3: Run manifest and syntax checks**

Run:

```bash
php -l app/db.php
php -l app/runtime_worker.php
php -l app/pack_job_runner.php
php -l app/pack_registry.php
php -l app/task_queue.php
php -l app/gateway.php
php -l app/public_api_docs.php
php -l app/cluster_router.php
python3 -m py_compile packs/whisper-asr/service/app.py packs/whisper-asr/service/job.py
php -r 'require "app/bootstrap.php"; $p=hub_get_pack("whisper-asr"); if (!$p || $p["status"] !== "ok") { fwrite(STDERR, json_encode($p["errors"] ?? [])); exit(1); }'
```

Expected: no syntax or Pack validation errors.

- [x] **Step 4: Run the complete suite**

Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=full`

Expected: no new failures. Record separately any already-known unrelated GPT-SoVITS, legacy audio route, web-capture accelerator, or WSL fixture failures instead of masking them.

Observed: 937 tests, 4 known unrelated failures, 7 skipped. The failures are the same GPT-SoVITS L4 fixture, legacy audio route map, web-capture accelerator, and Web Screenshot WSL source assertions present before this implementation.

- [x] **Step 5: Review the diff and deployment impact**

Run: `git diff --check && git status --short && git diff --stat`

Expected: only Phase A code/tests/docs plus the pre-existing presentation artifacts are present. Handoff must say that existing `asr-main` needs the updated image/settings and a controlled restart before resident mode becomes active; no restart is performed automatically.
