# BiRefNet Background Removal Pack Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship an `image-birefnet` HubPack that performs real local background removal, returns PNG bytes through the public gateway, prefers CUDA with one CPU fallback, and reaches L5 only after fixed quality fixtures pass.

**Architecture:** A FastAPI service owns validation, offline BiRefNet loading, inference, edge post-processing, and stateless PNG rendering. The existing PHP gateway remains the authentication and accounting boundary and gains an exact response-header allowlist for binary service responses. The existing admin Playground and benchmark runner gain narrowly scoped `image/png` handling; no photo asset, task, or artifact persistence is introduced.

**Tech Stack:** PHP 8/PDO/SQLite, FastAPI, PyTorch 2.9, Transformers `AutoModelForImageSegmentation`, Pillow, NumPy, Docker Compose with optional NVIDIA GPU access, existing PHP test runner, Python `unittest`.

---

## Scope And File Structure

| File | Responsibility |
| --- | --- |
| `packs/image-birefnet/pack.json` | Pack identity, storage, settings, binary API contract, errors, and benchmark cases. |
| `packs/image-birefnet/docker-compose.yml` | GPU-first development service with Hub model/cache/service-data mounts. |
| `packs/image-birefnet/service/Dockerfile` | Reproducible CUDA-capable service image and build-time dependency/unit smokes. |
| `packs/image-birefnet/service/requirements.txt` | Pinned service, image, model, and test dependencies. |
| `packs/image-birefnet/service/app.py` | FastAPI health endpoint, model lifecycle, CUDA-to-CPU fallback, request workspace, and PNG response. |
| `packs/image-birefnet/service/image_pipeline.py` | Pure validation, mask post-processing, defringing, background composition, and PNG encoding. |
| `packs/image-birefnet/service/provision_offline_assets.py` | Explicit pinned Hugging Face snapshot download and checksum manifest. |
| `packs/image-birefnet/service/{smoke.py,storage_smoke.py,model_smoke.py,inference_smoke.py}` | Runnable L2, L3, L4a, and L4b checks. |
| `packs/image-birefnet/service/{test_image_pipeline.py,test_app.py,acceptance.py}` | Deterministic unit tests and real L5 fixture scoring. |
| `packs/image-birefnet/demo/acceptance/*` | Three redistributable source/reference-mask pairs and provenance. |
| `packs/catalog.json` | Catalog registration. |
| `app/gateway.php` | Exact binary response metadata allowlist. |
| `app/benchmarks.php` | Binary PNG contract checks without JSON decoding. |
| `app/customer_accounts.php` | Playground mode allowlist. |
| `app/public_api_docs.php` | Binary response metadata and examples that save a PNG. |
| `admin/playground.php` | One-image parameters, PNG preview, download, and response metadata. |
| `tests/test_image_birefnet.php` | Pack, generated compose, gateway, Playground, and source-contract regression tests. |
| `tests/test_benchmark.php` | Binary benchmark behavior and L5 readiness regression tests. |
| `tests/test_public_api_docs.php` | PNG response contract and binary client examples. |
| `docs/operations/image-birefnet.md` | Provision, install, GPU/CPU smoke, benchmark, and rollback runbook. |

## Fixed Decisions

- Model repository: `ZhengPeng7/BiRefNet`.
- Pinned Hugging Face revision: `e2bf8e4460fc8fa32bba5ea4d94b3233d367b0e4`.
- Snapshot root: `/models/birefnet/snapshot`; readiness metadata: `/models/birefnet/ready.json`.
- Model input: RGB `1024x1024`, ImageNet normalization, model output `model(tensor)[-1].sigmoid()`.
- Public mode: `background_remove`; service endpoint: `POST /remove-background/image`.
- A successful response is binary `image/png`; failures remain JSON.
- The only service metadata forwarded by the generic gateway is `X-3waAIHub-Model`, `X-3waAIHub-Device`, `X-3waAIHub-Elapsed-Ms`, `X-3waAIHub-Width`, and `X-3waAIHub-Height`.
- New PHP files, including `tests/test_image_birefnet.php`, must be mode `0755`.

## Task 1: Add The L1 Pack Contract And Runtime-Not-Ready Adapter

**Files:**
- Create: `packs/image-birefnet/pack.json`
- Create: `packs/image-birefnet/docker-compose.yml`
- Create: `packs/image-birefnet/service/app.py`
- Create: `packs/image-birefnet/service/requirements.txt`
- Create: `packs/image-birefnet/service/Dockerfile`
- Modify: `packs/catalog.json`
- Create: `tests/test_image_birefnet.php`

