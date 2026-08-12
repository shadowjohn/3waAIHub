# Image Tools L4a Model Initialization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Promote `image-tools` to L4a by proving its pinned local
Real-ESRGAN model families initialize through the same loader used by image
upscaling, while retaining a generic Chinese Image Tools Pack identity.

**Architecture:** Move only the Real-ESRGAN architecture/weight construction
from `upscale_runner.py` to `model_runtime.py`. The existing runner still owns
request/image/output processing, while `model_smoke.py` verifies the immutable
marker once and calls the shared loader once per distinct model family. Tasks
1–3 retain the operational L3 marker state while they establish the generic
identity and deterministic loader/build gate. Only a successful installed CPU
smoke and its redacted L4a evidence record permit the manifest, `/health`, and
operator documentation to announce L4a; L4b HTTP inference and L5 quality
work remain separate.

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
| `packs/image-tools/service/test_app.py` | Marker-only health regression before promotion and L4a promotion regression after evidence. |
| `packs/image-tools/service/Dockerfile` | Runs the new deterministic L4a tests during image build. |
| `packs/image-tools/pack.json` | Generic Chinese identity, retained L3 operational state, and post-evidence L4a/L5 declarations. |
| `tests/test_image_tools.php` | Pack, UI-doc, and post-evidence L4a command/evidence contract assertions. |
| `README.md`, `docs/operations/image-tools.md`, `docs/operations/image-tools-acceptance.md` | Generic Pack statement, post-evidence L4a operator command, and redacted host evidence. |

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

- [ ] **Step 3: Extend the runner and pre-promotion health tests.**

  In `test_upscale_runner.py`, replace the `_upsampler` patch with a
  `model_runtime.build_upsampler` patch and assert the runner passes the pinned
  resolved path, the requested alias, and resolved backend. In `test_app.py`,
  keep a verified marker on the existing operational level until Task 4's
  installed CPU smoke succeeds:

  ```python
  {'ok': True, 'service': 'image-tools', 'ready': True,
   'runtime_level': 'L3-offline-assets', 'runtime_ready': True}
  ```

  Patch `model_runtime.build_upsampler` to raise if called and assert it remains
  uncalled: pre-promotion `/health` verifies only the marker and must not load
  a model. Task 4 changes this same expectation to L4a only after its real
  CPU smoke and recorded evidence succeed.

  Keep the missing/tampered marker case at `L1-contract` and `runtime_ready`
  false.

- [ ] **Step 4: Extend the generic Pack identity assertions without promoting runtime.**

  Assert exact generic identity and target-level values without changing
  endpoint/API assertions. The current operational runtime remains L3 until
  Task 4 records CPU smoke evidence:

  ```php
  hub_test_assert(($manifest['name'] ?? '') === '影像工具', 'image-tools display name must remain generic Chinese');
  hub_test_assert(($manifest['description'] ?? '') === '本機複合式影像處理工具；目前提供 Real-ESRGAN 圖片放大，後續功能將以獨立 operation 擴充。', 'image-tools description mismatch');
  hub_test_assert(($manifest['runtime_level'] ?? '') === 'L3-offline-assets', 'image-tools must retain L3 until installed L4a smoke evidence exists');
  hub_test_assert(($manifest['target_level'] ?? '') === 'L5-benchmark-ready', 'image-tools target level mismatch');
  ```

  Do not yet assert an L4a README/runbook command or a current L4a status;
  those public assertions are added only after Task 4 has recorded the
  successful installed-runtime evidence. Keep the L4b/L5 negative assertions
  with that later public contract.

- [ ] **Step 5: Run the focused tests and confirm they fail for the missing L4a implementation.**

  Run:

  ```bash
  python3 -m unittest -v \
    packs/image-tools/service/test_model_runtime.py \
    packs/image-tools/service/test_model_smoke.py \
    packs/image-tools/service/test_upscale_runner.py \
    packs/image-tools/service/test_app.py
  ```

  Expected: Python fails because `build_upsampler` and the new smoke module
  contract do not yet exist. The full PHP release gate is deliberately deferred
  to Task 4, after actual CPU smoke evidence permits the L4a public contract.

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

- [ ] **Step 4: Run focused Python tests and confirm the implementation passes.**

  Run the Python command from Task 1 only; no real model download or GPU is
  needed, and the full PHP gate remains deferred until Task 4.

  Expected: all focused tests pass; no test needs an actual model download or
  GPU.

- [ ] **Step 5: Commit the runtime implementation.**

  ```bash
  git add packs/image-tools/service/model_runtime.py \
    packs/image-tools/service/upscale_runner.py \
    packs/image-tools/service/model_smoke.py
  git commit -m "feat: add image tools L4a model smoke"
  ```

## Task 3: Publish the generic identity and offline build gate while retaining L3

**Files:**
- Modify: `packs/image-tools/pack.json`
- Modify: `packs/image-tools/service/app.py`
- Modify: `packs/image-tools/service/Dockerfile`
- Modify: `README.md`
- Modify: `docs/operations/image-tools.md`

