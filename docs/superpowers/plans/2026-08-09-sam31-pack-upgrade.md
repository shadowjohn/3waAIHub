# SAM 3.1 Pack Upgrade Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Upgrade the existing `sam3` Pack in place to official SAM 3.1, retain its synchronous image API, and add bounded image/video tasks plus secure administrator-managed stream monitoring.

**Architecture:** Keep `mode=sam3` as the only public mode and dispatch the `operation` field before the normal service proxy. The service uses Meta's official SAM 3.1 multiplex video predictor for both video and a single-frame image session, while PHP reuses the existing Pack-job, GPU-lease, resident-job, artifact, task, and retention facilities. Source URLs exist only in a system-admin registry; public calls submit an opaque `source_id` or a managed upload.

**Tech Stack:** PHP 8/SQLite/Apache gateway, existing Pack-job runner and Docker Compose runtime, Python 3.12, CUDA 12.8-compatible PyTorch 2.10, pinned `facebookresearch/sam3`, FFmpeg, PHPUnit-style standalone PHP tests, Python `unittest`.

**Approved design:** `docs/superpowers/specs/2026-08-09-sam31-pack-design.md`

---

## Scope and file map

| Area | Files | Responsibility |
| --- | --- | --- |
| Pack/runtime | `packs/sam3/pack.json`, `packs/sam3/service/Dockerfile`, `packs/sam3/service/requirements.txt`, `packs/sam3/scripts/provision_sam31.sh` | Pin official code, declare immutable job contracts, provision only gated model assets. |
| SAM service | `packs/sam3/service/app.py`, `packs/sam3/service/sam31.py`, `packs/sam3/service/jobs.py`, `packs/sam3/service/capture.py`, existing geometry/smoke files | One predictor adapter for image, video, and resident jobs; safe local capture and artifact generation. |
| Gateway/tasks | `app/gateway.php`, `app/pack_registry.php`, `app/pack_job_runner.php`, new `app/sam3.php`, new `app/sam3_sources.php` | Operation dispatch, fixed Pack routes, GPU arbitration, source capture, monitor event collection. |
| Persistence/admin | `app/db.php`, `admin/service_settings.php`, new `admin/sam3_sources.php` | Source registry, monitor state, liveness recovery, system-admin controls. |
| Contract/docs | `app/public_api_docs.php`, `docs/api_examples.md`, `docs/client_quickstart.md`, new `docs/operations/sam31-deploy-and-monitor.md` | Public operation documentation, host provisioning, rollback, acceptance record. |
| Verification | new `tests/test_sam3_pack.php`, `tests/test_vision_packs.php`, `tests/test_pack_job_artifacts.php`, `tests/test_gpu_leases.php`, `tests/test_job_first_schema.php`, `tests/test_public_api_docs.php`, `tests/suites/control-plane.php`, Python `test_*.py` under `packs/sam3/service/` | Contract, boundary, migration, liveness, and no-weight unit checks. |

## Compatibility decisions

- `POST api.php?mode=sam3` with no `operation` remains exactly the current synchronous image entrypoint. `operation=image` is equivalent. Only `image_task` and `video_task` add work; no `sam31` mode or Pack is created.
- The service uses `build_sam3_multiplex_video_predictor(checkpoint_path=..., max_num_objects=16, multiplex_count=16)` with `load_from_HF` disabled through its explicit checkpoint path. For a direct image it materializes the already-validated image as one private JPEG frame, starts one session, adds the legacy prompt, reads frame 0 output, closes the session, and removes the temporary frame. This is the official SAM 3.1 predictor path, not the legacy SAM 3 image builder.
- Legacy prompts map without changing request names: text, points, and boxes map to `add_prompt`; `guidance_mask` uses its non-empty pixel bounds as a positive box, which is the current Pack's documented compatibility behaviour. A request carrying more than one legacy prompt category is rejected as it is today; accepted fields never become raw model/path controls.
- Async work uses the existing `pack_job` state machine. `segment_image` and `track_video` run to a static artifact contract. `monitor` is deliberately the one SAM-specific resident Pack job; it adds only the small outbox collector needed to publish live monitor events while the job is running.
- The first implementation remains one-GPU / one-monitor. It uses the existing `gpu:0` lease; it does not add preemption, a scheduler, or a second queue. This is an intentional capacity ceiling, not a hidden promise of real-time throughput.

