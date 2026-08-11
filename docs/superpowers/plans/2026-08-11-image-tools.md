# Image Tools Pack Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the `image-tools` HubPack with one production operation, Real-ESRGAN image upscaling, exposed through synchronous and asynchronous APIs with strict image/Base64 validation and explicit `auto|cuda|cpu` backend control.

**Architecture:** Keep `image-birefnet` untouched. Add one FastAPI Pack image with a stateless sync endpoint and the same pinned runner image for async jobs. The PHP Gateway owns public-operation normalization, strict form/query consistency, Base64-to-temporary-upload conversion, and async staging; the Python runtime owns byte-level image verification, output limits, offline model checks, backend execution, and PNG generation. Async `auto` resolves once, at submission, to a CPU or GPU job using the existing service settings and GPU preflight, then uses the existing CPU queue or GPU lease/container runner.

**Tech Stack:** PHP 8/PDO/SQLite, existing Pack registry/gateway/task runner, FastAPI, Pillow, PyTorch, pinned Real-ESRGAN source, Docker Compose/NVIDIA runtime, existing PHP test runner, Python `unittest`.

---

## Fixed Decisions

- Pack id and public mode are both `image-tools`; existing `background_remove` routing remains unchanged.
- Public operations are exactly `upscale` and `upscale_task`. There are no colorize, restoration, video, YOLO, or Vulkan routes in this release.
- Accept exactly one client source: `image` upload or `base64_string`. The existing generic `source_artifact_id` path remains an internal async-task capability only; it is not added to the first public examples.
- Accepted decoded formats are Pillow-confirmed `JPEG`, `PNG`, `WEBP`, and `BMP`; file name, suffix, and client MIME are never trusted.
- Limits: 50 MiB decoded source, 8,192 px per axis, 4 MP sync source, 10 MP async source, 64 MP output, and 70 MiB Gateway body. The chosen 2x/3x/4x scale must also fit the 64 MP output ceiling.
- Models are exact aliases: `realesrgan-x4plus`, `realesrgan-x4plus-anime`, `realesr-animevideov3-x2`, `realesr-animevideov3-x3`, `realesr-animevideov3-x4`. The anime-video aliases share the official `realesr-animevideov3.pth` asset and differ only in output scale.
- Pin the Real-ESRGAN source to `a4abfb2979a7bbff3f69f58f58ae324608821e27`; never download code or weights during a request. Stage assets under `/DATA/models/image-tools/realesrgan` and verify `ready.json` checksums before inference.
- `IMAGE_TOOLS_DEFAULT_BACKEND=auto` is an installation setting. Explicit `cuda` fails with `backend_unavailable` when unavailable; only `auto` may resolve to CPU. CPU uses fp32 and CUDA uses fp16.
- Async has two fixed internal jobs, `upscale_image_gpu` and `upscale_image_cpu`; its public operation remains `upscale_task`. This reuses the existing runner contract instead of adding a dynamic accelerator abstraction.
- Both sync and async share the approved error vocabulary from the design: `file_required`, `source_ambiguous`, `invalid_base64`, `unsupported_media_type`, `invalid_image`, `invalid_operation`, `invalid_model`, `invalid_backend`, `invalid_request`, `backend_unavailable`, `model_not_present`, `model_load_failed`, `payload_too_large`, `runtime_not_ready`, and `inference_failed`.

## File Structure

