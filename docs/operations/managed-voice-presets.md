# Managed voice presets

`voice_generate` provides an owner-managed preset catalog for applications that
need stable character voices without coupling to engine details.

## Management

Create a confirmed base Voice Profile with the existing `profile_prepare` /
`profile_confirm` flow, then bind it through `voice_preset_upsert`. Add an
optional confirmed scene sample with `voice_preset_anchor_upsert`. Only the
authenticated owner can list, update, or retire a preset with
`voice_preset_delete`. The Hub stores profile
handles, reference WAV locations, prompt text, and engine strategy privately.

## Synthesis

Use `operation=preset_synthesize` with `voice_preset`, `purpose`, `scene`,
`candidate_count` (1–3), `text`, and optional `seed`. The response is the
ordinary asynchronous `task_id` response. Its completed result has ordered
`candidates`, each with `candidate_id`, `audio_url`, `seed`, and
`preset_revision`; save `task_id` with those candidate fields.

A scene with a configured anchor uses the internal ultimate-clone policy. A
declared scene without an anchor automatically falls back to the preset base
voice. Clients never select a model, clone mode, Voice Profile ID, WAV path,
`voice_prompt`, or `control`; `text` is always the exact spoken text.

Stable request errors are `voice_preset_required`, `voice_preset_not_found`,
`voice_preset_unavailable`, `voice_preset_scene_invalid`,
`voice_preset_candidate_count_invalid`, `voice_preset_candidate_count_unsupported`,
`voice_preset_forbidden_input`, `voice_preset_engine_incompatible`, and
`voice_preset_invalid`.

## Private engine bindings

`voice_preset_engine_bind` is owner-only management, not a synthesis input:

```json
{
  "operation": "voice_preset_engine_bind",
  "voice_preset": "mechanic-dad",
  "engine": "breezyvoice"
}
```

The initial supported engine value is `breezyvoice`, which binds the preset to
the `tts-breezyvoice` Pack after validating that its base Voice Profile is
private, consented, owned by the caller, transcript-confirmed, unexpired, and
Taiwan Mandarin compatible. The engine binding, Pack ID, Profile ID, reference
WAV/hash, transcript, model revision, and runner configuration remain private.
The response is only the normal public preset shape.

The B1 BreezyVoice contract accepts `candidate_count=1` only. Its saved seed is
provenance for a best-effort retry (`seed_applied=false`), not a claim of
deterministic sampling. It serves Taiwan Mandarin (`zh-TW`); Taigi/Hokkien and
other languages require their own explicit Pack contract.

The Pack must separately be installed, enabled, provisioned from its pinned
source/model revisions, and have a successful real-inference acceptance before
it can run. A Windows Hub invokes it through `windows-wsl2-linux-docker`; a
GTX 1080 uses the Pascal CUDA 11.8 runtime profile. `runtime_ready=false` is a
hard readiness boundary and returns `pack_runtime_not_ready` without creating a
task.

For a managed preset, the seed makes a selected candidate reproducible within
the same preset and engine revision. It does not promise byte-identical output
after a revision, engine, or inference-environment change.

## Generic voice exploration

`generic_synthesize` is separate from managed presets. It creates neither a
Voice Profile nor a preset, and it never reads an existing character voice.
Native Hub and Cluster callers send only `text`, `gender`, `age_bucket`,
`role_note`, and `candidate_count` (1–3). `text` is the only spoken content;
model, mode, Profile identifiers, paths, `voice_prompt`, `control`, and
caller-supplied seeds are rejected.

The Hub runs internal generic `design` and returns the normal async links. A
terminal result supplies ordered `candidate_id`, opaque `audio_artifact_id`,
opaque `audio_url`, server seed, `voice_design_revision`, and
`style_status=unverified`. It is not a child task, Profile, or path. Native
Hub `task_result` has no `ack_url_template` or `cluster_artifact_index`.
Through the Router only, use the candidate `audio_artifact_id` to expand the
returned `ack_url_template`. A Router generic result also carries a candidate-only
`cluster_artifact_index` with the opaque ID, type, MIME, size, and SHA-256 so
an integrity-aware client can validate the downloaded audio before ACK.
Gender, age, and role note remain owner-private exploration preferences until
an engine supports independent controls; they are not promised acoustic traits
and are never concatenated to speech. Save the WAV with task ID, candidate ID,
seed, and revision for provenance. Generic exploration has no preset: its
recipe only supports comparison or retry within the same design and runtime
revision, and cannot promise byte-identical output after a model, runtime, or
inference-environment change.

Stable request errors are `generic_voice_invalid`,
`generic_voice_candidate_count_invalid`, and
`generic_voice_forbidden_input`.

## Cluster Router

Through `cluster_api.php`, a successful preset binding records the owner,
public catalog metadata, and its selected station. `voice_presets` is served
from that Router-owned catalog; later `preset_synthesize` requests are pinned
to that station with no failover. A completed candidate still has the same
`candidate_id`, `seed`, and `preset_revision`; its `audio_url` is the normal
authenticated Router artifact URL.

`voice_preset_engine_bind` follows that exact existing affinity. The Router
will not select or migrate a different station for a bind. It strips child
engine and reference details before updating its catalog and before returning
the public response.

Generic exploration has no preset affinity. The Router selects an ordinary
eligible `voice_generate` station when `generic_synthesize` is submitted; once
the child accepts it, the opaque task and artifact links are pinned to that
station. Download each candidate through its opaque Router URL and ACK it with
the returned task ACK template expanded with its opaque `audio_artifact_id`.
