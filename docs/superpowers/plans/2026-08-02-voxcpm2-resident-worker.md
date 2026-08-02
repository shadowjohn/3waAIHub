# VoxCPM2 Resident Worker Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (- [ ]) syntax for tracking.

**Goal:** Add one opt-in Cluster resident-HTTP execution channel for async Pack jobs, then prove it with VoxCPM2 voice_generate reusing one loaded CUDA model while retaining the task, GPU lease, artifact, and Cluster contracts.

**Architecture:** each Pack remains one-shot by default and may declare the fixed `service_data_v1` resident-HTTP capability. The Pack-job worker owns task admission, runtime attempts, GPU serialization, cancellation, and artifact publication. For an opted-in resident service it stages a Hub-owned run under the service-data mount and calls the fixed authenticated internal endpoint in the already-running service process. Admission is conservative: a cold resident needs the Pack's ordinary VRAM requirement, a warm-idle resident needs only its configured free-VRAM floor, and running or unknown capacity waits. A durable resident-run row prevents a timeout or unconfirmed cancellation from deleting staged input; it keeps GPU admission blocked until authenticated terminal status proves cleanup is safe. VoxCPM2 is the first adapter; existing synchronous resident APIs such as Gemma4, SAM3, and BioCLIP keep their current direct-API paths unchanged. This cut preserves the current Router station ranking: it has no authoritative warm-model signal yet, while every selected child station can immediately use the same local resident channel. Warm-aware cross-station preference is a later routing-only change once several async Packs publish the capability.

**Tech Stack:** PHP 8, SQLite service_settings, cURL, existing Pack-job task worker and GPU lease, FastAPI/Uvicorn, Python unittest, Docker Compose, Windows WSL2 Docker runtime.

---

## File Structure

- packs/tts-voxcpm2/pack.json: becomes patch version 0.1.7 and declares resident-mode settings and safe defaults.
- app/pack_registry.php: validates the small opt-in resident capability stored with an immutable async job contract.
- app/db.php: persists only the run-to-service identity required to safely reconcile unconfirmed resident work after a worker exits.
- app/runtime_worker.php and scripts/task_worker.php: keep GPU admission blocked until an unconfirmed resident run reaches an authenticated terminal state.
- app/service_settings.php: generates a per-service internal job secret exactly once.
- app/docker_runner.php: refreshes an installed Pack runtime before restart so a Pack upgrade cannot retain an old image tag.
- admin/marketplace.php: shows the declared VoxCPM2 execution selector during installation.
- admin/service_settings.php and i18n/seed.json: translate new administrator-visible labels.
- packs/tts-voxcpm2/service/app.py: owns authenticated capacity/status endpoints, the internal job endpoint, job lock, idle-unload timer, and cancellation registry.
- packs/tts-voxcpm2/service/job.py: accepts only a trusted staged reference and cancellation callback.
- packs/tts-voxcpm2/service/test_app.py and test_job.py: cover the service security and output contract.
- packs/tts-voxcpm2/service/Dockerfile: runs both Python test files during image build.
- app/pack_job_runner.php: selects, capacity-checks, stages, executes, reconciles, and cleans the shared resident-HTTP envelope while Vox supplies the first service adapter.
- tests/test_gpu_leases.php, tests/test_service_settings.php, tests/test_admin_market.php, tests/test_phase_p1.php, tests/test_pack_registry.php, tests/test_pack_job_adapter.php, tests/test_tts_voxcpm2.php, and tests/test_runtime_portability.php: cover control plane, Pack-version refresh, resident-capacity admission, unconfirmed recovery, resident-contract validation, task worker, voice privacy, and WSL behavior.
- docs/operations/voxcpm2-three-mode-smoke.md and README.md: document the deployed smoke and operating rule.

### Task 1: Declare, persist, and expose the resident setting

**Files:**
- Modify: packs/tts-voxcpm2/pack.json
- Modify: app/service_settings.php
- Modify: app/docker_runner.php
- Modify: admin/marketplace.php
- Modify: admin/service_settings.php
- Modify: i18n/seed.json
- Test: tests/test_service_settings.php
- Test: tests/test_admin_market.php
- Test: tests/test_phase_p1.php