| File | Responsibility |
| --- | --- |
| `packs/image-tools/pack.json` | Pack, settings, sync API, both async runner contracts, limits, artifacts, and model mount declaration. |
| `packs/image-tools/docker-compose.yml` | GPU-first local development Compose; generated CPU install removes GPU allocation. |
| `packs/image-tools/service/{Dockerfile,requirements.txt}` | Reproducible Python/Real-ESRGAN runtime and build-time unit checks. |
| `packs/image-tools/service/image_contract.py` | Pure request/Base64/image/model/backend/output validation. |
| `packs/image-tools/service/model_runtime.py` | Pinned model manifest/checksum verification and explicit backend selection. |
| `packs/image-tools/service/upscale_runner.py` | Argument-array CLI that performs one Real-ESRGAN inference and writes one PNG. |
| `packs/image-tools/service/{app.py,jobs.py}` | Sync FastAPI endpoint and async workspace adapter, both invoking the same CLI. |
| `packs/image-tools/service/{provision_offline_assets.py,storage_smoke.py,model_smoke.py,inference_smoke.py,acceptance.py}` | Offline staging and CPU/CUDA real-runtime acceptance helpers. |
| `packs/image-tools/service/test_*.py` | Deterministic unit coverage for the trust boundary and runner command/cleanup behavior. |
| `packs/image-tools/demo/smoke.png` | Small redistributable image fixture for HTTP smoke tests. |
| `app/{gateway.php,pack_registry.php}` | Narrow image-tools routing, staged source handling, fixed async route selection, and approved `Backend` response metadata. |
| `app/{customer_accounts.php,public_api_docs.php}` | Playground allowlist and operation-aware public documentation rendering. |
| `admin/playground.php` | One image-tools form, operation selector, backend/model controls, PNG preview, and async example/result guidance. |
| `packs/catalog.json`, `README.md`, `docs/operations/image-tools.md` | Catalog entry, public examples, and operator runbook. |
| `tests/test_image_tools.php` | PHP contract, gateway, task-route, docs, Compose, artifact, and Playground regressions. |

## Task 1: Establish the Pack Contract and Catalog Entry

**Files:**
- Create: `packs/image-tools/pack.json`
- Create: `packs/image-tools/docker-compose.yml`
- Create: `tests/test_image_tools.php`
- Modify: `packs/catalog.json`

- [ ] **Step 1: Write the failing PHP contract test**

Add a test that requires a valid `image-tools` Pack, `execution_type: sync_api`, `default_mode: image-tools`, `gateway.invoke_path: /process/image`, `gateway.health_path: /health`, a 70 MiB Gateway request cap, `queue.max_concurrency: 1`, and a PNG output contract with these exact forwarded headers:

```php
[
    'X-3waAIHub-Model',
    'X-3waAIHub-Backend',
    'X-3waAIHub-Elapsed-Ms',
    'X-3waAIHub-Width',
    'X-3waAIHub-Height',
]
```

Assert the two operation descriptors, five model aliases, three backend values, exact limit values, the complete stable error list, exactly two async jobs in this order (`upscale_image_gpu`, `upscale_image_cpu`), a read-only `image-tools/realesrgan` model mount, and artifact contracts for `upscaled_image.png` plus `upscale_report.json`. Also assert that no deferred operation name occurs in the manifest or catalog entry.

- [ ] **Step 2: Run the focused test and confirm it fails**

Run:

```bash
php scripts/run_tests.php
```

Expected: failure because the Pack does not exist.

- [ ] **Step 3: Add the smallest complete manifest and Compose contract**

Create `pack.json` with a single sync service and two runner contracts. Both runner entrypoints use the same image and a fixed argument array:

```json
"entrypoint": ["python3", "/app/jobs.py"],
"args": ["--request", "/workspace/input/request.json", "--source", "/workspace/input/source", "--output-dir", "/workspace/output"],
"output_dir": "output",
"network_profile": "isolated"
```

Set the GPU job `accelerator` to `gpu` with a conservative declared VRAM floor; set the CPU job to `cpu` with `required_vram_mb: 0`. Both receive only `model` and resolved `backend` in `request_schema`; neither has client-controlled image path, command, output name, or model path fields.

Declare service settings for `IMAGE_TOOLS_USE_GPU`, `IMAGE_TOOLS_DEFAULT_BACKEND`, `IMAGE_TOOLS_MODEL_DIR`, `IMAGE_TOOLS_MAX_UPLOAD_MB`, and `IMAGE_TOOLS_PORT`. Default to GPU enabled and backend `auto`; the service itself remains able to make an explicit CPU request. Use the existing generated-Compose setting mechanism so `IMAGE_TOOLS_USE_GPU=0` omits `gpus: all` rather than introducing a second service template.

