# Image Tools L4b and L5 Design

## Goal

Promote `image-tools` from L4a model-initialization readiness to L5 by
recording real HTTP upscaling on both CPU and CUDA and by making the pinned
Real-ESRGAN output a deterministic quality gate.

## Proven baseline

The installed `image-tools-main` Compose service was run with the fixed
`packs/image-tools/demo/smoke.png` fixture (2x3) and the public service HTTP
endpoint.  Both explicit backends returned valid 8x12 PNG files and the
existing acceptance client reported these deterministic output digests:

| Backend | SHA-256 |
| --- | --- |
| `cuda` | `a6e3d6e87a8fa8b68a177d85e24f427416b0acb81c9a8469aeea6e4ece38396e` |
| `cpu` | `ebafc1306d63b9bc35ebb7b3f6e337e7919f18791e46d2901fb493eccb8207f7` |

The generated Compose service was verified to carry `gpus: all` for the
enabled GPU service and Docker reported a GPU DeviceRequest.  The host is an
RTX 5060 Ti.  The acceptance evidence records output metadata only; it never
stores source images, tokens, paths, or logs.

## Scope

- L4b proves direct HTTP `upscale` inference for the existing `realesrgan-x4plus`
  model and the fixed fixture on both explicit `cpu` and explicit `cuda`.
- Each response must be HTTP 200, `image/png`, 8x12, contain all five public
  image-tools response headers, report the requested backend, and equal the
  backend's pinned SHA-256 above.
- L5 uses two real-inference benchmark cases, one per backend, to apply those
  checks through the normal Hub gateway and persist a result for each service.
- L5 Readiness requires the most recent run of *every* declared real-inference
  case to be `pass`; a passing CPU case cannot hide a failed/missing CUDA case,
  and vice versa.
- The Pack promotes directly from L4a to `L5-benchmark-ready` only after both
  real benchmark cases pass.  L4b evidence remains separately recorded.
- Documentation and acceptance evidence remain in Chinese and redact all
  inputs, tokens, host paths, model binaries, and full logs.

## Implementation

Extend the existing generic binary benchmark helper instead of introducing a
second image benchmark runtime.  It gains optional exact
`expected_dimensions`, exact `expected_sha256`, and exact
`expected_response_header_values` fields.  Existing Packs that omit those
fields preserve current behavior.

The L5 readiness helper changes only its real-inference aggregation: it reads
the newest saved status for each declared real benchmark ID and requires all
of them to pass.  No queue, storage, model download, public operation, or
request schema changes are needed.

`acceptance.py --direct-sync` gains optional expected CPU/CUDA digest inputs
and rejects a response whose digest differs.  Its existing structural checks
remain the L4b HTTP assertion; the L5 benchmark independently verifies the
same quality contract through the Hub gateway.

The manifest declares exactly two real binary cases using `smoke.png`,
`operation=upscale`, `model=realesrgan-x4plus`, and explicit backend.  Both
require the known 8x12 output, the fixed SHA-256, all public headers, and
fixed model/backend header values.

## Verification and evidence

1. Unit tests make binary benchmark expected dimensions, digests, and header
   values fail closed, while omitted fields retain existing Pack behavior.
2. Unit tests prove L5 readiness fails until both real case IDs have their own
   latest passing run.
3. The installed Compose service is started with GPU enabled.  Run the direct
   acceptance client with both expected digests, then run each gateway L5
   benchmark case.  Repeat the direct acceptance once to confirm the same
   pair of hashes.
4. Append separate L4b and L5 JSON evidence sections with date, image tag,
   backend/case IDs, exit/result status, hashes, dimensions, headers verified,
   and elapsed time only.
5. Promote `runtime_level` to `L5-benchmark-ready`, run focused Python/PHP
   suites and a Docker build, and report broader-suite unrelated failures
   separately.

## Exclusions

- No additional image operation, model, fixture, batch/video workflow,
  quality heuristic, model download, or persistent image storage.
- No latency threshold: CPU/GPU timing is hardware-dependent and is recorded
  as evidence only.
- No public access/cluster permission change until the complete L5 source
  branch has passed verification, merged, and pushed.