- [ ] **Step 1: Write the failing Pack contract test**

Add assertions that require:

```php
$pack = hub_get_pack('image-birefnet');
hub_test_assert($pack !== null && $pack['status'] === 'ok', 'image-birefnet pack missing or invalid');
$manifest = $pack['manifest'];
hub_test_assert(($manifest['runtime_level'] ?? '') === 'L1-contract', 'BiRefNet must start at L1');
hub_test_assert(($manifest['target_level'] ?? '') === 'L5-benchmark-ready', 'BiRefNet target mismatch');
hub_test_assert(($manifest['default_mode'] ?? '') === 'background_remove', 'BiRefNet mode mismatch');
hub_test_assert(($manifest['gateway']['invoke_path'] ?? '') === '/remove-background/image', 'BiRefNet endpoint mismatch');
hub_test_assert(($manifest['l5_contract']['output']['content_type'] ?? '') === 'image/png', 'BiRefNet success MIME mismatch');
hub_test_assert(($manifest['hardware']['gpu_required'] ?? true) === false, 'BiRefNet must retain CPU fallback');
hub_test_assert(($manifest['hardware']['gpu_supported'] ?? false) === true, 'BiRefNet must advertise GPU support');
```

Also assert the exact input enums/ranges, 50 MB/8192/40 MP limits, exact error-code set, exact response-header set, no mock input, and catalog registration. Assert that `app.py` declares `/health` and `/remove-background/image` and returns `runtime_not_ready` without returning source pixels.

- [ ] **Step 2: Run the suite and confirm the new test fails**

Run: `php scripts/run_tests.php`

Expected: failure because `image-birefnet` does not exist.

- [ ] **Step 3: Add the smallest valid L1 Pack**

Use these manifest shapes:

```json
{
  "id": "image-birefnet",
  "execution_type": "sync_api",
  "runtime_level": "L1-contract",
  "target_level": "L5-benchmark-ready",
  "runtime_ready": false,
  "default_mode": "background_remove",
  "gateway": {
    "health_path": "/health",
    "invoke_path": "/remove-background/image",
    "methods": ["POST"],
    "timeout_sec": 180,
    "max_upload_mb": 50
  },
  "hardware": {
    "gpu_required": false,
    "gpu_supported": true,
    "cpu_fallback": true,
    "min_vram_mb": 0,
    "recommended_vram_mb": 4096,
    "min_compute_capability": "8.0"
  }
}
```

The `l5_contract.output` object must use `content_type`, `variants`, and `required_headers`; do not invent JSON `required_keys` for a PNG. Define settings/env for `BIREFNET_USE_GPU=1`, `BIREFNET_DEVICE=auto`, `BIREFNET_CPU_FALLBACK=1`, `BIREFNET_MODEL_DIR=/models/birefnet`, `BIREFNET_CACHE_DIR=/cache/birefnet`, `BIREFNET_SERVICE_DATA_DIR=/data/service`, `BIREFNET_MAX_UPLOAD_MB=50`, and `KEEP_WARM=0`. Set default port `18112` and the generic source Compose to `gpus: all`; generated CPU Compose remains available when `BIREFNET_USE_GPU=0`.

Declare `queue.supported=true`, `queue.default_queue=gpu`, and `queue.max_concurrency=1`. The GPU queue is the preferred admission path, not a declaration that a GPU is required.

The L1 endpoint validates that `image` exists, then always returns:

```python
raise HTTPException(
    status_code=503,
    detail={"ok": False, "error": "runtime_not_ready", "message": "BiRefNet runtime is not ready"},
)
```

Use one JSON exception handler so errors have top-level `ok`, `error`, and `message`; do not leak stack traces or local paths.

- [ ] **Step 4: Set required executable permission and verify L1**

Run:

```bash
chmod 0755 tests/test_image_birefnet.php
php scripts/run_tests.php
php -r '$p=json_decode(file_get_contents("packs/image-birefnet/pack.json"), true, 512, JSON_THROW_ON_ERROR); echo $p["runtime_level"], PHP_EOL;'
```

Expected: suite passes and prints `L1-contract`.

- [ ] **Step 5: Commit L1**

```bash
git add packs/image-birefnet packs/catalog.json tests/test_image_birefnet.php
git commit -m "feat: add BiRefNet background removal contract"
```

## Task 2: Build L2 Dependencies And Pure Image Processing