- [ ] **Step 1: Update the Pack identity while retaining the operational L3 declaration.**

  Apply only these manifest changes:

  ```json
  "name": "影像工具",
  "runtime_level": "L3-offline-assets",
  "target_level": "L5-benchmark-ready",
  "description": "本機複合式影像處理工具；目前提供 Real-ESRGAN 圖片放大，後續功能將以獨立 operation 擴充。"
  ```

  Do not change `id`, `default_mode`, operations, aliases, endpoints, request
  schema, queue, or storage mounts.

- [ ] **Step 2: Keep marker-only health on L3 without making it expensive.**

  In `app.py`, retain the same `verify_ready(model_dir())` boundary and retain
  the ready branch at `L3-offline-assets`; do not call `build_upsampler()` from
  `/health`. Task 4 changes the label only after its installed CPU smoke and
  evidence record both succeed.

  ```python
  return {'ok': True, 'service': 'image-tools', 'ready': True,
          'runtime_level': 'L3-offline-assets', 'runtime_ready': True}
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

- [ ] **Step 4: Publish generic identity documentation without claiming current L4a.**

  In the README and runbook, call the Pack `影像工具` and describe Real-ESRGAN
  as current `upscale` support, while retaining the actual current L3 status.
  Do not add an `## L4a` section, the installed-runtime smoke command, or any
  wording that says the Pack is currently L4a; those are Task 4 outputs.

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
  docker build -t 3waaihub-image-tools:test packs/image-tools/service
  ```

  Expected: all source-level Python tests pass and Docker reports a successful
  image build. The Docker output must include the L4a smoke unit tests, but
  not an unmounted real model smoke. Run the full PHP contract gate only in
  Task 4 after recording actual CPU smoke evidence; this source gate is valid
  while the operational manifest and marker-only `/health` remain L3.

- [ ] **Step 6: Commit the generic L3 identity and build gate.**

  ```bash
  git add packs/image-tools/pack.json packs/image-tools/service/app.py \
    packs/image-tools/service/Dockerfile README.md docs/operations/image-tools.md
  git commit -m "docs: publish image tools generic runtime identity"
  ```

## Task 4: Run controlled model acceptance and record the evidence

**Files:**
- Modify: `docs/operations/image-tools-acceptance.md`
- Modify: `packs/image-tools/pack.json`
- Modify: `packs/image-tools/service/app.py`
- Modify: `packs/image-tools/service/test_app.py`
- Modify: `README.md`
- Modify: `docs/operations/image-tools.md`
- Modify: `tests/test_image_tools.php`

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
  docker compose -f data/services/image-tools-main/docker-compose.generated.yml exec -T image-tools python3 /app/model_smoke.py --backend cpu
  ```

  Expected: exit 0 and JSON with `ok=true`, `backend=cpu`, the pinned commit,
  and exactly the three family IDs covering all five public aliases. A nonzero
  exit or any other output blocks L4a promotion; do not substitute L3 marker
  verification for this test.

- [ ] **Step 3: Record one structured, redacted L4a evidence block.**

  Append an isolated `## L4a` section containing the plain declaration `no
  source image/inference output` and exactly one `json` fenced evidence block.
  Its top-level key order is fixed: `date`, `image_tag`, `exit_result`,
  `backend`, `commit`, `loaded_family_ids`, `aliases`, `elapsed_time_ms`.
  Populate every value from Step 2's command and its installed image metadata:
  nonempty date and image tag, numeric `exit_result` `0`, backend `cpu`, commit
  `a4abfb2979a7bbff3f69f58f58ae324608821e27`, family IDs
  `realesrgan-x4plus`, `realesrgan-x4plus-anime`, and
  `realesr-animevideov3` in that order, all five public aliases in their
  manifest order, and a nonnegative numeric elapsed time. Do not add a second
  JSON block, host paths, model binaries, uploaded images, output images, or
  full container logs.

- [ ] **Step 4: Promote the public L4a state only after the evidence exists.**

  First change the pre-promotion `test_app.py` health expectation from
  `L3-offline-assets` to `L4a-model-init-smoke`, preserving the patched
  `build_upsampler` assertion that it remains uncalled. Extend
  `tests/test_image_tools.php` to require the exact installed Compose command
  from Step 2, the successful structured acceptance JSON, and no L4b/L5-ready
  claim. Then change `pack.json` and the marker-only ready branch in `app.py`
  to `L4a-model-init-smoke`; `health()` must still not call
  `build_upsampler()`.
  Update the README and runbook to state current L4a, publish only Step 2's
  installed Compose command, and state that it proves initialization only.
  L4b HTTP inference and L5 benchmark/quality acceptance remain unclaimed.

- [ ] **Step 5: Run the final regressions and inspect the diff.**

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

- [ ] **Step 6: Commit the evidence-backed L4a promotion.**

  ```bash
  git add docs/operations/image-tools-acceptance.md packs/image-tools/pack.json \
    packs/image-tools/service/app.py packs/image-tools/service/test_app.py \
    README.md docs/operations/image-tools.md tests/test_image_tools.php
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