## Task 1: Replace the Pack runtime and make model provisioning explicit

**Files:**
- Modify: `packs/sam3/pack.json`
- Modify: `packs/sam3/service/Dockerfile`
- Modify: `packs/sam3/service/requirements.txt`
- Modify: `packs/sam3/service/model_smoke.py`, `packs/sam3/service/storage_smoke.py`, `packs/sam3/service/inference_smoke.py`, `packs/sam3/service/smoke.py`
- Create: `packs/sam3/scripts/provision_sam31.sh`
- Modify: `tests/test_vision_packs.php`
- Create: `tests/test_sam3_pack.php`

- [ ] **Step 1: Write the failing Pack-contract tests.**

  Add assertions in `tests/test_sam3_pack.php` that the manifest reports version `0.2.0`, keeps `default_mode: sam3` and `/segment/image`, declares exactly `segment_image`, `track_video`, and resident `monitor`, and has required output names:

  ```php
  hub_test_assert(($manifest['version'] ?? '') === '0.2.0', 'SAM3 Pack must be version 0.2.0');
  hub_test_assert(array_column($manifest['async_jobs'] ?? [], 'job') === ['segment_image', 'track_video', 'monitor'], 'SAM3 async job set changed');
  hub_test_assert(in_array('sam3.1_multiplex.pt', $assetMount['required_paths'] ?? [], true), 'SAM3.1 checkpoint must be required');
  ```

  Extend `tests/test_vision_packs.php` to reject `ultralytics`, require the pinned upstream commit and SAM 3.1 checkpoint name, and verify Docker build smoke checks do not download a model.

- [ ] **Step 2: Run the tests to confirm they fail before the manifest change.**

  Run: `php tests/test_sam3_pack.php && php tests/test_vision_packs.php`

  Expected: failure because `0.1.0`, Ultralytics, and the old checkpoint selector are still present.

- [ ] **Step 3: Make the minimum runtime/manifest replacement.**

  In `pack.json` set `version` to `0.2.0`; retain the service key/mode and synchronous gateway fields. Replace Ultralytics settings with `SAM3_MODEL_DIR`, `SAM3_REAL_INFERENCE`, `SAM3_DEVICE`, `SAM3_MAX_UPLOAD_MB`, `SAM3_INTERNAL_JOB_TOKEN`, and `SAM3_RESIDENT_MIN_FREE_VRAM_MB`. Make the token a generated secret service setting, never a public setting.

  Define fixed Pack-job contracts:

  ```json
  "async_jobs": [
    {"job":"segment_image","input":{"source_required":true},"runner":{"accelerator":"gpu"}},
    {"job":"track_video","input":{"source_required":true},"runner":{"accelerator":"gpu"}},
    {"job":"monitor","input":{"source_required":false},"runner":{"accelerator":"gpu"},"resident":{"protocol":"service_data_v1"}}
  ]
  ```

  Give each job an allowlisted static `artifact_contract` and a read-only `runner.asset_mounts` entry whose `required_paths` include `sam3.1_multiplex.pt` and the provision manifest. Include `sam3_image_report.json` and `sam3_masks.json` for image, `sam3_video_report.json` and `sam3_tracks.jsonl` for video, and `sam3_monitor_report.json` plus `sam3_monitor_events.jsonl` for monitor. Overlay PNG/MP4 output remains optional and is validated as an actual image/video artifact.

  Change the Docker image to a CUDA 12.8-compatible Python 3.12 base with FFmpeg. Install `torch==2.10.0` from the CUDA 12.8 index and the official source at commit `96914d2425f90a64f45ca977c2b5165418099543`; do not add another model SDK. Build smoke tests import the adapter only and run with `SAM3_REAL_INFERENCE=0`.

  Replace model smoke discovery with an exact manifest check for `sam3.1_multiplex.pt` and a JSON provision manifest containing only `upstream_commit`, `repository`, `files`, and SHA-256 values. It must not print a host path or an environment variable value.

  Implement `provision_sam31.sh` as a strict shell script that requires a non-empty inherited `HF_TOKEN`, invokes the pinned Hugging Face download inside the SAM service image, writes files through a temporary directory followed by atomic rename, hashes the final files, and unsets the token in the child shell. It must never use `set -x`, echo the token, or write it to the manifest. A later service start only verifies the manifest; it never downloads weights.