**Files:**
- Create: `packs/image-birefnet/service/image_pipeline.py`
- Create: `packs/image-birefnet/service/test_image_pipeline.py`
- Create: `packs/image-birefnet/service/smoke.py`
- Modify: `packs/image-birefnet/service/{requirements.txt,Dockerfile,app.py}`
- Modify: `packs/image-birefnet/pack.json`
- Modify: `tests/test_image_birefnet.php`

- [ ] **Step 1: Write failing pure-pipeline tests**

Use `unittest` and in-memory Pillow images. Add tests named `test_edge_offset_dilates_and_erodes`, `test_feather_adds_partial_alpha`, `test_defringe_repairs_only_near_black_or_white_partial_edges`, `test_defringe_leaves_edge_when_no_nearby_opaque_pixel_exists`, `test_cover_crop_is_centered_and_opaque`, `test_mask_png_is_l_and_cutout_is_rgba_and_composite_is_rgb`, `test_invalid_option_combinations_have_invalid_parameter`, `test_exif_orientation_is_applied_before_dimension_limits`, and `test_decoded_dimension_and_pixel_limits_are_enforced`.

For nearest defringe candidates, sort by squared distance, then `y`, then `x`, so ties are deterministic. Positive `edge_offset_px` uses `ImageFilter.MaxFilter(2 * px + 1)`; negative values use `MinFilter(2 * abs(px) + 1)`. Feather uses `GaussianBlur(radius=feather_px)`.

The defringe test matrix must pin the approved thresholds: inspect only `0 < alpha < 255`; near black means `max(R,G,B) <= 16`; near white means `min(R,G,B) >= 239`; replacement candidates require `alpha >= 250` (the 8-bit form of `0.98`) within Euclidean radius three. Replace only RGB, never alpha. Leave the pixel unchanged when no candidate exists or its original edge color is outside the black/white thresholds.

- [ ] **Step 2: Run the Python test and confirm failure**

Run: `python3 -m unittest -v packs/image-birefnet/service/test_image_pipeline.py`

Expected: import failure because `image_pipeline.py` is missing.

- [ ] **Step 3: Implement the pure pipeline**

Create a frozen `RequestOptions` dataclass. Expose `parse_options(form, has_background_image)`, `decode_image(data, max_bytes, max_axis, max_pixels)`, `postprocess_mask(mask, size, feather_px, edge_offset_px)`, `defringe_rgb(rgb, alpha)`, `cover_background(background, size)`, and `render_png(source, alpha, options, background)` with the return types described by their names and the assertions below.

`decode_image` must use `Image.open(BytesIO(data))`, `verify()`, reopen, `ImageOps.exif_transpose`, and decoded `format` membership in `JPEG`, `PNG`, `WEBP`. Treat decompression-bomb warnings/errors as `invalid_image`. Check bytes before decode and dimensions/pixels after orientation. Convert source to RGB only after validation.

`parse_options` enforces the approved matrix: mask rejects every background-specific value; cutout permits only transparent; composite requires white/color/image; color accepts only `^#[0-9A-Fa-f]{6}$`; image requires the second upload. Boolean text accepts only `1/0`, `true/false`, `yes/no`, and `on/off`.

For custom backgrounds, `cover_background` applies EXIF orientation, converts transparent pixels over white, scales to cover, then center-crops. All PNG encodes omit source metadata.

- [ ] **Step 4: Pin dependencies and run build-time smokes**

Pin compatible versions rather than open-ended ranges:

```text
fastapi==0.115.6
uvicorn[standard]==0.34.0
python-multipart==0.0.20
pillow==11.1.0
numpy==1.26.4
requests==2.32.5
httpx==0.28.1
torch==2.9.1
torchvision==0.24.1
transformers==4.57.1
huggingface-hub==0.36.0
safetensors==0.6.2
timm==1.0.22
kornia==0.8.2
einops==0.8.1
```

`smoke.py` imports every runtime dependency and prints JSON with `runtime_level=L2-deps-import`; it must not load or download the model. The Dockerfile runs both `smoke.py` and `python3 -m unittest -v test_image_pipeline.py` during build using temporary `HOME`, `HF_HOME`, and `XDG_CACHE_HOME`.

- [ ] **Step 5: Promote and verify L2**

Set `runtime_level=L2-deps-import`, keep `runtime_ready=false`, then run:

```bash
python3 -m unittest -v packs/image-birefnet/service/test_image_pipeline.py
docker build -t 3waaihub-image-birefnet:test packs/image-birefnet/service
php scripts/run_tests.php
```

Expected: all three commands pass; Docker build performs no network model download.

- [ ] **Step 6: Commit L2**

