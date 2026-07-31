# Cluster Ultimate Clone API Design

Date: 2026-07-30

Status: implemented; real Cluster smoke passed

## Scope

Make the installed `tts-voxcpm2` Pack a complete asynchronous Cluster
capability:

```text
voice_generate -> tts-voxcpm2 / synthesize / job / gpu
```

The public Hub and Cluster APIs must support `design`, `clone`, and
`ultimate_clone`. A reusable managed Voice Profile is prepared through a
normal asynchronous task. Its public handle is the task ID already returned by
the Hub or Cluster, not a child-node profile ID.

The work includes:

- publishing `voice_generate` from an installed and enabled async Pack without
  requiring the optional synchronous `tts` runtime to be running;
- preparing, confirming, inspecting, and deleting a managed Voice Profile
  through `voice_generate` operations;
- pinning Profile follow-ups and synthesis to the child station that owns the
  Profile task;
- completing real VoxCPM2 GPU inference through `cluster_api.php`;
- documenting the installed contract in the existing public API portal.

The work does not modify `/var/www/html/myai/myai_voice`. MyAI may consume the
contract later by storing `voice_profile_task_id`. Cross-station Voice Profile
replication, automatic failover, a new Profile administration UI, and changes
to synchronous `tts` are out of scope.

## Existing Building Blocks

The implementation reuses these existing facilities:

- managed Voice Profiles, WAV validation, SHA-256 cache, ASR draft,
  confirmation, consent audit, and soft deletion;
- Pack-job task admission, ownership, callbacks, cancellation, artifact
  retention, GPU leases, and one-shot containers;
- Cluster route IDs, exact customer-token ownership, child station tokens,
  pinned task follow-ups, multipart relay, and artifact relay;
- the current synchronous VoxCPM2 `ultimate_clone` service contract.

No separate Cluster Voice Profile mapping table is added. The Profile
preparation task and existing Cluster route are the mapping.

## Public Contract

Both endpoints expose the same operations:

```text
api.php?mode=voice_generate
cluster_api.php?mode=voice_generate
```

All operations require the existing `voice_generate` Token permission.
Omitting `operation` means `synthesize`, preserving the existing async API.

### `profile_prepare`

`POST` multipart fields:

| Field | Rule |
| --- | --- |
| `operation` | required value `profile_prepare` |
| `reference_wav` | required RIFF/WAVE upload accepted by the existing Voice Profile validator |
| `profile_name` | required non-empty Voice Profile name |
| `consent_type` | required existing value: `self_recorded`, `explicit_permission`, or `licensed_voice` |
| `prompt_text` | optional reviewed transcript |
| `transcript_confirmed` | optional boolean; may be true only when `prompt_text` is present |
| `language` | optional bounded language hint |
| `callback_target` | optional existing registered callback alias |

The child station validates and copies the WAV into managed Voice Profile
storage before returning the standard asynchronous task response. The task
stores only its local managed Profile relationship and bounded non-secret
inputs. It does not duplicate `prompt_text` in task input, audit details,
logs, callback payloads, or Cluster route metadata.

When `prompt_text` is supplied, the Profile task uses it instead of running
ASR. `transcript_confirmed=true` confirms it during preparation. Otherwise it
is a draft. When `prompt_text` is absent, the task invokes the existing Voice
Profile ASR path and returns a draft requiring confirmation.

The task result contains only safe preparation state, character count, and
transcript SHA-256. A client retrieves an unconfirmed draft through the
owner-checked `profile_status` operation. Plaintext is therefore never copied
into task input or output.

The existing owner-and-WAV-SHA cache remains authoritative for direct native
Hub callers. A paired child does not apply that cache across Cluster
preparation requests: its station Token represents multiple Router customers,
so member-only cache reuse could cross customer boundaries. Cluster clients
reuse the `voice_profile_task_id` they already received. A future signed
per-customer child namespace may restore automatic child-side deduplication,
but is outside this design.

### `profile_confirm`

`POST` fields:

| Field | Rule |
| --- | --- |
| `operation` | required value `profile_confirm` |
| `voice_profile_task_id` | required Hub task ID or opaque Cluster route task ID |
| `prompt_text` | required reviewed non-empty transcript |

The operation is routed to the Profile task's station, confirms the local
managed Profile through the existing transactional audit path, and returns
safe Profile status.

### `profile_status`

