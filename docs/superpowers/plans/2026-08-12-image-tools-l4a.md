# Image Tools L4a Model Initialization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Promote `image-tools` to L4a by proving its pinned local
Real-ESRGAN model families initialize through the same loader used by image
upscaling, while retaining a generic Chinese Image Tools Pack identity.

**Architecture:** Move only the Real-ESRGAN architecture/weight construction
from `upscale_runner.py` to `model_runtime.py`. The existing runner still owns
request/image/output processing, while `model_smoke.py` verifies the immutable
marker once and calls the shared loader once per distinct model family. The
manifest and `/health` announce L4a after this smoke implementation is
available; L4b HTTP inference and L5 quality work remain separate.

**Tech Stack:** Python 3.10, PyTorch/Real-ESRGAN, Docker, PHP Pack manifest
tests, existing `unittest` runner.

---

## File Structure

| File | Responsibility |
| --- | --- |
| `packs/image-tools/service/model_runtime.py` | Canonical model-family metadata and shared Real-ESRGAN upsampler construction. |
| `packs/image-tools/service/upscale_runner.py` | Input/output inference path; delegates model construction to `model_runtime`. |
| `packs/image-tools/service/model_smoke.py` | L4a CLI: marker verification and model initialization only. |
| `packs/image-tools/service/test_model_runtime.py` | Unit tests for the shared loader and marker boundary. |
| `packs/image-tools/service/test_model_smoke.py` | Unit tests for family coverage, compact JSON, and failure behavior. |
| `packs/image-tools/service/test_upscale_runner.py` | Regression that production inference calls the shared loader. |
| `packs/image-tools/service/test_app.py` | Health level regression. |
| `packs/image-tools/service/Dockerfile` | Runs the new deterministic L4a tests during image build. |
| `packs/image-tools/pack.json` | Generic Chinese identity and L4a/L5 level declarations. |
| `tests/test_image_tools.php` | Pack, UI-doc, and L4a command contract assertions. |
| `README.md`, `docs/operations/image-tools.md`, `docs/operations/image-tools-acceptance.md` | Public level statement, exact L4a operator command, and redacted host evidence. |

## Task 1: Lock the L4a contract with failing tests

**Files:**
- Modify: `packs/image-tools/service/test_model_runtime.py`
- Create: `packs/image-tools/service/test_model_smoke.py`
- Modify: `packs/image-tools/service/test_upscale_runner.py`
- Modify: `packs/image-tools/service/test_app.py`
- Modify: `tests/test_image_tools.php`

- [ ] **Step 1: Add the shared-loader test before implementing it.**

  Add a `ModelRuntimeTest.test_build_upsampler_uses_only_known_architectures`
  test. Patch fake `basicsr.archs.rrdbnet_arch.RRDBNet`,
  `realesrgan.archs.srvgg_arch.SRVGGNetCompact`, and
  `realesrgan.RealESRGANer`; call `model_runtime.build_upsampler()` for
  `realesrgan-x4plus`, `realesrgan-x4plus-anime`, and
  `realesr-animevideov3-x4` with a local `Path('/models/pinned.pth')`.
  Assert their architecture parameters, `scale=4`, `half=False` for CPU,
  `device='cpu'`, and that `auto` raises `ModelRuntimeError('invalid_backend')`.

  ```python
  with self.assertRaisesRegex(ModelRuntimeError, '^invalid_backend$'):
      build_upsampler('realesrgan-x4plus', 'auto', Path('/models/pinned.pth'))
  self.assertEqual([call('realesrgan-x4plus'), call('realesrgan-x4plus-anime'), call('realesr-animevideov3-x4')], selection.call_args_list)
  ```

- [ ] **Step 2: Add a smoke CLI test that cannot pass by doing inference.**

  In `test_model_smoke.py`, patch `model_smoke.verify_ready` to return the
  pinned commit and patch `model_smoke.build_upsampler` with a sentinel. Call
  `model_smoke.main(['--backend', 'cpu', '--model-dir', '/models'])`; parse its
  stdout and assert this exact public shape:

  ```python
  {
      'ok': True,
      'backend': 'cpu',
      'commit': REAL_ESRGAN_COMMIT,
      'families': [
          {'id': 'realesrgan-x4plus', 'aliases': ['realesrgan-x4plus']},
          {'id': 'realesrgan-x4plus-anime', 'aliases': ['realesrgan-x4plus-anime']},
          {'id': 'realesr-animevideov3', 'aliases': [
              'realesr-animevideov3-x2', 'realesr-animevideov3-x3', 'realesr-animevideov3-x4'
          ]},
      ],
  }
  ```

  Assert exactly three loader calls (one per family), `verify_ready` occurs
  before the first load, and the source contains neither `decode_image` nor
  `.enhance(`. A `ModelRuntimeError('model_load_failed')` must cause return 1
  and emit only `{'ok': False, 'error': 'model_load_failed'}`.

