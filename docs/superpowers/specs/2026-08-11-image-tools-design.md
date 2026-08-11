# image-tools Pack Design

## Goal

Add a broad image-processing Pack without renaming or destabilizing the existing
`image-birefnet` background-removal contract. The first operation is still
small and concrete: single-image Real-ESRGAN upscaling/restoration with an
explicit CUDA/CPU backend switch. The existing portable NCNN/Vulkan assets
under `/opt/ai/photo_clear/p2` remain useful reference assets, but the public
CUDA/CPU contract uses the official PyTorch model format so both backends share
one model family.

The Pack is an operation registry so later image operations can be added behind
the same Hub mode. `colorize`, old-photo restoration, DPED enhancement, and
video processing are explicitly future work; they are not placeholder routes in
the first release.

## Boundary and compatibility

- Pack id: `image-tools`.
- Public mode: `image-tools`.
- Existing `mode=background_remove` and the `image-birefnet` Pack remain
  unchanged.
- The Pack is a Docker service with `execution_type=sync_api` and a declared
  `async_jobs` entry. Sync and async use the same image validation, model
  allowlist, and output contract.
- The service and the async runner use one Pack image so dependency and asset
  behavior cannot drift.

## Public API

### Synchronous operation

```text
POST /api.php?mode=image-tools&operation=upscale
Content-Type: multipart/form-data
```

### Asynchronous operation

```text
POST /api.php?mode=image-tools&operation=upscale_task
Content-Type: multipart/form-data
```

The Gateway normalizes the operation from the query string and form input. If
both are present and disagree, it returns `invalid_operation`; the service is
never allowed to choose a different operation from the Gateway route.

Both forms accept exactly one source:

- `image`: an uploaded file; or
- `base64_string`: a strict Base64 value, optionally prefixed with a
  `data:image/...;base64,` header.

Both forms also accept `backend`, optional, default `auto`, with the enum
`auto`, `cuda`, or `cpu`. An installation setting
`IMAGE_TOOLS_DEFAULT_BACKEND` supplies the default when the request omits the
field. An explicit backend never silently falls back: an unavailable CUDA
request returns `backend_unavailable`.

The Gateway stages a Base64 source for an async task and removes the raw value
from the persisted task input. No URL, host path, container path, or arbitrary
file path is accepted.

The `upscale` fields are:

- `model`, optional, default `realesrgan-x4plus`;
- `backend`, optional, default `auto`, enum `auto|cuda|cpu`;
- `image` or `base64_string`, exactly one.

Allowed models are fixed to the staged assets:

- `realesrgan-x4plus`;
- `realesrgan-x4plus-anime`;
- `realesr-animevideov3-x2`;
- `realesr-animevideov3-x3`;
- `realesr-animevideov3-x4`.

These are stable Hub aliases. The first two map to the corresponding official
Real-ESRGAN `.pth` models; the three `realesr-animevideov3-x*` aliases share
one official anime-video `.pth` model and select output scale 2, 3, or 4.

Success returns `image/png`. Sync returns the PNG body directly with:

- `X-3waAIHub-Model`;
- `X-3waAIHub-Backend: cuda` or `cpu`;
- `X-3waAIHub-Elapsed-Ms`;
- `X-3waAIHub-Width`;
- `X-3waAIHub-Height`.

Async submission returns the existing Hub task response with `task_id`, status,
result, log, and artifact URLs. The worker publishes `upscaled_image.png` and
`upscale_report.json`; the final image is downloaded through the existing
artifact API.

## Input validation and limits

The accepted decoded image formats are JPEG/JPG, PNG, WEBP, and BMP. The
extension and client MIME header are advisory only. Pillow must inspect the
bytes, run format verification, fully decode the image, and enforce dimensions
and decoded-pixel limits.

The first contract uses these ceilings:

- decoded source bytes: 50 MiB;
- source axis: 8,192 pixels;
- async source pixels: 10,000,000;
- sync source pixels: 4,000,000;
- generated output pixels: 64,000,000;
- Gateway request body: 70 MiB to cover Base64 overhead.