- [ ] **Step 4: Run unit/static checks.**

  Run:

  ```bash
  php tests/test_sam3_pack.php
  php tests/test_vision_packs.php
  python3 -m unittest discover -s packs/sam3/service -p 'test_*.py'
  ```

  Expected: passing without a GPU, checkpoint, or Hugging Face token.

- [ ] **Step 5: Commit the runtime boundary.**

  ```bash
  git add packs/sam3 tests/test_sam3_pack.php tests/test_vision_packs.php
  git commit -m "feat: upgrade sam3 pack runtime to sam 3.1"
  ```

## Task 2: Implement one official SAM 3.1 adapter for image and video requests

**Files:**
- Create: `packs/sam3/service/sam31.py`
- Create: `packs/sam3/service/jobs.py`
- Modify: `packs/sam3/service/app.py`
- Modify: `packs/sam3/service/geometry.py`, `packs/sam3/service/geometry_smoke.py`
- Create: `packs/sam3/service/test_sam31.py`
- Create: `packs/sam3/service/test_jobs.py`

- [ ] **Step 1: Write fake-predictor tests before importing Torch.**

  In `test_sam31.py`, inject a fake predictor whose `handle_request` records calls. Assert that one image creates a one-frame private session, emits one `start_session`, one normalized `add_prompt`, one `close_session`, and deletes the temporary input even when inference raises. Cover text, points with labels, boxes, `guidance_mask` bounding box, zero result, and invalid mixed prompt types.

  In `test_jobs.py`, assert that `prompts_json` accepts 1–16 objects with unique `track_key`, `frame_index >= 0`, and exactly one prompt type; reject a seventeenth object, duplicate key, NaN coordinate, long text, and a frame index beyond the decoded input. Assert artifact JSON contains only counts, frame indexes, boxes, scores, masks, and `track_key` values—not a local path or source URL.

- [ ] **Step 2: Run the new Python tests and confirm they fail.**

  Run: `python3 -m unittest packs.sam3.service.test_sam31 packs.sam3.service.test_jobs`

  Expected: import/module failures because the adapter and jobs do not exist.

- [ ] **Step 3: Add the smallest shared adapter.**

  `sam31.py` owns the only model-loading function:

  ```python
  def load_predictor(checkpoint: Path, device: str):
      return build_sam3_multiplex_video_predictor(
          checkpoint_path=str(checkpoint), max_num_objects=16,
          multiplex_count=16, warm_up=False,
      )
  ```

  It calls the predictor only through `handle_request` / `handle_stream_request`, always closes sessions in `finally`, bounds generated frame folders to the request workspace, normalizes legacy points and boxes to `rel_coordinates=True`, and converts output masks through the existing geometry serializer. Do not maintain a second image model cache or load a legacy SAM checkpoint.

  Add explicit `release_predictor()` that deletes the predictor, forces Python garbage collection, and performs CUDA synchronization/cache release when CUDA is active. `app.py` uses it after every synchronous request; the resident job owns its predictor only for the job lifetime.

  `jobs.py` reuses the same prompt normalization for image task and video task. It decodes only the staged local MP4/JPEG frames, starts one SAM 3.1 session, adds bounded prompts, streams propagation, polls the existing cancellation flag between frames, and writes only static contract output files through temp-file + rename.

- [ ] **Step 4: Preserve the current direct HTTP contract.**

  In `app.py`, retain `POST /segment/image` and the existing multipart field validation/JSON-or-PNG response shape. Replace only the old inference invocation with `sam31.segment_single_image`. Keep mock mode deterministic. Map internal errors to the approved safe codes (`model_not_present`, `model_access_required`, `model_load_failed`, `inference_timeout`, `inference_failed`) without a traceback, path, checkpoint name, or model token in the response.

  Protect model use with a single process lock shared by `/segment/image` and `/internal/jobs`; this matches the one-GPU Pack contract. The health response reports the safe model status and Pack version but never a resolved model path.

