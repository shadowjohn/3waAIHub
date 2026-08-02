# GPT-SoVITS Pack Phase A Design

Date: 2026-08-02

Status: Approved architecture, awaiting written-spec review

## Goal

Add a separately installable GPT-SoVITS Pack so a 3waAIHub station can make a
fair, real-inference comparison with VoxCPM2 on the same managed voice profile
and target text. The initial target is the 3wa RTX 5060 Ti 16 GB station.

The Pack provides governed voice cloning, artifact relay, Cluster routing, and
the existing Hub task lifecycle. It is not a proxy to MyAI and it does not
expose the upstream GPT-SoVITS HTTP API.

## Fixed product decisions

| Item | Phase A decision |
| --- | --- |
| Pack ID | `tts-gpt-sovits` |
| Public async mode | `voice_generate_gpt_sovits` |
| Service key | `gpt-sovits-main` |
| Local port | `18109` via `GPT_SOVITS_LOCAL_PORT` |
| Public clone modes | `clone`, `ultimate_clone` |
| Default execution | `isolated` |
| Performance option | Administrator selects `resident`, then restarts the service |
| Default idle unload | `0`, retain a resident model until explicit stop/restart |
| Phase B | `preset_voice`; no artificial `design` mode |

`voice_generate` remains the VoxCPM2 public contract. The new explicit mode
prevents an API client from accidentally getting a different voice engine or
behaviour merely because an administrator changes an installed Pack.

## Public API and profile contract

The Pack is asynchronous only. It uses the existing task, artifact download,
Cluster relay, API-key authorization, usage logging, and token-mode permission
paths. `voice_generate_gpt_sovits` accepts:

- `text` (required);
- `mode` with exactly `clone` or `ultimate_clone`;
- the existing Hub-managed `voice_profile_id` or profile-task reference; and
- the existing optional language/normal request metadata where the current
  audio gateway already accepts it.

It must not accept raw host paths, container paths, arbitrary service URLs,
upstream model paths, upstream reference-audio paths, or a client execution
mode override.

`clone` uses an authorized managed reference profile. `ultimate_clone` also
requires that profile's existing confirmed transcript. The profile ownership,
consent, transcript-confirmation, retention, audit, and artifact-relay rules
remain the canonical Hub implementation; the new Pack creates no second voice
profile table.

The existing asynchronous task path derives that governed voice-context
snapshot from each Pack's declared contract before queueing work. It needs one
small additional declared shape for a clone-only Pack: mode, clone,
ultimate-clone, profile, profile-task, and container path, with no design
value or design prompt. GPT-SoVITS therefore reuses the current voice-profile
task and Pack-job validation rather than adding a synchronous gateway helper
or a second profile mechanism. This is a declared contract, not a generic TTS
provider framework.

The new explicit mode is included in the existing async route resolver, public
and Cluster API documents, live catalog, Router token permissions, and API test
center. A key granted only `voice_generate` is not implicitly granted
`voice_generate_gpt_sovits`.

## Model and build supply

The service implementation is a clean Hub adapter informed by
`/var/www/html/myai/myai_voice/run_server.py`, not a wrapper around that
application. It uses the official GPT-SoVITS source pinned at
`d523079fc05d9a8028d6085bffe4a2757c32abb6` from
https://github.com/RVC-Boss/GPT-SoVITS.

The Docker image may acquire that exact source revision while building. Runtime
must never download source code, model weights, Hugging Face assets, or Python
packages. In particular, the MyAI runtime download fallbacks, global monkey
patches, and in-place profile-audio mutation are not carried into this Pack.

Phase A has one fixed, official GPT-SoVITS baseline. The Model Repository
installs its declared assets under the Pack's mounted model directory before a
service can become healthy:

- the baseline GPT and SoVITS inference weights;
- the required Chinese HuBERT asset; and
- the required Chinese RoBERTa text asset.

The Pack manifest declares these mounts and required files explicitly. A
missing asset leaves the service unready with a precise model-repository
diagnostic; it never reaches the public API then starts a hidden download.
Custom checkpoints, model-family selection, and online model fetch are out of
scope for Phase A.

## Service execution

The Pack follows the existing immutable container-job contract in `isolated`
mode. Its service settings mirror the proven VoxCPM2 controls with Pack-local
names:

| Setting | Values | Default | Meaning |
| --- | --- | --- | --- |
| `GPT_SOVITS_EXECUTION_MODE` | `isolated`, `resident` | `isolated` | One-shot container or persistent service worker. |
| `GPT_SOVITS_IDLE_UNLOAD_SECONDS` | integer >= 0 | `0` | `0` keeps the loaded model until stop/restart. |
| `GPT_SOVITS_RESIDENT_MIN_FREE_VRAM_MB` | integer >= 0 | `1024` | Free VRAM margin needed before a resident inference starts. |
| `GPT_SOVITS_INTERNAL_JOB_TOKEN` | secret | generated | Authenticates Hub-to-service internal work. |