- [ ] **Step 1: Write failing setting and installation tests**

Add a VoxCPM2 fixture proving the generated settings contain one stable masked internal token, isolated remains the default, idle unload permits 0, and changing a resident setting requires restart. Render the Marketplace form and assert only a Pack declaring install_option receives an execution selector. Add a restart fixture for an installed 0.1.6 Vox service which proves its runtime is refreshed to the 0.1.7 manifest before Docker image resolution.

~~~php
$settings = hub_ensure_service_settings($db, $service);
hub_test_assert(($settings['VOXCPM2_EXECUTION_MODE']['value'] ?? null) === 'isolated', 'VoxCPM2 must remain isolated until the administrator opts in');
hub_test_assert(($settings['VOXCPM2_IDLE_UNLOAD_SECONDS']['value'] ?? null) === '0', 'resident default must not unload automatically');
hub_test_assert(preg_match('/^[a-f0-9]{64}$/', (string)($settings['VOXCPM2_INTERNAL_JOB_TOKEN']['value'] ?? '')) === 1, 'internal token must be generated once per service');

$changed = hub_update_service_settings($db, (int)$service['id'], [
    'VOXCPM2_EXECUTION_MODE' => 'resident',
    'VOXCPM2_RESIDENT_MIN_FREE_VRAM_MB' => '1024',
]);
hub_test_assert($changed === ['changed' => true, 'restart_required' => true], 'resident changes must use the existing restart contract');
~~~

- [ ] **Step 2: Run focused tests to prove the feature is absent**

Run:

~~~bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=admin-ui
~~~

Expected: resident setting keys and the Marketplace selector are absent.

- [ ] **Step 3: Bump the Pack patch version and add the minimal settings**

Update packs/tts-voxcpm2/pack.json and its runner image references from 0.1.6 to 0.1.7, then add these restart-required settings and matching env entries. Its otherwise unchanged container runner declares the optional fixed resident capability below, so isolated remains the portable default. Change the old idle minimum/default from 60/900 to 0/0. Retain a narrowly tested VoxCPM2 compatibility path for an already-queued 0.1.6 task only; do not relax Pack-version immutability for any other Pack or arbitrary historical Vox task.

~~~json
{
  "key": "VOXCPM2_EXECUTION_MODE",
  "label": "VoxCPM2 執行模式",
  "type": "select",
  "default": "isolated",
  "options": ["isolated", "resident"],
  "option_labels": {
    "isolated": "一次性容器",
    "resident": "常駐模型"
  },
  "required": true,
  "restart_required": true,
  "install_option": true
}
~~~

~~~json
{
  "key": "VOXCPM2_RESIDENT_MIN_FREE_VRAM_MB",
  "label": "常駐推論最低可用 VRAM（MB）",
  "type": "integer",
  "default": "1024",
  "min": 0,
  "max": 16384,
  "required": true,
  "restart_required": true
}
~~~

~~~json
"resident": {
  "protocol": "service_data_v1",
  "mode_setting": "VOXCPM2_EXECUTION_MODE",
  "mode_value": "resident",
  "min_free_vram_setting": "VOXCPM2_RESIDENT_MIN_FREE_VRAM_MB"
}
~~~

Declare VOXCPM2_INTERNAL_JOB_TOKEN as a required secret restart-required setting. In hub_service_setting_default(), before the existing Pack-specific defaults, return bin2hex(random_bytes(32)) only for that VoxCPM2 key. INSERT OR IGNORE preserves existing rows, so upgrades gain a token once and never rotate it unexpectedly.

- [ ] **Step 4: Wire the declared install selector through the existing install path**

In admin/marketplace.php, find settings whose schema has install_option=true and render their existing select options in the collapsed installation fields. Submit only declared scalar values as the existing env option to hub_install_pack(); do not add columns or a second install flow.

~~~php
$installEnv = [];
foreach ($installOptions as $key => $item) {
    $value = $_POST['install_setting'][$key] ?? null;
    if (is_scalar($value)) {
        $installEnv[$key] = (string)$value;
    }
}