- [ ] **Step 5: Run adapter, geometry, and Pack checks.**

  Run:

  ```bash
  python3 -m unittest packs.sam3.service.test_sam31 packs.sam3.service.test_jobs
  python3 packs/sam3/service/geometry_smoke.py
  php tests/test_sam3_pack.php
  ```

  Expected: all pass without weights; existing geometry output remains valid.

- [ ] **Step 6: Commit the reusable SAM 3.1 inference core.**

  ```bash
  git add packs/sam3/service tests/test_sam3_pack.php
  git commit -m "feat: add sam 3.1 image and video adapter"
  ```

## Task 3: Dispatch the three public operations and route async work through Pack jobs

**Files:**
- Create: `app/sam3.php`
- Modify: `app/gateway.php`
- Modify: `app/pack_registry.php`
- Modify: `app/pack_job_runner.php`
- Modify: `tests/test_sam3_pack.php`
- Modify: `tests/test_gpu_leases.php`

- [ ] **Step 1: Add failing gateway tests.**

  In `test_sam3_pack.php`, call `hub_gateway_dispatch()` with a test `sam3` service and assert:

  ```php
  hub_test_assert_same('/segment/image', $captured['path']); // omitted operation
  hub_test_assert_same('/segment/image', $captured['path']); // operation=image
  hub_test_assert_same('pack_job', hub_get_task($db, $taskId)['task_type']); // image_task/video_task
  ```

  Assert the operation field is not proxied, only the three names are accepted, missing image/video inputs fail before task creation, and caller keys such as `pack_id`, `job`, `runner`, `checkpoint`, `device`, and `source_path` cannot alter the fixed route. Assert the normal `sam3` mode token is required for all three operations.

- [ ] **Step 2: Run the focused tests and confirm failure.**

  Run: `php tests/test_sam3_pack.php`

  Expected: `image_task` and `video_task` are treated as a normal service proxy request.

- [ ] **Step 3: Add a narrow SAM dispatcher, not a generic operation framework.**

  Add `hub_sam3_dispatch(PDO $db, array $service, array $request, ?callable $requester): array` in `app/sam3.php`. In `hub_gateway_dispatch`, invoke it only after normal method/token/service authorization succeeds and only when the resolved service is Pack `sam3`. It:

  1. defaults absent `operation` to `image`, strips it for the legacy proxy, and performs the monitor-busy check before a real synchronous image inference;
  2. resolves a fixed stored route for `segment_image` then stages an owned image upload for `image_task`;
  3. validates and stages either an owned MP4 upload or a source reference for `video_task`, then resolves the fixed `track_video` route;
  4. returns the existing Pack-task submit envelope/status URLs, never a custom task format.

  Do not put `sam3` into `hub_pack_job_async_routes()` because that table represents one top-level mode per job. Keep this operation switch local to SAM3.

- [ ] **Step 4: Serialize direct real inference with the existing GPU lease.**

  Add small SAM-specific acquire/finish helpers in `app/sam3.php`: create a short-lived runtime run, acquire the existing `gpu:0` lease before proxying real inference, and release the exact fence after the service returns. Do not call audio cleanup or stop the SAM Compose service. Mock image responses require no GPU lease. If acquisition cannot occur, return the existing safe GPU error; if an active SAM monitor owns the slot, return HTTP 409 with `sam3_monitor_busy`.

  In `hub_run_pack_job_task`, leave generic ordering intact. Add only a SAM `track_video` pre-GPU source-capture hook (implemented in Task 4); after it writes the managed local MP4, normal runner asset resolution, GPU lease, cancellation, artifact finalization, retry, and retention remain unchanged.

- [ ] **Step 5: Run focused PHP tests.**

  Run:

  ```bash
  php tests/test_sam3_pack.php
  php tests/test_gpu_leases.php
  php tests/test_pack_job_artifacts.php
  ```

  Expected: direct image is backward compatible; both task operations snapshot immutable `0.2.0` contracts; no route executes while an owned lease is missing.

- [ ] **Step 6: Commit the gateway and task integration.**

  ```bash
  git add app/gateway.php app/pack_registry.php app/pack_job_runner.php app/sam3.php tests
  git commit -m "feat: add sam3 image and video task operations"
  ```

## Task 4: Add a fail-closed source registry and bounded pre-GPU capture