When resident is selected, the Pack uses the existing `service_data_v1`
protocol and standard internal job, status, capacity, and cancel endpoints.
The Hub stages a per-run input/output directory and sends only a generated run
ID plus relative staged names. The service validates the run ID and every
relative path, serializes one inference at a time, reuses its in-process loaded
model, verifies its WAV, and writes the normal output metadata.

No public listener exposes the service executor. Linux uses the loopback
service route; Windows uses the existing WSL Docker control path. Stop and
restart are the explicit, predictable ways to release cached GPU memory.

## Reference normalization and synthesis

The service copies a managed reference WAV into the run's stage directory. It
never changes the original profile artifact or its confirmed transcript.

For each job, the adapter normalizes the staged reference to the supported
three-to-ten-second window. A reference longer than ten seconds is shortened
at a nearby silence boundary, targeting five seconds. The job derives a
boundary-safe staged prompt-text excerpt only for that temporary segment.
References shorter than three seconds fail clearly rather than being repeated
or padded into a misleading prompt. `ultimate_clone` must use the confirmed
transcript; `clone` may proceed without one where the upstream baseline allows
it.

The adapter uses a deterministic, non-streaming complete-WAV path suitable for
the existing artifact contract. It uses the useful MyAI defaults: Traditional
Chinese input is converted inside the staged request when required by the
baseline, `parallel_infer=true`, batch size `1`, and `text_split_method=cut5`.
Streaming, live chunks, arbitrary upstream parameters, and user-selectable
weights are deliberately deferred.

## GPU capacity and routing

The first real model load reserves a conservative `6144 MB` cold-start
requirement on the 16 GB station. This is a routing preflight budget, not a
claim that the final process will always occupy exactly 6 GB. The GPU lease
continues to serialize all GPU Pack work.

After the resident model is ready, dispatch applies only the configured
`1024 MB` free-VRAM safety margin, while accounting for the known resident
service process so it is not mistaken for an unrelated GPU process. If another
workload violates the margin, the task remains in `waiting_gpu` or is routed by
the Cluster to an eligible station. It must not evict VoxCPM2, preempt a
running task, or silently fall back to another voice engine.

The live catalog publishes the new mode only when the Pack is installed,
enabled, healthy, model-ready, and authorized for publication. The Router can
therefore compare eligible GPT-SoVITS stations in the same normal way as every
other mode.

## Benchmark and acceptance

Phase A adds a focused operator acceptance command in the existing benchmark
or test-center convention. It runs VoxCPM2 and GPT-SoVITS separately with the
same consent-qualified managed voice profile and target text. It records no
token, transcript, or source audio in its report.

For each engine it records:

- profile ID only as a non-secret reference;
- queue duration, execution duration, first-audio timing where measurable,
  total wall time, output duration, and real-time factor;
- cold and warm resident runs where resident mode is selected;
- `nvidia-smi` VRAM before dispatch, while busy, and after completion; and
- task ID, resulting artifact validation, relay/download success, and any
  waiting-GPU state.

The report is a downloadable JSON/operator result, not a new generic metrics
table or dashboard in this phase. A real GPU smoke is required before the Pack
is presented as L5-ready.

## Failure behaviour

- Missing models, failed model initialization, invalid profile ownership,
  absent required confirmed transcript, invalid staged audio, and invalid
  service artifacts fail through current task diagnostics and never publish a
  partial WAV.
- A service timeout, cancellation, or stale task fence cannot delete or
  publish another run's staged output.
- A resident endpoint rejects unauthenticated calls, traversal, unexpected
  files, and unknown run IDs.
- An unhealthy resident service does not cause a hidden one-shot fallback.
- A stopped Pack disappears from live Cluster availability rather than
  returning an engine-selection surprise to clients.

## Acceptance criteria

1. Pack manifest validation confirms a fixed `voice_generate_gpt_sovits`
   route, `clone`/`ultimate_clone` only, local port `18109`, model mounts, and
   isolated/resident settings.
2. Gateway and token tests prove profile authorization is required, raw paths
   are rejected, `ultimate_clone` needs a confirmed transcript, and existing
   VoxCPM2 `voice_generate` remains unchanged.
3. The adapter tests prove staged-only reference normalization, no mutation of
   profile artifacts, secret endpoint authentication, relative-path checks,
   and both isolated and resident artifact contracts.
4. A real 5060 Ti smoke produces a valid downloadable WAV through
   `voice_generate_gpt_sovits`, including Cluster task relay when routed to a
   child node.
5. The operator benchmark runs both engines with one consent-qualified voice
   profile and captures cold/warm, VRAM, timing, artifact, and relay results.
6. Linux local routing and Windows-to-WSL Docker selection are covered without
   adding a native Windows Docker special case.

## Deferred Phase B

`preset_voice` may later add named, consent-governed preset reference voices.
That is the product-appropriate replacement for a prompt-only `design` mode.
It will require its own preset ownership, disclosure, quota, and publication
rules. Phase B may also consider curated model variants, streaming, and richer
benchmark presentation only after Phase A is measured on real hardware.