$result = hub_install_pack($db, (string)$_POST['pack_id'], [
    // existing instance fields remain here
    'env' => $installEnv,
    'provision_runner' => false,
]);
~~~

Only the Pack schema controls which install_setting keys exist. Keep machine values as isolated and resident; add the minimal optional `option_labels` map to the existing normalized schema and have both the new install selector and admin/service_settings.php render that map through __(). Use Chinese source strings in the schema and add their English translations to i18n/seed.json; do not seed English-as-source text that zh_TW would display verbatim.

- [ ] **Step 5: Refresh a Pack runtime before restart**

In hub_restart_service(), call the existing hub_refresh_service_runtime_files() before checking restart-required state or deriving the image tag. The refresh goes through idempotent hub_install_pack(), so it updates services.pack_version, generated Compose, and .env from the manifest before restart decides whether 3waaihub/tts-voxcpm2-main:0.1.7 exists. Keep this shared repair generic for all upgraded Packs; the regression fixture must prove a 0.1.6 Vox row resolves 0.1.7 after restart preparation.

- [ ] **Step 6: Run focused tests, lint, and commit**

Run:

~~~bash
php -l app/service_settings.php
php -l app/docker_runner.php
php -l admin/marketplace.php
php -l admin/service_settings.php
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=admin-ui
~~~

Expected: default installation is isolated with no idle unload; installation and later setting edits both use the existing .env plus restart-required path.

~~~bash
git add packs/tts-voxcpm2/pack.json app/service_settings.php app/docker_runner.php admin/marketplace.php admin/service_settings.php i18n/seed.json tests/test_service_settings.php tests/test_admin_market.php tests/test_phase_p1.php
git commit -m "feat: configure VoxCPM2 resident execution"
~~~

### Task 2: Run Pack jobs inside the loaded Uvicorn process

**Files:**
- Modify: packs/tts-voxcpm2/service/app.py
- Modify: packs/tts-voxcpm2/service/job.py
- Modify: packs/tts-voxcpm2/service/test_app.py
- Modify: packs/tts-voxcpm2/service/test_job.py
- Modify: packs/tts-voxcpm2/service/Dockerfile

- [ ] **Step 1: Write failing service tests**

Use a temporary service-data root and fake app._MODEL. Exercise a valid staged run, wrong token, traversal run ID, missing input, duplicate active run, cancellation between chunks, zero idle seconds, and a positive idle timeout. Exercise the authenticated capacity endpoint in cold, ready, and running states, and the authenticated job-status endpoint through running, succeeded, failed, cancelled, and post-restart unknown states. Exercise a real `/v1/tts` call followed by an internal job and prove both serialize through the same lifecycle lock and retain the same fake model. Assert responses do not return prompt text, model paths, host paths, or public artifact URLs.

~~~python
response = app.internal_job(
    app.InternalJobRequest(run_id="run-0123456789abcdef"),
    x_aihub_internal_token="test-token",
)

self.assertEqual(200, response.status_code)
self.assertEqual(1, calls["run_job"])
self.assertIs(self.model, app._MODEL)
self.assertEqual(0, timers["created"])

self.assertEqual(403, app.internal_job(
    app.InternalJobRequest(run_id="run-0123456789abcdef"),
    x_aihub_internal_token="wrong-token",
).status_code)
~~~

In test_job.py, run the existing run_job() with a trusted managed_reference_path below the staged run and assert its metadata still omits the reference path, profile ID, model path, and confirmed transcript.

- [ ] **Step 2: Run Python unit tests to prove the feature is absent**

Run:

~~~bash
cd packs/tts-voxcpm2/service
python3 -m unittest -v test_app.py test_job.py
~~~

Expected: InternalJobRequest, internal_job, staged-reference support, and lifecycle helpers are undefined.

- [ ] **Step 3: Add the private endpoint and bounded lifecycle state**

In app.py add one process-local threading.RLock, a map of run_id to threading.Event, and at most one optional threading.Timer. Route both the public `/v1/tts` real-inference path and the private internal-job path through the same model-work context: it cancels a pending timer, serializes model use, and schedules release only after the final active operation. The timer is created only when VOXCPM2_IDLE_UNLOAD_SECONDS is positive and no job holds the lock. Its callback deletes _MODEL, runs gc.collect(), and calls torch.cuda.empty_cache() only when CUDA is available. This leaves mock/non-model response behavior unchanged.

