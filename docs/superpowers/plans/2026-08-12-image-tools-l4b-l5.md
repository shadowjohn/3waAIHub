# Image Tools L4b and L5 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prove real CPU/CUDA HTTP upscaling and promote `image-tools` to L5 only when both deterministic golden-output benchmarks pass.

**Architecture:** Reuse the Hub binary benchmark helper for exact dimensions, response-header values, and SHA-256 gates. Declare one real HTTP benchmark per backend in the existing Pack contract, and change readiness to require a latest passing result for every declared real case. The existing Python acceptance client validates the same pair directly against the installed service before the benchmark records are written.

**Tech Stack:** PHP 8/SQLite benchmark registry and gateway, Python 3/Pillow/urllib acceptance client, Docker Compose, Real-ESRGAN, existing unittest and PHP test harness.

---

## File map

| File | Responsibility |
| --- | --- |
| `app/benchmarks.php` | Generic binary golden assertions and per-real-case readiness aggregation. |
| `tests/test_benchmark.php` | Unit coverage for generic binary golden checks and dual-case readiness. |
| `packs/image-tools/pack.json` | L5 input/benchmark declaration and final runtime level. |
| `packs/image-tools/service/acceptance.py` | Direct CPU/CUDA HTTP golden validation. |
| `packs/image-tools/service/test_acceptance.py` | Direct acceptance hash and L5-health tests. |
| `tests/test_image_tools.php` | Exact Pack, evidence, and document assertions. |
| `README.md`, `docs/operations/image-tools.md` | L5 operator status and benchmark command. |
| `docs/operations/image-tools-acceptance.md` | Redacted L4b and L5 evidence only. |

## Task 1: Lock the dual-backend L5 contract with failing tests

**Files:**
- Modify: `tests/test_benchmark.php`
- Modify: `tests/test_image_tools.php`
- Modify: `packs/image-tools/service/test_acceptance.py`

- [ ] **Step 1: Add generic binary golden tests.**

  Extend the existing binary benchmark test to call
  `hub_benchmark_binary_response_result()` with:

  ```php
  'expected_dimensions' => [(int)$size[0], (int)$size[1]],
  'expected_sha256' => hash('sha256', $png),
  'expected_response_header_values' => [
      'X-3waAIHub-Model' => 'ZhengPeng7/BiRefNet@revision',
      'X-3waAIHub-Device' => 'cuda',
  ],
  ```

  Require the returned result to add `output_sha256`. Add one mutation per
  subcase—wrong width, wrong digest, and wrong header value—and assert
  `RuntimeException('benchmark contract check failed.')` for each.

- [ ] **Step 2: Add the image-tools L5 manifest/evidence assertions.**

  Extend `tests/test_image_tools.php` to require:

  - `runtime_level === 'L5-benchmark-ready'` only after final evidence;
  - one `l5_contract.input.fields` set containing `image`, `operation`,
    `model`, and `backend`;
  - two ordered cases `image_tools_cuda_upscale_golden` then
    `image_tools_cpu_upscale_golden`, with `real_inference=true`, fixture
    `packs/image-tools/demo/smoke.png`, exact `[8, 12]` dimensions, the five
    response headers, exact model/backend header values, and the proven
    backend-specific SHA-256 values;
  - isolated L4b and L5 evidence sections containing exactly one JSON block
    each with no source image/inference output declaration.

  The L4b JSON must require the two exact golden digests, `[8,12]`, and
  `headers_verified=true`; the L5 JSON must require the two exact benchmark
  IDs and `status='pass'`.

- [ ] **Step 3: Add direct acceptance golden tests.**

  Change the sync validation test to pass an expected SHA-256. Add a mismatch
  case asserting `AssertionError('unexpected output SHA-256')`; retain the
  existing structural/metadata test. The L5 health expectation is deferred to
  Task 4, because the manifest intentionally remains at L4a through Task 3.

- [ ] **Step 4: Run the focused tests to prove the contract is red.**

  Run:

  ```bash
  php scripts/run_tests.php --suite=full
  python3 -m unittest -v packs/image-tools/service/test_acceptance.py
  ```

  Expected: the direct acceptance test fails because the optional expected
  digest argument is absent; the PHP suite includes image-tools L5 contract
  failures. Record unrelated existing suite failures separately.

- [ ] **Step 5: Commit the red contract.**

  ```bash
  git add tests/test_benchmark.php tests/test_image_tools.php \
    packs/image-tools/service/test_acceptance.py
  git commit -m "test: define image tools L4b L5 quality gate"
  ```

## Task 2: Make binary quality checks and readiness fail closed

**Files:**
- Modify: `app/benchmarks.php`
- Modify: `tests/test_benchmark.php`

