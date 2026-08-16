# Managed voice presets

`voice_generate` provides an owner-managed preset catalog for applications that
need stable character voices without coupling to VoxCPM2 details.

## Management

Create a confirmed base Voice Profile with the existing `profile_prepare` /
`profile_confirm` flow, then bind it through `voice_preset_upsert`. Add an
optional confirmed scene sample with `voice_preset_anchor_upsert`. Only the
authenticated owner can list or update its presets. The Hub stores profile
handles, reference WAV locations, prompt text, and engine strategy privately.

## Synthesis

Use `operation=preset_synthesize` with `voice_preset`, `purpose`, `scene`,
`candidate_count` (1–3), `text`, and optional `seed`. The response is the
ordinary asynchronous `task_id` response. Its completed result has ordered
`candidates`; save `task_id`, `candidate_id`, `seed`, and `preset_revision`.

A scene with a configured anchor uses the internal ultimate-clone policy. A
declared scene without an anchor automatically falls back to the preset base
voice. Clients never select a model, clone mode, Voice Profile ID, WAV path,
`voice_prompt`, or `control`; `text` is always the exact spoken text.

The seed makes a selected candidate reproducible within the same preset and
engine revision. It does not promise byte-identical output after a revision or
engine change.
