# Manual Vision DocVQA Pack Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a separately deployable, CUDA-only `vlm-manual-vision` Pack that answers one bounded English DocVQA question about one PNG/JPEG through `mode=manual_vision` and `operation=docvqa`, using an operator-provisioned `google/paligemma-3b-ft-docvqa-448` float16 snapshot.

**Architecture:** The Pack is a narrow sync API service.  Its own service validates the entire multipart form, composes the one fixed prompt `answer en {question}`, runs the locally provisioned PaliGemma 1 DocVQA model, and emits a redacted JSON answer.  Existing Pack discovery, token-mode authorization, native gateway proxying, Cluster multipart relay, and service-health inventory are reused; no new Router route class or voice/profile plumbing is introduced.  Node provisioning and fixed-fixture CUDA acceptance are explicit command jobs.  A node only publishes `manual_vision` after the acceptance record succeeds.

**Tech Stack:** PHP 8 / existing Pack registry, gateway, Cluster Router, command queue and Docker runtime; Python 3.11, PyTorch CUDA, Transformers, Pillow; Docker Compose; existing PHP assertion suites and Python `unittest`.

**Non-goals:** Do not modify `vlm-paligemma2` / `paligemma2`, VoxCPM2, GPT-SoVITS, Voice Profiles, character-clone routes, PP-OCRv5, or `pdf2html`.  Revision 1 does not expose OCR, caption, detection, coordinates, translation, arbitrary prompts, caller model/generation controls, PDF upload, multi-page tasks, model comparison, automatic GPU eviction, or an 8 GiB compatibility promise.

---

## Contract to preserve

The native station and Cluster Router expose exactly this authenticated request:

```text
POST api.php?mode=manual_vision
Content-Type: multipart/form-data
Authorization: Bearer <token with manual_vision permission>

operation=docvqa
image=<one PNG or JPEG, <= 50 MiB>
question=<trimmed printable-ASCII English, 1..400 bytes, includes [A-Za-z]>
```

The service accepts exactly `operation`, `image`, and `question`.  Any `prompt`,
`model`, `revision`, `max_tokens`, `temperature`, `device`, path/URL, extra file,
or unknown scalar field returns `bad_request`; an operation other than `docvqa`
returns `unsupported_operation`.  Leading/trailing ASCII whitespace is removed from
the question and the rest is preserved.  The only model input is:

```python
prompt = f"answer en {question}"
```

The model-specific processor integration must have a test proving that it passes
that exact text; it must not copy the PaliGemma 2 baseline formatter or add a
publicly configurable prompt prefix.  The server owns `max_new_tokens=64` with a
hard configuration maximum of `128`, and accepts one inference at a time.

The only successful public shape is:

```json
{
  "ok": true,
  "mode": "manual_vision",
  "operation": "docvqa",
  "answer": "1.2 L",
  "answer_language": "en",
  "contract_revision": 1,
  "elapsed_ms": 840,
  "request_id": "req_..."
}
```

No model identifier/revision, prompt recipe, snapshot location, host, GPU, container,
token, task, artifact, or ACK URL is public.  Stable errors are `bad_request`,
`unsupported_operation`, `bad_image`, `file_too_large`, `missing_token`, and
`token_mode_not_allowed`; operational errors are `gpu_unavailable`,
`model_not_provisioned`, `model_manifest_invalid`, `runtime_not_ready`,
`inference_failed`, and `gateway_timeout`.  The service returns its local failures;
the existing native/Cluster proxy maps an elapsed upstream deadline to
`gateway_timeout` without exposing a runtime detail.

## File map