- [ ] **Step 1: Add optional exact binary fields.**

  In `hub_benchmark_binary_response_result()`, after PNG parsing, read only
  these optional case fields:

  ```php
  $expectedDimensions = $case['expected_dimensions'] ?? null;
  $expectedDigest = $case['expected_sha256'] ?? null;
  $expectedHeaderValues = $case['expected_response_header_values'] ?? [];
  $digest = hash('sha256', $body);
  ```

  A configured dimensions value must be a two-element positive-integer list;
  a configured digest must be a 64-character lowercase hexadecimal string;
  configured header values must be a string-to-string map. Invalid configured
  values fail the benchmark contract. Exact comparisons use `hash_equals()`
  for the digest and lowercase normalized header names. Omitted optional
  fields leave current Pack behavior unchanged. Add `output_sha256` to the
  result only when `expected_sha256` is configured.

- [ ] **Step 2: Require every real case's latest status to pass.**

  Replace the current single `ORDER BY id DESC LIMIT 1` real-case lookup in
  `hub_pack_l5_readiness()` with a grouped latest-ID query:

  ```sql
  SELECT runs.benchmark_key, runs.status
  FROM benchmark_runs AS runs
  JOIN (
      SELECT benchmark_key, MAX(id) AS id
      FROM benchmark_runs
      WHERE benchmark_key IN (?, ...)
      GROUP BY benchmark_key
  ) AS latest ON latest.id = runs.id
  ```

  Set `real_inference_benchmark_passed` only if the query returns every
  declared real case ID and every returned status equals `pass`.

- [ ] **Step 3: Run generic benchmark tests.**

  Run:

  ```bash
  php scripts/run_tests.php --suite=full
  ```

  Expected: all binary benchmark tests pass; exact full-suite failures are
  reported without claiming the unrelated suite is green.

- [ ] **Step 4: Commit the generic gate.**

  ```bash
  git add app/benchmarks.php tests/test_benchmark.php
  git commit -m "feat: gate binary benchmarks on golden output"
  ```

## Task 3: Declare L5 cases and direct golden validation

**Files:**
- Modify: `packs/image-tools/pack.json`
- Modify: `packs/image-tools/service/acceptance.py`
- Modify: `packs/image-tools/service/test_acceptance.py`
- Modify: `tests/test_image_tools.php`

- [ ] **Step 1: Implement the optional expected digest boundary.**

  Give `validate_sync_response()` and `run_sync()` this optional argument:

  ```python
  expected_sha256: str | None = None
  ```

  After validating the PNG and metadata, calculate its SHA-256. If an expected
  digest is supplied and differs, raise exactly
  `AssertionError('unexpected output SHA-256')`. In `main()`, add
  `--expected-cuda-sha256` and `--expected-cpu-sha256`; each is accepted only
  as 64 lowercase hex characters and is routed to its matching explicit
  backend. The existing no-option usage remains structural-only.

- [ ] **Step 2: Declare the L5 input and cases without promoting yet.**

  Add `l5_contract.input.fields` for the existing multipart `image`,
  `operation`, `model`, and `backend` values. Add exactly these benchmark
  cases under `l5_contract.benchmark`:

  ```json
  {
    "id": "image_tools_cuda_upscale_golden",
    "type": "api",
    "mode": "image-tools",
    "method": "POST",
    "fixture": "packs/image-tools/demo/smoke.png",
    "fixture_field": "image",
    "form": {"operation": "upscale", "model": "realesrgan-x4plus", "backend": "cuda"},
    "real_inference": true,
    "expected_content_type": "image/png",
    "expected_png": true,
    "expected_dimensions": [8, 12],
    "expected_sha256": "a6e3d6e87a8fa8b68a177d85e24f427416b0acb81c9a8469aeea6e4ece38396e",
    "expected_response_headers": ["X-3waAIHub-Model", "X-3waAIHub-Backend", "X-3waAIHub-Elapsed-Ms", "X-3waAIHub-Width", "X-3waAIHub-Height"],
    "expected_response_header_values": {"X-3waAIHub-Model": "realesrgan-x4plus", "X-3waAIHub-Backend": "cuda"}
  }
  ```

  The CPU case is identical except ID
  `image_tools_cpu_upscale_golden`, `backend` `cpu`, and digest
  `ebafc1306d63b9bc35ebb7b3f6e337e7919f18791e46d2901fb493eccb8207f7`.
  Keep runtime level at L4a until Task 4 succeeds.

- [ ] **Step 3: Run focused Python/PHP tests.**

  Run:

  ```bash
  python3 -m unittest -v packs/image-tools/service/test_acceptance.py
  php scripts/run_tests.php --suite=full
  ```

  Expected: focused Python tests pass; image-tools contract tests pass except
  for final runtime/evidence assertions deferred to Task 4.

