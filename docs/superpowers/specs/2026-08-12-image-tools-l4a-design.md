# Image Tools L4a Model Initialization Design

## Goal

Promote `image-tools` from verified offline assets (L3) to L4a by proving
that every currently supported Real-ESRGAN model family can construct and load
its pinned local weights. The Pack remains a general, extensible image-tools
surface; image upscaling is its first implemented operation.

## Scope

- Keep the public identity `pack_id=image-tools`, its `image-tools` mode,
  endpoint, request fields, operations, aliases, limits, and error codes.
- Rename the display name to `影像工具` and replace the English,
  model-specific description with Chinese text that describes a local,
  extensible image-processing Pack. Document Real-ESRGAN only as the current
  implementation of `upscale`.
- Set `runtime_level` to `L4a-model-init-smoke` and
  `target_level` to `L5-benchmark-ready` after the L4a checks are implemented
  and accepted.
- Add a model-load smoke that first verifies the existing immutable offline
  marker and then builds and loads the local Real-ESRGAN model families on an
  explicitly selected backend. Its controlled L4a command uses `cpu` so it is
  runnable without a GPU.
- Load each distinct architecture/weight pair once: x4plus, x4plus-anime, and
  anime-video. Report every public alias covered by the loaded family; the
  anime-video x2/x3/x4 aliases share one model file and architecture.
- Move the actual Real-ESRGAN model construction/loading code into one shared
  helper. The production upscale runner and the L4a smoke must use that helper
  so a successful smoke is evidence for the same loader used by requests.
- Update deterministic tests, the Pack contract, README, and the operations
  runbook. The runbook must contain an exact redacted host command and state
  what evidence is safe to record.

## Explicit Exclusions

- No source image decode, `enhance()` call, PNG output, HTTP inference, GPU
  benchmark, async task execution, or quality threshold belongs to L4a.
- No new public operation or model: no colorization, background removal,
  denoising, batch endpoint, video workflow, editor, or persisted image
  gallery.
- No runtime model downloads, writable model mount, fallback from an explicit
  failed backend, or relaxed marker/hash validation.
- L4b will separately prove actual CPU and CUDA HTTP upscaling. L5 will
  separately define an API benchmark and quality fixtures/thresholds. Neither
  level may be claimed by this change.

## Runtime Design

The shared loader accepts only a validated public alias, backend (`cpu` or
`cuda`), and verified model root. It resolves the canonical model family,
constructs the exact `RRDBNet` or `SRVGGNetCompact` architecture, and creates
`RealESRGANer` with the local pinned weight path. Loader exceptions become the
existing `model_load_failed` code; marker failures retain their existing
`model_not_present` or `model_load_failed` code.

`model_smoke.py` accepts `--backend cpu|cuda`, defaults to `cpu`, verifies the
marker before loading, and emits one compact JSON object. It contains only:
the selected backend, pinned source commit, and loaded model-family IDs with
their covered public aliases. It never prints model paths, source images,
request data, token values, or weight metadata beyond the already-pinned
commit. It exits nonzero when any family cannot load.

The application health response reports L4a only when the normal marker
verification succeeds. It does not run the expensive model-load smoke during
health polling; L4a evidence is the explicit operator smoke, not a side effect
of every health request.

## Test And Acceptance Design

Deterministic Python tests will use dependency injection or mocks around the
shared loader to prove that each unique family is constructed exactly once,
that all public aliases are covered, that invalid backends are rejected, and
that no inference/image decode occurs. Existing runner tests will assert that
the production path calls the same shared loader. PHP contract tests will pin
the Chinese Pack identity, L4a/L5 level declarations, and the documented
smoke command without changing the public API contract.

The host acceptance sequence is:

1. Build the existing controlled image.
2. Mount `/DATA/models/image-tools/realesrgan` read-only.
3. Run `python3 /app/model_smoke.py --backend cpu` as the runtime user.
4. Record only the exit result, backend, commit, loaded family IDs, aliases,
   elapsed time, image tag, and date in the acceptance record.

Any failure leaves the Pack at L3 operationally; it must be repaired before
the L4a runtime level is published. The L4a command is intentionally CPU-only
so that CUDA validation remains a distinct L4b gate.