Add a Pydantic request carrying only run_id and these private routes:

~~~python
@app.post("/internal/jobs")
def internal_job(request: InternalJobRequest, x_aihub_internal_token: str | None = Header(default=None)) -> JSONResponse:
    if not secrets.compare_digest(x_aihub_internal_token or "", os.getenv("VOXCPM2_INTERNAL_JOB_TOKEN", "")):
        return response_error(403, "internal_auth_failed", "Internal job authorization failed.")
    workspace = internal_workspace(request.run_id)
    with resident_job_lock(request.run_id) as cancelled:
        job.run_job(
            workspace,
            workspace / "input",
            workspace / "output",
            workspace / "input" / "runner_config.json",
            cancelled.is_set,
            workspace / "input" / "reference.wav",
        )
    return JSONResponse(status_code=200, content={"ok": True, "run_id": request.run_id})
~~~

The internal_workspace() helper accepts only [a-z0-9][a-z0-9_.-]{0,95}, resolves only VOXCPM2_SERVICE_DATA_DIR/resident_jobs/<run_id>, rejects links, and requires regular request.json plus runner_config.json. Add POST /internal/jobs/{run_id}/cancel with the same token; it sets only the matching active event and returns 404 for an unknown run. Neither private route appears in the Pack manifest or API documents.

Add GET /internal/capacity and GET /internal/jobs/{run_id}, protected by the same token. Capacity returns only `model_state` (`cold`, `ready`, or `running`) and `active_runs`; status returns only `state` (`running`, `succeeded`, `failed`, `cancelled`, or non-terminal `unknown`) and the run ID. Persist the terminal state atomically in the staged run directory before the internal POST returns, with no prompt, transcript, reference path, model path, artifact URL, or host path. A stage with no active in-memory run and no terminal state is `unknown`, never implicit completion. These endpoints are private service-to-Hub controls, not public Pack API.

- [ ] **Step 4: Preserve the job algorithm and add cooperative stop points**

Extend only the existing job.run_job() and its chunk calls:

~~~python
def run_job(
    workspace: Path,
    input_dir: Path,
    output: Path,
    runner_config_path: Path,
    cancelled: Callable[[], bool] = lambda: False,
    managed_reference_path: Path | None = None,
) -> None:
    if cancelled():
        raise RuntimeError("cancelled")
    # preserve request, model, plan, checkpoint, and output validation
~~~

For clone modes, accept managed_reference_path only when it is a regular file below the resolved workspace; otherwise retain the current fixed /data/voice_profiles/reference.wav rule. Check cancelled() before each chunk attempt and after each model call. Do not change public request fields, metadata, checkpoints, sample-rate validation, or the CLI signature.

- [ ] **Step 5: Run image-build tests and commit**

Update the Dockerfile to copy both tests and run:

~~~dockerfile
RUN python3 -m unittest -v test_app.py test_job.py     && python3 -c 'import app'
~~~

Run:

~~~bash
cd packs/tts-voxcpm2/service
python3 -m unittest -v test_app.py test_job.py
cd ../../../
~~~

Expected: direct /v1/tts and resident jobs share one _MODEL and one model-work lock; zero idle retention creates no timer; only an authenticated Hub-staged job can use the private route.

~~~bash
git add packs/tts-voxcpm2/service/app.py packs/tts-voxcpm2/service/job.py packs/tts-voxcpm2/service/test_app.py packs/tts-voxcpm2/service/test_job.py packs/tts-voxcpm2/service/Dockerfile
git commit -m "feat: run VoxCPM2 jobs in resident service"
~~~

### Task 3: Add the shared Cluster resident-HTTP channel, capacity admission, and safe recovery

**Files:**
- Modify: app/pack_registry.php
- Modify: app/pack_job_runner.php
- Modify: app/db.php
- Modify: app/runtime_worker.php
- Modify: scripts/task_worker.php
- Test: tests/test_pack_registry.php
- Test: tests/test_pack_job_adapter.php
- Test: tests/test_tts_voxcpm2.php
- Test: tests/test_gpu_leases.php
- Modify: tests/suites/control-plane.php