The model scale determines the maximum source size against the output ceiling;
an x4 model therefore accepts no more than the equivalent of four million
source pixels. This prevents a valid request from expanding into an
unbounded output allocation.

Errors are explicit and stable:

`file_required`, `source_ambiguous`, `invalid_base64`,
`unsupported_media_type`, `invalid_image`, `invalid_operation`,
`invalid_model`, `invalid_backend`, `backend_unavailable`,
`model_not_present`, `model_load_failed`, `payload_too_large`,
`runtime_not_ready`, and `inference_failed`.

The service rejects GIF, TIFF, SVG, PDF, HEIC, arbitrary text, truncated image
data, decompression-bomb dimensions, and mismatched source fields.

## Runtime architecture

The synchronous service exposes `/health` and `/process/image`. Its operation
router owns only validation and dispatch; the `upscale` adapter writes a
validated source into a private `/tmp` job directory, invokes the pinned
Real-ESRGAN PyTorch runner through an argument array, selects CUDA or CPU
explicitly, reads the PNG result, and removes the directory in a `finally`
path. CUDA uses the normal half-precision path; CPU forces fp32 because some
half-precision operators are not implemented for CPU. Shell interpolation is
not used.

The official `.pth` weights and their manifest are staged as a checksummed,
read-only model snapshot under `/DATA/models/image-tools/realesrgan`. Runtime
network downloads are disabled. The old NCNN/Vulkan binary and `.bin/.param`
files are retained outside the public backend enum for future comparison or a
separate Vulkan adapter; they do not silently stand in for CUDA or CPU.

The service and job runner use a single inference slot. Async dispatch selects
the GPU or CPU queue from the resolved backend: CUDA jobs use the existing Hub
GPU lease and container runner, while CPU jobs use the CPU queue. A long
upscale therefore cannot occupy the HTTP request or the general PHP worker
path.

The async operation remains `operation=upscale_task`; internally it resolves to
the `upscale_image` job with a backend-specific runner contract. It accepts an
image source artifact or one staged upload, the allowlisted model and backend,
and no client-controlled execution command. Its output contract validates PNG
MIME, dimensions, byte size, backend metadata, and the required report fields
before publishing artifacts.

## Documentation and test-machine acceptance

The first release updates all of these together:

- `packs/image-tools/pack.json` and `packs/catalog.json`;
- generated public API inventory/examples and the admin playground entry;
- the top-level README API examples;
- `docs/operations/image-tools.md`, including offline asset staging, Docker /
  CUDA/CPU preflight, sync CUDA/CPU smoke, async GPU/CPU submit/poll/download,
  and cleanup;
- PHP contract tests for registry, install, Gateway operation routing, docs,
  and artifact behavior;
- Python unit tests for Base64, format detection, dimension limits, model
  allowlisting, subprocess arguments, and temporary-directory cleanup;
- a real test-machine smoke that invokes the HTTP sync endpoint on CUDA and CPU,
  submits async CUDA and CPU tasks, verifies output PNG dimensions and
  metadata, polls the tasks, downloads the artifacts, and verifies their
  SHA-256.

The real acceptance requires Docker, the staged checksums, a working CUDA host
for GPU cases, a usable CPU runtime for CPU cases, and the declared test
fixture. It is separate from ordinary offline unit CI; CI still covers all
contract and safety checks without shipping model binaries.

## Deferred work

- `colorize` / DeOldify replacement or another colorization model;
- DPED phone-to-DSLR enhancement;
- video frame extraction and reassembly;
- YOLO-guided local enhancement from the old `p1` prototype;
- resident model services or batching.

Each deferred operation must bring its own model provenance, output contract,
fixture, and acceptance gate before being added to the operation enum.

## Upstream references

- [Real-ESRGAN image inference](https://github.com/xinntao/Real-ESRGAN/blob/master/inference_realesrgan.py)
- [Real-ESRGAN CPU FAQ](https://github.com/xinntao/Real-ESRGAN/blob/master/docs/FAQ.md)