Register the Pack in `packs/catalog.json`. Keep `runtime_ready: false` and `runtime_level: L1-contract` until the storage/runtime tasks are complete.

- [ ] **Step 4: Verify manifest and generated install behavior**

Run:

```bash
php scripts/run_tests.php
php -r 'require "app/bootstrap.php"; $p=hub_get_pack("image-tools"); echo $p["manifest"]["default_mode"], PHP_EOL;'
```

Expected: the test passes and the command prints `image-tools`.

- [ ] **Step 5: Commit the L1 contract**

```bash
git add packs/image-tools/pack.json packs/image-tools/docker-compose.yml packs/catalog.json tests/test_image_tools.php
git commit -m "feat: add image tools pack contract"
```

## Task 2: Add the Gateway Trust Boundary for Sync Requests

**Files:**
- Modify: `app/gateway.php`
- Modify: `tests/test_image_tools.php`

- [ ] **Step 1: Add failing Gateway tests before implementation**

Exercise `hub_gateway_dispatch()` with a stub requester and temporary fixtures. Cover all of these before writing the helper:

1. Query-only `operation=upscale` is injected into the form sent to `/process/image`.
2. Query/form duplicates for `operation`, `backend`, and `model` must byte-match; a conflict returns `400 invalid_request` without calling the requester.
3. Missing, unknown, NUL/control-containing, path-like, overlong, and array-valued fields fail before proxying.
4. Exactly one of upload/Base64 is required; none gives `file_required`, both gives `source_ambiguous`.
5. Strict Base64 accepts raw payload and only `data:image/(jpeg|png|webp|bmp);base64,`; malformed alphabet, padding, oversize encoded data, and `data:image/gif` return `invalid_base64`.
6. A valid Base64 source becomes one short-lived normalized upload passed to the requester, never an unbounded form string; its raw value is absent from the forwarded form and the temporary path no longer exists after dispatch.
7. The proxy permits only a valid `X-3waAIHub-Backend: cuda|cpu`, alongside the already validated model/elapsed/dimensions headers. Reject `vulkan`, `TPU`, controls, and duplicate-invalid headers.

- [ ] **Step 2: Run the test and confirm the current Gateway fails**

Run:

```bash
php scripts/run_tests.php
```

Expected: failures for the missing Pack-specific request path and `Backend` metadata allowlist.

- [ ] **Step 3: Implement a narrow image-tools normalizer**

Add Pack-specific helpers near the existing gateway payload preparation code; do not change global multipart behavior for unrelated Packs. The entry helper receives the resolved query/form/files and returns either a Gateway error or normalized scalar fields, one file record, and cleanup paths.

Use these constraints in the helper:

```php
$allowed = ['operation', 'backend', 'model', 'base64_string'];
$operations = ['upscale', 'upscale_task'];
$backends = ['auto', 'cuda', 'cpu'];
$models = [
    'realesrgan-x4plus', 'realesrgan-x4plus-anime',
    'realesr-animevideov3-x2', 'realesr-animevideov3-x3', 'realesr-animevideov3-x4',
];
```

Do not trim API enum values. Reject control characters, arrays, unknown keys, and non-exact enum strings. Resolve omitted `backend` from the installed service's `IMAGE_TOOLS_DEFAULT_BACKEND`, then validate it through the same enum. Build a Base64 temporary file with `tempnam(sys_get_temp_dir(), '3waaihub_image_tools_')`, set mode `0600`, and use only a constant synthetic filename such as `source.bin`; never use a user filename as a host path.

Scope the normalized `$_POST`/`$_FILES` around the proxy or async handoff and remove every created file in `finally`. The existing `hub_proxy_post_fields()` already converts this file record into a `CURLFile`; reuse it. Extend only the existing response-header validator for the new `X-3waAIHub-Backend` header and its two allowed values.

- [ ] **Step 4: Verify the sync trust boundary**

Run:

```bash
php scripts/run_tests.php
php scripts/run_tests.php --suite=control-plane
```

Expected: all image-tools request-rejection cases pass, and existing binary Pack response-header tests remain green.

- [ ] **Step 5: Commit the Gateway boundary**

