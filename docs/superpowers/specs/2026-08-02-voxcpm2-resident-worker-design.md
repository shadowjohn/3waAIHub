# VoxCPM2 Resident Worker Design

Date: 2026-08-02

Status: Approved design, awaiting written-spec review

## Goal

Make the installed VoxCPM2 service reuse its already-loaded CUDA model for
async `voice_generate` work. This removes the per-job container/model startup
cost while preserving the existing Hub task queue, GPU lease, artifact, and
Cluster contracts.

The default is deliberately memory-first: a resident model remains loaded
until an administrator stops or restarts the service. A 16 GB station therefore
gets fast repeated TTS requests instead of paying model warmup for every job.

## Scope and non-goals

This is a VoxCPM2-only first phase. It covers `voice_generate` jobs on Linux
and the existing Windows-to-WSL Docker runtime path.

It does not:

- make a generic resident-worker framework for every Pack;
- make Whisper resident or move Whisper between stations;
- add concurrent VoxCPM2 inference on one service;
- evict a loaded VoxCPM2 model to make room for another workload;
- change the public synchronous TTS, async API, Cluster API, task status, or
  artifact-download contracts;
- enable `torch.compile`; it remains off by default.

## Configuration and administrator experience

The VoxCPM2 Pack declares two execution choices through the existing
`service_settings` schema:

| Setting | Values | Default | Effect |
| --- | --- | --- | --- |
| `VOXCPM2_EXECUTION_MODE` | `isolated`, `resident` | `isolated` | Chooses one-shot container jobs or the resident service executor. |
| `VOXCPM2_IDLE_UNLOAD_SECONDS` | integer, `0` or greater | `0` | `0` retains the model indefinitely; a positive value enables opt-in idle unloading. |
| `VOXCPM2_RESIDENT_MIN_FREE_VRAM_MB` | integer, `0` or greater | `1024` | Free-VRAM margin required before a resident inference starts. |

Marketplace installation exposes the execution-mode choice only for Packs
that declare it. The default remains `isolated` so existing installations do
not unexpectedly retain VRAM. Selecting `resident` before install writes the
same service setting used by the normal settings page.

Changing either setting regenerates the existing service `.env`, marks the
service `restart_required`, and uses the current restart command. Stop and
restart are the normal explicit ways to release the resident model and its
CUDA allocations. The UI must say that a restart applies the change; it must
not imply an automatic live switch.

The prior manifest default of `900` idle seconds changes to `0`. An
administrator can opt into timed unloading at any time without a new deploy.

## Execution flow

`tasks` and `runtime_runs` remain the only task and attempt records. The
existing task worker remains the only dispatcher and the existing `gpu:0`
lease remains the only GPU serialization lock.

```text
client / Cluster router
  -> existing voice_generate task and mode authorization
  -> existing Pack-job worker and GPU lease
  -> stage Hub-owned inputs in the VoxCPM2 service-data mount
  -> resident service internal job endpoint
  -> same Uvicorn process reuses app._MODEL
  -> write WAV and metadata into the staged output directory
  -> existing artifact validation, publication, callback, and relay
```

The runner selects this path only when the resolved installed service is
VoxCPM2 with `VOXCPM2_EXECUTION_MODE=resident`. It otherwise retains the
current immutable manifest one-shot-container path. A task's public payload
never accepts container paths, service URLs, entrypoints, or execution-mode
overrides.

The resident endpoint is internal, authenticated with a per-service secret,
and unavailable from the public API. It accepts only a Hub-generated run ID
and relative staged input/output names. It rejects traversal, missing input,
unexpected files, and a run ID that does not match the allowed format. The
service data mount is the bounded handoff area; client content never supplies
host paths.

`job.py` moves its shared request validation, synthesis, WAV verification, and
metadata construction into a callable used by both the existing command-line
job and the resident endpoint. This prevents their artifact behavior from
drifting while keeping the external command compatible.

## GPU behaviour

One VoxCPM2 task runs at a time under the existing lease. A resident process
is registered as the active service process for GPU preflight, so its known
VRAM use is not classified as an unrelated `unmanaged_gpu_process`.

Resident dispatch checks that the station still has the configured small free
VRAM safety margin before inference. It must wait through the existing
`waiting_gpu` retry path when another process consumes that margin. The margin
is the `VOXCPM2_RESIDENT_MIN_FREE_VRAM_MB` service setting, with a conservative
`1024 MB` default. It remains a calibration value rather than an assumed
hardware constant; the real acceptance smoke records whether this station
needs a higher value.

The worker does not require the full one-shot `required_vram_mb` after the
resident model has already occupied its steady-state VRAM. It still takes the
lease before asking the service to run, so the model never performs two tasks
at once.

When idle unloading is enabled, the service starts a timer only after the
current request is complete. It clears the cached model and CUDA cache under
the service's request lock. A new request cancels or supersedes the timer and
loads the model once. With the default `0`, no timer is created.

If another Pack needs more VRAM, it waits or is routed to another station.
VoxCPM2 is never silently unloaded by that Pack. This keeps the administrator's
resident-mode choice predictable on the 16 GB 3wa station.

## Failures and recovery

- An unavailable, unhealthy, or misconfigured resident service fails through
  the existing Pack-job error and retry contract; no fallback starts an
  untracked container.
- A failed internal response, invalid output, or stale task fence prevents
  artifact publication and retains the existing diagnostic records.
- Stopping or restarting the service while a job is active follows the
  existing runtime failure/cancellation recovery instead of reporting success
  for a partial WAV.
- Linux continues to call the local service route. Windows uses the existing
  declared WSL runner/runtime command path and must not use a native Windows
  Docker shortcut.

## Acceptance

1. A fresh default install runs `isolated` mode and retains the existing
   one-shot behavior.
2. Selecting resident mode, then restarting, makes two real GPU
   `voice_generate` tasks succeed. The second task reuses the loaded model and
   is observably free of the one-shot model bootstrap.
3. The generated WAV and metadata pass the present artifact checks and remain
   downloadable through the normal task and Cluster relay paths.
4. `VOXCPM2_IDLE_UNLOAD_SECONDS=0` leaves VRAM allocated after an idle task;
   a service stop or restart releases it.
5. A positive idle value unloads only after a completed idle interval and a
   following request loads the model safely once.
6. A competing process that violates the resident safety margin makes the task
   enter `waiting_gpu`, not run concurrently or evict VoxCPM2.
7. Endpoint authentication, staged-path validation, Linux control-path tests,
   and Windows WSL selection tests cover the new branch.