- Create: `packs/vlm-manual-vision/{pack.json,README.md,docker-compose.yml,runtime-settings.example.conf}` — Pack manifest, operator contract, and resident service definition.
- Create: `packs/vlm-manual-vision/service/{Dockerfile,requirements.txt,app.py,provision.py,acceptance.py,gpu_smoke.py}` — CUDA service, gated snapshot provisioner, and offline acceptance tooling.
- Create: `packs/vlm-manual-vision/service/tests/{test_app.py,test_provision.py,test_acceptance.py}` — smallest direct contract and artifact checks.
- Create: `packs/vlm-manual-vision/demo/{manual_text_page.png,manual_specs_table.png,manual_labelled_diagram.png,acceptance_cases.json}` — versioned non-sensitive fixed acceptance inputs and expected answers.
- Modify: `packs/catalog.json`, `app/pack_registry.php`, `app/public_api_docs.php` — Pack catalog, L5 contract discovery, and generated public contract.
- Modify: `app/docker_runner.php`, `app/command_queue.php`, `scripts/command_worker.php` — explicit provision/acceptance jobs and the existing Docker/WSL runtime path.
- Modify only if the new failing relay test proves it necessary: `app/cluster_router.php` — shared multipart limits or safe error relay; no `manual_vision` special routing branch.
- Modify: `tests/test_manual_vision_pack.php`, `tests/test_cluster_router.php`, `tests/test_command_queue.php`, `tests/test_public_api_docs.php`, `tests/suites/control-plane.php`, `tests/test_windows_installer.ps1` — Pack, public-contract, node-command, and Cluster regression coverage.
- Modify: `README.md`, `docs/api_examples.md`, `docs/cluster-router.md`, `docs/operations/manual-vision-docvqa.md`, `scripts/windows/install-wsl-runtime.ps1`, `scripts/windows/write-runtime-profile.ps1` — caller, Cluster, node, and WSL operator documentation/install support.
- Do not modify: `packs/vlm-paligemma2/**`, `packs/tts-voxcpm2/**`, `packs/ocr-ppocrv5/**`, `pdf2html/**`, any MyAI route, or any voice/profile database table.

### Task 1: Register the narrow public Pack contract first

**Files:**
- Create: `packs/vlm-manual-vision/pack.json`
- Modify: `packs/catalog.json`, `app/pack_registry.php`, `app/public_api_docs.php`
- Create: `tests/test_manual_vision_pack.php`
- Modify: `tests/test_public_api_docs.php`, `tests/suites/control-plane.php`

- [ ] **Step 1: Add failing Pack/public-doc assertions.**

Create `tests/test_manual_vision_pack.php` using the existing Pack fixture helpers.  It must assert that the registered service is `api_service`, `sync_api`, CUDA-only, `max_concurrency=1`, has `default_mode=manual_vision`, `/vision/docvqa` as the only invoke path, 50 MiB upload maximum, and no CPU fallback.  Assert its L5 input fields are exactly `operation`, `image`, and `question`, its only operation enum is `docvqa`, and public output required keys are exactly the seven contract keys above.

In `tests/test_public_api_docs.php`, install/enable the test Pack and assert the generated native and Cluster service contract names `manual_vision`, says `English DocVQA`, lists the fixed operation/error table, and contains neither `google/paligemma`, `HF_TOKEN`, `prompt`, `model`, `GPU`, nor a filesystem path.  Add the test to `tests/suites/control-plane.php`.

- [ ] **Step 2: Verify RED.**

Run:

```bash
php tests/test_manual_vision_pack.php
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane
```

Expected: the focused Pack test fails because `vlm-manual-vision` is absent.  Record any already-existing unrelated control-plane failures separately; do not repair them in this feature.

- [ ] **Step 3: Add the manifest and catalog row.**

Add one catalog row and one manifest, following the current L5 `api_service` manifest schema rather than adding registry abstractions.  Set `id=vlm-manual-vision`, version `0.1.0`, category `vision`, provider `google-paligemma`, default mode `manual_vision`, and capability `document-question-answering`.  Declare `runtime_ready=false`, CUDA required/supported, `min_vram_mb=8192` as a preflight hint only, no CPU fallback, queue concurrency 1, and `/vision/docvqa` with `POST`/`multipart/form-data`.

Declare an L5 contract with all fixed request/output/error information from **Contract to preserve**, including `contract_revision=1` and `answer_language=en`.  Do not declare a `prompt`, `model`, `max_tokens`, `real_inference`, or a mock request control.  Define private mounts for `/models/manual-vision`, `/cache/manual-vision`, and `/data/service`; only the model mount is read-only in the runtime.  The settings schema must include:

```text
MANUAL_VISION_MODEL=google/paligemma-3b-ft-docvqa-448
MANUAL_VISION_MODEL_REVISION=<operator-approved immutable HF commit>
MANUAL_VISION_DEVICE=cuda
MANUAL_VISION_TORCH_DTYPE=float16
MANUAL_VISION_MAX_NEW_TOKENS=64 (min 1, max 128)
MANUAL_VISION_MAX_UPLOAD_MB=50 (min 1, max 50)
HF_TOKEN=<secret, provision only>
```

The provisioning job must reject a floating revision.  The commit value is recorded only after verifying the gated model release and is never returned by the API or included in a public document.

Use `hub_pack_l5_contract()` and the existing generated-doc path unchanged where it already consumes the manifest.  Add a small `manual_vision` formatter only if the generic document renderer cannot describe the manifest's `operation` and output constants; do not copy the voice-specific special case.