```bash
git add app/gateway.php tests/test_image_tools.php
git commit -m "feat: validate image tools gateway requests"
```

## Task 3: Route Async Work to a Fixed CPU or GPU Contract

**Files:**
- Modify: `app/pack_registry.php`
- Modify: `app/gateway.php`
- Modify: `tests/test_image_tools.php`

- [ ] **Step 1: Write failing async-route and staging tests**

Add test coverage that installs a GPU-enabled `image-tools` service, enables it, gives its API member the `image-tools` permission, and verifies:

```php
$route = hub_resolve_image_tools_operation_route($db, 'upscale_task', 'cuda');
hub_test_assert(($route['job'] ?? '') === 'upscale_image_gpu', 'CUDA must use the GPU job');
hub_test_assert(($route['accelerator'] ?? '') === 'gpu', 'CUDA must retain GPU lease semantics');
```

Add equivalent CPU assertions. For `auto`, inject the existing GPU probe seam: successful configured CUDA capacity selects GPU; disabled CUDA or a failed/insufficient preflight selects CPU. An explicit `cuda` with disabled CUDA must return `503 backend_unavailable`, never silently enqueue CPU.

Through `POST mode=image-tools&operation=upscale_task`, assert that a Base64 source is staged by the existing owned-task flow, `source_upload_path` is populated, `base64_string` is absent from stored task input, the route snapshot is immutable, and revalidation maps both stored jobs back to `image-tools`. Add source ambiguity, malformed Base64, unapproved task input, and no-requester regressions.

- [ ] **Step 2: Run the focused test and confirm it fails**

Run:

```bash
php scripts/run_tests.php
```

Expected: no image-tools operation resolver exists and task submission is not intercepted.

- [ ] **Step 3: Reuse the SAM3 operation pattern without aliases**

In `app/pack_registry.php`, add only these narrow image-tools helpers:

```php
hub_image_tools_operation_route_definition(string $operation, string $backend): ?array
hub_image_tools_effective_async_backend(PDO $db, string $requestedBackend): string
hub_resolve_image_tools_operation_route(PDO $db, string $operation, string $backend): array
```

The resolver accepts only `upscale_task`. It reads the installed service setting once. For `auto`, choose GPU only when `IMAGE_TOOLS_USE_GPU=1` and `hub_runtime_gpu_probe()` has no probe error and enough free memory for the manifest's GPU job plus the existing safety margin; otherwise choose CPU. For explicit CUDA, require the same configured/preflight capability and throw `backend_unavailable` if absent. Return the normal `hub_resolve_pack_job_route_from_definition()` result so existing version, enabled-service, contract, queue, and runner validation stay authoritative.

Extend `hub_revalidate_pack_job_async_route()` with the two stored image-tools job names. It must resolve through the matching fixed backend and compare all existing route-snapshot fields; do not let retries re-evaluate `auto`.

In the `hub_gateway_dispatch()` Pack-specific operation block, normalize once, dispatch `upscale_task` directly to `hub_api_pack_job_task_submit()`, and route `upscale` onward to the sync proxy. Reuse `hub_stage_owned_pack_job()`, `hub_store_task_upload_file()`, `hub_update_task_input()`, and `hub_publish_staged_pack_job()`; do not add a parallel task or upload store. Remove raw Base64 from the payload before `hub_pack_job_task_input()` receives it.

- [ ] **Step 4: Verify both queues and immutable retry routing**

Run:

```bash
php scripts/run_tests.php
php scripts/run_tests.php --suite=full
```

Expected: GPU `auto` snapshots the GPU job only when preflight is ready, CPU `auto` snapshots CPU otherwise, and retries preserve the stored choice.

- [ ] **Step 5: Commit async routing**

```bash
git add app/pack_registry.php app/gateway.php tests/test_image_tools.php
git commit -m "feat: queue image tools upscaling jobs"
```

## Task 4: Implement Pure Python Input, Model, and Command Validation