- [ ] **Step 3: Extend the runner and health tests.**

  In `test_upscale_runner.py`, replace the `_upsampler` patch with a
  `model_runtime.build_upsampler` patch and assert the runner passes the pinned
  resolved path, the requested alias, and resolved backend. In `test_app.py`,
  change the ready-health expectation to:

  ```python
  {'ok': True, 'service': 'image-tools', 'ready': True,
   'runtime_level': 'L4a-model-init-smoke', 'runtime_ready': True}
  ```

  Keep the missing/tampered marker case at `L1-contract` and `runtime_ready`
  false.

- [ ] **Step 4: Extend the PHP Pack contract assertions.**

  Change the first image-tools test title to reflect L4a. Assert exact generic
  identity and level values without changing endpoint/API assertions:

  ```php
  hub_test_assert(($manifest['name'] ?? '') === '影像工具', 'image-tools display name must remain generic Chinese');
  hub_test_assert(($manifest['description'] ?? '') === '本機複合式影像處理工具；目前提供 Real-ESRGAN 圖片放大，後續功能將以獨立 operation 擴充。', 'image-tools description mismatch');
  hub_test_assert(($manifest['runtime_level'] ?? '') === 'L4a-model-init-smoke', 'image-tools must publish L4a only after model-init smoke exists');
  hub_test_assert(($manifest['target_level'] ?? '') === 'L5-benchmark-ready', 'image-tools target level mismatch');
  ```

  Assert the README and runbook include `影像工具`, `L4a-model-init-smoke`, and
  `model_smoke.py --backend cpu`; assert they do not state that L4b/L5 is
  complete.

- [ ] **Step 5: Run the focused tests and confirm they fail for the missing L4a implementation.**

  Run:

  ```bash
  python3 -m unittest -v \
    packs/image-tools/service/test_model_runtime.py \
    packs/image-tools/service/test_model_smoke.py \
    packs/image-tools/service/test_upscale_runner.py \
    packs/image-tools/service/test_app.py
  php scripts/run_tests.php --suite=full
  ```

  Expected: Python fails because `build_upsampler` and the new smoke module
  contract do not yet exist; PHP reports the image-tools Pack contract failure
  because the Pack still declares its old English L3 identity. Record any
  unrelated pre-existing PHP suite failures separately.

- [ ] **Step 6: Commit the failing-test contract.**

  ```bash
  git add packs/image-tools/service/test_model_runtime.py \
    packs/image-tools/service/test_model_smoke.py \
    packs/image-tools/service/test_upscale_runner.py \
    packs/image-tools/service/test_app.py tests/test_image_tools.php
  git commit -m "test: define image tools L4a runtime contract"
  ```

## Task 2: Implement the shared loader and L4a smoke

**Files:**
- Modify: `packs/image-tools/service/model_runtime.py`
- Modify: `packs/image-tools/service/upscale_runner.py`
- Modify: `packs/image-tools/service/model_smoke.py`

- [ ] **Step 1: Add canonical L4a model-family metadata.**

  In `model_runtime.py`, add a fixed tuple in public alias order. It is not a
  user-configurable registry and contains only the three architectures/weight
  families already declared by `image_contract.py`:

  ```python
  MODEL_SMOKE_FAMILIES = (
      ('realesrgan-x4plus', 'realesrgan-x4plus', ('realesrgan-x4plus',)),
      ('realesrgan-x4plus-anime', 'realesrgan-x4plus-anime', ('realesrgan-x4plus-anime',)),
      ('realesr-animevideov3', 'realesr-animevideov3-x4', (
          'realesr-animevideov3-x2', 'realesr-animevideov3-x3', 'realesr-animevideov3-x4',
      )),
  )
  ```

  The second member is the canonical alias used to construct the model; the
  third member is report-only public coverage.

