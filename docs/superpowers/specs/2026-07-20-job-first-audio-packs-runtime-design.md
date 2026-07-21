# Job-first Audio Packs and Managed GPU Task Runtime Design

Date: 2026-07-20

Status: Approved design, awaiting written-spec review

Target: 3waAIHub v0.2.x on a single Linux station with one RTX 5060 Ti 16 GB GPU

## 1. Goal

Move reusable audio processing out of `myai_voice` and into governed 3waAIHub Packs while keeping product concerns in MyAI.

MyAI remains responsible for pages, articles, characters, paragraph or movie arrangement, task submission, callback handling, and use of final artifacts. 3waAIHub owns task admission, routing, execution history, GPU serialization, workspace lifecycle, artifact validation, callback delivery, and retention. Each Pack owns its model dependencies, runner, job contract, checkpoints, and domain-specific output quality.

The first release provides three independent async capabilities:

| Pack | Public async mode | Manifest job | Responsibility |
| --- | --- | --- | --- |
| `audio-cleanup` | `audio_cleanup` | `cleanup` | Demucs separation and optional DeepFilterNet enhancement |
| `whisper-asr` | `speech_transcribe` | `transcribe` | ASR, optional alignment, and optional diarization |
| `tts-voxcpm2` | `voice_generate` | `synthesize` | VoxCPM2 design/clone speech generation and long-form assembly |

`whisper-asr` and `tts-voxcpm2` keep their current Pack identities. Only `audio-cleanup` is new.

## 2. Non-goals

This phase does not implement:

- the later `voice-pipeline` orchestrator;
- multiple concurrent jobs on one GPU or capacity greater than one;
- multi-host or distributed scheduling;
- automatic CPU fallback for model inference;
- GPU-accelerated ffmpeg sharing;
- public client-controlled Pack IDs, entrypoints, commands, paths, or callback URLs;
- word-level forced alignment inside `tts-voxcpm2`;
- multi-character or cross-voice TTS composition;
- streaming or WebSocket inference;
- a generic cross-Pack voice-assembly library;
- automatic schema migration on ordinary web bootstrap;
- `scripts/schema_check.php`, which is deferred to a later phase.

## 3. Architectural Decision

The system uses Job-first atomic Packs rather than three permanently warm GPU services or one combined voice toolbox image.

```text
MyAI
  -> POST async mode with multipart or managed artifact reference
3waAIHub Gateway
  -> resolve installed mode to immutable Pack job route
Shared tasks queue
  -> generic Pack-job worker
runtime run lease
  -> host resource lease gpu:0
one-shot Pack container
  -> validated workspace outputs
atomic terminal transaction
  -> registered artifacts + task state + callback outbox + GPU release
callback worker
  -> signed notification to MyAI
MyAI
  -> authenticated artifact download
```

One-shot containers release model memory between workloads and isolate the three Python/CUDA dependency sets. Existing `asr` and `tts` synchronous endpoints remain available only for readiness, smoke tests, short manual tests, and installation acceptance.

## 4. Public Mode Routing

External clients specify only a public `mode`:

```text
api.php?mode=speech_transcribe
        -> Gateway route
        -> pack_id=whisper-asr
        -> pack_version=<installed snapshot>
        -> job=transcribe
        -> runtime_mode=job
        -> accelerator=gpu
```

Clients must not submit `pack_id`, `entrypoint`, `command`, script paths, model paths, host paths, container paths, or arbitrary environment variables. The generic adapter may execute only the exact job entrypoint declared by the resolved Pack manifest.

Authorization remains mode-based:

```text
Bearer token -> mode permission -> installed Hub route -> Pack job
```

There is no second Pack ACL. Packs are internal deployment units; modes are external capabilities and authorization units.

At admission time, the resolved route is stored immutably on the task:

- `requested_mode`
- `pack_id`
- `pack_version`
- `job`
- `runtime_mode`
- `accelerator`
- `route_resolved_at`

Pack upgrades do not rewrite queued or historical task routes. A retry of the same task must use the captured Pack version. If that version is no longer runnable, the attempt fails with `pack_version_unavailable` instead of silently selecting a newer version.

## 5. One Shared Queue and Generic Adapter

The existing `tasks` table remains the single business queue. This design does not introduce Whisper, VoxCPM2, or cleanup-specific queues or workers.