**Files:**
- Create: `packs/image-tools/service/image_contract.py`
- Create: `packs/image-tools/service/model_runtime.py`
- Create: `packs/image-tools/service/test_image_contract.py`
- Create: `packs/image-tools/service/test_model_runtime.py`
- Create: `packs/image-tools/service/test_upscale_runner.py`
- Create: `packs/image-tools/service/requirements.txt`
- Create: `packs/image-tools/service/Dockerfile`

- [ ] **Step 1: Write deterministic unit tests first**

Use in-memory Pillow images and temporary directories. Test all accepted formats, reject GIF/TIFF/SVG/PDF/HEIC/text/truncated bytes, and verify `Image.verify()` followed by a fresh full load. Test every size/dimension/pixel/output-scale boundary, including x4 source `4_000_000` accepted versus `4_000_001` rejected for the 64 MP output cap.

Test strict Base64 parity with the Gateway, exact model aliases/scales, and explicit backend resolution: `auto` uses CUDA when available, `auto` selects CPU when unavailable, explicit CUDA raises `backend_unavailable`, and CPU never calls `.half()`.

Create a minimal `ready.json` with known bytes. Assert the loader rejects absent marker, wrong repository/commit, unlisted files, symlinks, altered checksums, and wrong asset names before model load. Test command construction is a literal argv list and rejects inputs/outputs outside its caller-provided temp directory; assert the temporary job directory is removed after both success and runner failure.

- [ ] **Step 2: Run Python tests and confirm imports fail**

Run:

```bash
python3 -m unittest -v packs/image-tools/service/test_image_contract.py packs/image-tools/service/test_model_runtime.py packs/image-tools/service/test_upscale_runner.py
```

Expected: import failures because the runtime modules do not exist.

- [ ] **Step 3: Implement the smallest shared validation modules**

`image_contract.py` must expose pure functions for Base64 decode, `decode_image`, model selection, backend resolution, and output ceiling validation. Decode through `BytesIO`, call `verify()`, reopen, `load()`, apply `ImageOps.exif_transpose`, then check decoded format/dimensions/pixels. Treat decompression-bomb warnings/errors as `invalid_image`. Convert to RGB only after validation.

`model_runtime.py` must allow exactly these three staged filenames:

```text
RealESRGAN_x4plus.pth
RealESRGAN_x4plus_anime_6B.pth
realesr-animevideov3.pth
```

`ready.json` lists relative path, byte size, SHA-256, upstream release URL, and the pinned source commit. Verify every listed regular file and reject unlisted files; use `local_files_only` behavior throughout. The runtime error surface contains only stable error codes, never host paths or Python tracebacks.

`requirements.txt` pins FastAPI, Uvicorn, Pillow, NumPy, PyTorch/Torchvision for the selected CUDA base, and `basicsr` plus the exact Real-ESRGAN Git commit. The Dockerfile uses the same supported CUDA/Python baseline as current GPU Packs, creates a non-root runtime user, copies only source, runs the three unit modules during build, and never invokes the provisioner during build.

- [ ] **Step 4: Run pure tests and an image build**

Run:

```bash
python3 -m unittest -v packs/image-tools/service/test_image_contract.py packs/image-tools/service/test_model_runtime.py packs/image-tools/service/test_upscale_runner.py
docker build -t 3waaihub-image-tools:test packs/image-tools/service
```

Expected: tests pass and the image build validates imports/tests without model downloads.

- [ ] **Step 5: Commit the runtime foundation**

```bash
git add packs/image-tools/service
git commit -m "feat: add image tools validation runtime"
```

## Task 5: Add Offline Assets, Shared Inference CLI, and Sync Service

**Files:**
- Create: `packs/image-tools/service/{provision_offline_assets.py,storage_smoke.py,model_smoke.py,upscale_runner.py,app.py,test_app.py}`
- Modify: `packs/image-tools/service/{Dockerfile,requirements.txt}`
- Modify: `packs/image-tools/pack.json`
- Modify: `tests/test_image_tools.php`

- [ ] **Step 1: Write failing service and provisioner tests**

Mock the model constructor/subprocess boundary; never require real weights for unit tests. Require `/health` to distinguish missing assets (`ready: false`) from a valid staged snapshot. Require `POST /process/image` to return `image/png`, the exact five metadata headers, expected scaled dimensions, and no body buffering in a global cache.