```bash
git add packs/image-birefnet tests/test_image_birefnet.php
git commit -m "feat: add BiRefNet image processing pipeline"
```

## Task 3: Add L3 Offline Model Provisioning And Storage Verification

**Files:**
- Create: `packs/image-birefnet/service/provision_offline_assets.py`
- Create: `packs/image-birefnet/service/storage_smoke.py`
- Create: `packs/image-birefnet/service/test_provision_offline_assets.py`
- Modify: `packs/image-birefnet/service/Dockerfile`
- Modify: `packs/image-birefnet/pack.json`
- Modify: `packs/image-birefnet/docker-compose.yml`
- Modify: `tests/test_image_birefnet.php`

- [ ] **Step 1: Write failing provisioning tests with a fake downloader**

Test that provisioning:

- calls `snapshot_download` exactly once with repository `ZhengPeng7/BiRefNet`, the fixed full revision, `local_dir=/models/birefnet/snapshot`, and no token requirement;
- writes `ready.json` atomically only after download and checksums complete;
- records `repository`, `revision`, `created_at`, and sorted `{path, size, sha256}` file rows;
- rejects an empty snapshot and removes a temporary readiness file on failure;
- never accepts a symlink that resolves outside the snapshot root.

Inject the downloader and model root into `provision()` so tests use a temporary directory and no network.

- [ ] **Step 2: Confirm the new tests fail**

Run: `python3 -m unittest -v packs/image-birefnet/service/test_provision_offline_assets.py`

- [ ] **Step 3: Implement explicit pinned provisioning**

The provisioner must set `HF_HUB_DISABLE_TELEMETRY=1`, call:

```python
snapshot_download(
    repo_id="ZhengPeng7/BiRefNet",
    revision="e2bf8e4460fc8fa32bba5ea4d94b3233d367b0e4",
    local_dir=str(snapshot_dir),
)
```

Hash every regular runtime file below `snapshot`, excluding `.cache/` and `.git/`. Require at least `config.json` and one `.safetensors` file. Write `ready.json.tmp`, `fsync`, then `os.replace`. Runtime code never calls `snapshot_download`.

`storage_smoke.py` verifies writable `/models/birefnet`, `/cache/birefnet`, `/cache/birefnet/xdg`, `/cache/birefnet/home`, and `/data/service`, then prints JSON. It must not import Transformers or Torch.

- [ ] **Step 4: Wire mounts and offline environment**

Use Pack storage entries for:

```text
${AIHUB_MODELS_DIR}/birefnet:/models/birefnet
${AIHUB_CACHE_DIR}/birefnet:/cache/birefnet
${SERVICE_DATA_DIR}:/data/service
```

Set `HF_HOME=/models/birefnet/huggingface`, `HF_HUB_OFFLINE=1`, `TRANSFORMERS_OFFLINE=1`, `XDG_CACHE_HOME=/cache/birefnet/xdg`, and `HOME=/cache/birefnet/home`. Copy the provisioner into the image, but do not execute it during build or service startup.

- [ ] **Step 5: Promote and verify L3 without downloading in tests**

Set `runtime_level=L3-storage-mount`, keep `runtime_ready=false`, then run:

```bash
python3 -m unittest -v packs/image-birefnet/service/test_provision_offline_assets.py
docker build -t 3waaihub-image-birefnet:test packs/image-birefnet/service
php scripts/run_tests.php
```

Expected: all pass without model network access.

- [ ] **Step 6: Provision the real pinned snapshot once**

Run:

```bash
docker compose -f packs/image-birefnet/docker-compose.yml run --rm image-birefnet \
  python3 /app/provision_offline_assets.py
docker compose -f packs/image-birefnet/docker-compose.yml run --rm image-birefnet \
  python3 /app/storage_smoke.py
```

Expected: `ready.json` names the exact revision and storage smoke returns `ok=true`. Record total bytes and SHA-256 count in the task notes; do not commit model files.

- [ ] **Step 7: Commit L3**

```bash
git add packs/image-birefnet tests/test_image_birefnet.php
git commit -m "feat: provision BiRefNet for offline loading"
```

## Task 4: Add L4a Offline Model Initialization And Device Selection

**Files:**
- Create: `packs/image-birefnet/service/model_smoke.py`
- Create: `packs/image-birefnet/service/test_app.py`
- Modify: `packs/image-birefnet/service/app.py`
- Modify: `packs/image-birefnet/service/Dockerfile`
- Modify: `packs/image-birefnet/pack.json`
- Modify: `tests/test_image_birefnet.php`

