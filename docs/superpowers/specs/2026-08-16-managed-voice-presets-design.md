# Managed voice presets design

**Date:** 2026-08-16  
**Status:** approved

## Goal

Expose a model-independent, owner-managed character-voice API. A caller says
which preset, purpose, scene, text, candidate count, and optional seed it
wants; the Hub chooses and protects all runtime details.

MyAI is one consumer, not an integration dependency. It may manage its own
character presets through the same authenticated Hub control plane available
to any client. It stores only its character-to-`voice_preset` mapping and
selected output references.

## Boundary

### Control plane

The authenticated owner creates and updates a managed preset, its base voice
asset, and optional scene anchors. The Hub owns validated asset storage,
consent/provenance checks, preset revisioning, and internal engine strategy.
The control plane never accepts a host or container path.

### Generation plane

`operation=preset_synthesize` accepts only:

```json
{
  "voice_preset": "azhe",
  "purpose": "scene_preview",
  "scene": "nervous",
  "candidate_count": 3,
  "text": "等一下，我再確認一次……",
  "seed": 12345
}
```

`seed` is optional. Calls must reject model names, clone modes, Voice Profile
IDs, arbitrary audio paths, `voice_prompt`, and `control`. Spoken text is
always exactly `text`.

The existing raw VoxCPM2 API remains independent for clients that intentionally
use its low-level contract; it does not become a back door into a preset task.

## Resolution policy

1. A base voice asset is mandatory for every enabled preset.
2. A matching enabled scene anchor uses the internal `ultimate_clone` policy.
3. A supported scene without an anchor falls back automatically to that
   preset's base voice and the internal `clone` policy.
4. An unknown/disabled preset or a scene outside the preset's declared scene
   vocabulary returns a stable public error code. The client never decides
   clone versus ultimate-clone.
5. The concrete VoxCPM2 route, reference asset, profile handle, model, and
   seed derivation remain in the immutable task snapshot. Future engines can
   replace the internal policy without changing the external request.

## Batch task contract

One request produces one existing asynchronous batch `task_id`, capped at
three candidates. Completion returns ordered candidates, each with a stable
batch-local `candidate_id`, `audio_url`, `seed`, and `preset_revision`.

```json
{
  "task_id": "...",
  "status_url": "...",
  "candidates": [
    {
      "candidate_id": "candidate-01",
      "audio_url": "...",
      "seed": 12345,
      "preset_revision": 4
    }
  ]
}
```

MyAI persists `task_id`, `candidate_id`, `seed`, and `preset_revision` for the
chosen result. Reusing a seed selects the same generation tendency only; a
later preset revision or engine revision is not promised to be bit-identical.

## Public discovery and errors

`operation=voice_presets` is read-only. It returns only the caller-visible
`id`, `label`, `gender`, `age_bucket`, supported purposes, and supported
scenes for enabled presets. It never returns engine names, profile handles,
asset paths, hashes, or internal strategy.

Stable error codes include:

- `voice_preset_required`
- `voice_preset_not_found`
- `voice_preset_unavailable`
- `voice_preset_scene_invalid`
- `voice_preset_candidate_count_invalid`
- `voice_preset_forbidden_input`

Missing scene anchors are not an error; the result reports only the normal
candidate metadata and is rendered from the base voice.

## Cluster and documentation

Cluster Router forwards the semantic request and ordinary asynchronous task
status/result responses unchanged. It must not materialize protected internal
fields in relay payloads or logs.

Public API documentation describes discovery, synthesis, candidate replay, and
the spoken-text boundary. Operator documentation covers generic owner-managed
preset/anchor lifecycle and consent requirements without publishing asset
locations. Contract tests cover direct gateway and Cluster relay paths.

## Non-goals for this phase

- No model choice or free-form performance prompt in the synthesis contract.
- No promise of byte-for-byte reproducibility across preset or engine changes.
- No MyAI-specific schema, credentials, deployment path, or runtime dependency.
