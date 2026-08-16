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
`voice_preset_candidate_count_invalid`, `voice_preset_forbidden_input`, and
`voice_preset_invalid`.

The seed makes a selected candidate reproducible within the same preset and
engine revision. It does not promise byte-identical output after a revision or
engine change.

## Cluster Router

Through `cluster_api.php`, a successful preset binding records the owner,
public catalog metadata, and its selected station. `voice_presets` is served
from that Router-owned catalog; later `preset_synthesize` requests are pinned
to that station with no failover. A completed candidate still has the same
`candidate_id`, `seed`, and `preset_revision`; its `audio_url` is the normal
authenticated Router artifact URL.