Test success and failure cleanup for an image-only `/tmp/image-tools-*` job workspace. Test that `subprocess.run()` receives a list with `shell=False`, includes a forced `--fp32` for CPU only, uses a model alias rather than a path, and writes only `output.png` inside the workspace. Verify `backend_unavailable`, `model_not_present`, `model_load_failed`, and `inference_failed` responses are stable JSON errors without paths.

For provisioning, use a local HTTP fixture and assert an atomic staging directory, SHA-256 manifest, restrictive file permissions, failure cleanup, and no replacement of a valid existing snapshot until the new complete marker is ready.

- [ ] **Step 2: Run the tests and confirm they fail**

Run:

```bash
python3 -m unittest -v packs/image-tools/service/test_app.py
```

Expected: imports/endpoints are missing.

- [ ] **Step 3: Implement offline staging and one inference path**

`provision_offline_assets.py` downloads only the official release asset URLs for the three fixed filenames into a fresh sibling directory, records their calculated SHA-256/size/provenance in `ready.json`, fsyncs the marker, and atomically activates the directory. It must reject redirects to non-HTTPS, unexpected filenames, and symlinks. Runtime code only reads the marker; it never calls `requests`, `torch.hub`, or an upstream downloader.

`upscale_runner.py` is the one actual inference CLI. It takes explicit `--input`, `--output`, `--model`, `--backend`, and `--model-dir` arguments, checks all inputs through the pure modules, selects the official Real-ESRGAN architecture for the alias, invokes the library without network access, and writes exactly one PNG. It emits a small JSON report on stdout with model/backend/width/height/elapsed_ms for its caller; no raw exception is emitted.

`app.py` owns an `asyncio`/thread lock of size one, validates the normalized upload again, creates a private temp job directory, invokes the CLI with `subprocess.run([...], shell=False, check=False)`, reads the returned PNG/report, and removes the workspace in `finally`. It returns direct PNG bytes with `X-3waAIHub-Backend`, not the legacy `Device` header. Run blocking image work via FastAPI's threadpool so `/health` stays responsive.

Promote the manifest only after model verification works: `runtime_level: L3-offline-assets` and `runtime_ready: true` after storage/model smoke passes; do not claim L4/L5 before real inference acceptance.

- [ ] **Step 4: Verify local unit behavior and gateway contract**

Run:

```bash
python3 -m unittest -v packs/image-tools/service/test_app.py
php scripts/run_tests.php
docker build -t 3waaihub-image-tools:test packs/image-tools/service
```

Expected: Python behavior, manifest assertions, and the Docker build all pass.

- [ ] **Step 5: Commit sync runtime**

```bash
git add packs/image-tools/service packs/image-tools/pack.json tests/test_image_tools.php
git commit -m "feat: add image tools sync upscaling"
```

## Task 6: Implement Async Job Output and Artifact Publication

**Files:**
- Create: `packs/image-tools/service/{jobs.py,test_jobs.py}`
- Modify: `packs/image-tools/pack.json`
- Modify: `tests/test_image_tools.php`

- [ ] **Step 1: Write failing runner tests**

Build an existing-style `/workspace/input/{source,request.json}` fixture and assert both declared jobs call the shared CLI once with the stored alias and their resolved backend. Assert `jobs.py` validates source bytes/dimensions again, rejects a stored backend/job mismatch, never accepts a path/command field from `request.json`, and writes exactly:

```text
/workspace/output/upscaled_image.png
/workspace/output/upscale_report.json
```

The report must contain `model`, `backend`, source/output dimensions, `elapsed_ms`, and SHA-256 of the output. Add runner-contract tests that reject missing/extra output files, non-PNG image output, oversized dimensions, invalid report JSON, mismatched backend metadata, and a GPU task whose request says CPU.

- [ ] **Step 2: Run the runner tests and confirm failure**

Run:

```bash
python3 -m unittest -v packs/image-tools/service/test_jobs.py
php scripts/run_tests.php
```

Expected: `jobs.py` and its output artifact declaration are missing.