- [ ] **Step 1: Write failing model-loader unit tests**

Use injected fake Torch and model factories. Add tests named `test_auto_prefers_cuda_when_available`, `test_auto_uses_cpu_when_cuda_is_unavailable`, `test_cuda_load_failure_releases_cache_then_retries_cpu_once`, `test_cpu_retry_failure_reports_model_load_failed_without_paths`, `test_ready_revision_must_match_the_pinned_revision`, and `test_loader_uses_local_files_only_and_snapshot_path`.

Assert the loader calls `AutoModelForImageSegmentation.from_pretrained(snapshot_path, trust_remote_code=True, local_files_only=True)`, calls `.eval()`, calls `.half()` only on CUDA, and caches one `(model, device)` tuple behind a lock.

- [ ] **Step 2: Confirm failure, then implement the loader**

Run: `python3 -m unittest -v packs/image-birefnet/service/test_app.py`

Implement `load_model()` and `reset_model()` in `app.py`. `BIREFNET_DEVICE` accepts only `auto`, `cuda`, or `cpu`; `BIREFNET_USE_GPU=0` forces CPU. A requested/automatic CUDA attempt may retry CPU once only when `BIREFNET_CPU_FALLBACK=1`. Before retry, delete the failed model reference, run `gc.collect()`, and call `torch.cuda.empty_cache()` when available.

`/health` reports dependency availability, model readiness metadata, requested/effective device, storage paths, runtime level, and a sanitized error code. It must never emit host paths, exception traces, or readiness checksums.

- [ ] **Step 3: Add a real initialization smoke**

`model_smoke.py` verifies every `ready.json` size and SHA-256 entry, imports `load_model`, initializes the local snapshot, and prints model class, effective device, pinned revision, and `runtime_level=L4a-model-init-smoke`. It does not open an image or call the model forward pass. Add a unit test that mutates one snapshot file and requires checksum verification to fail before the model factory is called.

- [ ] **Step 4: Promote and verify L4a**

Set `runtime_level=L4a-model-init-smoke`, keep `runtime_ready=false`, then run:

```bash
docker build -t 3waaihub-image-birefnet:test packs/image-birefnet/service
docker compose -f packs/image-birefnet/docker-compose.yml run --rm image-birefnet \
  python3 /app/model_smoke.py
php scripts/run_tests.php
```

Expected: model smoke reports `device=cuda` on the 3wa host. A model-load error leaves the manifest at L3 and blocks this commit.

- [ ] **Step 5: Commit L4a**

```bash
git add packs/image-birefnet tests/test_image_birefnet.php
git commit -m "feat: initialize local BiRefNet model"
```

## Task 5: Implement L4b Real Inference And Stateless PNG Responses

**Files:**
- Create: `packs/image-birefnet/service/inference_smoke.py`
- Modify: `packs/image-birefnet/service/{app.py,test_app.py,image_pipeline.py,test_image_pipeline.py}`
- Modify: `packs/image-birefnet/pack.json`
- Modify: `tests/test_image_birefnet.php`

- [ ] **Step 1: Add failing endpoint and fallback tests**

With a fake model and FastAPI `TestClient`, test:

- JPEG/PNG/WebP input and source EXIF orientation;
- output mode/channel/dimension contracts;
- exact six success headers including `Content-Type`;
- invalid option combinations fail before the model factory is called;
- malformed/unsupported/oversized input returns the exact approved error;
- a CUDA out-of-memory inference error resets the model and retries the complete request on CPU once;
- a CPU inference failure returns JSON `inference_failed`, never source or placeholder pixels;
- every request creates and removes a private directory below `/data/service/tmp`.

Use a fake prediction tensor with background, foreground, and partial values. Assert PNG magic bytes `\x89PNG\r\n\x1a\n`.

- [ ] **Step 2: Implement the approved inference path**

The forward path is exactly:

```python
transform = transforms.Compose([
    transforms.Resize((1024, 1024)),
    transforms.ToTensor(),
    transforms.Normalize([0.485, 0.456, 0.406], [0.229, 0.224, 0.225]),
])
tensor = transform(source_rgb).unsqueeze(0).to(device)
if device == "cuda":
    tensor = tensor.half()
with torch.inference_mode():
    prediction = model(tensor)[-1].sigmoid().float().cpu()[0].squeeze()
```

Convert prediction to floating alpha, resize to original dimensions with bicubic interpolation, then call the pure post-processing/rendering functions. Save uploaded bytes and encoded output inside `TemporaryDirectory(dir=/data/service/tmp)` and read response bytes before context exit. Do not persist database rows or output files.