- [ ] **Step 1: Write failing resident-contract and dispatch tests**

In tests/test_pack_registry.php reject every resident declaration except the four fixed service_data_v1 keys, a valid setting-name pair, and the literal resident mode value. The frozen queued-job contract must preserve the normalized resident declaration. In tests/test_pack_job_adapter.php prove cold capacity uses the ordinary runner.required_vram_mb, ready capacity uses only the declared free-VRAM floor, and both running and unknown capacity return waiting_gpu without dispatch.

Install and enable VoxCPM2, switch it to resident, queue one design task and one managed-clone task, then run the existing Pack worker with an injected resident transport. Assert no command with docker run occurs; transport sees only the private loopback endpoint, generated run ID, and internal header; declared output is copied into the normal task workspace; all staged request/reference/checkpoint/output files are removed only after a terminal confirmation; 1023 MB ready capacity enters waiting_gpu without transport or an attempt; 1024 MB ready capacity runs even when the resident process appears in the GPU list.

In tests/test_gpu_leases.php, simulate a timeout and a cancellation whose status endpoint remains unreachable. Assert the staged directory remains, its durable resident-run row is `unconfirmed`, task failure uses the existing unattested-cleanup path, and gpu:0 remains `blocked`. Then simulate authenticated terminal status and assert reconciliation removes only the exact staged run, marks it reconciled, and releases that blocked GPU lease.

~~~php
$outcome = hub_run_pack_job_task($db, $task, [
    'gpu_probe' => static fn (): array => ['free_vram_mb' => 1024, 'processes' => [501]],
    'resident_transport' => static function (array $request) use ($serviceDir): array {
        hub_test_assert($request['path'] === '/internal/jobs', 'resident runner must call only the private endpoint');
        file_put_contents($serviceDir . '/resident_jobs/' . $request['run_id'] . '/output/generated_audio.wav', "RIFFresident", LOCK_EX);
        file_put_contents($serviceDir . '/resident_jobs/' . $request['run_id'] . '/output/synthesis_metadata.json', '{"device":{"type":"cuda","real_inference":true}}', LOCK_EX);
        return ['status' => 200, 'body' => '{"ok":true}'];
    },
]);

hub_test_assert(($outcome['status'] ?? '') === 'success', 'resident result must use the ordinary Pack finalizer');
hub_test_assert($dockerRunCalls === 0, 'resident mode must not create a per-task Docker container');
~~~

- [ ] **Step 2: Run focused task tests to prove the feature is absent**

Run:

~~~bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=voice-cluster
~~~

Expected: the registry rejects the resident declaration and the worker always selects the one-shot container executor.

- [ ] **Step 3: Validate one fixed resident capability and implement its shared envelope**

Extend hub_pack_async_job_runner_contract() so a runner may include only this optional resident object:

~~~json
{
  "protocol": "service_data_v1",
  "mode_setting": "UPPERCASE_SETTING_NAME",
  "mode_value": "resident",
  "min_free_vram_setting": "UPPERCASE_SETTING_NAME"
}
~~~

Persist it in the existing stored runner snapshot. Do not introduce per-Pack endpoint URLs, arbitrary headers, shell commands, or an executor factory: protocol service_data_v1 always means the local service-data `resident_jobs/<run_id>` tree, `POST /internal/jobs`, `POST /internal/jobs/<run_id>/cancel`, and the service's generated local URL.

In app/pack_job_runner.php add the shared helpers beside the existing Pack-specific WSL helpers:

~~~php
function hub_resident_http_service_for_task(PDO $db, array $task, array $runner): ?array;
function hub_resident_http_stage(array $context, array $service, array $runner): array;
function hub_resident_http_execute(array $service, array $context, array $stage, ?callable $transport = null): array;
function hub_resident_http_cleanup(array $stage): array;
function hub_reconcile_resident_http_runs(PDO $db, ?callable $transport = null): int;
~~~