`GET` or `POST` requires `operation=profile_status` and
`voice_profile_task_id`. It returns only:

- preparation task state;
- transcription state and bounded error code;
- confirmation boolean and timestamp;
- Profile name, language, consent type, reference WAV SHA-256, and timestamps;
- deletion or expiry state.

While confirmation is still required, it may also return the current
unconfirmed `prompt_text` draft to the exact owner. It never returns a host
path, child station ID, child task ID, local Profile ID, station Token, or
confirmed transcript.

### `profile_delete`

`POST` requires `operation=profile_delete` and
`voice_profile_task_id`. It uses the existing Voice Profile soft-delete audit,
deletes the managed WAV, marks the Profile task handle unavailable, and
returns a bounded success response. Repeating deletion is idempotent for the
same owner.

### `synthesize`

The existing fields remain valid. The Pack contract changes are:

| Field | Rule |
| --- | --- |
| `mode` | `design`, `clone`, or `ultimate_clone` |
| `voice_profile_task_id` | required for Cluster `clone` and `ultimate_clone` |
| `voice_profile_id` | retained only for compatible direct native-Hub clients |

`design` forbids either Profile field. `clone` requires one managed Profile
reference. `ultimate_clone` additionally requires a confirmed transcript.
Cluster callers use only `voice_profile_task_id`; the Router never accepts or
returns child-local Profile IDs.

## Task Affinity and Return Path

`profile_prepare` uses ordinary `voice_generate` station selection. The
Router admits a normal Cluster route, forwards the multipart request, records
the returned child task ID, and returns the existing opaque Cluster task ID
and follow-up links.

For `profile_confirm`, `profile_status`, `profile_delete`, `clone`, or
`ultimate_clone`, the Router:

1. resolves `voice_profile_task_id` as an existing Cluster route;
2. requires the exact customer member and Token ownership already used by
   Cluster task follow-ups;
3. requires the route to represent a successful Profile preparation task;
4. pins the request to that route's enabled and fresh station;
5. replaces the opaque route ID with the child task ID only in the internal
   request;
6. dispatches with the encrypted paired-station Token.

The child resolves its Profile task to its local managed Profile and validates
the paired node-member ownership. The local Profile ID is never sent back to
the caller.

Because the Router verifies the opaque route before translating it, a Cluster
customer can never submit a child task ID directly. The child also refuses
Profile task resolution through ordinary customer Tokens; only the paired
station Token reaches this path.

The generated task receives a new Cluster route. Status, result, log,
cancellation, artifact download, and artifact acknowledgement use the
existing `cluster_task_*` and `cluster_artifact` return path unchanged.

If the pinned station is unavailable, the Router returns
`station_unavailable`. It does not retry another station after dispatch and
does not transfer the private reference WAV between child stations. The
caller may prepare a new Profile when another station is available.

## Profile Task Lifetime

The local `voice_profiles` record links to its preparation task. Active,
unexpired, non-deleted Profiles prevent metadata purge of that task. Generic
task source and workspace files may still follow normal retention because the
validated WAV has already moved into managed Voice Profile storage.

Deleting or expiring a Profile releases this metadata protection. Normal task
metadata retention then applies. Existing Profile expiry checks remain
authoritative; expired managed audio is removed by bounded Voice Profile
cleanup.

This keeps a reusable task handle alive without pinning unrelated task
artifacts or adding a second Cluster mapping store.

## Async Ultimate Clone Execution

At synthesis admission, the child resolves the local Profile and stores a
path-free immutable `voice_context` snapshot:

- synthesis mode;
- local Profile ID;
- reference WAV SHA-256;
- for `ultimate_clone`, confirmed transcript SHA-256 and confirmation marker;
- fixed container path `/data/voice_profiles/reference.wav`.

The task input, audit, logs, callback, and public result do not contain the
confirmed transcript or host path.

Immediately before GPU execution, the worker reloads the Profile, verifies
ownership, deletion and expiry, revalidates the regular managed WAV and its
SHA-256, and for `ultimate_clone` revalidates the confirmed transcript
SHA-256. Any change after admission fails closed before inference.

The worker mounts only the verified WAV read-only at the fixed container path.
It writes the confirmed transcript only to the task's private ephemeral runner
request. The one-shot runner accepts all three modes, passes the same managed
WAV as reference and prompt audio for `ultimate_clone`, and deletes the
workspace through the existing cleanup path.