All three jobs use one path:

```text
task_worker.php
  -> resolve saved route
  -> create or reuse one provisional runtime_runs row
  -> acquire runtime run lease
  -> acquire gpu:0 lease
  -> pass dynamic GPU preflight
  -> consume one runtime attempt
  -> generic Pack-job adapter
  -> bin/aihub-run with manifest-declared job
  -> validate workspace outputs
  -> terminal fencing transaction
```

`runtime_runs` records execution attempts; it is not a second product queue. A worker may create one provisional row with nullable `attempt_no` so the immutable `run_id` can own the runtime and GPU leases. Waiting for GPU does not consume an attempt: the same provisional row is released back to `queued` and reused. Only the fenced transition immediately before container launch assigns the next `attempt_no`, changes the row to `running`, and consumes one runtime attempt.

The generic task type is `pack_job`. Existing specialized task types such as DocParser remain supported but are not copied for each audio Pack.

## 6. Database Migration Contract

Every new table, column, and index must be created through `app/db.php::hub_migrate()`. Migration is idempotent and must preserve all existing data. Running `hub_migrate()` twice must produce the same schema and data.

New DDL must not run from Gateway handlers, workers, or ordinary web requests. Deployment after `git pull` must run:

```bash
php scripts/init_db.php
```

`install.sh` and `init_db.php` are the mutation paths. Environment checks remain read-only. The deployment and API documentation must state this requirement.

### 6.1 Task ownership and route columns

The following nullable columns are added with `hub_add_column_if_missing()` so legacy tasks remain readable:

```text
tasks
- owner_member_id
- owner_token_id
- requested_mode
- pack_id
- pack_version
- job
- runtime_mode
- accelerator
- route_resolved_at
- source_artifact_id
- source_task_id
- retry_of_task_id
- waiting_reason
- next_attempt_at
- source_expires_at
- workspace_expires_at
- source_state
- workspace_state
- retention_state
- purged_at
- freed_bytes
```

New API tasks always save `owner_member_id` and `owner_token_id`. Existing legacy tasks with null ownership are not exposed to arbitrary API members; only the existing trusted localhost/system-admin path may access them.

`retry_of_task_id` is included in this phase. A manual retry always creates a new task and never mutates a terminal task back to queued.

### 6.2 Runtime attempt columns

The following columns link attempts and retain GPU recovery evidence:

```text
runtime_runs
- task_id
- attempt_no (nullable until the first container launch is authorized)
- gpu_process_baseline_json
- owned_gpu_pids_json
```

`runtime_runs.run_id` remains the immutable text identifier used by resource leases. It must never be confused with the integer `runtime_runs.id`.

### 6.3 Artifact columns

The existing `task_artifacts` registry is extended rather than replaced:

```text
task_artifacts
- artifact_type
- sha256
- expires_at
- state
- pinned_at
- legal_hold
- acknowledged_at
- last_accessed_at
- purged_at
- purge_error
```

Artifact ownership is derived through its owning task. A task row cannot be DB-pruned while any of its artifacts is available, pinned, held, or referenced by a nonterminal downstream task.

### 6.4 Callback targets and deliveries

`task_callback_targets` stores admin-registered targets bound to an API member:

```text
task_callback_targets
- id
- owner_member_id
- target_alias
- callback_url
- signing_secret
- enabled
- created_at
- updated_at

UNIQUE(owner_member_id, target_alias)
```

The signing secret is runtime-only sensitive data. It is masked after registration and never appears in Pack manifests, task payloads, logs, callback payloads, or public API responses.

`task_callback_deliveries` is the durable outbox:

```text
task_callback_deliveries
- id
- delivery_id UNIQUE
- callback_target_id
- task_id
- event_type
- payload_json
- attempt_count
- next_attempt_at
- delivered_at
- last_http_status
- last_error
- created_at
- updated_at

UNIQUE(callback_target_id, task_id, event_type)
INDEX(delivered_at, next_attempt_at)
INDEX(task_id)
```

### 6.5 Host resource lease table

`runtime_resource_leases` contains one persistent row for the first GPU:

```text
runtime_resource_leases
- resource_key TEXT PRIMARY KEY
- runtime_run_id TEXT NULL
- worker_id TEXT NULL
- lease_token TEXT NULL
- state TEXT NOT NULL
- acquired_at TEXT NULL
- heartbeat_at TEXT NULL
- lease_expires_at TEXT NULL
- last_error TEXT NULL
- updated_at TEXT NOT NULL

INDEX(state, lease_expires_at)
INDEX(runtime_run_id)
```

Migration initializes:

```text
resource_key=gpu:0
state=available
```

Normal release resets the row to `available`; it never deletes the row.

## 7. Task Ownership and Artifact Lineage

All status, result, log, cancel, retry, ACK, artifact download, and callback-target operations verify API member ownership. Different tokens belonging to the same member may share tasks and artifacts. Different members may not.

`audio_cleanup` and `speech_transcribe` accept exactly one source:

1. multipart upload, stored under the new Hub task's managed upload directory; or
2. `source_artifact_id`, resolved by Hub without exposing a host path.

An artifact reference is accepted only if:

- the artifact's owning task belongs to the caller member;
- artifact state is `available`;
- `purged_at` is null;
- `expires_at` has not passed;
- `artifact_type` is in the target job's manifest allowlist;
- the canonical file remains inside the Hub results root and is a regular file.

The downstream task saves both `source_artifact_id` and `source_task_id`. A nonterminal downstream task is a retention hold on its source artifact. The hold ends only when the downstream task is `success`, `failed`, `cancelled`, or `timed_out`.

`voice_generate` declares `source_required=false` and an empty source type allowlist. It accepts text and either allowlisted design controls or one owned managed voice profile; upload and `source_artifact_id` are rejected rather than silently ignored.

The first release supports one source artifact per task. A many-input dependency table is deferred until a real multi-input Pack requires it.

## 8. Task and Runtime States

Task states:

```text
queued
waiting_gpu
running
success
failed
cancelled
timed_out
```

Runtime attempt states:

```text
queued
claimed
running
succeeded
failed
cancelled
timed_out
```

Resource states:

```text
available
leased
recovery_required
blocked
```

Retention states are separate from task execution status:

```text
active
expiring
purging
purged
purge_failed
```

An existing task remains `success` after its files are purged. API responses expose file availability separately.

## 9. GPU Lease and Fencing

The first station has one host resource:

```text
gpu:0 capacity=1
```

All heavy CUDA jobs serialize through this row. CPU-only ffmpeg decode, filter, and encode do not acquire it. Any ffmpeg command using NVDEC, NVENC, or CUDA is not CPU-only and is outside this phase.

### 9.1 Acquisition order

```text
claim task
-> validate static input, Pack, model, disk, and route requirements
-> create or reuse and claim a provisional runtime run
-> acquire gpu:0 in a short BEGIN IMMEDIATE transaction
-> commit
-> inspect current VRAM and GPU processes
-> if preflight passes, atomically assign attempt_no and mark the run running
-> start one-shot container
-> load model
-> execute job
```

Docker, `nvidia-smi`, ffprobe, hashing, and file operations never execute while holding a SQLite write transaction.

The container may start only when all conditions hold:

- runtime run lease is valid;
- GPU resource lease is valid;
- run ID, worker ID, and lease token match exactly;
- resource state is `leased`;
- Pack GPU preflight passes;
- free VRAM is at least Pack `min_vram_mb` plus the configured safety margin.

The initial safety margin is `AIHUB_GPU_VRAM_SAFETY_MARGIN_MB=256`. It remains configurable because real GPU availability and model peaks require station calibration.

Initial Pack thresholds are:

| Pack | Initial `min_vram_mb` |
| --- | ---: |
| `audio-cleanup` | 6000 |
| `whisper-asr` | 10000 |
| `tts-voxcpm2` | 16000 |

Real 5060 Ti benchmarks may tune these manifest/settings values without changing the API contract.

If dynamic preflight finds insufficient VRAM or unmanaged GPU pressure, the run does not start a container. In one short fenced transaction, the task returns to `waiting_gpu`, sets `waiting_reason=insufficient_vram` or `unmanaged_gpu_process`, sets `next_attempt_at` with backoff, releases `gpu:0` to `available`, releases the runtime lease, and returns the same provisional run row to `queued`. Its `attempt_no` remains null, so this does not count as a runtime attempt or create a fresh run row on every retry.