- [ ] **Step 2: Move the existing construction code to one shared helper.**

  Add `build_upsampler(alias: str, backend: str, model_path: Path) -> Any` to
  `model_runtime.py`. Move the current `_upsampler` imports, architecture
  selection, and `RealESRGANer(...)` call into it. Preserve all constructor
  arguments exactly:

  ```python
  return RealESRGANer(
      scale=4, model_path=str(model_path), model=model,
      tile=0, tile_pad=10, pre_pad=0,
      half=backend == 'cuda', device=backend,
  )
  ```

  Validate `backend in {'cpu', 'cuda'}` before importing/loading. Use
  `select_model(alias)` to preserve the public alias validation, and convert
  import/construction exceptions to `ModelRuntimeError('model_load_failed')`.
  Delete `_upsampler` from `upscale_runner.py`; import and call the shared
  helper after its existing `model_path_for_alias()` marker validation.

- [ ] **Step 3: Make `model_smoke.py` initialize but never infer.**

  Replace its marker-only `main()` with an argparse CLI accepting
  `--backend {cpu,cuda}` (default `cpu`) and `--model-dir` (default existing
  model root). Its implementation must follow this order:

  ```python
  marker = verify_ready(model_root)
  for family_id, canonical_alias, aliases in MODEL_SMOKE_FAMILIES:
      build_upsampler(canonical_alias, backend, model_root / select_model(canonical_alias).filename)
      families.append({'id': family_id, 'aliases': list(aliases)})
  print(json.dumps({'ok': True, 'backend': backend, 'commit': marker['commit'], 'families': families}, sort_keys=True))
  ```

  Catch only `ModelRuntimeError` at the CLI boundary and print the fixed,
  path-free error JSON. Do not import image decoding, NumPy, or call
  `enhance()`.

- [ ] **Step 4: Run focused tests and confirm the implementation passes.**

  Run the same Python and PHP commands from Task 1.

  Expected: all focused tests pass; no test needs an actual model download or
  GPU.

- [ ] **Step 5: Commit the runtime implementation.**

  ```bash
  git add packs/image-tools/service/model_runtime.py \
    packs/image-tools/service/upscale_runner.py \
    packs/image-tools/service/model_smoke.py
  git commit -m "feat: add image tools L4a model smoke"
  ```

## Task 3: Publish the L4a identity and build gate

**Files:**
- Modify: `packs/image-tools/pack.json`
- Modify: `packs/image-tools/service/app.py`
- Modify: `packs/image-tools/service/Dockerfile`
- Modify: `README.md`
- Modify: `docs/operations/image-tools.md`

- [ ] **Step 1: Update the Pack identity and level declaration.**

  Apply only these manifest changes:

  ```json
  "name": "影像工具",
  "runtime_level": "L4a-model-init-smoke",
  "target_level": "L5-benchmark-ready",
  "description": "本機複合式影像處理工具；目前提供 Real-ESRGAN 圖片放大，後續功能將以獨立 operation 擴充。"
  ```

  Do not change `id`, `default_mode`, operations, aliases, endpoints, request
  schema, queue, or storage mounts.

- [ ] **Step 2: Publish L4a in ready health without making health expensive.**

  In `app.py`, retain the same `verify_ready(model_dir())` boundary. Replace
  only the ready branch's level with `L4a-model-init-smoke`; do not call
  `build_upsampler()` from `/health`.

  ```python
  return {'ok': True, 'service': 'image-tools', 'ready': True,
          'runtime_level': 'L4a-model-init-smoke', 'runtime_ready': True}
  ```

- [ ] **Step 3: Include and run all deterministic L4a tests in Docker.**

  Add `test_model_smoke.py` to the Docker `COPY` list and to the `unittest`
  command. The build gate remains offline: it imports Real-ESRGAN but does not
  mount/download weights or execute `model_smoke.py` against a model.

  ```dockerfile
  COPY ... test_model_runtime.py test_model_smoke.py test_upscale_runner.py ... ./
  RUN python3 -m unittest -v test_image_contract.py test_model_runtime.py \
      test_model_smoke.py test_upscale_runner.py test_jobs.py test_app.py \
      && chmod 0555 /app/*.py
  ```

- [ ] **Step 4: Update documentation without declaring L4b/L5.**

  In the README and runbook, call the Pack `影像工具`, describe Real-ESRGAN
  as current `upscale` support, and publish the exact L4a command:

  ```bash
  docker compose -f data/services/image-tools-main/docker-compose.generated.yml exec -T image-tools python3 /app/model_smoke.py --backend cpu
  ```

  State that its JSON proves local model initialization only. L4b still needs
  actual CPU/CUDA HTTP upscaling, and L5 still needs a declared benchmark and
  quality acceptance; neither result may be inferred from this command.