Generated metadata may record mode, model, controls, seed, elapsed time,
device, and safe Profile hashes. It must not record transcript text, host
paths, customer Tokens, or local Profile IDs.

The runner records an exact device attestation. Offline fake synthesis reports
`{"type":"fake","real_inference":false}`. A completed real inference reports
`{"type":"cuda","real_inference":true}`; there is no CPU fallback for this
GPU Pack.

## Catalog and Documentation

`voice_generate` is derived from the installed, enabled, runtime-ready Pack
job contract. It does not depend on a running synchronous `tts` service row.
This allows a child to publish the async mode while `voxcpm2-main` is stopped.

The child administrator must still select `voice_generate` for Cluster
publication. Router customer Tokens still require explicit `voice_generate`
permission.

The native and Cluster public API documents show:

- all three synthesis modes;
- the five operations and their methods;
- the Profile task preparation and confirmation sequence;
- Cluster-only use of `voice_profile_task_id`;
- standard task and artifact follow-up links;
- the pinned-station and no-failover behavior;
- safe curl, PHP, and JavaScript examples with placeholders only.

No transcript, Token, filesystem path, real task ID, or real Profile handle is
embedded in documentation.

## Errors

The public contract uses bounded stable errors:

| HTTP | Code | Meaning |
| --- | --- | --- |
| 400 | `invalid_request` | malformed operation or incompatible fields |
| 400 | `voice_profile_wav_invalid` | invalid managed reference WAV |
| 400 | `voice_profile_transcript_invalid` | missing or invalid confirmation text |
| 403 | `voice_profile_forbidden` | direct native-Hub owner mismatch |
| 404 | `profile_task_not_found` | unknown or foreign Cluster Profile task handle |
| 409 | `voice_profile_transcript_unconfirmed` | ultimate clone requires confirmation |
| 409 | `voice_profile_changed` | WAV or transcript changed after task admission |
| 410 | `voice_profile_unavailable` | deleted or expired Profile |
| 503 | `pack_runtime_not_ready` | async Pack is unavailable |
| 503 | `station_unavailable` | pinned child is disabled, stale, or unreachable |

Cluster ownership failures use `profile_task_not_found` so callers cannot
enumerate another customer's route handles.

## Verification

Focused automated coverage is required for:

- Pack schema and runner acceptance of all three modes;
- immutable WAV and transcript hash validation;
- no transcript or host path in task input, audit, callback, public result, or
  synthesis metadata;
- Profile preparation with supplied text, ASR draft, cache hit, confirmation,
  status, deletion, and retention protection;
- native SHA cache reuse plus paired-child cache isolation;
- Router task ownership, station pinning, child-ID translation, no failover,
  multipart upload, and foreign-handle rejection;
- installed async `voice_generate` publication without synchronous `tts`;
- native and Cluster documentation contracts.

The ordinary suite stays offline. The full repository suite is run only when
focused changes show shared-contract risk.

One station-only real acceptance command performs:

1. authenticated `profile_prepare` through `cluster_api.php` using a short
   consented WAV and its reviewed transcript;
2. Profile task polling and safe result validation;
3. `profile_status` and confirmation-state validation;
4. authenticated `ultimate_clone` submission through `cluster_api.php`;
5. task polling through the Router;
6. `generated_audio` and `synthesis_metadata` download through
   `cluster_artifact`;
7. SHA-256, RIFF/WAVE, `ffprobe`, mode, model, and real GPU execution checks;
8. artifact acknowledgement;
9. `profile_delete` cleanup.

The command receives its Cluster base URL, Token, WAV path, and reviewed
transcript from caller-controlled environment variables. It never persists
the Token, transcript, WAV, URLs, task IDs, artifact IDs, or hashes in a
benchmark result. A normal test run never launches this real inference smoke.

## Acceptance Record

Date: 2026-07-31

- `profile_prepared`: true
- `ultimate_clone`: true
- `audio_valid`: true
- `gpu`: true
- `artifacts_acknowledged`: true
- `no_active_gpu_lease`: true
- `no_acceptance_member`: true
- `no_acceptance_token`: true
- `no_active_acceptance_profile`: true
- `no_acceptance_profile_wav`: true
- `no_active_acceptance_task`: true
- `no_cli_temp_dir`: true
- `no_unacknowledged_acceptance_artifacts`: true