If preflight passes, a short fenced transaction assigns `attempt_no=max(previous consumed attempt)+1` and changes the provisional row from `claimed` to `running`. This is the point at which one runtime attempt is consumed. Container launch failure after this transition is therefore an infrastructure failure within that attempt.

### 9.2 Fencing token

The runtime run lease token is copied into the GPU lease and fences both resources. Heartbeat updates both rows in one short `BEGIN IMMEDIATE` transaction. Either both updates match exactly one row or the transaction rolls back.

Every heartbeat, release, block, recovery transition, cancel completion, timeout completion, and success completion includes the full ownership predicate:

```sql
WHERE resource_key = :resource_key
  AND runtime_run_id = :runtime_run_id
  AND worker_id = :worker_id
  AND lease_token = :lease_token
  AND state = :expected_state
```

If either lease update affects anything other than one row, the worker has lost ownership. It must stop, must not register artifacts, and must not mark a task or run successful.

### 9.3 Recovery

An expired `leased` row cannot be rented directly. A recovery worker atomically transitions it to `recovery_required`, assigns itself and a new fencing token, and fences the old worker.

Job startup records:

- container ID;
- a baseline GPU process snapshot;
- GPU PIDs attributable to this runtime run.

Recovery checks, in order:

1. whether the recorded container exists or is running;
2. whether the container runtime still reports processes for it;
3. whether recorded owned NVIDIA PIDs still exist;
4. whether residual GPU processes cannot be safely attributed.

If the old container remains, recovery attempts to stop and remove only that container and then checks again. It never kills unrelated NVIDIA PIDs.

- Known owned PID remains: do not release.
- Only unrelated unmanaged processes remain: do not kill them; later jobs may wait for VRAM.
- Ownership of a residual process cannot be proven: set `blocked`.
- Container and owned PIDs are gone: set `available`.

Uncertainty fails closed. An administrator must resolve a `blocked` GPU before new heavy jobs run.

## 10. Pack Job Contracts

All model aliases, request parameters, input artifact types, required outputs, conditional outputs, and runner entrypoints are manifest allowlists.

### 10.1 `audio-cleanup`

Input is one managed audio/video source. Supported operations and required artifacts are:

| Operation | Required artifacts |
| --- | --- |
| `separate` | `vocals_audio`, `background_audio`, `cleanup_report` |
| `enhance` | `cleaned_audio`, `cleanup_report` |
| `separate_and_enhance` | `vocals_audio`, `background_audio`, `cleaned_audio`, `cleanup_report` |

Rules:

- Demucs model selection is an allowlisted alias, initially `htdemucs`.
- DeepFilterNet is optional as a Pack capability.
- If DeepFilterNet is disabled, `enhance` and `separate_and_enhance` are rejected during admission or runner preflight.
- The runner never copies original/vocals audio and labels it `cleaned_audio` when enhancement did not run.
- There is no implicit fallback from enhance to separate.
- `cleanup_report` records the actual execution chain, model/package versions, source and output audio properties, elapsed time, and warnings.

### 10.2 `whisper-asr`

Input is one managed audio source or an allowed owned artifact such as `cleaned_audio` or `vocals_audio`.

Schema-validated request options include:

- allowlisted ASR model alias;
- language or `auto`;
- `word_timestamps`;
- `diarization`;
- `min_speakers` and `max_speakers`;
- `output_srt` and `output_vtt`.

`min_speakers` must be less than or equal to `max_speakers`. If `diarization=0`, providing either speaker-range field is rejected rather than silently ignored.

Model loading is explicit:

| Model | Loading rule |
| --- | --- |
| ASR model | always loaded for `speech_transcribe` |
| alignment model | loaded only when `word_timestamps=1` or the requested output contract requires word alignment |
| pyannote diarization model | loaded only when `diarization=1` |

Required artifacts:

- `transcript_json`
- `transcription_report`

Conditional artifacts:

- `subtitle_srt` when `output_srt=1`
- `subtitle_vtt` when `output_vtt=1`
- `speaker_timeline` when `diarization=1`

`speaker_timeline` must not be produced when diarization is disabled. Speakers are anonymous stable labels such as `speaker_01`; MyAI maps them to product character names.

The pyannote/Hugging Face token is injected only from a Hub secret setting. It never appears in the Pack manifest, task input, command echo, stdout, stderr, or API response. Any credential currently embedded in MyAI must be rotated during migration.