- [ ] **Step 4: Verify GREEN and commit.**

Run:

```bash
php tests/test_manual_vision_pack.php
php tests/test_public_api_docs.php
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane
git diff --check
git add packs/catalog.json packs/vlm-manual-vision/pack.json app/pack_registry.php app/public_api_docs.php tests/test_manual_vision_pack.php tests/test_public_api_docs.php tests/suites/control-plane.php
git commit -m "feat: register manual vision docvqa pack"
```

### Task 2: Implement strict service request validation and answer projection

**Files:**
- Create: `packs/vlm-manual-vision/service/app.py`, `Dockerfile`, `requirements.txt`
- Create: `packs/vlm-manual-vision/service/tests/test_app.py`
- Create: `packs/vlm-manual-vision/docker-compose.yml`, `runtime-settings.example.conf`

- [ ] **Step 1: Write unit tests before the service.**

In `test_app.py`, use a fake processor/model and an in-memory valid PNG.  Assert that:

```python
parse_request({"operation": "docvqa", "question": "  What is the rated capacity?  "})
assert parsed.question == "What is the rated capacity?"
assert format_docvqa_prompt(parsed.question) == "answer en What is the rated capacity?"
```

Assert rejection of non-ASCII, no English letter, empty/401-byte question, `operation=caption`, a `file` alias, a second upload, caller `prompt`, model/revision/generation/device/path fields, and an unknown field.  Assert generated arguments use the server setting `64` and reject configuration greater than `128`.  Assert a fake decoded result produces the exact seven public keys, with a generated `req_` request id, and does not contain a model/prompt/device key.  Assert missing verified snapshot is `model_not_provisioned`, failed record is `runtime_not_ready`, unavailable CUDA is `gpu_unavailable`, and decode failure is `inference_failed`.

- [ ] **Step 2: Verify RED.**

Run:

```bash
python3 -m unittest -v packs/vlm-manual-vision/service/tests/test_app.py
```

Expected: import failure because the service does not exist.

- [ ] **Step 3: Add the smallest service.**

Implement one FastAPI endpoint at `/vision/docvqa` and one `/health` endpoint.  At the trust boundary call `await request.form()` and reject any key outside the three allowed names; FastAPI's normal unknown-form-field ignoring is not acceptable here.  Require one uploaded PNG/JPEG, verify both signature and Pillow decoding, enforce the configured `<=50 MiB` limit before model loading, and do not persist the upload beyond request cleanup.

Load only the verified local snapshot using the checkpoint-appropriate PaliGemma 1 processor/model, float16 on CUDA.  Keep the input formatter in a small named function and pass the test's exact `answer en {question}` string to the processor.  Do not copy the PaliGemma 2 `<image>` prompting behavior without the direct processor-capture test passing.  Serialize generation under one in-process `asyncio.Lock`; return `gateway_timeout` if the existing gateway deadline ends first rather than queuing indefinitely.  Decode/trim the answer and return only the public response shape.

The Dockerfile starts from the existing accepted CUDA Python base for this repository, pins the smallest compatible Torch/Transformers/Pillow/FastAPI versions in `requirements.txt`, runs the unit test during build, uses a non-root user, and neither includes weights nor downloads models.  `docker-compose.yml` maps the manifest's three storage roots, exposes only `127.0.0.1:<assigned-port>:8000`, grants GPU access, and uses `restart: unless-stopped`.  `runtime-settings.example.conf` contains names only, never a token or revision value.

- [ ] **Step 4: Verify GREEN and commit.**

Run:

```bash
python3 -m unittest -v packs/vlm-manual-vision/service/tests/test_app.py
php tests/test_manual_vision_pack.php
git diff --check
git add packs/vlm-manual-vision/service/app.py packs/vlm-manual-vision/service/Dockerfile packs/vlm-manual-vision/service/requirements.txt packs/vlm-manual-vision/service/tests/test_app.py packs/vlm-manual-vision/docker-compose.yml packs/vlm-manual-vision/runtime-settings.example.conf
git commit -m "feat: add strict manual vision service"
```

### Task 3: Make gated provisioning and fixed CUDA acceptance publishable

**Files:**
- Create: `packs/vlm-manual-vision/service/provision.py`, `acceptance.py`, `gpu_smoke.py`
- Create: `packs/vlm-manual-vision/service/tests/test_provision.py`, `test_acceptance.py`
- Create: `packs/vlm-manual-vision/demo/{manual_text_page.png,manual_specs_table.png,manual_labelled_diagram.png,acceptance_cases.json}`
- Modify: `packs/vlm-manual-vision/pack.json`, `README.md`