- [ ] **Step 3: Implement the minimal async adapter**

`jobs.py` reads the existing runner's immutable `request.json` and mounted `source`; it does not parse HTTP or Base64. Compare `--backend` implied by the submitted runner job to the stored `backend`, create one private work directory, call `upscale_runner.py` with argv, validate its PNG/report through `image_contract.py`, atomically write the two declared artifact names to `/workspace/output`, and clean its temporary directory in `finally`.

Keep all container execution, network isolation, model read-only mount, CPU queue selection, GPU lease, timeout, cancellation, and artifact delivery in the existing `hub_pack_job_runner.php` machinery. Do not add a second worker, a custom queue table, or a resident service protocol.

- [ ] **Step 4: Verify async contract behavior**

Run:

```bash
python3 -m unittest -v packs/image-tools/service/test_jobs.py
php scripts/run_tests.php
php scripts/run_tests.php --suite=full
```

Expected: both fixed runners publish only the two contract artifacts, and unrelated Pack task tests remain green.

- [ ] **Step 5: Commit async artifacts**

```bash
git add packs/image-tools/service/jobs.py packs/image-tools/service/test_jobs.py packs/image-tools/pack.json tests/test_image_tools.php
git commit -m "feat: publish image tools upscale artifacts"
```

## Task 7: Publish Documentation and the Admin Playground

**Files:**
- Modify: `app/customer_accounts.php`
- Modify: `app/public_api_docs.php`
- Modify: `admin/playground.php`
- Modify: `README.md`
- Create: `docs/operations/image-tools.md`
- Modify: `tests/test_image_tools.php`

- [ ] **Step 1: Write failing documentation/UI regression tests**

Assert `image-tools` is in `hub_playground_supported_modes()`. Check that the Playground has one image picker, exact `upscale`/`upscale_task` operation choices, exact model/backend selects, an optional Base64 text area with no default sample payload, and no controls for a host path, URL, custom command, output filename, or deferred operation.

Assert a successful PNG response produces a preview/download named `upscaled-image.png` and renders `Model`, `Backend`, elapsed time, width, and height. Confirm public API docs and README include both `curl -F 'image=@...'` and Base64 examples, tell users that the async result uses the existing task/artifact endpoints, and list all allowed image formats/limits/error codes.

- [ ] **Step 2: Run the focused test and confirm failure**

Run:

```bash
php scripts/run_tests.php
```

Expected: the mode, docs, and Playground profile are absent.

- [ ] **Step 3: Reuse the existing binary Playground path**

Add a single `image_tools` profile patterned after `background_remove`; share the existing multipart/PNG parsing and preview code rather than duplicating an HTTP client. Add only the operation/model/backend fields and ensure the selected operation is sent both in the endpoint query and normalized form so the Gateway duplicate check can protect it.

Teach `hub_public_api_service_from_contract()` to render `l5_contract.operations` entries when present, so sync and async appear as separate examples under one mode; keep all static client strings exact and bounded. Update README with one concise sync upload, one Base64, and one async submit/poll/download sequence.

Write `docs/operations/image-tools.md` with: checksum/offline staging, Docker and CUDA preflight, GPU-first installation, explicit CPU installation, real sync CUDA/CPU smoke, async CUDA/CPU submit-poll-artifact flow, SHA-256 verification, cancellation/cleanup, rollback, and data-retention cautions. Reference the model marker instead of embedding model binaries or secrets.

- [ ] **Step 4: Verify rendered docs and UI contracts**

Run:

```bash
php scripts/run_tests.php
php scripts/run_tests.php --suite=control-plane
```

Expected: public inventory, Playground source contract, and static docs assertions pass.

- [ ] **Step 5: Commit public surfaces**

```bash
git add app/customer_accounts.php app/public_api_docs.php admin/playground.php README.md docs/operations/image-tools.md tests/test_image_tools.php
git commit -m "docs: publish image tools API and runbook"
```

## Task 8: Run Real Machine Acceptance and Finalize Runtime Readiness