- [ ] **Step 4: Commit the L5 contract.**

  ```bash
  git add packs/image-tools/pack.json packs/image-tools/service/acceptance.py \
    packs/image-tools/service/test_acceptance.py tests/test_image_tools.php
  git commit -m "feat: add image tools golden benchmarks"
  ```

## Task 4: Run L4b/L5 acceptance and promote

**Files:**
- Modify: `packs/image-tools/pack.json`
- Modify: `packs/image-tools/service/acceptance.py`
- Modify: `packs/image-tools/service/test_acceptance.py`
- Modify: `README.md`
- Modify: `docs/operations/image-tools.md`
- Modify: `docs/operations/image-tools-acceptance.md`
- Modify: `tests/test_image_tools.php`

- [ ] **Step 1: Start the generated test runtime with GPU enabled.**

  Ensure the isolated `image-tools-main` service has
  `IMAGE_TOOLS_USE_GPU=1`, regenerate its Compose file, and verify:

  ```bash
  docker compose -f data/services/image-tools-main/docker-compose.generated.yml config -q
  docker inspect 3waaihub-image-tools-main --format '{{json .HostConfig.DeviceRequests}}'
  ```

  Expected: Compose is valid and the inspect output contains a `gpu`
  DeviceRequest. Start it, wait for `/health`, and do not treat a connection
  before Uvicorn startup as an inference failure.

- [ ] **Step 2: Prove L4b twice with direct HTTP golden acceptance.**

  Run this command twice:

  ```bash
  python3 packs/image-tools/service/acceptance.py \
    --service-url http://127.0.0.1:18113 \
    --fixture packs/image-tools/demo/smoke.png \
    --direct-sync \
    --expected-cuda-sha256 a6e3d6e87a8fa8b68a177d85e24f427416b0acb81c9a8469aeea6e4ece38396e \
    --expected-cpu-sha256 ebafc1306d63b9bc35ebb7b3f6e337e7919f18791e46d2901fb493eccb8207f7
  ```

  Expected: both invocations exit 0 and output exactly one safe JSON object
  with two cases, each 8x12 and with the declared digest. Capture only date,
  image tag, exit status, backend, model, dimensions, digest, header check,
  and elapsed time.

- [ ] **Step 3: Run both real L5 gateway benchmark cases.**

  Run:

  ```bash
  php scripts/benchmark.php --service=image-tools-main --case=image_tools_cuda_upscale_golden
  php scripts/benchmark.php --service=image-tools-main --case=image_tools_cpu_upscale_golden
  ```

  Expected: both commands exit 0 and record individual `pass` runs. Confirm
  `hub_pack_l5_readiness($db, 'image-tools')` returns
  `real_inference_benchmark_passed=true` only after both rows exist.

- [ ] **Step 4: Append redacted L4b/L5 evidence.**

  Add isolated `## L4b` and `## L5` sections. Each starts with
  `no source image/inference output` and contains exactly one JSON block.
  The L4b record has the direct acceptance facts; the L5 record has both
  benchmark IDs, `status: "pass"`, the same two digests, dimensions, image
  tag, date, and elapsed times. Do not write source bytes, Token values,
  Base64, paths, full logs, or model binaries.

- [ ] **Step 5: Promote only after evidence exists.**

  Change `runtime_level` to `L5-benchmark-ready`, make the Python health
  acceptance expect L5 (with a test that rejects L4a), and update README/runbook
  to say L5 means this fixed dual-backend quality gate. Document the two
  benchmark commands. Do not claim batch/video/editor/colorization/background
  removal, a latency SLA, or any model outside the current Real-ESRGAN
  `upscale` operation.

- [ ] **Step 6: Run final source gates.**

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
  php scripts/run_tests.php --suite=full
  git diff --check
  git status --short
  ```

  Expected: all image-tools Python tests and the image build pass. Report the
  full PHP suite exact count/exit code and distinguish unrelated failures.

- [ ] **Step 7: Stop the temporary test runtime and commit.**

  ```bash
  docker compose -f data/services/image-tools-main/docker-compose.generated.yml down
  git add packs/image-tools/pack.json packs/image-tools/service/acceptance.py \
    packs/image-tools/service/test_acceptance.py README.md \
    docs/operations/image-tools.md docs/operations/image-tools-acceptance.md \
    tests/test_image_tools.php
  git commit -m "feat: promote image tools to L5"
  ```

## Final checklist

- [ ] CPU and CUDA direct HTTP runs have each produced the fixed golden hash twice.
- [ ] Both separately persisted real benchmark cases are passing; a missing or
  failed backend makes readiness fail.
- [ ] L5 declaration, health acceptance, docs, and redacted L4b/L5 evidence agree.
- [ ] No public operation, model download, writable model mount, image storage,
  or latency threshold was introduced.
