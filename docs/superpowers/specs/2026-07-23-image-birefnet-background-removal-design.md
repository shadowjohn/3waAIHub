# BiRefNet Background Removal Pack Design

## Goal

Add a local `image-birefnet` HubPack that removes image backgrounds with one strong, commercially usable model and exposes deterministic edge cleanup and background composition controls. The Pack advances through the existing Hub runtime levels and ends with real-inference quality benchmarks.

The Pack is an API and runtime deliverable. A richer public demonstration page may later map its controls into a dedicated before/after editing experience without changing the Pack contract.

## Model Decision

The first release uses the official `ZhengPeng7/BiRefNet` weights and code under the MIT license:

- model: `ZhengPeng7/BiRefNet`
- task: general foreground/background separation
- local model root: `/models/birefnet`
- runtime: PyTorch with CUDA preferred and CPU fallback

The model is provisioned explicitly into Hub-managed storage at a pinned revision. Runtime inference loads only the local snapshot and never downloads code or weights during a request.

BRIA RMBG 2.0 is excluded because its self-hosted weights require a separate agreement for commercial use. BEN2 and multi-model selection are also excluded from the first release. A future Pack version may add another backend only after its weights, license, hardware behavior, and benchmark fixtures are independently accepted.

References:

- <https://huggingface.co/ZhengPeng7/BiRefNet>
- <https://github.com/ZhengPeng7/BiRefNet>
- <https://huggingface.co/briaai/RMBG-2.0>

## Pack Identity

- `id`: `image-birefnet`
- `default_mode`: `background_remove`
- `type`: `api_service`
- `execution_type`: `sync_api`
- endpoint: `POST /remove-background/image`
- request content type: `multipart/form-data`
- success content type: `image/png`
- gateway timeout: 180 seconds
- queue: single request concurrency
- GPU: preferred, not required

The Pack remains stateless. It processes each request in a private temporary workspace, returns the generated PNG, and removes request files before completion. It does not create `photo_assets`, artifact rows, or server-side editing sessions.

## API Contract

### Inputs

| Field | Type | Required | Default | Contract |
| --- | --- | --- | --- | --- |
| `image` | file | yes | - | JPEG, PNG, or WebP foreground source |
| `output` | enum | no | `cutout` | `cutout`, `mask`, or `composite` |
| `feather_px` | number | no | `0` | `0` through `20`, measured at original output resolution |
| `edge_offset_px` | integer | no | `0` | `-20` through `20`; negative erodes and positive dilates |
| `defringe` | boolean | no | `true` | repairs black or white color spill only on partially transparent edge pixels |
| `background` | enum | no | `transparent` | `transparent`, `white`, `color`, or `image` |
| `background_color` | string | conditional | - | `#RRGGBB`, accepted only with `background=color` |
| `background_image` | file | conditional | - | JPEG, PNG, or WebP, required only with `background=image` |

`output=cutout` requires `background=transparent`. `output=mask` rejects all background-specific fields. `output=composite` requires `background=white`, `color`, or `image`. A custom background uses centered cover-crop composition at the source image dimensions; transparent custom-background pixels are flattened onto white.

The source image limit is 50 MB, 8192 pixels on either axis, and 40 million decoded pixels. The custom background uses the same limits. Validation uses decoded MIME and dimensions rather than filename extensions.

### Success Response

A successful request returns PNG bytes with these response headers:

- `Content-Type: image/png`
- `X-3waAIHub-Model: ZhengPeng7/BiRefNet`
- `X-3waAIHub-Device: cuda` or `cpu`
- `X-3waAIHub-Elapsed-Ms: <positive integer>`
- `X-3waAIHub-Width: <original width>`
- `X-3waAIHub-Height: <original height>`

The generic Hub proxy forwards only this exact metadata allowlist plus `Content-Type`; it does not pass arbitrary service response headers to public clients.

`cutout` returns RGBA PNG at the original dimensions. `mask` returns an 8-bit grayscale PNG at the original dimensions. `composite` returns an opaque RGB PNG at the original dimensions.

### Error Response

Failures return JSON through the normal Hub gateway error response:

- `file_required`
- `payload_too_large`
- `unsupported_media_type`
- `invalid_image`
- `invalid_parameter`
- `model_not_present`
- `model_load_failed`
- `inference_failed`
- `inference_timeout`
- `runtime_not_ready`
- `gateway_timeout`

Invalid parameter combinations fail before model inference. Real-inference failures never return a mock or unchanged source image.

## Processing Pipeline

1. Validate request fields, MIME, byte size, dimensions, and decoded pixel count.
2. Decode the source into RGB while preserving its original dimensions and orientation.
3. Resize model input to `1024x1024` and run BiRefNet on CUDA when available. CUDA unavailability, model-load failure, or out-of-memory retries once on CPU after releasing CUDA state.
4. Resize the floating-point alpha prediction back to the original dimensions.
5. Apply `edge_offset_px` with Pillow minimum or maximum filters at output resolution.
6. Apply `feather_px` with Pillow Gaussian blur at output resolution.
7. When enabled, inspect only pixels where `0 < alpha < 1`. An edge pixel is near black when its largest RGB channel is at most `16`, and near white when its smallest RGB channel is at least `239`. Its RGB is replaced from the nearest foreground pixel with alpha at least `0.98` within three pixels; if no such pixel exists, or the edge color is outside those thresholds, the source RGB remains unchanged.
8. Render the selected `cutout`, `mask`, or `composite` output.
9. Encode PNG, return it, and remove the private workspace.

The initial release does not use tiled inference. Large images preserve their original output dimensions but use the fixed `1024x1024` model input. Tiled inference belongs in a later version only if accepted fixtures show that resizing loses required edge detail.

## Runtime Progression

The design record is the L0 decision point; `runtime_level` begins at the existing Pack levels:

1. `L1-contract`: manifest, endpoint, request fields, binary success response, and error contract. Before real inference exists, the endpoint reports `runtime_not_ready` rather than returning placeholder pixels.
2. `L2-deps-import`: container build imports the pinned image, PyTorch, Transformers, Pillow, and NumPy dependencies and passes a dependency smoke.
3. `L3-storage-mount`: Hub-managed BiRefNet model storage, explicit pinned provisioning, checksum metadata, and offline runtime loading.
4. `L4a-model-init-smoke`: health and smoke checks prove the local model can be loaded without performing image inference.
5. `L4b-real-inference`: one real source image produces a valid, non-degenerate alpha PNG; CUDA is preferred and CPU fallback is exercised independently.
6. `L5-benchmark-ready`: real fixtures, quality thresholds, post-processing checks, and gateway acceptance pass from the installed Pack.

Each promotion changes `runtime_level` only after its runnable check passes. `target_level` remains `L5-benchmark-ready` throughout development.

## L5 Acceptance

The Pack owns three redistributable source images with reference masks:

- a person with fine hair edges
- a product containing white foreground against a light background
- an animal with fur edges

For each real fixture, the benchmark requires:

- HTTP 200 and `image/png`
- output dimensions equal source dimensions
- expected alpha or grayscale channel structure
- alpha containing foreground, background, and partial edge values
- `X-3waAIHub-Device` equal to the measured runtime device
- positive elapsed time
- F-score at least `0.80`
- mean absolute alpha error no greater than `0.10`

Small synthetic masks separately verify feathering, erosion, dilation, defringing boundaries, solid-color composition, and centered cover-crop composition. Invalid parameter combinations, malformed images, oversized decoded dimensions, missing models, and model failures retain their exact error codes.

A station smoke on the 3wa host proves GPU-first execution. A separate controlled smoke hides CUDA and proves CPU fallback without changing the API response contract.

## Playground And Future Demo

The current admin Playground only needs enough support to upload one image, select the API parameters, display the returned PNG, and expose response metadata. It does not become a full image editor.

A later dedicated demonstration page may add drag-and-drop, original/result comparison, a checkerboard alpha preview, sliders, background swatches, custom background upload, and repeated single-image requests. Batch processing remains outside this Pack contract and may later orchestrate multiple calls through the existing task system.

## Excluded From The First Release

- BRIA RMBG 2.0 or other selectable model backends
- batch processing
- video matting
- persistent result storage
- public editing sessions
- native full-resolution 8K or tiled model inference claims
- manual brush, trimap, point, box, or text prompts
- a standalone public demonstration page