**Files:**
- Modify: `app/db.php`
- Create: `app/sam3_sources.php`
- Modify: `app/pack_job_runner.php`
- Create: `packs/sam3/service/capture.py`
- Create: `packs/sam3/service/capture-entrypoint.sh`
- Create: `packs/sam3/service/test_capture.py`
- Modify: `tests/test_sam3_pack.php`, `tests/test_job_first_schema.php`, `tests/suites/control-plane.php`

- [ ] **Step 1: Write source-boundary and migration tests.**

  Add PHP tests that a non-system-admin cannot create/edit/enable/stop a source; RTSP accepts only `rtsp`/`rtsps` literal private IP hosts and rejects credentials, query, fragment, a hostname, public IP, or unexpected port. Test HTTPS HLS requires a `.m3u8` path, port 443, no user info/query/fragment, an exact configured HLS hostname, and a resolved public address. Assert returned task/result/audit arrays do not contain the canonical URL.

  Add missing-table cases to `test_job_first_schema.php` for `sam3_sources`, `sam3_monitor_runs`, and `sam3_monitor_event_artifacts`; `hub_migrate()` must restore them plus indexes.

  In `test_capture.py`, use a fake subprocess runner and resolver to assert all FFmpeg arguments are arrays (never a shell string), duration/size probes reject over `60` seconds or `512 MiB`, every HLS redirect/segment target is revalidated, and safe error output excludes input URLs and stderr.

- [ ] **Step 2: Confirm tests fail.**

  Run:

  ```bash
  php tests/test_sam3_pack.php
  php tests/test_job_first_schema.php
  python3 -m unittest packs.sam3.service.test_capture
  ```

  Expected: source functions/tables and capture module are absent.

- [ ] **Step 3: Create the minimal durable source state.**

  Bump `HUB_DB_MIGRATION_VERSION`; add these tables and indexes through the existing idempotent migration path:

  ```sql
  sam3_sources(id, service_id, source_id UNIQUE, display_name, protocol,
               canonical_url, enabled, clip_seconds, monitor_sample_seconds,
               last_safe_error_code, created_by, created_at, updated_at)
  sam3_monitor_runs(id, source_id UNIQUE, service_id, task_id, runtime_run_id UNIQUE,
                    state, last_heartbeat_at, started_at, stopped_at, last_safe_error_code)
  sam3_monitor_event_artifacts(id, runtime_run_id, sequence, artifact_id,
                               created_at, UNIQUE(runtime_run_id, sequence))
  ```

  Add `hub_sam3_source_validate()` and `hub_sam3_source_resolve_for_task()` in `app/sam3_sources.php`. Keep URL storage server-side only. The former validates canonical components; the latter rechecks enabled/service association and resolves HLS DNS immediately before capture. Expose a separate `AIHUB_SAM3_HLS_ALLOWED_HOSTS` system setting parsed with the existing hostname-normalization helper; do not reuse a public web-capture list with different intent.

- [ ] **Step 4: Capture a source before acquiring a GPU.**

  For source-based `track_video`, `hub_sam3_prepare_video_input()` performs the short CPU capture before `hub_runtime_gpu_acquire_for_task()`. It writes only `workspace/input/source.mp4` via a temporary file, invokes a fixed container entrypoint with argument arrays, probes its decoded duration, and has a 512 MiB filesystem/output limit. MP4 uploads use the same ffprobe duration check but no network capture.

  `capture.py` has two fixed policies: RTSP permits only its validated private literal address and port; HTTPS HLS permits only the re-resolved allowlisted public address and rejects redirects/segments that leave it. The container starts unprivileged, has no user-supplied Docker options or command, and the entrypoint redirects raw FFmpeg diagnostics to a private temporary log while emitting one approved safe error code. It deletes partial capture on any failure.

- [ ] **Step 5: Run the source/capture regression set.**

  Run:

  ```bash
  php tests/test_sam3_pack.php
  php tests/test_job_first_schema.php
  python3 -m unittest packs.sam3.service.test_capture
  php scripts/run_tests.php --suite=control-plane
  ```

  Expected: invalid URLs are rejected before any subprocess; a source task cannot consume GPU until a bounded local MP4 exists.