Map timeouts to `inference_timeout`; CUDA OOM retries once on CPU; all other forward errors become `inference_failed`. Positive elapsed time uses `max(1, int(round((time.perf_counter() - started) * 1000)))`.

- [ ] **Step 3: Add direct real-inference smoke**

`inference_smoke.py` posts `packs/image-birefnet/demo/smoke.png` directly to `http://127.0.0.1:8000/remove-background/image`, verifies HTTP 200, PNG signature, original dimensions, non-degenerate alpha, exact model header, positive elapsed time, and expected device.

- [ ] **Step 4: Promote and verify GPU-first plus controlled CPU fallback**

Set `runtime_level=L4b-real-inference`, `runtime_ready=true`, and add `demo/smoke.png` derived from an existing repository-owned vision fixture. Run:

```bash
python3 -m unittest -v packs/image-birefnet/service/test_image_pipeline.py packs/image-birefnet/service/test_app.py
docker compose -f packs/image-birefnet/docker-compose.yml up -d --build
docker run --rm --network host -v "$PWD/packs/image-birefnet:/pack:ro" \
  3waaihub-image-birefnet:test python3 /app/inference_smoke.py \
  --base-url http://127.0.0.1:18112 --fixture /pack/demo/smoke.png --expect-device cuda
BIREFNET_USE_GPU=0 BIREFNET_DEVICE=cpu docker compose -f packs/image-birefnet/docker-compose.yml run --rm \
  -v "$PWD/packs/image-birefnet:/pack:ro" image-birefnet \
  python3 /app/inference_smoke.py --in-process --fixture /pack/demo/smoke.png --expect-device cpu
php scripts/run_tests.php
```

Expected: the station smoke reports CUDA and the controlled smoke reports CPU with the same response contract. Keep the Pack at L4a if either real path fails.

- [ ] **Step 5: Commit L4b**

```bash
git add packs/image-birefnet tests/test_image_birefnet.php
git commit -m "feat: run real BiRefNet background removal"
```

## Task 6: Preserve Binary Metadata Through Gateway And Public Docs

**Files:**
- Modify: `app/gateway.php`
- Modify: `app/public_api_docs.php`
- Modify: `tests/test_image_birefnet.php`
- Modify: `tests/test_public_api_docs.php`

- [ ] **Step 1: Write failing gateway allowlist tests**

Add a pure helper test with raw service headers containing the five approved headers plus `Set-Cookie`, `Location`, an invented `X-Internal-Path`, duplicates, mixed casing, and CR/LF contamination. Require output to contain only canonical `Content-Type` plus one canonical instance of each approved metadata header. Numeric headers accept digits only; device accepts only `cuda|cpu`; model rejects control characters.

Also dispatch a fake successful PNG response through `hub_gateway_dispatch` and assert request ID, output-byte accounting, PNG body, MIME, and metadata all survive unchanged.

- [ ] **Step 2: Confirm the gateway tests fail**

Run: `php scripts/run_tests.php`

- [ ] **Step 3: Implement exact response-header extraction**

Add:

```php
function hub_proxy_allowed_response_headers(string $rawHeaders, string $contentType): array
```

Parse only the final HTTP header block. Start with `Content-Type: <curl content type>`, then copy only the five exact allowlisted names after value validation. Do not forward cookies, cache policy, redirects, content disposition, server identifiers, or arbitrary `X-*` headers. Keep `hub_gateway_attach_request_id()` behavior unchanged.

- [ ] **Step 4: Teach public API docs about binary output**

Expose `response_content_type` and `response_headers` from `l5_contract.output`. For `image/png` examples:

- curl ends with `--output result.png`;
- PHP stores `curl_exec()` into `result.png` after checking status/MIME;
- JavaScript uses `await res.blob()` and an object URL, never `res.json()`.

Errors remain documented as JSON. Update L5 readiness so a binary output contract is valid when `output.content_type` and `output.required_headers` are present; existing JSON contracts still require `required_keys`.

- [ ] **Step 5: Verify and commit**

Run: `php scripts/run_tests.php`

```bash
git add app/gateway.php app/public_api_docs.php tests/test_image_birefnet.php tests/test_public_api_docs.php
git commit -m "feat: proxy binary image response metadata"
```

## Task 7: Add The Minimal Admin Playground Experience

**Files:**
- Modify: `app/customer_accounts.php`
- Modify: `admin/playground.php`
- Modify: `tests/test_image_birefnet.php`