### 10.3 `tts-voxcpm2`

`voice_generate` accepts exactly one voice context per task.

`mode=design`:

- rejects `voice_profile_id`;
- accepts allowlisted voice prompt/control fields;
- accepts a task seed and seed policy.

`mode=clone`:

- requires a managed `voice_profile_id` owned by the caller member;
- uses the existing consent, ownership, read-only container mapping, SHA, and audit boundary;
- rejects external reference paths and embedded credentials/model settings.

All long-form chunks keep the same voice profile, model alias, voice controls, sample format, and seed policy. The required public artifacts are:

- `generated_audio`
- `synthesis_metadata`

`waveform_preview` is conditional on `output_waveform_preview=1`, which defaults to enabled. Chunk WAVs are checkpoint files, not public artifacts.

## 11. VoxCPM2 Long-form Assembly

The Pack runner, not Hub Core, absorbs the proven and refined MyAI voice engineering.

Current MyAI logic already provides punctuation-aware splitting, per-chunk WAV/stat files, voice-anchor reuse and F0 checks, subtitle-slot alignment, gain, and fade handling. The existing character TTS path still joins chunks with a fixed pause and has no immutable plan or checkpoint resume. The following are therefore explicit new Pack capabilities rather than claims about existing behavior.

### 11.1 Immutable chunk plan

Before synthesis, the runner normalizes text and writes `plan/chunks.json` using `chunk_policy=semantic-v1`. The plan records:

- immutable `chunk_id` values;
- original and normalized text;
- source character offsets;
- boundary type;
- pause preset/result;
- policy version and SHA-256;
- model/voice/seed policy snapshot.

Splitting priority is paragraph, sentence terminator, semicolon/colon, comma, then hard length. Golden fixtures cover numbers with units, Latin abbreviations, names, quotations, balanced brackets, and mixed Chinese/English text so they are not split at unsafe boundaries.

Changing input text, voice context, model alias, or chunk policy creates a new task/plan. A retry of the same task does not regenerate a different plan.

### 11.2 Stable voice and seed

Voice profile/conditioning is resolved once and reused for every chunk. If design mode needs a generated voice anchor, the anchor is produced and validated once.

Supported seed policies are `fixed` and `derived_per_chunk`. Derived seeds use a stable SHA-256 derivation from the task seed and `chunk_id`; they do not use language-runtime `hash()` values.

### 11.3 Chunk checkpoints

The workspace layout is:

```text
workspace/
  input/
  plan/chunks.json
  chunks/chunk_0001.wav
  chunks/chunk_0001.json
  assembly/final.wav
  reports/synthesis_metadata.json
```

Each chunk metadata file records text hash, plan hash, voice/model snapshot, seed, attempts, duration, audio format, SHA-256, and quality checks. A runtime retry reuses a chunk only when all hashes and checks remain valid. Failed chunks rerun individually. Assembly failures rerun only assembly.

Manual retry creates a new task. It may seed its new workspace from immutable, revalidated checkpoints of the failed task, but never mutates the original task, run, artifact, callback, or workspace history.

### 11.4 Boundary and assembly policy

Pause values are bounded Pack presets. Initial ranges are:

| Boundary | Pause range |
| --- | ---: |
| comma | 120-250 ms |
| semicolon/colon | 250-400 ms |
| sentence | 350-650 ms |
| paragraph | 600-1000 ms |

The boundary analyzer selects only:

- `direct_concat`
- `silence_insert`
- `crossfade`
- `trim_then_pause`
- `regenerate_chunk`

Crossfade is bounded to 10-40 ms and is applied only when analysis requires it. Existing natural silence is not automatically removed. A chunk may be regenerated at most three times; after that the task fails rather than silently omitting text.

All chunks are converted to one sample rate, channel count, sample format, and codec. Each chunk receives only a basic peak guard. Global loudness normalization occurs after final assembly to avoid per-sentence pumping.

### 11.5 Quality and timeline

Each chunk is checked for:

- readable nonzero audio;
- duration within configured bounds;
- non-silence;
- no clipping;
- expected sample rate/channels;
- bounded leading/trailing silence;
- bounded loudness difference from adjacent chunks.

`synthesis_metadata` is required and includes:

- chunk policy and normalized input;
- immutable chunk plan;
- seed and attempt per chunk;
- voice profile/anchor, controls, and model/package versions;
- generated duration, inserted pause, trim, crossfade, and boundary action;
- sample-accurate chunk start/end timeline;
- final duration, sample rate, channels, sample format, peak dBFS, and integrated LUFS.

The first phase requires chunk-level timing only. Word-level forced alignment is deferred so the TTS image does not absorb ASR dependencies or a second large GPU model.

## 12. Artifact Contract and Validation

Pack manifests declare required and conditional artifacts. Example:

```json
{
  "artifacts": {
    "required": ["transcript_json", "transcription_report"],
    "conditional": {
      "subtitle_srt": "output_srt=true",
      "subtitle_vtt": "output_vtt=true",
      "speaker_timeline": "diarization=true"
    }
  }
}
```

Runner output is untrusted until Hub validates it. Before any terminal transaction, Hub:

- resolves a canonical path inside that task workspace/results root;
- rejects traversal and symlink escape;
- requires a regular file;
- detects MIME instead of trusting runner metadata;
- recomputes SHA-256 and size;
- verifies required and activated conditional types;
- runs ffprobe on audio to verify readability, duration, sample rate, and channels;
- parses JSON reports and validates their declared schema.

Any missing, unsafe, corrupt, or incomplete required output fails with `output_contract_invalid`. A task is never marked successful first and completed with artifacts later.

Public artifact metadata includes:

```text
artifact_id
task_id
pack_id
artifact_type
mime_type
size_bytes
sha256
expires_at
state
```

Webhook payloads contain only this compact metadata/reference set. Large files and transcript bodies are downloaded through the authenticated artifact endpoint.

## 13. Terminal Transactions

### 13.1 Success preconditions

The success transaction begins only after this exact external sequence completes:

```text
runner exits successfully
-> container is stopped and removed
-> owned GPU PIDs are gone
-> all artifacts pass path, content, and contract validation
-> begin terminal SQLite transaction
```

Valid model output does not override cleanup failure. If container or owned PID cleanup fails:

```text
task=failed
runtime_run=failed
error_code=cleanup_failed
gpu:0=blocked
```

### 13.2 Transaction contents

The short transaction performs SQLite state transitions only:

1. verify runtime run fencing ownership;
2. verify GPU resource fencing ownership;
3. insert precomputed artifact metadata;
4. finish the runtime run;
5. finish the task;
6. insert callback outbox delivery when configured;
7. release or block the GPU resource as required.

It does not hash files, run ffprobe, move large files, call Docker, execute `nvidia-smi`, or send callbacks.

Callback workers can see an outbox row only after commit. If the transaction rolls back, validated files remain unregistered orphan workspace files and are not externally downloadable. Recovery or retention later removes them.

### 13.3 Cancel and timeout

Queued or waiting tasks can be cancelled without a GPU. A running task follows:

```text
cancel_requested
-> cooperative runner stop
-> container and owned PID cleanup
-> fencing terminal transaction
-> cancelled
```

Timeout follows the same cleanup and fencing path and ends `timed_out`.

If stopping or cleanup fails, failure takes precedence over cancellation/timeout:

```text
failed / cleanup_failed
gpu:0 blocked
```

No worker may mark cancellation or timeout without owning the current run/resource fencing token.

## 14. Retry Semantics

Retry counters are independent:

| Counter | Owner | Limit |
| --- | --- | ---: |
| `chunk_attempt` | Pack runner per chunk | 3 |
| `runtime_attempt` | Hub infrastructure runs per task | 2 |
| `callback_attempt` | callback delivery worker | 5 |

Admission rejection, a failed queue claim, and `waiting_gpu` do not increment any runtime/chunk attempt. A provisional runtime row with null `attempt_no` is not an attempt and is reused until it either reaches `running` or the task becomes terminal.

Deterministic business/model errors do not trigger blind Hub retries, including invalid input, missing model, invalid parameters, and `output_contract_invalid`. Hub may retry only infrastructure failures such as a lost worker or container runtime interruption, up to two runtime runs, using the original route and workspace checkpoint.

An OOM after a Pack's declared preflight is a failed `gpu_oom`, not an infinite wait/retry loop. Cleanup must still complete before releasing the GPU.