**Files:**
- Create: `packs/image-tools/service/acceptance.py`
- Create: `packs/image-tools/service/test_acceptance.py`
- Modify: `packs/image-tools/service/inference_smoke.py`
- Modify: `packs/image-tools/pack.json`
- Modify: `docs/operations/image-tools.md`
- Modify: `tests/test_image_tools.php`

- [ ] **Step 1: Write acceptance helper tests**

Unit-test the helper's HTTP parsing with fixtures: require `image/png`, validate the five headers, decode the output with Pillow, compare expected scaled dimensions, require a matching report SHA-256 for async artifacts, and reject a CUDA response that reports CPU (or vice versa). Ensure logs redact authorization headers and never include raw Base64/image bytes.

- [ ] **Step 2: Run the helper test and confirm it fails**

Run:

```bash
python3 -m unittest -v packs/image-tools/service/test_acceptance.py
```

Expected: the acceptance helper is absent.

- [ ] **Step 3: Implement the real acceptance commands**

`inference_smoke.py` must invoke HTTP, not in-process functions, and accept `--expect-backend cuda|cpu`. `acceptance.py` runs the following separate cases with `packs/image-tools/demo/smoke.png`:

1. Gateway sync `backend=cuda`, expecting a 4x PNG and `Backend: cuda`.
2. Gateway sync `backend=cpu`, expecting the same dimensions and `Backend: cpu`.
3. Gateway async `backend=cuda`: submit, poll the existing task API, download `upscaled_image.png` and `upscale_report.json`, verify image/report metadata and SHA-256.
4. Gateway async `backend=cpu`: perform the same flow in the CPU queue.

Keep these commands out of ordinary offline CI. In `docs/operations/image-tools.md`, make CUDA cases conditional on a real NVIDIA runtime and staged assets; CPU cases remain required on a usable CPU runtime. Do not set `runtime_ready: true` based solely on a mocked test. After storage/model/sync/async real checks have passed, set the manifest runtime level and readiness to the project’s real-runtime convention, then update the PHP test expectation.

- [ ] **Step 4: Execute the full verification sequence**

Run:

```bash
php scripts/run_tests.php --suite=full
python3 -m unittest -v \
  packs/image-tools/service/test_image_contract.py \
  packs/image-tools/service/test_model_runtime.py \
  packs/image-tools/service/test_upscale_runner.py \
  packs/image-tools/service/test_app.py \
  packs/image-tools/service/test_jobs.py \
  packs/image-tools/service/test_acceptance.py
docker build -t 3waaihub-image-tools:test packs/image-tools/service
git diff --check
```

Then, on the configured test machine with `/DATA/models/image-tools/realesrgan/ready.json` present, run the four documented sync/async CUDA/CPU acceptance cases. Record only elapsed time, dimensions, selected backend, model alias, output hashes, and environment versions in the operation log; never commit source/output images, tokens, or model files.

- [ ] **Step 5: Commit verified readiness**

```bash
git add packs/image-tools docs/operations/image-tools.md tests/test_image_tools.php
git commit -m "test: verify image tools real runtime"
```

## Final Review Checklist

- [ ] `mode=image-tools` is the only new public mode; `background_remove` test coverage is unchanged and green.
- [ ] Sync and async reject untrusted strings, unknown fields, ambiguous source forms, unsupported actual images, and oversize decoded/output images before inference.
- [ ] Raw Base64 is never stored in a task row, task workspace metadata, log, report, or artifact.
- [ ] Explicit CUDA cannot fall back to CPU; `auto` is resolved once before async enqueue and retry uses the immutable snapshot.
- [ ] CPU and GPU jobs use the existing queues/lease/container runner; no new scheduler, resident runtime, or global PHP image processing is introduced.
- [ ] Models and source code are pinned/offline/checksummed; request-time network downloads, arbitrary model paths, shell interpolation, and Vulkan-as-CUDA fallback are absent.
- [ ] The Pack returns only PNG output with validated `Model`, `Backend`, elapsed time, and dimensions; async publishes only the approved image/report artifacts.
- [ ] Offline tests, Docker build, full PHP suite, and the documented real-machine CUDA/CPU acceptance have evidence before claiming completion.
