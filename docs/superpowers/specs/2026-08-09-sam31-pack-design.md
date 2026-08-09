# SAM 3.1 Pack Design

**Status:** approved architecture; awaiting user review before implementation

## Goal

Upgrade the existing `sam3` Pack in place to Meta's official SAM 3.1 runtime while preserving the current synchronous image API. Add bounded asynchronous image and video work, plus an administrator-controlled resident stream monitor. All public SAM operations stay under one API mode:

```text
POST api.php?mode=sam3
```

The implementation uses the official `facebookresearch/sam3` code pinned to commit `96914d2425f90a64f45ca977c2b5165418099543` and gated `facebook/sam3.1` checkpoints. It does not use the older Ultralytics adapter or the unsupported Transformers path for SAM 3.1.

## Non-goals

- Do not retain a second `sam31` Pack or a second top-level public mode.
- Do not accept arbitrary stream URLs or credentials from public API requests.
- Do not support unbounded video processing or an unlimited number of concurrently monitored streams.
- Do not claim real-time throughput until it has been measured on each target GPU.

## Public API

`operation` follows the existing Hub API convention. Omitting it remains backward compatible with the legacy image call.

| Operation | Input | Response | Purpose |
| --- | --- | --- | --- |
| `image` (default) | Existing image multipart fields | Immediate JSON or PNG | Existing synchronous image segmentation contract. |
| `image_task` | One image plus the same prompt fields | Existing task-submit envelope | Queued image segmentation with retained artifacts. |
| `video_task` | One MP4 upload or one administrator-created `source_id`, `prompts_json`, optional `clip_seconds` | Existing task-submit envelope | Bounded video detection, segmentation, and tracking. |

The direct image operation forwards only the legacy fields to `POST /segment/image`; `operation` is stripped before proxying. Existing fields and their meanings remain unchanged: `image`, `guidance_mask`, `prompt_type`, `points_json`, `boxes_json`, `text`, `text_prompt`, `output_format`, and `real_inference`.

Task operations never trust caller-selected Pack IDs, runner commands, model paths, GPU choices, source paths, or retention controls. They use a fixed `sam3` Pack route and the existing task status, result, cancellation, artifact, acknowledgement, and retention endpoints.

`prompts_json` for video is a bounded array of 1 through 16 prompt objects. Each object has a client-chosen unique `track_key`, `frame_index`, and exactly one supported prompt: short `text`, positive/negative points, or a box. The limit is 16 because SAM 3.1 Object Multiplex batches up to 16 tracked objects. Long natural-language prompts are rejected rather than silently reinterpreted.

## Runtime and Pack

The `sam3` Pack version becomes `0.2.0`. The installed service continues to use `mode=sam3`, so an idempotent refresh upgrades existing `sam3-main` rows and generated Compose files rather than creating a parallel service.

The Docker image moves to a CUDA 12.8-compatible Python 3.12 runtime and installs PyTorch 2.10 CUDA 12.8 wheels plus the pinned official SAM repository. The image contains FFmpeg for local MP4 decoding and overlay rendering. It contains no checkpoint and no Hugging Face token.

One explicit provisioning command downloads the accepted `facebook/sam3.1` snapshot into `${AIHUB_MODELS_DIR}/sam3` on each GPU host. It receives `HF_TOKEN` only from the invoking environment, performs no token logging, and writes a non-secret manifest containing the upstream commit, model repository, and downloaded file hashes. Health reports `model_not_present` or `model_access_required` without echoing a token or host path.

The Pack exposes two immutable asynchronous jobs alongside its existing sync gateway:

| Job | Accelerator | Input source | Required artifacts |
| --- | --- | --- | --- |
| `segment_image` | GPU | managed image upload or permitted source artifact | `sam3_image_report.json`, `sam3_masks.json`, optional `sam3_mask.png` |
| `track_video` | GPU | managed MP4 upload or normalized source clip | `sam3_video_report.json`, `sam3_tracks.jsonl`, optional `sam3_overlay.mp4` |

Both jobs reuse the existing signed Pack-job contract, workspace, GPU lease, cancellation, retry, output registration, download authorization, and retention rules. They run in one-shot containers. This makes batch task execution deterministic and keeps it separate from the synchronous service process.

## Source intake and resident monitoring

MP4 uploads are the default video input. A system administrator may additionally register a named source for a service instance. The public API can only refer to that opaque `source_id`; it can never submit a raw URL.

The initial source registry permits:

- `rtsp://` or `rtsps://` using an approved literal private camera address;
- `https://...m3u8` whose host is in the administrator-managed HLS allowlist.

Source URLs containing a username, password, fragment, or query string are rejected. HLS redirects are revalidated before every fetch. This deliberately excludes signed URLs and authenticated cameras for this cut, because the current Hub has no encrypted credential vault. Add credentialed sources only with an explicit secret-storage design; do not put camera credentials in task input, logs, SQLite settings, or generated Compose files.