- [ ] **Step 6: Commit source intake.**

  ```bash
  git add app/db.php app/sam3_sources.php app/pack_job_runner.php packs/sam3/service tests
  git commit -m "feat: add bounded sam3 stream source capture"
  ```

## Task 5: Finish asynchronous image/video artifact execution

**Files:**
- Modify: `packs/sam3/service/jobs.py`, `packs/sam3/service/app.py`, `packs/sam3/service/geometry.py`
- Modify: `packs/sam3/pack.json`
- Modify: `tests/test_sam3_pack.php`, `tests/test_pack_job_artifacts.php`
- Modify: `packs/sam3/service/test_jobs.py`

- [ ] **Step 1: Extend tests for immutable artifacts and cancellation.**

  Add contract fixtures for a PNG mask and a short valid MP4 overlay. Assert image-task output contains the same geometry/mask representation as direct image output; video-task output contains JSONL entries with `frame_index`, `track_key`, `boxes`, `scores`, and mask data. Reject an undeclared output, symlink, malformed JSONL, non-video overlay, or URL/path leakage. In the Python job test, cancel after a frame and assert no final success report is written.

- [ ] **Step 2: Run tests to see the incomplete contract.**

  Run:

  ```bash
  php tests/test_pack_job_artifacts.php
  python3 -m unittest packs.sam3.service.test_jobs
  ```

  Expected: the current manifest/service cannot produce all declared SAM job artifacts.

- [ ] **Step 3: Implement only the three fixed service jobs.**

  Add private authenticated resident endpoints in `app.py` following the existing GPT-SoVITS internal-job protocol: `POST /internal/jobs`, `GET /internal/jobs/{run_id}`, and `POST /internal/jobs/{run_id}/cancel`. Verify the generated internal token with constant-time comparison and return only the run ID/state/safe code.

  `segment_image` invokes the single-frame adapter and writes `sam3_image_report.json` plus `sam3_masks.json`. `track_video` invokes the multiplex predictor once for the staged MP4, adds at most 16 normalized prompts, streams output to `sam3_tracks.jsonl`, and optionally renders `sam3_overlay.mp4`. Both write reports last only after every required output validates locally. Keep direct requests and jobs on the same service lock; do not add multiple predictor pools.

- [ ] **Step 4: Run artifact and service tests.**

  Run:

  ```bash
  python3 -m unittest packs.sam3.service.test_sam31 packs.sam3.service.test_jobs packs.sam3.service.test_capture
  php tests/test_pack_job_artifacts.php
  php tests/test_sam3_pack.php
  ```

  Expected: exactly the manifest's static artifacts publish, with existing authorization and retention paths unchanged.

- [ ] **Step 5: Commit asynchronous execution.**

  ```bash
  git add packs/sam3 tests/test_sam3_pack.php tests/test_pack_job_artifacts.php
  git commit -m "feat: run sam3 image and video jobs"
  ```

## Task 6: Add one resident monitor with liveness, cancellation, and live event artifacts

**Files:**
- Modify: `app/sam3.php`, `app/sam3_sources.php`, `app/pack_job_runner.php`, `app/task_queue.php`
- Modify: `packs/sam3/service/app.py`, `packs/sam3/service/jobs.py`
- Modify: `tests/test_sam3_pack.php`, `tests/test_gpu_leases.php`
- Modify: `packs/sam3/service/test_jobs.py`

- [ ] **Step 1: Add failing monitor lifecycle tests.**

  Test that only one enabled source can own a monitor run; a second start returns `monitor_already_running`. While monitor state is active, direct `image` returns 409 `sam3_monitor_busy`, and image/video Pack jobs remain `waiting_gpu` rather than run. Test stop delivers resident cancellation, a stale heartbeat causes a service-status check before lease release, and replacement is permitted only after the exact run is terminal.

  Create a fake resident stage with `outbox/event-000001.json` and an optional overlay. Assert the collector validates fixed event keys (`sequence`, `observed_at`, `change_score`, `track_count`, `frame_count`), registers an artifact exactly once with `UNIQUE(runtime_run_id, sequence)`, and rejects an oversized/malformed event or a path traversal. Assert an event body never publishes source URL, raw frame data, private stage path, or stderr.