- [ ] **Step 1: Add failing snapshot/acceptance tests.**

`test_provision.py` must build temporary fake snapshot directories and prove the verifier rejects a missing marker, a non-40-hex revision, a model id other than `google/paligemma-3b-ft-docvqa-448`, a non-float16 dtype, a symlink, or a changed listed SHA-256.  It must accept only a complete staged manifest written after all files hash correctly.

`test_acceptance.py` must use a fake inference function and the committed `acceptance_cases.json`.  Require exactly three cases in this order, with the checked questions/answers:

```json
[
  {"id":"manual-text","question":"What is the shutdown temperature?","answer":"85 °C"},
  {"id":"spec-table","question":"What is the rated capacity?","answer":"1.2 L"},
  {"id":"labelled-diagram","question":"What component is marked A?","answer":"Fuse"}
]
```

Assert answer normalization is only `strip()` plus ASCII-whitespace collapse; it must not case-fold, remove punctuation, or alter digits/units.  Assert a wrong answer, empty answer, CUDA OOM, or fewer than `512 MiB` free at observed peak writes a failed record and never writes a success record.  Assert a success record includes fixture SHA-256, exact normalized answer, cold/warm elapsed milliseconds, peak/remaining VRAM, model revision, dtype, and timestamp but contains no `HF_TOKEN`.

- [ ] **Step 2: Verify RED.**

Run:

```bash
python3 -m unittest -v packs/vlm-manual-vision/service/tests/test_provision.py packs/vlm-manual-vision/service/tests/test_acceptance.py
```

Expected: module/import failure.

- [ ] **Step 3: Implement staged provision and acceptance record.**

`provision.py` receives only environment settings plus a secret `HF_TOKEN`, validates a full immutable revision before requesting the gated Hugging Face snapshot, downloads to a private staging directory, validates required model files and every hash, then atomically publishes `/models/manual-vision/<revision>/manifest.json`.  It sets the current snapshot pointer only after verification.  It must never leave a ready marker after a failed provision and must not log a token.

`acceptance.py` calls the same local inference path (no network), processes all three images serially once cold and once warm, measures elapsed time and peak/free CUDA memory, and atomically writes `/data/service/manual-vision-acceptance.json` only on an all-pass run.  `gpu_smoke.py` reports CUDA/driver/available VRAM and exits non-zero for missing CUDA; it does not start or stop another service.  `/health` and service readiness must require both a valid snapshot manifest and that success record.

Commit only compact non-sensitive fixture images and `acceptance_cases.json`; no weights, cache, acceptance record, or runtime secret goes into Git.  In the Pack README state that 8 GiB admission is verified per node, not assumed, and an operator may temporarily stop a GPU service then restore it without deleting any Voice Profile/volume.

- [ ] **Step 4: Verify GREEN and commit.**

Run:

```bash
python3 -m unittest -v packs/vlm-manual-vision/service/tests/test_provision.py packs/vlm-manual-vision/service/tests/test_acceptance.py
python3 -m unittest -v packs/vlm-manual-vision/service/tests/test_app.py
git diff --check
git add packs/vlm-manual-vision
git commit -m "feat: add manual vision provision acceptance"
```

### Task 4: Add only the existing node-runtime hooks needed to run the Pack

**Files:**
- Modify: `app/docker_runner.php`, `app/command_queue.php`, `scripts/command_worker.php`
- Modify: `scripts/windows/install-wsl-runtime.ps1`, `scripts/windows/write-runtime-profile.ps1`
- Create: `packs/vlm-manual-vision/service/Dockerfile.pascal-cu118`, `requirements.pascal-cu118.txt` only when the current Pascal WSL profile's pinned CUDA base cannot run the default Dockerfile
- Modify: `tests/test_command_queue.php`, `tests/test_windows_installer.ps1`, `tests/test_manual_vision_pack.php`

- [ ] **Step 1: Add failing node-command tests.**

Extend the existing command queue tests with a manual-vision service fixture.  Assert an explicit `manual_vision_provision` command invokes `provision.py` in a one-shot service container, and `manual_vision_acceptance` invokes `acceptance.py` with the three committed fixture paths, then captures only bounded redacted output.  Assert both actions reject another Pack id and preserve standard job status/exit-code semantics.  Assert the service compose command mounts the verified models read-only, cache and service data writable, runs CUDA with one resident API process, and does not inject `HF_TOKEN` into the resident compose environment.