The source registry stores only the service association, opaque source ID, display name, protocol, canonical URL, enabled state, bounded clip length, monitor sampling rate, timestamps, and last safe error code. Every create, edit, enable, disable, and monitor action is system-admin-only and audited.

`video_task` starts a CPU FFmpeg capture stage for a source, capped at 60 seconds and 512 MB, producing a managed MP4 input for `track_video`. Capture is a short-lived task step; it is not allowed to reserve the GPU. Direct MP4 uploads use the same 512 MB and 60-second decoded-duration ceiling.

A resident monitor processes an enabled named source as a sequence of bounded clips. It emits one event JSON artifact per detected change and optional overlay snippets, then checks the next clip. It owns the Pack's sole GPU execution slot while active. Image and video Pack jobs wait, and the synchronous `image` operation returns a stable `sam3_monitor_busy` response, rather than overcommitting a 16 GB GPU. An administrator explicitly stops or pauses the monitor to release the slot. Multi-GPU scheduling or automatic preemption is a later capacity feature.

The monitor handles disconnects with bounded exponential backoff, reports `source_unavailable` after its retry budget, and never retries indefinitely inside an HTTP request. It has a liveness timestamp; the scheduler marks a stale monitor failed and releases its GPU ownership before a replacement can start.

## Data flow

```text
image upload ── operation=image ──> SAM 3.1 API ──> immediate JSON/PNG

image upload ── operation=image_task ──> owned Pack task ──> GPU runner ──> artifacts
MP4/source_id ── operation=video_task ──> capture (if source) ──> GPU runner ──> artifacts

admin source ──> resident monitor ──> bounded clips ──> GPU runner ──> events/artifacts
```

All GPU work is serialized through the current lease mechanism. The monitor's durable run identity is also checked during restart recovery so an orphan cannot leave the GPU blocked or permit a duplicate monitor.

## Error contract

The gateway returns the existing token, method, and task errors unchanged. SAM-specific errors are stable machine codes:

- `invalid_operation`, `invalid_prompts`, `source_not_found`, `source_not_allowed`, `source_unavailable`;
- `video_too_large`, `video_too_long`, `unsupported_video`, `capture_failed`;
- `sam3_monitor_busy`, `monitor_already_running`, `monitor_not_running`;
- existing `model_not_present`, `model_access_required`, `model_load_failed`, `gpu_unavailable`, `inference_failed`, and `inference_timeout`.

Errors, task results, audit records, health payloads, and artifacts contain IDs, counts, timings, checksums, and safe codes only. They do not expose source URLs, credential material, local paths, model-token values, raw video frames, or untrusted FFmpeg stderr.

## Deployment and operations

Each GPU host upgrades with:

1. `git pull`;
2. `php scripts/init_db.php` to create or backfill source and monitor state;
3. accept the gated model terms and run the one-time `HF_TOKEN` provisioning command;
4. Rebuild then Restart the `sam3` service, which refreshes its generated runtime files and Pack version;
5. enable real inference and run the image and short-video acceptance checks.

The deployment guide includes rollback: stop monitors, disable the service, restore the previous Git revision, rebuild, restart, and keep existing task artifacts subject to their normal retention policy. Queued `0.1.0` SAM tasks are not reinterpreted as `0.2.0`; none exist today, but the upgraded route must report `pack_version_unavailable` rather than run a mismatched job.

## Verification

PHP tests prove that:

- missing `operation` remains the legacy synchronous image request;
- only the three declared public operations are accepted and all retain `sam3` token authorization;
- image and video tasks snapshot immutable Pack/job contracts and reject caller-controlled execution fields;
- source registration is admin-only, canonical, bounded, rechecked at execution, and never serializes URLs with user info;
- monitor ownership prevents duplicate starts, stale recovery releases the exact GPU fence, and image/task requests receive the specified busy or waiting state;
- public task/result/artifact views expose no source URL, secret, host path, or internal runner value.

Python tests prove the official adapter mapping with fake model objects, image output compatibility, video prompt bounds, FFmpeg invocation without shell interpolation, source capture limits, cancellation, monitor reconnect/backoff, and redaction. They run without model weights.

GPU acceptance on each target host proves a real SAM 3.1 image request, a real MP4 video task with up to 16 prompts, one approved RTSP or HLS bounded capture, monitor start/stop/recovery, and artifact downloads. The recorded benchmark reports GPU, model manifest hash, elapsed time, frame count, object count, and output checksums; it does not publish source URLs or video content.

## References

- Meta, [SAM 3.1 release](https://ai.meta.com/blog/segment-anything-model-3/): Object Multiplex and video tracking improvements.
- Meta, [official SAM 3 repository](https://github.com/facebookresearch/sam3): current code and installation requirements.
- Meta, [SAM 3.1 model card](https://huggingface.co/facebook/sam3.1): gated checkpoints and official-code-only integration.