- [ ] **Step 2: Run the tests and confirm failure.**

  Run:

  ```bash
  php tests/test_sam3_pack.php
  php tests/test_gpu_leases.php
  python3 -m unittest packs.sam3.service.test_jobs
  ```

  Expected: no monitor route/outbox/liveness behaviour exists.

- [ ] **Step 3: Reuse the resident-runner protocol with one SAM callback.**

  Start `monitor` by constructing the fixed resident `pack_job` route and a `sam3_monitor_runs` row, then run it through the existing `hub_pack_job_resident_executor`. Add one optional `resident_progress_callback` context callable; it runs at the existing heartbeat cadence, updates `last_heartbeat_at`, and calls `hub_sam3_collect_monitor_outbox()`. Do not fork a new daemon, queue, or artifact transport.

  The collector accepts only regular files named `event-%06d.json` and `overlay-%06d.mp4`, copies them atomically into the task workspace, registers them through the normal artifact mechanism, then records the unique sequence. It never trusts arbitrary stage filenames or passes event JSON through unchecked.

- [ ] **Step 4: Make the monitor bounded and recoverable in the service.**

  Implement `monitor` in `jobs.py` as repeated bounded source clips with a finite exponential reconnect policy. After its retry budget it writes the safe `source_unavailable` terminal status; it does not loop indefinitely inside a request. It polls cancellation between clips and emits the final report/JSONL only on terminal completion.

  On worker startup, `hub_sam3_reconcile_monitors()` selects stale rows, asks the resident service for the exact `runtime_run_id` state, and releases the GPU fence only after terminal confirmation or existing generic unconfirmed-run reconciliation proves no process remains. Never free a lease merely because the timestamp expired.

- [ ] **Step 5: Integrate cancel semantics for a running monitor.**

  Extend `hub_cancel_task()` only for a running SAM resident monitor: mark cancellation intent and invoke the existing resident cancel handler; preserve its normal fence/terminal transaction. Queued/waiting Pack-job cancellation remains untouched. The admin stop action calls this path and is idempotent (`monitor_not_running` when there is no active run).

- [ ] **Step 6: Run monitor regression checks.**

  Run:

  ```bash
  php tests/test_sam3_pack.php
  php tests/test_gpu_leases.php
  python3 -m unittest packs.sam3.service.test_jobs
  ```

  Expected: a monitor holds the sole GPU slot, live artifacts are idempotent, and crash recovery cannot create duplicate monitoring or an unfenced release.

- [ ] **Step 7: Commit resident monitoring.**

  ```bash
  git add app/sam3.php app/sam3_sources.php app/pack_job_runner.php app/task_queue.php packs/sam3/service tests
  git commit -m "feat: add sam3 resident stream monitoring"
  ```

## Task 7: Expose system-admin controls and document the one-mode API

**Files:**
- Create: `admin/sam3_sources.php`
- Modify: `admin/service_settings.php`
- Modify: `app/public_api_docs.php`
- Modify: `docs/api_examples.md`, `docs/client_quickstart.md`, `README.md`
- Create: `docs/operations/sam31-deploy-and-monitor.md`
- Modify: `tests/test_sam3_pack.php`, `tests/test_public_api_docs.php`

- [ ] **Step 1: Write UI/docs contract tests.**

  Test that the source page rejects non-system-admin access and missing/invalid CSRF tokens; a system admin sees only source ID, display name, protocol, enabled/state, and safe error—not canonical URL. Verify POST actions are audited with source IDs and safe codes only. Add documentation tests ensuring the public API has only `mode=sam3` and lists `image`, `image_task`, and `video_task`, while no example accepts `rtsp://`, an HLS URL, an HF token, or a filesystem model path.

- [ ] **Step 2: Run the tests and confirm they fail.**

  Run: `php tests/test_sam3_pack.php && php tests/test_public_api_docs.php`

  Expected: the source page and operation-specific docs do not exist.

- [ ] **Step 3: Add the minimal admin page.**

  Implement one `admin/sam3_sources.php` system-admin page using existing authentication, CSRF, validation, flash, and audit helpers. It lists sources by opaque ID and permits create/edit/enable/disable/start/stop; it neither renders nor accepts a public raw source URL. Link to it from the SAM3 area of `admin/service_settings.php` and add the HLS allowlist field there.

  Do not add a general stream management subsystem, video gallery, credentials UI, or a monitor dashboard. Existing task/status/artifact pages remain the monitor result UI.