- [ ] **Step 5: Run the source-level release gate.**

  Run:

  ```bash
  python3 -m unittest -v \
    packs/image-tools/service/test_image_contract.py \
    packs/image-tools/service/test_model_runtime.py \
    packs/image-tools/service/test_model_smoke.py \
    packs/image-tools/service/test_upscale_runner.py \
    packs/image-tools/service/test_jobs.py \
    packs/image-tools/service/test_app.py \
    packs/image-tools/service/test_acceptance.py
  php scripts/run_tests.php --suite=full
  docker build -t 3waaihub-image-tools:test packs/image-tools/service
  ```

  Expected: all image-tools Python/PHP tests pass and Docker reports a
  successful image build. The Docker output must include the L4a smoke unit
  tests, but not an unmounted real model smoke.

- [ ] **Step 6: Commit the public L4a contract and build gate.**

  ```bash
  git add packs/image-tools/pack.json packs/image-tools/service/app.py \
    packs/image-tools/service/Dockerfile README.md docs/operations/image-tools.md
  git commit -m "docs: publish image tools L4a runtime"
  ```

## Task 4: Run controlled model acceptance and record the evidence

**Files:**
- Modify: `docs/operations/image-tools-acceptance.md`

- [ ] **Step 1: Confirm the staged source is the pinned model set.**

  Run:

  ```bash
  sha256sum /park/models/image-tools/realesrgan/RealESRGAN_x4plus.pth \
    /park/models/image-tools/realesrgan/RealESRGAN_x4plus_anime_6B.pth \
    /park/models/image-tools/realesrgan/realesr-animevideov3.pth
  ```

  Expected hashes are the three `MODEL_ASSETS` values in
  `model_runtime.py`; `ready.json` must exist and the directory must be mounted
  read-only. Do not copy models into Git or a Docker layer.

- [ ] **Step 2: Execute the CPU-only L4a smoke as the runtime user.**

  Run:

  ```bash
  docker run --rm --user 65532:65532 \
    -e IMAGE_TOOLS_MODEL_DIR=/models/image-tools/realesrgan \
    -v /park/models/image-tools/realesrgan:/models/image-tools/realesrgan:ro \
    3waaihub-image-tools:test python3 /app/model_smoke.py --backend cpu
  ```

  Expected: exit 0 and JSON with `ok=true`, `backend=cpu`, the pinned commit,
  and exactly the three family IDs covering all five public aliases. A nonzero
  exit or any other output blocks L4a promotion; do not substitute L3 marker
  verification for this test.

- [ ] **Step 3: Record only redacted L4a facts.**

  Append a dated L4a section to the acceptance record containing the image
  digest/tag, exit result, CPU backend, source commit, three family IDs,
  covered aliases, elapsed time, and the statement that no image inference
  occurred. Do not record source paths beyond the documented model mount,
  checksums, model binaries, tokens, uploaded images, output images, or full
  container logs.

- [ ] **Step 4: Run the final regressions and inspect the diff.**

  Run:

  ```bash
  python3 -m unittest -v \
    packs/image-tools/service/test_image_contract.py \
    packs/image-tools/service/test_model_runtime.py \
    packs/image-tools/service/test_model_smoke.py \
    packs/image-tools/service/test_upscale_runner.py \
    packs/image-tools/service/test_jobs.py \
    packs/image-tools/service/test_app.py \
    packs/image-tools/service/test_acceptance.py
  php scripts/run_tests.php --suite=full
  git diff --check
  git status --short
  ```

  Expected: image-tools tests pass; record the PHP suite's exact exit code and
  separately report any pre-existing unrelated failures/warnings rather than
  calling the full suite wholly green.

- [ ] **Step 5: Commit the redacted acceptance record.**

  ```bash
  git add docs/operations/image-tools-acceptance.md
  git commit -m "docs: record image tools L4a acceptance"
  ```

## Final Verification Checklist

- [ ] The Pack is named `影像工具`, while `pack_id=image-tools` and every
  existing API contract remain stable.
- [ ] `model_smoke.py --backend cpu` verifies the marker and initializes all
  three distinct local model families without decoding an image or inferencing.
- [ ] The production upscale runner delegates model construction to that same
  shared loader.
- [ ] L4a is declared only after actual model-smoke evidence is recorded.
- [ ] No L4b/L5 status, new operation, model download, or persistent image
  storage is introduced.