hub_resident_http_service_for_task() selects only an installed, enabled, running service for the task Pack whose declared mode_setting equals the declared resident mode_value. Every runner without the optional resident declaration keeps the current container, WSL, or custom executor unchanged. Vox remains the first Pack whose declared setting enables this path.

When resolving an already-queued 0.1.6 VoxCPM2 voice job, accept only the documented compatible stored contract and map it to the installed 0.1.7 Pack runner; add an explicit test for that case. Keep generic Pack resolution immutable and reject any incompatible Vox version, mode, or missing runner snapshot rather than silently upgrading it.

Create a narrow `resident_job_runs` table keyed by runtime_run_id, with task_id, service_id, opaque run_id, and lifecycle state (`dispatched`, `cancel_requested`, `unconfirmed`, `reconciled`) plus timestamps. Add its index and schema-missing check beside the existing runtime tables. It contains no host path, secret, input, or artifact data. Insert its row before POST dispatch; the stage location is always derived from its installed service runtime directory and opaque run ID.

hub_resident_http_capacity() calls the fixed authenticated capacity endpoint before GPU preflight. `cold` passes the ordinary runner.required_vram_mb to the existing preflight; `ready` passes 0 plus the declared free-VRAM floor; `running` or missing/invalid/unreachable capacity returns waiting_gpu with `resident_capacity_busy` or `resident_capacity_unknown`. A resident request never falls back to a second one-shot container after capacity is unknown.

hub_resident_http_stage() creates <runtime-dir>/resident_jobs/<run_id> with 0700 directories and copies the already Hub-owned task input/runner config there. It requires regular non-link sources, uses 0600 request copies, creates output/checkpoints, and never accepts a caller-supplied path. For Vox's already-governed clone reference, stage the resolved profile as input/reference.wav; that is its only Pack-specific input preparation, while the wire protocol and cleanup remain shared.

hub_resident_http_execute() validates the stored runner snapshot, calls context['started']([]), posts only {"run_id":"..."} to the service 127.0.0.1 origin, and uses X-AIHub-Internal-Token. Use cURL with one-second connect timeout and the stored job timeout. Its progress callback continues tick() heartbeats. On cancellation or timeout set the durable row to cancel_requested, send the authenticated private cancel request once, then poll the authenticated status endpoint for a terminal state during one fixed 60-second confirmation grace period.

After HTTP 200 with {"ok":true}, copy only declared regular output files from the staged service-data output directory to context['workspace'] . '/output'. The existing hub_finalize_pack_job_success() remains the sole artifact validator/publisher. Return:

~~~php
[
    'exit_code' => 0,
    'completed_no_process_evidence' => true,
    'cleanup' => hub_pack_job_no_work_cleanup(),
]
~~~

The finally block securely removes only that exact run directory after an authenticated `succeeded`, `failed`, or `cancelled` state. Its recursive remover rejects a symlink at every level and requires every target to remain below the resolved resident_jobs root. It must not delete model, cache, artifacts, or another run. If POST/cancel/status remains unconfirmed after the grace period, leave the stage and resident_job_runs row intact, return no cleanup attestation, and let the current terminal-fence behavior mark gpu:0 `blocked`; it must not delete the stage, release the GPU, or try a duplicate job. At the start of each task-worker loop, hub_reconcile_resident_http_runs() checks unconfirmed rows: only authenticated terminal status allows exact-stage deletion, row completion, and the narrowly matched blocked-GPU release. Add that release as one runtime-worker helper matching the blocked lease's stored runtime_run_id and lease token; no general "clear blocked GPU" shortcut is permitted. This fixed envelope is deliberately the only shared layer future async resident Packs reuse; their model loading and input semantics stay in their own service adapter.

- [ ] **Step 4: Apply resident capacity states without changing GPU leases**

Inside hub_run_pack_job_task(), resolve resident capacity before GPU preflight. For a `cold` service call the current preflight with the ordinary required_vram_mb and global margin. For a `ready` service call it with requiredVramMb=0 and the declared min_free_vram_setting. `running` and `unknown` capacity must transition to waiting_gpu before any POST dispatch or model staging. Other GPU Packs continue to pass stored required_vram_mb and global margin.