- [ ] **Step 1: Write failing Playground contract tests**

Assert `background_remove` is in `hub_playground_supported_modes()` and the page source contains one source upload, optional background upload, output/background selects, feather and edge offset numeric controls, defringe checkbox, color input, PNG preview, download link, and metadata labels. Test the response parser with synthetic PNG bytes and response headers; it must not run `json_decode` as its success path or print binary bytes inside `<pre>`.

- [ ] **Step 2: Add request payload and binary response parsing**

Add the profile:

```php
'background_remove' => ['label' => 'BiRefNet 去背', 'method' => 'POST', 'kind' => 'background_remove'],
```

Build multipart fields from the approved parameters. Attach `image` and attach `background_image` only when supplied. Use `Accept: image/png`. Extend `hub_playground_finish_curl()` to return parsed `content_type`, allowlisted metadata, and `preview_data_uri` for a successful PNG. For non-2xx JSON, keep existing safe error handling. For binary success, set `pretty_body` to a small metadata JSON object rather than the PNG bytes.

- [ ] **Step 3: Render minimal controls and result**

Use existing panel/form styles. Display the returned PNG against a CSS checkerboard, provide a `download="background-removed.png"` anchor, and show model/device/width/height/elapsed metadata. Keep the page to one source image and one result; do not add drag-drop, comparison sliders, batch, persistence, or editing sessions.

Examples for this mode must save/download `result.png` and use `blob()` in JavaScript.

- [ ] **Step 4: Verify and commit**

Run:

```bash
php -l admin/playground.php
php scripts/run_tests.php
```

```bash
git add app/customer_accounts.php admin/playground.php tests/test_image_birefnet.php
git commit -m "feat: preview BiRefNet output in playground"
```

## Task 8: Add L5 Fixtures, Binary Benchmarking, And Quality Gates

**Files:**
- Create: `packs/image-birefnet/demo/acceptance/{person_hair,person_hair_mask,white_product,white_product_mask,animal_fur,animal_fur_mask}.png`
- Create: `packs/image-birefnet/demo/acceptance/README.md`
- Create: `packs/image-birefnet/service/acceptance.py`
- Modify: `packs/image-birefnet/pack.json`
- Modify: `app/benchmarks.php`
- Modify: `tests/test_benchmark.php`
- Modify: `tests/test_image_birefnet.php`

- [ ] **Step 1: Create redistributable fixed fixture pairs**

Generate three original RGBA subjects with the image generation tool using these fixed concepts, then composite each over a deterministic photographic background while retaining the original alpha as the reference mask:

1. Shoulder portrait with loose dark and light hair strands crossing the background.
2. White consumer product with holes and pale reflective edges on a light gray background.
3. Long-haired animal with backlit fur and an irregular silhouette.

Reject and regenerate any subject whose alpha lacks foreground, background, or partial values. Store only the final RGB source and L-mode reference mask. In `README.md`, record the exact prompt, generation date, tool, compositing script checksum, dimensions, and confirmation that the repository may redistribute the generated assets. Do not derive reference masks from BiRefNet output.

- [ ] **Step 2: Write failing acceptance and benchmark tests**

`acceptance.py` calls the running service for all three fixtures and computes:

```python
pred = np.asarray(output_alpha, dtype=np.float32) / 255.0
truth = np.asarray(reference_mask, dtype=np.float32) / 255.0
mae = float(np.abs(pred - truth).mean())
precision = tp / max(tp + fp, 1)
recall = tp / max(tp + fn, 1)
f_score = 2 * precision * recall / max(precision + recall, 1e-12)
```

Threshold both masks at `0.5`. Require each fixture, not only the average, to have `f_score >= 0.80` and mean absolute alpha error `mae <= 0.10`. Also require exact dimensions/channels, partial alpha, headers, and positive elapsed time. Print one JSON result and exit nonzero on any failure.

Add PHP benchmark tests for `expected_content_type=image/png`, `expected_png=true`, `expected_dimensions_from_fixture=true`, and the five expected response headers. Ensure the binary branch never calls JSON key checks.

- [ ] **Step 3: Extend the generic benchmark narrowly**

In `hub_benchmark_l5_contract_case()`, branch only when a case declares `expected_content_type`. Validate status, MIME, PNG signature, `getimagesizefromstring()` dimensions, and declared headers. Return `content_type`, `output_bytes`, `width`, `height`, and `response_headers_pass`; retain the existing JSON path unchanged.