Add Windows installer/profile assertions only for copying the new Pack source and writing its standard WSL settings/mounts; the tests must reject `%USERPROFILE%`, host paths, and tokens in generated public config.  If the existing default WSL CUDA base runs the Pack, assert it is selected; otherwise add the minimal existing Pascal-CUDA profile branch and test only its profile selection.

- [ ] **Step 2: Verify RED.**

Run:

```bash
php tests/test_command_queue.php
powershell -NoProfile -ExecutionPolicy Bypass -File tests/test_windows_installer.ps1
```

Expected: failures for the missing commands/profile only.

- [ ] **Step 3: Implement the vertical runtime slice.**

In `app/docker_runner.php`, add Pack-specific helpers beside the existing OCR/Pali runtime helpers:

```php
hub_manual_vision_wsl_runtime_profile(...)
hub_manual_vision_provisioning_plan(...)
hub_run_manual_vision_provision_job(...)
hub_manual_vision_acceptance_args(...)
hub_run_manual_vision_acceptance_job(...)
hub_manual_vision_wsl_service_compose_command(...)
```

They may reuse the current generic Docker execution, setting-file validation, log redaction, and WSL quoting helpers.  Do not invent a generic VLM framework for a single new Pack.  Mount `/models/manual-vision` read-only for the resident service, while the one-shot provision job is the sole process allowed to write the staged model root.  Reuse the command-worker's existing action dispatch and execution bookkeeping; add only the two explicit action cases and appropriate long timeout.  Ensure routine start/restart never re-provisions or re-accepts the model.

Update the WSL installer/profile scripts only enough to copy the Pack, select the tested CUDA image/profile, provision its three mounts, and run the same actions.  Keep the prior PaliGemma and OCR behavior byte-for-byte unchanged.

- [ ] **Step 4: Verify GREEN and commit.**

Run:

```bash
php tests/test_command_queue.php
php tests/test_manual_vision_pack.php
powershell -NoProfile -ExecutionPolicy Bypass -File tests/test_windows_installer.ps1
git diff --check
git add app/docker_runner.php app/command_queue.php scripts/command_worker.php scripts/windows/install-wsl-runtime.ps1 scripts/windows/write-runtime-profile.ps1 packs/vlm-manual-vision tests/test_command_queue.php tests/test_windows_installer.ps1 tests/test_manual_vision_pack.php
git commit -m "feat: add manual vision node runtime actions"
```

### Task 5: Prove native and Cluster relay behavior without a Router fork

**Files:**
- Modify: `tests/test_cluster_router.php`, `tests/test_manual_vision_pack.php`
- Modify: `app/cluster_router.php` only if a failing test proves a shared restriction prevents the declared request

- [ ] **Step 1: Add failing direct/Cluster contract tests.**

Using the existing fake service/Cluster transport fixtures, send one `multipart/form-data` request for `mode=manual_vision` with the three permitted fields and a safe PNG.  Assert native mode permission accepts the token, forwards one request to `/vision/docvqa`, and returns the redacted seven-key JSON unchanged apart from the standard Router request id.

Assert the Cluster case selects only a station whose live manifest advertises accepted `manual_vision`, forwards the multipart scalar fields and file once, replaces rather than forwards the caller bearer credential at the station boundary, and returns the same response.  Test these failures: missing permission gives `token_mode_not_allowed`; no accepted station gives an availability error; station `bad_request`/`unsupported_operation` relay as those stable codes; request/response body never contains station token, node id, model id, revision, GPU, snapshot path, or `HF_TOKEN`.

Also send `prompt`, `max_tokens`, a 401-byte question, and a second file through the Router.  The final response must be the service's stable rejection, not silently accepted or locally rewritten.  This demonstrates the service owns strict semantics while Router preserves generic multipart behavior.

- [ ] **Step 2: Verify RED.**

Run:

```bash
php tests/test_cluster_router.php
php tests/test_manual_vision_pack.php
```

Expected: the new route cannot yet be discovered or one asserted public response path fails.  If generic multipart relay passes intact, leave `app/cluster_router.php` unchanged.

- [ ] **Step 3: Make the smallest necessary integration correction.**

First use normal Pack discovery/live-manifest selection and generic proxy logic.  Only if the test identifies a real shared limit, add a `manual_vision` case to `hub_cluster_router_multipart_scalar_limits()` allowing exactly `operation` (6 bytes) and `question` (400 bytes); do not add prompt/model fields or a new station-selection/pinning rule.  Add a shared error-table entry only where existing relay code otherwise converts one of the declared stable errors into `router_response_failed`.