~~~php
$capacity = hub_resident_http_capacity($residentService, $runner, $transport);
if (in_array($capacity['model_state'], ['running', 'unknown'], true)) {
    return hub_pack_job_wait_without_gpu($db, $taskId, $run, (string)$capacity['reason'], $backoffSeconds);
}
$requiredVramMb = $capacity['model_state'] === 'cold' ? (int)$runner['required_vram_mb'] : 0;
$safetyMarginMb = $capacity['model_state'] === 'cold'
    ? null
    : (int)$capacity['min_free_vram_mb'];
$preflight = hub_runtime_gpu_preflight(
    $db, $taskId, $run, $gpuLease, $requiredVramMb, $probe, $backoffSeconds, $safetyMarginMb
);
~~~

Keep hub_runtime_gpu_acquire_for_task(), runtime_runs, waiting_gpu, callbacks, finalizers, and Cluster artifact relay unchanged. The known resident process may appear in nvidia-smi; only a ready resident with the free margin met can reuse it. Do not add a GPU-process registry, a second queue, or a second cross-station task protocol.

- [ ] **Step 5: Run regressions and commit**

Run:

~~~bash
php -l app/pack_job_runner.php
php -l app/pack_registry.php
php -l app/runtime_worker.php
php -l scripts/task_worker.php
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=voice-cluster
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane
~~~

Expected: cold residents require the full normal VRAM capacity; only ready residents reuse their loaded model with the configured free floor; unknown cancellation keeps its stage and gpu:0 blocked; authenticated terminal reconciliation performs the exact cleanup and release. All undeclared jobs remain one-shot; any future Pack using the fixed resident declaration reuses the shared task envelope; Vox resident jobs never use docker run; compatible already-queued 0.1.6 Vox jobs resolve through the current 0.1.7 runner only; clone profiles stay private.

~~~bash
git add app/pack_registry.php app/pack_job_runner.php app/db.php app/runtime_worker.php scripts/task_worker.php tests/test_pack_registry.php tests/test_pack_job_adapter.php tests/test_tts_voxcpm2.php tests/test_gpu_leases.php tests/suites/control-plane.php
git commit -m "feat: add Cluster resident job dispatch"
~~~

### Task 4: Publish the operating rule and preserve Windows/WSL behavior

**Files:**
- Modify: docs/operations/voxcpm2-three-mode-smoke.md
- Modify: README.md
- Modify: tests/test_tts_voxcpm2.php
- Modify: tests/test_runtime_portability.php

- [ ] **Step 1: Write failing documentation and portability tests**

Extend the existing runbook-content test to require VOXCPM2_EXECUTION_MODE=resident, VOXCPM2_IDLE_UNLOAD_SECONDS=0, VOXCPM2_RESIDENT_MIN_FREE_VRAM_MB, cold-versus-ready admission, two consecutive voice_generate tasks, nvidia-smi, unconfirmed-stage retention, and an explicit stop/restart release check. Add a Windows fixture proving resident execution uses the existing loopback internal URL plus injected HTTP transport, never a Windows Docker CLI or a direct Linux Docker runner.

~~~php
hub_test_assert(
    $request['url'] === 'http://127.0.0.1:18108/internal/jobs'
        && !str_contains(implode(' ', $commands), 'docker run'),
    'Windows WSL resident dispatch must use the published local service port, not Docker CLI'
);
~~~

- [ ] **Step 2: Run focused tests to prove the documentation and WSL assertion are absent**

Run:

~~~bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=voice-cluster
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane
~~~

Expected: resident operator steps and the loopback-only Windows assertion are missing.

- [ ] **Step 3: Add the concise administrator runbook**

In docs/operations/voxcpm2-three-mode-smoke.md add a resident section after real-inference preflight:

1. Select resident in service settings and restart VoxCPM2.
2. Keep VOXCPM2_IDLE_UNLOAD_SECONDS=0; use 1024 MB as the ready-model safety margin. A cold service still requires its normal 9600 MB job capacity before first load.
3. Submit two serial real voice_generate tasks through the current Cluster acceptance command and confirm the second avoids model bootstrap.
4. Check nvidia-smi between tasks to confirm the model remains allocated and only the configured ready-model margin is needed for the next task.
5. For a timeout or cancellation, wait for the service's authenticated terminal state. If it cannot be confirmed, retain the staged run and leave GPU admission blocked; the task worker reconciles it later and never launches a duplicate.
6. Stop or restart the service, then confirm its VRAM is released before a large GPU Pack runs.