Register one real gateway structural case in `l5_contract.benchmark.cases`. Add a separate `l5_contract.quality_benchmark` declaration naming `service/acceptance.py`, the three fixture/mask pairs, `f_score_min=0.80`, and `mae_max=0.10`. The admin benchmark runner owns the gateway structural pass; `acceptance.py` owns all three per-fixture alpha metric passes.

- [ ] **Step 4: Run L5 acceptance before promotion**

Run:

```bash
docker compose -f packs/image-birefnet/docker-compose.yml up -d --build
docker run --rm --network host -v "$PWD/packs/image-birefnet:/pack:ro" \
  3waaihub-image-birefnet:test python3 /app/acceptance.py \
  --base-url http://127.0.0.1:18112 --fixtures /pack/demo/acceptance --expect-device cuda
php scripts/run_tests.php
```

Expected: all three fixtures independently pass F-score/MAE and structural gates. If a fixture fails, inspect the output and improve only justified post-processing or fixture correctness; do not lower thresholds and do not promote the manifest.

- [ ] **Step 5: Promote to L5 and verify Hub benchmark readiness**

Only after Step 4 passes, set `runtime_level=L5-benchmark-ready`. Install or refresh a test service and run its declared binary benchmark through the existing admin/CLI benchmark path. Confirm `hub_pack_l5_readiness()` reports `real_inference_benchmark_passed=true` and all checks green.

- [ ] **Step 6: Commit L5**

```bash
git add packs/image-birefnet app/benchmarks.php tests/test_benchmark.php tests/test_image_birefnet.php
git commit -m "feat: benchmark BiRefNet removal quality"
```

## Task 9: Document Operations And Run Final Acceptance

**Files:**
- Create: `docs/operations/image-birefnet.md`
- Modify: `tests/test_image_birefnet.php`

- [ ] **Step 1: Write the operations runbook**

Document exact commands for:

- model provisioning and revision/checksum inspection;
- Pack install with GPU default and explicit CPU mode;
- build, health, model-init, direct inference, gateway, and quality smokes;
- expected 5060 device headers and measured latency/VRAM notes;
- model refresh policy: change revision in code, reprovision, rerun all L3-L5 checks, then commit;
- rollback: stop service, select the previous Pack commit/image, and retain or remove only `/models/birefnet` after operator confirmation;
- known limits: fixed 1024 input, original-size output, no tiling, no batch/video/editor/persistence.

Do not include bearer tokens, private host credentials, or model binaries.

- [ ] **Step 2: Add a runnable documentation contract check**

Extend `tests/test_image_birefnet.php` to require the runbook, pinned repository/revision, provisioning command, GPU and CPU smokes, acceptance command, rollback, and exclusions.

- [ ] **Step 3: Run final verification**

```bash
php -l admin/playground.php
php -l app/gateway.php
php -l app/benchmarks.php
python3 -m unittest -v \
  packs/image-birefnet/service/test_image_pipeline.py \
  packs/image-birefnet/service/test_provision_offline_assets.py \
  packs/image-birefnet/service/test_app.py
docker build -t 3waaihub-image-birefnet:test packs/image-birefnet/service
php scripts/run_tests.php
docker run --rm --network host -v "$PWD/packs/image-birefnet:/pack:ro" \
  3waaihub-image-birefnet:test python3 /app/acceptance.py \
  --base-url http://127.0.0.1:18112 --fixtures /pack/demo/acceptance --expect-device cuda
git diff --check
git status --short
```

Expected: all checks pass; only intentional files are modified; no model/cache/service-data files are tracked.

- [ ] **Step 4: Commit documentation**

```bash
git add docs/operations/image-birefnet.md tests/test_image_birefnet.php
git commit -m "docs: add BiRefNet operations runbook"
```

## Completion Gate

- [ ] Runtime levels were promoted only after their runnable checks passed.
- [ ] Runtime requests perform no network model/code download.
- [ ] Default generated Compose requests GPU; `BIREFNET_USE_GPU=0` produces a CPU Compose.
- [ ] CUDA and controlled CPU inference both return the same binary contract.
- [ ] Invalid requests and inference failures return JSON and never placeholder pixels.
- [ ] Gateway forwards only the exact approved metadata headers.
- [ ] Playground previews one result without storing an asset/artifact/session.
- [ ] Every L5 fixture independently meets F-score and MAE thresholds.
- [ ] `php scripts/run_tests.php`, Python unit tests, Docker build, and real acceptance are green.
- [ ] `tests/test_image_birefnet.php` is mode `0755`; no new PHP file has a non-executable mode.
- [ ] Model snapshots, caches, generated service data, and secrets are absent from Git.