Manual retry creates a new task with `retry_of_task_id`, `source_task_id`, and `source_artifact_id` where applicable. Historical terminal states, runs, artifacts, and callbacks remain immutable.

## 15. Reliable Callback Contract

MyAI registers one or more callback aliases in advance. Task submission may specify `callback_target=<alias>` and supported events but never a URL.

The first release emits only:

- `task.completed`
- `task.failed`

Progress remains poll-based. Cancel, timeout, waiting-GPU, and progress events are deferred.

Delivery headers are:

```text
X-AIHub-Event: task.completed
X-AIHub-Delivery: <stable delivery_id>
X-AIHub-Timestamp: <unix timestamp>
X-AIHub-Signature: sha256=<hex hmac>
```

The signature input is:

```text
timestamp + "." + exact_raw_body
```

The delivery ID and JSON body remain stable across retries; the attempt timestamp/signature may change. MyAI deduplicates by delivery ID or task ID plus event and returns 2xx for repeats.

Only HTTP 2xx is success. The worker does not follow redirects. Target registration and delivery enforce the configured scheme/host policy; ordinary task callers cannot turn the Hub into an SSRF client.

Retry schedule:

```text
immediate
30 seconds
2 minutes
10 minutes
1 hour
```

Polling task status/results remains available as the fallback and diagnostic path.

## 16. Retention and Purging

File lifecycle is independent from task execution status.

Default retention:

| Managed data | Default |
| --- | ---: |
| failed `.partial` upload | 1 hour |
| ffmpeg temporary files, chunks, workspace | 24 hours after terminal state |
| source media for success/failed tasks | 7 days |
| result artifacts | 30 days |
| task audit metadata | 180 days |
| pinned or legal-hold artifact | no automatic deletion |

Pack policy may shorten defaults but cannot exceed Hub system maximums. The task records resolved source/workspace expiry; every artifact records its own expiry.

The pruner checks before deletion:

- no active task/run/GPU lease;
- no nonterminal downstream task retention hold;
- no pin or legal hold;
- no retry pending;
- no active download file lock.

Artifact download takes a shared file lock. Purge first marks `purging`; new downloads are rejected, and deletion proceeds only after obtaining the exclusive lock. All deletion targets pass canonical storage-root checks. A failed `realpath()` is unsafe and is not deleted.

Purge updates `purged_at`, state, and freed bytes while retaining task/audit metadata. A purged artifact request returns `410 Gone`. Delete failure becomes `purge_failed` with an error and is retried.

`task_artifacts_ack` verifies task/member ownership and artifact membership. ACK never deletes immediately. It may shorten relevant source/artifact retention to no earlier than 24 hours from ACK.

DB metadata pruning occurs only after physical lifecycle rules allow it. Existing `prune_db.php` must not cascade-delete rows while files, pins, holds, or live references remain.

## 17. Diagnostic Sync Endpoints

Existing `asr` and `tts` synchronous modes remain for administrators, readiness, smoke tests, and short manual samples.

They enforce:

- `sync_max_duration_seconds=30`;
- bounded `sync_max_upload_bytes` from Pack settings;
- `sync_concurrency=1`;
- no callback;
- no `source_artifact_id` chaining.

Oversized media returns an explicit `sync_duration_exceeded`/`async_required` response pointing to `speech_transcribe` or `voice_generate`. A busy sync runner returns `sync_busy`. The Gateway never silently turns a synchronous call into an async task.

## 18. MyAI Integration Boundary

MyAI:

- keeps pages, article/role/paragraph/movie arrangement, and product state;
- submits server-to-server multipart tasks with its Bearer token;
- stores returned Hub task IDs;
- verifies signed callbacks idempotently;
- downloads artifacts through authenticated Hub APIs;
- maps anonymous ASR speakers to product characters;
- later orchestrates multiple atomic tasks through `voice-pipeline`.

MyAI does not pass local paths or invoke Pack runners directly. During migration, the old MyAI-hosted model services are removed from the production flow only after each Hub Pack passes real acceptance on the 5060 Ti.

## 19. Acceptance Tests

### 19.1 Hub Core