- [ ] **Step 4: Document exact request and deployment flows.**

  In public docs, show the legacy multipart image call unchanged and operation-specific examples:

  ```text
  POST api.php?mode=sam3                         # synchronous image
  POST api.php?mode=sam3&operation=image_task    # owned image task
  POST api.php?mode=sam3&operation=video_task    # MP4 or source_id task
  ```

  `docs/operations/sam31-deploy-and-monitor.md` must give the exact host sequence: `git pull`; `php scripts/init_db.php`; accept model terms; invoke `HF_TOKEN=... bash packs/sam3/scripts/provision_sam31.sh`; rebuild/restart the existing `sam3` service; enable real inference; run acceptance. Include safe rollback: stop monitor, disable service, restore the previous revision, rebuild/restart, and preserve normal artifact retention. State that a `0.1.0` stored job reports `pack_version_unavailable`, never gets reinterpreted.

- [ ] **Step 5: Run admin/doc checks.**

  Run:

  ```bash
  php tests/test_sam3_pack.php
  php tests/test_public_api_docs.php
  php scripts/run_tests.php --suite=admin-ui
  ```

  Expected: controls are system-admin-only and the public contract documents one mode without secrets or source URLs.

- [ ] **Step 6: Commit the admin and operational handoff.**

  ```bash
  git add admin app/public_api_docs.php README.md docs tests
  git commit -m "docs: document sam3.1 operations and deployment"
  ```

## Task 8: Run the release gate and record hardware acceptance separately

**Files:**
- Modify if required by failures: files named by the regression output only
- Modify: `docs/operations/sam31-deploy-and-monitor.md`

- [ ] **Step 1: Run all deterministic checks from a clean checkout.**

  Run:

  ```bash
  php scripts/run_tests.php --suite=control-plane
  php scripts/run_tests.php --suite=admin-ui
  php scripts/run_tests.php --suite=voice-cluster
  python3 -m unittest discover -s packs/sam3/service -p 'test_*.py'
  git diff --check
  ```

  Expected: all pass without a model download, stream access, or GPU.

- [ ] **Step 2: Run the GPU-host acceptance after provisioning.**

  On each intended GPU host, after the documented provisioning sequence, run one real legacy image request, one `image_task`, one local MP4 `video_task` with 16 prompts, one approved source capture, and monitor start/stop/recovery. Record only GPU name, Pack version, model manifest checksum, elapsed time, frame/object counts, and output checksums in the operations guide; do not store camera URLs, frames, credentials, model tokens, or raw logs in Git.

- [ ] **Step 3: Confirm upgrade/rollback compatibility.**

  Queue a synthetic stored `sam3` `0.1.0` task and assert the worker returns `pack_version_unavailable`; do not execute it with `0.2.0`. Exercise the documented rollback only after stopping the monitor and verify task artifacts remain under the existing retention policy.

- [ ] **Step 4: Commit only concrete release-gate fixes and the redacted acceptance record.**

  ```bash
  git add -u docs/operations/sam31-deploy-and-monitor.md
  git commit -m "test: verify sam3.1 pack release gate"
  ```

  Skip this commit when no tracked file changed; do not manufacture a no-op commit.

## Final acceptance checklist

- [ ] Existing `mode=sam3` direct image clients need no request change and receive the legacy JSON/PNG response shape.
- [ ] `image_task` and `video_task` use immutable `sam3` 0.2.0 contracts and existing task/artifact/retention authorization.
- [ ] All actual inference uses the pinned official SAM 3.1 multiplex predictor and the provisioned `sam3.1_multiplex.pt`; runtime never downloads weights.
- [ ] Video is capped at 512 MiB and 60 decoded seconds; prompts are capped at 16; source capture precedes GPU allocation.
- [ ] Raw source URLs/credentials are absent from public API, public responses, events, artifacts, audits, Compose, and logs. HLS and RTSP remain fail-closed at every trust boundary.
- [ ] A monitor is system-admin-controlled, owns `gpu:0`, publishes validated live events idempotently, stops cleanly, and cannot be duplicated or unfenced after a crash.
- [ ] PHP and Python deterministic suites pass without model access; each GPU host has a separate redacted real-inference acceptance record.