State that a competing GPU task waits or routes to another station; it never auto-unloads VoxCPM2. Add one matching paragraph to the README 3wa voice-node section without any new client API.

- [ ] **Step 4: Run documentation, portability, and targeted checks**

Run:

~~~bash
php -l tests/test_tts_voxcpm2.php
php -l tests/test_runtime_portability.php
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=voice-cluster
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane
~~~

Expected: the runbook documents administrator actions only; API clients and Cluster callers retain their voice_generate contract.

~~~bash
git add docs/operations/voxcpm2-three-mode-smoke.md README.md tests/test_tts_voxcpm2.php tests/test_runtime_portability.php
git commit -m "docs: add VoxCPM2 resident worker smoke"
~~~

### Task 5: Refresh, build, restart, and run real resident acceptance

**Files:**
- Modify: generated runtime files only through existing Marketplace setting/restart actions
- Test: deployed VoxCPM2 service and scripts/voxcpm2_cluster_acceptance.php

- [ ] **Step 1: Run static and focused regressions before deployment**

Run:

~~~bash
git status --short
php -l app/pack_job_runner.php
cd packs/tts-voxcpm2/service
python3 -m unittest -v test_app.py test_job.py
cd ../../../
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=voice-cluster
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane
~~~

Expected: no test failure and no unrelated working-tree change is staged.

- [ ] **Step 2: Rebuild then restart through the existing service command path**

Use the Marketplace's existing `重新建置` action for `voxcpm2-main`, let the existing command worker complete it, then use its `重啟` action. This makes hub_refresh_service_runtime_files() update the service row and generated files to 0.1.7 before the current image is built and started. Do not invoke the Pack development Compose file or raw Docker Compose; either can bypass the application-level version refresh and collide with the installed port.

~~~bash
php -r 'require "app/bootstrap.php"; hub_cli_only(); $db = hub_db(); $service = hub_get_service_by_key($db, "voxcpm2-main"); if (!$service) { throw new RuntimeException("service missing"); } echo hub_enqueue_command_job($db, "service_rebuild", (int)$service["id"], ["reason" => "resident_worker_release"], null, "127.0.0.1"), PHP_EOL;'
php scripts/command_worker.php --limit=1
php -r 'require "app/bootstrap.php"; hub_cli_only(); $db = hub_db(); $service = hub_get_service_by_key($db, "voxcpm2-main"); if (!$service) { throw new RuntimeException("service missing"); } echo hub_enqueue_command_job($db, "service_restart", (int)$service["id"], ["reason" => "resident_worker_release"], null, "127.0.0.1"), PHP_EOL;'
php scripts/command_worker.php --limit=1
~~~

Expected: health reports ready, the generated .env contains resident plus idle=0, and the service row/image tag both resolve 0.1.7.

- [ ] **Step 3: Run the two-pass real inference acceptance**

Ensure resident mode, VOXCPM2_REAL_INFERENCE=1, and at least the configured free margin. Run the current Cluster acceptance twice serially and inspect tasks, artifacts, and GPU state.

~~~bash
php scripts/voxcpm2_cluster_acceptance.php --json
nvidia-smi
php scripts/voxcpm2_cluster_acceptance.php --json
nvidia-smi
~~~

Expected: both runs yield verified non-mock WAV artifacts; the second creates no aihub-pack-* container; the VoxCPM2 CUDA process remains after task two; gpu:0 returns to available between tasks.

- [ ] **Step 4: Verify explicit release and commit deployment-safe code only**

Use Marketplace stop or restart, wait for completion, then confirm VoxCPM2 is absent from nvidia-smi. Re-enable after the check. Never commit generated .env, generated Compose, task artifacts, or benchmark output.

~~~bash
git status --short
git log -4 --oneline
~~~

Expected: only the four implementation commits are ready for review; runtime data remains untracked or ignored.