- Migration upgrades an old DB, retains data, and is idempotent when run twice.
- Mode resolution snapshots Pack ID/version/job and rejects client-controlled entrypoints.
- Task/status/log/result/cancel/retry/ACK/artifact APIs enforce member ownership.
- Same-member tokens can share; different members cannot.
- Source artifact type/state/expiry/path checks and downstream retention hold work.
- Manual retry creates a new linked task and leaves history immutable.

### 19.2 GPU runtime

- Two workers race for `gpu:0`; exactly one acquires it.
- Non-owner token cannot heartbeat, release, block, or complete.
- Runtime and GPU heartbeat update atomically.
- Expired lease enters `recovery_required` and cannot be rented directly.
- Missing container and owned PID recover to `available`.
- Existing container/owned PID prevents release.
- Ambiguous cleanup becomes `blocked` and stops subsequent GPU dispatch.
- Unmanaged GPU pressure produces `waiting_gpu`, not process killing.
- CPU-only ffmpeg never touches `runtime_resource_leases`.
- Cleanup failure cannot produce success/cancelled/timed_out.

### 19.3 Callback and retention

- Terminal state and outbox insertion commit atomically.
- HMAC uses timestamp plus exact body.
- Delivery ID remains stable and attempts follow the five-step schedule.
- Redirect/non-2xx delivery does not mark success.
- ACK, pin, legal hold, source dependency, recent download, and active lease prevent purge.
- Traversal, symlink escape, and failed canonical resolution are not deleted or downloaded.
- Purged artifacts return 410 while task audit data remains.

### 19.4 Pack contracts

- All three `audio_cleanup` operation matrices enforce conditional required artifacts.
- Enhancement-disabled Pack rejects enhancement operations and never creates fake `cleaned_audio`.
- ASR always loads its ASR model, conditionally loads alignment/pyannote, validates speaker ranges, and emits speaker timeline only for diarization.
- TTS design/clone inputs are mutually exclusive and clone ownership is audited.
- TTS chunk plan is deterministic for golden Chinese/mixed-language fixtures.
- One voice context is fixed across chunks.
- Valid chunk checkpoints resume without regeneration; corrupt/mismatched chunks rerun.
- Assembly failure does not rerun valid model chunks.
- Missing artifacts, bad MIME, corrupt WAV, unsafe paths, bad report JSON/schema, or incomplete metadata fail `output_contract_invalid`.

### 19.5 Real station acceptance

- Real Demucs + DeepFilterNet smoke on the RTX 5060 Ti.
- Real faster-whisper/WhisperX smoke with diarization off and on.
- Real VoxCPM2 design and managed clone smoke, including long-form checkpoint resume.
- VRAM measurements calibrate each Pack threshold and the 256 MB safety margin.
- MyAI multipart submit, signed callback, idempotent receipt, artifact download, and cleanup-to-ASR chaining work end to end.

Real model tests run as explicit station acceptance/benchmarks, not ordinary dependency-free CI.

## 20. Documentation and Deployment

Implementation must update:

- `docs/pack_runtime_contract_v0.1.md` for async Pack-job routing, resource fencing, and terminal transactions;
- `docs/local_job_contract_v0.1.md` for generic task-to-job execution and checkpoint workspace reuse;
- `docs/api_examples.md` and `docs/client_quickstart.md` for all three async modes, callback verification, polling, ACK, and artifact download;
- `README.md` for migration, Pack responsibilities, sync limits, retention, and real benchmark commands.

Deployment order is:

1. `git pull`;
2. `php scripts/init_db.php`;
3. run PHP/unit/static tests;
4. install/build Packs;
5. collect host metrics and run preflight;
6. run real 5060 Ti acceptance;
7. configure MyAI callback target and permissions;
8. cut MyAI over one capability at a time.

## 21. Implementation Boundaries

The work should be implemented in these dependency-ordered milestones:

1. task ownership, immutable routing, migrations, artifact contract, and retention foundation;
2. callback target/outbox worker and MyAI verification fixture;
3. runtime attempt linkage, host GPU lease, fencing, recovery, and generic Pack-job adapter;
4. `audio-cleanup` Pack and real smoke;
5. `whisper-asr` async job upgrade and real smoke;
6. `tts-voxcpm2` async job/long-form upgrade and real smoke;
7. MyAI server-to-server integration and staged cutover.

No milestone creates a Pack-specific queue worker. Cross-cutting behavior remains in Hub Core; model/audio behavior remains in the Pack runner.