- [ ] **Step 4: Verify GREEN and commit.**

Run:

```bash
php tests/test_cluster_router.php
php tests/test_manual_vision_pack.php
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane
git diff --check
git add app/cluster_router.php tests/test_cluster_router.php tests/test_manual_vision_pack.php
git commit -m "test: cover manual vision cluster relay"
```

If Router source did not change, omit it from `git add` and state in the commit/PR summary that generic multipart relay was proven sufficient.

### Task 6: Publish node/Cluster runbooks and execute the hardware release gate

**Files:**
- Modify: `README.md`, `docs/api_examples.md`, `docs/cluster-router.md`
- Create: `docs/operations/manual-vision-docvqa.md`
- Modify: `packs/vlm-manual-vision/README.md`

- [ ] **Step 1: Add documentation assertions, then write the runbooks.**

Add assertions to `tests/test_public_api_docs.php`/`tests/test_manual_vision_pack.php` that the generated docs and Pack README say: English DocVQA is an answer capability, not literal OCR; the prompt contract is `answer en {question}`; accepted request fields are only three; no public model/profile/path control exists; and PaliGemma2, PP-OCRv5, and `pdf2html` remain separate.

Document one native and one Cluster `curl -F` example, using a placeholder bearer token and no private paths.  In `docs/operations/manual-vision-docvqa.md`, give these exact operator phases:

1. confirm the Hugging Face license has been accepted and place the secret only in the one-shot provision job settings;
2. run GPU smoke and confirm no automatic service is stopped;
3. optionally pause a chosen GPU container, run `manual_vision_provision`, then start the resident service;
4. run `manual_vision_acceptance` and inspect all three exact normalized answers, cold/warm milliseconds, and peak free VRAM `>=512 MiB`;
5. publish/refresh the node manifest only after a success record; then test native and Router request examples;
6. on failure, keep the Pack disabled/unpublished, collect redacted command log, restore any manually paused service, and never delete a voice-profile volume as recovery.

State that a model snapshot, cache, acceptance JSON, and HF token are node-private and must never enter Git; backup retention is an operator policy to review separately.  State that `pdf2html` integration is a later separate change after this real-image gate, with PP-OCRv5 retaining literal transcript/table-number responsibility.

- [ ] **Step 2: Run all repository checks that this feature owns.**

Run:

```bash
python3 -m unittest -v packs/vlm-manual-vision/service/tests/test_app.py packs/vlm-manual-vision/service/tests/test_provision.py packs/vlm-manual-vision/service/tests/test_acceptance.py
php tests/test_manual_vision_pack.php
php tests/test_cluster_router.php
php tests/test_command_queue.php
php tests/test_public_api_docs.php
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane
git diff --check
```

Run PHP lint on every changed PHP file.  If the known unrelated control-plane executable-bit failures remain, report their exact names separately; do not mark them fixed or conceal them.

- [ ] **Step 3: Commit docs and perform the deployment-only acceptance.**

```bash
git add README.md docs/api_examples.md docs/cluster-router.md docs/operations/manual-vision-docvqa.md packs/vlm-manual-vision/README.md tests/test_manual_vision_pack.php tests/test_public_api_docs.php
git commit -m "docs: publish manual vision docvqa runbook"
```

After merged deployment, an authorized operator runs the documented provision/start/acceptance commands on one chosen node.  Capture the redacted record in the deployment ticket, not Git.  Do not advertise `manual_vision` in Cluster or connect `pdf2html` until the record has all three exact answers and at least 512 MiB remaining VRAM.  If it fails, leave the existing PaliGemma2 and voice services exactly as they were and diagnose from the command log; no rollback destroys model or profile data.

## Final implementation checklist

- [ ] Native and Cluster requests accept only `operation=docvqa`, `image`, and English `question`.
- [ ] The model sees exactly `answer en {question}`; callers cannot supply an alternate prompt or generation controls.
- [ ] Existing PaliGemma2, OCR, voice/Profile, clone, and `pdf2html` behavior has no source diff.
- [ ] Service never downloads on request, uses CUDA-only float16, and serializes inference.
- [ ] Snapshot and acceptance records are private, integrity-checked, and success requires the three fixed answers plus `>=512 MiB` free VRAM.
- [ ] Node/WSL and Cluster docs/tests are updated, while Router has no special branch unless a shared test proves one is necessary.
